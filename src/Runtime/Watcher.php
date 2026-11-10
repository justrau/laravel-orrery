<?php

namespace Justrau\Orrery\Runtime;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Queue\TimeoutExceededException;
use Justrau\Orrery\Exceptions\WatchedOperationFailed;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use RuntimeException;
use Throwable;
use WeakMap;

/**
 * Runs the package's own work so that it never changes the host outcome.
 *
 * - run() returns the work's value, or $default when the work throws. It
 *   never rethrows, except under the test switch rethrowForTesting() and
 *   for Laravel's TimeoutExceededException: with Worker::$killOnTimeout
 *   false, the worker's timeout handler throws it into the code that runs,
 *   which can be package code, and it must reach the job.
 * - A dependency error of the mirror's session (ErrorKinds::
 *   isMirrorDependency()) opens the breaker for
 *   `orrery.observer.breaker_seconds` (default 30). A dependency error of
 *   another package session (a secondary queue database) is reported and
 *   recorded, and its caller handles it per target. While it is open, run()
 *   returns $default without calling the work, and counts the observation
 *   as dropped. When the breaker changes from closed to open, each
 *   whenBreakerOpens() callback runs once, after the failed work, in its own
 *   try/catch.
 * - A data error drops only its own observation. Two lock timeouts of
 *   mirror statements within MIRROR_TIMEOUT_WINDOW_SECONDS
 *   (ErrorKinds::isMirrorTimeout(); a statement timeout never counts) open
 *   the breaker too. A success in between does not reset the
 *   count: a lock on one table (orrery_jobs) leaves the other tables (the
 *   heartbeat) writable.
 * - Every failure is recorded in the degradation record (Degradation) and
 *   reported through Laravel's report(), at most once per operation per 60
 *   seconds per process. The report is a WatchedOperationFailed with the
 *   SafeError summary, no previous exception and an empty trace, never the
 *   original error. When the report itself throws, one
 *   error_log() line with the same summary replaces it.
 * - A successful watched operation that ran at least one statement on the
 *   mirror session, and in which no watched work failed, is a recovery
 *   (Degradation::recovered()).
 * - The breaker's close is a flush point of the degradation record.
 * - The breaker reaches the next processes through the SharedBreaker:
 *   while its own breaker is closed, the watcher reads the store at
 *   most once per second (SHARED_READ_SECONDS), and it writes the store when
 *   a mirror dependency error opens its breaker. So a new application under
 *   PHP-FPM does not wait the connect timeout of an unreachable mirror
 *   again. Every flush, the close's flush included, reads the store once
 *   more first, so it does not write into a mirror that another process
 *   found unreachable meanwhile. A breaker that lock
 *   timeouts opened is not shared, and it stops only the flushes that are
 *   not the process's last (skipsFlush()).
 *
 * The parent framework calls are never watched: only the package's own work
 * runs here, so real queue errors propagate unchanged.
 *
 * The watcher, its breaker and its counters are process state: a container
 * singleton. The static methods act on the application's
 * watcher (instance()).
 */
final class Watcher
{
    public const REPORT_INTERVAL_SECONDS = 60.0;

    /** Two mirror lock timeouts within this many seconds open the breaker. */
    public const MIRROR_TIMEOUT_WINDOW_SECONDS = 60.0;

    private const DEFAULT_BREAKER_SECONDS = 30.0;

    private bool $rethrow = false;

    /** @var list<string> */
    private array $failOperations = [];

    /** The statements that the mirror sessions of this process ran (see trackMirror()). */
    private int $mirrorStatements = 0;

    /** False while the degradation record flushes: its own write is no recovery. */
    private bool $countMirrorStatements = true;

    /** The failures of watched work in this process. */
    private int $failures = 0;

    /** The monotonic time of the last timeout of a mirror statement (ErrorKinds::isMirrorTimeout()), or null. */
    private ?float $lastMirrorTimeoutAt = null;

    /** Seconds that tests add to the monotonic clock (advanceMonotonicForTesting()). */
    private float $monotonicOffset = 0.0;

    /** The frozen base of the monotonic clock (freezeMonotonicForTesting()), or null while it runs. */
    private ?float $frozenMonotonic = null;

    private readonly Breaker $breaker;

    /** @var list<Closure(): void> */
    private array $breakerCallbacks = [];

    /** @var array<string, float> the monotonic time of the last report per operation */
    private array $reportedAt = [];

    /** @var WeakMap<Connection, true> the mirror sessions whose statements are counted */
    private WeakMap $tracked;

