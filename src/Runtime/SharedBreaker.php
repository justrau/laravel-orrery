<?php

namespace Justrau\Orrery\Runtime;

use Closure;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Throwable;

/**
 * The end of an open mirror breaker, shared with the next processes.
 * Under PHP-FPM each request builds a new application, so the watcher's
 * own breaker lives for one request: without this store, every request
 * would wait the connect timeout of an unreachable mirror again.
 *
 * - The store is one small file per mirror (`orrery-breaker-<key>`, the key
 *   is the hash of the mirror's session name and endpoint identity,
 *   computed per call) in `orrery.observer.shared_breaker_path` (default:
 *   the application's `storage/framework`). It holds two wall-clock times
 *   as epoch seconds: the end of the breaker and the time of the
 *   connection failure that opened it. A write goes to a temporary file
 *   first and is renamed over the old one, so a reader never sees half a
 *   value.
 * - `orrery.observer.shared_breaker`: null (the default) uses the store only
 *   outside the console (a web SAPI), true always, false never. A worker or
 *   a command keeps its breaker in its own process.
 * - A mirror dependency error is shared (Watcher::failed(), and a failed
 *   flush, Watcher::flushFailed()): a lost connection in the middle of a
 *   statement counts as much as a failed connect. A breaker that lock
 *   timeouts opened stays in its process. The key names no host, so nodes
 *   that share one storage directory share the breaker too.
 * - The probe claim: the first reader that finds an end that passed, while
 *   the failure is recent (at most two breaker windows ago), moves the end
 *   PROBE_SECONDS ahead before it tries the mirror itself; its own claim
 *   never holds it off. So the other
 *   processes skip the mirror while about one probes it, and do not all
 *   wait the connect timeout at once. A success of the probe on the mirror
 *   removes the file (clearProbe(); also a successful degradation flush
 *   that took the claim), unless another process wrote a newer
 *   end into it meanwhile; a failure opens the breaker again.
 * - An end more than the breaker window (or PROBE_SECONDS, when that is
 *   longer) ahead (a wall clock that jumped, a damaged file) is ignored.
 * - It never throws: a store that cannot be read or written behaves as no
 *   store; open() returns false, so the watcher can report it.
 */
final class SharedBreaker
{
    /** How long a probe claim holds the other processes off: one connect timeout and a second. */
    public const PROBE_SECONDS = PackageSession::CONNECT_TIMEOUT_SECONDS + 1.0;

    private const FILE_PREFIX = 'orrery-breaker-';

    /** The path of the file whose probe this process claimed, or null. */
    private ?string $claimed = null;

    /** The content that the claim wrote into that file. */
    private ?string $claimedContent = null;

    /**
     * @param  Closure(): string  $key  the key of the mirror (no connection opens)
     */
    public function __construct(private readonly ?string $directory, private readonly Closure $key) {}

    public function enabled(): bool
    {
        return $this->directory !== null && $this->directory !== '';
    }

    /**
     * Seconds that remain of a breaker that a process opened, at most
     * $window; null when none is open. It claims the probe when a recent
     * end passed (the class docblock).
     */
    public function remaining(float $window): ?float
    {
        try {
            $path = $this->path();

            if ($path === null || ! is_file($path)) {
                return null;
            }

            $content = @file_get_contents($path);

            if ($this->claimed === $path && $content === $this->claimedContent) {
                // This process holds the probe: its own claim never holds it off.
                return null;
            }

            $times = is_string($content) ? explode(' ', trim($content)) : [];

            if (count($times) !== 2 || ! is_numeric($times[0]) || ! is_numeric($times[1])) {
                return null;
            }

            $now = Clock::now();
            $remaining = (float) $times[0] - $now;
            $failedAt = (float) $times[1];

            if ($remaining > 0.0) {
                // A probe claim holds PROBE_SECONDS, which can be longer than a short window.
                return $remaining <= max($window, self::PROBE_SECONDS) ? $remaining : null;
            }

            if ($failedAt <= $now && $now - $failedAt <= 2 * $window) {
                $content = $this->write($path, $now + self::PROBE_SECONDS, $failedAt);

                if ($content !== null) {
                    $this->claimed = $path;
                    $this->claimedContent = $content;
                }
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Stores the end of a breaker that a connection failure opens now for
     * $seconds. False when the store is on and the file could not be
     * written.
     */
    public function open(float $seconds): bool
    {
        try {
            $path = $this->path();

            if ($path === null) {
                return ! $this->enabled();
            }

            $now = Clock::now();
            $this->claimed = null;
            $this->claimedContent = null;

            return $this->write($path, $now + $seconds, $now) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The probe that this process claimed reached the mirror: the file goes,
     * so no later reader claims a probe again. A file that holds another
     * content now (another process opened the breaker again after its own
     * failure) stays.
     */
    public function clearProbe(): void
    {
        if ($this->claimed === null) {
            return;
        }

        $path = $this->claimed;
        $content = $this->claimedContent;
        $this->claimed = null;
        $this->claimedContent = null;

        try {
            if (@file_get_contents($path) === $content) {
                @unlink($path);
            }
        } catch (Throwable) {
            // The end has passed anyway; a later reader may probe once more.
        }
    }

    /**
     * Writes the two times; returns the written content, or null when the
     * file could not be written (no temporary file stays).
     */
    private function write(string $path, float $until, float $failedAt): ?string
    {
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        $content = sprintf('%.6F %.6F', $until, $failedAt);

        if (@file_put_contents($temporary, $content) === false) {
            @unlink($temporary);

            return null;
        }

        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            return null;
        }

        return $content;
    }

    /**
     * The file of the current mirror; null when the store is off or the key
     * cannot be computed.
     */
    private function path(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            return rtrim((string) $this->directory, '/').'/'.self::FILE_PREFIX.substr(($this->key)(), 0, 40);
        } catch (Throwable) {
            return null;
        }
    }
}
