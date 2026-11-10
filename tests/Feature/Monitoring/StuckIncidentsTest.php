<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring;

use ArrayObject;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Justrau\Orrery\Console\TickCommand;
use Justrau\Orrery\Database\Schema\DeferredIndexes;
use Justrau\Orrery\Database\Schema\IndexName;
use Justrau\Orrery\Database\Schema\SchemaOutdated;
use Justrau\Orrery\Events\StuckJobDetected;
use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Maintenance\RunsPastDeadline;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickRunner;
use Justrau\Orrery\Monitoring\Phases\StuckEventsPhase;
use Justrau\Orrery\Monitoring\Phases\StuckIncidentsPhase;
use Justrau\Orrery\Monitoring\Phases\StuckNotificationsPhase;
use Justrau\Orrery\Monitoring\StuckIncidents;
use Justrau\Orrery\Monitoring\StuckSignals;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\SiblingFileUnavailable;
use Justrau\Orrery\Tests\Feature\Lists\ListFixtures;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;

/**
 * The stuck incidents and StuckJobDetected:
 * creation per signal after a persisted cursor,
 * the `unknown` start time, the delivery bound of 3 claims with its
 * backoff, the cycle check, `index_missing` once per change, and the
 * notification routes.
 */
final class StuckIncidentsTest extends TestCase
{
    use ListFixtures {
        addRow as addListRow;
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('queue.connections.database.retry_after', 60);
        $app['config']->set('mail.default', 'array');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Hooks::$callback = null;

        parent::tearDown();
    }

    /**
     * Rejects: a signal without its incident, an incident of another cycle
     * than the row's current one, evidence other than the signal's facts, a
     * finished row or an unknown row older than `incidents_since` with an
     * incident, a delivery that is not recorded (`delivered_at`,
     * `attempts_made` 1), an event without the row's connection, queue and
     * name, and a second run that dispatches or inserts again.
     */
    public function test_each_signal_gets_one_incident_of_the_current_cycle_and_one_event(): void
    {
        $now = Clock::now();
        $this->setSince($now - 100);
        $this->addRow(1, 'reserved', 1, ['reserved_at' => Clock::toDecimal($now - 120), 'cycle' => 3, 'queue' => 'emails', 'name' => 'App\\Jobs\\Mail']);
        $this->addRow(2, 'pending', 2, ['observed_gap' => 2, 'cycle' => 2]);
        $this->addRow(3, 'pending', 3, ['releases' => 25, 'attempts' => 26, 'max_tries' => 30]);
        $this->addRow(4, 'reserved', 4, ['attempts' => 31, 'max_tries' => 0, 'reserved_at' => Clock::toDecimal($now)]);
        $this->addRow(5, 'unknown', 5, ['unknown_since' => Clock::toDecimal($now - 50), 'finished_at' => Clock::toDecimal($now - 50), 'unknown_reason' => 'absent']);
        // No incident: a finished row with a gap, and an unknown row from before incidents_since.
        $this->addRow(6, 'completed', 6, ['observed_gap' => 4]);
        $this->addRow(7, 'unknown', 7, ['unknown_since' => Clock::toDecimal($now - 150), 'finished_at' => Clock::toDecimal($now - 150), 'unknown_reason' => 'absent']);
        $this->flushRows();
        $events = $this->recordEvents();

        $report = $this->runIncidents();

        self::assertSame([
            [self::uuidOf(1), 3, 'reservation_expired', ['lease_expires_at' => round($now - 60, 6), 'retry_after' => 60]],
            [self::uuidOf(2), 2, 'outcome_not_observed', ['gap' => 2]],
            [self::uuidOf(3), 1, 'repeated_releases', ['releases' => 25]],
            [self::uuidOf(4), 1, 'attempts_not_capped', ['attempts' => 31, 'rule' => 'none']],
            [self::uuidOf(5), 1, 'unknown', ['reason' => 'absent']],
        ], array_map(static fn (stdClass $row): array => [$row->job_uuid, (int) $row->cycle, $row->signal, self::roundFacts(json_decode($row->evidence, true))], $this->incidents()));
        self::assertSame(5, $report['delivered']);

        foreach ($this->incidents() as $incident) {
            self::assertSame(1, (int) $incident->attempts_made);
            self::assertNotNull($incident->delivered_at);
            self::assertNull($incident->delivery_failed_at);
        }

        $first = array_values(array_filter($events->getArrayCopy(), static fn (StuckJobDetected $event): bool => $event->signal === 'reservation_expired'))[0] ?? null;
        self::assertInstanceOf(StuckJobDetected::class, $first);
        self::assertSame([self::uuidOf(1), 3, 'reservation_expired', 'database', 'emails', 'App\\Jobs\\Mail', 60], [$first->uuid, $first->cycle, $first->signal, $first->connection, $first->queue, $first->name, $first->evidence['retry_after']]);
        self::assertCount(5, $events);

        $again = $this->runIncidents();
        self::assertSame(0, $again['claimed'], 'a delivered incident is not claimed again');
        self::assertCount(5, $events, 'a second run dispatches nothing');
        self::assertCount(5, $this->incidents(), 'a second run inserts no second incident');
    }

