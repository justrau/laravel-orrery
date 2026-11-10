<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Failed;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Failed\FailedRecords;
use Justrau\Orrery\Failed\SqlFailedRecords;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Storage\HostFileAccessNotAllowed;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Jobs\FailingJob;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\TestCase;

/**
 * The id binding on an application SQLite file in rollback-journal mode
 * (D18): the package may not read
 * such a file automatically, and the capture needs no read of it. The
 * application file is the test's own (`app`), so it runs on every engine.
 * Kind: new behaviour.
 */
final class HostFileCaptureTest extends TestCase
{
    use CapturingFailer;

    /** @var list<string> */
    private array $files = [];

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->beforeApplicationDestroyed(function (): void {
            PackageSession::releaseAll();
            DB::purge('app');
            $this->app['config']->set($this->saved);

            foreach ($this->files as $file) {
                SqliteFiles::remove($file);
            }
        });
    }

    /**
     * Rejects: a capture that reads the host file (a uuid lookup, the legacy ProviderIdentity measurement): on a
     * rollback-journal file without a busy timeout no automatic read is allowed, so such a capture leaves every
     * failure unbound and unretryable. The retry itself is tested elsewhere: here the adapter of the operator
     * action proves the record and gives the in_tx mode.
     */
    public function test_failure_on_a_rollback_host_file_stays_retryable(): void
    {
        $this->useHostFile(['journal_mode' => 'delete', 'busy_timeout' => 0]);
        $files = $this->app->make(HostFiles::class);
        self::assertTrue($files->isHost('app'));
        self::assertFalse($files->allowsReads('app'), 'The setup allows no automatic read of the file.');

        $this->useCapturingFailer('failed_jobs', 'app');
        $this->app['events']->listen(JobFailed::class, function ($event): void {
            $this->app['queue.failer']->log($event->connectionName, $event->job->getQueue(), $event->job->getRawBody(), $event->exception);
        });

        Queue::connection('app_queue')->push(new FailingJob);
        $options = new WorkerOptions;
        $options->sleep = 0;
        $options->maxTries = 1;
        $this->app->make('queue.worker')->runNextJob('app_queue', 'default', $options);

        $sessions = $this->app->make(PackageSession::class);
        $record = DB::connection('app')->table('failed_jobs')->sole();
        $row = $sessions->mirror()->table('orrery_jobs')->where('uuid', $record->uuid)->first();

        self::assertNotNull($row);
        self::assertSame('failed', $row->status);
        self::assertSame((int) $record->id, $row->provider_id === null ? null : (int) $row->provider_id, 'The failure is bound with its exact id.');
        self::assertSame([], Degradation::record()['errors'] ?? [], 'No degradation error is recorded.');
        self::assertNull(Degradation::record()['last_error_operation'] ?? null);

        // An automatic caller cannot prove the table's ids on this file; the operator action can.
        try {
            FailedRecords::for($this->app, $this->app['config']);
            self::fail('An automatic read of the host file is refused.');
        } catch (HostFileAccessNotAllowed) {
        }

        $sessions->operator(function () use ($row): void {
            $records = FailedRecords::for($this->app, $this->app['config']);
            self::assertInstanceOf(SqlFailedRecords::class, $records);
            self::assertSame(Operations::IN_TX, $records->mode('app_queue'));
            self::assertSame($records->target(), $row->provider_target);
            self::assertNotNull($records->read((int) $row->provider_id, (string) $row->uuid), 'The operator action reads the exact record.');
        });
    }

    /**
     * An application SQLite file `app` with the queue and failed-job tables, the default connection (so the
     * package keeps its tables in the sibling file).
     *
     * @param  array<string, mixed>  $changes
     */
    private function useHostFile(array $changes): void
    {
        $file = $this->files[] = SqliteFiles::create();
        $config = $this->app['config'];

        foreach (['database.default', 'database.connections.app', 'queue.connections', 'queue.failed', 'orrery.database'] as $key) {
            $this->saved[$key] = $config->get($key);
        }

        $config->set('database.connections.app', array_replace(['driver' => 'sqlite', 'url' => null, 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000, 'journal_mode' => 'wal'], $changes));
        $config->set('queue.connections.app_queue', ['driver' => 'database', 'connection' => 'app', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false]);
        $config->set('orrery.database', null);
        PackageSession::releaseAll();

        $schema = DB::connection('app')->getSchemaBuilder();
        $schema->create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $schema->create('failed_jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        $config->set('database.default', 'app');
    }
}
