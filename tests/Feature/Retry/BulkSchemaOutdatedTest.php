<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retry;

use Illuminate\Database\Schema\Blueprint;
use Justrau\Orrery\Database\Schema\IndexName;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Retry\ForgetService;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\Support\FreshSchema;

/**
 * A mirror before schema step 8: a MySQL or
 * Postgres mirror that an earlier release migrated and that was not
 * refreshed. The bulk routes answer 503 `schema_outdated` with `php artisan
 * migrate` and the `/api/health` pointer; the per-job writes still work, because an operation of no run never
 * names bulk_ref.
 */
#[FreshSchema]
final class BulkSchemaOutdatedTest extends RetryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');
        Orrery::auth(static fn (): bool => true);
        Orrery::operateUsing(static fn (): bool => true);

        // The version 7 schema: no bulk run table, no orrery_operations.bulk_ref.
        $schema = $this->mirrorSchema();
        $mirror = $this->mirror();
        $schema->drop('orrery_bulk_runs');
        $schema->table('orrery_operations', static function (Blueprint $table) use ($mirror): void {
            $table->dropIndex(IndexName::for($mirror, 'orrery_operations', 'bulk'));
        });
        $schema->table('orrery_operations', static function (Blueprint $table): void {
            $table->dropColumn('bulk_ref');
        });
    }

    /**
     * Rejects: a per-job write that inserts a null bulk_ref (it fails on
     * this schema), a bulk route that answers a 500 or works partly, and a
     * message that names the destructive migrate:refresh instead of
     * `php artisan migrate` and the health pointer.
     */
    public function test_the_bulk_routes_answer_schema_outdated_and_the_per_job_writes_work(): void
    {
        [$retried, $retriedOccurrence] = $this->failedJob();
        [$forgotten, $forgottenOccurrence] = $this->failedJob();

        foreach ([
            ['POST', '/orrery/api/bulk/freeze', ['action' => 'retry', 'source' => ['kind' => 'batch', 'batch_id' => 'b']]],
            ['POST', '/orrery/api/bulk/run', ['token' => 'a.b', 'after' => null, 'confirmed_total' => null]],
            ['GET', '/orrery/api/bulk', []],
            ['GET', '/orrery/api/bulk/'.str_repeat('a', 32), []],
        ] as [$method, $uri, $body]) {
            $response = $this->actingAs(new User)->json($method, $uri, $body);
            $response->assertStatus(503)->assertJsonPath('error', 'schema_outdated');
            self::assertStringContainsString('Run php artisan migrate; GET /orrery/api/health (`schema`) gives the exact steps.', (string) $response->json('message'), "{$method} {$uri}");
            self::assertStringNotContainsString('migrate:refresh', (string) $response->json('message'), "{$method} {$uri}: no destructive step (Spec D section 3, item 7).");
        }

        $this->actingAs(new User)->postJson('/orrery/api/jobs/'.$retried.'/retry', ['occurrence_id' => $retriedOccurrence])->assertOk()->assertJsonPath('result', 'accepted');
        $this->actingAs(new User)->postJson('/orrery/api/jobs/'.$forgotten.'/forget', ['occurrence_id' => $forgottenOccurrence])->assertOk()->assertJsonPath('result', 'deleted');
        self::assertSame('pending', $this->rowOrFail($retried)->status);
        self::assertTrue($this->app->make(ForgetService::class)->forgotten($forgotten, $forgottenOccurrence));
    }
}