    /** The degradation record of the application, kept after its first use (both are process singletons). */
    private ?Degradation $degradation = null;

    /** The package sessions of the application, kept after their first use. */
    private ?PackageSession $sessions = null;

    /** The least seconds between two reads of the shared breaker. */
    public const SHARED_READ_SECONDS = 1.0;

    /** The monotonic time of the last read of the shared breaker, or null. */
    private ?float $sharedReadAt = null;

    /** The open breaker was opened by a mirror dependency error (or adopted from the shared breaker). */
    private bool $dependencyOpen = false;

    /** A failed write of the shared breaker was reported (once per process). */
    private bool $sharedFailureReported = false;

    public function __construct(?float $breakerSeconds = null, private readonly ?SharedBreaker $shared = null)
    {
        $this->breaker = new Breaker($breakerSeconds ?? self::DEFAULT_BREAKER_SECONDS, $this->now(...));
        $this->tracked = new WeakMap;
    }

    /**
     * The watcher of the running application.
     */
    public static function instance(): self
    {
        return Container::getInstance()->make(self::class);
    }

    /**
     * @template T
     * @template D
     *
     * @param  callable(): T  $work
     * @param  D  $default
     * @return T|D
     */
    public function run(string $operation, callable $work, mixed $default = null): mixed
    {
        $result = $this->attempt($operation, $work);

        return $result->succeeded() ? $result->value() : $default;
    }

    /**
     * Like run(), with an explicit result: a skipped or failed work gives a
     * failed result, never a null value that a caller could read as success.
     *
     * @param  callable(): mixed  $work
     */
    public function attempt(string $operation, callable $work): WatchResult
    {
        $this->closeExpiredBreaker();
        $this->adoptSharedBreaker();

        if ($this->breaker->isOpen()) {
            Degradation::dropped($operation);

            return WatchResult::failed(new BreakerOpen("The mirror breaker is open, so the package skipped {$operation}."));
        }

        $unreachable = $this->trackMirror();

        if ($unreachable !== null) {
            // The mirror session could not be built (a pooled Postgres
            // session connects in its build): this work fails
            // as the dependency error, so the breaker opens now, and the
            // next work does not pay the connect timeout again.
            $this->failed($operation, $unreachable);

            return WatchResult::failed($unreachable);
        }

        $statements = $this->mirrorStatements;
        $failures = $this->failures;

        try {
            foreach ($this->failOperations as $prefix) {
                if (str_starts_with($operation, $prefix)) {
                    throw new RuntimeException("Watcher::failOperationsForTesting() fails {$operation}.");
                }
            }

            $value = $work();
        } catch (Throwable $error) {
            if ($error instanceof TimeoutExceededException) {
                // The worker's own timeout (Worker::$killOnTimeout false), not a package failure.
                throw $error;
            }

            if (ErrorKinds::isHostFileRefusal($error)) {
                // D18: no automatic access to a host SQLite file. A skip: no
                // report, no drop, never a dependency error. The caller's
                // default stands for unknown.
                return WatchResult::failed($error);
            }

            $this->failed($operation, $error);

            if ($this->rethrow) {
                throw $error;
            }

            return WatchResult::failed($error);
        }

        if ($this->mirrorStatements > $statements && $this->failures === $failures) {
            $this->shared?->clearProbe();
            $this->degradation ??= Container::getInstance()->make(Degradation::class);
            $this->degradation->markRecovered();
        }

        return WatchResult::ok($value);
    }

    /**
     * Reports a problem through Laravel's report(), at most once per
     * operation per 60 seconds, as a WatchedOperationFailed (its message is
     * SafeError::summary(), so it carries no job value). A report that
     * throws is replaced by one error_log() line with the same summary. It
     * never throws.
     */
    public function report(string $operation, Throwable|string $problem): void
    {
        $now = $this->now();
        $last = $this->reportedAt[$operation] ?? null;

        if ($last !== null && $now - $last < self::REPORT_INTERVAL_SECONDS) {
            return;
        }

        $this->reportedAt[$operation] = $now;

        try {
            // Never the original error: its message, its previous chain and its trace can carry job values.
            $error = WatchedOperationFailed::of($operation, $problem, __FILE__, __LINE__);
            report($error);
        } catch (Throwable $failure) {
            try {
                error_log(self::oneLine(sprintf(
                    'orrery: %s Its report failed: %s',
                    SafeError::summary($operation, $problem),
                    SafeError::describe($failure),
                )));
            } catch (Throwable) {
                // Nothing is left to report to.
            }
        }
    }

