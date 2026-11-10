<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Projection;

use Justrau\Orrery\Http\Projection\JobProjection;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Tests\Feature\Projection\Jobs\CanaryJob;
use Justrau\Orrery\Tests\Feature\Retry\RetryTestCase;

/**
 * Stored data is never changed: a retry after a projection
 * publishes the stored payload with its secrets, because the job needs
 * them.
 */
final class StoredPayloadRetryTest extends RetryTestCase
{
    /**
     * Rejects: a projection that writes its redacted form back, or a retry
     * that publishes the projected payload.
     */
    public function test_a_retry_publishes_the_stored_payload(): void
    {
        [$uuid, $occurrence] = $this->failWith(new CanaryJob(null, true));
        $stored = $this->mirror()->table(JobStore::TABLE)->where('uuid', $uuid)->value('payload');
        self::assertIsString($stored);

        $projected = (string) json_encode($this->app->make(JobProjection::class)->payload($stored, strlen($stored)));
        self::assertStringNotContainsString('canary-public-password', $projected);
        self::assertSame($stored, $this->mirror()->table(JobStore::TABLE)->where('uuid', $uuid)->value('payload'), 'The projection changes no stored data.');

        $result = $this->service()->retry($uuid, $occurrence, 'dashboard');

        self::assertSame(RetryResult::ACCEPTED, $result->result);
        $published = $this->queueTable()->orderByDesc('id')->value('payload');
        self::assertIsString($published);
        self::assertStringContainsString('canary-public-password', $published, 'The retry publishes the stored command.');
        self::assertStringContainsString('canary-private-secret', $published);
    }
}