    /**
     * Rejects: a creation without a persisted cursor (every run would read
     * the same 500 rows, so the rows after them never get an incident), a
     * cursor kept in process memory (each run is a new tick process here: a
     * new StuckIncidents), more than 500 examined rows per signal, and a
     * cursor that does not wrap at the end of the range.
     */
    public function test_1200_matching_rows_all_get_incidents_within_3_runs(): void
    {
        // The 2 s creation share (StuckIncidents::CREATE_SECONDS) must not cut a run of 500 on a loaded machine.
        Clock::freezeMonotonicForTesting();

        for ($n = 1; $n <= 1200; $n++) {
            $this->addRow($n, $n % 2 === 0 ? 'pending' : 'reserved', $n, ['observed_gap' => 1 + $n % 7, 'reserved_at' => $n % 2 === 0 ? null : Clock::toDecimal(Clock::now())]);
        }

        $this->flushRows();
        $this->recordEvents();
        $count = fn (): int => $this->mirror()->table(StuckIncidents::TABLE)->where('signal', 'outcome_not_observed')->count();
        $totals = [];

        for ($run = 1; $run <= 3; $run++) {
            self::assertLessThanOrEqual(500, $this->runIncidents()['created']['outcome_not_observed'], 'a run examines at most 500 rows');
            $totals[] = $count();
        }

        self::assertSame([500, 1000, 1200], $totals, 'each run continues after the cursor of the run before');
        self::assertNull($this->setting(StuckIncidents::CURSOR_PREFIX.'outcome_not_observed'), 'the end of the last range wraps the cursor');

        self::assertSame(0, $this->runIncidents()['created']['outcome_not_observed'], 'after the wrap a run starts at the beginning, and inserts no existing incident');
        self::assertNotNull($this->setting(StuckIncidents::CURSOR_PREFIX.'outcome_not_observed'), 'that run stopped after 500 rows');
        self::assertSame(1200, $count());
    }

    /**
     * Rejects: a creation that reads the two ranges of a signal merged in
     * one order (with 600 pending rows at gap 7 and 600 running rows at gap
     * 1, the rows of the second range that a merged scan buffered are
     * dropped at each stop), a second range that starts after the first
     * range's cursor (its larger gaps would be skipped), and a run that
     * ends exactly at the end of the first range and then wraps (the next
     * run would read the first range again, never the second).
     *
     * @param  array{0: int, 1: int, 2: int, 3: int}  $shape  pending rows, their gap, running rows, their gap
     */
    #[DataProvider('skewedRanges')]
    public function test_1200_rows_of_two_skewed_ranges_get_incidents_within_3_runs(array $shape): void
    {
        [$pending, $pendingGap, $running, $runningGap] = $shape;
        // The 2 s creation share (StuckIncidents::CREATE_SECONDS) must not cut a run of 500 on a loaded machine.
        Clock::freezeMonotonicForTesting();

        for ($n = 1; $n <= $pending + $running; $n++) {
            $isPending = $n <= $pending;
            $this->addRow($n, $isPending ? 'pending' : 'reserved', $n, ['observed_gap' => $isPending ? $pendingGap : $runningGap, 'reserved_at' => $isPending ? null : Clock::toDecimal(Clock::now())]);
        }

        $this->flushRows();
        $this->recordEvents();
        $totals = [];

        for ($run = 1; $run <= 3; $run++) {
            $this->runIncidents();
            $totals[] = $this->mirror()->table(StuckIncidents::TABLE)->where('signal', 'outcome_not_observed')->count();
        }

        self::assertSame([500, 1000, 1200], $totals);
        self::assertNull($this->setting(StuckIncidents::CURSOR_PREFIX.'outcome_not_observed'));
    }

