<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Justrau\Orrery\Database\Schema\DeferredIndexes;
use Justrau\Orrery\Database\Schema\IndexName;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Feature\Lists\ListFixtures;
use Justrau\Orrery\Tests\Support\FreshSchema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET /api/jobs/stuck: the list of one stuck signal with the
 * list contract of 4.3, each row with every signal that holds and the
 * enforced rule; `index_missing` while the signal's deferred index is
 * missing; and worker liveness as a fact, not a signal.
 */
final class StuckJobsTest extends ControllerTest
{
    use ListFixtures;

    /**
     * Rejects: a gap list in another order than the largest gap first (then
     * the newest id), a finished row with a gap listed, a pending or a
     * running row left out (the two merged ranges), a row without its
     * signals, facts and enforced rule, and a total that counts other rows.
     */
    public function test_outcome_not_observed_lists_active_rows_by_the_largest_gap(): void
    {
        $this->addRow(1, 'pending', 1, ['observed_gap' => 1, 'attempts' => 2]);
        $this->addRow(2, 'reserved', 2, ['observed_gap' => 3, 'attempts' => 4, 'reserved_at' => Clock::toDecimal(Clock::now())]);
        $this->addRow(3, 'pending', 3, ['observed_gap' => 3, 'attempts' => 4]);
        $this->addRow(4, 'completed', 4, ['observed_gap' => 5, 'attempts' => 6]);
        $this->addRow(5, 'pending', 5, ['observed_gap' => 0, 'attempts' => 1]);
        $this->addRow(6, 'reserved', 6, ['observed_gap' => 2, 'attempts' => 3, 'reserved_at' => Clock::toDecimal(Clock::now())]);
        $this->flushRows();

        $response = $this->stuck(['signal' => 'outcome_not_observed']);

        self::assertSame([self::uuidOf(3), self::uuidOf(2), self::uuidOf(6), self::uuidOf(1)], array_column($response->json('jobs'), 'id'));
        self::assertSame(['count' => 4, 'at_least' => false], $response->json('total'));
        self::assertSame('desc', $response->json('order'));
        self::assertSame('outcome_not_observed', $response->json('signal'));
        self::assertSame([['signal' => 'outcome_not_observed', 'facts' => ['gap' => 3]]], $response->json('jobs.0.signals'));
        self::assertSame('unknown', $response->json('jobs.0.enforced_rule.kind'));
        self::assertNull($response->json('next_cursor'));
        self::assertNull($response->json('prev_cursor'));
        self::assertNull($response->json('has_newer'));
        self::assertFalse($response->json('search_limited'));
        self::assertArrayHasKey('last_verified', $response->json());
        self::assertArrayNotHasKey('payload', $response->json('jobs.0'));
    }

    /**
     * Rejects: an expiry read with one retry_after for every connection
     * (the per-connection check left out), a lease that is still live
     * listed, a pending row listed, and an order other than the oldest
     * reservation first. With connection and queue, it rejects a list that
     * mixes in the rows of another queue.
     */
    public function test_reservation_expired_checks_each_connections_retry_after(): void
    {
        $this->app['config']->set('queue.connections.database.retry_after', 60);
        // Another jobs table: its own lease (a shared table would share the shortest one).
        $this->app['config']->set('queue.connections.slow', ['driver' => 'database', 'table' => 'slow_jobs', 'queue' => 'default', 'retry_after' => 600]);
        $now = Clock::now();
        $reserved = static fn (float $ago): array => ['reserved_at' => Clock::toDecimal($now - $ago)];
        $this->addRow(1, 'reserved', 1, $reserved(100));
        $this->addRow(2, 'reserved', 2, $reserved(300) + ['connection' => 'slow']);
        $this->addRow(3, 'reserved', 3, $reserved(700) + ['connection' => 'slow']);
        $this->addRow(4, 'reserved', 4, $reserved(30));
        $this->addRow(5, 'reserved', 5, $reserved(200) + ['queue' => 'emails']);
        $this->addRow(6, 'pending', 6, $reserved(900));
        $this->flushRows();

        $response = $this->stuck(['signal' => 'reservation_expired']);
        self::assertSame([self::uuidOf(3), self::uuidOf(5), self::uuidOf(1)], array_column($response->json('jobs'), 'id'));
        self::assertSame('asc', $response->json('order'));
        self::assertSame(['count' => 3, 'at_least' => false], $response->json('total'));
        self::assertSame(600, $response->json('jobs.0.signals.0.facts.retry_after'));
        self::assertEqualsWithDelta($now - 100, $response->json('jobs.0.signals.0.facts.lease_expires_at'), 0.01);
        self::assertSame(['id' => null, 'heartbeat_at' => null, 'liveness' => 'unknown', 'silent_since' => null], $response->json('jobs.0.worker'), 'a running row carries its worker fact');
        self::assertEqualsWithDelta($now - 100, $response->json('jobs.0.lease_expires_at'), 0.01);

        $queue = $this->stuck(['signal' => 'reservation_expired', 'connection' => 'database', 'queue' => 'default']);
        self::assertSame([self::uuidOf(1)], array_column($queue->json('jobs'), 'id'));
        self::assertSame(['count' => 1, 'at_least' => false], $queue->json('total'));
    }

