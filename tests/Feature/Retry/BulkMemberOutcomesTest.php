<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retry;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Justrau\Orrery\Http\Bulk\BulkRun;
use Justrau\Orrery\Http\Bulk\BulkRuns;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Retry\ForgetService;
use Justrau\Orrery\Retry\Publication;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\PackageStatements;
use Justrau\Orrery\Tests\Support\SessionPdo;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\ThrowingCommitPdo;
use Justrau\Orrery\Tests\Support\ThrowingRollbackPdo;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The outcome of a bulk run member whose row another operator or retention
 * changes after the page read its members (Http\Bulk\BulkRun::
 * classify(), which reads the row again before the service). These cases
 * were the per-item rules of the group operations (Failures\GroupOperation,
 * now gone); the bulk run keeps them. The members are real failures
 * with bound failed records; a is older than b, so a page handles b first,
 * and the change to a happens at b's claim (the AFTER_CLAIM hook).
 *
 * The second half covers the service results that the run maps
 * (BulkRun::member() and notEligible()): an uncertain retry, a pending
 * mirror delete, a forget whose record delete fails, a retry whose
 * preparation fails, and a row that changes just before the service's claim.
 */
final class BulkMemberOutcomesTest extends RetryTestCase
{
    private const APP_KEY = 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('app.key', self::APP_KEY);
        Orrery::auth(static fn (): bool => true);
        Orrery::operateUsing(static fn (): bool => true);
    }

    /**
     * Rejects: a run that retries a member again after another operator
     * retried it (a second publication of one failure), and one that reports
     * that member as accepted or as not a member.
     */
    public function test_a_member_retried_by_another_operator_is_already_done(): void
    {
        [$a, $occurrenceA] = $this->failedJob();
        [$b] = $this->failedJob();
        $frozen = $this->freeze('retry')->assertOk();
        self::assertSame(['count' => 2, 'at_least' => false], $frozen->json('frozen.total'));
        $this->atFirst(RetryService::AFTER_CLAIM, fn () => self::assertSame('accepted', $this->service()->retry($a, $occurrenceA, 'cli')->result));
        $published = DB::table('jobs')->count();

        $results = $this->runPage($frozen, null)->assertOk()->json('results');

        self::assertSame(self::sorted([$a => ['already_done', 'retried'], $b => ['accepted', null]]), $this->outcomes($results));
        self::assertSame($published + 2, DB::table('jobs')->count(), 'one publication each: b by the run, a by the other operator');
    }

    /**
     * Rejects: a run that forgets again (or reports as missing) a member
     * that another operator forgot after the freeze: the ledger's done
     * forget proves it gone.
     */
    public function test_a_member_forgotten_by_another_operator_is_already_done(): void
    {
        [$a, $occurrenceA] = $this->failedJob();
        [$b] = $this->failedJob();
        $frozen = $this->freeze('forget')->assertOk();
        self::assertSame(['count' => 2, 'at_least' => false], $frozen->json('frozen.total'));
        $this->atFirst(ForgetService::AFTER_CLAIM, fn () => self::assertSame('deleted', $this->forgetService()->forget($a, $occurrenceA, 'cli')->result));

        $results = $this->runPage($frozen, 2)->assertOk()->json('results');

        self::assertSame(self::sorted([$a => ['already_done', 'gone'], $b => ['accepted', null]]), $this->outcomes($results));
        self::assertNull($this->providerRecord($b), 'b was forgotten by the run');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function actions(): array
    {
        return ['retry' => ['retry'], 'forget' => ['forget']];
    }

    /**
     * Rejects: a run that retries or forgets a member whose mirror row is
     * gone (retention trimmed it) without a forget in the ledger, and one
     * that reports it as already done: retention keeps the failed record, so
     * nothing proves a forget.
     */
    #[DataProvider('actions')]
    public function test_a_member_without_its_row_is_not_a_member(string $action): void
    {
        [$a] = $this->failedJob();
        [$b] = $this->failedJob();
        $frozen = $this->freeze($action)->assertOk();
        $this->atFirst($action === 'retry' ? RetryService::AFTER_CLAIM : ForgetService::AFTER_CLAIM, fn () => $this->trimMirrorRow($a));
        $published = DB::table('jobs')->count();

        $results = $this->runPage($frozen, $action === 'forget' ? 2 : null)->assertOk()->json('results');

        self::assertSame(self::sorted([$a => ['not_member', 'missing'], $b => ['accepted', null]]), $this->outcomes($results));
        self::assertNotNull($this->providerRecord($a), 'the failed record of a is kept');
        self::assertSame($published + ($action === 'retry' ? 1 : 0), DB::table('jobs')->count(), 'a was not published');
    }

    /**
     * Rejects: an uncertain claim reported as accepted or refused, and a
     * replay of the page that retries the held member as new work. The
     * mirror is down while the service reads its operation back after the
     * claim, and back before the next member.
     */
    #[FreshSchema]
    public function test_an_uncertain_retry_is_reported_and_a_replay_does_not_retry_it(): void
    {
        [$a] = $this->failedJob();
        [$b] = $this->failedJob();
        $frozen = $this->freeze('retry')->assertOk();
        $this->outageFrom(RetryService::AFTER_CLAIM);
        $published = DB::table('jobs')->count();

        $first = $this->runPage($frozen, null)->assertOk();

        self::assertSame(self::sorted([$a => ['accepted', null], $b => ['uncertain', 'claim_uncertain']]), $this->outcomes($first->json('results')));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) collect($first->json('results'))->firstWhere('id', $b)['operation_id']);
        self::assertSame($published + 1, DB::table('jobs')->count(), 'only a was published');

        $replay = $this->runPage($frozen, null)->assertOk();

        self::assertSame(self::sorted([$b => ['uncertain', 'operation_in_progress']]), $this->outcomes($replay->json('results')), 'the held member is answered, never retried; a left the source');
        self::assertSame($published + 1, DB::table('jobs')->count(), 'the replay published nothing');
    }

    /**
     * Rejects: mirror_pending reported as a refusal or as uncertain: the
     * record is deleted, and the runner finishes the mirror delete.
     */
    #[FreshSchema]
    public function test_a_forget_with_a_pending_mirror_delete_is_accepted(): void
    {
        [$a] = $this->failedJob();
        [$b] = $this->failedJob();
        $frozen = $this->freeze('forget')->assertOk();
        $this->outageFrom(ForgetService::AFTER_RECORD_DELETE);

        $results = $this->runPage($frozen, 2)->assertOk()->json('results');

        self::assertSame(self::sorted([$a => ['accepted', null], $b => ['accepted', 'mirror_pending']]), $this->outcomes($results));
        self::assertNull($this->providerRecord($b), 'the record of b is deleted');
        self::assertSame('claimed', $this->operation((string) collect($results)->firstWhere('id', $b)['operation_id'])->state, 'the runner finishes it');
    }

    /**
     * Rejects: a record delete error reported as accepted or uncertain, an
     * operation left holding the row, a replay of the same run that deletes
     * past its confirmed total (the aborted forget spent its claim slot, so
     * the run answers not_confirmed), and a new run that does not try again
     * (the abort freed the row, so the member is still a member).
     */
    public function test_a_forget_record_error_is_refused_and_a_replay_tries_again(): void
    {
        [$a] = $this->failedJob();
        $frozen = $this->freeze('forget')->assertOk();
        $refuse = true;
        PackageStatements::beforeEach($this->app, TestEnvironment::PRIMARY, static function (string $sql) use (&$refuse): void {
            if ($refuse && preg_match('/^delete from (?:["`]?[a-z0-9_]+["`]?\.)?["`]?[a-z_]*failed_jobs/i', $sql) === 1) {
                throw new RuntimeException('provider delete refused (test)');
            }
        });

        $first = $this->runPage($frozen, 1)->assertOk();

        self::assertSame(self::sorted([$a => ['refused', 'provider_error']]), $this->outcomes($first->json('results')));
        self::assertSame(['failed', null], [$this->rowOrFail($a)->status, $this->rowOrFail($a)->operation_id]);
        self::assertSame('aborted', $this->operation((string) $first->json('results.0.operation_id'))->state);
        self::assertNotNull($this->providerRecord($a));

        $refuse = false;
        $replay = $this->runPage($frozen, 1)->assertOk();

        self::assertSame(self::sorted([$a => ['not_confirmed', null]]), $this->outcomes($replay->json('results')), 'the claim slot of the confirmed total is spent');
        self::assertNotNull($this->providerRecord($a));

        $again = $this->runPage($this->freeze('forget')->assertOk(), 1)->assertOk();

        self::assertSame(self::sorted([$a => ['accepted', null]]), $this->outcomes($again->json('results')), 'a new run tries again');
        self::assertNull($this->providerRecord($a));
    }

    /**
     * Rejects: a retry `failed` result (the preparation threw) reported as
     * accepted or uncertain, and a replay that does not try again once the
     * preparation works.
     */
    public function test_a_retry_whose_preparation_fails_is_refused_and_a_replay_retries(): void
    {
        [$a] = $this->failedJob();
        $frozen = $this->freeze('retry')->assertOk();
        // The job's retryUntil() throws in the retry preparation.
        file_put_contents($this->file('throw-in-retry-until'), 'on');
        $published = DB::table('jobs')->count();

        $first = $this->runPage($frozen, null)->assertOk();

        self::assertSame(self::sorted([$a => ['refused', 'prepare_failed']]), $this->outcomes($first->json('results')));
        self::assertSame(['refused' => 1], array_filter((array) $first->json('totals')));
        self::assertSame($published, DB::table('jobs')->count());

        unlink($this->file('throw-in-retry-until'));
        $replay = $this->runPage($frozen, null)->assertOk();

        self::assertSame(self::sorted([$a => ['accepted', null]]), $this->outcomes($replay->json('results')));
        self::assertSame($published + 1, DB::table('jobs')->count());
    }

    /**
     * @return array<string, array{0: string, 1: array{0: string, 1: string}}>
     */
    public static function changesBeforeTheClaim(): array
    {
        return [
            'another failure (occurrence_mismatch)' => ['occurrence', ['not_member', 'occurrence_changed']],
            'a retry by another operator (not_failed)' => ['retried', ['already_done', 'retried']],
        ];
    }

    /**
     * The row changes after the run's own classification, just before the
     * service's claim: the service answers not_eligible, and the run
     * classifies the member again from a fresh read (BulkRun::notEligible()).
     *
     * Rejects: the service's not_eligible code reported as a refusal
     * (`occurrence_mismatch`, `not_failed`) instead of the fresh outcome.
     *
     * @param  array{0: string, 1: string}  $expected
     */
    #[DataProvider('changesBeforeTheClaim')]
    public function test_a_row_change_before_the_claim_is_classified_again(string $change, array $expected): void
    {
        [$a, $occurrence] = $this->failedJob();
        $frozen = $this->freeze('retry')->assertOk();
        $this->atFirst(RetryService::BEFORE_CLAIM, fn () => $this->mirror()->table('orrery_jobs')->where('uuid', $a)->update($change === 'occurrence'
            ? ['occurrence_id' => $occurrence + 1000]
            : ['status' => 'completed', 'retried_at' => Clock::toDecimal(Clock::now())]));
        $published = DB::table('jobs')->count();

        $results = $this->runPage($frozen, null)->assertOk()->json('results');

        self::assertSame(self::sorted([$a => $expected]), $this->outcomes($results));
        self::assertSame($published, DB::table('jobs')->count(), 'nothing was published');
    }

    /**
     * A publication that fails (a lock wait, a deadlock, a statement
     * timeout) aborts its member, and the page goes on with the next one.
     * On MySQL and Postgres the queue's connection name is the
     * mirror's here, so the publication and the run share one session; only
     * those engines can fail this test.
     *
     * The run resolves the mirror at each read (the next test), so the page
     * goes on even when the session is released; the session that a caller
     * held before the page is what proves the clean session was kept.
     *
     * Rejects: a publication that closes the shared session after a clean
     * rollback, so a caller that holds the mirror session (the run before
     * it resolved the mirror at each read, the runner) gets
     * PackageSessionReleased at its next read or write; and a page that
     * answers an error or loses its counters. Would still pass: a
     * publication that closes the session only after a rollback that
     * failed (the connection is gone then, for the run too).
     */
    public function test_a_failed_publication_does_not_end_the_page(): void
    {
        [$a] = $this->failedJob();
        [$b] = $this->failedJob();
        $frozen = $this->freeze('retry')->assertOk();
        $held = $this->app->make(PackageSession::class)->mirror();
        $this->atFirst(Publication::BEFORE_COMMIT, static function (): never {
            throw new RuntimeException('The publication failed (test).');
        });
        $published = DB::table('jobs')->count();

        $page = $this->runPage($frozen, null)->assertOk();
        $outcomes = $this->outcomes($page->json('results'));

        self::assertSame(['accepted', null], $outcomes[$a], 'the next member was retried');
        self::assertSame(1, (int) $held->table(BulkRuns::TABLE)->where('ref', $page->json('ref'))->value('accepted'), 'the mirror session held before the page still works, and the page stored its counters');
        self::assertNotSame('accepted', $outcomes[$b][0], 'the failed publication is not accepted');
        self::assertSame($published + 1, DB::table('jobs')->count(), 'only a was published');
    }

    /**
     * A publication whose COMMIT throws releases its session (a session
     * whose COMMIT failed is not trusted again). On MySQL and Postgres with
     * the mirror on the queue's connection name that session is the
     * mirror's, so the run must not hold it across members.
     * Only those engines can fail this test.
     *
     * Rejects: a run or a member scan that keeps one mirror session object
     * for the whole page, so after the release the next member read, the
     * counters or the remaining count throw. Would still pass: a run that
     * resolves the mirror again but reads a stale page position (the
     * outcomes of both members are checked, not the cursor).
     */
    public function test_a_released_publication_session_does_not_end_the_page(): void
    {
        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('In-memory SQLite publishes through the application connection, which is never released.');
        }

        [$a] = $this->failedJob();
        [$b] = $this->failedJob();
        $frozen = $this->freeze('retry')->assertOk();
        $sessions = $this->app->make(PackageSession::class);
        $config = (array) $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY);
        $this->atFirst(Publication::BEFORE_FENCE_INSERT, static function () use ($sessions, $config): void {
            $session = $sessions->forAction(TestEnvironment::PRIMARY);
            $session->setPdo(SessionPdo::for($session, ThrowingCommitPdo::forConfig($config)));
        });
        $published = DB::table('jobs')->count();

        $page = $this->runPage($frozen, null)->assertOk();
        $outcomes = $this->outcomes($page->json('results'));

        self::assertSame(['accepted', null], $outcomes[$a], 'the next member was retried');
        self::assertNotSame('accepted', $outcomes[$b][0], 'the failed COMMIT is not accepted');
        self::assertSame(1, (int) $this->mirror()->table(BulkRuns::TABLE)->where('ref', $page->json('ref'))->value('accepted'), 'the page stored its counters on the mirror');
        self::assertSame($published + 1, DB::table('jobs')->count(), 'only a was published');
    }

    /**
     * The keep-or-release rule of Publication::publish(): a session whose
     * rollback did not end cleanly is released, so the server discards the
     * open transaction and the next publication starts at level 0 on a new
     * session. That keeps each effect (the pushed job) in one transaction
     * with its fence row. b's publication throws before COMMIT, and its
     * rollback throws before it reaches the server; a is published next.
     *
     * Rejects: a publication that keeps a session whose rollback failed
     * (or that ignores the PDO's open transaction): a's publication then
     * runs as a savepoint inside b's open transaction, its COMMIT commits
     * nothing, and a is reported accepted with no job in the queue table.
     * Would still pass: a publication that also releases clean sessions
     * (the test before the previous one rejects that).
     */
    public function test_a_publication_whose_rollback_fails_releases_its_session(): void
    {
        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('In-memory SQLite publishes through the application connection, which is never released.');
        }

        [$a] = $this->failedJob();
        [$b] = $this->failedJob();
        $frozen = $this->freeze('retry')->assertOk();
        $sessions = $this->app->make(PackageSession::class);
        $config = (array) $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY);
        $installed = false;
        $thrown = false;
        $this->onHook(static function (string $reached) use ($sessions, $config, &$installed, &$thrown): void {
            if ($reached === Publication::BEFORE_FENCE_INSERT && ! $installed) {
                $installed = true;
                $session = $sessions->forAction(TestEnvironment::PRIMARY);
                $session->setPdo(SessionPdo::for($session, ThrowingRollbackPdo::forConfig($config)));
            }

            if ($reached === Publication::BEFORE_COMMIT && ! $thrown) {
                $thrown = true;

                throw new RuntimeException('The publication failed (test).');
            }
        });
        $published = DB::table('jobs')->count();

        $page = $this->runPage($frozen, null)->assertOk();
        $outcomes = $this->outcomes($page->json('results'));

        self::assertNotSame('accepted', $outcomes[$b][0], 'the failed publication is not accepted');
        self::assertSame(['accepted', null], $outcomes[$a], 'the next member was retried');
        self::assertSame($published + 1, DB::table('jobs')->count(), 'a\'s job is committed with its fence row, b\'s job is discarded with its own');
    }

    /**
     * A mirror outage from the first time a page reaches $point (b's step)
     * until the page reaches the next member.
     */
    private function outageFrom(string $point): void
    {
        $outage = null;

        $this->onHook(function (string $reached) use (&$outage, $point): void {
            if ($reached === $point && $outage === null) {
                $outage = $this->mirrorOutage();
            } elseif ($reached === BulkRun::BETWEEN_MEMBERS && $outage !== null && $outage !== false) {
                $outage->end();
                $outage = false;
            }
        });
    }

    /**
     * Runs $change once, at the first time the page reaches $point (b's claim).
     */
    private function atFirst(string $point, \Closure $change): void
    {
        $done = false;

        $this->onHook(static function (string $reached) use (&$done, $point, $change): void {
            if ($reached === $point && ! $done) {
                $done = true;
                $change();
            }
        });
    }

    private function freeze(string $action): TestResponse
    {
        return $this->actingAs(new User)->postJson('/orrery/api/bulk/freeze', ['action' => $action, 'source' => ['kind' => 'list', 'connection' => 'database', 'queue' => 'default']]);
    }

    private function runPage(TestResponse $frozen, ?int $confirmed): TestResponse
    {
        return $this->actingAs(new User)->postJson('/orrery/api/bulk/run', ['token' => $frozen->json('frozen.token'), 'after' => null, 'confirmed_total' => $confirmed]);
    }

    /**
     * The expected outcomes in the key order of outcomes(), so that a strict
     * comparison tells a null code from 0, false or ''.
     *
     * @param  array<string, array{0: string, 1: ?string}>  $outcomes
     * @return array<string, array{0: string, 1: ?string}>
     */
    private static function sorted(array $outcomes): array
    {
        ksort($outcomes);

        return $outcomes;
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    private function outcomes(mixed $results): array
    {
        self::assertIsArray($results);
        $outcomes = [];

        foreach ($results as $item) {
            self::assertIsArray($item);
            $outcomes[(string) $item['id']] = [(string) $item['outcome'], $item['code'] ?? null];
        }

        ksort($outcomes);

        return $outcomes;
    }
}
