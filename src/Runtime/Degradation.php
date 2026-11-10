<?php

namespace Justrau\Orrery\Runtime;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Log;
use Justrau\Orrery\Storage\Clock;
use Throwable;

/**
 * The in-memory degradation record of this process, and its
 * flush to orrery_health_events.
 *
 * Windows:
 * - A window opens at the first dropped, unmatched, unverified or error
 *   event. Its started_at is the time of that event.
 * - Its ended_at is the time of the first successful watched mirror
 *   operation after its last event (recovered()). A later event makes it
 *   open again (ended_at null). A terminating flush writes the row with
 *   the time of the flush as its end, because the process ends with it;
 *   the record in memory stays open, so a long-lived process
 *   writes the row open again at its next event. It ends the row also when
 *   nothing changed since an earlier flush wrote it open (the end of
 *   orrery:tick, a heartbeat).
 * - One row exists per (process, started_at). A flush writes the whole
 *   record to that row. A flush of a recovered window closes it: the next
 *   event opens a new window with a new row.
 * - A flush skips its write while a breaker that a mirror dependency error
 *   opened is open, here or in another process (Watcher::skipsFlush()):
 *   the mirror could not be reached, so the write would only
 *   wait for its connect timeout. The record stays in memory, and the close
 *   of the breaker flushes it. A flush that is not terminating (the
 *   heartbeat flush) also skips while a breaker of lock timeouts is open;
 *   a terminating flush writes then. A terminating flush that skips its write logs the record
 *   once (Log::warning), because the process ends with it: a web request
 *   or a command such as orrery:tick. When a write fails, it logs the
 *   record once and keeps it in memory for the next flush.
 *
 * The flush points are each heartbeat write, the application's terminating
 * callback (once per terminating, ObservationModule), and the close of the
 * breaker. The hook points
 * health.before_flush and health.after_flush (Hooks::reach()) surround a
 * flush that has a record to write.
 */
final class Degradation
{
    public const BEFORE_FLUSH = 'health.before_flush';

    public const AFTER_FLUSH = 'health.after_flush';

    /**
     * @var array{started_at: float, ended_at: ?float, dropped: int, unmatched_observations: int, unverified: int, rollback_unverified: int, last_error_operation: ?string, last_error_at: ?float, errors: array<string, float>}|null
     */
    private ?array $window = null;

    /** The record changed since its last successful flush. */
    private bool $dirty = false;

    /** A failed flush of the current record was logged already. */
    private bool $logged = false;

    /** A skipped terminating flush was logged already. */
    private bool $skipLogged = false;

    /** A flush runs: a nested flush (a hook, a log handler) does nothing. */
    private bool $flushing = false;

    /** The last write left the row open (no end): a terminating flush must end it. */
    private bool $writtenOpen = false;

    public static function dropped(string $operation): void
    {
        $window = &self::event();
        $window['dropped']++;
    }

    public static function unmatched(): void
    {
        $window = &self::event();
        $window['unmatched_observations']++;
    }

    /**
     * Observations that the commit deferral dropped without a proof of their
     * outcome: the `unverified` counter (named
     * `ownership_unverified` before).
     */
    public static function unverified(int $items = 1): void
    {
        if ($items <= 0) {
            return;
        }

        $window = &self::event();
        $window['unverified'] += $items;
    }

    /**
     * Observations under a root transaction that rolled back:
     * usually a business rollback after a push; a lost COMMIT
     * acknowledgement cannot be ruled out.
     */
    public static function rollbackUnverified(int $items = 1): void
    {
        if ($items <= 0) {
            return;
        }

        $window = &self::event();
        $window['rollback_unverified'] += $items;
    }

    public static function error(string $operation, Throwable|string $problem): void
    {
        $now = Clock::now();
        $window = &self::event();
        $window['last_error_operation'] = $operation;
        $window['last_error_at'] = $now;
        $window['errors'][$operation] = $now;
    }

    /**
     * Work that did not run and is not the cause of the degradation (a tick
     * phase that the runner skipped after the deadline). It counts as dropped
     * and is kept in the errors map, but it never replaces
     * last_error_operation, so the stored row keeps naming the cause.
     */
    public static function skipped(string $operation): void
    {
        $now = Clock::now();
        $window = &self::event();
        $window['dropped']++;
        $window['errors'][$operation] = $now;
    }

    /**
     * The watcher calls it after a successful watched mirror operation: an
     * open window gets its end.
     */
    public static function recovered(): void
    {
        self::bound()->markRecovered();
    }

    /**
     * recovered() on this instance: the watcher keeps its degradation record,
     * so a successful watched operation needs no container lookup.
     */
    public function markRecovered(): void
    {
        $window = $this->window;

        if ($window === null || $window['ended_at'] !== null) {
            return;
        }

        $window['ended_at'] = Clock::now();
        $this->window = $window;
        $this->dirty = true;
    }

