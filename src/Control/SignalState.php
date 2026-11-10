<?php

namespace Justrau\Orrery\Control;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Carbon;
use Justrau\Orrery\Database\Models\Signal;
use Justrau\Orrery\Monitoring\MeasurementCache;
use Justrau\Orrery\Monitoring\NativePause;
use Justrau\Orrery\Observation\DatabaseDriverFilter;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\SafeText;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use Throwable;

/**
 * The control plane's read and write API.
 * Worker-side code (DatabaseQueue::pop() and the Looping listener) reads
 * through WorkerControl; the dashboard and the orrery:* commands write
 * through the same class.
 *
 * Scopes: `global` (scope_value ''), `connection` ('<connection>') and
 * `queue` ('<connection>:<queue>').
 *
 * - Pause is an OR of the scopes: a queue (c, q) is paused when a
 *   global pause row exists, or a connection pause row for c, or Laravel's
 *   QueueManager::isPaused(c, q) is true. There are no continue rows, and
 *   no scope overrides another (PauseCauses gives the causes).
 * - pause(global) and pause(connection, c) write their row and delete no
 *   narrower pause. pause(queue, c:q) calls Laravel's pause(c, q) and
 *   upserts a display-only annotation row (signal pause, scope queue) with
 *   the actor; the annotation never decides anything.
 * - continue(scope) deletes the pause row of exactly that scope; for a
 *   queue it calls Laravel's resume(c, q) and deletes the annotation. Its
 *   result (ContinueResult) names every other cause that still pauses the
 *   target. Each pause and continue locks the settings row control_version.
 * - Workers read only the global and connection rows and terminate
 *   (connectionPaused()): Laravel's worker skips a natively paused queue
 *   before it calls pop(), so a worker reads no cache key for the pause.
 * - Terminate is one-shot: the newest applicable, unexpired signal decides
 *   (freshness before specificity), and it applies when its issued_at is
 *   later than the worker's start.
 * - Terminate uses one time domain, the clock of the mirror's database
 *   server (Storage\DatabaseClock): terminate() stores issued_at and
 *   expires_at from it, and a worker that has an applicable terminate row
 *   calibrates once (offset = database time - local time) and judges the
 *   issue time against its start + offset and the expiry against its local
 *   time + offset. A failed calibration is recorded as
 *   `control:clock_uncalibrated`; the worker then judges with its local
 *   clock and tries again at its next signal read with such a row. Pause
 *   rows keep their local issued_at, which is only shown.
 * - Expired signals are ignored at read time; orrery:tick deletes them, and
 *   the queue annotations whose native flag is off (SignalExpiryPhase).
 * - Reads are cached for one second per process. When a read fails, the
 *   last successful read stays in effect for
 *   `orrery.control.stale_signals_seconds` (default 900). After that, the
 *   worker fails open (no signal applies), reports it once and records it
 *   in the degradation record (`control:signals_stale`), which the health
 *   events flush.
 */
class SignalState
{
    public const PAUSE = 'pause';

    public const TERMINATE = 'terminate';

    public const DEFAULT_STALE_SECONDS = 900;

    /** The lifetime of a terminate signal, in minutes. */
    public const TERMINATE_TTL_MINUTES = 30;

    /** The degradation operation of a failed clock calibration. */
    public const CLOCK_UNCALIBRATED = 'control:clock_uncalibrated';

    /** The orrery_settings row that serialises the scope replacements. */
    public const VERSION_KEY = 'control_version';

    protected const float CACHE_TTL_SECONDS = 1.0;

