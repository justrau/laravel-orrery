<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Database\Events\QueryExecuted;
use Justrau\Orrery\Http\Tags;
use Justrau\Orrery\Monitoring\MonitoringVersion;
use Justrau\Orrery\Observation\TagNormalizer;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Support\FreshSchema;

/**
 * The tags API: `GET /api/tags`, `POST
 * /api/tags/monitored` and `DELETE /api/tags/monitored/{tag}`. A tag's jobs
 * are the Jobs list with `tag=` (the list engine), not this API.
 */
final class TagsControllerTest extends ControllerTest
{
    private const API = '/orrery/api/tags';

    /**
     * Rejects: an index that returns the legacy Horizon shape (plain
     * integers), counts that add the failed tags to `count`, a list in
     * insertion order, and a missing `truncated` or `lookup`.
     */
    public function test_the_index_lists_the_monitored_tags_with_capped_counts_ordered_by_tag(): void
    {
        $this->monitored('shipments', 'orders');
        $this->jobTags('orders', 2);
        $this->failedTags('orders', 1);
        $this->jobTags('shipments', 1);

        $this->actingAs(new User)->getJson(self::API)
            ->assertOk()
            ->assertExactJson([
                'tags' => [
                    ['tag' => 'orders', 'count' => ['count' => 2, 'at_least' => false], 'failed' => ['count' => 1, 'at_least' => false]],
                    ['tag' => 'shipments', 'count' => ['count' => 1, 'at_least' => false], 'failed' => ['count' => 0, 'at_least' => false]],
                ],
                'truncated' => false,
                'lookup' => null,
            ]);
    }

    public function test_no_monitored_tag_gives_an_empty_list(): void
    {
        $this->actingAs(new User)->getJson(self::API)
            ->assertOk()
            ->assertExactJson(['tags' => [], 'truncated' => false, 'lookup' => null]);
    }

    /**
     * Rejects: an uncapped count (the legacy MonitoringController, now gone,
     * counted every row), a cap that says `at_least` at exactly the cap, and a cap other
     * than 1,000.
     */
    public function test_the_counts_stop_at_the_cap_and_say_at_least(): void
    {
        $this->monitored('at-cap', 'over-cap');
        $this->jobTags('at-cap', Tags::COUNT_CAP);
        $this->failedTags('at-cap', Tags::COUNT_CAP);
        $this->jobTags('over-cap', Tags::COUNT_CAP + 1);
        $this->failedTags('over-cap', Tags::COUNT_CAP + 1);

        $response = $this->actingAs(new User)->getJson(self::API)->assertOk();

        self::assertSame(1000, Tags::COUNT_CAP);
        self::assertSame(['count' => 1000, 'at_least' => false], $response->json('tags.0.count'));
        self::assertSame(['count' => 1000, 'at_least' => false], $response->json('tags.0.failed'));
        self::assertSame('over-cap', $response->json('tags.1.tag'));
        self::assertSame(['count' => 1000, 'at_least' => true], $response->json('tags.1.count'));
        self::assertSame(['count' => 1000, 'at_least' => true], $response->json('tags.1.failed'));
    }

    /**
     * Rejects: an unlimited tag list, a limit other than 100, and
     * `truncated` true at exactly 100 tags.
     */
    public function test_the_list_stops_at_100_tags_and_says_truncated(): void
    {
        $tags = array_map(static fn (int $n): string => sprintf('tag-%03d', $n), range(1, 100));
        $this->monitored(...$tags);

        $response = $this->actingAs(new User)->getJson(self::API)->assertOk();
        self::assertCount(100, $response->json('tags'));
        self::assertFalse($response->json('truncated'));

        $this->monitored('tag-101');
        $response = $this->actingAs(new User)->getJson(self::API)->assertOk();
        self::assertSame(100, Tags::MAX_TAGS);
        self::assertCount(100, $response->json('tags'));
        self::assertTrue($response->json('truncated'));
        self::assertSame('tag-001', $response->json('tags.0.tag'));
        self::assertSame('tag-100', $response->json('tags.99.tag'));
    }

    /**
     * Tags are compared byte for byte: case, accents and a trailing space
     * that a collation folds or pads stay apart (the trailing-space row is
     * written directly: the normalizer never stores one).
     *
     * Rejects: a count with the collated equality alone (MySQL's
     * utf8mb4_bin pads trailing spaces, so `orders ` counts for `orders`),
     * and a case-insensitive comparison.
     */
    public function test_the_counts_are_byte_exact(): void
    {
        $this->monitored('orders', 'Orders', 'ordérs');
        $this->jobTags('orders', 2);
        $this->jobTags('orders ', 3, 100);
        $this->jobTags('ORDERS', 4, 200);
        $this->failedTags('orders ', 5, 300);

        $response = $this->actingAs(new User)->getJson(self::API)->assertOk();
        $counts = [];

        foreach ((array) $response->json('tags') as $row) {
            $counts[$row['tag']] = [$row['count']['count'], $row['failed']['count']];
        }

        // The order of the list is the tag index's (the database collation on Postgres): compare by key.
        ksort($counts, SORT_STRING);
        self::assertSame(['Orders' => [0, 0], 'orders' => [2, 0], 'ordérs' => [0, 0]], $counts);
    }

