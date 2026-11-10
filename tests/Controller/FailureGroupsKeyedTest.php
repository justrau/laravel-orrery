<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Justrau\Orrery\Failures\GroupKey;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;

/**
 * The failure groups through the stored key: the true
 * start, the detail of a group older than the scan cap, the per-key bound,
 * the queues, the rows without a key, and the byte-exact scope.
 */
final class FailureGroupsKeyedTest extends ControllerTest
{
    private const NOW = 1_789_999_200.0;

    private const HOUR = 3600;

    private const NAME = 'App\\Jobs\\Sync';

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
     * The scenario: a burst of 6,000 failures over 600 s.
     *
     * Rejects: first_failed_at as the minimum over the scanned rows.
     */
    public function test_list_first_failed_at_is_the_true_start(): void
    {
        $start = self::NOW - 600;
        $this->insertMany(6000, static fn (int $i): array => ['failed_at' => $start + $i * 0.1]);

        $response = $this->listGroups(['from' => self::NOW - self::HOUR, 'to' => self::NOW + 1])->assertOk();

        self::assertSame([true, 1], [$response->json('truncated'), $response->json('total')]);
        $group = $response->json('groups.0');
        self::assertEquals($start, $group['first_failed_at'], 'the true start, not the oldest scanned row');
        self::assertEqualsWithDelta($start + 599.9, $group['last_failed_at'], 0.00001);
        self::assertSame([5000, true, false, false], [$group['count'], $group['count_capped'], $group['exact'], $group['queues_complete']]);
        self::assertSame(['RuntimeException', 'Boom', self::NAME, ['database:default']], [$group['exception_class'], $group['message'], $group['job'], $group['queues']]);
    }