    /**
     * Should a worker on the connection skip job reservation? True when a
     * global pause row or a pause row of the connection applies (the
     * cached, unexpired rows). A queue pause is Laravel's own: its worker
     * skips the queue before pop(), so this never reads a cache key.
     */
    public function connectionPaused(string $connection): bool
    {
        foreach ($this->signals() as $row) {
            if ($row['signal'] === self::PAUSE
                && ($row['scope'] === 'global' || ($row['scope'] === 'connection' && $row['scope_value'] === $connection))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The unexpired pause rows with their actor, read now without the worker
     * cache (the dashboard's pause state; PauseCauses): the
     * global and connection rows and the queue annotations. One statement.
     * Expiry uses the same wall clock as the worker reads.
     *
     * @return list<array{signal: string, scope: string, scope_value: string, issued_at: float, issued_by: ?string}>
     */
    public function pauseRows(): array
    {
        $wall = Carbon::now()->getTimestamp();
        $rows = [];

        foreach (Signal::query()->where('signal', self::PAUSE)->get() as $signal) {
            $expiresAt = $signal->expires_at?->getTimestamp();

            if ($expiresAt !== null && $expiresAt <= $wall) {
                continue;
            }

            $rows[] = [
                'signal' => (string) $signal->signal,
                'scope' => (string) $signal->scope,
                'scope_value' => (string) $signal->scope_value,
                'issued_at' => (float) $signal->issued_at,
                'issued_by' => $signal->issued_by === null ? null : (string) $signal->issued_by,
            ];
        }

        return $rows;
    }

    /**
     * The issued_at of the newest applicable, unexpired terminate signal when
     * it is later than the worker's start, else null. Both comparisons run
     * in the database clock's domain: $workerStartedAt and the local
     * now are moved by the calibration offset. Without an applicable
     * terminate row, no clock statement runs.
     */
    public function terminateIssuedAfter(string $connection, string $queue, float $workerStartedAt): ?float
    {
        $applicable = [];

        foreach ($this->rows() as $row) {
            if ($row['signal'] !== self::TERMINATE) {
                continue;
            }

            foreach (self::scopes($connection, $queue) as [$scope, $value]) {
                if ($row['scope'] === $scope && $row['scope_value'] === $value) {
                    $applicable[] = $row;
                }
            }
        }

        if ($applicable === []) {
            return null;
        }

        // Rows that expired more than one TTL ago by the local clock (a tick that
        // never deleted them) need no calibration: no clock error that large is
        // corrected, and a new worker would otherwise read the clock forever.
        $stale = Clock::now() - self::TERMINATE_TTL_MINUTES * 60;

        if (array_filter($applicable, static fn (array $row): bool => $row['expires_at'] === null || $row['expires_at'] >= $stale) === []) {
            return null;
        }

        $offset = self::clockOffset() ?? 0.0;
        $now = Clock::now() + $offset;
        $newest = null;

        foreach ($applicable as $row) {
            if ($row['expires_at'] === null || $row['expires_at'] > $now) {
                $newest = max($newest ?? $row['issued_at'], $row['issued_at']);
            }
        }

        return $newest !== null && $newest > $workerStartedAt + $offset ? $newest : null;
    }

    /**
     * Issues or refreshes a pause for the scope, as an operator action. A
     * global or connection pause writes its row and deletes no narrower
     * pause. A queue pause calls Laravel's QueueManager::pause() first (also
     * under each failover connection whose first connection is the target,
     * its worker asks under its own name), outside the package
     * transaction, so the application's QueuePaused listeners and
     * the cache write never run under the package's lock, and a busy retry of
     * the transaction never pauses twice (an error reaches the caller, and no
     * annotation is written). Then one upsert on the unique key (signal,
     * scope, scope_value) writes the row under the control_version lock: no
     * read before the write, so a concurrent signal_expiry delete can never
     * leave the refresh without its row. $issuedBy is the actor
     * (Orrery::actor(), or `cli`), cleaned again at this storage boundary
     * (SafeText).
     */
    public function pause(string $scope, string $scopeValue, ?string $issuedBy = null): void
    {
        PackageSession::asOperator(function () use ($scope, $scopeValue, $issuedBy): void {
            if ($scope === 'queue') {
                [$connection, $queue] = self::split($scopeValue);
                self::manager()->pause($connection, $queue);

                // A worker on a failover connection asks under its own name.
                foreach (DatabaseDriverFilter::failoversOf($connection) as $failover) {
                    self::manager()->pause($failover, $queue);
                }
            }

            $write = fn () => $this->atomically(static function () use ($scope, $scopeValue, $issuedBy): void {
                Signal::query()->upsert(
                    [[
                        'signal' => self::PAUSE,
                        'scope' => $scope,
                        'scope_value' => $scopeValue,
                        'issued_at' => Clock::toDecimal(Clock::now()),
                        'expires_at' => null,
                        'issued_by' => SafeText::clean($issuedBy),
                    ]],
                    ['signal', 'scope', 'scope_value'],
                    ['issued_at', 'expires_at', 'issued_by'],
                );
            });

            if ($scope !== 'queue') {
                $write();

                return;
            }

            try {
                $write();
            } catch (Throwable $error) {
                // Laravel's pause is set already: say so.
                self::changed();

                throw new QueuePauseRecordFailed(true, $scopeValue, $error);
            }
        });

        self::changed();
    }

    /**
     * Lifts the pause of exactly this scope, as an operator action: it
     * deletes the scope's pause row, and for a queue it first reads Laravel's
     * state of the queue, then calls QueueManager::resume() (also under each
     * failover connection of the target) outside the
     * package transaction (as pause() does) and deletes the annotation. A
     * broader or narrower pause stays. The result names the lifted pause,
     * every other cause that still pauses the target, and `liftUnknown` when
     * nothing was deleted but resume() may have lifted a flag whose state
     * was hidden (Laravel's global pause) or unreadable (ContinueResult). The
     * native state is read also when the cache store is on a host SQLite
     * file. A continue writes no row, so it stores no actor.
     */
    public function continue(string $scope, string $scopeValue): ContinueResult
    {
        $result = PackageSession::asOperator(function () use ($scope, $scopeValue): ContinueResult {
            $native = Container::getInstance()->make(NativePause::class);
            $before = null;

            if ($scope === 'queue') {
                [$connection, $queue] = self::split($scopeValue);
                $before = $native->read([[$connection, $queue]])[NativePause::key($connection, $queue)];
                self::manager()->resume($connection, $queue);

                foreach (DatabaseDriverFilter::failoversOf($connection) as $failover) {
                    self::manager()->resume($failover, $queue);
                }
            }

            $record = function () use ($scope, $scopeValue, $before, $native): ContinueResult {
                $deleted = $this->atomically(static fn (): int => (int) Signal::query()
                    ->where('signal', self::PAUSE)
                    ->where('scope', $scope)
                    ->where('scope_value', $scopeValue)
                    ->delete());

                $removed = $deleted > 0 || ($before['queue'] ?? null) === true ? 1 : 0;
                $liftUnknown = $before !== null && $removed === 0 && $before['queue'] === null;

                return $this->stillPaused($scope, $scopeValue, $removed, $native, $liftUnknown);
            };

            if ($scope !== 'queue') {
                return $record();
            }

            try {
                return $record();
            } catch (Throwable $error) {
                // Laravel's pause is lifted already: say so.
                self::changed();

                throw new QueuePauseRecordFailed(false, $scopeValue, $error);
            }
        });

        self::changed();

        return $result;
    }

    /**
     * Issues a terminate signal. Workers that started before it stop at their
     * next poll. $issuedBy is the actor, cleaned (SafeText). issued_at and
     * expires_at come from the database clock; when that read fails,
     * the error reaches the caller and no signal is written.
     */
    public function terminate(string $scope, string $scopeValue, int $ttlMinutes = self::TERMINATE_TTL_MINUTES, ?string $issuedBy = null): void
    {
        $now = DatabaseClock::now(Container::getInstance()->make(PackageSession::class)->mirror());

        Signal::query()->updateOrCreate(
            ['signal' => self::TERMINATE, 'scope' => $scope, 'scope_value' => $scopeValue],
            [
                'issued_at' => Clock::toDecimal($now),
                'expires_at' => self::dateTime($now + $ttlMinutes * 60),
                'issued_by' => SafeText::clean($issuedBy),
            ],
        );

        self::flushCache();
    }

    /**
     * The causes that still pause the target after a continue: the Orrery
     * pauses of the broader scopes, Laravel's global pause (any target),
     * and Laravel's own flag of a queue target.
     */
    private function stillPaused(string $scope, string $scopeValue, int $removed, NativePause $native, bool $liftUnknown): ContinueResult
    {
        $connection = match ($scope) {
            'queue' => self::split($scopeValue)[0],
            'connection' => $scopeValue,
            default => (string) Container::getInstance()->make('config')->get('queue.default', ''),
        };
        $queue = $scope === 'queue' ? self::split($scopeValue)[1] : NativePause::PROBE_QUEUE;
        // A global or connection continue needs only Laravel's global pause, which Laravel 12 does not have: no read then.
        $state = $scope !== 'queue' && ! NativePause::laravelHasGlobalPause()
            ? ['queue' => false, 'global' => false, 'unknown' => null]
            : $native->read([[$connection, $queue]])[NativePause::key($connection, $queue)];
        $rows = Signal::query()->where('signal', self::PAUSE)->whereIn('scope', ['global', 'connection'])->get(['scope', 'scope_value']);
        $still = [];

        if ($scope !== 'global' && $rows->contains(static fn (Signal $row): bool => $row->scope === 'global')) {
            $still[] = ['scope' => 'global', 'value' => '', 'source' => PauseCauses::ORRERY];
        }

        if ($state['global'] === true) {
            $still[] = ['scope' => 'global', 'value' => '', 'source' => PauseCauses::QUEUE_PAUSE_ALL];
        }

        if ($scope === 'queue' && $rows->contains(static fn (Signal $row): bool => $row->scope === 'connection' && $row->scope_value === $connection)) {
            $still[] = ['scope' => 'connection', 'value' => $connection, 'source' => PauseCauses::ORRERY];
        }

        if ($scope === 'queue' && $state['queue'] === true) {
            $still[] = ['scope' => 'queue', 'value' => $scopeValue, 'source' => PauseCauses::QUEUE_PAUSE];
        }

        // Unknown is never "not paused": a failed or skipped native read says so.
        return new ContinueResult($removed, $still, $state['unknown'] === NativePause::FAILED || $state['unknown'] === NativePause::UNSAFE, $liftUnknown);
    }

    /**
     * [connection, queue] of a queue scope value.
     *
     * @return array{0: string, 1: string}
     */
    private static function split(string $scopeValue): array
    {
        $parts = explode(':', $scopeValue, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    private static function manager(): QueueManager
    {
        /** @var QueueManager */
        return Container::getInstance()->make('queue');
    }

    /**
     * After a pause or continue: this process reads the signals again, and
     * the shared measurement (which holds the native state) is measured
     * again at the next request.
     */
    private static function changed(): void
    {
        self::flushCache();
        Container::getInstance()->make(MeasurementCache::class)->forget();
    }

    /**
     * Forces a read at the next check. The cached rows and the stale state
     * are dropped too.
     */
    public static function flushCache(): void
    {
        $cache = self::cache();
        $cache->refreshedAt = 0.0;
        $cache->rows = [];
        $cache->lastReadAt = null;
        $cache->failedOpen = false;
        $cache->clockOffset = null;
        $cache->clockFailedAt = null;
    }

    /**
     * The process's signal cache (a container singleton).
     */
    private static function cache(): SignalCache
    {
        return Container::getInstance()->make(SignalCache::class);
    }

    /**
     * An epoch time of the database clock as the value of the dateTime column
     * expires_at: in UTC, as the model's cast (UtcDateTime) stores and reads
     * it, so the stored strings follow the epoch and the expiry compares in
     * SQL, also across a change of daylight saving time.
     */
    public static function dateTime(float $epoch): Carbon
    {
        return Carbon::createFromTimestamp($epoch, 'UTC');
    }

    /**
     * Every signal row, for the dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $now = Clock::now();

        /** @var \Illuminate\Database\Eloquent\Collection<int, Signal> $rows */
        $rows = Signal::query()
            ->orderBy('scope')
            ->orderBy('scope_value')
            ->orderBy('signal')
            ->get();

        $result = [];
        foreach ($rows as $s) {
            $result[] = [
                'id' => $s->id,
                'signal' => $s->signal,
                'scope' => $s->scope,
                'scope_value' => $s->scope_value,
                'issued_at' => (float) $s->issued_at,
                'issued_seconds_ago' => round($now - (float) $s->issued_at, 1),
                'issued_by' => $s->issued_by,
            ];
        }

        return $result;
    }

    /**
     * The scopes that apply to (connection, queue), most specific first.
     *
     * @return list<array{0: string, 1: string}>
     */
    protected static function scopes(string $connection, string $queue): array
    {
        return [
            ['queue', "{$connection}:{$queue}"],
            ['connection', $connection],
            ['global', ''],
        ];
    }

    /**
     * The unexpired signal rows, read at most once per second. A failed read
     * keeps the last successful read for the stale window, then fails open.
     * Pause and continue rows have no expiry; terminate rows are judged by
     * terminateIssuedAfter() in the database clock's domain.
     *
     * @return list<array{signal: string, scope: string, scope_value: string, issued_at: float, expires_at: ?int}>
     */
    protected function signals(): array
    {
        $wall = Carbon::now()->getTimestamp();

        return array_values(array_filter(
            $this->rows(),
            static fn (array $row): bool => $row['expires_at'] === null || $row['expires_at'] > $wall,
        ));
    }

    /**
     * Every cached signal row, expired ones included, read at most once per
     * second (the cache of signals()).
     *
     * @return list<array{signal: string, scope: string, scope_value: string, issued_at: float, expires_at: ?int}>
     */
    private function rows(): array
    {
        $now = Watcher::monotonic();
        $cache = self::cache();

        if ($cache->refreshedAt === 0.0 || $now - $cache->refreshedAt > self::cacheSeconds()) {
            $cache->refreshedAt = $now;
            $result = Watcher::instance()->attempt('control:signals', static fn (): array => self::read());

            if ($result->succeeded()) {
                $cache->rows = $result->value();
                $cache->lastReadAt = $now;
                $cache->failedOpen = false;
            } elseif ($cache->lastReadAt === null || $now - $cache->lastReadAt > self::staleSeconds()) {
                self::failOpen($result->error());
            }
        }

        return $cache->rows;
    }

    /**
     * The calibration offset (database time - local time), read lazily on
     * the package storage session: at most one attempt per signal read, and
     * kept after the first success. A failure is recorded as
     * `control:clock_uncalibrated` and gives null.
     */
    private static function clockOffset(): ?float
    {
        $cache = self::cache();

        if ($cache->clockOffset !== null) {
            return $cache->clockOffset;
        }

        if ($cache->clockFailedAt !== null && $cache->clockFailedAt === $cache->refreshedAt) {
            return null;
        }

        $result = Watcher::instance()->attempt('control:clock', static function (): float {
            $before = Clock::now();
            $database = DatabaseClock::now(Container::getInstance()->make(PackageSession::class)->mirror());

            return $database - ($before + Clock::now()) / 2;
        });

        if ($result->succeeded()) {
            $cache->clockFailedAt = null;

            return $cache->clockOffset = $result->value();
        }

        $cache->clockFailedAt = $cache->refreshedAt;
        $error = $result->error();
        Degradation::error(self::CLOCK_UNCALIBRATED, 'orrery: the worker could not read the database clock, so it judges the terminate signals with its local clock.'.($error === null ? '' : ' Last error: '.$error->getMessage()));

        return null;
    }

    /**
     * @return list<array{signal: string, scope: string, scope_value: string, issued_at: float, expires_at: ?int}>
     */
    private static function read(): array
    {
        $rows = [];

        foreach (Signal::query()->get() as $signal) {
            $rows[] = [
                'signal' => (string) $signal->signal,
                'scope' => (string) $signal->scope,
                'scope_value' => (string) $signal->scope_value,
                'issued_at' => (float) $signal->issued_at,
                'expires_at' => $signal->expires_at?->getTimestamp(),
            ];
        }

        return $rows;
    }

    private static function failOpen(?Throwable $error): void
    {
        $cache = self::cache();
        $cache->rows = [];

        if ($cache->failedOpen) {
            return;
        }

        $cache->failedOpen = true;
        $message = 'orrery: the control signals could not be read for longer than the stale window, so the worker ignores them until the next successful read.'
            .($error === null ? '' : ' Last error: '.$error->getMessage());

        Degradation::error('control:signals_stale', $message);
        Watcher::instance()->report('control:signals_stale', $message);
    }

    /**
     * The age of the cached signals before the next read
     * (`orrery.control.signal_cache_seconds`, default one second).
     */
    private static function cacheSeconds(): float
    {
        $seconds = Container::getInstance()->make('config')->get('orrery.control.signal_cache_seconds', self::CACHE_TTL_SECONDS);

        return is_numeric($seconds) ? max(0.0, (float) $seconds) : self::CACHE_TTL_SECONDS;
    }

    private static function staleSeconds(): float
    {
        $seconds = Container::getInstance()->make('config')->get('orrery.control.stale_signals_seconds', self::DEFAULT_STALE_SECONDS);

        return is_numeric($seconds) ? max(0.0, (float) $seconds) : (float) self::DEFAULT_STALE_SECONDS;
    }

    /**
     * Runs a pause or continue write in one package transaction on the
     * package storage session (PackageTransaction::run()), under the lock of
     * the settings row control_version, so concurrent commands run in a
     * serial order.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function atomically(callable $work): mixed
    {
        $session = Container::getInstance()->make(PackageSession::class)->mirror();

        // The lock row exists before the first command. Its creation runs
        // outside the lock; a concurrent creation is ignored.
        if (! $session->table('orrery_settings')->where('key', self::VERSION_KEY)->exists()) {
            $session->table('orrery_settings')->insertOrIgnore(['key' => self::VERSION_KEY, 'value' => '0']);
        }

        return Container::getInstance()->make(PackageTransaction::class)->run($session, static function (Connection $tx) use ($work): mixed {
            // One lock serialises every scope replacement, also when its
            // covered set is empty: SELECT ... FOR UPDATE on MySQL and
            // Postgres (READ COMMITTED alone lets two commands interleave);
            // SQLite's BEGIN IMMEDIATE serialises the writers already.
            // The increment gives each replacement a version.
            $version = $tx->table('orrery_settings')->where('key', self::VERSION_KEY)->lockForUpdate()->value('value');
            $tx->table('orrery_settings')->where('key', self::VERSION_KEY)->update(['value' => (string) (((int) $version) + 1)]);

            return $work();
        });
    }
}
