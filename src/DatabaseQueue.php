<?php

namespace Justrau\Orrery;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Queue\DatabaseQueue as BaseQueue;
use Illuminate\Queue\Jobs\DatabaseJob;
use Justrau\Orrery\Control\StopLatch;
use Justrau\Orrery\Control\WorkerControl;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Lifecycle\Observation;
use Justrau\Orrery\Listeners\RecordWorkerHeartbeat;
use Justrau\Orrery\Observation\Capture;
use Justrau\Orrery\Observation\CommitDeferral;
use Justrau\Orrery\Observation\Generation;
use Justrau\Orrery\Observation\PayloadIdentity;
use Justrau\Orrery\Queue\BulkCapture;
use Justrau\Orrery\Queue\BulkResolver;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Workers\Heartbeats;
use stdClass;
use Throwable;
use UnitEnum;

/**
 * The swapped database queue.
 *
 * Every hook calls its parent exactly once, outside any watcher, and builds
 * its observation from the parent call's return value. The commit deferral
 * (CommitDeferral::around()) captures the queue connection instance before
 * the call and decides after it: the observation runs at once in
 * autocommit, or at the commit of the root transaction of the instance, and
 * it is dropped (and counted) on a rollback or when the outcome cannot be
 * proved. The parent's return value and exception reach the caller
 * unchanged.
 * - push and later: pushToDatabase() observes the insert with the returned
 *   id, unless the insert belongs to pushRaw() or to a release of this
 *   instance's deleteAndRelease() (the one-shot owned-insert flag);
 * - pushRaw(): stamps a new generation (never skipped by the breaker) and
 *   observes a republish with the returned id;
 * - pop(): the heartbeat and the control check, then the reservation with
 *   this process's worker identity and evidence. pop() runs one own
 *   transaction, whose commit the deferral witnesses;
 * - deleteAndRelease(): release() returns the new id j', and the hook
 *   observes the release with it. deleteAndRelease() runs one own
 *   transaction;
 * - bulk(): parent::bulk() unchanged with the call's capture (BulkCapture),
 *   then BulkResolver::observe() of the records built during
 *   the call, and of Laravel 13's deferred insert at its own time.
 * A payload without a canonical-UUID uuid or id is not mirrored: its
 * hook calls the parent without a capture.
 */
class DatabaseQueue extends BaseQueue
{
    /** The hold point after parent::pop() returned a job, before the reserve observation. */
    public const AFTER_POP = 'observer.after_pop';

    /** The hold point in a worker's pop(), before the control check. */
    public const BEFORE_CONTROL = 'control.before_check';

    /**
     * The next pushToDatabase() of this instance is the insert of pushRaw()
     * or of the release of deleteAndRelease(): its caller observes it.
     * pushToDatabase() consumes it on entry, so a push that a listener
     * dispatches during that insert is observed as its own push.
     */
    private bool $nextInsertOwned = false;

    /**
     * The job record of the deleteAndRelease() that runs on this instance:
     * only a release() of exactly this record (===) owns its insert.
     */
    private ?object $releasingRecord = null;

    /** The new id j' that release() returned inside deleteAndRelease(). */
    private mixed $releasedId = null;

    /**
     * A native republish (for example queue:retry) gets a new generation:
     * the stamp replaces the payload's top-level `orreryGen`
     * and removes a stale top-level `orreryRetry`, because a native
     * republish of an old package retry must not look like that retry.
     * Nothing else of the payload changes, and it is never classified again.
     * The stamp never depends on the watcher: it runs also while the
     * breaker is open, and on any failure the payload is
     * published unchanged.
     *
     * The hook observes a republish of the exact stamped payload with the
     * returned id. A republish acts as push when no row exists, and it never
     * changes an existing row.
     *
     * @param  string  $payload
     * @param  string|null  $queue
     * @param  array<mixed>  $options
     */
    #[\Override]
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $stamped = $this->stamp($payload);
        $deferral = PayloadIdentity::uuidOf($stamped) === null ? null : $this->deferral();
        $this->nextInsertOwned = true;