    /**
     * Rejects: a failed count that answers 0 when its read fails (unknown is
     * never zero), and a failed count that fails the whole response.
     */
    #[FreshSchema]
    public function test_a_count_whose_read_fails_is_unknown_not_zero(): void
    {
        $this->monitored('orders');
        $this->jobTags('orders', 2);
        $this->mirrorSchema()->drop('orrery_failed_tags');

        $this->actingAs(new User)->getJson(self::API)
            ->assertOk()
            ->assertExactJson([
                'tags' => [['tag' => 'orders', 'count' => ['count' => 2, 'at_least' => false], 'failed' => ['count' => null, 'at_least' => false]]],
                'truncated' => false,
                'lookup' => null,
            ]);
    }

    /**
     * Rejects: an empty list (a definite "no monitored tag") when the
     * monitored tags cannot be read.
     */
    #[FreshSchema]
    public function test_an_unreadable_tag_list_answers_503(): void
    {
        $this->mirrorSchema()->drop('orrery_monitored_tags');

        $this->actingAs(new User)->getJson(self::API)
            ->assertStatus(503)
            ->assertJsonPath('error', 'read_failed')
            ->assertJsonStructure(['error', 'message']);
    }

    /**
     * The tag view asks for the monitor state of one tag; the tag can be
     * beyond the first 100.
     *
     * Rejects: a lookup that reads the monitor state from the truncated
     * list, one that compares the raw value instead of its stored form, and
     * one that folds case.
     */
    public function test_the_lookup_gives_the_monitor_state_of_one_tag(): void
    {
        $this->monitored(...array_map(static fn (int $n): string => sprintf('tag-%03d', $n), range(1, 101)));
        $this->monitored('Orders');

        $this->actingAs(new User)->getJson(self::API.'?tag=tag-101')
            ->assertOk()
            ->assertJsonPath('truncated', true)
            ->assertJsonPath('lookup', ['tag' => 'tag-101', 'monitored' => true]);

        $this->actingAs(new User)->getJson(self::API.'?tag=%20Orders%20')
            ->assertOk()
            ->assertJsonPath('lookup', ['tag' => 'Orders', 'monitored' => true]);

        $this->actingAs(new User)->getJson(self::API.'?tag=orders')
            ->assertOk()
            ->assertJsonPath('lookup', ['tag' => 'orders', 'monitored' => false]);
    }

    /**
     * Rejects: a lookup of an empty, blank, array or invalid-UTF-8 value
     * that answers "not monitored" instead of 422.
     */
    public function test_an_invalid_lookup_tag_answers_422(): void
    {
        foreach (['tag=', 'tag=%20%20', 'tag[]=x', 'tag=%FF'] as $query) {
            $this->actingAs(new User)->getJson(self::API.'?'.$query)
                ->assertStatus(422)
                ->assertExactJson(['error' => 'invalid', 'message' => 'The tag must be a non-empty text.', 'field' => 'tag']);
        }
    }

    /**
     * Rejects: a monitor that queues its change instead of committing it
     * before the response, one that stores the raw value instead of its
     * normalised form, and one that does not move the monitoring version.
     */
    public function test_monitor_commits_the_normalised_tag_synchronously(): void
    {
        $version = $this->version();

        $this->actingAs(new User)->postJson(self::API.'/monitored', ['tag' => "  orders\t"])
            ->assertOk()
            ->assertExactJson(['tag' => 'orders', 'monitored' => true, 'changed' => true]);

        self::assertSame(['orders'], $this->mirror()->table('orrery_monitored_tags')->pluck('tag')->all());
        self::assertSame($version + 1, $this->version());

        $this->actingAs(new User)->postJson(self::API.'/monitored', ['tag' => 'orders'])
            ->assertOk()
            ->assertExactJson(['tag' => 'orders', 'monitored' => true, 'changed' => false]);
        self::assertSame($version + 1, $this->version());
    }

    /**
     * Rejects: a monitor of an empty or non-string tag that answers 200 (as
     * the legacy POST /api/monitoring did) or stores anything.
     */
    public function test_monitor_of_an_invalid_tag_answers_422_and_changes_nothing(): void
    {
        foreach ([['tag' => ''], ['tag' => '   '], ['tag' => ['x']], ['tag' => 5], []] as $body) {
            $this->actingAs(new User)->postJson(self::API.'/monitored', $body)
                ->assertStatus(422)
                ->assertExactJson(['error' => 'invalid', 'message' => 'The tag must be a non-empty text.', 'field' => 'tag']);
        }

        self::assertSame(0, $this->mirror()->table('orrery_monitored_tags')->count());
    }

