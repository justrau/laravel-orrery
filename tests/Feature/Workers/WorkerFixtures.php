<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Workers;

use Illuminate\Support\Str;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Runtime\WorkerEvidence;
use Justrau\Orrery\Storage\Clock;

/**
 * Rows and process contexts for the worker and control tests.
 * Every time goes through Clock::toDecimal().
 */
trait WorkerFixtures
{
    /**
     * A heartbeat row. $overrides may hold floats for the time columns.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function heartbeatRow(string $host, int $pid, array $overrides = []): array
    {
        $now = Clock::now();
        $started = (float) ($overrides['started_at'] ?? $now - 120);
        $parentStarted = $overrides['parent_started_at'] ?? null;

        $row = array_merge([
            'worker_id' => $host.':'.$pid.':'.Clock::toDecimal($started),
            'host' => $host,
            'pid' => $pid,
            'ppid' => null,
            'mode' => 'daemon',
            'connection' => 'database',
            'subscribed_queues' => 'default',
            'current_queue' => null,
            'options' => '{}',
        ], $overrides);

        $row['started_at'] = Clock::toDecimal($started);
        $row['parent_started_at'] = $parentStarted === null ? null : Clock::toDecimal((float) $parentStarted);
        $row['last_seen_at'] = Clock::toDecimal((float) ($overrides['last_seen_at'] ?? $now - 5));

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function insertHeartbeats(array $rows): void
    {
        foreach ($rows as $row) {
            $this->mirror()->table('orrery_worker_heartbeats')->insert($row);
        }
    }

    /**
     * A reserved mirror row. $reservedBy null is a reservation without a verified worker.
     */
    protected function reservedJob(?string $reservedBy, float $reservedAt, string $connection = 'database', string $queue = 'default'): string
    {
        $uuid = (string) Str::uuid();
        $at = Clock::toDecimal($reservedAt);

        $this->mirror()->table('orrery_jobs')->insert([
            'uuid' => $uuid,
            'connection' => $connection,
            'queue' => $queue,
            'status' => 'reserved',
            'payload' => '{}',
            'payload_bytes' => 2,
            'pushed_at' => $at,
            'reserved_at' => $at,
            'reserved_by' => $reservedBy,
            'attempts' => 1,
            'updated_at' => $at,
        ]);

        return $uuid;
    }

    /**
     * Makes this PHPUnit process a worker process of the given shape.
     */
    protected function actAsWorker(?int $ppid = null, ?float $parentStartedAt = null, ?string $parentCommand = null, ?WorkerEvidence $evidence = null, ?float $startedAt = null, ?int $pid = null, ?float $controlStartedAt = null): ProcessContext
    {
        $context = ProcessContext::forTesting(
            kind: ProcessContext::WORKER,
            host: (string) (gethostname() ?: 'unknown'),
            pid: $pid ?? (int) getmypid(),
            startedAt: $startedAt ?? Clock::now() - 60,
            evidence: $evidence,
            ppid: $ppid,
            parentStartedAt: $parentStartedAt,
            parentCommand: $parentCommand,
            controlStartedAt: $controlStartedAt,
        );

        ProcessContext::useForTesting($context);

        return $context;
    }

    protected function actAs(string $kind): void
    {
        ProcessContext::useForTesting(ProcessContext::current()->withKindForTesting($kind));
    }
}
