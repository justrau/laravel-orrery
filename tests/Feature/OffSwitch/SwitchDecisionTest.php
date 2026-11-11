<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\OffSwitch;

use Illuminate\Queue\DatabaseQueue as LaravelDatabaseQueue;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Justrau\Orrery\DatabaseQueue;
use Justrau\Orrery\Failed\CapturingUuidFailedJobProvider;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Runtime\OffSwitch;
use Justrau\Orrery\Tests\TestCase;

/**
 * The provider decides the off switch once per process, at boot. A config
 * change later (a test, a runtime `config()` call) never gives a half-on
 * state: the connector, the failed-job provider, the routes and the
 * commands keep the decision of the boot.
 */
final class SwitchDecisionTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if (str_contains($this->name(), 'disabled_at_boot')) {
            $app['config']->set('orrery.enabled', false);
        }
    }

    /**
     * Rejects: a lazy callback (connector, failer, route middleware, command) that reads the config again and turns
     * the package half on after a runtime change.
     */
    public function test_disabled_at_boot_stays_off_after_a_config_change(): void
    {
        $this->app['config']->set('orrery.enabled', true);
        Orrery::auth(static fn (): bool => true);

        self::assertFalse(OffSwitch::enabled());
        self::assertSame(LaravelDatabaseQueue::class, $this->freshQueue()::class);
        self::assertSame(DatabaseUuidFailedJobProvider::class, $this->freshFailer()::class);
        $this->getJson('/orrery/api/workload')->assertStatus(503);
        $this->artisan('orrery:pause')->expectsOutputToContain(OffSwitch::MESSAGE)->assertExitCode(1);
    }

    /**
     * Rejects: the same half state in the other direction.
     */
    public function test_enabled_at_boot_stays_on_after_a_config_change(): void
    {
        $this->app['config']->set('orrery.enabled', false);
        Orrery::auth(static fn (): bool => true);

        self::assertTrue(OffSwitch::enabled());
        self::assertSame(DatabaseQueue::class, $this->freshQueue()::class);
        self::assertSame(CapturingUuidFailedJobProvider::class, $this->freshFailer()::class);
        $this->getJson('/orrery/api/health')->assertOk();
        $this->artisan('orrery:clear-metrics')->assertExitCode(0);
    }

    private function freshQueue(): object
    {
        $this->app->forgetInstance('queue');
        $this->app->forgetInstance('queue.connection');

        return $this->app['queue']->connection('database');
    }

    private function freshFailer(): object
    {
        $this->app->forgetInstance('queue.failer');

        return $this->app['queue.failer'];
    }
}