    /**
     * Rejects: an unmonitor that cannot reach a tag with a slash or a
     * space (a route without `where tag .*`), one that removes another tag,
     * and one that reports a change for a tag that was not monitored.
     */
    public function test_unmonitor_removes_exactly_that_tag(): void
    {
        $this->monitored('team/a b', 'team/a', 'Team/a b');
        $version = $this->version();

        $this->actingAs(new User)->deleteJson(self::API.'/monitored/'.rawurlencode('team/a b'))
            ->assertOk()
            ->assertExactJson(['tag' => 'team/a b', 'monitored' => false, 'changed' => true]);

        $remaining = $this->mirror()->table('orrery_monitored_tags')->pluck('tag')->all();
        sort($remaining, SORT_STRING);
        self::assertSame(['Team/a b', 'team/a'], $remaining);
        self::assertSame($version + 1, $this->version());

        $this->actingAs(new User)->deleteJson(self::API.'/monitored/'.rawurlencode('team/a b'))
            ->assertOk()
            ->assertExactJson(['tag' => 'team/a b', 'monitored' => false, 'changed' => false]);
    }

    /**
     * Rejects: an unmonitor of a blank tag that answers 200.
     */
    public function test_unmonitor_of_a_blank_tag_answers_422(): void
    {
        $this->actingAs(new User)->deleteJson(self::API.'/monitored/%20%20')
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid')
            ->assertJsonPath('field', 'tag');
    }

    /**
     * Rejects: a monitor whose write fails but that answers 200 (the
     * operator would believe the tag is monitored).
     */
    #[FreshSchema]
    public function test_a_failed_monitor_write_answers_503(): void
    {
        $this->mirrorSchema()->drop('orrery_monitored_tags');

        $this->actingAs(new User)->postJson(self::API.'/monitored', ['tag' => 'orders'])
            ->assertStatus(503)
            ->assertJsonPath('error', 'write_failed')
            ->assertJsonStructure(['error', 'message']);

        $this->actingAs(new User)->deleteJson(self::API.'/monitored/orders')
            ->assertStatus(503)
            ->assertJsonPath('error', 'write_failed');

        $this->actingAs(new User)->deleteJson(self::API.'/monitored?tag=orders')
            ->assertStatus(503)
            ->assertJsonPath('error', 'write_failed');
    }

    /**
     * A tag that ends or starts with a non-ASCII space keeps it: the
     * application's TrimStrings middleware strips NBSP and U+3000, the
     * TagNormalizer does not, so the stored form is the normalizer's.
     *
     * Rejects: a store that reads $request->input() behind TrimStrings (it
     * monitors `orders` for "orders\u{00A0}").
     */
    public function test_monitor_reads_the_raw_body_past_trim_strings(): void
    {
        foreach (["orders\u{00A0}", "\u{3000}orders"] as $input) {
            $expected = TagNormalizer::normalize($input);
            self::assertNotSame('orders', $expected);

            $this->actingAs(new User)->postJson(self::API.'/monitored', ['tag' => $input])
                ->assertOk()
                ->assertJsonPath('tag', $expected)
                ->assertJsonPath('changed', true);
        }

        $stored = $this->mirror()->table('orrery_monitored_tags')->pluck('tag')->all();
        sort($stored, SORT_STRING);
        $expected = [TagNormalizer::normalize("orders\u{00A0}"), TagNormalizer::normalize("\u{3000}orders")];
        sort($expected, SORT_STRING);
        self::assertSame($expected, $stored);
        self::assertNotContains('orders', $stored);
    }

    /**
     * Rejects: a tag with a NUL byte that reaches SQL (Postgres cuts text at
     * NUL, so DELETE /tags/monitored/a%00b removed `a` there).
     */
    public function test_a_tag_with_a_nul_byte_answers_422_everywhere(): void
    {
        $this->monitored('a');
        $invalid = ['error' => 'invalid', 'message' => 'The tag must be a non-empty text.', 'field' => 'tag'];

        $this->actingAs(new User)->getJson(self::API.'?tag=a%00b')->assertStatus(422)->assertExactJson($invalid);
        $this->actingAs(new User)->postJson(self::API.'/monitored', ['tag' => "a\0b"])->assertStatus(422)->assertExactJson($invalid);
        $this->actingAs(new User)->postJson(self::API.'/monitored', ['tag' => "a\0"])->assertStatus(422)->assertExactJson($invalid);
        $this->actingAs(new User)->deleteJson(self::API.'/monitored/a%00b')->assertStatus(422)->assertExactJson($invalid);
        $this->actingAs(new User)->deleteJson(self::API.'/monitored?tag=a%00b')->assertStatus(422)->assertExactJson($invalid);

        self::assertSame(['a'], $this->mirror()->table('orrery_monitored_tags')->pluck('tag')->all());
    }

