<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations\Fixtures;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\ServiceProvider;

/**
 * The test commands of the new retry protocol in a worker process
 * (tests/Support/bin/artisan loads it when ORRERY_TEST_PROVIDERS
 * names it): test:retry-operation. With ORRERY_TEST_SPLIT_FAILED=1 the
 * failed-job table is on the connection testbench_failed, a copy of
 * testbench (mode before), as RetryProtocolTestCase::useSplitFailedConnection()
 * sets it in the parent.
 */
class OperationCommandsProvider extends ServiceProvider
{
    public const SPLIT_VARIABLE = 'ORRERY_TEST_SPLIT_FAILED';

    public function boot(): void
    {
        // At the command's start: after the worker CLI applied the test environment and checked the identity.
        if (getenv(self::SPLIT_VARIABLE) === '1') {
            $this->app['events']->listen(CommandStarting::class, function (): void {
                $config = $this->app['config'];
                $config->set('database.connections.testbench_failed', $config->get('database.connections.testbench'));
                $config->set('queue.failed', ['driver' => 'database-uuids', 'database' => 'testbench_failed', 'table' => 'failed_jobs']);
                $this->app->forgetInstance('queue.failer');
            });
        }

        if ($this->app->runningInConsole()) {
            $this->commands([RetryOperationCommand::class]);
        }
    }
}
