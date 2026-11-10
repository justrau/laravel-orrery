<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Illuminate\Queue\Events\JobFailed as LaravelJobFailed;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Monitoring\Phases\LongWaitPhase;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\ForgetService;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Jobs\FailingJob;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\Fixtures\HostFile\HostFileFixture;
use Justrau\Orrery\Tests\Support\Fixtures\HostFile\HostFileProvider;
use Justrau\Orrery\Tests\Support\Fixtures\HostFile\HostNativeCommand;
use Justrau\Orrery\Tests\Support\PackageStatements;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * test_sqlite_host_file_gets_no_automatic_write, the data-provider
 * matrix (D17), in
 * worker processes. The application's SQLite file `app` holds the queue
 * (`app_queue`), the failed jobs (through a second connection name, so the
 * retries run in mode before with a restore), the batches, the fence table
 * and the default cache store (`cache.default` a `database` store on the
 * file). Kind: new behaviour.
 *
 * Seeded in the test: an uncertain publication (a retry stopped after its
 * claim), a due abort with a restore (a retry stopped after its record
 * delete), a due forget (stopped after its claim) and an old fence row of a
 * finished operation; the database clocks then move past every deadline.
 *
 * Then a host `deleteReserved()` (test:host-native, busy_timeout 0) is held
 * inside its transaction, between its lockForUpdate()->find() read and its
 * delete, while a real `schedule:run` runs the tick
 * (every phase, the operation runner, the fence retention). The access spy
 * (HostFileProvider) logs every statement on the host files. After the
 * tick, /api/workload and a long-wait notification run in this process.
 *
 * In every case: no automatic package write reaches any host file (also no
 * BEGIN, the write lock of SQLite), no package write goes through the
 * application's own connections (no cache lock row: the tick's mutex is the
 * sibling file mutex), the old fence row stays, the held host delete
 * succeeds, the three operations wait as `sqlite_host_file`, and the resolve
 * route, orrery:resolve and a later retry of the forgotten occurrence each
 * finish one.
 */
#[Group('process')]
#[Group('shared-database')]
final class HostFileWriteMatrixTest extends RetryProtocolTestCase
{
    /** A second application file: the failed records of one case. */
    private const OTHER_FILE = 'app_failed_file';

    /** @var list<string> */
    private array $hostFiles = [];

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->beforeApplicationDestroyed(function (): void {
            PackageSession::releaseAll();

            foreach ([HostFileFixture::CONNECTION, HostFileFixture::ALIAS, self::OTHER_FILE] as $name) {
                DB::purge($name);
            }

            $this->app['config']->set($this->saved);

            foreach ($this->hostFiles as $file) {
                // The sibling file mutex of the scheduled tick (MaintenanceModule).
                @unlink($file.'.orrery-tick.lock');
                @unlink($file.'.spy');
            }

            // The links first: SqliteFiles::remove() refuses a directory that holds another file.
            foreach (array_filter($this->hostFiles, 'is_link') as $link) {
                @unlink($link);
            }

            foreach (array_filter($this->hostFiles, static fn (string $file): bool => ! is_link($file) && file_exists($file)) as $file) {
                SqliteFiles::remove($file);
            }
        });
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostFileCases(): array
    {
        return [
            'no transaction_mode' => ['plain'],
            'IMMEDIATE (on PHP 8.4+; Laravel ignores the key before)' => ['immediate'],
            'a second connection name with the other mode on the same file' => ['alias_other_mode'],
            'the file reached through a symlink' => ['symlink'],
            'a failed-record connection on another application file' => ['other_file'],
            'a safe cache.default with an unsafe effective schedule store' => ['schedule_store'],
        ];
    }