    /**
     * The query form of unmonitor reaches the tags that no path can carry
     * ("." and ".." are resolved by clients, "%2F" is refused by Apache).
     *
     * Rejects: a query form that reads the tag through $request->query()
     * behind TrimStrings, and one that answers without removing the tag.
     */
    public function test_unmonitor_by_query_reaches_dot_tags(): void
    {
        $this->monitored('..', '.', "x\u{00A0}");

        $this->actingAs(new User)->deleteJson(self::API.'/monitored?tag=..')
            ->assertOk()
            ->assertExactJson(['tag' => '..', 'monitored' => false, 'changed' => true]);
        $this->actingAs(new User)->deleteJson(self::API.'/monitored?tag='.rawurlencode("x\u{00A0}"))
            ->assertOk()
            ->assertJsonPath('changed', true);
        $this->actingAs(new User)->deleteJson(self::API.'/monitored')
            ->assertStatus(422)
            ->assertJsonPath('field', 'tag');

        self::assertSame(['.'], $this->mirror()->table('orrery_monitored_tags')->pluck('tag')->all());
    }

    /**
     * After the first count that fails, the other counts of the request are
     * unknown without a read, so a failing mirror costs one statement
     * timeout, not 200.
     *
     * Rejects: a request that keeps sending count statements after a count
     * failed, and one that answers 0 for the skipped counts.
     */
    #[FreshSchema]
    public function test_after_a_failed_count_no_further_count_runs(): void
    {
        $this->monitored('a', 'b');
        $this->jobTags('a', 2);
        $this->jobTags('b', 3, 100);
        $this->mirrorSchema()->drop('orrery_failed_tags');
        $counts = 0;
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$counts): void {
            $counts += str_contains($event->sql, 'capped_rows') ? 1 : 0;
        });

        $this->actingAs(new User)->getJson(self::API)
            ->assertOk()
            ->assertExactJson([
                'tags' => [
                    ['tag' => 'a', 'count' => ['count' => 2, 'at_least' => false], 'failed' => ['count' => null, 'at_least' => false]],
                    ['tag' => 'b', 'count' => ['count' => null, 'at_least' => false], 'failed' => ['count' => null, 'at_least' => false]],
                ],
                'truncated' => false,
                'lookup' => null,
            ]);

        // The failed count raised no QueryExecuted: only a's job count ran.
        self::assertSame(1, $counts);
    }

    /**
     * Rejects: a 503 for the whole response (or a "not monitored") when only
     * the lookup cannot be read.
     */
    public function test_a_failed_lookup_is_unknown_and_keeps_the_list(): void
    {
        $this->monitored('orders');
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event): void {
            if (str_contains($event->sql, 'orrery_monitored_tags') && str_contains($event->sql, 'limit 2')) {
                throw new \RuntimeException('lookup failed');
            }
        });

        $this->actingAs(new User)->getJson(self::API.'?tag=orders')
            ->assertOk()
            ->assertJsonPath('tags.0.tag', 'orders')
            ->assertJsonPath('lookup', ['tag' => 'orders', 'monitored' => null]);
    }

    private function monitored(string ...$tags): void
    {
        foreach ($tags as $tag) {
            $this->mirror()->table('orrery_monitored_tags')->insert(['tag' => $tag, 'created_at' => Clock::toDecimal(Clock::now())]);
        }
    }

    private function jobTags(string $tag, int $count, int $offset = 0): void
    {
        $rows = [];

        for ($n = 1; $n <= $count; $n++) {
            $rows[] = ['tag' => $tag, 'job_uuid' => sprintf('00000000-0000-4000-8000-%012d', $offset + $n), 'pushed_at' => Clock::toDecimal(1_790_000_000 + $n)];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $this->mirror()->table('orrery_job_tags')->insert($chunk);
        }
    }

    private function failedTags(string $tag, int $count, int $offset = 0): void
    {
        $rows = [];

        for ($n = 1; $n <= $count; $n++) {
            $rows[] = ['tag' => $tag, 'job_uuid' => sprintf('00000000-0000-4000-8000-%012d', $offset + $n), 'finished_at' => Clock::toDecimal(1_790_000_000 + $n)];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $this->mirror()->table('orrery_failed_tags')->insert($chunk);
        }
    }

    private function version(): int
    {
        return app(MonitoringVersion::class)->current($this->mirror());
    }
}