    /**
     * Rejects: a keyset that skips or repeats rows of the decimal-ordered
     * reads (the unknown rows by `finished_at`, the expired reservations by
     * `reserved_at`) across two runs, and a cursor that does not wrap after
     * the last row.
     */
    public function test_the_unknown_and_expiry_reads_continue_after_their_cursor(): void
    {
        $now = Clock::now();
        $this->setSince($now - 10_000);

        for ($n = 1; $n <= 520; $n++) {
            // Pairs of rows share one time, so the keyset needs the id after the time.
            $at = Clock::toDecimal($now - 9_000 + intdiv($n, 2));
            $this->addRow($n, 'unknown', $n, ['unknown_since' => $at, 'finished_at' => $at, 'unknown_reason' => 'absent']);
            $this->addRow(1000 + $n, 'reserved', $n, ['reserved_at' => Clock::toDecimal($now - 5_000 + intdiv($n, 2))]);
        }

        $this->flushRows();
        $this->recordEvents();
        $count = fn (string $signal): int => $this->mirror()->table(StuckIncidents::TABLE)->where('signal', $signal)->count();

        $first = $this->runIncidents()['created'];
        self::assertSame([500, 500], [$first['unknown'], $first['reservation_expired']]);
        self::assertSame([500, 500], [$count('unknown'), $count('reservation_expired')]);

        $second = $this->runIncidents()['created'];
        self::assertSame([20, 20], [$second['unknown'], $second['reservation_expired']]);
        self::assertSame([520, 520], [$count('unknown'), $count('reservation_expired')]);
        self::assertNull($this->setting(StuckIncidents::CURSOR_PREFIX.'unknown'));
        self::assertNull($this->setting(StuckIncidents::CURSOR_PREFIX.'reservation_expired'));
    }