    /**
     * 6,000 failures of A, then 5,001 newer failures of B: A is beyond the
     * head scan of the list, and its detail still finds it.
     *
     * Rejects: a detail that scans first and filters by key afterwards.
     */
    public function test_detail_finds_a_group_older_than_the_scan_cap(): void
    {
        $this->insertMany(6000, static fn (int $i): array => ['failed_at' => self::NOW - 3000 + $i * 0.1]);
        $this->insertMany(5001, static fn (int $i): array => ['failed_at' => self::NOW - 1000 + $i * 0.1, 'exception_message' => 'Other']);
        $a = self::key('Boom');

        $list = $this->listGroups(['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk();
        self::assertSame([true, [self::key('Other')]], [$list->json('truncated'), array_column($list->json('groups'), 'key')], 'A is older than the head scan');

        $detail = $this->detail($a, ['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk();

        self::assertTrue($detail->json('complete'));
        self::assertNotNull($detail->json('group'));
        self::assertSame([5000, true, false], [$detail->json('group.count'), $detail->json('group.count_capped'), $detail->json('group.exact')]);
        self::assertEquals(self::NOW - 3000, $detail->json('group.first_failed_at'), 'the true start');
        self::assertEqualsWithDelta(self::NOW - 3000 + 599.9, $detail->json('group.last_failed_at'), 0.00001);
        self::assertSame([5000, true, true], [$detail->json('total'), $detail->json('count_capped'), $detail->json('truncated')]);
        self::assertCount(200, $detail->json('selection'));
        self::assertEqualsWithDelta(self::NOW - 3000 + 599.9, $detail->json('selection.0.failed_at'), 0.00001, 'the newest of A');
        self::assertCount(50, $detail->json('jobs'));
    }

    /**
     * The cap is configured to 50, which cap() raises to its floor of 200
     * (the pairs of one selection): 210 failures, one per 8 s, so the newest
     * 200 are counted and the oldest 10 are beyond the cap.
     *
     * Rejects: an unbounded GROUP BY or COUNT over the key, buckets that
     * count rows beyond the cap, a member offset beyond the cap, and a cap
     * below the selection size.
     */
    public function test_per_key_reads_are_bounded(): void
    {
        $this->app['config']->set('orrery.dashboard.group_count_cap', 50);
        $this->insertMany(210, static fn (int $i): array => ['failed_at' => self::NOW - 1800 + $i * 8, 'attempts' => $i < 10 ? 9 : 2]);
        $statements = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $group = $this->listGroups(['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk()->json('groups.0');

        self::assertSame([200, true, false], [$group['count'], $group['count_capped'], $group['exact']]);
        self::assertEquals(self::NOW - 1800, $group['first_failed_at'], 'the start is exact');
        $keyed = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, '"failure_key" = ') || str_contains($sql, '`failure_key` = ')));
        $counts = array_values(array_filter($keyed, static fn (string $sql): bool => str_contains($sql, 'as "total"') || str_contains($sql, 'as `total`')));
        $buckets = array_values(array_filter($keyed, static fn (string $sql): bool => str_contains($sql, 'as bucket')));
        $from = array_values(array_filter($keyed, static fn (string $sql): bool => str_contains($sql, 'as "counted_from"') || str_contains($sql, 'as `counted_from`')));
        self::assertSame([1, 1, 1], [count($counts), count($buckets), count($from)], 'one statement of figures, one of buckets, one of the capped start');
        self::assertStringContainsString('limit 201', $counts[0], 'the count reads the newest cap + 1 ids');
        self::assertStringContainsString('limit 200', $buckets[0], 'the buckets count the newest cap rows');
        self::assertStringContainsString('limit 200', $from[0]);

        foreach ($keyed as $sql) {
            self::assertStringNotContainsString('limit 51', $sql, 'the configured 50 is raised to 200');
            self::assertStringNotContainsString('limit 5001', $sql, 'no per-key read uses the default cap');
            self::assertStringNotContainsString('group by "failure_key"', $sql, 'no unbounded group by key');
        }

        // The counted rows start at NOW - 1800 + 10 * 8 = NOW - 1720, in the bucket of NOW - 1740 (rows 8..14, of which
        // 10..14 are counted): older buckets are unknown, and that bucket is partial.
        $buckets = $group['buckets'];
        self::assertSame(['at' => (int) self::NOW - 1740, 'count' => 5, 'partial' => true], $buckets[0]);
        self::assertSame(200, array_sum(array_column($buckets, 'count')));

        $detail = $this->detail($group['key'], ['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk();
        self::assertSame(['min' => 2, 'max' => 2, 'of_newest' => 200], $detail->json('attempts'), 'the attempts of the counted rows');
        $this->detail($group['key'], ['from' => self::NOW - self::HOUR, 'to' => self::NOW, 'offset' => '199'])->assertOk();
        $this->detail($group['key'], ['from' => self::NOW - self::HOUR, 'to' => self::NOW, 'offset' => '200'])->assertStatus(422)->assertJsonPath('error', 'invalid_request');
    }

    /**
     * Rejects: a start statement for a key whose count is not capped.
     */
    public function test_counted_from_is_read_only_for_capped_keys(): void
    {
        $this->insert(['failed_at' => self::NOW - 10]);
        $statements = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $this->listGroups(['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk()->assertJsonPath('groups.0.count_capped', false);

        self::assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'counted_from'))));
    }

    /**
     * Rejects: the queues of the newest member only.
     */
    public function test_group_queues_cover_every_scanned_queue(): void
    {
        $this->insert(['failed_at' => self::NOW - 10, 'queue' => 'emails']);
        $this->insert(['failed_at' => self::NOW - 20, 'queue' => 'default']);
        $this->insert(['failed_at' => self::NOW - 30, 'connection' => 'second', 'queue' => 'default']);

        $group = $this->listGroups(['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk()->json('groups.0');

        self::assertSame(['database:default', 'database:emails', 'second:default'], $group['queues']);
        self::assertSame([3, true, true], [$group['count'], $group['queues_complete'], $group['exact']]);

        $detail = $this->detail($group['key'], ['from' => self::NOW - self::HOUR, 'to' => self::NOW])->assertOk();
        self::assertSame(['database:default', 'database:emails', 'second:default'], $detail->json('group.queues'));
    }

    /**
     * MySQL compares `emails` and `Emails` as equal under the column
     * collation. 300 newer failures of `Emails` share the key of the
     * `emails` failures.
     *
     * Rejects: a scope that the column collation decides.
     */
    public function test_scope_is_byte_exact_on_mysql(): void
    {
        if (! in_array($this->mirror()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Only MySQL folds the case of the queue in its column collation; Postgres and SQLite compare bytes.');
        }

        $lower = [];

        for ($i = 0; $i < 5; $i++) {
            $lower[] = $this->insert(['failed_at' => self::NOW - 1000 - $i, 'queue' => 'emails'])['uuid'];
        }

        $this->insertMany(300, static fn (int $i): array => ['failed_at' => self::NOW - 500 + $i, 'queue' => 'Emails']);
        $scope = ['from' => self::NOW - self::HOUR, 'to' => self::NOW, 'queue' => 'database:emails'];

        $list = $this->listGroups($scope)->assertOk();
        self::assertSame([5, ['database:emails']], [$list->json('groups.0.count'), $list->json('groups.0.queues')]);
        self::assertEquals(self::NOW - 1000, $list->json('groups.0.last_failed_at'));

        $detail = $this->detail(self::key('Boom'), $scope)->assertOk();
        self::assertSame([5, 5], [$detail->json('group.count'), $detail->json('total')]);
        self::assertSame($lower, array_column($detail->json('selection'), 'uuid'), 'the pairs are of `emails` only');
        self::assertSame(['emails'], array_values(array_unique(array_column($detail->json('jobs'), 'queue'))));
    }

    private static function key(string $message): string
    {
        return GroupKey::of('RuntimeException', $message, self::NAME);
    }

    /**
     * One failed row. The stored key is that of its columns, unless the
     * columns name a `failure_key` (null: a row without a key).
     *
     * @param  array<string, mixed>  $columns
     * @return array{uuid: string, occurrence_id: int}
     */
    private function insert(array $columns = []): array
    {
        $row = $this->columns($columns);
        $this->mirror()->table('orrery_jobs')->insert($row);

        return ['uuid' => $row['uuid'], 'occurrence_id' => $row['occurrence_id']];
    }

    /**
     * @param  \Closure(int): array<string, mixed>  $columns
     */
    private function insertMany(int $count, \Closure $columns): void
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = $this->columns($columns($i));

            if (count($rows) === 200) {
                $this->mirror()->table('orrery_jobs')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $this->mirror()->table('orrery_jobs')->insert($rows);
        }
    }

    /**
     * @param  array<string, mixed>  $columns
     * @return array<string, mixed>
     */
    private function columns(array $columns): array
    {
        $this->rows++;
        $uuid = sprintf('00000000-0000-4000-8000-%012d', $this->rows);
        $failedAt = (float) ($columns['failed_at'] ?? self::NOW - 10);
        unset($columns['failed_at']);
        $message = array_key_exists('exception_message', $columns) ? $columns['exception_message'] : 'Boom';

        return $columns + [
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'name' => self::NAME,
            'type' => 'job',
            'status' => 'failed',
            'payload' => '{}',
            'payload_bytes' => 2,
            'occurrence_id' => 1000 + $this->rows,
            'exception_class' => 'RuntimeException',
            'exception_message' => $message,
            'failure_key' => GroupKey::of('RuntimeException', is_string($message) ? $message : null, self::NAME),
            'exception' => 'RuntimeException: '.$message,
            'attempts' => 1,
            'pushed_at' => Clock::toDecimal($failedAt - 5),
            'finished_at' => Clock::toDecimal($failedAt),
            'failed_at' => Clock::toDecimal($failedAt),
            'updated_at' => Clock::toDecimal($failedAt),
        ];
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
