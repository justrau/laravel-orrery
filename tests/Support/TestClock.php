<?php

namespace Justrau\Orrery\Tests\Support;

use Closure;
use Illuminate\Database\Connection;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\PackageSession;
use RuntimeException;

/**
 * The test clock file of the database clocks.
 *
 * shift() moves the database clock of one connection name by a number of
 * seconds: in this process through DatabaseClock::$offsetForTesting, and in
 * every worker that WorkerProcess starts while the file is active (the
 * variable VARIABLE; tests/Support/bin/artisan installs reader() from it).
 * reader() reads the file at every clock read, so a held child sees a moved
 * deadline without a restart and without a sleep.
 *
 * The keys are database connection names, as the package session names them
 * without its prefix: `testbench` for the queue's database, `testbench#sibling`
 * for the sibling SQLite mirror, `testbench_mirror` for a separate mirror,
 * and `*` for every other connection. The module reset clears the offset
 * closure after each test; the next shift() then starts a new file, so a test
 * never inherits the offsets of an earlier one.
 */
final class TestClock
{
    public const VARIABLE = 'ORRERY_TEST_CLOCK_FILE';

    /** Every other connection name. */
    public const EVERY = '*';

    private static ?string $file = null;

    private static ?Closure $installed = null;

    /**
     * Moves the clock of $connection to the real clock plus $seconds.
     */
    public static function shift(string $connection, float $seconds): void
    {
        $file = self::file() ?? self::start();
        $offsets = self::read($file);
        $offsets[$connection] = $seconds;
        self::write($file, $offsets);
    }

    /**
     * The active clock file of this test, or null before the first shift().
     */
    public static function file(): ?string
    {
        if (self::$installed === null || DatabaseClock::$offsetForTesting !== self::$installed) {
            return null;
        }

        return self::$file;
    }

    /**
     * The offset closure of a clock file: it reads the file at every call.
     *
     * @return Closure(Connection): float
     */
    public static function reader(string $file): Closure
    {
        return static function (Connection $connection) use ($file): float {
            $offsets = self::read($file);
            $name = (string) $connection->getName();

            if (str_starts_with($name, PackageSession::NAME_PREFIX)) {
                $name = substr($name, strlen(PackageSession::NAME_PREFIX));
            }

            return (float) ($offsets[$name] ?? $offsets[self::EVERY] ?? 0.0);
        };
    }

    private static ?bool $cleanup = null;

    private static function start(): string
    {
        if (self::$file !== null) {
            @unlink(self::$file);
        }

        // The last file of the process goes when the process ends.
        if (self::$cleanup === null) {
            self::$cleanup = true;
            register_shutdown_function(static function (): void {
                if (self::$file !== null) {
                    @unlink(self::$file);
                }
            });
        }

        $file = tempnam(sys_get_temp_dir(), 'orrery-clock-');

        if ($file === false) {
            throw new RuntimeException('The test clock file could not be created.');
        }

        self::write($file, []);
        self::$file = $file;
        self::$installed = self::reader($file);
        DatabaseClock::$offsetForTesting = self::$installed;

        return $file;
    }

    /**
     * @return array<string, float>
     */
    private static function read(string $file): array
    {
        $contents = @file_get_contents($file);
        $offsets = is_string($contents) ? json_decode($contents, true) : null;

        return is_array($offsets) ? array_map('floatval', array_filter($offsets, 'is_numeric')) : [];
    }

    /**
     * @param  array<string, float>  $offsets
     */
    private static function write(string $file, array $offsets): void
    {
        // A rename is atomic, so a reader never sees half a file.
        $temporary = $file.'.'.getmypid().'.tmp';

        if (file_put_contents($temporary, json_encode($offsets, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)) === false || ! rename($temporary, $file)) {
            throw new RuntimeException("The test clock file {$file} could not be written.");
        }
    }
}
