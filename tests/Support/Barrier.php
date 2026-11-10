<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use LogicException;
use Throwable;

/**
 * Coordinates processes through marker files in a private directory.
 * Sleeps never prove an interleaving: the markers do.
 *
 * - arrive($name) creates the marker <directory>/<name>. It writes a
 *   temporary file and renames it, so no process sees a partial marker.
 * - await($name, $seconds) returns when the marker exists. After the
 *   deadline it throws WaitTimeout, and so does an $abandon callback that
 *   returns a reason (for example "the worker exited").
 *
 * Hook points. The test worker CLI (tests/Support/bin/artisan) holds at the
 * points that ORRERY_TEST_HOLD_AT names (holdEnvironment() builds the
 * variables). When the code reaches such a point (Hooks::reach()), the worker
 * arrives at "<point>.reached" and awaits "<point>.release". The test awaits
 * the first marker, checks the state, and arrives at the second.
 *
 * forTest() creates a new directory for each call. TestCase::tearDown()
 * removes every directory that forTest() created in this process
 * (removeAll()).
 */
final class Barrier
{
    public const DIRECTORY_PATTERN = '/^orrery-barrier-[0-9a-f]{16}$/D';

    /** A marker name. It never starts with a dot, so it never matches a temporary file. */
    public const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/D';

    /** A hook point name. With ".reached" or ".release" it is still a valid marker name. */
    public const POINT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D';

    public const DIRECTORY_VARIABLE = 'ORRERY_TEST_BARRIER_DIR';

    public const HOLD_VARIABLE = 'ORRERY_TEST_HOLD_AT';

    /** How long a worker that holds at a hook point waits for "<point>.release". */
    public const HOLD_SECONDS = 60.0;

    private const TEMPORARY_PATTERN = '/^\.[A-Za-z0-9._-]+\.tmp$/D';

    private const POLL_MICROSECONDS = 5_000;

    /** @var array<string, self> the directories that forTest() created in this process */
    private static array $created = [];

    private function __construct(private readonly string $directory) {}

    /**
     * A new private directory in the temporary directory.
     */
    public static function forTest(): self
    {
        $parent = realpath(sys_get_temp_dir());

        if ($parent === false || ! is_dir($parent)) {
            throw new HarnessRefusal('The temporary directory '.sys_get_temp_dir().' does not exist.');
        }

        $directory = $parent.'/orrery-barrier-'.bin2hex(random_bytes(8));
        $umask = umask(0077);

        try {
            if (! @mkdir($directory, 0700)) {
                throw new HarnessRefusal("Cannot create the barrier directory {$directory}.");
            }
        } finally {
            umask($umask);
        }

        return self::$created[$directory] = self::open($directory);
    }

    /**
     * An existing barrier directory, for example in a child process. It must
     * be an absolute, canonical path to a private directory of this user,
     * named orrery-barrier-<16 hex>.
     *
     * @throws HarnessRefusal
     */
    public static function open(string $directory): self
    {
        if ($directory === '' || $directory[0] !== '/') {
            throw new HarnessRefusal("A barrier directory must be an absolute path, not '{$directory}'.");
        }

        if (preg_match(self::DIRECTORY_PATTERN, basename($directory)) !== 1) {
            throw new HarnessRefusal("A barrier directory must be named orrery-barrier-<16 hex>: {$directory}.");
        }

        clearstatcache();
        $stat = @lstat($directory);

        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
            throw new HarnessRefusal("The barrier directory {$directory} does not exist, or it is not a directory (a symlink is refused).");
        }

        if (realpath($directory) !== $directory) {
            throw new HarnessRefusal("The barrier directory {$directory} is not a canonical path.");
        }

        if ($stat['uid'] !== (function_exists('posix_geteuid') ? posix_geteuid() : (int) trim((string) shell_exec('id -u')))) {
            throw new HarnessRefusal("The barrier directory {$directory} is not owned by this user.");
        }

        if (($stat['mode'] & 0077) !== 0) {
            throw new HarnessRefusal(sprintf('The barrier directory %s has mode %o; it must be private (0700).', $directory, $stat['mode'] & 0777));
        }

