<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Illuminate\Support\Facades\Exceptions;
use Justrau\Orrery\Exceptions\WatchedOperationFailed;
use Justrau\Orrery\Storage\PooledSessionRefused;

/**
 * The schema drift of a failed-record connection in pooled Postgres mode.
 *
 * The drift sets `search_path = orrery_drift` on the failed-record
 * connection. Pooled mode supports only the server's default search_path:
 * the package refuses to build a session for that
 * connection (PooledSessionRefused, "use a direct connection") and never
 * sends a statement through it. So an action refuses before it reads the
 * failed record or compares the target: `record_unreachable`, not
 * `target_changed`, and nothing is claimed or deleted. In direct mode the
 * session follows the new path and the target check refuses with
 * `target_changed`.
 *
 * The pooled assertions prove which path ran: the action reported a
 * watched error whose cause is the pooled refusal.
 */
trait PooledSchemaDrift
{
    protected function pooledSchemaDrift(string $change): bool
    {
        return $change === 'schema' && $this->testEnvironment()->pooled;
    }

    /**
     * Call before the action: the reports of the action are kept for
     * assertPooledRefusalReported().
     */
    protected function expectPooledRefusal(): void
    {
        Exceptions::fake();
    }

    protected function assertPooledRefusalReported(string $operation): void
    {
        Exceptions::assertReported(static fn (WatchedOperationFailed $reported): bool => $reported->operation === $operation
            && $reported->causeClass === PooledSessionRefused::class);
    }

    protected static function assertPooledRefusal(PooledSessionRefused $refused): void
    {
        self::assertMatchesRegularExpression('/its search_path orrery_drift is not the server default .*; use a direct connection\.$/', $refused->getMessage());
    }
}
