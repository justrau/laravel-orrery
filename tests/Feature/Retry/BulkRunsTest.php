<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retry;

use Illuminate\Queue\Failed\FileFailedJobProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Justrau\Orrery\Failures\GroupKey;
use Justrau\Orrery\Http\Bulk\BulkRun;
use Justrau\Orrery\Http\Bulk\BulkSource;
use Justrau\Orrery\Http\Bulk\FrozenToken;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Retry\Phases\OperationRetentionPhase;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use Justrau\Orrery\Tests\Controller\Fakes\User;

/**
 * The bulk runs (section 11 "Bulk runs"): the signed freeze,
 * the frozen pages through the ledger services, the audit row and its
 * reads. The members are seeded failed rows under the null failer (a
 * forget is mode none, a retry publishes the row's payload), or real
 * failures where the case needs the failed-job provider.
 */
final class BulkRunsTest extends RetryTestCase
{
    private const APP_KEY = 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=';

    private const KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private int $seeded = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('app.key', self::APP_KEY);
        Orrery::auth(static fn (): bool => true);
        Orrery::operateUsing(static fn (): bool => true);
    }

    /**
     * Rejects: a retention delete that binds every ref of a full run: 1,000 refs and the cutoff are
     * 1,001 variables, above the 999 of SQLite before 3.32. Each delete binds at most DELETE_CHUNK refs and the
     * cutoff, and every old run goes.
     * Still passes: a chunk size above 999 on an engine whose limit is higher (the bound is checked only here).
     */
    public function test_the_retention_of_a_full_batch_deletes_in_chunks(): void
    {
        $rows = [];

        for ($n = 0; $n < \Justrau\Orrery\Http\Bulk\BulkRuns::RETENTION_ROWS; $n++) {
            $rows[] = [
                'ref' => sprintf('%032x', $n + 1), 'action' => 'retry', 'source' => '{}', 'source_hash' => str_repeat('0', 64),
                'max_occurrence' => 1, 'total' => 0, 'created_at' => '100.000000', 'last_run_at' => '100.000000',
            ];
        }

        foreach (array_chunk($rows, 50) as $chunk) {
            $this->mirror()->table(\Justrau\Orrery\Http\Bulk\BulkRuns::TABLE)->insert($chunk);
        }

        $deletes = [];
        $this->mirror()->listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$deletes): void {
            if (str_starts_with(strtolower($query->sql), 'delete from')) {
                $deletes[] = count($query->bindings);
            }
        });

        self::assertSame(\Justrau\Orrery\Http\Bulk\BulkRuns::RETENTION_ROWS, \Justrau\Orrery\Http\Bulk\BulkRuns::deleteOlderThan($this->mirror(), 200.0));
        self::assertSame(0, $this->mirror()->table(\Justrau\Orrery\Http\Bulk\BulkRuns::TABLE)->count());
        self::assertLessThanOrEqual(999, max($deletes), 'SQLite before 3.32 takes at most 999 variables');
    }

    /**
     * Rejects: a run that repeats or skips a member across pages, a page of
     * more than 200 members, a `done` before the last page, and counters or
     * claims that differ from the work (S-13: the audit row and the ledger
     * agree after the run).
     *
     * The clock of the page deadline (BulkRun::PAGE_SECONDS on
     * Clock::monotonic()) is frozen: on a loaded machine a page ended at its
     * deadline after 193 or 197 members (wave-B matrix), so the page sizes
     * measured the machine, not the run.
     */
    public function test_450_members_are_processed_once_over_three_pages(): void
    {
        Clock::freezeMonotonicForTesting();
        $this->useNullFailer();
        $uuids = $this->seedFailures(450, ['queue' => 'bulk']);
        $frozen = $this->freeze('forget', $this->listSource(['connection' => 'database', 'queue' => 'bulk']))->assertOk();

        self::assertSame(['count' => 450, 'at_least' => false], $frozen->json('frozen.total'));
        self::assertTrue($frozen->json('frozen.exact'));
        self::assertCount(BulkRun::PREVIEW, (array) $frozen->json('preview'));

        $pages = $this->runAll($frozen, 450);

        self::assertSame([200, 200, 50], array_map(static fn (array $page): int => count($page['results']), $pages));
        self::assertSame([false, false, true], array_column($pages, 'done'));
        self::assertNull($pages[2]['next_after']);
        self::assertSame(['count' => 0, 'at_least' => false], $pages[2]['remaining']);
        self::assertSame(['count' => 250, 'at_least' => false], $pages[0]['remaining']);
        $done = array_merge(...array_map(static fn (array $page): array => array_column($page['results'], 'id'), $pages));
        sort($done);
        $expected = $uuids;
        sort($expected);
        self::assertSame($expected, $done, 'every member once');
        self::assertSame(0, $this->mirror()->table('orrery_jobs')->where('queue', 'bulk')->count());

        $run = $this->actingAs(new User)->getJson('/orrery/api/bulk/'.$frozen->json('frozen.ref'))->assertOk();
        self::assertSame(450, $run->json('claimed'));
        self::assertSame(450, $run->json('confirmed_total'));
        self::assertSame(['accepted' => 450, 'refused' => 0, 'uncertain' => 0, 'not_member' => 0, 'already_done' => 0, 'not_confirmed' => 0], $run->json('counters'));
        self::assertSame(['claimed' => 0, 'published' => 0, 'done' => 450, 'aborting' => 0, 'aborted' => 0], $run->json('operations'));
        self::assertSame(450, $this->mirror()->table('orrery_operations')->where('bulk_ref', $frozen->json('frozen.ref'))->where('source', 'bulk')->count());
    }

    /**
     * Rejects: a display-only remaining count whose error fails the page after its members acted: the page
     * answers 200 with its results and `remaining` {count: null, at_least: false}.
     */
    public function test_a_failed_remaining_count_keeps_the_page_results(): void
    {
        Clock::freezeMonotonicForTesting();
        $this->useNullFailer();
        $this->seedFailures(5, ['queue' => 'slow']);
        $frozen = $this->freeze('retry', $this->listSource(['connection' => 'database', 'queue' => 'slow']))->assertOk();
        $between = 0;
        Hooks::$callback = static function (string $point) use (&$between): void {
            if ($point === BulkRun::BETWEEN_MEMBERS && ++$between === 2) {
                Clock::advanceMonotonicForTesting(BulkRun::PAGE_SECONDS + 1);
            }

            if ($point === BulkRun::BEFORE_REMAINING) {
                throw new \RuntimeException('The count broke.');
            }
        };

        try {
            $page = $this->runPage($frozen, null, null)->assertOk()->json();
        } finally {
            Hooks::$callback = null;
        }

        self::assertIsArray($page);
        self::assertCount(3, $page['results']);
        self::assertFalse($page['done']);
        self::assertSame(['count' => null, 'at_least' => false], $page['remaining']);
    }

    /**
     * Rejects: a page that answers 503 when a read fails after members were acted on: the browser
     * lost their results. The page answers 200 with the results so far, not done, and the next page goes on
     * after the last member, so every member is acted on once. A failure before any member still fails.
     * Still passes: a page that keeps its results but repeats the failing member on the next page (its
     * service answers already_done).
     */
    public function test_a_failed_read_after_members_keeps_the_page_results(): void
    {
        Clock::freezeMonotonicForTesting();
        $this->useNullFailer();
        $uuids = $this->seedFailures(5, ['queue' => 'slow']);
        $frozen = $this->freeze('retry', $this->listSource(['connection' => 'database', 'queue' => 'slow']))->assertOk();
        $between = 0;
        Hooks::$callback = static function (string $point) use (&$between): void {
            if ($point === BulkRun::BETWEEN_MEMBERS && ++$between === 2) {
                throw new \RuntimeException('The scan broke.');
            }
        };

        try {
            $first = $this->runPage($frozen, null, null)->assertOk()->json();
        } finally {
            Hooks::$callback = null;
        }

        self::assertIsArray($first);
        self::assertCount(2, $first['results']);
        self::assertFalse($first['done']);
        self::assertNotNull($first['next_after']);

        $seen = array_column($first['results'], 'id');
        $after = $first['next_after'];

        for ($pages = 0; $pages < 10; $pages++) {
            $page = $this->runPage($frozen, $after, null)->assertOk()->json();
            self::assertIsArray($page);
            $seen = [...$seen, ...array_column($page['results'], 'id')];
            $after = $page['next_after'];

            if ($page['done']) {
                break;
            }
        }

        sort($seen);
        sort($uuids);
        self::assertSame($uuids, $seen, 'every member once');
    }

    /**
     * Rejects: a page that ignores its deadline (BulkRun::PAGE_SECONDS), so
     * a slow page holds the request past it; a page that ends at the
     * deadline without a cursor, or marks the run done; a next page that
     * repeats the members of the stopped page or skips one after it.
     */
    public function test_a_page_ends_at_its_deadline_and_the_next_page_resumes(): void
    {
        Clock::freezeMonotonicForTesting();
        $this->useNullFailer();
        // The run takes the members newest first.
        $uuids = array_reverse($this->seedFailures(5, ['queue' => 'slow']));
        $frozen = $this->freeze('retry', $this->listSource(['connection' => 'database', 'queue' => 'slow']))->assertOk();
        $between = 0;
        // Before the third member, the page's time passes its deadline.
        Hooks::$callback = static function (string $point) use (&$between): void {
            if ($point === BulkRun::BETWEEN_MEMBERS && ++$between === 2) {
                Clock::advanceMonotonicForTesting(BulkRun::PAGE_SECONDS + 1);
            }
        };

        $first = $this->runPage($frozen, null, null)->assertOk()->json();
        Hooks::$callback = null;

        self::assertIsArray($first);
        self::assertSame(array_slice($uuids, 0, 3), array_column($first['results'], 'id'), 'the member that started before the deadline ends the page');
        self::assertFalse($first['done']);
        self::assertIsString($first['next_after']);

        $after = $first['next_after'];
        $rest = [];

        do {
            $page = $this->runPage($frozen, $after, null)->assertOk()->json();
            self::assertIsArray($page);
            array_push($rest, ...array_column($page['results'], 'id'));
            $after = $page['next_after'];
            self::assertLessThan(10, count($rest) + 1, 'the run ends');
        } while (! $page['done']);

        self::assertSame(array_slice($uuids, 3), $rest, 'the next pages resume after the cursor: no member twice, none skipped');
    }

    /**
     * Rejects: a max_occurrence derived from the members (D-5): a failure of
     * the source whose occurrence is above every member that the freeze saw,
     * but below the global maximum read before them, is still a member; a
     * failure with a larger id than the global maximum is never one.
     */
    public function test_max_occurrence_is_the_global_maximum_read_before_the_members(): void
    {
        $this->useNullFailer();
        $members = $this->seedFailures(2, ['queue' => 'q'], occurrence: 10);
        $this->seedFailures(1, ['queue' => 'other'], occurrence: 50);
        $frozen = $this->freeze('retry', $this->listSource(['connection' => 'database', 'queue' => 'q']))->assertOk();

        self::assertSame(50, $frozen->json('frozen.max_occurrence'));
        self::assertSame(2, $frozen->json('frozen.total.count'));
        [$inFlight] = $this->seedFailures(1, ['queue' => 'q'], occurrence: 20);
        [$later] = $this->seedFailures(1, ['queue' => 'q'], occurrence: 60);

        $page = $this->runPage($frozen, null, null)->assertOk();
        $outcomes = array_column((array) $page->json('results'), 'outcome', 'id');
        ksort($outcomes);

        self::assertSame([$members[0] => 'accepted', $members[1] => 'accepted', $inFlight => 'accepted'], $outcomes);
        self::assertTrue($page->json('done'));
        self::assertSame('failed', $this->rowOrFail($later)->status, 'a failure after the freeze is never acted on');
    }

    /**
     * Rejects: a run that acts again on a member that failed again after its
     * retry (a new occurrence, allocated after the freeze), also when its new
     * failure sorts below the cursor (a producer clock behind).
     */
    public function test_a_member_that_fails_again_is_not_retried_again_by_the_run(): void
    {
        [$a, $occurrenceA] = $this->failedJob($this->job(failRuns: 2));
        [$b] = $this->failedJob();
        $frozen = $this->freeze('retry', $this->listSource(['connection' => 'database', 'queue' => 'default']))->assertOk();
        self::assertSame(2, $frozen->json('frozen.total.count'));

        // The first page stops after its first member (the newest, b).
        $first = $this->app->make(BulkRun::class)->run($frozen->json('frozen.token'), null, null, 'operator', null, $this->stopAfter(1));
        self::assertSame([$b], array_column($first['results'], 'id'));
        self::assertFalse($first['done']);

        $second = $this->app->make(BulkRun::class)->run($frozen->json('frozen.token'), $first['next_after'], null, 'operator', null, $this->stopAfter(1));
        self::assertSame([[$a, 'accepted']], array_map(static fn (array $item): array => [$item['id'], $item['outcome']], $second['results']));

        // b's retry completes and a fails again: a new occurrence; its failure time is moved below the cursor.
        $this->work();
        $this->work();
        $jobs = DB::table('jobs')->count();
        $row = $this->rowOrFail($a);
        self::assertSame('failed', $row->status);
        self::assertGreaterThan($occurrenceA, (int) $row->occurrence_id);
        $this->mirror()->table('orrery_jobs')->where('uuid', $a)->update(['failed_at' => Clock::toDecimal(1_000_000.0), 'finished_at' => Clock::toDecimal(1_000_000.0)]);

        $third = $this->runPage($frozen, $second['next_after'], null)->assertOk();
        self::assertSame([], $third->json('results'));
        self::assertTrue($third->json('done'));
        self::assertSame('failed', $this->rowOrFail($a)->status, 'the new failure is not retried by this run');
        self::assertSame($jobs, DB::table('jobs')->count(), 'nothing was published again');
    }

    /**
     * Rejects: a freeze that leaves held rows out (S-5) and a run that acts
     * on a held member or reports it as done.
     */
    public function test_a_held_member_is_counted_and_answered_uncertain(): void
    {
        $this->useNullFailer();
        [$free, $held] = $this->seedFailures(2, ['queue' => 'held']);
        $this->mirror()->table('orrery_jobs')->where('uuid', $held)->update(['status' => 'retrying', 'operation_id' => str_repeat('c', 32)]);

        $frozen = $this->freeze('forget', $this->listSource(['connection' => 'database', 'queue' => 'held']))->assertOk();
        self::assertSame(['count' => 2, 'at_least' => false], $frozen->json('frozen.total'));
        self::assertSame(['count' => 1, 'at_least' => false], $frozen->json('held'));

        $page = $this->runPage($frozen, null, 2)->assertOk();
        $outcomes = array_column((array) $page->json('results'), 'outcome', 'id');
        self::assertSame(['accepted', 'uncertain'], [$outcomes[$free], $outcomes[$held]]);
        self::assertSame('operation_in_progress', collect($page->json('results'))->firstWhere('id', $held)['code']);
        self::assertSame('retrying', $this->rowOrFail($held)->status, 'a held member is not acted on');
    }

    /**
     * Rejects: a page whose examined rows are all non-members that answers
     * `done` or does not advance (S-7), and a cursor that skips the member
     * after them.
     */
    public function test_a_page_of_non_members_advances_and_is_not_done(): void
    {
        $this->useNullFailer();
        $this->app['config']->set('orrery.dashboard.search_candidates', 4);
        [$member] = $this->seedFailures(1, ['queue' => 'mixed', 'name' => 'App\\Jobs\\Wanted'], at: 1_700_000_000.0);
        $this->seedFailures(10, ['queue' => 'mixed', 'name' => 'App\\Jobs\\Other'], at: 1_700_001_000.0);

        $frozen = $this->freeze('retry', $this->listSource(['connection' => 'database', 'class' => 'App\\Jobs\\Wanted']))->assertOk();
        self::assertTrue($frozen->json('frozen.total.at_least'), 'the count is search limited');
        self::assertFalse($frozen->json('frozen.exact'));

        $pages = $this->runAll($frozen, null);

        self::assertGreaterThanOrEqual(3, count($pages));
        self::assertSame([], $pages[0]['results']);
        self::assertFalse($pages[0]['done']);
        self::assertIsString($pages[0]['next_after']);
        self::assertSame([$member], array_merge(...array_map(static fn (array $page): array => array_column($page['results'], 'id'), $pages)));
        self::assertTrue($pages[count($pages) - 1]['done']);
    }

    /**
     * Rejects: a forget whose total is capped or search limited (S-1), above
     * forget_max, with an empty app.key, or with an invalid forget_max.
     */
    public function test_a_forget_freeze_needs_an_exact_total_within_the_maximum(): void
    {
        $this->useNullFailer();
        $this->seedFailures(5, ['queue' => 'f', 'name' => 'App\\Jobs\\F']);
        $queue = $this->listSource(['connection' => 'database', 'queue' => 'f']);

        $this->app['config']->set('orrery.dashboard.list_count_cap', 3);
        $this->freeze('forget', $queue)->assertStatus(422)->assertJsonPath('error', 'total_not_exact');
        $this->freeze('retry', $queue)->assertOk()->assertJsonPath('frozen.total', ['count' => 3, 'at_least' => true])->assertJsonPath('frozen.exact', false);
        $this->app['config']->set('orrery.dashboard.list_count_cap', 10000);

        $this->app['config']->set('orrery.dashboard.search_candidates', 2);
        $this->freeze('forget', $this->listSource(['connection' => 'database', 'class' => 'App\\Jobs\\F']))->assertStatus(422)->assertJsonPath('error', 'total_not_exact');
        $this->app['config']->set('orrery.dashboard.search_candidates', 50000);

        $this->app['config']->set('orrery.bulk.forget_max', 4);
        $this->freeze('forget', $queue)->assertStatus(422)->assertJsonPath('error', 'too_many')->assertJsonPath('maximum', 4);
        $this->app['config']->set('orrery.bulk.forget_max', 5);
        $this->freeze('forget', $queue)->assertOk()->assertJsonPath('frozen.total.count', 5);

        foreach ([0, 200_000, '12x', -1, 1.5] as $invalid) {
            $this->app['config']->set('orrery.bulk.forget_max', $invalid);
            $this->freeze('forget', $queue)->assertStatus(422)->assertJsonPath('error', 'config_invalid');
            $this->freeze('retry', $queue)->assertOk();
        }

        $this->app['config']->set('orrery.bulk.forget_max', 10000);
        $this->app['config']->set('app.key', '');
        $this->freeze('forget', $queue)->assertStatus(422)->assertJsonPath('error', 'app_key_missing');
        $this->freeze('retry', $queue)->assertOk();
        self::assertSame(5, $this->mirror()->table('orrery_jobs')->where('queue', 'f')->count(), 'a freeze writes nothing');
        self::assertSame(0, $this->mirror()->table('orrery_bulk_runs')->count());
    }

    /**
     * Rejects: a token check that is not a signature (a changed byte, an
     * added key, another app key, a field rebuilt from parsed values), and a
     * server that reads a field from beside the token.
     */
    public function test_the_token_is_verified_and_echoed_verbatim(): void
    {
        $this->useNullFailer();
        $this->seedFailures(2, ['queue' => 't']);
        $frozen = $this->freeze('forget', $this->listSource(['connection' => 'database', 'queue' => 't']))->assertOk();
        $token = (string) $frozen->json('frozen.token');
        [$body, $signature] = explode('.', $token);

        $changed = $body;
        $changed[10] = $changed[10] === 'A' ? 'B' : 'A';
        $fields = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);
        $added = $fields + ['zz' => 1];
        ksort($added);

        foreach ([
            'a changed byte' => $changed.'.'.$signature,
            'another signature' => $body.'.'.strrev($signature),
            'an unknown key, signed' => $this->sign($added, self::APP_KEY),
            'another app key' => $this->sign($fields, 'base64:'.base64_encode(str_repeat('x', 32))),
            'not a token' => 'abc',
        ] as $case => $bad) {
            $response = $this->actingAs(new User)->postJson('/orrery/api/bulk/run', ['token' => $bad, 'after' => null, 'confirmed_total' => 2]);

            $response->assertStatus(422)->assertJsonPath('error', 'frozen_invalid');
        }

        // A client that parses and re-serializes the whole answer still echoes the token string.
        $answer = json_decode((string) json_encode(json_decode((string) $frozen->getContent(), true)), true);
        self::assertSame($token, $answer['frozen']['token']);
        // A total beside the token is for display only.
        $this->actingAs(new User)->postJson('/orrery/api/bulk/run', ['token' => $token, 'after' => null, 'confirmed_total' => 2, 'total' => ['count' => 1]])->assertOk();
    }

    /**
     * Rejects: an expiry from frozen_at alone (a long run would stop), no
     * expiry for an idle run, and a token older than an hour that starts a
     * run.
     */
    public function test_the_hour_rule_slides_with_the_run(): void
    {
        $this->useNullFailer();
        $this->seedFailures(3, ['queue' => 'e']);
        $source = $this->listSource(['connection' => 'database', 'queue' => 'e']);

        $stale = $this->freeze('retry', $source)->assertOk();
        $this->moveClock(3601);
        $this->runPage($stale, null, null)->assertStatus(422)->assertJsonPath('error', 'frozen_expired');
        self::assertSame(0, $this->mirror()->table('orrery_bulk_runs')->count());

        $frozen = $this->freeze('retry', $source)->assertOk();
        $first = $this->app->make(BulkRun::class)->run($frozen->json('frozen.token'), null, null, 'operator', null, $this->stopAfter(1));
        $this->moveClock(3000);
        $second = $this->app->make(BulkRun::class)->run($frozen->json('frozen.token'), $first['next_after'], null, 'operator', null, $this->stopAfter(1));
        self::assertCount(1, $second['results']);

        // More than an hour after frozen_at, less than an hour after the last page: the run goes on.
        $this->moveClock(3000);
        $third = $this->runPage($frozen, $second['next_after'], null)->assertOk();
        self::assertTrue($third->json('done'));

        // Idle for more than an hour: expired.
        $this->moveClock(3601);
        $this->runPage($frozen, null, null)->assertStatus(422)->assertJsonPath('error', 'frozen_expired');
    }

    /**
     * Rejects: a forget run without the exact confirmation, and a second
     * client that runs the same ref with other frozen fields.
     */
    public function test_a_confirmation_mismatch_and_a_frozen_mismatch(): void
    {
        $this->useNullFailer();
        $this->seedFailures(3, ['queue' => 'c']);
        $frozen = $this->freeze('forget', $this->listSource(['connection' => 'database', 'queue' => 'c']))->assertOk();

        foreach ([2, 4, '3', null] as $wrong) {
            $this->runPage($frozen, null, $wrong)->assertStatus(422)->assertJsonPath('error', 'confirmation_mismatch');
        }

        self::assertSame(0, $this->mirror()->table('orrery_bulk_runs')->count(), 'a refused request creates no run');
        $this->app->make(BulkRun::class)->run($frozen->json('frozen.token'), null, 3, 'operator', null, $this->stopAfter(1));

        // The same ref, signed by this server with another total and confirmation.
        $verified = FrozenToken::verify($frozen->json('frozen.token'), self::APP_KEY);
        $other = FrozenToken::freeze($verified->ref, 'forget', $verified->source, $verified->maxOccurrence, Clock::now(), ['count' => 2, 'at_least' => false], true)->encode(self::APP_KEY);
        $this->actingAs(new User)->postJson('/orrery/api/bulk/run', ['token' => $other, 'after' => null, 'confirmed_total' => 2])
            ->assertStatus(422)->assertJsonPath('error', 'frozen_mismatch');
        self::assertSame(2, $this->mirror()->table('orrery_jobs')->where('queue', 'c')->count(), 'the second client deleted nothing');
    }

    /**
     * Rejects: a forget bound checked in PHP from the client's own count
     * (two clients would each delete up to the total), instead of the slots
     * in the claim transactions (S-1): failures that join during the freeze
     * window are members, and the run still deletes at most its confirmed
     * total; the rest are not_confirmed.
     */
    public function test_two_clients_on_one_forget_ref_delete_at_most_the_confirmed_total(): void
    {
        $this->useNullFailer();
        $this->seedFailures(2, ['queue' => 'two'], occurrence: 100);
        $this->seedFailures(1, ['queue' => 'other'], occurrence: 500);
        $frozen = $this->freeze('forget', $this->listSource(['connection' => 'database', 'queue' => 'two']))->assertOk();
        self::assertSame(2, $frozen->json('frozen.total.count'));
        // In-flight failures: ids below the frozen bound, committed after the freeze.
        $this->seedFailures(3, ['queue' => 'two'], occurrence: 200);

        $first = $this->runPage($frozen, null, 2)->assertOk();
        $second = $this->runPage($frozen, null, 2)->assertOk();

        self::assertSame(2, $first->json('totals.accepted'));
        self::assertSame(3, $first->json('totals.not_confirmed'));
        self::assertSame(0, $second->json('totals.accepted'));
        self::assertSame(3, $second->json('totals.not_confirmed'));
        self::assertSame(3, $this->mirror()->table('orrery_jobs')->where('queue', 'two')->count(), 'exactly the confirmed total is gone');
        self::assertSame(2, $this->actingAs(new User)->getJson('/orrery/api/bulk/'.$frozen->json('frozen.ref'))->json('claimed'));
    }

    /**
     * Rejects: a replay after a lost response that repeats work, and a run
     * that a reload cannot find again (S-13, P-6).
     */
    public function test_a_replay_repeats_no_work_and_a_reload_finds_the_run(): void
    {
        [$a] = $this->failedJob();
        [$b] = $this->failedJob();
        $frozen = $this->freeze('retry', $this->listSource(['connection' => 'database', 'queue' => 'default']))->assertOk();

        $first = $this->runPage($frozen, null, null)->assertOk();
        self::assertSame(2, $first->json('totals.accepted'));
        $jobs = DB::table('jobs')->count();

        // The response was lost: the client sends the same request again.
        $replay = $this->runPage($frozen, null, null)->assertOk();
        self::assertSame([], $replay->json('results'));
        self::assertTrue($replay->json('done'));
        self::assertSame($jobs, DB::table('jobs')->count(), 'no second publication');

        $runs = $this->actingAs(new User)->getJson('/orrery/api/bulk')->assertOk();
        self::assertSame($frozen->json('frozen.ref'), $runs->json('runs.0.ref'));
        self::assertSame('retry', $runs->json('runs.0.action'));
        $run = $this->actingAs(new User)->getJson('/orrery/api/bulk/'.$frozen->json('frozen.ref'))->assertOk();
        self::assertSame(2, $run->json('claimed'));
        self::assertSame(2, $run->json('counters.accepted'));
        self::assertSame(2, $run->json('operations.done') + $run->json('operations.published'));
        self::assertNull($run->json('confirmed_total'));
        self::assertSame($frozen->json('frozen.source'), $run->json('source'));
        self::assertSame(['pending', 'pending'], [$this->rowOrFail($a)->status, $this->rowOrFail($b)->status]);

        $this->actingAs(new User)->getJson('/orrery/api/bulk/'.str_repeat('d', 32))->assertNotFound()->assertJsonPath('error', 'not_found');
        $this->actingAs(new User)->getJson('/orrery/api/bulk/not-a-ref')->assertNotFound();
    }

    /**
     * Rejects: a groups source stored cut down, or compared field by field
     * instead of by source_hash; a same-key group in another queue
     * or a failure outside the window that a run touches; and a scope that
     * trusts the SQL collation.
     */
    public function test_a_groups_source_keeps_its_scope_and_its_full_source(): void
    {
        // The page scans one segment per key (200); the page deadline is not under test, so the
        // monotonic clock is frozen: on a loaded Postgres the scans passed it and the page was not done.
        Clock::freezeMonotonicForTesting();
        $this->useNullFailer();
        $now = Clock::now();
        [$member] = $this->seedFailures(1, ['queue' => 'high', 'failure_key' => self::KEY], at: $now - 100);
        [$otherQueue] = $this->seedFailures(1, ['queue' => 'low', 'failure_key' => self::KEY], at: $now - 100);
        [$folded] = $this->seedFailures(1, ['queue' => 'HIGH', 'failure_key' => self::KEY], at: $now - 100);
        [$outside] = $this->seedFailures(1, ['queue' => 'high', 'failure_key' => self::KEY], at: $now - 5000);
        $keys = [self::KEY];

        for ($index = 1; count($keys) < 200; $index++) {
            $keys[] = hash('sha256', 'key '.$index);
        }

        $source = ['kind' => 'groups', 'queue' => 'database:high', 'from' => $now - 1000, 'to' => $now + 10, 'keys' => $keys];
        $frozen = $this->freeze('forget', $source)->assertOk();
        self::assertSame(1, $frozen->json('frozen.total.count'));
        $page = $this->runPage($frozen, null, 1)->assertOk();

        self::assertSame([[$member, 'accepted']], array_map(static fn (array $item): array => [$item['id'], $item['outcome']], (array) $page->json('results')));
        self::assertTrue($page->json('done'));

        foreach ([$otherQueue, $folded, $outside] as $untouched) {
            self::assertSame('failed', $this->rowOrFail($untouched)->status);
        }

        $stored = $this->mirror()->table('orrery_bulk_runs')->where('ref', $frozen->json('frozen.ref'))->first();
        self::assertNotNull($stored);
        $decoded = json_decode((string) $stored->source, true);
        self::assertSame($keys, $decoded['keys']);
        self::assertSame(hash('sha256', (string) $stored->source), $stored->source_hash);
        self::assertSame($frozen->json('frozen.source_hash'), $stored->source_hash);
    }

    /**
     * Rejects: a batch source that misses the held members or matches
     * another batch id by collation.
     */
    public function test_a_batch_source_reads_its_failed_and_held_members(): void
    {
        $this->useNullFailer();
        [$failed, $held] = $this->seedFailures(2, ['queue' => 'b', 'batch_id' => 'batch-1']);
        $this->mirror()->table('orrery_jobs')->where('uuid', $held)->update(['status' => 'retrying', 'operation_id' => str_repeat('e', 32)]);
        [$other] = $this->seedFailures(1, ['queue' => 'b', 'batch_id' => 'BATCH-1']);

        $frozen = $this->freeze('retry', ['kind' => 'batch', 'batch_id' => 'batch-1'])->assertOk();
        self::assertSame(['count' => 2, 'at_least' => false], $frozen->json('frozen.total'));
        $page = $this->runPage($frozen, null, null)->assertOk();
        $outcomes = array_column((array) $page->json('results'), 'outcome', 'id');

        self::assertSame(['accepted', 'uncertain'], [$outcomes[$failed], $outcomes[$held]]);
        self::assertArrayNotHasKey($other, $outcomes);
        self::assertSame('batch', $this->rowOrFail($failed)->cycle_source);
    }

    /**
     * A list source with a tag compares the tag byte for byte (the
     * `orders ` row is written directly: the normalizer never stores one).
     *
     * Rejects: a tag membership read with the collated equality alone
     * (MySQL's utf8mb4_bin pads trailing spaces, so the `orders ` row is
     * frozen and retried as a member of `orders`).
     */
    public function test_a_tag_list_source_freezes_only_the_exact_tag(): void
    {
        $this->useNullFailer();
        [$first, $second, $padded] = $this->seedFailures(3, []);
        $at = Clock::toDecimal(Clock::now() - 3600);

        foreach ([$first => 'orders', $second => 'orders', $padded => 'orders '] as $uuid => $tag) {
            $this->mirror()->table('orrery_job_tags')->insert(['tag' => $tag, 'job_uuid' => $uuid, 'pushed_at' => $at]);
            $this->mirror()->table('orrery_failed_tags')->insert(['tag' => $tag, 'job_uuid' => $uuid, 'finished_at' => $at]);
        }

        $frozen = $this->freeze('retry', $this->listSource(['tag' => 'orders']))->assertOk();
        self::assertSame(['count' => 2, 'at_least' => false], $frozen->json('frozen.total'));
        $outcomes = [];

        foreach ($this->runAll($frozen, null) as $page) {
            $outcomes += array_column((array) $page['results'], 'outcome', 'id');
        }

        self::assertSame(['accepted', 'accepted'], [$outcomes[$first] ?? null, $outcomes[$second] ?? null]);
        self::assertArrayNotHasKey($padded, $outcomes);
        self::assertSame('failed', $this->rowOrFail($padded)->status, 'the padded row is untouched');
    }

    /**
     * Rejects: a run that hides a read-only failer's refusal (each member
     * answers its code and the operator text).
     */
    public function test_a_read_only_failer_refuses_every_member_with_its_code(): void
    {
        $this->failedJob();
        $this->failedJob();
        $this->app->instance('queue.failer', new FileFailedJobProvider(sys_get_temp_dir().'/orrery-bulk-no-failures'));
        $frozen = $this->freeze('retry', $this->listSource(['connection' => 'database', 'queue' => 'default']))->assertOk();

        $page = $this->runPage($frozen, null, null)->assertOk();
        self::assertSame(['provider_read_only' => 2], $page->json('refused_reasons'));
        self::assertSame(['refused', 'refused'], array_column((array) $page->json('results'), 'outcome'));
        self::assertStringStartsWith('Retry and forget need the database-uuids or null failed-job driver', (string) $page->json('results.0.message'));
        self::assertSame(0, $this->actingAs(new User)->getJson('/orrery/api/bulk/'.$frozen->json('frozen.ref'))->json('claimed'));
    }

    /**
     * Rejects: a run answer that carries job text (S-14): it must answer
     * while the redaction configuration is invalid, and hold no message,
     * name or payload of a member.
     */
    public function test_a_run_answers_without_job_text_when_the_redaction_turns_invalid(): void
    {
        $this->useNullFailer();
        $this->seedFailures(2, ['queue' => 'r', 'exception_message' => 'canary-bulk-secret', 'name' => 'App\\Jobs\\CanaryJob']);
        $frozen = $this->freeze('retry', $this->listSource(['connection' => 'database', 'queue' => 'r']))->assertOk();

        $this->app['config']->set('orrery.redact.patterns', ['not a pattern']);
        $this->freeze('retry', $this->listSource(['connection' => 'database', 'queue' => 'r']))->assertStatus(500)->assertJsonPath('error', 'redaction_config_invalid');
        $page = $this->runPage($frozen, null, null)->assertOk();

        self::assertSame(2, $page->json('totals.accepted'));
        self::assertStringNotContainsString('canary-bulk-secret', (string) $page->getContent());
        self::assertStringNotContainsString('CanaryJob', (string) $page->getContent());
        self::assertSame(['id', 'occurrence_id', 'outcome', 'code', 'message', 'operation_id'], array_keys((array) $page->json('results.0')));
    }

    /**
     * Rejects: a malformed freeze or run body that reaches the engine.
     */
    public function test_malformed_bodies_answer_422_with_the_field(): void
    {
        $now = Clock::now();

        foreach ([
            [['action' => 'delete', 'source' => ['kind' => 'batch', 'batch_id' => 'b']], 'action'],
            [['action' => 'retry', 'source' => ['kind' => 'all']], 'source.kind'],
            [['action' => 'retry', 'source' => ['kind' => 'batch', 'batch_id' => 'b', 'extra' => 1]], 'source.extra'],
            [['action' => 'retry', 'source' => ['kind' => 'list', 'queue' => 'q']], 'source.queue'],
            [['action' => 'retry', 'source' => ['kind' => 'list', 'from' => 'yesterday']], 'source.from'],
            [['action' => 'retry', 'source' => ['kind' => 'groups', 'queue' => null, 'from' => $now - 10, 'to' => $now, 'keys' => []]], 'source.keys'],
            [['action' => 'retry', 'source' => ['kind' => 'groups', 'queue' => null, 'from' => $now - 10, 'to' => $now, 'keys' => array_map(static fn (int $n): string => hash('sha256', (string) $n), range(1, 201))]], 'source.keys'],
            [['action' => 'retry', 'source' => ['kind' => 'groups', 'queue' => 'database:'.str_repeat('q', 256), 'from' => $now - 10, 'to' => $now, 'keys' => [str_repeat('a', 64)]]], 'source.queue'],
            [['action' => 'retry', 'source' => ['kind' => 'list', 'connection' => str_repeat('c', 192)]], 'source.connection'],
            [['action' => 'retry', 'source' => ['kind' => 'list', 'connection' => 'database', 'queue' => str_repeat('q', 256)]], 'source.queue'],
            [['action' => 'retry', 'source' => ['kind' => 'list', 'class' => str_repeat('C', 256)]], 'source.class'],
            [['action' => 'retry', 'source' => ['kind' => 'list', 'tag' => str_repeat('t', 256)]], 'source.tag'],
            [['action' => 'retry', 'source' => ['kind' => 'batch', 'batch_id' => str_repeat('b', 256)]], 'source.batch_id'],
            [['action' => 'retry', 'source' => 'list'], 'source'],
        ] as [$body, $field]) {
            $this->actingAs(new User)->postJson('/orrery/api/bulk/freeze', $body)->assertStatus(422)->assertJsonPath('error', 'invalid')->assertJsonPath('field', $field);
        }

        $this->seedFailures(1, ['queue' => 'm']);
        $frozen = $this->freeze('retry', $this->listSource(['connection' => 'database', 'queue' => 'm']))->assertOk();
        $this->runPage($frozen, 'not-a-cursor', null)->assertStatus(422)->assertJsonPath('field', 'after');
    }

    /**
     * Rejects: bulk run rows that never age out, and a retention that
     * deletes a run that is still used.
     */
    public function test_the_bulk_runs_age_out_with_the_operations(): void
    {
        $now = Clock::now();
        $insert = fn (string $ref, float $at) => $this->mirror()->table('orrery_bulk_runs')->insert([
            'ref' => $ref, 'action' => 'retry', 'source' => '{}', 'source_hash' => str_repeat('0', 64), 'max_occurrence' => 1, 'total' => 1,
            'created_at' => Clock::toDecimal($at), 'last_run_at' => Clock::toDecimal($at),
        ]);
        $insert(str_repeat('1', 32), $now - 31 * 86400);
        $insert(str_repeat('2', 32), $now - 29 * 86400);

        $this->runPhase(OperationRetentionPhase::class);

        self::assertSame([str_repeat('2', 32)], $this->mirror()->table('orrery_bulk_runs')->pluck('ref')->all());
    }

    /**
     * Rejects: a retention of 0 days that deletes the audit row of a run whose token is still valid (that resets
     * the forget ceiling): a run stays until it is idle for BulkRun::EXPIRY_SECONDS.
     */
    public function test_a_zero_day_retention_keeps_a_live_run(): void
    {
        $this->app['config']->set('orrery.operations.retention_days', 0);
        $now = Clock::now();
        $insert = fn (string $ref, float $at) => $this->mirror()->table('orrery_bulk_runs')->insert([
            'ref' => $ref, 'action' => 'forget', 'source' => '{}', 'source_hash' => str_repeat('0', 64), 'max_occurrence' => 1, 'total' => 1,
            'created_at' => Clock::toDecimal($at), 'last_run_at' => Clock::toDecimal($at),
        ]);
        $insert(str_repeat('1', 32), $now - BulkRun::EXPIRY_SECONDS - 60);
        $insert(str_repeat('2', 32), $now - 60);

        $this->runPhase(OperationRetentionPhase::class);

        self::assertSame([str_repeat('2', 32)], $this->mirror()->table('orrery_bulk_runs')->pluck('ref')->all());
    }

    /**
     * Rejects: a token check that accepts another base64 form of the signed
     * bytes (the unused low bits of the body's last character): only the
     * canonical encoding is the token that the server gave out.
     */
    public function test_another_base64_form_of_the_body_is_frozen_invalid(): void
    {
        $frozenAt = Clock::now();
        $token = '';

        // A body whose last base64 character has unused bits: its length is not a multiple of 4.
        for ($length = 1; $length === 1 || strlen(explode('.', $token)[0]) % 4 === 0; $length++) {
            $source = BulkSource::from(['kind' => 'batch', 'batch_id' => str_repeat('b', $length)]);
            $token = FrozenToken::freeze(str_repeat('c', 32), 'retry', $source, 1, $frozenAt, ['count' => 0, 'at_least' => false], true)->encode(self::APP_KEY);
        }

        [$body, $signature] = explode('.', $token);
        $decode = static fn (string $text): string|false => base64_decode(strtr($text, '-_', '+/'), true);
        $alternative = null;

        foreach (str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_') as $character) {
            $candidate = substr($body, 0, -1).$character;

            if ($candidate !== $body && $decode($candidate) === $decode($body)) {
                $alternative = $candidate;

                break;
            }
        }

        self::assertNotNull($alternative, 'an alternative encoding of the same bytes exists');
        $this->actingAs(new User)->postJson('/orrery/api/bulk/run', ['token' => $alternative.'.'.$signature, 'after' => null, 'confirmed_total' => null])
            ->assertStatus(422)->assertJsonPath('error', 'frozen_invalid');
        $this->actingAs(new User)->postJson('/orrery/api/bulk/run', ['token' => $token, 'after' => null, 'confirmed_total' => null])->assertOk();
    }

    /**
     * Rejects: a signing key or a signature that differs from the
     * documented construction, checked against values that a
     * separate implementation (Python's hmac) computed.
     */
    public function test_the_token_matches_a_known_answer(): void
    {
        self::assertSame('b213fe0f0bc9ad94b47cb6f5af2487bad5b94b7c9ad04540ef7ccc82226fe700', FrozenToken::key(self::APP_KEY));

        $token = FrozenToken::freeze('0123456789abcdef0123456789abcdef', 'retry', BulkSource::from(['kind' => 'batch', 'batch_id' => 'b-1']), 42, 1762600000.0, ['count' => 3, 'at_least' => false], true)->encode(self::APP_KEY);

        self::assertSame(
            'eyJhY3Rpb24iOiJyZXRyeSIsImV4YWN0Ijp0cnVlLCJmcm96ZW5fYXQiOjE3NjI2MDAwMDAuMCwibWF4X29jY3VycmVuY2UiOjQyLCJyZWYiOiIwMTIzNDU2Nzg5YWJjZGVmMDEyMzQ1Njc4OWFiY2RlZiIsInNvdXJjZSI6eyJiYXRjaF9pZCI6ImItMSIsImtpbmQiOiJiYXRjaCJ9LCJzb3VyY2VfaGFzaCI6IjcxNzE2ZDAyMGRmODFkOGZiMTU0MjM4ZmQwMDA3NTVhYjBiN2M3MTgwOTUzOTBiYTg0NTYyNDdiMzg0ZjEwOGYiLCJ0b3RhbCI6eyJhdF9sZWFzdCI6ZmFsc2UsImNvdW50IjozfX0.sG3eE5k82xDWZDMEJ_3ywN0MyuEHMme_P3vaveVZRrU',
            $token,
        );
        self::assertSame(42, FrozenToken::verify($token, self::APP_KEY)->maxOccurrence);
    }

    /**
     * Rejects: a forget run of a batch whose operations name the source
     * `batch` (that source means a retry of a batch's failed members); a
     * forget is always `bulk`.
     */
    public function test_a_forget_of_a_batch_has_the_bulk_source(): void
    {
        $this->useNullFailer();
        $this->seedFailures(2, ['queue' => 'fb', 'batch_id' => 'batch-f']);
        $frozen = $this->freeze('forget', ['kind' => 'batch', 'batch_id' => 'batch-f'])->assertOk();

        $this->runPage($frozen, null, 2)->assertOk()->assertJsonPath('totals.accepted', 2);
        self::assertSame(['bulk'], $this->mirror()->table('orrery_operations')->where('bulk_ref', $frozen->json('frozen.ref'))->distinct()->pluck('source')->all());
    }

    /**
     * Rejects: a run that a tiny candidate budget stalls (a page that gives
     * no new position), on an 8-range list source (a queue: 4 retention
     * ranges per status), with members in different ranges.
     */
    #[DataProvider('tinyBudgets')]
    public function test_a_tiny_budget_still_moves_on_over_eight_ranges(int $budget): void
    {
        $this->useNullFailer();
        $this->app['config']->set('orrery.dashboard.search_candidates', $budget);
        $uuids = [];

        foreach ([[false, false], [false, true], [true, false], [true, true]] as $index => [$silenced, $monitored]) {
            $uuids = array_merge($uuids, $this->seedFailures(2, ['queue' => 'eight', 'silenced' => $silenced, 'monitored' => $monitored], at: 1_700_000_000.0 + 10 * $index));
        }

        $frozen = $this->freeze('retry', $this->listSource(['connection' => 'database', 'queue' => 'eight']))->assertOk();
        $pages = $this->runAll($frozen, null);
        $done = array_merge(...array_map(static fn (array $page): array => array_column($page['results'], 'id'), $pages));
        sort($done);
        sort($uuids);

        self::assertSame($uuids, $done, 'every member once');

        foreach (array_slice($pages, 0, -1) as $index => $page) {
            self::assertNotSame($index === 0 ? null : $pages[$index - 1]['next_after'], $page['next_after'], 'each page moves on');
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function tinyBudgets(): array
    {
        return ['1 candidate' => [1], '8 candidates' => [8]];
    }

    /**
     * Rejects: run details whose ledger counts claim to be complete after
     * the operation retention may have deleted operations of the run.
     */
    public function test_the_run_detail_says_when_its_ledger_counts_may_be_incomplete(): void
    {
        $now = Clock::now();

        foreach ([str_repeat('3', 32) => $now - 3600, str_repeat('4', 32) => $now - 31 * 86400] as $ref => $at) {
            $this->mirror()->table('orrery_bulk_runs')->insert([
                'ref' => $ref, 'action' => 'retry', 'source' => '{}', 'source_hash' => str_repeat('0', 64), 'max_occurrence' => 1, 'total' => 1,
                'created_at' => Clock::toDecimal($at), 'last_run_at' => Clock::toDecimal($now),
            ]);
        }

        $this->actingAs(new User)->getJson('/orrery/api/bulk/'.str_repeat('3', 32))->assertOk()->assertJsonPath('operations_complete', true);
        $this->actingAs(new User)->getJson('/orrery/api/bulk/'.str_repeat('4', 32))->assertOk()->assertJsonPath('operations_complete', false);
    }

    /**
     * Rejects: a freeze that reads the members before MAX(occurrence_id), or
     * derives the bound from them (D-5): a failure that commits between the
     * two reads with a larger id is not a member, one with a smaller id (an
     * id allocated before the freeze, committed during it) is.
     */
    public function test_failures_between_the_bound_and_the_member_reads(): void
    {
        $this->useNullFailer();
        [$first] = $this->seedFailures(1, ['queue' => 'race'], occurrence: 100);
        $this->seedFailures(1, ['queue' => 'other'], occurrence: 200);
        $newer = $lower = null;
        $this->onHook(function (string $point) use (&$newer, &$lower): void {
            if ($point === BulkRun::AFTER_MAX_OCCURRENCE && $newer === null) {
                [$newer] = $this->seedFailures(1, ['queue' => 'race'], occurrence: 300);
                [$lower] = $this->seedFailures(1, ['queue' => 'race'], occurrence: 150);
            }
        });

        $frozen = $this->freeze('retry', $this->listSource(['connection' => 'database', 'queue' => 'race']))->assertOk();
        self::assertSame(200, $frozen->json('frozen.max_occurrence'));
        self::assertSame(2, $frozen->json('frozen.total.count'), 'the lower id is a member, the larger one is not');

        $page = $this->runPage($frozen, null, null)->assertOk();
        $done = array_column((array) $page->json('results'), 'id');
        sort($done);
        $expected = [$first, $lower];
        sort($expected);

        self::assertSame($expected, $done);
        self::assertSame('failed', $this->rowOrFail((string) $newer)->status);
    }

    /**
     * Rejects: a bulk run that holds a write transaction of the application's
     * SQLite file across members, or writes it outside an operator action
     * (D17): each member's publication is one short transaction of its own.
     * Only a SQLite file test run has a host file.
     */
    public function test_on_a_host_sqlite_file_each_member_is_one_short_operator_transaction(): void
    {
        $environment = $this->testEnvironment();

        if ($environment->driver !== 'sqlite' || $environment->inMemory() || $environment->separate()) {
            self::markTestSkipped('Only a SQLite file run in same mode has a host SQLite file (bin/test sqlite --sqlite-file).');
        }

        $this->failedJob();
        $this->failedJob();
        $frozen = $this->freeze('retry', $this->listSource(['connection' => 'database', 'queue' => 'default']))->assertOk();
        $host = PackageSession::NAME_PREFIX.TestEnvironment::PRIMARY;
        $sessions = $this->app->make(PackageSession::class);
        $events = [];
        PackageSession::recordForTesting(static function (string $kind, string $sql, ?string $session) use (&$events, $host, $sessions): void {
            if ($session !== $host || $kind === PackageSession::EXECUTED) {
                return;
            }

            $verb = strtolower((string) strtok(ltrim($sql), " \n"));

            if ($kind === PackageSession::STATEMENT && in_array($verb, ['select', 'pragma'], true)) {
                return;
            }

            $events[] = ['kind' => $kind, 'sql' => strtolower($sql), 'operator' => $sessions->operatorContext() !== null];
        });

        $this->runPage($frozen, null, null)->assertOk()->assertJsonPath('totals.accepted', 2);

        self::assertNotSame([], $events);
        self::assertSame([true], array_values(array_unique(array_column($events, 'operator'))), 'every host write is an operator write');
        $transactions = [];
        $open = null;

        foreach ($events as $event) {
            if ($event['kind'] === PackageSession::BEGIN) {
                self::assertNull($open, 'no transaction is open across another');
                $open = [];
            } elseif ($event['kind'] === PackageSession::COMMIT || $event['kind'] === PackageSession::ROLLBACK) {
                $transactions[] = $open ?? [];
                $open = null;
            } elseif ($open !== null) {
                $open[] = $event['sql'];
            }
        }

        self::assertNull($open);
        // The queue table with the run's table prefix (ORRERY_TEST_PREFIX).
        $jobs = '"'.$environment->primaryPrefix().'jobs"';
        $inserts = array_map(static fn (array $statements): int => count(array_filter($statements, static fn (string $sql): bool => str_starts_with(ltrim($sql), 'insert into') && str_contains($sql, $jobs))), $transactions);
        self::assertSame([1, 1], array_values(array_filter($inserts)), 'one publication per transaction, one transaction per member');
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function freeze(string $action, array $source): TestResponse
    {
        return $this->actingAs(new User)->postJson('/orrery/api/bulk/freeze', ['action' => $action, 'source' => $source]);
    }

    private function runPage(TestResponse $frozen, mixed $after, mixed $confirmed): TestResponse
    {
        return $this->actingAs(new User)->postJson('/orrery/api/bulk/run', ['token' => $frozen->json('frozen.token'), 'after' => $after, 'confirmed_total' => $confirmed]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runAll(TestResponse $frozen, ?int $confirmed): array
    {
        $pages = [];
        $after = null;

        do {
            $page = $this->runPage($frozen, $after, $confirmed)->assertOk()->json();
            self::assertIsArray($page);
            $pages[] = $page;
            $after = $page['next_after'];
            self::assertLessThan(50, count($pages), 'the run ends');
        } while (! $page['done']);

        return $pages;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function listSource(array $filters): array
    {
        return ['kind' => 'list'] + $filters;
    }

    /**
     * A stop callback that stops a page after $members members.
     */
    private function stopAfter(int $members): \Closure
    {
        $asked = 0;

        return static function () use (&$asked, $members): bool {
            return ++$asked > $members;
        };
    }

    /**
     * Seeds failed rows without a failed record (the null failer's
     * failures), newest last; returns their uuids, oldest first.
     *
     * @param  array<string, mixed>  $columns
     * @return list<string>
     */
    private function seedFailures(int $count, array $columns, ?int $occurrence = null, ?float $at = null): array
    {
        $at ??= Clock::now() - 3600;
        $rows = [];
        $uuids = [];

        for ($index = 0; $index < $count; $index++) {
            $this->seeded++;
            $uuid = sprintf('00000000-0000-4000-8000-%012d', $this->seeded);
            $time = Clock::toDecimal($at + $index);
            $name = (string) ($columns['name'] ?? 'App\\Jobs\\Seeded');
            $uuids[] = $uuid;
            $rows[] = array_merge([
                'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'name' => $name, 'type' => 'job',
                'status' => 'failed', 'occurrence_id' => $occurrence !== null ? $occurrence + $index : 10_000 + $this->seeded,
                'payload' => json_encode(['uuid' => $uuid, 'displayName' => $name, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'attempts' => 1, 'data' => ['commandName' => $name]]),
                'payload_bytes' => 100, 'exception_class' => 'RuntimeException', 'exception_message' => 'seeded failure',
                'failure_key' => GroupKey::of('RuntimeException', 'seeded failure', $name),
                'pushed_at' => $time, 'failed_at' => $time, 'finished_at' => $time, 'updated_at' => $time,
            ], $columns);
        }

        foreach (array_chunk($rows, 50) as $chunk) {
            $this->mirror()->table('orrery_jobs')->insert($chunk);
        }

        return $uuids;
    }

    /**
     * A token of these fields, signed with an app key, as FrozenToken does.
     *
     * @param  array<string, mixed>  $fields
     */
    private function sign(array $fields, string $appKey): string
    {
        $json = (string) json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $encode = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return $encode($json).'.'.$encode(hash_hmac('sha256', $json, FrozenToken::key($appKey), true));
    }
}