        try {
            if ($deferral === null) {
                return parent::pushRaw($stamped, $queue, $options);
            }

            return $deferral->around(
                $this->database,
                0,
                fn (): mixed => parent::pushRaw($stamped, $queue, $options),
                fn (mixed $id): ?Closure => $this->observing(Observation::REPUBLISH, $stamped, $this->getQueue($queue), ['jobId' => $id]),
                'hook:push_raw',
            );
        } finally {
            $this->nextInsertOwned = false;
        }
    }

    /**
     * The generation stamp of pushRaw(): the payload with a new top-level
     * `orreryGen` and without a top-level `orreryRetry`, or the payload
     * unchanged when it is not a JSON object, holds an integer beyond int64,
     * or any step fails. The payload
     * is decoded to objects, so an empty JSON object stays an object, and it
     * is encoded with JSON_UNESCAPED_UNICODE, as Laravel encodes payloads,
     * and with JSON_PRESERVE_ZERO_FRACTION, so a float stays a float.
     */
    private function stamp(mixed $payload): mixed
    {
        try {
            if (! is_string($payload)) {
                return $payload;
            }

            $decoded = json_decode($payload);

            if (! $decoded instanceof stdClass || self::losesIntegers($payload, $decoded)) {
                return $payload;
            }

            $decoded->orreryGen = Generation::new();
            unset($decoded->orreryRetry);

            $encoded = static::encodeStamped($decoded);

            return is_string($encoded) ? $encoded : $payload;
        } catch (Throwable $error) {
            try {
                Watcher::instance()->report('payload.stamp', $error);
            } catch (Throwable) {
                // Nothing is left to report to.
            }

            return $payload;
        }
    }

    /**
     * Whether the decode turned an integer beyond PHP's int64 into a float:
     * the stamp would then publish another number. Only a
     * payload with a run of 19 or more digits is decoded a second time, with
     * the big integers as strings, and compared.
     */
    private static function losesIntegers(string $payload, stdClass $decoded): bool
    {
        if (preg_match('/\d{19}/', $payload) !== 1) {
            return false;
        }

        $exact = json_decode($payload, false, 512, JSON_BIGINT_AS_STRING);

        return json_encode($exact, JSON_PRESERVE_ZERO_FRACTION) !== json_encode($decoded, JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * The encode of the stamp (a test seam: a test subclass makes it fail).
     */
    protected static function encodeStamped(stdClass $payload): string|false
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Inserts the payload unchanged: a release keeps its payload byte for
     * byte, and push, later and pushRaw() are classified or stamped before
     * this call.
     *
     * The insert of pushRaw() and of a release of deleteAndRelease() belongs
     * to its caller (the owned-insert flag). Every other insert is a push or
     * a later: the hook observes it with the returned id and the delay.
     *
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     */
    #[\Override]
    protected function pushToDatabase($queue, $payload, $delay = 0, $attempts = 0): mixed
    {
        $owned = $this->nextInsertOwned;
        $this->nextInsertOwned = false;

        // A job that a bulk's classification dispatches is never captured
        // into that bulk's capture.
        $suspended = BulkCapture::suspend();

        try {
            $deferral = $owned || PayloadIdentity::uuidOf($payload) === null ? null : $this->deferral();

            if ($deferral === null) {
                return parent::pushToDatabase($queue, $payload, $delay, $attempts);
            }

            return $deferral->around(
                $this->database,
                0,
                fn (): mixed => parent::pushToDatabase($queue, $payload, $delay, $attempts),
                fn (mixed $id): ?Closure => $this->observing(Observation::PUSH, (string) $payload, $this->getQueue($queue), [
                    'jobId' => $id,
                    'delay' => $this->secondsUntil($delay),
                ]),
                'hook:push',
            );
        } finally {
            BulkCapture::restore($suspended);
        }
    }

    /**
     * A bulk push. One capture path serves every
     * Laravel release: the floor is read on the observer session, the
     * call's capture opens, and parent::bulk() runs unchanged inside the
     * deferral's capture. After the call, BulkResolver::observe() builds the
     * observation of the records built during the call (Laravel 12's single
     * insert; Laravel 13's immediate insert, and its after-commit insert
     * when no transaction was pending). The payloads that the call
     * classified but did not build (Laravel 13's after-commit jobs) wait in
     * the pending capture, whose flush observes Laravel's deferred insert
     * right after it (BulkCapture). Nothing is observed when the parent
     * throws, and the capture closes in `finally`. When the parent throws
     * after it built records, the bulk is counted once `unverified`, also
     * when the insert itself failed: the package cannot tell which records
     * reached the table. The parent's value and exception reach the caller
     * unchanged.
     *
     * @param  mixed  $jobs
     * @param  mixed  $data
     * @param  mixed  $queue  a UnitEnum, a string or null: Laravel 12 declares
     *                         string|null for bulk() and getQueue(), but its
     *                         callers also pass a UnitEnum. The value reaches
     *                         the parent unchanged.
     * @return mixed
     */
    #[\Override]
    public function bulk($jobs, $data = '', $queue = null)
    {
        // Every bulk call has its own capture, also an unobserved one: its
        // records never enter an outer bulk's capture.
        $capture = BulkCapture::open();

        try {
            $connection = (string) $this->getConnectionName();
            $floor = $this->bulkFloor($connection);

            if ($floor === null) {
                return parent::bulk($jobs, $data, $queue);
            }

            $deferral = $this->deferral();

            if ($deferral === null) {
                return parent::bulk($jobs, $data, $queue);
            }

            try {
                return $this->observedBulk($capture, $deferral, $connection, $floor, $jobs, $data, $queue);
            } catch (Throwable $error) {
                // The parent threw after it built records: they may have been
                // inserted (for example Laravel 13's immediate insert before
                // an after-commit payload failed), and are not observed.
                if ($capture->records() !== []) {
                    try {
                        Degradation::unverified();
                    } catch (Throwable) {
                        // Nothing is left to count with.
                    }
                }

                throw $error;
            }
        } finally {
            $capture->close();
        }
    }

    /**
     * parent::bulk() inside the deferral's capture, and the observation of
     * the records built during the call.
     *
     * @param  mixed  $jobs
     * @param  mixed  $data
     * @param  mixed  $queue
     */
    private function observedBulk(BulkCapture $capture, CommitDeferral $deferral, string $connection, int $floor, $jobs, $data, $queue): mixed
    {
        return $deferral->around(
            $this->database,
            0,
            fn (): mixed => parent::bulk($jobs, $data, $queue),
            function () use ($capture, $deferral, $connection, $floor): ?Closure {
                $capture->close();
                // Registered before the immediate records are decided, so
                // nothing runs between Laravel's addCallback() and this one.
                $capture->pend($this->database, $deferral, fn (Capture $insert, array $records) => $this->observeDeferred($deferral, $insert, $connection, $records, $floor));

                return $this->observingBulk($connection, $capture->records(), $floor);
            },
            'hook:bulk',
        );
    }

    /**
     * Laravel's own record builder: each record goes to the capture that
     * its payload identity names (BulkCapture::route()), so each job is
     * serialized once.
     *
     * @param  string|null  $queue
     * @param  string  $payload
     * @param  int  $availableAt
     * @param  int  $attempts
     * @return array<string, mixed>
     */
    #[\Override]
    protected function buildDatabaseRecord($queue, $payload, $availableAt, $attempts = 0)
    {
        $record = parent::buildDatabaseRecord($queue, $payload, $availableAt, $attempts);

        try {
            BulkCapture::route($record);
        } catch (Throwable $error) {
            try {
                Watcher::instance()->report('hook:bulk.route', $error);
            } catch (Throwable) {
                // Nothing is left to report to.
            }
        }

        return $record;
    }

    /**
     * The deferred insert of a pending capture: its observation, built now,
     * goes to the deferral with the capture that was taken just before the
     * insert's SQL.
     *
     * @param  list<array<string, mixed>>  $records
     */
    private function observeDeferred(CommitDeferral $deferral, Capture $insert, string $connection, array $records, int $floor): void
    {
        $built = Watcher::instance()->attempt('hook:bulk.build', fn (): ?Closure => $this->observingBulk($connection, $records, $floor));
        $run = $built->succeeded() ? $built->value() : null;

        if ($run instanceof Closure) {
            $deferral->after($this->database, $insert, $run, 'hook:bulk');
        }
    }

    /**
     * The floor of a bulk on the observer session, or null when it cannot be
     * read: then the bulk is not observed, and its jobs get their rows at
     * reservation (reserveWithoutRow).
     */
    private function bulkFloor(string $connection): ?int
    {
        $floor = Watcher::instance()->run('hook:bulk_floor', static fn (): int => Container::getInstance()->make(BulkResolver::class)->floor($connection, ''));

        return is_int($floor) ? $floor : null;
    }

    /**
     * The deferred work of one bulk insert: its bulkPush rows and their
     * resolution (BulkResolver::observe()), or null when no record has a
     * payload to mirror.
     *
     * @param  array<int, array<string, mixed>>  $records
     */
    private function observingBulk(string $connection, array $records, int $floor): ?Closure
    {
        $payloads = [];
        $delays = [];
        $queue = null;

        foreach ($records as $record) {
            $payload = $record['payload'] ?? null;

            if (! is_string($payload) || PayloadIdentity::uuidOf($payload) === null) {
                continue;
            }

            $queue ??= Observation::queueName($record['queue'] ?? null);
            $delays[count($payloads)] = max(0, (int) ($record['available_at'] ?? 0) - (int) ($record['created_at'] ?? 0));
            $payloads[] = $payload;
        }

        if ($payloads === []) {
            return null;
        }

        return Container::getInstance()->make(BulkResolver::class)->observe($connection, (string) $queue, $payloads, $floor, $delays);
    }

    /**
     * Declares the parent's parameters and forwards every received argument
     * through popFromParent(). The heartbeat and, in
     * a worker process, the control check run first; neither can
     * throw into the worker. Then the hook observes the verified
     * reservation of the returned job (reserve()).
     *
     * @param  mixed  $queue  a UnitEnum, a string or null: Laravel 12 declares
     *                         string|null for bulk() and getQueue(), but its
     *                         callers also pass a UnitEnum. The value reaches
     *                         the parent unchanged.
     */
    #[\Override]
    public function pop($queue = null)
    {
        $arguments = func_get_args();
        $connection = (string) $this->getConnectionName();
        $watcher = Watcher::instance();
        // Local work only, so it runs also while the breaker is open: the
        // heartbeat and the terminate check name this queue.
        try {
            $resolved = Observation::queueName($this->getQueue($queue));
        } catch (Throwable $error) {
            $watcher->report('hook:queue_name', $error);
            $resolved = '';
        }

        // The heartbeat of every poll, also an idle one: the
        // queue:listen children run queue:work --once and raise no Looping.
        // It writes only in a worker process, at most once per interval. It
        // watches its own write, and its flush runs also while the breaker
        // is open.
        try {
            RecordWorkerHeartbeat::write($connection);
        } catch (Throwable $error) {
            $watcher->report('hook:heartbeat', $error);
        }

        // The control plane, in worker processes only
        // (WorkerControl): the stop latch, a terminate and a global or
        // connection pause skip this queue. A queue pause is Laravel's own:
        // its worker never calls pop() for that queue, so no cache key is
        // read here. It is not one watched run,
        // because an open breaker would skip it: the signal reads are watched
        // inside SignalState, which keeps the last read signals for the stale
        // window.
        Hooks::reach(self::BEFORE_CONTROL);

        try {
            $skip = WorkerControl::popShouldSkip($connection, $resolved);
        } catch (Throwable $error) {
            $watcher->report('hook:control', $error);
            $skip = StopLatch::isSet();
        }

        if ($skip) {
            return null;
        }

        $job = $this->reserve($arguments, $connection);

        if ($job instanceof DatabaseJob) {
            // The queue of the last reservation (current_queue), written at
            // once when it changed.
            try {
                Heartbeats::reserved($connection, $resolved);
            } catch (Throwable $error) {
                $watcher->report('hook:heartbeat', $error);
            }
        }

        return $job;
    }

    /**
     * The reservation of pop(): parent::pop() through popFromParent() with
     * every argument, inside the deferral's capture. pop() runs one own
     * transaction (its locking read is the first statement).
     *
     * @param  array<int, mixed>  $arguments
     */
    private function reserve(array $arguments, string $connection): mixed
    {
        $deferral = $this->deferral();

        if ($deferral === null) {
            return $this->popFromParent($arguments);
        }

        return $deferral->around(
            $this->database,
            1,
            fn (): mixed => $this->popFromParent($arguments),
            function (mixed $job) use ($connection): ?Closure {
                if (! $job instanceof DatabaseJob) {
                    // No job, so nothing to observe.
                    return null;
                }

                Hooks::reach(self::AFTER_POP);

                $context = ProcessContext::current();

                return $this->observing(Observation::RESERVE, $job->getRawBody(), $job->getQueue(), [
                    'jobId' => $job->getJobId(),
                    'attempt' => $job->attempts(),
                    'workerId' => $context->identity(),
                    'worker' => $context->workerEvidence(),
                    'inWorker' => true,
                ], $connection);
            },
            'hook:pop',
        );
    }

    /**
     * parent::pop() with every argument that pop() received: a
     * seam for the forwarding test.
     *
     * @param  array<int, mixed>  $arguments
     */
    protected function popFromParent(array $arguments): mixed
    {
        return parent::pop(...$arguments);
    }

    /**
     * A release inside Laravel's deleteAndRelease transaction:
     * release() returns the new id j', and the hook observes the release with
     * the old id, the attempt, j' and the delay. deleteAndRelease() runs one
     * own transaction (its locking read is the first statement).
     *
     * @param  string  $queue
     * @param  \Illuminate\Queue\Jobs\DatabaseJob  $job
     * @param  int  $delay
     *
     * @return void
     */
    #[\Override]
    public function deleteAndRelease($queue, $job, $delay)
    {
        $payload = $job->getRawBody();
        $deferral = PayloadIdentity::uuidOf($payload) === null ? null : $this->deferral();

        $releasing = $this->releasingRecord;
        $releasedId = $this->releasedId;

        if ($deferral === null) {
            // An unobserved release claims nothing of an outer hook's state.
            $this->releasingRecord = null;
            $this->releasedId = null;

            try {
                parent::deleteAndRelease($queue, $job, $delay);
            } finally {
                $this->releasingRecord = $releasing;
                $this->releasedId = $releasedId;
            }

            return;
        }

        $this->releasingRecord = $job->getJobRecord();
        $this->releasedId = null;
        $connection = (string) $this->getConnectionName();

        try {
            $deferral->around(
                $this->database,
                1,
                function () use ($queue, $job, $delay): mixed {
                    parent::deleteAndRelease($queue, $job, $delay);

                    return $this->releasedId;
                },
                function (mixed $newJobId) use ($job, $delay, $payload, $queue, $connection): ?Closure {
                    $newJobId = self::jobId($newJobId);

                    if ($newJobId === null) {
                        // release() did not run: no release insert to observe.
                        return null;
                    }

                    return $this->observing(Observation::RELEASE, $payload, $this->getQueue($queue), [
                        'jobId' => $job->getJobId(),
                        'attempt' => $job->attempts(),
                        'newJobId' => $newJobId,
                        'delay' => $this->secondsUntil($delay),
                    ], $connection);
                },
                'hook:release',
            );
        } finally {
            $this->releasingRecord = $releasing;
            $this->releasedId = $releasedId;
        }
    }

    /**
     * The release insert. Inside this instance's deleteAndRelease(), the
     * release of exactly the hook's job record belongs to that hook, and the
     * new id j' goes back to it. A direct call, or a release of another
     * record (a listener during the hook), is an ordinary insert, observed
     * by pushToDatabase().
     *
     * @param  string  $queue
     * @param  \Illuminate\Queue\Jobs\DatabaseJobRecord  $job
     * @param  int  $delay
     * @return mixed
     */
    #[\Override]
    public function release($queue, $job, $delay)
    {
        if ($this->releasingRecord === null || $job !== $this->releasingRecord) {
            return parent::release($queue, $job, $delay);
        }

        // Only the first release of the hook's record is its own.
        $this->releasingRecord = null;
        $this->nextInsertOwned = true;

        try {
            $id = parent::release($queue, $job, $delay);
        } finally {
            $this->nextInsertOwned = false;
        }

        $this->releasedId = $id;

        return $id;
    }

    /**
     * The deferred work of one observation: the JobStore write of the
     * observation built now (one payload decode, `at` = now), or null when
     * the payload has no usable uuid.
     *
     * @param  array<string, mixed>  $fields
     */
    private function observing(string $kind, string $payload, UnitEnum|string|null $queue, array $fields, ?string $connection = null): ?Closure
    {
        $observation = Observation::fromPayload($kind, $payload, $connection ?? (string) $this->getConnectionName(), $queue, $fields + [
            'at' => Clock::now(),
            'instrumented' => true,
        ]);

        if ($observation === null) {
            return null;
        }

        $store = Container::getInstance()->make(JobStore::class);

        return static function () use ($store, $observation): void {
            $store->observe($observation);
        };
    }

    private static function jobId(mixed $id): ?int
    {
        if (is_int($id)) {
            return $id;
        }

        return is_string($id) && ctype_digit($id) ? (int) $id : null;
    }

    /**
     * The commit deferral, or null when the package cannot resolve it: then
     * the hook calls its parent without a capture.
     */
    private function deferral(): ?CommitDeferral
    {
        try {
            return Container::getInstance()->make(CommitDeferral::class);
        } catch (Throwable $error) {
            try {
                Watcher::instance()->report('hook:deferral', $error);
            } catch (Throwable) {
                // Nothing is left to report to.
            }

            return null;
        }
    }
}
