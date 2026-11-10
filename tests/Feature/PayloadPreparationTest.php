<?php

namespace Justrau\Orrery\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\TestCase;

class PayloadPreparationTest extends TestCase
{
    public function test_pushtodatabase_injects_pushedAt_tags_type_silenced_into_payload(): void
    {
        Queue::push(new BasicJob);

        $row = DB::table('jobs')->first();
        $this->assertNotNull($row);

        $payload = json_decode($row->payload, true);

        $this->assertArrayHasKey('pushedAt', $payload, 'pushToDatabase must call JobPayload::prepare() so pushedAt is set');
        $this->assertArrayHasKey('tags', $payload);
        $this->assertArrayHasKey('type', $payload);
        $this->assertArrayHasKey('silenced', $payload);

        $this->assertSame('job', $payload['type']);
        $this->assertFalse($payload['silenced']);
        $this->assertIsString($payload['pushedAt']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $payload['pushedAt']);
    }

    public function test_mirror_row_pushed_at_is_not_clobbered_by_reserve(): void
    {
        Queue::push(new BasicJob);

        $beforeReserve = $this->mirror()->table('orrery_jobs')->first();
        $this->assertNotNull($beforeReserve);
        $this->assertSame('pending', $beforeReserve->status);
        $originalPushedAt = (float) $beforeReserve->pushed_at;

        usleep(50_000);

        $this->work();

        $afterWork = $this->mirror()->table('orrery_jobs')->first();
        $this->assertNotNull($afterWork);
        $this->assertSame('completed', $afterWork->status);

        $this->assertSame(
            $originalPushedAt,
            (float) $afterWork->pushed_at,
            'pushed_at must not be overwritten by the reserve / complete path',
        );

        $this->assertGreaterThan(
            $originalPushedAt,
            (float) $afterWork->reserved_at,
            'reserved_at should be strictly after pushed_at',
        );
        $this->assertGreaterThan(
            (float) $afterWork->reserved_at,
            (float) $afterWork->finished_at,
            'finished_at should be strictly after reserved_at',
        );
    }
}
