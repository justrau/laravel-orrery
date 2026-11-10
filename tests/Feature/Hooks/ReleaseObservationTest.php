<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Hooks\Jobs\ReleaseOnceJob;
use Justrau\Orrery\Tests\Feature\Hooks\Jobs\ReleaseThenThrowJob;

/**
 * Releases (rule L1). Rewrites of the probes
 * ReleaseAndClassificationProbeTest and ReleaseRepreparesPayloadProbeTest.
 */
final class ReleaseObservationTest extends HooksTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ReleaseOnceJob::$releaseDelay = 0;
    }

    protected function tearDown(): void
    {
        ReleaseOnceJob::$releaseDelay = 0;
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: a released job mirrored as completed (a probe result).
     *
     * The release is admitted by its own root, so L1 applies at + delay with
     * the observation's microseconds. A release
     * hook that released its admission before defer() would let the root
     * drain first, and the late observation would apply the committed
     * available_at of the jobs row, in whole seconds, through its check.
     */
    public function test_released_job_is_pending_with_the_new_id_and_available_at(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1790000000.5));
        ReleaseOnceJob::$releaseDelay = 30;

        Queue::push(new ReleaseOnceJob);
        $pushed = $this->onlyJobsRow();
        $uuid = self::uuidOf($pushed['payload']);

        $this->work();

        $released = $this->onlyJobsRow();
        self::assertNotSame($pushed['id'], $released['id'], 'The release inserted a new jobs row.');
        self::assertSame(1790000030, $released['available_at']);

        $row = $this->rowOrFail($uuid);
        self::assertSame('pending', $row->status, 'A released job is pending, never completed.');
        self::assertSame($released['id'], (int) $row->job_id, 'The row follows the new jobs.id.');
        self::assertSame($pushed['id'], (int) $row->prev_job_id);
        self::assertSame(1, (int) $row->releases);
        self::assertSame(1, (int) $row->attempts);
        self::assertSame(Clock::toDecimal(1790000030.5), Clock::toDecimal((float) $row->available_at), 'available_at is the observation time plus the delay.');
        self::assertNull($row->finished_at);
    }

    /**
     * Rejects: a release counted as a processed job.
     */
    public function test_release_is_not_throughput(): void
    {
        Queue::push(new ReleaseOnceJob);

        $this->work();

        self::assertSame([], array_filter($this->counters(), static fn (array $counter): bool => $counter['throughput'] > 0), 'The released attempt is not throughput.');

        $this->work();

        $counters = $this->counters();
        self::assertSame(1, $counters['queue:database:default']['throughput'] ?? null, 'Only the completion counts.');
        self::assertSame(1, $counters['job:'.ReleaseOnceJob::class]['throughput'] ?? null);
        self::assertSame('completed', $this->mirror()->table('orrery_jobs')->value('status'));
    }

    /**
     * Rejects: matching the exception by the current job_id only.
     */
    public function test_release_then_throw_matches_the_previous_id(): void
    {
        Queue::push(new ReleaseThenThrowJob);
        $pushed = $this->onlyJobsRow();
        $uuid = self::uuidOf($pushed['payload']);

        $this->work();

        $released = $this->onlyJobsRow();
        $row = $this->rowOrFail($uuid);
        self::assertSame('pending', $row->status);
        self::assertSame(1, (int) $row->releases, 'One release.');
        self::assertSame(1, (int) $row->exceptions, 'The exception of the replaced id is counted.');
        self::assertSame(1, (int) $row->last_exception_attempt);
        self::assertSame($pushed['id'], (int) $row->prev_job_id);
        self::assertSame($released['id'], $row->job_id === null ? null : (int) $row->job_id);
        self::assertSame(1, $this->mirror()->table('orrery_job_history')->where('job_uuid', $uuid)->where('event', 'exception')->count());

        $this->work();

        self::assertSame('completed', $this->rowOrFail($uuid)->status);
    }
}
