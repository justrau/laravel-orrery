<?php

namespace Justrau\Orrery\Tests\Feature\Classification;

use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Monitoring\MonitoringVersion;
use Justrau\Orrery\Tests\Feature\Classification\Jobs\ClassifiedJob;
use Justrau\Orrery\Tests\Feature\Classification\Jobs\DispatchThenReleaseJob;
use Justrau\Orrery\Tests\Feature\Classification\Jobs\ReleasingOnceJob;
use Justrau\Orrery\Tests\Feature\Classification\Jobs\SilencedReleasingJob;
use Justrau\Orrery\Tests\Feature\Classification\Jobs\SilencedTaggedJob;
use Justrau\Orrery\Tests\TestCase;

/**
 * The rewrites of the classification probes
 * (ReleaseAndClassificationProbeTest, ReleaseRepreparesPayloadProbeTest and
 * StaleLastPushedJobProbeTest). They assert the jobs payloads and the
 * mirror's silenced and tag values. Later tests move the status assertions to the
 * new store.
 */
class ReleaseClassificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ReleasingOnceJob::$rawBodies = [];
    }

    protected function tearDown(): void
    {
        ReleasingOnceJob::$rawBodies = [];
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function workerOptions(): WorkerOptions
    {
        $options = parent::workerOptions();
        $options->maxTries = 3;

        return $options;
    }

    /**
     * Rejects: re-classifying the payload at release.
     */
    public function test_release_keeps_the_silenced_flag(): void
    {
        config(['orrery.silenced' => [ReleasingOnceJob::class]]);

        Queue::push(new ReleasingOnceJob);
        $this->assertTrue($this->jobsPayloads()[0]['silenced'], 'silenced at push');

        // A new worker process: nothing was pushed through this queue instance.
        $this->refreshQueueConnections();

        $this->work();

        $released = $this->jobsPayloads();
        $this->assertCount(1, $released);
        $this->assertTrue($released[0]['silenced'], 'the release cleared the silenced flag');

        $this->work();

        $row = $this->mirror()->table('orrery_jobs')->sole();
        $this->assertSame('completed', $row->status);
        $this->assertTrue((bool) $row->silenced, 'the mirror row lost its silenced flag');
    }

    /**
     * Rejects: a "last pushed job" property.
     */
    public function test_release_keeps_its_own_tags(): void
    {
        $tags = $this->app->make(MonitoringVersion::class);
        $tags->monitor('parent');
        $tags->monitor('child');

        Queue::push(new DispatchThenReleaseJob);
        $parentUuid = $this->jobsPayloads()[0]['uuid'];

        $this->refreshQueueConnections();

        // The parent pushes the child, then releases itself.
        $this->work();

        $payloads = collect($this->jobsPayloads())->keyBy('displayName');
        $this->assertSame(['parent'], $payloads[DispatchThenReleaseJob::class]['tags'], 'the released parent took the tags of another job');
        $this->assertSame($parentUuid, $payloads[DispatchThenReleaseJob::class]['uuid']);
        $this->assertSame(['child'], $payloads[ClassifiedJob::class]['tags']);
        $childUuid = $payloads[ClassifiedJob::class]['uuid'];

        $this->assertSame(
            [[$childUuid, 'child'], [$parentUuid, 'parent']],
            $this->mirrorTags(),
            'the mirror tag rows mix the parent and the child',
        );
    }

    /**
     * Rejects: classification state shared between two dispatches.
     */
    public function test_after_commit_dispatches_keep_their_own_tags(): void
    {
        DB::transaction(function (): void {
            Queue::push((new ClassifiedJob(['first']))->afterCommit());
            Queue::push((new SilencedTaggedJob)->afterCommit());
            Queue::push((new ClassifiedJob(['second']))->afterCommit());

            $this->assertSame(0, DB::table('jobs')->count(), 'the jobs wait for the commit');
        });

        $payloads = $this->jobsPayloads();

        $this->assertSame([['first'], ['tenant:42'], ['second']], array_column($payloads, 'tags'));
        $this->assertSame([false, true, false], array_column($payloads, 'silenced'));

        $silenced = $this->mirror()->table('orrery_jobs')->orderBy('id')->pluck('silenced')
            ->map(static fn (mixed $value): bool => (bool) $value)
            ->all();
        $this->assertSame([false, true, false], $silenced);
    }

    /**
     * Rejects: re-preparing the payload at release.
     */
    public function test_release_keeps_the_silenced_classification(): void
    {
        Queue::push(new SilencedReleasingJob);
        $uuid = $this->jobsPayloads()[0]['uuid'];
        $this->assertTrue((bool) $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->value('silenced'));

        $this->refreshQueueConnections();

        $this->work();
        $this->assertTrue($this->jobsPayloads()[0]['silenced'], 'the released payload is not silenced');

        $this->work();

        $row = $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->sole();
        $this->assertSame('completed', $row->status);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertTrue((bool) $row->silenced, 'the release re-classified the payload and cleared silenced');
    }

    /**
     * Rejects: a new pushedAt at release.
     */
    public function test_release_keeps_the_original_pushed_at(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('U.u', '1790000000.123456'));

        Queue::push(new ReleasingOnceJob);
        $uuid = $this->jobsPayloads()[0]['uuid'];
        $this->assertSame('1790000000.123456', $this->jobsPayloads()[0]['pushedAt']);

        Carbon::setTestNow(Carbon::createFromFormat('U.u', '1790000005.654321'));
        $this->refreshQueueConnections();

        $this->work();
        $this->assertSame('1790000000.123456', $this->jobsPayloads()[0]['pushedAt'], 'the release wrote a new pushedAt');

        $this->work();

        $row = $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->sole();
        $this->assertSame('completed', $row->status);
        // The legacy repository binds pushedAt as a float (PHP precision 14),
        // so the mirror keeps 1790000000.1235 until the lifecycle store binds the
        // payload's decimal string. The release time is 5 s later, so the
        // delta still rejects a new pushedAt.
        $this->assertEqualsWithDelta(1790000000.123456, (float) $row->pushed_at, 0.001, 'the release overwrote pushed_at');
    }

    /**
     * Rejects: classification taken from another job object.
     */
    public function test_released_job_keeps_its_own_classification(): void
    {
        $this->app->make(MonitoringVersion::class)->monitor('tenant:42');

        // In one process: the releasing job B first, then the silenced and
        // tagged job A. A is the last job object that this queue saw.
        Queue::push(new ReleasingOnceJob);
        $releasing = $this->jobsPayloads()[0]['uuid'];
        Queue::push(new SilencedTaggedJob);

        $this->work();

        $requeued = collect($this->jobsPayloads())->firstWhere('uuid', $releasing);
        $this->assertNotNull($requeued, 'the releasing job is back in the jobs table');
        $this->assertSame([], $requeued['tags'], 'the released job took the tags of the last pushed job');
        $this->assertFalse($requeued['silenced'], 'the released job took the silenced flag of the last pushed job');

        $this->assertFalse((bool) $this->mirror()->table('orrery_jobs')->where('uuid', $releasing)->value('silenced'));
        $this->assertSame(0, $this->mirror()->table('orrery_job_tags')->where('job_uuid', $releasing)->count());
    }

    /**
     * The payloads of Laravel's jobs table, in id order.
     *
     * @return list<array<string, mixed>>
     */
    private function jobsPayloads(): array
    {
        return DB::table('jobs')->orderBy('id')->pluck('payload')
            ->map(static fn (string $payload): array => json_decode($payload, true))
            ->values()
            ->all();
    }

    /**
     * @return list<array{0: string, 1: string}> (job uuid, tag), sorted
     */
    private function mirrorTags(): array
    {
        $rows = $this->mirror()->table('orrery_job_tags')->get(['job_uuid', 'tag'])
            ->map(static fn (object $row): array => [(string) $row->job_uuid, (string) $row->tag])
            ->all();

        usort($rows, static fn (array $a, array $b): int => [$a[1], $a[0]] <=> [$b[1], $b[0]]);

        return $rows;
    }

    /**
     * A new worker process: the queue manager builds new queue instances.
     */
    private function refreshQueueConnections(): void
    {
        $manager = $this->app['queue'];
        (new \ReflectionProperty($manager, 'connections'))->setValue($manager, []);
    }
}
