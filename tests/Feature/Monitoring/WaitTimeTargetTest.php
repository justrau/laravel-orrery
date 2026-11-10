<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Monitoring\WaitTime;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\PackageSessionHelpers;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;

/**
 * WaitTime reads the database of the queue object that the queue manager
 * holds, not the database that the configuration names
 * now.
 */
final class WaitTimeTargetTest extends TestCase
{
    use PackageSessionHelpers;

    private const OTHER = 'orrery_wait_other';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app['config'];
        $config->set('orrery.waits', ['*' => 60]);
        // An empty database without a jobs table.
        $config->set('database.connections.'.self::OTHER, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'url' => null]);
    }

    /**
     * Rejects: reads from the database that the current default connection names.
     */
    public function test_reads_the_database_of_the_queue_object(): void
    {
        // The queue connection names no database connection: it uses the default one. It is set
        // after the harness's migrations: `migrate --database=<mirror>` makes the mirror the default
        // meanwhile, and the queue would then share the mirror's SQLite file, which install refuses.
        $config = $this->app['config'];
        $queueConnection = $config->get('queue.connections.database.connection');
        $config->set('queue.connections.database.connection', null);
        $this->beforeApplicationDestroyed(static function () use ($config, $queueConnection): void {
            $config->set('queue.connections.database.connection', $queueConnection);
        });
        // The package storage stays where it is when the default changes. A SQLite file keeps it in
        // the sibling file: the package refuses the application's own file.
        $environment = $this->testEnvironment();
        $package = $environment->packageConnection();

        if ($package === null && $environment->siblingConnection() !== null) {
            $this->mirror();
            $package = TestEnvironment::SIBLING;
        }

        $this->usePackageConnection($package ?? TestEnvironment::PRIMARY);
        Queue::pushOn('reports', new BasicJob);
        Queue::pushOn('reports', new BasicJob);

        DB::setDefaultConnection(self::OTHER);

        try {
            $rows = $this->app->make(WaitTime::class)->measure();
        } finally {
            DB::setDefaultConnection(TestEnvironment::PRIMARY);
        }

        $reports = array_values(array_filter($rows, static fn (array $row): bool => $row['connection'] === 'database' && $row['queue'] === 'reports'));
        self::assertCount(1, $reports, 'the queue is found in the jobs table of the queue object: '.json_encode($rows));
        self::assertSame(2, $reports[0]['backlog']);
        self::assertNotNull($reports[0]['oldest_age']);
    }
}
