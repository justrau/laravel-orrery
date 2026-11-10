<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring;

use Justrau\Orrery\Monitoring\StuckIncidents;
use Justrau\Orrery\Monitoring\StuckNotifications;
use Justrau\Orrery\Monitoring\StuckSignals;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\Fixtures\Incidents\IncidentCommandsProvider;
use Justrau\Orrery\Tests\Support\Fixtures\Incidents\RecordStuckDispatchesProvider;
use Justrau\Orrery\Tests\Support\WaitTimeout;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

/**
 * The delivery bound of the stuck incidents across real `orrery:tick`
 * processes (D-3): two ticks at a barrier deliver
 * once; a tick killed after a dispatch, or between a claim and its
 * dispatch, never leads to more than 3 dispatches of one incident.
 *
 * Each child records its dispatches as barrier markers
 * (RecordStuckDispatchesProvider), so a killed child's dispatches count
 * too. Between two ticks the test moves `next_attempt_at` into the past,
 * as the backoff's time passing would.
 */
#[Group('process')]
#[Group('shared-database')]
final class StuckIncidentsProcessTest extends TestCase
{
    private const UUID = '00000000-0000-4000-8000-0000000d8008';

    private Barrier $dispatches;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('A worker process needs a database that it shares with the test: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        $this->dispatches = Barrier::forTest();
        $now = Clock::toDecimal(Clock::now());
        // A pending row without a job id and without a signal's facts: no tick creates another
        // incident, the reconciliation of the waiting rows leaves it alone (no job id), and it is
        // still in the state of the incident's signal, so the delivery and the notifications keep
        // the incident (a finished job ends it). Its cycle is the incident's.
        $this->mirror()->table('orrery_jobs')->insert([
            'uuid' => self::UUID, 'connection' => 'database', 'queue' => 'default', 'name' => 'App\\Jobs\\Stuck', 'type' => 'job',
            'status' => 'pending', 'payload' => '{}', 'payload_bytes' => 2, 'pushed_at' => $now, 'updated_at' => $now,
        ]);
        $this->mirror()->table(StuckIncidents::TABLE)->insert([
            'job_uuid' => self::UUID, 'cycle' => 1, 'signal' => StuckSignals::OUTCOME_NOT_OBSERVED,
            'first_seen_at' => $now, 'evidence' => '{"gap":1}', 'attempts_made' => 0,
        ]);
    }

    /**
     * Rejects: a claim that is not guarded by `attempts_made = k` (both
     * ticks read the due incident before either claims it, so both would
     * dispatch).
     */
    public function test_two_tick_processes_at_a_barrier_deliver_one_incident(): void
    {
        $holds = [Barrier::forTest(), Barrier::forTest()];
        $ticks = [];

        foreach ($holds as $hold) {
            $ticks[] = $this->tick($hold, StuckIncidents::BEFORE_CLAIM);
        }

        foreach ($ticks as $index => $tick) {
            $holds[$index]->await(StuckIncidents::BEFORE_CLAIM.'.reached', 60, $tick->abandonIfExited());
        }

        foreach ($holds as $hold) {
            $hold->arrive(StuckIncidents::BEFORE_CLAIM.'.release');
        }

        foreach ($ticks as $tick) {
            self::assertSame(0, $tick->waitForExit(60), $tick->describe());
        }

        self::assertSame(1, RecordStuckDispatchesProvider::count($this->dispatches));
        $incident = $this->incident();
        self::assertSame(1, (int) $incident->attempts_made);
        self::assertNotNull($incident->delivered_at);
    }