    public function breakerOpen(): bool
    {
        $this->closeExpiredBreaker();
        $this->adoptSharedBreaker();

        return $this->breaker->isOpen();
    }

    /**
     * Whether the breaker is open because the mirror could not be reached (a
     * dependency error here or in another process), not because of lock
     * timeouts. It changes no state, so the degradation flush can ask it.
     */
    public function dependencyBreakerOpen(): bool
    {
        return $this->dependencyOpen && $this->breaker->isOpen();
    }

    /**
     * Whether a degradation flush must skip its write now: while a breaker
     * that a mirror dependency error opened is open (here, or in another
     * process: the store is read again now, without the one-second limit),
     * and, for a flush that is not the process's last ($terminating false,
     * for example the heartbeat flush), while any breaker is open. The close
     * of the breaker flushes the record anyway. A breaker that expired is
     * closed first; its own flush does nothing while a flush runs. So the
     * close of an expired breaker never flushes into a mirror that another
     * process found unreachable meanwhile.
     */
    public function skipsFlush(bool $terminating): bool
    {
        $this->closeExpiredBreaker();
        $this->adoptSharedBreaker(true);

        return $this->dependencyBreakerOpen() || (! $terminating && $this->breaker->isOpen());
    }

    /**
     * $callback runs once each time the breaker changes from closed to open,
     * after the failed work. A callback error is reported, and the next
     * callback still runs.
     */
    public function whenBreakerOpens(Closure $callback): void
    {
        $this->breakerCallbacks[] = $callback;
    }

    /**
     * The test switch: watched work rethrows its error after the
     * watcher recorded it. Isolation tests never use it.
     */
    public static function rethrowForTesting(bool $rethrow = true): void
    {
        self::instance()->rethrow = $rethrow;
    }

    /**
     * @param  list<string>  $operationPrefixes  run() treats these operations as data errors, without calling their work
     */
    public static function failOperationsForTesting(array $operationPrefixes): void
    {
        self::instance()->failOperations = $operationPrefixes;
    }

    /**
     * Runs $work without counting its mirror statements as a recovery (the
     * degradation record's own write).
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function withoutRecovery(callable $work): mixed
    {
        $watcher = self::instance();
        $previous = $watcher->countMirrorStatements;
        $watcher->countMirrorStatements = false;

        try {
            return $work();
        } finally {
            $watcher->countMirrorStatements = $previous;
        }
    }

    /**
     * The clock of the breaker and the report limit: Clock::monotonic(), so
     * these durations never follow a change of the wall clock (plan, "Time").
     */
    public static function monotonic(): float
    {
        return self::instance()->now();
    }

    /**
     * Stops the watcher's monotonic clock at its current value (tests only):
     * the breaker window, the report limit, the monitoring cache and the
     * signal cache then change only through advanceMonotonicForTesting().
     * A new application's watcher runs again.
     */
    public static function freezeMonotonicForTesting(): void
    {
        self::instance()->frozenMonotonic = Clock::monotonic();
    }

    /**
     * Lets $seconds elapse on the watcher's monotonic clock (tests only).
     */
    public static function advanceMonotonicForTesting(float $seconds): void
    {
        self::instance()->monotonicOffset += $seconds;
    }

    /**
     * The watcher's monotonic clock: Clock::monotonic(), or its frozen base,
     * plus the test offset.
     */
    private function now(): float
    {
        return ($this->frozenMonotonic ?? Clock::monotonic()) + $this->monotonicOffset;
    }

    private function failed(string $operation, Throwable $error): void
    {
        $this->failures++;
        Degradation::error($operation, $error);
        Degradation::dropped($operation);
        $this->report($operation, $error);

        $dependency = ErrorKinds::isMirrorDependency($error);

        if (! $dependency && ! $this->secondMirrorTimeout($error)) {
            return;
        }

        $this->openBreaker($dependency);
    }

    /**
     * A degradation flush whose write reached the mirror: a probe claim that
     * the flush took (skipsFlush() reads the shared store) ends, so the
     * other processes stop skipping the mirror.
     */
    public function flushSucceeded(): void
    {
        $this->shared?->clearProbe();
    }

    /**
     * A degradation flush whose write failed: a mirror dependency error opens
     * and shares the breaker, as in failed work, so the next watched work in
     * this process (the work after the close of an expired breaker) and the
     * next processes do not pay the connect timeout again. Another error
     * changes nothing. The flush logs the error itself; it is no dropped
     * work.
     */
    public function flushFailed(Throwable $error): void
    {
        if (ErrorKinds::isMirrorDependency($error)) {
            $this->openBreaker(true);
        }
    }

