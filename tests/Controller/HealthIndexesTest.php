<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Database\Schema\Blueprint;
use Justrau\Orrery\Database\Schema\DeferredIndexes;
use Justrau\Orrery\Database\Schema\IndexName;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Support\FreshSchema;

/**
 * GET /api/health `missing_indexes`: `[{index, hint}]`.
 *
 * FreshSchema: a test drops an index.
 */
#[FreshSchema]
final class HealthIndexesTest extends ControllerTest
{
    /**
     * Rejects: a health answer that never lists a missing deferred index, a
     * list of bare names instead of `{index, hint}`, and a read
     * on every request instead of the 60 s cache.
     */
    public function test_missing_indexes_lists_the_dropped_index_with_the_hint(): void
    {
        Clock::freezeMonotonicForTesting();
        $health = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk();
        self::assertSame([], $health->json('missing_indexes'));

        $mirror = $this->mirror();
        $mirror->getSchemaBuilder()->table('orrery_jobs', static function (Blueprint $table) use ($mirror): void {
            $table->dropIndex(IndexName::for($mirror, 'orrery_jobs', 'st_gap'));
        });

        self::assertSame([], $this->actingAs(new User)->getJson('/orrery/api/health')->json('missing_indexes'), 'cached for 60 s');

        Clock::advanceMonotonicForTesting(61.0);
        $health = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk();
        self::assertSame([['index' => 'st_gap', 'hint' => DeferredIndexes::HINT]], $health->json('missing_indexes'));
        self::assertStringContainsString('php artisan orrery:install --indexes', (string) $health->json('missing_indexes.0.hint'));
        self::assertArrayNotHasKey('indexes_hint', (array) $health->json());
    }

    /**
     * Rejects: a failed read reported as "none missing".
     */
    public function test_a_failed_read_is_null(): void
    {
        Watcher::failOperationsForTesting(['http:health_indexes']);

        $health = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk();
        self::assertNull($health->json('missing_indexes'));
    }
}