    /**
     * Rejects: an `unknown` incident for a row that was unknown before the
     * first run (an install would raise one event per old row), and a first
     * run that does not write `incidents_since` or moves it later.
     */
    public function test_no_unknown_incident_for_rows_older_than_incidents_since(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));
        $this->addRow(1, 'unknown', 1, ['unknown_since' => Clock::toDecimal(1_799_999_000.0), 'finished_at' => Clock::toDecimal(1_799_999_000.0), 'unknown_reason' => 'absent']);
        $this->flushRows();
        $this->recordEvents();

        $this->runIncidents();
        self::assertSame(Clock::toDecimal(1_800_000_000.0), Clock::toDecimal((float) $this->setting(StuckIncidents::SINCE)), 'the first run writes its time');
        self::assertSame([], $this->incidents());

        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_600));
        $this->addRow(2, 'unknown', 2, ['unknown_since' => Clock::toDecimal(1_800_000_300.0), 'finished_at' => Clock::toDecimal(1_800_000_300.0), 'unknown_reason' => 'bulk_unresolved']);
        $this->flushRows();
        $this->runIncidents();

        self::assertSame(Clock::toDecimal(1_800_000_000.0), Clock::toDecimal((float) $this->setting(StuckIncidents::SINCE)), 'a later run keeps the first time');
        self::assertSame([[self::uuidOf(2), 'unknown', '{"reason":"bulk_unresolved"}']], array_map(static fn (stdClass $row): array => [$row->job_uuid, $row->signal, $row->evidence], $this->incidents()));
    }

    /**
     * Rejects: a stuck incident (and so a notification) for a row that is unknown only because its queue
     * connection is not in this process's configuration (reason `connection_removed`): a stale configuration on
     * the scheduler host can mark live jobs so. An unknown row with the reason `absent` still raises its
     * incident. Would still pass: a filter that also leaves out another reason that no test names.
     */
    public function test_a_removed_connections_unknown_row_raises_no_incident(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));
        $this->runIncidents();

        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_600));
        $this->addRow(1, 'unknown', 1, ['unknown_since' => Clock::toDecimal(1_800_000_300.0), 'finished_at' => Clock::toDecimal(1_800_000_300.0), 'unknown_reason' => Reconciliation::REMOVED_REASON]);
        $this->addRow(2, 'unknown', 2, ['unknown_since' => Clock::toDecimal(1_800_000_300.0), 'finished_at' => Clock::toDecimal(1_800_000_300.0), 'unknown_reason' => Reconciliation::UNKNOWN_REASON]);
        $this->flushRows();
        $this->runIncidents();

        self::assertSame([[self::uuidOf(2), 'unknown', '{"reason":"absent"}']], array_map(static fn (stdClass $row): array => [$row->job_uuid, $row->signal, $row->evidence], $this->incidents()));
        self::assertSame(['unknown', Reconciliation::REMOVED_REASON], [$this->mirror()->table('orrery_jobs')->where('uuid', self::uuidOf(1))->value('status'), $this->mirror()->table('orrery_jobs')->where('uuid', self::uuidOf(1))->value('unknown_reason')]);
    }

    /**
     * Rejects: a claim without the backoff (a second claim at once), a
     * fourth dispatch, a last claim that does not mark `delivery_failed_at`,
     * and a failed dispatch recorded as delivered.
     */
    public function test_a_failing_listener_gets_three_attempts_then_delivery_failed_at(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));
        $this->incidentFor(1);
        $calls = new ArrayObject;
        Event::listen(StuckJobDetected::class, static function () use ($calls): never {
            $calls[] = Clock::now();

            throw new RuntimeException('The listener is down.');
        });

        $this->runIncidents();
        self::assertSame([1, '1800000060.000000', null], $this->delivery());
        $this->runIncidents();
        self::assertCount(1, $calls, 'the backoff of 60 s holds the second claim');

        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_060));
        $this->runIncidents();
        self::assertSame([2, '1800000360.000000', null], $this->delivery());

        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_359));
        $this->runIncidents();
        self::assertCount(2, $calls, 'the backoff of 300 s holds the third claim');

        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_360));
        $report = $this->runIncidents();
        self::assertSame([3, null, '1800000360.000000'], $this->delivery());
        self::assertSame(1, $report['failed']);

        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_100_000));
        $this->runIncidents();
        self::assertCount(3, $calls, 'no fourth dispatch');
        self::assertNull($this->mirror()->table(StuckIncidents::TABLE)->value('delivered_at'));
        self::assertArrayHasKey(StuckIncidents::DISPATCH_OPERATION, Degradation::record()['errors'] ?? [], 'the failed dispatch is recorded');
    }

    /**
     * Rejects: a successful last dispatch that keeps the failure mark, and
     * a success that does not record `delivered_at`.
     */
    public function test_a_third_dispatch_that_succeeds_clears_the_failure_mark(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));
        $this->incidentFor(1);
        $calls = new ArrayObject;
        Event::listen(StuckJobDetected::class, static function () use ($calls): void {
            $calls[] = true;

            if (count($calls) < 3) {
                throw new RuntimeException('The listener fails twice.');
            }
        });

        foreach ([0, 60, 360] as $seconds) {
            Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000 + $seconds));
            $this->runIncidents();
        }

        $row = $this->mirror()->table(StuckIncidents::TABLE)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertSame([3, '1800000360.000000', null], [(int) $row->attempts_made, Clock::toDecimal((float) $row->delivered_at), $row->delivery_failed_at]);
        self::assertCount(3, $calls);
    }

    /**
     * Rejects: a claim without its `attempts_made = k` guard (another
     * process claimed the same attempt between the due read and the claim),
     * and a delivery without any `attempts_made < 3` check (the due read,
     * the check before the claim and the claim's guard all left out): an
     * incident with 3 claims and no marks is dispatched. Still passes: a
     * delivery that leaves out only one of those three checks.
     */
    public function test_a_lost_claim_and_a_spent_incident_dispatch_nothing(): void
    {
        $this->incidentFor(1);
        $this->incidentFor(2);
        $this->mirror()->table(StuckIncidents::TABLE)->where('job_uuid', self::uuidOf(2))->update(['attempts_made' => 3]);
        $events = $this->recordEvents();
        Hooks::$callback = function (string $point): void {
            if ($point === StuckIncidents::BEFORE_CLAIM) {
                // Another tick claims the first attempt between this tick's due read and its claim.
                $this->mirror()->table(StuckIncidents::TABLE)->where('job_uuid', self::uuidOf(1))->update(['attempts_made' => 1, 'next_attempt_at' => Clock::toDecimal(Clock::now() + 60)]);
            }
        };

        $report = $this->runIncidents();

        self::assertSame(0, $report['claimed']);
        self::assertCount(0, $events);
    }

    /**
     * Rejects: a dispatch for a cycle that ended (the row moved on) or a job
     * that is gone, and an incident kept after such a check.
     */
    public function test_an_incident_of_an_ended_cycle_or_a_gone_job_is_deleted_at_delivery(): void
    {
        $this->incidentFor(1);
        $this->incidentFor(2);
        $this->mirror()->table('orrery_jobs')->where('uuid', self::uuidOf(1))->update(['cycle' => 2]);
        $this->mirror()->table('orrery_jobs')->where('uuid', self::uuidOf(2))->delete();
        $events = $this->recordEvents();

        $report = $this->runIncidents();

        self::assertSame([2, 2, 0], [$report['claimed'], $report['deleted'], count($events)]);
        self::assertSame([], $this->incidents());
    }

    /**
     * Rejects: a delivery that compares only the cycle, so a job that
     * completed (or failed) in the same cycle after its incident was created
     * is still reported as stuck; and an `unknown` incident of a row that
     * left `unknown`.
     */
    public function test_an_incident_of_a_job_that_finished_in_its_cycle_is_deleted_at_delivery(): void
    {
        $this->incidentFor(1);
        $this->incidentFor(2);
        $this->incidentFor(3, ['signal' => StuckIncidents::UNKNOWN]);
        $this->incidentFor(4);
        $this->mirror()->table('orrery_jobs')->where('uuid', self::uuidOf(1))->update(['status' => 'completed']);
        $this->mirror()->table('orrery_jobs')->where('uuid', self::uuidOf(2))->update(['status' => 'failed']);
        $events = $this->recordEvents();

        $report = $this->runIncidents();

        self::assertSame([4, 3], [$report['claimed'], $report['deleted']]);
        self::assertSame([self::uuidOf(4)], array_map(static fn (StuckJobDetected $event): string => $event->uuid, $events->getArrayCopy()), 'only the job that is still pending was reported');
        self::assertSame([self::uuidOf(4)], array_map(static fn (stdClass $row): string => (string) $row->job_uuid, $this->incidents()));
    }

    /**
     * Rejects: a delivery of more than 50 incidents per run, and a due read
     * that ignores `next_attempt_at`.
     */
    public function test_a_run_delivers_at_most_50_due_incidents(): void
    {
        // The 3 s share is not under test: a slow machine must not end the delivery early.
        Clock::freezeMonotonicForTesting();

        for ($n = 1; $n <= 60; $n++) {
            $this->incidentFor($n);
        }

        $this->incidentFor(61, ['next_attempt_at' => Clock::toDecimal(Clock::now() + 600), 'attempts_made' => 1]);
        $events = $this->recordEvents();

        self::assertSame(50, $this->runIncidents()['delivered']);
        self::assertSame(10, $this->runIncidents()['delivered']);
        self::assertSame(0, $this->runIncidents()['claimed'], 'an incident before its next attempt is not due');
        self::assertCount(60, $events);
    }

    /**
     * Rejects: an encoding over 1 KB stored, and an encoding that is not a
     * JSON object (an empty list).
     */
    public function test_evidence_is_a_json_object_of_at_most_1_kb(): void
    {
        self::assertSame('{"gap":2}', StuckIncidents::evidence(['gap' => 2]));
        self::assertSame('{}', StuckIncidents::evidence([]));
        self::assertSame(StuckIncidents::TRUNCATED, StuckIncidents::evidence(['rule' => str_repeat('x', 1100)]));
        self::assertSame(StuckIncidents::TRUNCATED, StuckIncidents::evidence(['gap' => NAN]));
        self::assertLessThanOrEqual(StuckIncidents::EVIDENCE_BYTES, strlen(StuckIncidents::evidence(['rule' => str_repeat('x', 1013)])));
        self::assertSame([60, 300, null], [StuckIncidents::backoff(1), StuckIncidents::backoff(2), StuckIncidents::backoff(3)]);
    }

    /**
     * Rejects: a signal read without its deferred index (a full scan of
     * orrery_jobs every tick), `index_missing` recorded by every tick (each
     * tick is a new process), and a change of the missing set that is not
     * recorded again.
     */
    #[FreshSchema]
    public function test_a_missing_deferred_index_skips_its_signal_and_records_index_missing_once(): void
    {
        $now = Clock::now();
        $this->addRow(1, 'pending', 1, ['observed_gap' => 2]);
        $this->addRow(2, 'reserved', 2, ['reserved_at' => Clock::toDecimal($now - 120)]);
        $this->flushRows();
        $this->recordEvents();
        $this->dropIndex('st_gap');

        $report = $this->runIncidents();
        self::assertSame(['outcome_not_observed'], $report['skipped']);
        self::assertSame(['reservation_expired'], array_column($this->incidents(), 'signal'));
        self::assertArrayHasKey(DeferredIndexes::OPERATION, Degradation::record()['errors'] ?? []);
        self::assertSame('["st_gap"]', $this->setting(StuckIncidents::INDEX_MISSING));

        // The next tick is a new process: a new DeferredIndexes, an empty degradation record.
        $this->newTickProcess();
        $this->runIncidents();
        self::assertArrayNotHasKey(DeferredIndexes::OPERATION, Degradation::record()['errors'] ?? [], 'the same missing index is not recorded again');

        $this->dropIndex('st_attempts');
        $this->newTickProcess();
        $skipped = $this->runIncidents()['skipped'];
        sort($skipped);
        self::assertSame(['attempts_not_capped', 'outcome_not_observed'], $skipped);
        self::assertArrayHasKey(DeferredIndexes::OPERATION, Degradation::record()['errors'] ?? [], 'a changed set is recorded');
        self::assertSame('["st_attempts","st_gap"]', $this->setting(StuckIncidents::INDEX_MISSING));
    }

    /**
     * Rejects: a missing-index record that depends on how far a run got (a
     * run that stops before the signal of the missing index would clear the
     * setting, and the next tick process would record `index_missing`
     * again).
     */
    #[FreshSchema]
    public function test_a_run_that_stops_early_keeps_the_missing_index_record(): void
    {
        $this->recordEvents();
        $this->dropIndex('st_attempts');

        $this->runIncidents();
        self::assertSame('["st_attempts"]', $this->setting(StuckIncidents::INDEX_MISSING));

        $this->newTickProcess();
        $this->runIncidents(new TickBudget(Clock::monotonic()));
        self::assertSame('["st_attempts"]', $this->setting(StuckIncidents::INDEX_MISSING), 'a run without time keeps the record');

        $this->newTickProcess();
        $this->runIncidents();
        self::assertArrayNotHasKey(DeferredIndexes::OPERATION, Degradation::record()['errors'] ?? [], 'the next tick does not record it again');
    }

    /**
     * Rejects: a delivery that goes on claiming while the mirror breaker is
     * open (each claim would spend an attempt of an incident whose dispatch
     * the watcher skips).
     */
    public function test_an_open_breaker_stops_the_delivery_before_its_next_claim(): void
    {
        $this->incidentFor(1);
        $this->incidentFor(2);
        $calls = new ArrayObject;
        Event::listen(StuckJobDetected::class, static function () use ($calls): void {
            $calls[] = true;

            // A mirror dependency error opens the breaker.
            throw new SiblingFileUnavailable('/tmp/orrery-test.orrery.sqlite', 'the test makes the mirror unavailable');
        });

        $report = $this->runIncidents();

        self::assertTrue($this->app->make(Watcher::class)->breakerOpen());
        self::assertSame([1, 1], [$report['claimed'], count($calls)]);
        $made = $this->mirror()->table(StuckIncidents::TABLE)->orderBy('attempts_made')->pluck('attempts_made')->map(static fn (mixed $made): int => (int) $made)->all();
        self::assertSame([0, 1], $made, 'the second incident keeps its attempts');
    }

    /**
     * Rejects: a fixed signal order (with a share that allows one signal per
     * run, the later signals would never get an incident) and a creation
     * that leaves no time for the delivery.
     */
    public function test_a_tight_share_rotates_the_signals_and_still_delivers(): void
    {
        $now = Clock::now();
        $this->setSince($now - 100);
        $this->addRow(1, 'reserved', 1, ['reserved_at' => Clock::toDecimal($now - 120)]);
        $this->addRow(2, 'pending', 2, ['observed_gap' => 2]);
        $this->addRow(3, 'pending', 3, ['releases' => 25, 'attempts' => 26, 'max_tries' => 30]);
        $this->addRow(4, 'pending', 4, ['attempts' => 31, 'max_tries' => 0]);
        $this->addRow(5, 'unknown', 5, ['unknown_since' => Clock::toDecimal($now - 50), 'finished_at' => Clock::toDecimal($now - 50), 'unknown_reason' => 'absent']);
        $this->flushRows();
        $this->recordEvents();
        Clock::freezeMonotonicForTesting();
        // Each signal's creation takes 2.5 s of the 3 s share: one signal per run.
        Hooks::$callback = static function (string $point): void {
            if ($point === StuckIncidents::AFTER_SIGNAL) {
                Clock::advanceMonotonicForTesting(2.5);
            }
        };
        $created = [];

        for ($run = 1; $run <= 5; $run++) {
            $report = $this->runIncidents();
            self::assertCount(1, $report['created'], "run {$run} creates one signal");
            self::assertSame(1, $report['delivered'], "run {$run} still delivers");
            $created[] = array_key_first($report['created']);
        }

        sort($created);
        $signals = StuckIncidents::SIGNALS;
        sort($signals);
        self::assertSame($signals, $created, 'every signal gets its turn');
        self::assertCount(5, $this->incidents());
    }

    /**
     * Rejects: a due order that puts every new incident before the retries
     * (with more than 50 new incidents, a retry would wait until they are
     * all delivered).
     */
    public function test_a_due_retry_goes_before_new_incidents(): void
    {
        for ($n = 1; $n <= 55; $n++) {
            $this->incidentFor($n);
        }

        $this->incidentFor(56, ['attempts_made' => 1, 'next_attempt_at' => Clock::toDecimal(Clock::now() - 1)]);
        $events = $this->recordEvents();

        $this->runIncidents();

        self::assertCount(50, $events);
        self::assertContains(self::uuidOf(56), array_map(static fn (StuckJobDetected $event): string => $event->uuid, $events->getArrayCopy()));
    }

    /**
     * Rejects: a phase that fails without a clear error, or one that creates
     * the table itself, on a mirror migrated before step 9; and a tick that
     * stops at the failed phase.
     */
    #[FreshSchema]
    public function test_a_mirror_before_step_9_fails_the_phase_with_schema_outdated(): void
    {
        $this->mirrorSchema()->drop(StuckIncidents::TABLE);

        try {
            $this->runIncidents();
            self::fail('The phase ran without orrery_incidents.');
        } catch (SchemaOutdated $error) {
            self::assertStringStartsWith('schema_outdated: the table orrery_incidents is missing.', $error->getMessage());
        }

        $report = $this->app->make(TickRunner::class)->run($this->app->tagged(TickCommand::PHASES_TAG));
        // The phases of the incidents fail with the hint; the phases between them still run.
        self::assertSame(['stuck_incidents', 'stuck_events', 'stuck_notifications'], $report->failed());
        self::assertContains('marker_retention', $report->ran(), 'the later phases still run');
        self::assertArrayHasKey('tick:stuck_incidents', Degradation::record()['errors'] ?? []);
        self::assertFalse($this->mirrorSchema()->hasTable(StuckIncidents::TABLE));
    }

    /**
     * Rejects: a mirror migrated before step 10 that loses its stuck events
     * (the creation would insert the missing notification columns and fail),
     * and a notifications phase that runs there without the upgrade hint.
     */
    #[FreshSchema]
    public function test_a_mirror_before_step_10_keeps_its_events_and_fails_only_the_notifications(): void
    {
        $mirror = $this->mirror();
        $this->mirrorSchema()->table(StuckIncidents::TABLE, static function (Blueprint $table) use ($mirror): void {
            $table->dropIndex(IndexName::for($mirror, StuckIncidents::TABLE, 'notify_due'));
        });
        $this->mirrorSchema()->table(StuckIncidents::TABLE, static function (Blueprint $table): void {
            $table->dropColumn(['notified', 'notify_attempts', 'notify_next_at']);
        });
        $this->addRow(1, 'pending', 1, ['observed_gap' => 2]);
        $this->flushRows();
        $events = $this->recordEvents();

        self::assertSame(1, $this->runIncidents()['delivered']);
        self::assertCount(1, $events);

        try {
            $this->app->make(StuckNotificationsPhase::class)->run(new TickBudget(Clock::monotonic() + 30));
            self::fail('The notifications ran without their state.');
        } catch (SchemaOutdated $error) {
            self::assertStringStartsWith('schema_outdated: orrery_incidents.notify_next_at is missing.', $error->getMessage());
        }
    }

    /**
     * Rejects: a phase outside the tick, or one at another priority.
     */
    public function test_the_phase_runs_in_the_tick(): void
    {
        $phase = $this->app->make(StuckIncidentsPhase::class);
        self::assertSame(['stuck_incidents', 410], [$phase->name(), $phase->priority()]);
        $events = $this->app->make(StuckEventsPhase::class);
        self::assertSame(['stuck_events', 940], [$events->name(), $events->priority()]);
        $names = [];

        foreach ($this->app->tagged(TickCommand::PHASES_TAG) as $tagged) {
            $names[] = $tagged::class;
        }

        self::assertContains(StuckIncidentsPhase::class, $names);
        self::assertContains(StuckEventsPhase::class, $names);
    }

    /**
     * Rejects: a delivery of StuckJobDetected inside `stuck_incidents`
     * (priority 410), where a slow listener of the application spends the
     * tick's deadline and the later phases (reconcile_failed, operations,
     * the retentions) are skipped. The creation phase
     * dispatches nothing; the late phase `stuck_events` does, and it runs
     * also after the deadline. It would still pass a delivery phase at
     * another late priority.
     */
    public function test_the_events_are_delivered_in_a_late_phase_after_the_deadline(): void
    {
        $this->addRow(1, 'pending', 1, ['observed_gap' => 2]);
        $this->flushRows();
        $events = $this->recordEvents();

        $this->app->make(StuckIncidentsPhase::class)->run(new TickBudget(Clock::monotonic() + 30));
        self::assertSame(1, $this->mirror()->table(StuckIncidents::TABLE)->count(), 'the creation made the incident');
        self::assertCount(0, $events, 'the creation phase dispatched an event');

        self::assertInstanceOf(RunsPastDeadline::class, $this->app->make(StuckEventsPhase::class));
        // A tick whose deadline passed before the phase: it still has its own share.
        $this->app['config']->set('orrery.tick.deadline_seconds', 0);
        $report = $this->app->make(TickRunner::class)->run([$this->app->make(StuckEventsPhase::class)]);
        self::assertSame(['stuck_events'], $report->ran());
        self::assertCount(1, $events, 'the late phase did not deliver');
    }

    /**
     * Queues one orrery_jobs row with the counters that every row of one
     * insert needs (ListFixtures::flushRows() gives a missing column null).
     *
     * @param  array<string, mixed>  $values
     */
    protected function addRow(int $n, string $status, float $at, array $values = []): void
    {
        $this->addListRow($n, $status, $at, $values + ['observed_gap' => 0, 'releases' => 0, 'attempts' => 1, 'cycle' => 1]);
    }

    /**
     * @return array<string, array{array{0: int, 1: int, 2: int, 3: int}}>
     */
    public static function skewedRanges(): array
    {
        return [
            'larger gaps pending' => [[600, 7, 600, 1]],
            'larger gaps running' => [[600, 1, 600, 7]],
            'the first range ends at the budget' => [[500, 3, 700, 3]],
        ];
    }

    /**
     * Runs the incidents once, as one tick run.
     *
     * @return array{created: array<string, int>, skipped: list<string>, claimed: int, delivered: int, failed: int, deleted: int}
     */
    private function runIncidents(?TickBudget $budget = null): array
    {
        return $this->app->make(StuckIncidents::class)->run($budget ?? new TickBudget(Clock::monotonic() + 30));
    }

    /**
     * @return ArrayObject<int, StuckJobDetected>
     */
    private function recordEvents(): ArrayObject
    {
        /** @var ArrayObject<int, StuckJobDetected> $events */
        $events = new ArrayObject;
        Event::listen(StuckJobDetected::class, static function (StuckJobDetected $event) use ($events): void {
            $events[] = $event;
        });

        return $events;
    }

    /**
     * A pending job row $n with a gap, and its due incident.
     *
     * @param  array<string, mixed>  $values
     */
    private function incidentFor(int $n, array $values = []): void
    {
        // No signal holds for the row itself: the creation adds nothing.
        $this->addRow($n, 'pending', $n);
        $this->flushRows();
        $this->mirror()->table(StuckIncidents::TABLE)->insert($values + [
            'job_uuid' => self::uuidOf($n),
            'cycle' => 1,
            'signal' => StuckSignals::OUTCOME_NOT_OBSERVED,
            'first_seen_at' => Clock::toDecimal(Clock::now()),
            'evidence' => '{"gap":2}',
            'attempts_made' => 0,
        ]);
    }

    /**
     * The delivery state of the only incident: attempts_made,
     * next_attempt_at and delivery_failed_at.
     *
     * @return array{int, ?string, ?string}
     */
    private function delivery(): array
    {
        $row = $this->mirror()->table(StuckIncidents::TABLE)->first();
        self::assertInstanceOf(stdClass::class, $row);
        $time = static fn (mixed $value): ?string => $value === null ? null : Clock::toDecimal((float) $value);

        return [(int) $row->attempts_made, $time($row->next_attempt_at), $time($row->delivery_failed_at)];
    }

    /**
     * @return list<stdClass>
     */
    private function incidents(): array
    {
        return array_values($this->mirror()->table(StuckIncidents::TABLE)->orderBy('job_uuid')->orderBy('signal')->get()->all());
    }

    private function setting(string $key): ?string
    {
        $value = $this->mirror()->table('orrery_settings')->where('key', $key)->value('value');

        return $value === null ? null : (string) $value;
    }

    private function setSince(float $at): void
    {
        $this->mirror()->table('orrery_settings')->insert(['key' => StuckIncidents::SINCE, 'value' => Clock::toDecimal($at)]);
    }

    private function dropIndex(string $suffix): void
    {
        $mirror = $this->mirror();
        $this->mirrorSchema()->table('orrery_jobs', static function (Blueprint $table) use ($mirror, $suffix): void {
            $table->dropIndex(IndexName::for($mirror, 'orrery_jobs', $suffix));
        });
        $this->app->make(DeferredIndexes::class)->forget();
    }

    private function newTickProcess(): void
    {
        $this->app->forgetInstance(DeferredIndexes::class);
        Degradation::reset();
    }

    /**
     * Rounds the float facts to 6 decimals (the stored times).
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private static function roundFacts(array $facts): array
    {
        return array_map(static fn (mixed $value): mixed => is_float($value) ? round($value, 6) : $value, $facts);
    }
}
