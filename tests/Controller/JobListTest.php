<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Testing\TestResponse;
use Justrau\Orrery\Http\Lists\JobList;
use Justrau\Orrery\Http\Lists\ListRequest;
use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Feature\Lists\ListFixtures;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET /api/jobs, the list engine: the tabs and their
 * predicates, both orders, the access paths (the `retention` merged ranges
 * included), keyset traversal under concurrent writes, the tag and batch
 * sources, cursors, capped totals, the candidate budget, has_newer, the
 * byte-exact filters and the summary rows.
 */
final class JobListTest extends ControllerTest
{
    use ListFixtures;

    /** One row per tab and one more of each, in a known order. */
    private const TABS = [
        'pending' => ['pending', []],
        'running' => ['reserved', []],
        'completed' => ['completed', []],
        'silenced' => ['completed', ['silenced' => true]],
        'failed' => ['failed', []],
        'retrying' => ['retrying', []],
        'unknown' => ['unknown', []],
    ];

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tabsAndOrders(): array
    {
        $cases = [];

        foreach (array_keys(self::TABS) as $tab) {
            foreach (['desc', 'asc'] as $order) {
                $cases["{$tab} {$order}"] = [$tab, $order];
            }
        }

        return $cases;
    }

    /**
     * Rejects: a tab that lists a row of another tab (completed with the
     * silenced, pending with the running), an order that is not the tab's,
     * and a list without its total.
     */
    #[DataProvider('tabsAndOrders')]
    public function test_each_tab_lists_only_its_rows_in_both_orders(string $tab, string $order): void
    {
        $n = 0;
        $expected = [];

        foreach (self::TABS as $name => [$status, $values]) {
            foreach ([10, 30, 20] as $at) {
                $this->addRow(++$n, $status, $at + $n * 0.001, $values);

                if ($name === $tab) {
                    $expected[$at + $n * 0.001] = self::uuidOf($n);
                }
            }
        }

        $this->flushRows();
        $order === 'desc' ? krsort($expected) : ksort($expected);

        $response = $this->list(['status' => $tab, 'order' => $order]);
        self::assertSame(array_values($expected), array_column($response->json('jobs'), 'id'));
        self::assertSame(['count' => 3, 'at_least' => false], $response->json('total'));
        self::assertNull($response->json('next_cursor'));
        self::assertNull($response->json('prev_cursor'));
        self::assertFalse($response->json('search_limited'));
    }

