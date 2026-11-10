<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Failed;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Failed\CapturingUuidFailedJobProvider;
use Justrau\Orrery\Failed\OccurrenceSlot;
use Justrau\Orrery\Tests\Support\TestEnvironment;

/**
 * Configures `queue.failed` for the package's CapturingUuidFailedJobProvider
 * in a test: the package's verifying extender
 * (ProviderRegistrar) replaces Laravel's provider for exactly that
 * configuration.
 */
trait CapturingFailer
{
    protected function useCapturingFailer(string $table = 'failed_jobs', ?string $connection = TestEnvironment::PRIMARY): void
    {
        $config = $this->app['config'];
        $config->set('queue.failed', ['driver' => 'database-uuids', 'database' => $connection, 'table' => $table]);

        $this->app->forgetInstance('queue.failer');
        self::assertInstanceOf(CapturingUuidFailedJobProvider::class, $this->app['queue.failer']);
    }

    /**
     * Records with other uuids, so the provider ids differ from the mirror ids.
     */
    protected function seedFailedRecords(int $count, string $table = 'failed_jobs', string $connection = TestEnvironment::PRIMARY): void
    {
        foreach (range(1, $count) as $i) {
            DB::connection($connection)->table($table)->insert([
                'uuid' => sprintf('00000000-0000-4000-8000-%012d', $i), 'connection' => 'database', 'queue' => 'default',
                'payload' => '{}', 'exception' => 'seed', 'failed_at' => Carbon::now(),
            ]);
        }
    }

    protected function slot(): OccurrenceSlot
    {
        return $this->app->make(OccurrenceSlot::class);
    }
}
