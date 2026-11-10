<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retry;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Controller\Fakes\User;

/**
 * POST /api/jobs/{uuid}/retry and POST /api/jobs/{uuid}/forget:
 * the occurrence comes from the body and is never
 * substituted; the answer is the service's result item, without job text.
 */
final class JobWritesTest extends RetryTestCase
{
    private const ITEM_KEYS = ['id', 'occurrence_id', 'result', 'code', 'message', 'operation_id', 'cycle', 'job_id', 'awaiting_reason'];

    protected function setUp(): void
    {
        parent::setUp();

        Orrery::auth(static fn (): bool => true);
        Orrery::operateUsing(static fn (): bool => true);
    }

    /**
     * Rejects: a retry that answers camelCase keys or job text, or that
     * runs outside one operator action.
     */
    public function test_a_retry_answers_the_service_item(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $contexts = [];
        $this->onHook(function (string $point) use (&$contexts): void {
            if ($point === RetryService::AFTER_CLAIM) {
                $contexts[] = $this->app->make(PackageSession::class)->operatorContext();
            }
        });

        $response = $this->write('retry', strtoupper($uuid), ['occurrence_id' => $occurrence])->assertOk();

        self::assertSame(self::ITEM_KEYS, array_keys((array) $response->json()));
        self::assertSame([$uuid, $occurrence, 'accepted', null], [$response->json('id'), $response->json('occurrence_id'), $response->json('result'), $response->json('code')]);
        self::assertIsString($response->json('operation_id'));
        self::assertSame(2, $response->json('cycle'));
        self::assertIsInt($response->json('job_id'));
        self::assertSame(['pending', 'dashboard'], [$this->rowOrFail($uuid)->status, $this->rowOrFail($uuid)->cycle_source]);
        self::assertCount(1, $contexts);
        self::assertNotNull($contexts[0], 'the retry runs inside an operator action');
        $this->assertNoJobText($response, $uuid);
    }

    /**
     * Rejects: a retry that substitutes the row's current occurrence (the
     * legacy POST /api/jobs/retry/{id} did): a stale page whose occurrence
     * was replaced must change nothing.
     */
    public function test_a_stale_occurrence_is_not_eligible_and_changes_nothing(): void
    {
        [$uuid, $occurrence] = $this->failedJob($this->job(failRuns: 2));
        $this->retry($uuid, $occurrence);
        $current = $this->failAgain($uuid);
        self::assertNotSame($occurrence, $current);
        $jobs = DB::table('jobs')->count();

        foreach (['retry', 'forget'] as $action) {
            $this->write($action, $uuid, ['occurrence_id' => $occurrence])
                ->assertOk()
                ->assertJsonPath('result', 'not_eligible')
                ->assertJsonPath('code', 'occurrence_mismatch');
        }

        self::assertSame(['failed', $current], [$this->rowOrFail($uuid)->status, (int) $this->rowOrFail($uuid)->occurrence_id]);
        self::assertSame($jobs, DB::table('jobs')->count(), 'nothing was published');
        self::assertNotNull($this->providerRecord($uuid), 'nothing was forgotten');
    }

    /**
     * Rejects: a forget replay after a lost response that answers 404 (the
     * SPA would show an error for work that happened), and a 404 rule that
     * answers already_done for any missing row (retention also deletes
     * rows and keeps the failed record).
     */
    public function test_a_forget_replay_answers_already_done_and_an_unknown_uuid_404(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $records = $this->providerRecords();

        $first = $this->write('forget', $uuid, ['occurrence_id' => $occurrence])->assertOk();
        self::assertSame(['deleted', null], [$first->json('result'), $first->json('code')]);
        self::assertSame(self::ITEM_KEYS, array_keys((array) $first->json()));
        self::assertFalse($this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->exists());
        self::assertSame($records - 1, $this->providerRecords());

        $replay = $this->write('forget', $uuid, ['occurrence_id' => $occurrence])->assertOk();
        self::assertSame(['already_done', null, null], [$replay->json('result'), $replay->json('code'), $replay->json('operation_id')]);
        self::assertSame($records - 1, $this->providerRecords());

        // Another occurrence of the forgotten uuid, a retry of it, and a uuid without a row: 404.
        $this->write('forget', $uuid, ['occurrence_id' => $occurrence + 1])->assertNotFound()->assertExactJson(['error' => 'not_found', 'message' => 'Not found.']);
        $this->write('retry', $uuid, ['occurrence_id' => $occurrence])->assertNotFound()->assertJsonPath('error', 'not_found');

        // A row that retention removed (its failed record stays): 404, never already_done.
        [$trimmed, $trimmedOccurrence] = $this->failedJob();
        $this->trimMirrorRow($trimmed);
        $this->write('forget', $trimmed, ['occurrence_id' => $trimmedOccurrence])->assertNotFound();
        self::assertNotNull($this->providerRecord($trimmed));
    }

    /**
     * Rejects: a body whose occurrence_id is a string, zero, negative or
     * missing reaching a service (a string "5" is not the integer that the
     * page read).
     */
    public function test_a_malformed_body_answers_422(): void
    {
        [$uuid] = $this->failedJob();

        foreach ([[], ['occurrence_id' => '5'], ['occurrence_id' => 0], ['occurrence_id' => -1], ['occurrence_id' => 1.5], ['occurrence_id' => null]] as $body) {
            foreach (['retry', 'forget'] as $action) {
                $this->write($action, $uuid, $body)
                    ->assertStatus(422)
                    ->assertExactJson(['error' => 'invalid', 'message' => 'occurrence_id is a whole number of 1 or more.', 'field' => 'occurrence_id']);
            }
        }

        self::assertSame('failed', $this->rowOrFail($uuid)->status);
        self::assertSame(0, $this->mirror()->table('orrery_operations')->count());
    }

    /**
     * Rejects: a write route that a non-uuid path reaches (it shadowed the
     * legacy POST /api/jobs/retry/{id} until it was deleted).
     */
    public function test_the_routes_take_a_uuid_only(): void
    {
        // Only the GET catch-all matches the path: the router answers 405, and no write ran.
        $response = $this->actingAs(new User)->postJson('/orrery/api/jobs/not-a-uuid/retry', ['occurrence_id' => 1]);
        self::assertSame(405, $response->status());
        $response->assertJsonMissingPath('result');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function write(string $action, string $uuid, array $body): TestResponse
    {
        return $this->actingAs(new User)->postJson('/orrery/api/jobs/'.$uuid.'/'.$action, $body);
    }

    private function assertNoJobText(TestResponse $response, string $uuid): void
    {
        $content = (string) $response->getContent();
        $message = $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->value('exception_message')
            ?? (string) $this->mirror()->table('orrery_cycle_summaries')->where('job_uuid', $uuid)->value('exception_message');

        self::assertNotSame('', (string) $message);
        self::assertStringNotContainsString((string) $message, $content, 'a write answer carries no job text');
        self::assertStringNotContainsString('RetryableJob', $content);
    }
}
