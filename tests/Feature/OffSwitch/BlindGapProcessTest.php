<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\OffSwitch;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Events\StuckJobDetected;
use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Monitoring\BlindGaps;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Monitoring\StuckIncidents;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use PHPUnit\Framework\Attributes\Group;

/**
 * The off switch end to end with a real worker that runs with
 * ORRERY_ENABLED=false: the minutes of the off period read as unknown, and the
 * waiting job that the worker ran becomes `unknown` with the reason
 * `blind_gap`, with no stuck incident, event or notification.
 */
#[Group('process')]
#[Group('shared-database')]
final class BlindGapProcessTest extends ControllerTest
{
    private const DISABLED = ['ORRERY_ENABLED' => 'false'];

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: minutes of the off period counted as 0, and a stuck alert for the job that ran while Orrery was off.
     * Still passes: a rule that also marks the minute of the tick after the gap unknown (it is touched by the gap).
     */
    public function test_an_off_period_reads_unknown_and_its_lost_job_raises_nothing(): void
    {
        $env = $this->sharedEnvironment();
        $start = (float) floor(microtime(true));
        Carbon::setTestNow(Carbon::createFromTimestamp($start, 'UTC'));
        $events = [];
        Event::listen(StuckJobDetected::class, static function (StuckJobDetected $event) use (&$events): void {
            $events[] = $event;
        });

        // On: a waiting job, and a tick.
        Queue::push(new BasicJob);
        $uuid = (string) $this->mirror()->table('orrery_jobs')->value('uuid');
        $this->artisan('orrery:tick')->assertExitCode(0);

        // Off: a worker that does not observe runs the job.
        $worker = WorkerProcess::start(['queue:work', 'database', '--stop-when-empty', '--sleep=0', '--tries=1'], $env, self::DISABLED);
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());
        self::assertSame(0, DB::table('jobs')->count(), $worker->describe());

        // On again 20 minutes later.
        Carbon::setTestNow(Carbon::createFromTimestamp($start + 1200, 'UTC'));
        $this->artisan('orrery:tick')->assertExitCode(0);
        self::assertNotSame([], BlindGaps::read());
        $minutes = $this->getJson('/orrery/api/throughput?minutes=15')->assertOk()->json('buckets');
        self::assertNotSame([], $minutes);

        foreach ($minutes as $bucket) {
            if ($bucket['at'] < SampleCounts::minuteOf($start + 1200)) {
                self::assertNull($bucket['completed'] ?? null, 'A minute of the off period is unknown, not 0.');
            }
        }

        Carbon::setTestNow(Carbon::createFromTimestamp($start + 1200 + Reconciliation::MIN_GRACE_SECONDS + 60, 'UTC'));
        $this->artisan('orrery:tick')->assertExitCode(0);

        $row = $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->first();
        self::assertNotNull($row);
        self::assertSame(['unknown', Reconciliation::BLIND_GAP_REASON], [$row->status, $row->unknown_reason]);
        self::assertSame(0, $this->mirror()->table(StuckIncidents::TABLE)->count());
        self::assertSame([], $events);
        self::assertSame(Reconciliation::BLIND_GAP_REASON, $this->getJson('/orrery/api/jobs/'.$uuid)->assertOk()->json('unknown_reason'));
    }

    private function sharedEnvironment(): TestEnvironment
    {
        $env = $this->testEnvironment();

        if ($env->inMemory()) {
            self::markTestSkipped('A worker process needs a database that two processes share. Run the process tests through bin/test sqlite --sqlite-file, bin/test mysql or bin/test pgsql.');
        }

        return $env;
    }
}
