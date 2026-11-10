<?php

namespace Justrau\Orrery\Workers;

use Illuminate\Container\Container;
use Illuminate\Queue\WorkerOptions;
use Justrau\Orrery\Database\Models\WorkerHeartbeat;
use Justrau\Orrery\Observation\DatabaseDriverFilter;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Throwable;

/**
 * The heartbeat row of this worker process.
 *
 * - Only a worker process (ProcessContext::isWorker(), queue:work in argv)
 *   on a database-driver connection writes a row.
 * - `worker_id` is the process identity host:pid:start, so a later process
 *   with a reused PID gets its own row.
 * - `mode`, `ppid` and `parent_started_at` come from ProcessContext, so a
 *   queue:listen child can be grouped by host, ppid and parent start.
 * - `subscribed_queues` (a comma-separated list, as Laravel's --queue) comes
 *   from WorkerStarting, else the Looping queue string, else the --queue
 *   option of the command line, else the connection's default queue.
 * - `current_queue` is the queue of the last reservation of this process.
 * - `options` is JSON: the WorkerOptions of WorkerStarting or Looping when a
 *   daemon raised them, else the options of the command line.
 *
 * The write runs inside the watcher. The throttle and the degradation flush
 * are RecordWorkerHeartbeat's.
 */
final class Heartbeats
{
    // Process state: a container singleton.

    /** @var list<string>|null the queues from WorkerStarting or Looping */
    private ?array $subscribed = null;

    private ?string $currentQueue = null;

    /** The current_queue of the last row that this process wrote. */
    private ?string $writtenQueue = null;

    /** @var array<string, mixed>|null the WorkerOptions of WorkerStarting or Looping */
    private ?array $options = null;

    /**
     * Whether this process writes heartbeats for the queue connection: a
     * worker process on a database-driver connection.
     */
    public static function applies(string $connection): bool
    {
        return ProcessContext::current()->isWorker() && DatabaseDriverFilter::isDatabase($connection);
    }

    /**
     * Writes this process's row. $queues is a queue string of the worker loop
     * (WorkerStarting or Looping), or null. Returns false when no row is
     * written.
     */
    public static function write(string $connection, ?string $queues = null): bool
    {
        if (! self::applies($connection)) {
            return false;
        }

        $state = self::bound();

        if ($queues !== null && $queues !== '') {
            $state->subscribed = self::split($queues);
        }

        $context = ProcessContext::current();
        $now = Clock::now();
        $parentStartedAt = $context->parentStartedAt();

        $row = [
            'worker_id' => $context->identity(),
            'host' => $context->host(),
            'pid' => $context->pid(),
            'started_at' => Clock::toDecimal($context->startedAt()),
            'ppid' => $context->ppid(),
            'parent_started_at' => $parentStartedAt === null ? null : Clock::toDecimal($parentStartedAt),
            'mode' => $context->mode(),
            'connection' => $connection,
            'subscribed_queues' => implode(',', self::subscribed($connection)),
            'current_queue' => $state->currentQueue,
            'options' => self::encodedOptions(),
            'last_seen_at' => Clock::toDecimal($now),
        ];

        $written = Watcher::instance()->run('heartbeat', static function () use ($row): bool {
            WorkerHeartbeat::query()->upsert([$row], ['worker_id'], array_values(array_diff(array_keys($row), ['worker_id'])));

            return true;
        }, false);

        if ($written === true) {
            $state->writtenQueue = $row['current_queue'];
        }

        return $written === true;
    }

    /**
     * WorkerStarting and Looping (Laravel 13 adds the options to Looping):
     * the queue string and the options that the daemon uses.
     */
    public static function loop(?string $queues, mixed $options): void
    {
        $state = self::bound();

        if ($queues !== null && $queues !== '') {
            $state->subscribed = self::split($queues);
        }

        if ($options instanceof WorkerOptions) {
            $state->options = get_object_vars($options);
        }
    }

    /**
     * The queue of a reservation of this process (the last reserve). When it
     * differs from the current_queue of the last written row, the row is
     * written at once, outside the heartbeat throttle: a --once worker or a
     * queue:listen child exits before its next throttled heartbeat. A daemon
     * that keeps working one queue writes no extra row after its first
     * reservation.
     */
    public static function reserved(string $connection, string $queue): void
    {
        $state = self::bound();

        if ($queue === '') {
            return;
        }

        $state->currentQueue = $queue;

        if ($state->writtenQueue !== $queue) {
            // Bookkeeping between two heartbeats: no flush point of the
            // degradation record, and no recovery of an open window (the
            // throttled heartbeat flushes first and then writes).
            Watcher::withoutRecovery(static fn (): bool => self::write($connection));
        }
    }

    /**
     * The queues that this process works on the connection.
     *
     * @return list<string>
     */
    public static function subscribed(string $connection): array
    {
        $state = self::bound();

        if ($state->subscribed !== null && $state->subscribed !== []) {
            return $state->subscribed;
        }

        $argv = ProcessContext::current()->workerEvidence()?->queue;

        if (is_string($argv) && $argv !== '') {
            return self::split($argv);
        }

        try {
            $default = Container::getInstance()->make('config')->get("queue.connections.{$connection}.queue");
        } catch (Throwable) {
            $default = null;
        }

        return [is_string($default) && $default !== '' ? $default : 'default'];
    }

    /**
     * @return list<string>
     */
    private static function split(string $queues): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $queues)), static fn (string $queue): bool => $queue !== ''));
    }

    private static function encodedOptions(): string
    {
        $options = self::bound()->options ?? ProcessContext::current()->workerEvidence()?->toArray() ?? [];
        $json = json_encode((object) $options, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return is_string($json) ? $json : '{}';
    }

    /**
     * The application's instance (a container binding).
     */
    private static function bound(): self
    {
        return Container::getInstance()->make(self::class);
    }
}
