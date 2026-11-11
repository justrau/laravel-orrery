<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\OffSwitch;

use Closure;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\DatabaseQueue as LaravelDatabaseQueue;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Runtime\OffSwitch;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Jobs\FailingJob;
use Justrau\Orrery\Tests\Feature\Workers\WorkerFixtures;
use Justrau\Orrery\Tests\Support\PackageStatements;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionFunction;

/**
 * ORRERY_ENABLED=false: the package behaves as if it were not installed.
 * Laravel's own connector, failed-job provider and events; no listener, no
 * payload key, no schedule entry and no package statement or session. The
 * acting commands refuse; install, continue and resolve still run.
 */
final class DisabledPackageTest extends TestCase
{
    use WorkerFixtures;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('orrery.enabled', false);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/host-page', static fn (): string => 'host');
    }

    /**
     * Rejects: a disabled package that still swaps the connector, stamps payloads, registers a queue, job or worker
     * listener (heartbeat, outcome, commit deferral), extends the failed-job provider, or sends any statement on a
     * package table or a package session in a dispatch, a worker run of a succeeding and a failing job, or a web request.
     * Still passes: a package that registers a listener on an event this test does not fire.
     */
    public function test_a_dispatch_a_worker_run_and_a_web_request_touch_no_package_storage(): void
    {
        $this->actAsWorker();
        $sessionsBefore = count(PackageSession::sessionsForTesting());
        $tableStatements = [];

        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$tableStatements): void {
            if (str_contains($event->sql, 'orrery_')) {
                $tableStatements[] = $event->connectionName.': '.$event->sql;
            }
        });

        $packageStatements = PackageStatements::record(function (): void {
            Queue::push(new BasicJob);
            Queue::push(new FailingJob);

            foreach (DB::table('jobs')->pluck('payload') as $payload) {
                $decoded = json_decode((string) $payload, true, flags: JSON_THROW_ON_ERROR);
                self::assertArrayNotHasKey('orreryGen', $decoded, 'the payload callback ran');
                self::assertArrayNotHasKey('tags', $decoded, 'the payload callback ran');
            }

            event(new Looping('database', 'default'));
            $this->work(2);
            $this->get('/host-page')->assertOk()->assertSee('host');
        });

        self::assertSame([], $packageStatements);
        self::assertSame([], $tableStatements);
        self::assertSame($sessionsBefore, count(PackageSession::sessionsForTesting()));
        self::assertSame(LaravelDatabaseQueue::class, $this->app['queue']->connection('database')::class);
        self::assertSame(DatabaseUuidFailedJobProvider::class, $this->app['queue.failer']::class);

        // The host's own work happened: both jobs ran. (queue:work's own JobFailed listener writes
        // failed_jobs; TestCase::work() runs the worker without it, and the process test checks it.)
        self::assertSame(0, DB::table('jobs')->count());

        foreach (['orrery_jobs', 'orrery_worker_heartbeats', 'orrery_health_events'] as $table) {
            self::assertSame(0, $this->mirror()->table($table)->count(), $table);
        }

        $source = dirname(__DIR__, 3).'/src/';

        foreach ([WorkerStarting::class, Looping::class, JobProcessing::class, JobProcessed::class, JobExceptionOccurred::class, JobFailed::class, JobAttempted::class, WorkerStopping::class, CommandStarting::class, CommandFinished::class] as $event) {
            foreach ($this->app['events']->getRawListeners()[$event] ?? [] as $listener) {
                // Laravel's own listeners (the Context provider listens to JobProcessing) stay.
                $file = $listener instanceof Closure ? (string) (new ReflectionFunction($listener))->getFileName() : '';

                // The one exception: StorageModule's command listeners keep `migrate --database=` working (in memory only).
                if ($file !== $source.'Registration/Modules/StorageModule.php') {
                    self::assertStringStartsNotWith($source, $file, $event.' has a package listener');
                }

                self::assertFalse(is_string($listener) && str_starts_with($listener, 'Justrau\\Orrery\\'), $event.' has a package listener');
            }
        }
    }

    /**
     * Rejects: a disabled package that still schedules orrery:tick.
     */
    public function test_the_tick_is_not_scheduled(): void
    {
        $commands = array_map(static fn ($event): string => (string) $event->command, $this->app->make(Schedule::class)->events());

        self::assertSame([], array_values(array_filter($commands, static fn (string $command): bool => str_contains($command, 'orrery:'))));
    }

    /**
     * Rejects: an acting command that runs (or exits 0) while the package is off, or a refusal without the reason.
     * Still passes: a refusal that also opened a package session before it refused (the first test covers the hooks only).
     *
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('actingCommands')]
    public function test_an_acting_command_refuses_while_disabled(string $command, array $arguments): void
    {
        $statements = PackageStatements::record(function () use ($command, $arguments): void {
            $this->artisan($command, $arguments)
                ->expectsOutputToContain(OffSwitch::MESSAGE)
                ->assertExitCode(1);
        });

        self::assertSame([], $statements);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function actingCommands(): array
    {
        $uuid = '0d7f2b2a-1111-4111-8111-111111111111';

        return [
            'tick' => ['orrery:tick', []],
            'retry' => ['orrery:retry', ['uuid' => $uuid]],
            'forget' => ['orrery:forget', ['id' => $uuid]],
            'clear' => ['orrery:clear', ['connection' => 'database', '--force' => true]],
            'clear-metrics' => ['orrery:clear-metrics', []],
            'pause' => ['orrery:pause', []],
            'terminate' => ['orrery:terminate', []],
            'reconcile' => ['orrery:reconcile', []],
            'deployed' => ['orrery:deployed', ['--label' => 'v1']],
        ];
    }

    /**
     * Rejects: a disabled package that refuses the commands of the removal procedure (docs/upgrading.md: continue
     * every paused queue, resolve the open operations) or orrery:install.
     * Still passes: a continue or resolve that does nothing (nothing is paused or open here).
     */
    public function test_the_removal_commands_and_install_still_run_while_disabled(): void
    {
        $this->artisan('orrery:continue')->doesntExpectOutputToContain(OffSwitch::MESSAGE)->assertExitCode(0);
        $this->artisan('orrery:resolve', ['--all' => true])->doesntExpectOutputToContain(OffSwitch::MESSAGE)->assertExitCode(0);
        // An invalid --timeout: install reaches its own validation, not the off switch.
        $this->artisan('orrery:install', ['--indexes' => true, '--timeout' => 'x'])
            ->expectsOutputToContain('--timeout is a whole number of seconds')
            ->assertExitCode(1);
    }
}
