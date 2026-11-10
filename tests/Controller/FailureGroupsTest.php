<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Justrau\Orrery\Failures\FailureScan;
use Justrau\Orrery\Failures\GroupKey;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET /api/failures/groups and GET /api/failures/groups/{key}:
 * the bounded scan, the grouping, the order and pages, the buckets,
 * the scope validation, and the member projection.
 *
 * The rows of these tests carry the stored failure_key that JobStore
 * writes with every failure; FailureGroupsKeyedTest
 * covers the per-key reads in detail.
 */
final class FailureGroupsTest extends ControllerTest
{
    private const NOW = 1_789_999_200.0;

    private const HOUR = 3600;

    private int $rows = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromFormat('U.u', '1789999200.000000'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: a group key without the job name, or a list that is not grouped.
     */
    public function test_rows_with_one_key_form_one_group_with_its_counts_and_times(): void
    {
        $this->failed(['failed_at' => self::NOW - 100, 'exception_message' => "Boom\nline 2"]);
        $this->failed(['failed_at' => self::NOW - 50, 'exception_message' => "Boom\r\nother line 2"]);
        $this->failed(['failed_at' => self::NOW - 70, 'exception_message' => 'Boom', 'name' => 'App\\Jobs\\Other']);

        $response = $this->listGroups(['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk();

        self::assertSame(2, $response->json('total'));
        $first = $response->json('groups.0');
        self::assertSame(GroupKey::of('RuntimeException', 'Boom', 'App\\Jobs\\Sync'), $first['key']);
        self::assertSame(['RuntimeException', 'Boom', 'App\\Jobs\\Sync', ['database:default'], 2], [$first['exception_class'], $first['message'], $first['job'], $first['queues'], $first['count']]);
        self::assertEquals([self::NOW - 100, self::NOW - 50], [$first['first_failed_at'], $first['last_failed_at']]);
        self::assertSame('App\\Jobs\\Other', $response->json('groups.1.job'));
    }

    /**
     * Rejects: a scan that ignores the window edges or the queue filter.
     */
    public function test_failures_outside_the_window_or_the_queue_are_not_counted(): void
    {
        $this->failed(['failed_at' => self::NOW - self::HOUR - 0.000001]);
        $this->failed(['failed_at' => self::NOW - self::HOUR]);
        $this->failed(['failed_at' => self::NOW - 1]);
        $this->failed(['failed_at' => self::NOW]);
        $this->failed(['failed_at' => self::NOW - 10, 'queue' => 'other']);
        $this->failed(['failed_at' => self::NOW - 10, 'connection' => 'second']);
        $this->failed(['failed_at' => self::NOW - 10, 'status' => 'completed']);

        $all = $this->listGroups(['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk();
        self::assertSame(4, $all->json('scanned'), 'from is inclusive, to is exclusive, and only failed rows count');
        self::assertSame(['database:default', 'database:other', 'second:default'], $all->json('groups.0.queues'));

        $one = $this->listGroups(['from' => self::NOW - self::HOUR, 'to' => self::NOW, 'queue' => 'database:default'])->assertOk();
        self::assertSame(2, $one->json('groups.0.count'));
        self::assertSame(['database:default'], $one->json('groups.0.queues'));

        $other = $this->listGroups(['from' => self::NOW - self::HOUR, 'to' => self::NOW, 'queue' => 'second:default'])->assertOk();
        self::assertSame(1, $other->json('groups.0.count'));
    }

    /**
     * Rejects: another order, a missing key tie-breaker, or another page size.
     */
    public function test_groups_are_ordered_by_last_failure_then_key_in_pages_of_50(): void
    {
        for ($group = 0; $group < 55; $group++) {
            // Pairs of groups share their last failure time, so the key decides their order.
            $this->failed(['failed_at' => self::NOW - 100 - intdiv($group, 2), 'exception_message' => "Message {$group}"]);
        }

        $first = $this->listGroups(['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk();
        $second = $this->listGroups(['from' => self::NOW - self::HOUR, 'to' => self::NOW, 'offset' => '50'])->assertOk();

        self::assertSame([55, 50, 0], [$first->json('total'), $first->json('per_page'), $first->json('offset')]);
        self::assertCount(50, $first->json('groups'));
        self::assertCount(5, $second->json('groups'));

        $listed = array_map(static fn (array $group): array => [$group['last_failed_at'], $group['key']], array_merge($first->json('groups'), $second->json('groups')));
        $expected = $listed;
        usort($expected, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        self::assertSame($expected, $listed);
        self::assertCount(55, array_unique(array_column($listed, 1)));
    }

    /**
     * Rejects: a scan without the cap, a cap without the flag, or a flag without more rows.
     */
    public function test_the_scan_stops_at_5000_rows_and_says_truncated(): void
    {
        $this->bulkFailed(FailureScan::CAP, self::NOW - 1000);

        $exact = $this->listGroups(['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk();
        self::assertSame([false, FailureScan::CAP, FailureScan::CAP], [$exact->json('truncated'), $exact->json('scanned'), $exact->json('groups.0.count')]);

        // One older row: the newest 5,000 are scanned, and the count is "at least".
        $this->failed(['failed_at' => self::NOW - 2000]);

        $over = $this->listGroups(['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk();
        self::assertSame([true, FailureScan::CAP, FailureScan::CAP], [$over->json('truncated'), $over->json('scanned'), $over->json('groups.0.count')]);
        self::assertEquals(self::NOW - 2000, $over->json('groups.0.first_failed_at'), 'the per-key read finds the true start beyond the scan cap');
        self::assertNull($exact->json('scanned_from'));
        self::assertEquals(self::NOW - 1000, $over->json('scanned_from'));

        // Older buckets are unknown: absent, never zero. The bucket of scanned_from is partial.
        $buckets = $over->json('groups.0.buckets');
        $holding = (int) (floor((self::NOW - 1000) / 60) * 60);
        self::assertSame(['at' => $holding, 'count' => FailureScan::CAP, 'partial' => true], $buckets[0]);
        self::assertCount(((int) self::NOW - 60 - $holding) / 60 + 1, $buckets);
        self::assertFalse($buckets[1]['partial']);

        $detail = $this->detail($over->json('groups.0.key'), ['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk();
        self::assertSame([true, FailureScan::CAP], [$detail->json('truncated'), $detail->json('total')]);
    }

    /**
     * Rejects: a scan that reads every failed row into PHP, or that reads the payload or the trace.
     */
    public function test_the_scan_is_one_bounded_query_without_the_payload_or_the_trace(): void
    {
        $this->failed(['failed_at' => self::NOW - 10]);
        $statements = [];
        // Every connection: the scan runs on the package session, which can be a clone of the mirror connection.
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $this->listGroups(['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk();

        $reads = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'orrery_jobs')));
        // One bounded head scan of the keyed rows.
        $scans = array_values(array_filter($reads, static fn (string $sql): bool => str_contains($sql, 'limit 5001') && ! str_contains($sql, 'count(*)')));
        self::assertCount(1, $scans, 'the keyed head scan');

        foreach ($reads as $sql) {
            self::assertStringNotContainsString('select *', $sql);
            self::assertStringNotContainsString('payload', $sql);
            self::assertStringNotContainsString('"exception"', str_replace('`', '"', $sql));
        }
    }

    /**
     * Rejects: a queue filter that only the column collation decides.
     */
    public function test_the_queue_scope_compares_bytes_exactly(): void
    {
        $this->failed(['failed_at' => self::NOW - 10, 'queue' => 'default']);
        $this->failed(['failed_at' => self::NOW - 20, 'queue' => 'Default']);
        $this->failed(['failed_at' => self::NOW - 30, 'queue' => 'défault']);

        $response = $this->listGroups(['from' => self::NOW - self::HOUR, 'to' => self::NOW, 'queue' => 'database:default'])->assertOk();

        self::assertSame([1, ['database:default']], [$response->json('groups.0.count'), $response->json('groups.0.queues')]);
    }

    /**
     * Rejects: a scope read from the parsed query, which the host's TrimStrings changes: `database:high ` read as
     * `database:high`, so the groups of another queue answered. The list and the detail read the raw query.
     */
    public function test_the_queue_scope_is_read_past_trim_strings(): void
    {
        $this->failed(['failed_at' => self::NOW - 10, 'queue' => 'high']);
        $this->failed(['failed_at' => self::NOW - 20, 'queue' => 'high ']);
        $this->failed(['failed_at' => self::NOW - 30, 'queue' => 'high ']);
        $scope = ['from' => self::NOW - self::HOUR, 'to' => self::NOW, 'queue' => 'database:high '];

        $response = $this->listGroups($scope)->assertOk();
        self::assertSame([2, ['database:high ']], [$response->json('groups.0.count'), $response->json('groups.0.queues')]);
        self::assertSame('database:high ', $response->json('scope.queue'));

        $detail = $this->detail(GroupKey::of('RuntimeException', 'Boom', 'App\\Jobs\\Sync'), $scope)->assertOk();
        self::assertCount(2, $detail->json('selection'));
    }

    /**
     * Rejects: a detail whose operation selection follows the display page, or has no cap.
     */
    public function test_the_selection_is_the_newest_200_pairs_whatever_the_offset(): void
    {
        $this->bulkFailed(205, self::NOW - 100);
        $key = GroupKey::of('RuntimeException', 'Boom', 'App\\Jobs\\Sync');

        $first = $this->detail($key, ['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk();
        $later = $this->detail($key, ['from' => self::NOW - self::HOUR, 'to' => self::NOW, 'offset' => '150'])->assertOk();

        self::assertCount(200, $first->json('selection'));
        self::assertSame(205, $first->json('selection_total'));
        self::assertSame($first->json('selection'), $later->json('selection'));
        self::assertCount(50, $first->json('jobs'));
        self::assertCount(50, $later->json('jobs'));
        self::assertSame(array_column($first->json('jobs'), 'uuid'), array_column(array_slice($first->json('selection'), 0, 50), 'uuid'), 'the newest first');
        self::assertSame(['uuid', 'occurrence_id', 'failed_at'], array_keys($first->json('selection.0')));
        self::assertEquals(self::NOW - 100, $first->json('selection.0.failed_at'));
    }

    /**
     * Rejects: a bucket cut by `from` that is not partial.
     */
    public function test_a_bucket_cut_by_from_is_partial(): void
    {
        $this->failed(['failed_at' => self::NOW - 3000]);

        $buckets = $this->listGroups(['from' => self::NOW - self::HOUR + 30, 'to' => self::NOW])->assertOk()->json('groups.0.buckets');

        self::assertSame([(int) self::NOW - self::HOUR, true], [$buckets[0]['at'], $buckets[0]['partial']]);
        self::assertFalse($buckets[1]['partial']);
        self::assertCount(60, $buckets);
    }

    /**
     * Rejects: a bucket that holds the retention horizon dropped, or kept as complete.
     */
    public function test_the_bucket_holding_the_retention_horizon_is_kept_as_partial(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('U.u', '1789999220.000000'));
        $this->app['config']->set('orrery.trim.failed', 30);
        $this->failed(['failed_at' => self::NOW - 10]);

        $response = $this->listGroups(['from' => self::NOW - self::HOUR + 20, 'to' => self::NOW + 20])->assertOk();

        self::assertEquals(self::NOW + 20 - 1800, $response->json('retention_from'));
        $buckets = $response->json('groups.0.buckets');
        self::assertSame([(int) self::NOW - 1800, true], [$buckets[0]['at'], $buckets[0]['partial']], 'the horizon is inside this bucket');
        self::assertFalse($buckets[1]['partial']);
        self::assertSame([(int) self::NOW, true], [end($buckets)['at'], end($buckets)['partial']]);
    }

    /**
     * @return array<string, array{0: mixed, 1: int|null}>
     */
    public static function trimValues(): array
    {
        return [
            'int' => [60, 60],
            'digit string' => ['60', 60],
            'zero' => [0, 0],
            'float' => [60.5, null],
            'float string' => ['60.5', null],
            'negative' => [-1, null],
            'text' => ['week', null],
            'null' => [null, null],
        ];
    }

    /**
     * Rejects: a retention horizon from a value that the retention itself rejects.
     */
    #[DataProvider('trimValues')]
    public function test_retention_from_follows_the_retention_policy_rule(mixed $minutes, ?int $expected): void
    {
        $this->app['config']->set('orrery.trim.failed', $minutes);

        $response = $this->listGroups(['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk();

        self::assertEquals($expected === null ? null : self::NOW - $expected * 60, $response->json('retention_from'));
    }

    /**
     * Rejects: patterns that accept a trailing newline ($ without D).
     */
    public function test_values_with_a_trailing_newline_are_invalid(): void
    {
        TrimStrings::skipWhen(static fn (): bool => true);

        try {
            $this->listGroups(['from' => ((string) (int) (self::NOW - 60))."\n", 'to' => (string) (int) self::NOW])->assertStatus(422);
            $this->listGroups(['from' => (string) (int) (self::NOW - 60), 'to' => (string) (int) self::NOW, 'offset' => "1\n"])->assertStatus(422);
            $this->detail(str_repeat('a', 64).'%0A', ['from' => self::NOW - 60, 'to' => self::NOW])->assertStatus(422);
        } finally {
            TrimStrings::flushState();
        }

        self::assertFalse(GroupKey::valid(str_repeat('a', 64)."\n"));
    }

    /**
     * Rejects: a key from the cut head of a long first line.
     */
    public function test_a_first_line_longer_than_the_head_keeps_its_whole_text_in_the_key(): void
    {
        $prefix = str_repeat('é', FailureScan::HEAD_CHARACTERS + 10);
        $this->failed(['failed_at' => self::NOW - 10, 'exception_message' => $prefix.'A']);
        $this->failed(['failed_at' => self::NOW - 20, 'exception_message' => $prefix."B\nsecond line"]);

        $response = $this->listGroups(['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk();

        self::assertSame(2, $response->json('total'));
        self::assertSame(
            [GroupKey::of('RuntimeException', $prefix.'A', 'App\\Jobs\\Sync'), GroupKey::of('RuntimeException', $prefix.'B', 'App\\Jobs\\Sync')],
            [$response->json('groups.0.key'), $response->json('groups.1.key')],
        );
        self::assertSame(FailureScan::MESSAGE_CHARACTERS, mb_strlen((string) $response->json('groups.0.message')), 'the display message is cut');
    }

    /**
     * Rejects: hour buckets for a short window, buckets outside the window, or zeros before the retention horizon.
     */
    public function test_buckets_are_per_minute_up_to_one_hour_and_per_hour_above(): void
    {
        $this->failed(['failed_at' => self::NOW - 30]);
        $this->failed(['failed_at' => self::NOW - 90]);
        $this->failed(['failed_at' => self::NOW - 95]);

        $minute = $this->listGroups(['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk();
        self::assertSame([60, 'per minute'], [$minute->json('bucket_seconds'), $minute->json('bucket_label')]);
        $buckets = $minute->json('groups.0.buckets');
        self::assertCount(60, $buckets, 'one bucket per minute of the window: to = as_of is on a boundary');
        self::assertSame(['at' => (int) self::NOW - 60, 'count' => 1, 'partial' => false], $buckets[59]);
        self::assertSame(['at' => (int) self::NOW - 120, 'count' => 2, 'partial' => false], $buckets[58]);
        self::assertSame(3, array_sum(array_column($buckets, 'count')));

        $hour = $this->listGroups(['from' => self::NOW - 24 * self::HOUR, 'to' => self::NOW + 1])->assertOk();
        self::assertSame([3600, 'per hour'], [$hour->json('bucket_seconds'), $hour->json('bucket_label')]);
        $hourly = $hour->json('groups.0.buckets');
        self::assertCount(25, $hourly, 'the 24 hours before now and the current hour');
        self::assertSame(['at' => (int) self::NOW - 3600, 'count' => 3, 'partial' => false], $hourly[23]);
        self::assertSame(['at' => (int) self::NOW, 'count' => 0, 'partial' => true], $hourly[24], 'the current bucket is partial');

        // A retention of 30 minutes: the older buckets are absent, not zero.
        $this->app['config']->set('orrery.trim.failed', 30);
        $short = $this->listGroups(['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk();
        self::assertEquals(self::NOW - 1800, $short->json('retention_from'));
        self::assertCount(30, $short->json('groups.0.buckets'));
        self::assertSame((int) self::NOW - 1800, $short->json('groups.0.buckets.0.at'));
    }

    /**
     * Rejects: a retention floor check, or a response without retention_from.
     */
    public function test_a_seven_day_window_older_than_the_retention_is_valid_and_reports_the_horizon(): void
    {
        $this->app['config']->set('orrery.trim.failed', 60);
        $this->failed(['failed_at' => self::NOW - 7 * 86400 + 10]);

        $response = $this->listGroups(['from' => self::NOW - 7 * 86400, 'to' => self::NOW])->assertOk();

        self::assertEquals(self::NOW - 3600, $response->json('retention_from'));
        self::assertEquals(self::NOW, $response->json('as_of'));
        self::assertSame(1, $response->json('total'));

        $detail = $this->detail($response->json('groups.0.key'), ['from' => self::NOW - 7 * 86400, 'to' => self::NOW])->assertOk();
        self::assertEquals(self::NOW - 3600, $detail->json('retention_from'));
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function invalidScopes(): array
    {
        $now = (string) (int) self::NOW;

        return [
            'no from' => [['to' => $now]],
            'no to' => [['from' => $now]],
            'from equals to' => [['from' => $now, 'to' => $now]],
            'from after to' => [['from' => $now, 'to' => (string) ((int) $now - 1)]],
            'longer than 7 days' => [['from' => (string) ((int) $now - 604801), 'to' => $now]],
            'not a number' => [['from' => 'yesterday', 'to' => $now]],
            'negative' => [['from' => '-1', 'to' => $now]],
            'exponent' => [['from' => '1e9', 'to' => $now]],
            'queue without connection' => [['from' => (string) ((int) $now - 60), 'to' => $now, 'queue' => 'default']],
            'queue with empty part' => [['from' => (string) ((int) $now - 60), 'to' => $now, 'queue' => 'database:']],
            // The keyed reads write the queue into the SQL as an escaped literal, which cannot hold a NUL byte.
            'queue with a NUL byte' => [['from' => (string) ((int) $now - 60), 'to' => $now, 'queue' => "database:de\0fault"]],
            'queue with a backslash' => [['from' => (string) ((int) $now - 60), 'to' => $now, 'queue' => 'database:de\\fault']],
            'queue not UTF-8' => [['from' => (string) ((int) $now - 60), 'to' => $now, 'queue' => "database:d\xFFefault"]],
            'bad offset' => [['from' => (string) ((int) $now - 60), 'to' => $now, 'offset' => '-1']],
        ];
    }

    /**
     * Rejects: a scope that accepts an invalid value, or an answer other than 422.
     *
     * @param  array<string, string>  $query
     */
    #[DataProvider('invalidScopes')]
    public function test_an_invalid_scope_gives_422(array $query): void
    {
        $this->listGroups($query)->assertStatus(422)->assertJsonPath('error', 'invalid_request');
        $this->detail(str_repeat('a', 64), $query)->assertStatus(422)->assertJsonPath('error', 'invalid_request');
    }

    public function test_exactly_seven_days_is_valid(): void
    {
        $this->listGroups(['from' => self::NOW - 604800, 'to' => self::NOW])->assertOk();
    }

    public function test_an_invalid_key_gives_422(): void
    {
        $this->detail(str_repeat('A', 64), ['from' => self::NOW - 60, 'to' => self::NOW])->assertStatus(422);
        $this->detail('abc', ['from' => self::NOW - 60, 'to' => self::NOW])->assertStatus(422);
    }

    /**
     * Rejects: a member list with the payload, the trace or the operation hold, or without the pair fields.
     */
    public function test_the_detail_lists_members_with_a_safe_projection(): void
    {
        $older = $this->failed(['failed_at' => self::NOW - 200, 'attempts' => 1, 'exception' => 'RuntimeException: Boom in older.php']);
        $newer = $this->failed(['failed_at' => self::NOW - 100, 'attempts' => 3, 'exception' => "RuntimeException: Boom\n#0 newer.php", 'operation_id' => str_repeat('b', 32)]);
        $this->mirror()->table('orrery_job_tags')->insert([
            ['tag' => 'user:1', 'job_uuid' => $newer['uuid'], 'pushed_at' => Clock::toDecimal(self::NOW - 300)],
            ['tag' => 'account:2', 'job_uuid' => $newer['uuid'], 'pushed_at' => Clock::toDecimal(self::NOW - 300)],
        ]);
        $key = GroupKey::of('RuntimeException', 'Boom', 'App\\Jobs\\Sync');

        $response = $this->detail($key, ['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk();

        self::assertSame(['min' => 1, 'max' => 3, 'of_newest' => null], $response->json('attempts'));
        self::assertSame("RuntimeException: Boom\n#0 newer.php", $response->json('latest_trace'));
        self::assertSame([false, 2, 2], [$response->json('truncated'), $response->json('total'), $response->json('group.count')]);
        self::assertSame([$newer['uuid'], $older['uuid']], array_column($response->json('jobs'), 'uuid'));
        self::assertSame([
            'uuid' => $newer['uuid'],
            'occurrence_id' => $newer['occurrence_id'],
            'name' => 'App\\Jobs\\Sync',
            'connection' => 'database',
            'queue' => 'default',
            'pushed_at' => self::NOW - 300,
            'failed_at' => self::NOW - 100,
            'attempts' => 3,
            'tags' => ['account:2', 'user:1'],
        ], $response->json('jobs.0'));
        self::assertStringNotContainsString(str_repeat('b', 32), (string) $response->getContent());
        self::assertStringNotContainsString('payload', (string) $response->getContent());
    }

    /**
     * Rejects: member pages without the stable newest-first order, or another page size.
     */
    public function test_detail_members_page_by_50_newest_first_with_the_id_tie_breaker(): void
    {
        $uuids = [];

        for ($row = 0; $row < 55; $row++) {
            // Pairs of rows share their failure time; the higher id comes first.
            $uuids[] = $this->failed(['failed_at' => self::NOW - 100 - intdiv($row, 2)])['uuid'];
        }

        $key = GroupKey::of('RuntimeException', 'Boom', 'App\\Jobs\\Sync');
        $first = $this->detail($key, ['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk();
        $second = $this->detail($key, ['from' => self::NOW - self::HOUR, 'to' => self::NOW, 'offset' => '50'])->assertOk();

        $expected = [];

        for ($row = 0; $row < 55; $row += 2) {
            array_push($expected, ...array_reverse(array_slice($uuids, $row, 2)));
        }

        self::assertSame(55, $first->json('total'));
        self::assertSame($expected, array_merge(array_column($first->json('jobs'), 'uuid'), array_column($second->json('jobs'), 'uuid')));
        self::assertCount(50, $first->json('jobs'));
    }

    /**
     * Rejects: a 404 or an error for a key without members in the scope.
     */
    public function test_a_key_without_members_in_scope_gives_an_empty_detail(): void
    {
        $response = $this->detail(str_repeat('c', 64), ['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk();

        self::assertSame([null, null, null, 0, []], [$response->json('group'), $response->json('attempts'), $response->json('latest_trace'), $response->json('total'), $response->json('jobs')]);
    }

    /**
     * @param  array<string, mixed>  $columns
     * @return array{uuid: string, occurrence_id: int}
     */
    private function failed(array $columns = []): array
    {
        $row = $this->failedColumns($columns);
        $this->mirror()->table('orrery_jobs')->insert($row);

        return ['uuid' => $row['uuid'], 'occurrence_id' => $row['occurrence_id']];
    }

    /**
     * @param  array<string, mixed>  $columns
     * @return array<string, mixed>
     */
    private function failedColumns(array $columns = []): array
    {
        $this->rows++;
        $uuid = sprintf('00000000-0000-4000-8000-%012d', $this->rows);
        $failedAt = (float) ($columns['failed_at'] ?? self::NOW - 10);
        unset($columns['failed_at']);

        $row = $columns + [
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'name' => 'App\\Jobs\\Sync',
            'type' => 'job',
            'status' => 'failed',
            'payload' => '{"uuid":"'.$uuid.'","secret":"payload"}',
            'payload_bytes' => 40,
            'occurrence_id' => 1000 + $this->rows,
            'exception_class' => 'RuntimeException',
            'exception_message' => 'Boom',
            'exception' => 'RuntimeException: Boom',
            'attempts' => 1,
            'pushed_at' => Clock::toDecimal(self::NOW - 300),
            'finished_at' => Clock::toDecimal($failedAt),
            'failed_at' => Clock::toDecimal($failedAt),
            'updated_at' => Clock::toDecimal($failedAt),
        ];

        // The stored key and the trace's byte count, as JobStore writes them with every failure.
        return $row + [
            'failure_key' => GroupKey::of($row['exception_class'], $row['exception_message'], $row['name']),
            'exception_bytes' => is_string($row['exception']) ? strlen($row['exception']) : null,
        ];
    }

    private function bulkFailed(int $count, float $failedAt): void
    {
        $rows = [];

        for ($row = 0; $row < $count; $row++) {
            $this->rows++;
            $rows[] = [
                'uuid' => sprintf('00000000-0000-4000-8000-%012d', $this->rows),
                'connection' => 'database', 'queue' => 'default', 'name' => 'App\\Jobs\\Sync', 'type' => 'job', 'status' => 'failed',
                'payload' => '{}', 'payload_bytes' => 2, 'occurrence_id' => 1000 + $this->rows,
                'exception_class' => 'RuntimeException', 'exception_message' => 'Boom', 'attempts' => 1,
                'failure_key' => GroupKey::of('RuntimeException', 'Boom', 'App\\Jobs\\Sync'),
                'pushed_at' => Clock::toDecimal($failedAt), 'failed_at' => Clock::toDecimal($failedAt), 'updated_at' => Clock::toDecimal($failedAt),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $this->mirror()->table('orrery_jobs')->insert($chunk);
        }
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function listGroups(array $query): TestResponse
    {
        return $this->actingAs(new User)->getJson('/orrery/api/failures/groups?'.http_build_query(self::strings($query)));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function detail(string $key, array $query): TestResponse
    {
        return $this->actingAs(new User)->getJson('/orrery/api/failures/groups/'.$key.'?'.http_build_query(self::strings($query)));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, string>
     */
    private static function strings(array $query): array
    {
        return array_map(static fn (mixed $value): string => is_float($value) ? sprintf('%.6F', $value) : (string) $value, $query);
    }
}