    /**
     * Rejects: a queue-scoped terminal tab that reads one silenced or
     * monitored combination only (the `retention` index has 2 or 4 ranges),
     * merges them out of order, or pages across them wrongly.
     */
    #[DataProvider('scopedTabs')]
    public function test_a_queue_scoped_terminal_tab_merges_the_retention_ranges(string $tab): void
    {
        [$status, $values] = self::TABS[$tab];
        $expected = [];

        for ($n = 1; $n <= 37; $n++) {
            $silenced = $tab === 'silenced' || ($tab !== 'completed' && $n % 2 === 0);
            $this->addRow($n, $status, 1000 - $n * 3, ['silenced' => $silenced, 'monitored' => $n % 3 === 0, 'queue' => 'emails'] + $values);
            $expected[] = self::uuidOf($n);
        }

        // The same tab on another queue, and another tab on the same queue.
        $this->addRow(100, $status, 500, ['queue' => 'other'] + $values);
        $this->addRow(101, $tab === 'pending' ? 'unknown' : 'pending', 500, ['queue' => 'emails']);
        $this->flushRows();

        $seen = [];
        $cursor = null;
        $guard = 0;

        do {
            $response = $this->list(['status' => $tab, 'connection' => 'database', 'queue' => 'emails', 'per_page' => 10] + ($cursor === null ? [] : ['cursor' => $cursor]));
            self::assertSame(['count' => 37, 'at_least' => false], $response->json('total'));
            $seen = [...$seen, ...array_column($response->json('jobs'), 'id')];
            $cursor = $response->json('next_cursor');
        } while ($cursor !== null && ++$guard < 1000);

        self::assertSame($expected, $seen, 'newest first across the merged ranges, each row once');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function scopedTabs(): array
    {
        return ['completed' => ['completed'], 'silenced' => ['silenced'], 'failed' => ['failed'], 'retrying' => ['retrying'], 'unknown' => ['unknown'], 'pending' => ['pending'], 'running' => ['running']];
    }

    /**
     * Rejects: offset paging or a keyset that skips or repeats a row whose
     * order key does not change while rows arrive and leave between pages,
     * and a page that lists a row of another tab. 25,000 pending rows, read
     * newest first in pages of 100; between pages new rows arrive and
     * unread rows move to completed.
     */
    public function test_keyset_traversal_under_concurrent_writes(): void
    {
        $total = 25_000;

        for ($n = 1; $n <= $total; $n++) {
            // Pairs share a pushed_at, so the id decides the order inside a pair.
            $this->addRow($n, 'pending', intdiv($n, 2));
        }

        $this->flushRows();
        $mirror = $this->mirror();
        $moved = [];
        $next = $total;
        $seen = [];
        $cursor = null;
        $guard = 0;
        $page = 0;

        do {
            $response = $this->list(['status' => 'pending', 'per_page' => 100] + ($cursor === null ? [] : ['cursor' => $cursor]));

            foreach ($response->json('jobs') as $job) {
                self::assertSame('pending', $job['status']);
                self::assertArrayNotHasKey($job['id'], $seen, 'a row appears once');
                $seen[$job['id']] = true;
            }

            $cursor = $response->json('next_cursor');
            $page++;

            // A new row arrives (newer than every read row), and an unread row moves to completed.
            $this->addRow(++$next, 'pending', $total);
            $this->flushRows();
            $victim = max(1, $total - $page * 100 - 50);
            $mirror->table('orrery_jobs')->where('uuid', self::uuidOf($victim))->update(['status' => 'completed', 'finished_at' => Clock::toDecimal(self::BASE + $total)]);
            $moved[self::uuidOf($victim)] = true;
        } while ($cursor !== null && ++$guard < 1000);

        for ($n = 1; $n <= $total; $n++) {
            $uuid = self::uuidOf($n);

            if (! isset($moved[$uuid])) {
                self::assertArrayHasKey($uuid, $seen, "the stable row {$n} appears");
            }
        }

        self::assertGreaterThan(240, $page);
    }

    /**
     * Rejects: a tag-source cursor keyed on the id or on the order value
     * alone (tied pushed_at values would skip or repeat rows), and a tag
     * list that misses the tag's jobs of every status.
     */
    public function test_a_tag_source_pages_by_order_and_job_uuid(): void
    {
        for ($n = 1; $n <= 45; $n++) {
            $this->addRow($n, ['pending', 'completed', 'failed'][$n % 3], intdiv($n, 10));
            $this->addTag($n, 'orders', intdiv($n, 10));
        }

        $this->addRow(99, 'pending', 1);
        $this->addTag(99, 'other', 1);
        $this->flushRows();

        $seen = [];
        $cursor = null;
        $guard = 0;

        do {
            $response = $this->list(['status' => 'all', 'tag' => ' orders ', 'per_page' => 7] + ($cursor === null ? [] : ['cursor' => $cursor]));
            self::assertSame(['count' => 45, 'at_least' => false], $response->json('total'));
            $seen = [...$seen, ...array_column($response->json('jobs'), 'id')];
            $cursor = $response->json('next_cursor');
        } while ($cursor !== null && ++$guard < 1000);

        self::assertCount(45, $seen);
        self::assertCount(45, array_unique($seen));

        // A silenced completed row with the tag belongs to the silenced tab only.
        $this->addRow(98, 'completed', 2, ['silenced' => true]);
        $this->addTag(98, 'orders', 2);
        $this->flushRows();
        self::assertNotContains(self::uuidOf(98), array_column($this->list(['status' => 'completed', 'tag' => 'orders', 'per_page' => 100])->json('jobs'), 'id'));
        self::assertSame([self::uuidOf(98)], array_column($this->list(['status' => 'silenced', 'tag' => 'orders', 'per_page' => 100])->json('jobs'), 'id'));

        $pending = $this->list(['status' => 'pending', 'tag' => 'orders', 'per_page' => 100]);
        self::assertCount(15, $pending->json('jobs'), 'a status tab with a tag is residual on the tag source');
        self::assertSame(['pending'], array_values(array_unique(array_column($pending->json('jobs'), 'status'))));
    }

    /**
     * A tag's list compares the tag byte for byte, as its count on the tags
     * API does (spec 4.11: the tag's full total equals the list total of its
     * tag view). The `orders ` rows are written directly: the normalizer
     * never stores one.
     *
     * Rejects: a tag source, a `q` tag check or a uuid-page tag check with
     * the collated equality alone (MySQL's utf8mb4_bin pads trailing spaces,
     * so the `orders ` rows count and list for `orders`).
     */
    public function test_a_tag_list_is_byte_exact_and_equals_the_tag_count(): void
    {
        $this->mirror()->table('orrery_monitored_tags')->insert(['tag' => 'orders', 'created_at' => Clock::toDecimal(Clock::now())]);

        foreach ([1 => 'orders', 2 => 'orders', 3 => 'orders ', 4 => 'orders ', 5 => 'orders '] as $n => $tag) {
            $this->addRow($n, 'failed', $n, ['name' => 'App\\Jobs\\Sync']);
            $this->addTag($n, $tag, $n);
            $this->addTag($n, $tag, $n, true);
        }

        $this->addRow(6, 'completed', 6, ['name' => 'App\\Jobs\\Sync']);
        $this->addTag(6, 'orders ', 6);
        $this->flushRows();

        $tags = $this->actingAs(new User)->getJson('/orrery/api/tags')->assertOk();
        self::assertSame(['count' => 2, 'at_least' => false], $tags->json('tags.0.count'));
        self::assertSame(['count' => 2, 'at_least' => false], $tags->json('tags.0.failed'));

        foreach (['all' => 'count', 'failed' => 'failed'] as $tab => $field) {
            $response = $this->list(['status' => $tab, 'tag' => 'orders', 'per_page' => 100]);
            self::assertSame($tags->json("tags.0.{$field}"), $response->json('total'), "{$tab}: the list total is the tag's count");
            self::assertSame([self::uuidOf(2), self::uuidOf(1)], array_column($response->json('jobs'), 'id'), "{$tab}: the page");
        }

        self::assertSame([], $this->list(['status' => 'all', 'tag' => 'orders', 'q' => self::uuidOf(3)])->json('jobs'), 'the uuid page checks the tag bytes');
        self::assertSame([], $this->list(['status' => 'completed', 'q' => 'orders'])->json('jobs'), 'q finds no row through a padded tag');
        self::assertSame([self::uuidOf(2), self::uuidOf(1)], array_column($this->list(['status' => 'failed', 'q' => 'orders'])->json('jobs'), 'id'), 'q matches the exact tag only');
    }

    /**
     * Rejects: a JobList::total() whose residual count differs from the
     * page's total (it once copied read()'s counting and missed the
     * byte-exact `q` tag check): a `q` that is a tag only in another letter
     * case, or (MySQL) only with a trailing space, is no tag, and both give
     * the same total.
     */
    public function test_total_equals_the_page_total_for_a_q_tag(): void
    {
        foreach ([1 => 'orders', 2 => 'orders ', 3 => 'Orders'] as $n => $tag) {
            $this->addRow($n, 'failed', $n, ['name' => 'App\\Jobs\\Sync']);
            $this->addTag($n, $tag, $n);
        }

        $this->flushRows();
        $lists = $this->app->make(JobList::class);

        foreach (['ORDERS', 'orders', 'orders ', 'Orders'] as $q) {
            foreach (['failed', 'completed'] as $tab) {
                $query = ['status' => $tab, 'q' => $q];
                self::assertSame($this->list($query)->json('total'), $lists->total(ListRequest::from($query)), "{$tab}, q '{$q}'");
            }
        }

        self::assertSame(['count' => 1, 'at_least' => false], $lists->total(ListRequest::from(['status' => 'failed', 'q' => 'orders'])));
    }

    /**
     * Rejects: a batch source with the collated equality alone (MySQL folds
     * case, so `b` lists and counts the members of `B`).
     */
    public function test_a_batch_list_is_byte_exact(): void
    {
        foreach ([1 => 'B', 2 => 'B', 3 => 'b', 4 => 'b', 5 => 'b'] as $n => $batch) {
            $this->addRow($n, 'failed', $n, ['batch_id' => $batch]);
            $this->addRow($n + 10, 'pending', $n, ['batch_id' => $batch]);
        }

        $this->flushRows();

        foreach (['B' => [2, 1], 'b' => [5, 4, 3]] as $batch => $members) {
            foreach (['failed' => 0, 'pending' => 10] as $tab => $offset) {
                $response = $this->list(['status' => $tab, 'batch' => $batch, 'per_page' => 100]);
                self::assertSame(['count' => count($members), 'at_least' => false], $response->json('total'), "{$tab}: the count of {$batch}");
                self::assertSame(array_map(static fn (int $n): string => self::uuidOf($n + $offset), $members), array_column($response->json('jobs'), 'id'), "{$tab}: {$batch}");
            }
        }
    }

    /**
     * Rejects: a cursor accepted for another status, filter or order, and a
     * malformed cursor accepted.
     */
    public function test_a_cursor_of_another_list_is_refused(): void
    {
        for ($n = 1; $n <= 5; $n++) {
            $this->addRow($n, 'pending', $n);
        }

        $this->flushRows();
        $cursor = $this->list(['status' => 'pending', 'per_page' => 2])->json('next_cursor');
        self::assertIsString($cursor);

        foreach ([['status' => 'completed'], ['status' => 'pending', 'order' => 'asc'], ['status' => 'pending', 'class' => 'X']] as $other) {
            $this->actingAs(new User)->getJson('/orrery/api/jobs?'.http_build_query($other + ['cursor' => $cursor]))
                ->assertStatus(422)
                ->assertJson(['error' => 'cursor_mismatch', 'field' => 'cursor']);
        }

        $this->actingAs(new User)->getJson('/orrery/api/jobs?status=pending&newer_than='.$cursor)->assertOk();
        $this->actingAs(new User)->getJson('/orrery/api/jobs?status=pending&cursor=bm90IGpzb24')->assertStatus(422)->assertJson(['error' => 'invalid', 'field' => 'cursor']);
    }

    /**
     * Rejects: a cursor with a null order value on a list without a null-order group (only a batch's pending and
     * running members have one): it reached the query builder (a 503 on MySQL); it answers 422 `invalid`.
     */
    public function test_a_null_order_cursor_is_refused_outside_a_null_order_group(): void
    {
        $this->addRow(1, 'pending', 1);
        $this->flushRows();
        $cursor = $this->list(['status' => 'pending', 'per_page' => 1])->json('head');
        self::assertIsString($cursor);
        $data = json_decode((string) base64_decode(strtr($cursor, '-_', '+/')), true);
        self::assertIsArray($data);
        $data['k'][0] = null;
        $crafted = rtrim(strtr(base64_encode((string) json_encode($data, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');

        foreach (['cursor', 'newer_than'] as $field) {
            $this->actingAs(new User)->getJson('/orrery/api/jobs?'.http_build_query(['status' => 'pending', $field => $crafted]))
                ->assertStatus(422)
                ->assertJson(['error' => 'invalid', 'field' => $field]);
        }
    }

    /**
     * Rejects: a total that is not capped, says at_least at exactly the cap,
     * or is null instead of 0 on an empty list.
     */
    public function test_the_capped_total_at_0_1_10000_and_10001_rows(): void
    {
        self::assertSame(['count' => 0, 'at_least' => false], $this->list(['status' => 'unknown'])->json('total'));

        $this->addRow(1, 'unknown', 1);
        $this->flushRows();
        self::assertSame(['count' => 1, 'at_least' => false], $this->list(['status' => 'unknown'])->json('total'));

        for ($n = 2; $n <= 10_000; $n++) {
            $this->addRow($n, 'unknown', $n);
        }

        $this->flushRows();
        self::assertSame(['count' => 10_000, 'at_least' => false], $this->list(['status' => 'unknown'])->json('total'));

        $this->addRow(10_001, 'unknown', 10_001);
        $this->flushRows();
        self::assertSame(['count' => 10_000, 'at_least' => true], $this->list(['status' => 'unknown'])->json('total'));
    }

    /**
     * Rejects: a residual search without a budget, a budget that answers no
     * `search_limited`, and a next page that repeats the scan or skips past
     * unexamined rows (it must resume after the last examined row).
     */
    public function test_search_limited_resumes_after_the_last_examined_row(): void
    {
        $this->app['config']->set('orrery.dashboard.search_candidates', 100);

        for ($n = 1; $n <= 450; $n++) {
            $this->addRow($n, 'completed', $n, ['name' => $n % 90 === 0 ? 'App\\Jobs\\Rare' : 'App\\Jobs\\Common']);
        }

        $this->flushRows();

        $first = $this->list(['status' => 'completed', 'class' => 'App\\Jobs\\Rare', 'per_page' => 10]);
        self::assertTrue($first->json('search_limited'));
        // The budget examines rows 450 down to 351: two matches.
        self::assertSame(['count' => 2, 'at_least' => true], $first->json('total'));
        self::assertSame([self::uuidOf(450), self::uuidOf(360)], array_column($first->json('jobs'), 'id'));
        self::assertIsString($first->json('scanned_to'));
        self::assertSame($first->json('scanned_to'), $first->json('next_cursor'), 'the page is not full: resume after the last examined row');

        $seen = array_column($first->json('jobs'), 'id');
        $cursor = $first->json('next_cursor');
        $guard = 0;

        while ($cursor !== null && ++$guard < 1000) {
            $page = $this->list(['status' => 'completed', 'class' => 'App\\Jobs\\Rare', 'per_page' => 10, 'cursor' => $cursor]);
            $seen = [...$seen, ...array_column($page->json('jobs'), 'id')];
            $cursor = $page->json('next_cursor');
        }

        self::assertSame([self::uuidOf(450), self::uuidOf(360), self::uuidOf(270), self::uuidOf(180), self::uuidOf(90)], $seen);

        $this->app['config']->set('orrery.dashboard.search_candidates', 1000);
        $all = $this->list(['status' => 'completed', 'class' => 'App\\Jobs\\Rare', 'per_page' => 10]);
        self::assertFalse($all->json('search_limited'));
        self::assertNull($all->json('scanned_to'));
        self::assertSame(['count' => 5, 'at_least' => false], $all->json('total'));
    }

    /**
     * Rejects: a backward page under search_limited that
     * answers prev null in the middle of the list, a next cursor after the
     * cursor's own row (it skips it), a limited backward page whose prev
     * starts before its first row instead of before the last examined row,
     * and a forward empty page with a cursor that answers prev null.
     */
    public function test_backward_paging_under_search_limited_skips_and_loses_nothing(): void
    {
        $this->app['config']->set('orrery.dashboard.search_candidates', 100);

        for ($n = 1; $n <= 1000; $n++) {
            $this->addRow($n, 'completed', $n, ['name' => in_array($n, [500, 950], true) ? 'App\\Jobs\\Rare' : 'App\\Jobs\\Common']);
        }

        $this->flushRows();
        $query = ['status' => 'completed', 'class' => 'App\\Jobs\\Rare', 'per_page' => 10];
        $response = $this->list($query);
        self::assertSame([self::uuidOf(950)], array_column($response->json('jobs'), 'id'));
        $guard = 0;

        // Forward to the page that holds 500; the empty pages on the way keep a prev cursor.
        while (! in_array(self::uuidOf(500), array_column($response->json('jobs'), 'id'), true) && ++$guard < 50) {
            $response = $this->list($query + ['cursor' => $response->json('next_cursor')]);

            if ($response->json('jobs') === []) {
                self::assertIsString($response->json('prev_cursor'), 'a forward empty page with a cursor keeps a prev cursor');
            }
        }

        self::assertSame([self::uuidOf(500)], array_column($response->json('jobs'), 'id'));

        // Back: the budget ends before a match.
        $back = $this->list($query + ['cursor' => $response->json('prev_cursor')]);
        self::assertSame([], $back->json('jobs'));
        self::assertTrue($back->json('search_limited'));
        self::assertIsString($back->json('prev_cursor'), 'the middle of the list has a prev cursor');

        $forward = $this->list($query + ['cursor' => $back->json('next_cursor')]);
        self::assertSame(self::uuidOf(500), $forward->json('jobs.0.id'), 'the next cursor of a backward page includes the row of its cursor');

        // The prev chain reaches 950, then the first page.
        $seen = [];
        $cursor = $back->json('prev_cursor');
        $guard = 0;

        while ($cursor !== null && ++$guard < 50) {
            $page = $this->list($query + ['cursor' => $cursor]);
            $seen = [...$seen, ...array_column($page->json('jobs'), 'id')];
            $cursor = $page->json('prev_cursor');
        }

        self::assertSame([self::uuidOf(950)], $seen);
        self::assertLessThan(50, $guard);
    }

    /**
     * Rejects: a limited backward page that is not full
     * and whose prev cursor starts before its first row (it would examine
     * the same non-matching rows again, and never move past them).
     */
    public function test_a_limited_backward_page_continues_before_the_last_examined_row(): void
    {
        $this->app['config']->set('orrery.dashboard.search_candidates', 100);

        for ($n = 1; $n <= 400; $n++) {
            $this->addRow($n, 'completed', $n, ['name' => in_array($n, [150, 215, 330], true) ? 'App\\Jobs\\Rare' : 'App\\Jobs\\Common']);
        }

        $this->flushRows();
        $query = ['status' => 'completed', 'class' => 'App\\Jobs\\Rare', 'per_page' => 10];
        $page = $this->list($query);
        $guard = 0;

        // Pages of 100 examined rows: 400..301 [330], 300..201 [215], 200..101 [150].
        while (array_column($page->json('jobs'), 'id') !== [self::uuidOf(150)] && ++$guard < 20) {
            $page = $this->list($query + ['cursor' => $page->json('next_cursor')]);
        }

        self::assertLessThan(20, $guard);

        // Before 150: rows 151..250 are examined, 215 matches. The next page back starts before 250, so it reaches 330.
        $back = $this->list($query + ['cursor' => $page->json('prev_cursor')]);
        self::assertSame([self::uuidOf(215)], array_column($back->json('jobs'), 'id'));
        self::assertTrue($back->json('search_limited'));
        $back = $this->list($query + ['cursor' => $back->json('prev_cursor')]);
        self::assertSame([self::uuidOf(330)], array_column($back->json('jobs'), 'id'));
    }

    /**
     * Rejects: a residual scan over merged
     * ranges whose first range takes the whole candidate budget (a chunk of
     * 500 with a budget of 300): no row comes out, there is no next cursor,
     * and the list never moves past its start.
     */
    public function test_a_small_budget_over_merged_ranges_still_advances(): void
    {
        $this->app['config']->set('orrery.dashboard.search_candidates', 300);
        $expected = [];

        for ($n = 1; $n <= 1200; $n++) {
            $silenced = $n % 2 === 0;
            $monitored = $n % 3 === 0;
            $rare = $silenced && $monitored && $n % 120 === 0;
            $this->addRow($n, 'failed', $n, ['silenced' => $silenced, 'monitored' => $monitored, 'queue' => 'emails', 'name' => $rare ? 'App\\Jobs\\Rare' : 'App\\Jobs\\Common']);

            if ($rare) {
                $expected[] = self::uuidOf($n);
            }
        }

        $this->flushRows();
        $query = ['status' => 'failed', 'connection' => 'database', 'queue' => 'emails', 'class' => 'App\\Jobs\\Rare', 'per_page' => 3];
        $seen = [];
        $cursor = null;
        $guard = 0;

        do {
            $response = $this->list($query + ($cursor === null ? [] : ['cursor' => $cursor]));
            $seen = [...$seen, ...array_column($response->json('jobs'), 'id')];
            $cursor = $response->json('next_cursor');
        } while ($cursor !== null && ++$guard < 100);

        self::assertSame(array_reverse($expected), $seen, 'every match, newest first, each once');
        self::assertLessThan(100, $guard);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function tinyBudgets(): array
    {
        return ['1' => [1], '2' => [2], '300' => [300]];
    }

    /**
     * Rejects: a scan over 2 merged
     * ranges that stops `limited` before its first row when the budget is
     * smaller than the ranges' first reads together: no row, no scanned_to,
     * no next cursor. Every limited request must return a row or a cursor
     * past an examined row, so the pages reach every match.
     */
    #[DataProvider('tinyBudgets')]
    public function test_every_budget_makes_progress_over_two_merged_ranges(int $budget): void
    {
        $this->app['config']->set('orrery.dashboard.search_candidates', $budget);
        $expected = [];

        for ($n = 1; $n <= 60; $n++) {
            $rare = $n % 10 === 0;
            $this->addRow($n, 'completed', $n, ['monitored' => $n % 2 === 0, 'queue' => 'emails', 'name' => $rare ? 'App\\Jobs\\Rare' : 'App\\Jobs\\Common']);

            if ($rare) {
                $expected[] = self::uuidOf($n);
            }
        }

        $this->flushRows();
        $query = ['status' => 'completed', 'connection' => 'database', 'queue' => 'emails', 'class' => 'App\\Jobs\\Rare', 'per_page' => 2];
        $seen = [];
        $cursor = null;
        $guard = 0;

        do {
            $response = $this->list($query + ($cursor === null ? [] : ['cursor' => $cursor]));
            $seen = [...$seen, ...array_column($response->json('jobs'), 'id')];
            $cursor = $response->json('next_cursor');

            if ($response->json('search_limited') === true) {
                self::assertTrue($response->json('jobs') !== [] || is_string($cursor), 'a limited request makes progress');
            }
        } while ($cursor !== null && ++$guard < 500);

        self::assertSame(array_reverse($expected), $seen);
    }

    /**
     * Rejects: a has_newer that answers false (nothing new) when the
     * residual seek spent its budget before a match: that is unknown.
     */
    public function test_has_newer_is_null_when_the_residual_seek_spends_its_budget(): void
    {
        $this->app['config']->set('orrery.dashboard.search_candidates', 100);

        for ($n = 1; $n <= 5; $n++) {
            $this->addRow($n, 'completed', $n, ['name' => 'App\\Jobs\\Rare']);
        }

        $this->flushRows();
        $head = $this->list(['status' => 'completed', 'class' => 'App\\Jobs\\Rare'])->json('head');

        for ($n = 6; $n <= 160; $n++) {
            $this->addRow($n, 'completed', $n, ['name' => 'App\\Jobs\\Common']);
        }

        $this->flushRows();
        self::assertNull($this->list(['status' => 'completed', 'class' => 'App\\Jobs\\Rare', 'newer_than' => $head])->json('has_newer'));
    }

    /**
     * Rejects: a residual total that is not capped at list_count_cap, or not
     * "at least" there, and a scan that goes on counting past the cap (it
     * would spend the budget and answer search_limited).
     */
    public function test_a_residual_total_is_capped(): void
    {
        $this->app['config']->set('orrery.dashboard.list_count_cap', 101);
        // The scan stops at cap + 1 matches, before the budget would end it.
        $this->app['config']->set('orrery.dashboard.search_candidates', 120);

        for ($n = 1; $n <= 150; $n++) {
            $this->addRow($n, 'completed', $n);
        }

        $this->flushRows();
        $response = $this->list(['status' => 'completed', 'class' => 'App\\Jobs\\Sync', 'per_page' => 100]);
        self::assertSame(['count' => 101, 'at_least' => true], $response->json('total'));
        self::assertCount(100, $response->json('jobs'));
        self::assertIsString($response->json('next_cursor'));
        self::assertFalse($response->json('search_limited'));
    }

    /**
     * Rejects: a list_count_cap below a full page that stops a residual
     * scan before the page is full.
     */
    public function test_a_small_count_cap_still_fills_the_page(): void
    {
        $this->app['config']->set('orrery.dashboard.list_count_cap', 5);

        for ($n = 1; $n <= 30; $n++) {
            $this->addRow($n, 'completed', $n);
        }

        $this->flushRows();
        $response = $this->list(['status' => 'completed', 'class' => 'App\\Jobs\\Sync', 'per_page' => 20]);
        self::assertCount(20, $response->json('jobs'));
    }

    /**
     * Rejects: a range that ends exactly at the candidate budget answered as
     * search_limited (the end of the list is known).
     */
    public function test_a_range_that_ends_at_the_budget_is_not_search_limited(): void
    {
        $this->app['config']->set('orrery.dashboard.search_candidates', 100);

        for ($n = 1; $n <= 100; $n++) {
            $this->addRow($n, 'completed', $n, ['name' => $n === 1 ? 'App\\Jobs\\Rare' : 'App\\Jobs\\Common']);
        }

        $this->flushRows();
        $response = $this->list(['status' => 'completed', 'class' => 'App\\Jobs\\Rare']);
        self::assertFalse($response->json('search_limited'));
        self::assertSame(['count' => 1, 'at_least' => false], $response->json('total'));
        self::assertNull($response->json('next_cursor'));
    }

    /**
     * Rejects: ascending keyset pages across the merged retention ranges
     * that skip or repeat rows, or come out of order.
     */
    public function test_ascending_pages_across_merged_ranges(): void
    {
        $expected = [];

        for ($n = 1; $n <= 37; $n++) {
            $this->addRow($n, 'failed', $n * 3, ['silenced' => $n % 2 === 0, 'monitored' => $n % 3 === 0, 'queue' => 'emails']);
            $expected[] = self::uuidOf($n);
        }

        $this->flushRows();
        $query = ['status' => 'failed', 'connection' => 'database', 'queue' => 'emails', 'order' => 'asc', 'per_page' => 10];
        $seen = [];
        $pages = [];
        $cursor = null;
        $guard = 0;

        do {
            $response = $this->list($query + ($cursor === null ? [] : ['cursor' => $cursor]));
            $pages[] = $response;
            $seen = [...$seen, ...array_column($response->json('jobs'), 'id')];
            $cursor = $response->json('next_cursor');
        } while ($cursor !== null && ++$guard < 100);

        self::assertSame($expected, $seen);
        $back = $this->list($query + ['cursor' => $pages[2]->json('prev_cursor')]);
        self::assertSame(array_column($pages[1]->json('jobs'), 'id'), array_column($back->json('jobs'), 'id'));
    }

    /**
     * Rejects: a has_newer from a hash of the first row (true when rows only
     * leave), a has_newer that misses an arrival, and one with order asc.
     */
    public function test_has_newer_is_true_on_an_arrival_and_false_when_rows_only_leave(): void
    {
        for ($n = 1; $n <= 5; $n++) {
            $this->addRow($n, 'failed', $n);
        }

        $this->flushRows();
        $first = $this->list(['status' => 'failed']);
        $head = $first->json('head');
        self::assertIsString($head);
        self::assertNull($first->json('has_newer'));

        $this->mirror()->table('orrery_jobs')->where('uuid', self::uuidOf(5))->update(['status' => 'retrying']);
        $this->mirror()->table('orrery_jobs')->where('uuid', self::uuidOf(2))->delete();
        self::assertFalse($this->list(['status' => 'failed', 'newer_than' => $head])->json('has_newer'));

        $this->addRow(6, 'failed', 6);
        $this->flushRows();
        self::assertTrue($this->list(['status' => 'failed', 'newer_than' => $head])->json('has_newer'));

        // A residual filter: the seek goes on until a match.
        $headClass = $this->list(['status' => 'failed', 'class' => 'App\\Jobs\\Sync'])->json('head');
        $this->addRow(7, 'failed', 7, ['name' => 'App\\Jobs\\Other']);
        $this->flushRows();
        self::assertFalse($this->list(['status' => 'failed', 'class' => 'App\\Jobs\\Sync', 'newer_than' => $headClass])->json('has_newer'));
        $this->addRow(8, 'failed', 8);
        $this->flushRows();
        self::assertTrue($this->list(['status' => 'failed', 'class' => 'App\\Jobs\\Sync', 'newer_than' => $headClass])->json('has_newer'));

        $asc = $this->list(['status' => 'failed', 'order' => 'asc']);
        self::assertNull($this->list(['status' => 'failed', 'order' => 'asc', 'newer_than' => $asc->json('head')])->json('has_newer'));
    }

    /**
     * Rejects: a collated comparison (MySQL folds case, accents and
     * trailing spaces) that lists or counts `HIGH` for `high`, and a filter
     * that trims the value.
     */
    public function test_queue_and_class_filters_are_byte_exact(): void
    {
        $n = 0;

        foreach (['high', 'HIGH', 'high ', 'hígh'] as $queue) {
            foreach (['App\\Jobs\\Sync', 'App\\Jobs\\SYNC', 'App\\Jobs\\Sync '] as $class) {
                $this->addRow(++$n, 'completed', $n, ['queue' => $queue, 'name' => $class]);
                $this->addRow(++$n, 'pending', $n, ['queue' => $queue, 'name' => $class]);
            }
        }

        $this->flushRows();

        foreach (['completed', 'pending'] as $tab) {
            foreach (['high', 'HIGH', 'high ', 'hígh'] as $queue) {
                $response = $this->list(['status' => $tab, 'connection' => 'database', 'queue' => $queue]);
                self::assertSame([$queue], array_values(array_unique(array_column($response->json('jobs'), 'queue'))), "{$tab}: {$queue}");
                self::assertSame(['count' => 3, 'at_least' => false], $response->json('total'), "{$tab}: the count of {$queue}");

                $class = $this->list(['status' => $tab, 'connection' => 'database', 'queue' => $queue, 'class' => 'App\\Jobs\\Sync']);
                self::assertSame(['App\\Jobs\\Sync'], array_column($class->json('jobs'), 'name'));
            }
        }

        $connection = $this->list(['status' => 'completed', 'connection' => 'DATABASE']);
        self::assertSame([], $connection->json('jobs'));
    }

    /**
     * Rejects: a list read that selects the payload, the exception text or
     * the context (the summary rows never carry them).
     */
    public function test_no_list_statement_reads_payload_exception_or_context(): void
    {
        for ($n = 1; $n <= 5; $n++) {
            $this->addRow($n, 'failed', $n, ['exception' => 'trace', 'exception_message' => 'boom', 'exception_class' => 'RuntimeException', 'context' => '{}']);
            $this->addTag($n, 'orders', $n);
        }

        $this->flushRows();
        $statements = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->list(['status' => 'failed']);
        $this->list(['status' => 'failed', 'class' => 'X', 'q' => 'Syn']);
        $this->list(['status' => 'all', 'tag' => 'orders']);

        $jobs = array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'orrery_jobs'));
        self::assertNotSame([], $jobs);

        foreach ($jobs as $sql) {
            self::assertDoesNotMatchRegularExpression('/["`](payload|exception|context)["`]|select \*|\.\*/', $sql);
        }
    }

    /**
     * The "last verified" time of each database queue connection,
     * on the pending and running tabs, and null on the other tabs.
     * Rewritten on the list engine from the legacy CompatFieldsTest.
     *
     * Rejects: a database queue connection missing from the map, a
     * connection without a complete sweep shown as verified (or as 0), the
     * swept time of another connection, and the field on a terminal tab.
     */
    public function test_last_verified_names_each_database_connection_on_the_active_tabs(): void
    {
        $this->app['config']->set('queue.connections.list-other', ['driver' => 'database', 'connection' => 'testbench', 'table' => 'jobs', 'queue' => 'default']);
        $this->mirror()->table('orrery_settings')->insert(['key' => Reconciliation::SWEPT_PREFIX.'database', 'value' => Clock::toDecimal(1_234.5)]);

        foreach (['pending', 'running'] as $tab) {
            $verified = $this->list(['status' => $tab])->json('last_verified');
            self::assertIsArray($verified, $tab);
            self::assertSame(1_234.5, $verified['database'] ?? 'missing', $tab);
            self::assertArrayHasKey('list-other', $verified, $tab);
            self::assertNull($verified['list-other'], $tab.': a connection without a complete sweep is never verified');
        }

        foreach (['completed', 'silenced', 'failed', 'retrying', 'unknown'] as $tab) {
            self::assertNull($this->list(['status' => $tab])->json('last_verified'), $tab);
        }
    }

    /**
     * Rejects: a `prev_cursor` on the first page, a `before` cursor that
     * repeats or skips rows, and a short first page reached backwards.
     */
    public function test_prev_cursor_pages_back_to_the_first_page(): void
    {
        for ($n = 1; $n <= 25; $n++) {
            $this->addRow($n, 'completed', $n);
        }

        $this->flushRows();
        $pages = [];
        $response = $this->list(['status' => 'completed', 'per_page' => 10]);
        $pages[] = array_column($response->json('jobs'), 'id');
        $response = $this->list(['status' => 'completed', 'per_page' => 10, 'cursor' => $response->json('next_cursor')]);
        $pages[] = array_column($response->json('jobs'), 'id');
        $response = $this->list(['status' => 'completed', 'per_page' => 10, 'cursor' => $response->json('next_cursor')]);
        $pages[] = array_column($response->json('jobs'), 'id');
        self::assertNull($response->json('next_cursor'));
        self::assertCount(5, $pages[2]);

        $back = $this->list(['status' => 'completed', 'per_page' => 10, 'cursor' => $response->json('prev_cursor')]);
        self::assertSame($pages[1], array_column($back->json('jobs'), 'id'));
        $back = $this->list(['status' => 'completed', 'per_page' => 10, 'cursor' => $back->json('prev_cursor')]);
        self::assertSame($pages[0], array_column($back->json('jobs'), 'id'));
        self::assertNull($back->json('prev_cursor'));
        self::assertIsString($back->json('head'));
    }

    /**
     * Rejects: a batch's pending members read on a null finished_at with a
     * keyset on the order value (it would read nothing after page 1), and
     * a batch filter that lists another batch.
     */
    public function test_a_batch_pages_its_pending_members_by_id(): void
    {
        for ($n = 1; $n <= 12; $n++) {
            $this->addRow($n, 'pending', $n, ['batch_id' => 'batch-a']);
        }

        $this->addRow(20, 'pending', 1, ['batch_id' => 'batch-b']);
        $this->addRow(21, 'failed', 1, ['batch_id' => 'batch-a']);
        $this->flushRows();

        $seen = [];
        $cursor = null;
        $guard = 0;

        do {
            $response = $this->list(['status' => 'pending', 'batch' => 'batch-a', 'per_page' => 5] + ($cursor === null ? [] : ['cursor' => $cursor]));
            self::assertSame(['count' => 12, 'at_least' => false], $response->json('total'));
            $seen = [...$seen, ...array_column($response->json('jobs'), 'id')];
            $cursor = $response->json('next_cursor');
        } while ($cursor !== null && ++$guard < 1000);

        self::assertSame(array_map(self::uuidOf(...), range(12, 1, -1)), $seen);
        self::assertSame([self::uuidOf(21)], array_column($this->list(['status' => 'failed', 'batch' => 'batch-a'])->json('jobs'), 'id'));
    }

    /**
     * Rejects: a search text matched case-sensitively, an exact tag of the
     * same text left out, and a full uuid that ignores the tab.
     */
    public function test_q_matches_a_name_substring_an_existing_tag_or_a_uuid(): void
    {
        $this->addRow(1, 'completed', 1, ['name' => 'App\\Jobs\\SendInvoice']);
        $this->addRow(2, 'completed', 2, ['name' => 'App\\Jobs\\Sync']);
        $this->addRow(3, 'completed', 3, ['name' => 'App\\Jobs\\Other']);
        $this->addTag(3, 'invoice', 3);
        $this->addRow(4, 'pending', 4, ['name' => 'App\\Jobs\\SendInvoice']);
        $this->flushRows();

        self::assertSame([self::uuidOf(3), self::uuidOf(1)], array_column($this->list(['status' => 'completed', 'q' => 'invoice'])->json('jobs'), 'id'));
        self::assertSame([self::uuidOf(2)], array_column($this->list(['status' => 'completed', 'q' => 'SYNC'])->json('jobs'), 'id'));

        $uuid = strtoupper(self::uuidOf(1));
        self::assertSame([self::uuidOf(1)], array_column($this->list(['status' => 'completed', 'q' => $uuid])->json('jobs'), 'id'));
        self::assertSame([], $this->list(['status' => 'pending', 'q' => $uuid])->json('jobs'), 'the uuid is checked against the tab');
        self::assertSame(['count' => 0, 'at_least' => false], $this->list(['status' => 'pending', 'q' => $uuid])->json('total'));
    }

    /**
     * Rejects: a running row without its worker fact or lease, a held row
     * without its operation, and a summary that carries the internal id.
     */
    public function test_summary_rows_carry_tags_worker_lease_and_operation(): void
    {
        $this->app['config']->set('queue.connections.database.retry_after', 90);
        $now = Clock::now();
        $this->addRow(1, 'reserved', $now - self::BASE - 120, ['reserved_by' => 'host:1:1']);
        $this->addRow(2, 'failed', 1, ['operation_id' => str_repeat('a', 32)]);
        $this->addTag(1, 'b-tag', 1);
        $this->addTag(1, 'a-tag', 1);
        $this->flushRows();
        $this->mirror()->table('orrery_worker_heartbeats')->insert([
            'worker_id' => 'host:1:1', 'host' => 'host', 'pid' => 1, 'started_at' => '1.000000', 'mode' => 'work', 'connection' => 'database',
            'subscribed_queues' => '["default"]', 'options' => '{}', 'last_seen_at' => Clock::toDecimal($now - 300),
        ]);
        $this->mirror()->table('orrery_operations')->insert([
            'id' => str_repeat('a', 32), 'action' => 'retry', 'state' => 'claimed', 'mode' => 'none', 'job_uuid' => self::uuidOf(2), 'occurrence_id' => 2,
            'queue_connection' => 'database', 'queue' => 'default', 'queue_fingerprint' => str_repeat('0', 64), 'deadline' => '1.000000', 'next_check_at' => '1.000000',
            'actor' => 'ops@example.com', 'source' => 'dashboard', 'created_at' => '1790000001.500000', 'updated_at' => '1790000001.500000',
        ]);

        $running = $this->list(['status' => 'running'])->json('jobs.0');
        self::assertSame(self::uuidOf(1), $running['id']);
        self::assertSame(['a-tag', 'b-tag'], $running['tags']);
        self::assertEqualsWithDelta($now - 120 + 90, $running['lease_expires_at'], 0.01);
        self::assertSame('host:1:1', $running['worker']['id']);
        self::assertSame('silent', $running['worker']['liveness']);
        self::assertEqualsWithDelta($now - 30, $running['worker']['silent_since'], 0.01, 'max(lease end, heartbeat + 60)');
        self::assertArrayHasKey('last_verified', $this->list(['status' => 'running'])->json());

        $failed = $this->list(['status' => 'failed'])->json('jobs.0');
        self::assertSame(['id' => str_repeat('a', 32), 'action' => 'retry', 'state' => 'claimed', 'awaiting_reason' => null, 'actor' => 'ops@example.com', 'created_at' => 1790000001.5], $failed['operation']);
        self::assertNull($failed['worker']);
        self::assertSame(2, $failed['occurrence_id']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidRequests(): array
    {
        return [
            'no status' => [[], 'status'],
            'unknown status' => [['status' => 'stuck'], 'status'],
            'all without a tag' => [['status' => 'all'], 'status'],
            'queue without connection' => [['status' => 'pending', 'queue' => 'default'], 'queue'],
            'an array' => [['status' => 'pending', 'class' => ['a']], 'class'],
            'q too short' => [['status' => 'pending', 'q' => 'ab'], 'q'],
            'q too long' => [['status' => 'pending', 'q' => str_repeat('a', 101)], 'q'],
            'per_page 0' => [['status' => 'pending', 'per_page' => '0'], 'per_page'],
            'per_page 101' => [['status' => 'pending', 'per_page' => '101'], 'per_page'],
            'from after to' => [['status' => 'pending', 'from' => '20', 'to' => '10'], 'from'],
            'from not a number' => [['status' => 'pending', 'from' => '1e9'], 'from'],
            'order' => [['status' => 'pending', 'order' => 'random'], 'order'],
            'batch with all' => [['status' => 'all', 'tag' => 'x', 'batch' => 'b'], 'batch'],
            'batch too long' => [['status' => 'pending', 'batch' => str_repeat('b', 256)], 'batch'],
            'invalid utf-8' => [['status' => 'pending', 'class' => "\xff"], 'class'],
        ];
    }

    /**
     * Rejects: a request that a rule refuses answered as a list, or a 422
     * without the field.
     *
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('invalidRequests')]
    public function test_an_invalid_request_answers_422_with_the_field(array $query, string $field): void
    {
        $this->actingAs(new User)->getJson('/orrery/api/jobs?'.http_build_query($query))
            ->assertStatus(422)
            ->assertJson(['error' => 'invalid', 'field' => $field]);
    }

    /**
     * Rejects: a keyset page that drops the from or to bound (a later page
     * of a time range that runs past it), in both orders, also on a
     * residual scan.
     */
    public function test_from_and_to_hold_on_every_page_in_both_orders(): void
    {
        for ($n = 1; $n <= 30; $n++) {
            $this->addRow($n, 'completed', $n, ['silenced' => $n % 2 === 0, 'queue' => 'emails']);
        }

        $this->flushRows();
        $bounds = ['from' => (string) (self::BASE + 5), 'to' => (string) (self::BASE + 25)];

        foreach ([['status' => 'completed'], ['status' => 'completed', 'connection' => 'database', 'queue' => 'emails'], ['status' => 'completed', 'class' => 'App\\Jobs\\Sync']] as $filters) {
            foreach (['desc' => range(23, 5, -2), 'asc' => range(5, 23, 2)] as $order => $expected) {
                $query = $filters + $bounds + ['order' => $order, 'per_page' => 3];
                $seen = [];
                $cursor = null;
                $guard = 0;

                do {
                    $response = $this->list($query + ($cursor === null ? [] : ['cursor' => $cursor]));
                    $seen = [...$seen, ...array_column($response->json('jobs'), 'id')];
                    $cursor = $response->json('next_cursor');
                } while ($cursor !== null && ++$guard < 50);

                self::assertSame(array_map(self::uuidOf(...), $expected), $seen, json_encode($filters).' '.$order);
            }
        }
    }

    /**
     * Rejects: a list that shows a row without an order value on its first
     * page (no keyset can page past it) or counts it.
     */
    public function test_a_row_without_its_order_value_is_left_out(): void
    {
        $this->addRow(1, 'reserved', 1);
        $this->addRow(2, 'reserved', 2, ['reserved_at' => null]);
        $this->flushRows();

        foreach (['desc', 'asc'] as $order) {
            $response = $this->list(['status' => 'running', 'order' => $order]);
            self::assertSame([self::uuidOf(1)], array_column($response->json('jobs'), 'id'));
            self::assertSame(['count' => 1, 'at_least' => false], $response->json('total'));
        }
    }

    /**
     * Rejects: from/to that are not half-open on the tab's order column.
     */
    public function test_from_and_to_are_half_open(): void
    {
        for ($n = 1; $n <= 5; $n++) {
            $this->addRow($n, 'completed', $n * 10);
        }

        $this->flushRows();
        $response = $this->list(['status' => 'completed', 'from' => (string) (self::BASE + 20), 'to' => (string) (self::BASE + 40)]);
        self::assertSame([self::uuidOf(3), self::uuidOf(2)], array_column($response->json('jobs'), 'id'));
        self::assertSame(['count' => 2, 'at_least' => false], $response->json('total'));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function list(array $query): TestResponse
    {
        return $this->actingAs(new User)->getJson('/orrery/api/jobs?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986))->assertOk();
    }
}