    /**
     * Rejects: a fourth claim after a third dispatch whose process died
     * before it recorded the delivery (no `delivery_failed_at` at the third
     * claim, or a due read without `attempts_made < 3`).
     */
    public function test_a_tick_killed_after_the_third_dispatch_leaves_3_dispatches(): void
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->killAt(StuckIncidents::AFTER_DISPATCH);
            self::assertSame($attempt, RecordStuckDispatchesProvider::count($this->dispatches));
        }

        $this->finish();

        self::assertSame(3, RecordStuckDispatchesProvider::count($this->dispatches));
        $incident = $this->incident();
        self::assertSame(3, (int) $incident->attempts_made);
        self::assertNotNull($incident->delivery_failed_at);
        self::assertNull($incident->delivered_at);
    }

    /**
     * Rejects: a claim that frees its attempt when its process dies before
     * the dispatch (a fourth claim): killed between the third claim and its
     * dispatch, the incident keeps 2 dispatches.
     */
    public function test_a_tick_killed_between_the_third_claim_and_its_dispatch_leaves_2_dispatches(): void
    {
        $this->killAt(StuckIncidents::AFTER_DISPATCH);
        $this->killAt(StuckIncidents::AFTER_DISPATCH);
        $this->killAt(StuckIncidents::AFTER_CLAIM);

        $this->finish();

        self::assertSame(2, RecordStuckDispatchesProvider::count($this->dispatches));
        $incident = $this->incident();
        self::assertSame(3, (int) $incident->attempts_made);
        self::assertNotNull($incident->delivery_failed_at);
    }

    /**
     * Rejects: a delivery recorded before the dispatch (a killed tick would
     * leave the incident delivered without an event): killed after its
     * first dispatch, the next tick dispatches again (a duplicate within
     * the 3) and records the delivery.
     */
    public function test_a_tick_killed_after_an_earlier_dispatch_dispatches_again_within_3(): void
    {
        $this->killAt(StuckIncidents::AFTER_DISPATCH);
        self::assertNull($this->incident()->delivered_at);

        $this->finish();

        self::assertSame(2, RecordStuckDispatchesProvider::count($this->dispatches));
        $incident = $this->incident();
        self::assertSame(2, (int) $incident->attempts_made);
        self::assertNotNull($incident->delivered_at);
        self::assertNull($incident->delivery_failed_at);
    }

    /**
     * Rejects: a send decision without the lock of the key's cooldown row
     * (two overlapping ticks would both read the cooldown as free and both
     * send for one queue and signal). Worker A holds inside its decision,
     * after its locked read; worker B, for another job on the same queue,
     * is held before its lock and released only then, so it reaches the
     * lock while A holds it. B must wait, then find A's cooldown and hold
     * its incident back. On SQLite BEGIN IMMEDIATE serializes the two
     * anyway, so the test passes there by design; the server engines prove
     * the row lock.
     */
    public function test_two_ticks_send_one_message_for_one_key(): void
    {
        $now = Clock::toDecimal(Clock::now());
        $this->mirror()->table(StuckIncidents::TABLE)->delete();
        $this->mirror()->table('orrery_jobs')->delete();

        foreach ([1, 2] as $n) {
            $uuid = sprintf('00000000-0000-4000-8000-00000000000%d', $n);
            $this->mirror()->table('orrery_jobs')->insert([
                'uuid' => $uuid, 'connection' => 'database', 'queue' => 'alpha', 'name' => 'App\\Jobs\\Stuck', 'type' => 'job',
                // Still in the state of its signal: a round ends the incident of a finished job.
                'status' => 'pending', 'payload' => '{}', 'payload_bytes' => 2, 'pushed_at' => $now, 'updated_at' => $now,
            ]);
            $this->mirror()->table(StuckIncidents::TABLE)->insert([
                'job_uuid' => $uuid, 'cycle' => 1, 'signal' => StuckSignals::OUTCOME_NOT_OBSERVED, 'first_seen_at' => $now,
                'evidence' => '{"gap":1}', 'attempts_made' => 1, 'delivered_at' => $now,
                // The second incident is not due when worker A reads its rounds.
                'notify_next_at' => $n === 1 ? $now : Clock::toDecimal(Clock::now() + 3600),
            ]);
        }

        [$holdA, $holdB] = [Barrier::forTest(), Barrier::forTest()];
        $after = StuckNotifications::AFTER_LOCK;
        $env = ['ORRERY_TEST_PROVIDERS' => IncidentCommandsProvider::class];
        $a = WorkerProcess::start(['test:stuck-notifications'], $this->testEnvironment(), $env + $holdA->holdEnvironment(StuckNotifications::BEFORE_CLAIM, $after));
        $holdA->await(StuckNotifications::BEFORE_CLAIM.'.reached', 60, $a->abandonIfExited());
        // A read its due rounds: only the first incident. The second one becomes due for B alone.
        $this->mirror()->table(StuckIncidents::TABLE)->where('job_uuid', sprintf('00000000-0000-4000-8000-00000000000%d', 2))->update(['notify_next_at' => $now]);
        $holdA->arrive(StuckNotifications::BEFORE_CLAIM.'.release');
        $holdA->await($after.'.reached', 60, $a->abandonIfExited());

        if ($this->testEnvironment()->driver === 'sqlite') {
            // SQLite: A's transaction holds the file's write lock, so B cannot even claim its round
            // meanwhile. B reads its due round while A holds the lock, and goes on after A's commit.
            $b = WorkerProcess::start(['test:stuck-notifications'], $this->testEnvironment(), $env + $holdB->holdEnvironment(StuckNotifications::BEFORE_CLAIM));
            $holdB->await(StuckNotifications::BEFORE_CLAIM.'.reached', 60, $b->abandonIfExited());
            $holdA->arrive($after.'.release');
            self::assertSame(0, $a->waitForExit(60), $a->describe());
            $holdB->arrive(StuckNotifications::BEFORE_CLAIM.'.release');
        } else {
            // B is held before its lock, and released only while A holds it.
            $b = WorkerProcess::start(['test:stuck-notifications'], $this->testEnvironment(), $env + $holdB->holdEnvironment(StuckNotifications::BEFORE_LOCK, $after));
            $holdB->await(StuckNotifications::BEFORE_LOCK.'.reached', 60, $b->abandonIfExited());
            $holdB->arrive(StuckNotifications::BEFORE_LOCK.'.release');

            try {
                $holdB->await($after.'.reached', 1.5, $b->abandonIfExited());
                self::fail('Worker B read the cooldown while worker A held its lock.');
            } catch (WaitTimeout) {
                // B waits for A's lock.
            }

            $holdA->arrive($after.'.release');
            $holdB->await($after.'.reached', 60, $b->abandonIfExited());
            $holdB->arrive($after.'.release');
        }

        self::assertSame(0, $a->waitForExit(60), $a->describe());
        self::assertSame(0, $b->waitForExit(60), $b->describe());

        $states = $this->mirror()->table(StuckIncidents::TABLE)->orderBy('job_uuid')->pluck('notified')->map(static fn (mixed $state): mixed => json_decode((string) $state, true))->all();
        self::assertSame([['mail' => StuckNotifications::SENT], ['mail' => StuckNotifications::SUPPRESSED]], $states, 'A sent, B held its incident back');
        self::assertSame(1, (int) $this->mirror()->table(StuckNotifications::COOLDOWNS)->value('held'));
    }

    /**
     * Starts `orrery:tick` that records its dispatches and holds at $point.
     */
    private function tick(Barrier $hold, string $point): WorkerProcess
    {
        return WorkerProcess::start(
            ['orrery:tick'],
            $this->testEnvironment(),
            ['ORRERY_TEST_PROVIDERS' => RecordStuckDispatchesProvider::class, RecordStuckDispatchesProvider::DIRECTORY => $this->dispatches->directory()]
                + $hold->holdEnvironment($point),
        );
    }

    /**
     * Makes the incident due, runs a tick to $point and kills it there.
     */
    private function killAt(string $point): void
    {
        $this->makeDue();
        $hold = Barrier::forTest();
        $tick = $this->tick($hold, $point);
        $hold->await($point.'.reached', 60, $tick->abandonIfExited());
        $tick->stop(0.0);
    }

    /**
     * Makes the incident due (when it still can be) and runs one more tick
     * to its end.
     */
    private function finish(): void
    {
        $this->makeDue();
        $tick = WorkerProcess::start(
            ['orrery:tick'],
            $this->testEnvironment(),
            ['ORRERY_TEST_PROVIDERS' => RecordStuckDispatchesProvider::class, RecordStuckDispatchesProvider::DIRECTORY => $this->dispatches->directory()],
        );
        self::assertSame(0, $tick->waitForExit(60), $tick->describe());
    }

    private function makeDue(): void
    {
        $this->mirror()->table(StuckIncidents::TABLE)->where('job_uuid', self::UUID)->whereNotNull('next_attempt_at')
            ->update(['next_attempt_at' => Clock::toDecimal(Clock::now() - 1)]);
    }

    private function incident(): stdClass
    {
        $incident = $this->mirror()->table(StuckIncidents::TABLE)->where('job_uuid', self::UUID)->first();
        self::assertInstanceOf(stdClass::class, $incident);

        return $incident;
    }
}
