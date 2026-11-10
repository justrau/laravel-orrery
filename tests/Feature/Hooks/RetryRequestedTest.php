<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks;

use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Jobs\FailingJob;

/**
 * JobRetryRequested sets retry_requested_at only.
 */
final class RetryRequestedTest extends HooksTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: a state transition from JobRetryRequested.
     */
    public function test_job_retry_requested_sets_only_the_intent(): void
    {
        Queue::push(new FailingJob);
        $uuid = self::uuidOf($this->onlyJobsRow()['payload']);
        $this->workAndLogFailures();

        $before = (array) $this->rowOrFail($uuid);
        self::assertSame('failed', $before['status']);

        $record = DB::table('failed_jobs')->where('uuid', $uuid)->first();
        self::assertNotNull($record, 'The failure is logged.');

        Carbon::setTestNow(Carbon::createFromTimestamp(1790000100.25));
        $this->app['events']->dispatch(new JobRetryRequested($record));

        $after = (array) $this->rowOrFail($uuid);
        self::assertSame(Clock::toDecimal(1790000100.25), Clock::toDecimal((float) $after['retry_requested_at']));

        unset($before['retry_requested_at'], $before['updated_at'], $after['retry_requested_at'], $after['updated_at']);
        self::assertSame($before, $after, 'Nothing else of the row changes.');
    }

    /**
     * Rejects: a retry intent of a failed record of another queue driver
     * (`queue:retry` of a Redis job) that still updates the mirror, one
     * statement per job.
     */
    public function test_a_retry_request_of_another_driver_changes_nothing(): void
    {
        Queue::push(new FailingJob);
        $uuid = self::uuidOf($this->onlyJobsRow()['payload']);
        $this->workAndLogFailures();

        $record = DB::table('failed_jobs')->where('uuid', $uuid)->first();
        self::assertNotNull($record, 'The failure is logged.');
        $this->app['config']->set('queue.connections.elsewhere', ['driver' => 'sync']);
        $record->connection = 'elsewhere';

        $this->app['events']->dispatch(new JobRetryRequested($record));

        self::assertNull($this->rowOrFail($uuid)->retry_requested_at, 'A record of another driver set the retry intent.');
    }
}