    /**
     * Writes or updates this process's window. It skips the write while
     * Watcher::skipsFlush() says so; with $terminating (the last flush of
     * the process) it then logs the record once, and else writes the row
     * with its time as the end. It never throws.
     */
    public static function flush(bool $terminating = false): void
    {
        $state = self::bound();

        if ($state->window === null || $state->flushing) {
            return;
        }

        if (! $state->dirty && ! ($terminating && $state->writtenOpen)) {
            // Nothing changed since the last write. A terminating flush still
            // ends a row that an earlier flush of this process wrote open
            // (the end of orrery:tick, a heartbeat).
            return;
        }

        $state->flushing = true;

        try {
            $watcher = Watcher::instance();

            if ($watcher->skipsFlush($terminating)) {
                if ($terminating) {
                    self::logSkipped($state, $state->window);
                }

                return;
            }

            Hooks::reach(self::BEFORE_FLUSH);

            $window = $state->window;
            $written = $window;

            if ($terminating && $written['ended_at'] === null) {
                // The row ends with the process: an open row would cover every later
                // range. The record stays open in memory: a long-lived process (Octane) that has a
                // later event writes the same row open again.
                $written['ended_at'] = Clock::now();
            }

            try {
                $context = ProcessContext::current();
                Watcher::withoutRecovery(static fn () => HealthEvents::write($context->kind(), $context->identity(), $written));
            } catch (Throwable $error) {
                self::logOnce($window, $error);
                // A lost mirror opens the breaker, so the work after this flush does not connect again.
                $watcher->flushFailed($error);

                return;
            }

            $state->writtenOpen = $written['ended_at'] === null;
            // A later outage of a long-lived process (Octane) logs its skipped flush again.
            $state->skipLogged = false;
            // A probe claim that this flush took (skipsFlush() reads the store) ends: the mirror answers.
            $watcher->flushSucceeded();

            // An event during the write keeps the record dirty.
            if ($state->window === $window) {
                $state->dirty = false;
                $state->logged = false;

                if ($window['ended_at'] !== null) {
                    $state->window = null;
                }
            }

            Hooks::reach(self::AFTER_FLUSH);
        } finally {
            $state->flushing = false;
        }
    }

    /**
     * The current window, or null. The errors map holds the last time of
     * each failed operation.
     *
     * @return array{started_at: float, ended_at: ?float, dropped: int, unmatched_observations: int, unverified: int, rollback_unverified: int, last_error_operation: ?string, last_error_at: ?float, errors: array<string, float>}|null
     */
    public static function record(): ?array
    {
        return self::bound()->window;
    }

    public static function reset(): void
    {
        $state = self::bound();

        $state->window = null;
        $state->dirty = false;
        $state->logged = false;
        $state->skipLogged = false;
        $state->flushing = false;
        $state->writtenOpen = false;
    }

    /**
     * The open window, opened by this event when there is none. Every event
     * makes the window open again and the record dirty.
     *
     * @return array{started_at: float, ended_at: ?float, dropped: int, unmatched_observations: int, unverified: int, rollback_unverified: int, last_error_operation: ?string, last_error_at: ?float, errors: array<string, float>}
     */
    private static function &event(): array
    {
        $state = self::bound();

        $window = $state->window ?? [
            'started_at' => Clock::now(),
            'ended_at' => null,
            'dropped' => 0,
            'unmatched_observations' => 0,
            'unverified' => 0,
            'rollback_unverified' => 0,
            'last_error_operation' => null,
            'last_error_at' => null,
            'errors' => [],
        ];

        $window['ended_at'] = null;
        $state->window = $window;
        $state->dirty = true;

        return $state->window;
    }

    /**
     * @param  array<string, mixed>  $window
     */
    private static function logOnce(array $window, Throwable $error): void
    {
        $state = self::bound();

        if ($state->logged) {
            return;
        }

        $state->logged = true;

        try {
            if (Container::getInstance()->bound('log')) {
                Log::warning('Orrery could not write its degradation record to orrery_health_events. The record stays in memory until the next flush.', [
                    'record' => $window,
                    // The summary, never the raw message: it can carry job values.
                    'error' => SafeError::summary('health:flush', $error),
                ]);
            }
        } catch (Throwable) {
            // The log is the last place to report to.
        }
    }

    /**
     * @param  array<string, mixed>  $window
     */
    private static function logSkipped(self $state, array $window): void
    {
        if ($state->skipLogged) {
            return;
        }

        $state->skipLogged = true;

        try {
            if (Container::getInstance()->bound('log')) {
                Log::warning('Orrery could not reach the package database, so it did not write its degradation record to orrery_health_events before the process ended.', [
                    'record' => $window,
                ]);
            }
        } catch (Throwable) {
            // The log is the last place to report to.
        }
    }

    /**
     * The application's instance (a container binding).
     */
    private static function bound(): self
    {
        return Container::getInstance()->make(self::class);
    }
}