    /**
     * Rejects: a policy by connection or by mode, an automatic write on a
     * host file (the runner, the fence retention, the tick's mutex, a cache
     * read or write, the workload or the notification), and an awaiting
     * step that only time ends (D17).
     */
    #[DataProvider('hostFileCases')]
    public function test_sqlite_host_file_gets_no_automatic_write(string $case): void
    {
        $this->needsSharedDatabase();
        $wal = ['journal_mode' => 'wal', 'busy_timeout' => 5000];
        $file = $this->hostFiles[] = SqliteFiles::create();
        $config = $this->app['config'];

        foreach (['database.default', 'database.connections.'.HostFileFixture::CONNECTION, 'database.connections.'.HostFileFixture::ALIAS, 'database.connections.'.self::OTHER_FILE, 'queue.connections', 'queue.failed', 'queue.batching', 'cache.default', 'cache.stores', 'cache.schedule_store', 'orrery.waits'] as $key) {
            $this->saved[$key] = $config->get($key);
        }

        $config->set('database.connections.host_setup', HostFileFixture::connection($file, $wal));
        HostFileFixture::createTables(DB::connection('host_setup')->getSchemaBuilder());
        DB::purge('host_setup');

        // The case: the app connection, its alias (the failed records), and the child configuration.
        $path = $file;
        $extra = $wal;
        $alias = $wal;
        $failedConnection = HostFileFixture::ALIAS;
        $connections = [];
        $more = [];

        switch ($case) {
            case 'immediate':
                $extra += ['transaction_mode' => 'IMMEDIATE'];
                $alias += ['transaction_mode' => 'IMMEDIATE'];

                break;
            case 'alias_other_mode':
                $alias += ['transaction_mode' => 'IMMEDIATE'];

                break;
            case 'symlink':
                $path = $this->hostFiles[] = $file.'.link.sqlite';
                self::assertTrue(symlink($file, $path));

                break;
            case 'other_file':
                $other = $this->hostFiles[] = SqliteFiles::create();
                $config->set('database.connections.host_setup', HostFileFixture::connection($other, $wal));
                HostFileFixture::createTables(DB::connection('host_setup')->getSchemaBuilder());
                DB::purge('host_setup');
                $connections[self::OTHER_FILE] = HostFileFixture::connection($other, $wal);
                $failedConnection = self::OTHER_FILE;

                break;
            case 'schedule_store':
                $more = ['cache.default' => 'array', 'cache.schedule_store' => 'app_database'];

                break;
        }

        HostFileFixture::apply($config, $path, $extra, null, $alias);
        HostFileFixture::useHostTables($config);

        foreach ($connections as $name => $connection) {
            $config->set('database.connections.'.$name, $connection);
        }

        $config->set('queue.failed.database', $failedConnection);

        foreach ($more as $key => $value) {
            $config->set($key, $value);
        }

        HostFiles::resetForTesting();
        PackageSession::releaseAll();
        $this->app->forgetInstance('queue.failer');
        $failedDatabase = DB::connection($failedConnection);
        self::assertTrue($this->app->make(HostFiles::class)->isHost(HostFileFixture::CONNECTION), 'The fixture: the queue\'s file is a host file.');
        self::assertTrue($this->app->make(HostFiles::class)->isHost($failedConnection), 'The fixture: the failed records are on a host file.');

        // The seed: three bound failures, then the three operations.
        [$published, $occurrenceA] = $this->failOnHost();
        [$restored, $occurrenceB] = $this->failOnHost();
        [$forgotten, $occurrenceC] = $this->failOnHost();
        $this->killAt(RetryService::AFTER_CLAIM);
        $this->expectKilled(fn () => $this->retry($published, $occurrenceA), RetryService::AFTER_CLAIM);
        $this->killAt(RetryService::AFTER_RECORD_DELETE);
        $this->expectKilled(fn () => $this->retry($restored, $occurrenceB), RetryService::AFTER_RECORD_DELETE);
        $this->killAt(ForgetService::AFTER_CLAIM);
        $this->expectKilled(fn () => $this->app->make(ForgetService::class)->forget($forgotten, $occurrenceC, 'tester', 'cli'), ForgetService::AFTER_CLAIM);
        $ids = [(string) $this->rowOrFail($published)->operation_id, (string) $this->rowOrFail($restored)->operation_id, (string) $this->rowOrFail($forgotten)->operation_id];
        self::assertSame(Operations::BEFORE, $this->operation($ids[1])->mode);
        self::assertSame(0, (int) $failedDatabase->table('failed_jobs')->where('uuid', $restored)->count(), 'The fixture: the record of the abort is deleted.');

        // An old fence row of a finished operation, and a job for the host's own delete.
        $old = Operations::newId();
        DB::connection(HostFileFixture::CONNECTION)->table(Fence::TABLE)->insert(['operation_id' => $old, 'slot' => Fence::PUBLISH, 'state' => Fence::CANCELLED, 'job_id' => null, 'deadline' => Clock::toDecimal(1000.0), 'created_at' => Clock::toDecimal(1000.0)]);
        Queue::connection(HostFileFixture::QUEUE)->push(new BasicJob);
        PackageSession::releaseAll();
        DB::purge(HostFileFixture::CONNECTION);
        DB::purge(HostFileFixture::ALIAS);
        DB::purge($failedConnection);
        $this->moveDatabaseClocks(4000);

        // The host's deleteReserved(), held inside its transaction after its locking read, with busy_timeout 0.
        $spy = $file.'.spy';
        $environment = [
            'ORRERY_TEST_PROVIDERS' => HostFileProvider::class,
            HostFileProvider::FILE => $path,
            HostFileProvider::CONFIG => (string) json_encode((object) $extra),
            HostFileProvider::ALIAS => (string) json_encode((object) $alias),
            HostFileProvider::TABLES => '1',
            HostFileProvider::FAILED => (string) json_encode(['connections' => (object) $connections, 'failed_connection' => $failedConnection, 'config' => (object) $more]),
            HostFileProvider::SPY => $spy,
            HostFileProvider::SPY_NAMES => self::OTHER_FILE,
        ];
        $barrier = Barrier::forTest();
        $native = WorkerProcess::start(['test:host-native', 'queue'], $this->testEnvironment(), [HostFileProvider::CONFIG => (string) json_encode(['busy_timeout' => 0] + $extra)] + $environment + $barrier->holdEnvironment(HostNativeCommand::IN_DELETE));
        $barrier->await(HostNativeCommand::IN_DELETE.'.reached', 60, $native->abandonIfExited());

        $this->mirror()->table('orrery_settings')->where('key', 'observer_tick_at')->delete();
        $schedule = WorkerProcess::start(['test:host-schedule'], $this->testEnvironment(), $environment);
        self::assertSame(0, $schedule->waitForExit(120), $schedule->describe());
        self::assertNotNull($this->mirror()->table('orrery_settings')->where('key', 'observer_tick_at')->value('value'), 'The scheduled tick ran. '.$schedule->output());

        $barrier->arrive(HostNativeCommand::IN_DELETE.'.release');
        self::assertSame(0, $native->waitForExit(60), $native->describe());
        $nativeResults = (array) json_decode(self::lastLine($native), true);
        self::assertSame(['pop', true, null], $nativeResults[0] ?? null);
        self::assertSame(['deleteReserved', true, null], $nativeResults[1] ?? null, 'The host\'s held transaction committed its delete after the tick: the tick wrote nothing and left no lock on the file.');

        // This process: the workload poll and a long-wait notification.
        $parent = [];
        foreach (array_unique([HostFileFixture::CONNECTION, HostFileFixture::ALIAS, $failedConnection]) as $name) {
            PackageStatements::beforeEach($this->app, $name, static function (string $sql) use (&$parent, $name): void {
                $parent[] = $name.': '.$sql;
            });
        }

        Orrery::auth(static fn (): bool => true);
        $this->getJson('/orrery/api/workload')->assertOk();
        $config->set('orrery.waits', ['*' => 1]);
        Orrery::routeMailNotificationsTo('ops@example.test');
        Queue::connection(HostFileFixture::QUEUE)->later(0, new BasicJob);
        $this->app->make(LongWaitPhase::class)->run(new TickBudget(Clock::monotonic() + 30));
        PackageStatements::reset();

        $log = array_map(static fn (string $line): array => (array) json_decode($line, true), array_values(array_filter(explode("\n", (string) @file_get_contents($spy)))));
        $automaticWrites = array_values(array_filter($log, static fn (array $entry): bool => $entry['source'] === 'package'
            && $entry['mode'] !== PackageSession::OPERATOR
            && ($entry['kind'] !== PackageSession::EXECUTED || preg_match('/^\s*(select|pragma)\b/i', (string) $entry['sql']) !== 1)));
        // Every process but the host's own (test:host-native): the scheduler and the tick.
        $applicationWrites = array_values(array_filter($log, static fn (array $entry): bool => $entry['source'] === 'laravel'
            && $entry['command'] !== 'test:host-native'
            && preg_match('/^\s*(select|pragma)\b/i', (string) $entry['sql']) !== 1));

        self::assertSame([], $automaticWrites, 'No automatic package write (and no BEGIN) on a host file.');
        self::assertSame([], $applicationWrites, 'No write through the application\'s connections: no cache or schedule lock row on the host file.');
        self::assertSame([], array_values(array_filter($log, static fn (array $entry): bool => $entry['source'] === 'log')), 'No error log line.');
        self::assertSame([], array_values(array_filter($parent, static fn (string $statement): bool => preg_match('/^\w+: \s*(select|pragma)\b/i', $statement) !== 1)), 'The workload poll and the notification write nothing to a host file.');
        self::assertSame(1, (int) DB::connection(HostFileFixture::CONNECTION)->table(Fence::TABLE)->where('operation_id', $old)->count(), 'The fence retention left the host file alone.');

        foreach ($ids as $id) {
            self::assertSame(Operations::SQLITE_HOST_FILE, $this->operation($id)->awaiting_reason, $id);
        }

        // The resolve route, orrery:resolve, and a later retry of the forgotten occurrence each finish one.
        $this->resolveAsOperator($ids[0]);
        self::assertSame(Operations::ABORTED, $this->operation($ids[0])->state);

        self::assertSame(0, Artisan::call('orrery:resolve', ['id' => $ids[1]]));
        self::assertSame(Operations::ABORTED, $this->operation($ids[1])->state);
        self::assertSame(1, (int) DB::connection($failedConnection)->table('failed_jobs')->where('uuid', $restored)->count(), 'The record was restored once.');

        $retry = $this->retry($forgotten, $occurrenceC);
        self::assertSame(Operations::ABORTED, $this->operation($ids[2])->state, 'The later retry ran the forget\'s pending step first.');
        self::assertOutcome(RetryResult::ACCEPTED, null, $retry);
    }