    /**
     * Rejects: a lease from the shortest retry_after of the queue connections
     * on one jobs table: a reservation 100 s old on the 600 s
     * connection `a` (queue `long`) is not expired although the 60 s
     * connection `b` shares its table; its worker is live by its lease. A
     * reservation 100 s old on `b` is expired, also in the list of one queue.
     */
    public function test_connections_on_one_jobs_table_keep_their_own_lease(): void
    {
        $this->app['config']->set('queue.connections.a', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'long', 'retry_after' => 600]);
        $this->app['config']->set('queue.connections.b', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 60]);
        $this->app['config']->set('queue.connections.database.table', 'other_jobs');
        $now = Clock::now();
        $this->addRow(1, 'reserved', 1, ['connection' => 'a', 'queue' => 'long', 'reserved_at' => Clock::toDecimal($now - 100), 'reserved_by' => 'host:1:1']);
        $this->addRow(2, 'reserved', 2, ['connection' => 'b', 'reserved_at' => Clock::toDecimal($now - 100), 'reserved_by' => 'host:1:1']);
        $this->flushRows();

        $response = $this->stuck(['signal' => 'reservation_expired']);
        self::assertSame([self::uuidOf(2)], array_column($response->json('jobs'), 'id'));
        self::assertSame(60, $response->json('jobs.0.signals.0.facts.retry_after'));
        self::assertSame([], $this->stuck(['signal' => 'reservation_expired', 'connection' => 'a', 'queue' => 'long'])->json('jobs'));
        self::assertSame([self::uuidOf(2)], array_column($this->stuck(['signal' => 'reservation_expired', 'connection' => 'b', 'queue' => 'default'])->json('jobs'), 'id'));

        $detail = $this->actingAs(new User)->getJson('/orrery/api/jobs/'.self::uuidOf(1))->assertOk();
        self::assertEqualsWithDelta($now + 500, $detail->json('lease_expires_at'), 0.01);
        self::assertSame('live', $detail->json('worker.liveness'));
        self::assertNotContains('reservation_expired', array_column($detail->json('signals'), 'signal'));
    }

    /**
     * Rejects: a lease guessed (Laravel's 60 s) for a row whose connection
     * is not a configured `database` queue connection: no expiry is
     * claimed, the list does not show it, and lease_expires_at is null.
     */
    public function test_a_connection_that_is_not_configured_has_an_unknown_lease(): void
    {
        $this->app['config']->set('queue.connections.database.retry_after', 300);
        $now = Clock::now();
        $this->addRow(1, 'reserved', 1, ['connection' => 'gone', 'reserved_at' => Clock::toDecimal($now - 100)]);
        $this->addRow(2, 'reserved', 2, ['connection' => 'gone', 'reserved_at' => Clock::toDecimal($now - 100_000)]);
        $this->flushRows();

        self::assertSame([], $this->stuck(['signal' => 'reservation_expired'])->json('jobs'));
        self::assertSame([], $this->stuck(['signal' => 'reservation_expired', 'connection' => 'gone', 'queue' => 'default'])->json('jobs'));

        $detail = $this->actingAs(new User)->getJson('/orrery/api/jobs/'.self::uuidOf(1))->assertOk();
        self::assertNotContains('reservation_expired', array_column($detail->json('signals'), 'signal'));
        self::assertNull($detail->json('lease_expires_at'));
        self::assertNull($this->actingAs(new User)->getJson('/orrery/api/jobs/'.self::uuidOf(2))->json('lease_expires_at'));
    }

    /**
     * Rejects: an expiry list that guesses a lease when no `database` queue
     * connection is configured: it is empty, never every reservation.
     */
    public function test_without_a_database_queue_connection_the_expiry_list_is_empty(): void
    {
        $this->addRow(1, 'reserved', 1, ['reserved_at' => Clock::toDecimal(Clock::now() - 100_000)]);
        $this->flushRows();
        $this->app['config']->set('queue.connections', ['sync' => ['driver' => 'sync']]);

        $reads = 0;
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$reads): void {
            // A pooled Postgres session names the relation with its schema ("public"."orrery_jobs").
            $reads += preg_match('/^select .* from (?:["`]?\\w+["`]?\\.)?["`]?\\w*orrery_jobs["`]? /i', $event->sql) === 1 ? 1 : 0;
        });

        $response = $this->stuck(['signal' => 'reservation_expired']);
        self::assertSame([], $response->json('jobs'));
        self::assertSame(['count' => 0, 'at_least' => false], $response->json('total'));
        self::assertSame(0, $reads, 'no lease is guessed, so no reservation is read');
    }

    /**
     * Rejects: a release list read from the attempts range (st_attempts
     * with attempts >= threshold), which misses a row whose releases
     * exceed its attempts (two lineages after a reclaim, row 6); rows whose
     * attempts reach the threshold but whose releases do not; another
     * order than the most releases first; a finished row; and a release
     * threshold that ignores the configuration.
     */
    public function test_repeated_releases_reads_the_releases_range(): void
    {
        $this->addRow(1, 'pending', 1, ['attempts' => 25, 'releases' => 20]);
        $this->addRow(2, 'reserved', 2, ['attempts' => 30, 'releases' => 5, 'reserved_at' => Clock::toDecimal(Clock::now())]);
        $this->addRow(3, 'reserved', 3, ['attempts' => 21, 'releases' => 21, 'reserved_at' => Clock::toDecimal(Clock::now())]);
        $this->addRow(4, 'failed', 4, ['attempts' => 40, 'releases' => 39]);
        $this->addRow(5, 'pending', 5, ['attempts' => 12, 'releases' => 12]);
        $this->addRow(6, 'pending', 6, ['attempts' => 15, 'releases' => 22]);
        $this->flushRows();

        $response = $this->stuck(['signal' => 'repeated_releases']);
        self::assertSame([self::uuidOf(6), self::uuidOf(3), self::uuidOf(1)], array_column($response->json('jobs'), 'id'));
        self::assertSame(['count' => 3, 'at_least' => false], $response->json('total'));
        self::assertContains(['signal' => 'repeated_releases', 'facts' => ['releases' => 22]], $response->json('jobs.0.signals'));

        $this->app['config']->set('orrery.stuck.release_threshold', 10);
        self::assertSame([self::uuidOf(6), self::uuidOf(3), self::uuidOf(1), self::uuidOf(5)], array_column($this->stuck(['signal' => 'repeated_releases'])->json('jobs'), 'id'));
    }

    /**
     * Rejects: "attempts not capped" for an attempt limit, an unknown limit
     * or a passed deadline, and a row with a future deadline left out.
     */
    public function test_attempts_not_capped_checks_the_enforced_rule(): void
    {
        $now = (int) Clock::now();
        $this->addRow(1, 'pending', 1, ['attempts' => 31, 'max_tries' => 0]);
        $this->addRow(2, 'pending', 2, ['attempts' => 31, 'max_tries' => 50]);
        $this->addRow(3, 'pending', 3, ['attempts' => 32, 'max_tries' => 2, 'retry_until' => $now + 3600]);
        $this->addRow(4, 'pending', 4, ['attempts' => 33, 'max_tries' => 100, 'retry_until' => $now - 60]);
        $this->addRow(5, 'pending', 5, ['attempts' => 34]);
        $this->flushRows();

        $response = $this->stuck(['signal' => 'attempts_not_capped']);
        self::assertSame([self::uuidOf(3), self::uuidOf(1)], array_column($response->json('jobs'), 'id'));
        self::assertSame(['attempts' => 32, 'rule' => 'deadline', 'deadline' => $now + 3600], $response->json('jobs.0.signals.0.facts'));
        self::assertSame('deadline', $response->json('jobs.0.enforced_rule.kind'));
        self::assertSame(2, $response->json('jobs.0.enforced_rule.tries'));
        self::assertSame(['attempts' => 31, 'rule' => 'none'], $response->json('jobs.1.signals.0.facts'));
    }

    /**
     * Rejects: a traversal of merged ranges that repeats or skips a row at
     * a page boundary (equal gaps across the pending and the running
     * range), a prev_cursor that does not lead back, and a cursor of another
     * signal or filter accepted.
     */
    public function test_cursors_traverse_the_merged_ranges_once(): void
    {
        $expected = [];

        for ($n = 1; $n <= 23; $n++) {
            $status = $n % 2 === 0 ? 'reserved' : 'pending';
            $gap = intdiv($n, 4) + 1;
            $this->addRow($n, $status, $n, ['observed_gap' => $gap, 'attempts' => $gap + 1] + ($status === 'reserved' ? ['reserved_at' => Clock::toDecimal(Clock::now())] : []));
            $expected[] = [$gap, $n];
        }

        $this->flushRows();
        rsort($expected);
        $expectedIds = array_map(static fn (array $key): string => self::uuidOf($key[1]), $expected);

        $seen = [];
        $pages = [];
        $cursor = null;

        do {
            $response = $this->stuck(['signal' => 'outcome_not_observed', 'per_page' => '5'] + ($cursor === null ? [] : ['cursor' => $cursor]));
            $pages[] = $response;
            $seen = [...$seen, ...array_column($response->json('jobs'), 'id')];
            $cursor = $response->json('next_cursor');
        } while ($cursor !== null && count($pages) < 10);

        self::assertSame($expectedIds, $seen);
        self::assertSame(['count' => 23, 'at_least' => false], $pages[0]->json('total'));

        $back = $this->stuck(['signal' => 'outcome_not_observed', 'per_page' => '5', 'cursor' => $pages[2]->json('prev_cursor')]);
        self::assertSame(array_column($pages[1]->json('jobs'), 'id'), array_column($back->json('jobs'), 'id'));

        $mismatch = $this->getStuck(['signal' => 'repeated_releases', 'cursor' => $pages[0]->json('next_cursor')]);
        $mismatch->assertStatus(422);
        self::assertSame('cursor_mismatch', $mismatch->json('error'));
        self::assertSame('cursor', $mismatch->json('field'));
    }

    /**
     * Rejects: a cursor with a null order value: it reached the keyset condition, which threw on
     * MySQL (a 503 and a host report per request). It answers 422 `invalid` on the field `cursor`.
     * Still passes: a check that refuses only a null order, not an order of another type.
     */
    public function test_a_null_order_cursor_is_refused(): void
    {
        for ($n = 1; $n <= 3; $n++) {
            $this->addRow($n, 'pending', $n, ['observed_gap' => 2, 'attempts' => 3]);
        }

        $this->flushRows();
        $cursor = $this->stuck(['signal' => 'outcome_not_observed', 'per_page' => '1'])->json('next_cursor');
        self::assertIsString($cursor);
        $data = json_decode((string) base64_decode(strtr($cursor, '-_', '+/')), true);
        self::assertIsArray($data);
        $data['k'][0] = null;
        $crafted = rtrim(strtr(base64_encode((string) json_encode($data, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');

        $response = $this->getStuck(['signal' => 'outcome_not_observed', 'per_page' => '1', 'cursor' => $crafted]);
        $response->assertStatus(422);
        self::assertSame('cursor', $response->json('field'));
    }

    /**
     * Rejects: filters compared by the collation (`HIGH` for `high`, a
     * trailing space dropped), a class filter that is ignored, and a queue
     * filter on another connection.
     */
    public function test_filters_are_byte_exact(): void
    {
        $this->addRow(1, 'pending', 1, ['observed_gap' => 1, 'queue' => 'high', 'name' => 'App\\Jobs\\A']);
        $this->addRow(2, 'pending', 2, ['observed_gap' => 1, 'queue' => 'HIGH', 'name' => 'App\\Jobs\\A']);
        $this->addRow(3, 'pending', 3, ['observed_gap' => 1, 'queue' => 'high ', 'name' => 'App\\Jobs\\B']);
        $this->addRow(4, 'pending', 4, ['observed_gap' => 1, 'queue' => 'high', 'connection' => 'other', 'name' => 'App\\Jobs\\A']);
        $this->flushRows();

        $ids = fn (array $query): array => array_column($this->stuck(['signal' => 'outcome_not_observed'] + $query)->json('jobs'), 'id');

        self::assertSame([self::uuidOf(1)], $ids(['connection' => 'database', 'queue' => 'high']));
        self::assertSame([self::uuidOf(3)], $ids(['connection' => 'database', 'queue' => 'high ']));
        self::assertSame([self::uuidOf(4)], $ids(['connection' => 'other']));
        self::assertSame([self::uuidOf(4), self::uuidOf(2), self::uuidOf(1)], $ids(['class' => 'App\\Jobs\\A']));
        self::assertSame(['count' => 1, 'at_least' => false], $this->stuck(['signal' => 'outcome_not_observed', 'class' => 'App\\Jobs\\B'])->json('total'));
    }

    /**
     * Rejects: a residual scan without the candidate budget, a budget end
     * without `search_limited` and a lower-bound total, and a next_cursor
     * that does not resume after the last examined row (the scan would
     * repeat or never reach the rest of the range).
     */
    public function test_a_residual_scan_stops_at_the_candidate_budget_and_resumes(): void
    {
        $this->app['config']->set('orrery.dashboard.search_candidates', 12);

        // Pending: attempts 30 down to 21, only the last one without an attempt limit. Running: attempts 40 down to 36.
        for ($n = 1; $n <= 10; $n++) {
            $this->addRow($n, 'pending', $n, ['attempts' => 31 - $n, 'max_tries' => $n === 10 ? 0 : 50]);
        }

        for ($n = 11; $n <= 15; $n++) {
            $this->addRow($n, 'reserved', $n, ['attempts' => 51 - $n, 'max_tries' => 50, 'reserved_at' => Clock::toDecimal(Clock::now())]);
        }

        $this->flushRows();

        $first = $this->stuck(['signal' => 'attempts_not_capped']);
        self::assertSame([], $first->json('jobs'));
        self::assertTrue($first->json('search_limited'));
        self::assertSame(['count' => 0, 'at_least' => true], $first->json('total'));
        self::assertNotNull($first->json('scanned_to'));

        $found = [];
        $cursor = $first->json('next_cursor');
        $requests = 1;

        while ($cursor !== null && $requests < 10) {
            $response = $this->stuck(['signal' => 'attempts_not_capped', 'cursor' => $cursor]);
            $found = [...$found, ...array_column($response->json('jobs'), 'id')];
            $cursor = $response->json('next_cursor');
            $requests++;
        }

        self::assertSame([self::uuidOf(10)], $found);
        self::assertLessThan(6, $requests, 'each request resumes after the rows that the one before examined');
    }

    /**
     * Rejects: a published configuration without the `stuck` block
     * that fails the list or reads a threshold of 0 (every
     * active row listed).
     */
    public function test_a_configuration_without_the_stuck_block_takes_the_defaults(): void
    {
        $this->app['config']->set('orrery', array_diff_key((array) $this->app['config']->get('orrery'), ['stuck' => true]));
        self::assertNull($this->app['config']->get('orrery.stuck'));
        $this->addRow(1, 'pending', 1, ['attempts' => 20, 'releases' => 20]);
        $this->addRow(2, 'pending', 2, ['attempts' => 19, 'releases' => 19]);
        $this->flushRows();

        self::assertSame([self::uuidOf(1)], array_column($this->stuck(['signal' => 'repeated_releases'])->json('jobs'), 'id'));
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function tinyBudgets(): array
    {
        return ['search_candidates 1' => [1], 'search_candidates 2' => [2]];
    }

    /**
     * Rejects: a residual scan over two ranges that stalls on a budget
     * smaller than its chunks (the first range takes the whole budget, the
     * page is empty and no cursor moves on), a traversal that repeats or
     * skips a row, and a `before` cursor that does not lead back over the
     * same rows.
     */
    #[DataProvider('tinyBudgets')]
    public function test_a_residual_list_over_two_ranges_progresses_on_a_tiny_budget(int $budget): void
    {
        $this->app['config']->set('orrery.dashboard.search_candidates', $budget);
        $expected = [];

        // attempts_not_capped: every third row has no attempt limit; pending and running rows interleave.
        for ($n = 1; $n <= 12; $n++) {
            $status = $n % 2 === 0 ? 'reserved' : 'pending';
            $this->addRow($n, $status, $n, ['attempts' => 20 + $n, 'max_tries' => $n % 3 === 0 ? 0 : 50] + ($status === 'reserved' ? ['reserved_at' => Clock::toDecimal(Clock::now())] : []));

            if ($n % 3 === 0) {
                $expected[] = self::uuidOf($n);
            }
        }

        $this->flushRows();
        $expected = array_reverse($expected);

        $seen = [];
        $cursors = [];
        $cursor = null;
        $requests = 0;

        do {
            $response = $this->stuck(['signal' => 'attempts_not_capped', 'per_page' => '1'] + ($cursor === null ? [] : ['cursor' => $cursor]));
            $seen = [...$seen, ...array_column($response->json('jobs'), 'id')];
            $cursors[] = $response->json('prev_cursor');
            $cursor = $response->json('next_cursor');
            $requests++;
        } while ($cursor !== null && $requests < 40);

        self::assertSame($expected, $seen, 'each row once, in order');
        self::assertLessThan(40, $requests, 'the traversal ends');

        // Back from the end: the before cursors lead over the same rows in reverse.
        $back = [];
        $cursor = $cursors[count($cursors) - 1];
        $requests = 0;

        while ($cursor !== null && $requests < 40) {
            $response = $this->stuck(['signal' => 'attempts_not_capped', 'per_page' => '1', 'cursor' => $cursor]);
            $back = [...array_column($response->json('jobs'), 'id'), ...$back];
            $cursor = $response->json('prev_cursor');
            $requests++;
        }

        self::assertLessThan(40, $requests, 'the way back ends');
        self::assertSame(array_slice($expected, 0, count($back)), $back);
        self::assertGreaterThanOrEqual(count($expected) - 1, count($back), 'the way back reaches the first rows');
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function invalidRequests(): array
    {
        return [
            'no signal' => [[], 'signal'],
            'an unknown signal' => [['signal' => 'worker_silent'], 'signal'],
            'a status instead of a signal' => [['signal' => 'unknown'], 'signal'],
            'per_page 0' => [['signal' => 'outcome_not_observed', 'per_page' => '0'], 'per_page'],
            'per_page 101' => [['signal' => 'outcome_not_observed', 'per_page' => '101'], 'per_page'],
            'a queue without a connection' => [['signal' => 'outcome_not_observed', 'queue' => 'default'], 'queue'],
            'a malformed cursor' => [['signal' => 'outcome_not_observed', 'cursor' => 'not a cursor'], 'cursor'],
        ];
    }

    /**
     * Rejects: an invalid request answered with a 200 or a 500, and an error
     * without its field.
     *
     * @param  array<string, string>  $query
     */
    #[DataProvider('invalidRequests')]
    public function test_an_invalid_request_answers_422_with_its_field(array $query, string $field): void
    {
        $response = $this->getStuck($query)->assertStatus(422);

        self::assertSame('invalid', $response->json('error'));
        self::assertSame($field, $response->json('field'));
        self::assertIsString($response->json('message'));
    }

    /**
     * Rejects: a gap or attempts list read without its deferred index (a
     * full scan on a request path), an index_missing answer for the expiry
     * list (its index is not deferred), and index_missing recorded as a
     * degradation on every request.
     */
    #[FreshSchema]
    public function test_a_missing_deferred_index_answers_index_missing(): void
    {
        $this->addRow(1, 'reserved', 1, ['reserved_at' => Clock::toDecimal(Clock::now() - 3600), 'observed_gap' => 1]);
        $this->flushRows();
        $mirror = $this->mirror();
        $mirror->getSchemaBuilder()->table('orrery_jobs', static function ($table) use ($mirror): void {
            $table->dropIndex(IndexName::for($mirror, 'orrery_jobs', 'st_gap'));
        });

        $response = $this->getStuck(['signal' => 'outcome_not_observed'])->assertStatus(503);
        self::assertSame('index_missing', $response->json('error'));
        self::assertSame(DeferredIndexes::COMMAND, $response->json('hint'));
        self::assertSame('st_gap', $response->json('index'));
        $recordedAt = Degradation::record()['errors'][DeferredIndexes::OPERATION] ?? null;
        self::assertNotNull($recordedAt, 'index_missing is recorded');
        Carbon::setTestNow(Carbon::now()->addSeconds(5));
        $this->getStuck(['signal' => 'outcome_not_observed'])->assertStatus(503);
        $this->getStuck(['signal' => 'attempts_not_capped'])->assertOk();
        self::assertSame($recordedAt, Degradation::record()['errors'][DeferredIndexes::OPERATION] ?? null, 'recorded once per process, not on every request');
        Carbon::setTestNow();

        self::assertSame([self::uuidOf(1)], array_column($this->stuck(['signal' => 'reservation_expired'])->json('jobs'), 'id'));
    }

    /**
     * Rejects: a long job inside its lease shown as stuck or with a silent
     * worker because its heartbeat is old (D-2): a job that runs for 3
     * minutes inside a 5-minute lease is neither stuck nor silent.
     */
    public function test_a_long_job_inside_its_lease_is_not_stuck_and_its_worker_is_live(): void
    {
        $this->app['config']->set('queue.connections.database.retry_after', 300);
        $now = Clock::now();
        $this->addRow(1, 'reserved', 1, ['reserved_at' => Clock::toDecimal($now - 180), 'reserved_by' => 'host:1:1', 'attempts' => 1]);
        $this->flushRows();
        $this->mirror()->table('orrery_worker_heartbeats')->insert([
            'worker_id' => 'host:1:1', 'host' => 'host', 'pid' => 1, 'started_at' => '1.000000', 'mode' => 'work', 'connection' => 'database',
            'subscribed_queues' => '["default"]', 'options' => '{}', 'last_seen_at' => Clock::toDecimal($now - 180),
        ]);

        foreach (['reservation_expired', 'outcome_not_observed', 'repeated_releases', 'attempts_not_capped'] as $signal) {
            self::assertSame([], $this->stuck(['signal' => $signal])->json('jobs'), $signal);
        }

        $running = $this->actingAs(new User)->getJson('/orrery/api/jobs?status=running')->assertOk();
        self::assertSame('live', $running->json('jobs.0.worker.liveness'));
        self::assertNull($running->json('jobs.0.worker.silent_since'));
        self::assertEqualsWithDelta($now + 120, $running->json('jobs.0.lease_expires_at'), 0.01);

        $detail = $this->actingAs(new User)->getJson('/orrery/api/jobs/'.self::uuidOf(1))->assertOk();
        self::assertSame([], $detail->json('signals'));
        self::assertSame('live', $detail->json('worker.liveness'));
        self::assertSame('unknown', $detail->json('enforced_rule.kind'));
    }

    /**
     * Rejects: a detail without the signals of an active row (null, "not
     * evaluated"), and signals on a finished row.
     */
    public function test_the_detail_evaluates_every_signal_of_an_active_row(): void
    {
        $this->app['config']->set('queue.connections.database.retry_after', 60);
        $now = Clock::now();
        $this->addRow(1, 'reserved', 1, ['reserved_at' => Clock::toDecimal($now - 120), 'observed_gap' => 2, 'attempts' => 22, 'releases' => 20, 'max_tries' => 0, 'last_outcome_attempt' => 21]);
        $this->addRow(2, 'completed', 2, ['observed_gap' => 2, 'attempts' => 22, 'releases' => 20, 'max_tries' => 0, 'last_outcome_attempt' => 22]);
        $this->flushRows();

        $detail = $this->actingAs(new User)->getJson('/orrery/api/jobs/'.self::uuidOf(1))->assertOk();
        self::assertSame(['reservation_expired', 'outcome_not_observed', 'repeated_releases', 'attempts_not_capped'], array_column($detail->json('signals'), 'signal'));
        self::assertSame(['attempts' => 22, 'rule' => 'none'], $detail->json('signals.3.facts'));
        self::assertSame('none', $detail->json('enforced_rule.kind'));

        $finished = $this->actingAs(new User)->getJson('/orrery/api/jobs/'.self::uuidOf(2))->assertOk()->json();
        self::assertArrayNotHasKey('signals', $finished);
        self::assertArrayNotHasKey('enforced_rule', $finished);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function stuck(array $query): TestResponse
    {
        return $this->getStuck($query)->assertOk();
    }

    /**
     * @param  array<string, string>  $query
     */
    private function getStuck(array $query): TestResponse
    {
        return $this->actingAs(new User)->getJson('/orrery/api/jobs/stuck?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }
}