    /**
     * Opens the breaker (a mirror dependency error, else a second lock
     * timeout); a newly opened one is shared when a dependency error opened
     * it, and runs the whenBreakerOpens() callbacks.
     */
    private function openBreaker(bool $dependency): void
    {
        if (! $this->breaker->open()) {
            if ($dependency && ! $this->dependencyOpen) {
                // A breaker of lock timeouts is open, and now the mirror is
                // lost: it becomes a dependency breaker, which other
                // processes must see too.
                $this->dependencyOpen = true;
                $this->share();
            }

            return;
        }

        $this->dependencyOpen = $dependency;

        if ($dependency) {
            $this->share();
        }

        foreach ($this->breakerCallbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $callbackError) {
                $this->report('breaker_callback', $callbackError);
            }
        }
    }

    /**
     * Whether $error is the second timeout of a mirror statement within
     * MIRROR_TIMEOUT_WINDOW_SECONDS: a mirror whose writes
     * are blocked makes every observation wait its lock timeout, as an
     * unreachable one makes it wait its connect timeout. Successes in
     * between do not reset the count: a lock on one table leaves the others
     * writable.
     */
    private function secondMirrorTimeout(Throwable $error): bool
    {
        if (! ErrorKinds::isMirrorTimeout($error)) {
            return false;
        }

        $now = $this->now();
        $last = $this->lastMirrorTimeoutAt;

        if ($last === null || $now - $last > self::MIRROR_TIMEOUT_WINDOW_SECONDS) {
            $this->lastMirrorTimeoutAt = $now;

            return false;
        }

        $this->lastMirrorTimeoutAt = null;

        return true;
    }

    /**
     * Writes the open breaker to the shared store. A store that is on but
     * cannot be written (a read-only or missing directory) is reported once
     * per process.
     */
    private function share(): void
    {
        if ($this->shared === null || $this->shared->open($this->breaker->seconds()) || $this->sharedFailureReported) {
            return;
        }

        $this->sharedFailureReported = true;
        $this->report('shared_breaker', 'The shared breaker file could not be written in orrery.observer.shared_breaker_path, so other processes do not see the open breaker.');
    }

    /**
     * Opens the breaker for what remains of a breaker that another process
     * opened (SharedBreaker). It reads the store only while this breaker is
     * closed, at most once per SHARED_READ_SECONDS unless $force, and not at
     * all when the store is off. An adopted breaker runs no
     * whenBreakerOpens() callback: its opening happened elsewhere.
     */
    private function adoptSharedBreaker(bool $force = false): void
    {
        if ($this->shared === null || ! $this->shared->enabled() || $this->breaker->isOpen()) {
            return;
        }

        $now = $this->now();

        if (! $force && $this->sharedReadAt !== null && $now - $this->sharedReadAt < self::SHARED_READ_SECONDS) {
            return;
        }

        $this->sharedReadAt = $now;
        $remaining = $this->shared->remaining($this->breaker->seconds());

        if ($remaining !== null && $this->breaker->openFor($remaining)) {
            $this->dependencyOpen = true;
        }
    }

    /**
     * Closes an expired breaker and flushes the degradation record. The
     * flush reads the store first (skipsFlush()): when another process
     * opened the breaker again meanwhile, the breaker opens for what
     * remains of that, and the flush skips its write.
     */
    private function closeExpiredBreaker(): void
    {
        if ($this->breaker->closeIfExpired()) {
            $this->dependencyOpen = false;
            Degradation::flush();
        }
    }

    /**
     * Counts the statements of the current package session (the mirror,
     * PackageSession::mirror()) through a beforeExecuting callback on that connection instance. A new instance
     * (after PackageSession::releaseAll()) gets its own callback.
     *
     * Returns the error of a mirror session that cannot be built because of
     * a mirror dependency error (ErrorKinds::isMirrorDependency()); any
     * other error of the build is ignored here (the work meets it).
     */
    private function trackMirror(): ?Throwable
    {
        try {
            $this->sessions ??= Container::getInstance()->make(PackageSession::class);
            $mirror = $this->sessions->mirror();
        } catch (Throwable $error) {
            return ErrorKinds::isMirrorDependency($error) ? $error : null;
        }

        if (isset($this->tracked[$mirror])) {
            return null;
        }

        $this->tracked[$mirror] = true;
        $mirror->beforeExecuting(function (): void {
            if ($this->countMirrorStatements) {
                $this->mirrorStatements++;
            }
        });

        return null;
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