    /**
     * Pushes a failing job onto `app_queue` and runs it once in this
     * process; the package's provider logs and binds the failure.
     *
     * @return array{0: string, 1: int}
     */
    private function failOnHost(): array
    {
        static $listening = [];

        if (! isset($listening[spl_object_id($this->app)])) {
            $listening[spl_object_id($this->app)] = true;
            Event::listen(LaravelJobFailed::class, function (LaravelJobFailed $event): void {
                $this->app['queue.failer']->log($event->connectionName, $event->job->getQueue(), $event->job->getRawBody(), $event->exception);
            });
        }

        $id = Queue::connection(HostFileFixture::QUEUE)->push(new FailingJob);
        $payload = DB::connection(HostFileFixture::CONNECTION)->table('jobs')->where('id', $id)->value('payload');
        self::assertIsString($payload);
        $uuid = self::uuidOf($payload);
        $options = new WorkerOptions;
        $options->sleep = 0;
        $options->maxTries = 1;
        $this->app->make('queue.worker')->runNextJob(HostFileFixture::QUEUE, 'default', $options);

        $row = $this->rowOrFail($uuid);
        self::assertSame('failed', $row->status);
        self::assertNotNull($row->provider_id, 'The failure is bound to its record.');

        return [$uuid, (int) $row->occurrence_id];
    }

    private static function lastLine(WorkerProcess $worker): string
    {
        $lines = array_values(array_filter(explode("\n", trim($worker->output())), static fn (string $line): bool => str_starts_with(trim($line), '[')));

        return (string) end($lines);
    }
}
