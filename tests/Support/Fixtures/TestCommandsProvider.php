<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures;

use Illuminate\Support\ServiceProvider;
use Justrau\Orrery\Tests\Support\TestEnvironment;

/**
 * The test commands of the worker CLI (tests/Support/bin/artisan loads it
 * when ORRERY_TEST_PROVIDERS names it):
 * - test:custom-worker (CustomWorkerCommand) runs Worker::daemon() directly;
 * - test:throw (ThrowingCommand) throws, for the exit status of the CLI;
 * - with ORRERY_TEST_FAILING_PHASE=<name>, a tick phase of that name that
 *   fails with a data error (FailingTickPhase);
 * - with ORRERY_TEST_PUBLISH_TIMEOUT=<seconds>, orrery.retry.publish_timeout;
 * - with ORRERY_TEST_TARGET_QUEUE=<queue connection>, the database connection
 *   testbench_target (a copy of testbench) and a database queue connection of
 *   that name on it, as UsesAltServer and the parent test define them. The
 *   queue connection `database` stays, so the worker's identity check holds.
 */
class TestCommandsProvider extends ServiceProvider
{
    public const FAILING_PHASE_VARIABLE = 'ORRERY_TEST_FAILING_PHASE';

    public const PUBLISH_TIMEOUT_VARIABLE = 'ORRERY_TEST_PUBLISH_TIMEOUT';

    public const TARGET_QUEUE_VARIABLE = 'ORRERY_TEST_TARGET_QUEUE';

    public const TARGET_CONNECTION = 'testbench_target';

    public function register(): void
    {
        $phase = getenv(self::FAILING_PHASE_VARIABLE);

        if (is_string($phase) && $phase !== '') {
            $this->app->instance('orrery.test.failing-phase', new FailingTickPhase($phase));
            $this->app->tag(['orrery.test.failing-phase'], 'orrery.tick-phases');
        }
    }

    public function boot(): void
    {
        // In boot(): the worker CLI applies the test environment after the providers register.
        $config = $this->app['config'];
        $timeout = getenv(self::PUBLISH_TIMEOUT_VARIABLE);

        if (is_string($timeout) && ctype_digit($timeout)) {
            $config->set('orrery.retry.publish_timeout', (int) $timeout);
        }

        $queue = getenv(self::TARGET_QUEUE_VARIABLE);

        if (is_string($queue) && $queue !== '' && $queue !== 'database') {
            $config->set('database.connections.'.self::TARGET_CONNECTION, $config->get('database.connections.'.TestEnvironment::PRIMARY));
            $config->set("queue.connections.{$queue}", ['driver' => 'database', 'connection' => self::TARGET_CONNECTION, 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CustomWorkerCommand::class, ThrowingCommand::class]);
        }
    }
}
