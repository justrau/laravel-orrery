<?php

namespace Justrau\Orrery\Tests\Feature;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\Fixtures\Metrics\CountsSamples;
use Justrau\Orrery\Tests\TestCase;

class QueueLifecycleTest extends TestCase
{
    use CountsSamples;

    /**
     * Rejects: counts under the old queue-name key, or no pending row before the worker runs.
     */
    public function test_push_then_work_gives_pending_then_completed(): void
    {
        $processed = null;
        Event::listen(JobProcessed::class, function ($event) use (&$processed) {
            $processed = $event;
        });

        Queue::push(new BasicJob);

        $this->assertSame(1, DB::table('jobs')->count());
        $jobId = (int) DB::table('jobs')->value('id');
        $pending = $this->mirror()->table('orrery_jobs')->get();
        $this->assertCount(1, $pending, 'The push has its mirror row before a worker runs.');
        $this->assertSame('pending', $pending[0]->status);
        $this->assertSame($jobId, (int) $pending[0]->job_id);
        $this->assertSame('database', $pending[0]->connection);
        $this->assertSame('default', $pending[0]->queue);

        $this->work();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, $this->mirror()->table('orrery_jobs')->where('status', 'completed')->count());
        $this->assertNotNull($processed);

        $counted = array_keys(array_filter($this->countedSamples(), static fn (array $counter): bool => $counter['throughput'] === 1));
        sort($counted, SORT_STRING);
        $this->assertSame(['job:'.BasicJob::class, 'queue:database:default'], $counted, 'The tick counts the completion under connection:queue and the job name.');
    }
}
