<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Failed;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobFailed as LaravelJobFailed;
use Illuminate\Support\Facades\Event;
use Justrau\Orrery\Tests\Support\TestEnvironment;

/**
 * The fixtures of the failed-job provider tests.
 *
 * TestCase::work() runs the real worker without WorkCommand, so nothing
 * logs a failure. logFailures() adds the listener of WorkCommand::logFailedJob()
 * after the package listeners, as queue:work does: the package's JobFailed
 * listener runs first, then log().
 */
trait FailedProviderFixtures
{
    /** @var list<array{connection: string, table: string}> */
    private array $createdTables = [];

    /**
     * The listener of WorkCommand::logFailedJob().
     */
    protected function logFailures(): void
    {
        Event::listen(LaravelJobFailed::class, function (LaravelJobFailed $event): void {
            $this->app['queue.failer']->log($event->connectionName, $event->job->getQueue(), $event->job->getRawBody(), $event->exception);
        });
    }

    /**
     * Sets queue.failed and builds the failer again from its binding.
     */
    protected function useFailer(string $driver, string $table = 'failed_jobs', string $connection = TestEnvironment::PRIMARY): void
    {
        $this->app['config']->set('queue.failed', ['driver' => $driver, 'database' => $connection, 'table' => $table]);
        $this->app->forgetInstance('queue.failer');
    }

    /**
     * A failed-job table like Laravel's, with or without the uuid column.
     * It is dropped before the application is destroyed.
     */
    protected function createFailedTable(string $table, bool $uuid, string $connection = TestEnvironment::PRIMARY): void
    {
        $schema = $this->app['db']->connection($connection)->getSchemaBuilder();
        $schema->dropIfExists($table);
        $schema->create($table, static function (Blueprint $blueprint) use ($uuid): void {
            $blueprint->id();

            if ($uuid) {
                $blueprint->string('uuid')->unique();
            }

            $blueprint->text('connection');
            $blueprint->text('queue');
            $blueprint->longText('payload');
            $blueprint->longText('exception');
            $blueprint->timestamp('failed_at')->useCurrent();
        });

        if ($this->createdTables === []) {
            $this->beforeApplicationDestroyed(function (): void {
                foreach ($this->createdTables as $created) {
                    $this->app['db']->connection($created['connection'])->getSchemaBuilder()->dropIfExists($created['table']);
                }

                $this->createdTables = [];
            });
        }

        $this->createdTables[] = ['connection' => $connection, 'table' => $table];
    }

    protected function mirrorRow(string $uuid): object
    {
        $row = $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->first();
        self::assertNotNull($row, "The mirror row of {$uuid} exists.");

        return $row;
    }
}
