<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Ping;

use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Tests\Support\FreshSchema;

/**
 * The ping while the package storage fails: 503, never a 500, and no check
 * that needs it is ok.
 */
#[FreshSchema]
final class PingOutageTest extends PingTestCase
{
    /**
     * Rejects: a ping that reads the other checks after the probe failed (each would wait for its own connect),
     * or reports one of them as ok.
     */
    public function test_an_unreachable_package_database_fails_and_the_rest_is_unknown(): void
    {
        Watcher::failOperationsForTesting(['ping:package_database']);

        $response = $this->ping()->assertStatus(503);

        self::assertSame([
            'package_database' => ['ok' => false, 'reason' => 'unreachable'],
            'schema' => ['ok' => false, 'reason' => 'unknown'],
            'tick' => ['ok' => false, 'reason' => 'unknown'],
            'observations' => ['ok' => false, 'reason' => 'unknown'],
        ], $response->json('checks'));
        self::assertSame('fail', $response->json('status'));
        $this->artisan('orrery:ping')->assertExitCode(1);
    }

    /**
     * Rejects: a ping that throws (500) when the package tables fail, or that passes a check from a stale value.
     * Still passes: either answer of the probe (the in-memory outage renames the tables, so the server answers and
     * the schema reads as missing; a server outage refuses the connect).
     */
    public function test_a_mirror_outage_is_a_503(): void
    {
        $this->mirrorOutage();

        $response = $this->ping()->assertStatus(503);

        self::assertSame('fail', $response->json('status'));
        self::assertFalse($response->json('checks.schema.ok'));
        self::assertFalse($response->json('checks.tick.ok'));
        self::assertFalse($response->json('checks.observations.ok'));
    }
}
