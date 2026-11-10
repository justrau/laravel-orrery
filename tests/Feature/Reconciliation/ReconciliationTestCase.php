<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Reconciliation;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Justrau\Orrery\Maintenance\Phases\ReconcileActivePhase;
use Justrau\Orrery\Maintenance\Phases\ReconcileFailedPhase;
use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Retry\RetryTestCase;

/**
 * The fixtures of the reconciliation tests, on top of the retry
 * and forget fixtures: pushed jobs, a fixed clock, the two tick phases and readers.
 */
abstract class ReconciliationTestCase extends RetryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::now());
    }

    protected function reconciliation(): Reconciliation
    {
        return $this->app->make(Reconciliation::class);
    }

    protected function runActive(): void
    {
        $this->runPhase(ReconcileActivePhase::class);
    }

    protected function runFailed(): void
    {
        $this->runPhase(ReconcileFailedPhase::class);
    }

    /**
     * Two checks of the failed rows: the first absence, and a second check
     * after the grace (max(retry_after = 90, 600) s on the connection `database`).
     */
    protected function runFailedPastGrace(): void
    {
        $this->runFailed();
        $this->moveClock(Reconciliation::MIN_GRACE_SECONDS + 1);
        $this->runFailed();
    }

    /**
     * Pushes a job and returns its uuid and jobs id. The mirror row is `pending`.
     *
     * @return array{0: string, 1: int}
     */
    protected function pushed(string $queueConnection = 'database', ?object $job = null): array
    {
        /** @var QueueContract $queue */
        $queue = $this->app['queue']->connection($queueConnection);
        $id = (int) $queue->push($job ?? new BasicJob);
        $payload = $this->queueTable($queueConnection)->where('id', $id)->value('payload');
        self::assertIsString($payload, 'The pushed jobs row exists.');
        $uuid = self::uuidOf($payload);
        self::assertSame(['pending', $id], [$this->rowOrFail($uuid)->status, (int) $this->rowOrFail($uuid)->job_id]);

        return [$uuid, $id];
    }

    /**
     * absent_since of a row, as a stored decimal, or null.
     */
    protected function absentSince(string $uuid): ?string
    {
        $value = $this->rowOrFail($uuid)->absent_since;

        return $value === null ? null : Clock::toDecimal((float) $value);
    }

    protected static function now(): string
    {
        return Clock::toDecimal(Clock::now());
    }

    /**
     * The errors that this process recorded for the health events.
     *
     * @return array<string, float>
     */
    protected function healthErrors(): array
    {
        $record = \Justrau\Orrery\Runtime\Degradation::record();

        return is_array($record['errors'] ?? null) ? $record['errors'] : [];
    }

    /**
     * A release row keeps the attempts of its job, so a replacement runs at
     * a higher attempt than 1.
     */
    protected function workerOptions(): WorkerOptions
    {
        $options = parent::workerOptions();
        $options->maxTries = 5;

        return $options;
    }
}