        return new self($directory);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function arrive(string $name): void
    {
        $marker = $this->path($name);
        $temporary = sprintf('%s/.%s.%d.%s.tmp', $this->directory, $name, getmypid(), bin2hex(random_bytes(4)));
        $content = json_encode(['pid' => getmypid(), 'time' => microtime(true)]);

        if (@file_put_contents($temporary, $content."\n") === false) {
            throw new HarnessRefusal("Cannot write the barrier marker {$temporary}.");
        }

        if (! @rename($temporary, $marker)) {
            @unlink($temporary);

            throw new HarnessRefusal("Cannot create the barrier marker {$marker}.");
        }
    }

    public function arrived(string $name): bool
    {
        $marker = $this->path($name);
        clearstatcache(true, $marker);

        return is_file($marker);
    }

    /**
     * Returns when the marker $name exists.
     *
     * @param  (callable(): ?string)|null  $abandon  called on every poll; a string is the reason to stop waiting
     *
     * @throws WaitTimeout after $seconds, or when $abandon returns a reason before the marker exists
     */
    public function await(string $name, float $seconds, ?callable $abandon = null): void
    {
        if ($seconds <= 0) {
            throw new LogicException('A barrier wait needs a deadline of more than 0 seconds.');
        }

        $deadline = microtime(true) + $seconds;

        while (! $this->arrived($name)) {
            $reason = $abandon === null ? null : $abandon();

            // The marker can arrive just before the reason (a worker that
            // arrives and then exits), so it is checked once more.
            if (is_string($reason) && ! $this->arrived($name)) {
                throw new WaitTimeout(sprintf("Stopped waiting for the barrier marker '%s' in %s: %s", $name, $this->directory, $reason));
            }

            if (microtime(true) >= $deadline) {
                throw new WaitTimeout(sprintf("The barrier marker '%s' did not arrive in %s within %s seconds.", $name, $this->directory, $seconds));
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }

    /**
     * The variables that make a test worker hold at $points (the extra
     * environment of WorkerProcess::start()).
     *
     * @return array<string, string>
     */
    public function holdEnvironment(string ...$points): array
    {
        if ($points === []) {
            throw new LogicException('Name at least one hook point.');
        }

        foreach ($points as $point) {
            self::validatePoint($point);
        }

        return [self::DIRECTORY_VARIABLE => $this->directory, self::HOLD_VARIABLE => implode(',', $points)];
    }

    /**
     * @throws HarnessRefusal
     */
    public static function validatePoint(string $point): string
    {
        if (preg_match(self::POINT_PATTERN, $point) !== 1) {
            throw new HarnessRefusal("Invalid hook point '{$point}': it must match ".self::POINT_PATTERN.'.');
        }

        return $point;
    }

    /**
     * Removes the directory with its markers. It refuses a directory that
     * holds anything else.
     */
    public function remove(): void
    {
        unset(self::$created[$this->directory]);
        clearstatcache();

        if (! is_dir($this->directory)) {
            return;
        }

        $entries = array_values(array_diff(scandir($this->directory) ?: [], ['.', '..']));

        foreach ($entries as $entry) {
            $path = $this->directory.'/'.$entry;

            if (is_link($path) || ! is_file($path) || (preg_match(self::NAME_PATTERN, $entry) !== 1 && preg_match(self::TEMPORARY_PATTERN, $entry) !== 1)) {
                throw new HarnessRefusal("Refusing to remove {$this->directory}: it holds {$entry}, which is not a barrier marker.");
            }
        }

        foreach ($entries as $entry) {
            @unlink($this->directory.'/'.$entry);
        }

        if (! @rmdir($this->directory)) {
            throw new HarnessRefusal("Cannot remove the barrier directory {$this->directory}.");
        }
    }

    /**
     * Removes every directory that forTest() created in this process.
     */
    public static function removeAll(): void
    {
        $first = null;

        foreach (self::$created as $barrier) {
            try {
                $barrier->remove();
            } catch (Throwable $throwable) {
                $first ??= $throwable;
            }
        }

        self::$created = [];

        if ($first !== null) {
            throw $first;
        }
    }

    private function path(string $name): string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new LogicException("Invalid barrier marker name '{$name}': it must match ".self::NAME_PATTERN.'.');
        }

        return $this->directory.'/'.$name;
    }
}
