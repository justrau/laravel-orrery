<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Justrau\Orrery\Monitoring\StuckIncidents;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Retry\RetryTestCase;
use Justrau\Orrery\Tests\Support\TestEnvironment;

/**
 * Every path that deletes a job's mirror row deletes its incidents with it
 * (IncidentCleanup::deleteForJobs()): a forget, `orrery:forget
 * --all` and `orrery:clear`. The trim is in RetentionTest.
 */
final class IncidentDeletionTest extends RetryTestCase
{
    /**
     * Rejects: a forget that leaves the job's incidents, or deletes those of
     * another job.
     */
    public function test_a_forget_deletes_the_jobs_incidents(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        [$other] = $this->failedJob();
        $this->incident($uuid, 'outcome_not_observed');
        $this->incident($uuid, 'unknown');
        $this->incident($other, 'unknown');

        self::assertSame('deleted', $this->forgetService()->forget($uuid, $occurrence, 'cli', 'cli')->result);

        self::assertSame(0, $this->incidents($uuid));
        self::assertSame(1, $this->incidents($other));
    }

    /**
     * Rejects: an `orrery:forget --all` that leaves the incidents of the
     * rows it deletes.
     */
    public function test_forget_all_deletes_the_incidents(): void
    {
        [$first] = $this->failedJob();
        [$second] = $this->failedJob();
        $this->incident($first, 'unknown');
        $this->incident($second, 'repeated_releases');

        $this->artisan('orrery:forget', ['--all' => true, '--force' => true])->assertExitCode(0);

        self::assertSame([0, 0], [$this->incidents($first), $this->incidents($second)]);
    }

    /**
     * Rejects: an `orrery:clear` that deletes the waiting job's mirror row
     * and leaves its incidents.
     */
    public function test_clear_deletes_the_incidents_of_the_waiting_jobs(): void
    {
        /** @var QueueContract $queue */
        $queue = $this->app['queue']->connection('database');
        $id = $queue->push($this->job());
        $payload = $this->app['db']->connection(TestEnvironment::PRIMARY)->table('jobs')->where('id', $id)->value('payload');
        self::assertIsString($payload);
        $uuid = self::uuidOf($payload);
        $this->incident($uuid, 'outcome_not_observed');

        $this->artisan('orrery:clear', ['connection' => 'database', '--force' => true])->assertExitCode(0);

        self::assertNull($this->row($uuid));
        self::assertSame(0, $this->incidents($uuid));
    }

    private function incident(string $uuid, string $signal): void
    {
        $this->mirror()->table(StuckIncidents::TABLE)->insert([
            'job_uuid' => $uuid,
            'cycle' => (int) $this->rowOrFail($uuid)->cycle,
            'signal' => $signal,
            'first_seen_at' => Clock::toDecimal(Clock::now()),
            'evidence' => '{}',
        ]);
    }

    private function incidents(string $uuid): int
    {
        return $this->mirror()->table(StuckIncidents::TABLE)->where('job_uuid', $uuid)->count();
    }
}
