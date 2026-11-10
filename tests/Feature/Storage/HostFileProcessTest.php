<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Storage;

use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\Fixtures\HostFile\HostBatchCommand;
use Justrau\Orrery\Tests\Support\Fixtures\HostFile\HostFileFixture;
use Justrau\Orrery\Tests\Support\Fixtures\HostFile\HostFileProvider;
use Justrau\Orrery\Tests\Support\Fixtures\HostFile\HostNativeCommand;
use Justrau\Orrery\Tests\Support\Fixtures\HostFile\HostReaderCommand;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use Justrau\Orrery\Tests\TestCase;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The process cases of D18:
 * the application's SQLite file is used by other processes
 * while the package works in its own, and each case runs once more as an
 * uninstrumented control (WorkerProcess::FOREIGN_QUEUE: Laravel's own
 * `database` connector, so no package hook runs on the queue calls).
 *
 * Each test builds its own application file (HostFileFixture) next to the
 * harness, so it runs on every engine that has a shared database; the
 * mirror is the harness's. On in-memory SQLite the tests skip (a child
 * process cannot share that database).
 */
#[Group('process')]
#[Group('shared-database')]
final class HostFileProcessTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    /** @var list<string> the lock files that a case made next to a file */
    private array $locks = [];

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('Two processes need a database that they share: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        Orrery::auth(static fn (): bool => true);

        $this->beforeApplicationDestroyed(function (): void {
            PackageSession::releaseAll();

            // The files go below, before the fresh mode's rollback. The fence
            // migration's down() reaches every configured `database` queue
            // connection, so the queue connections of the files go first.
            $config = $this->app['config'];
            $queues = (array) $config->get('queue.connections', []);
            unset($queues[HostFileFixture::QUEUE], $queues[HostFileFixture::SECOND_QUEUE]);
            $config->set('queue.connections', $queues);

            foreach ([HostFileFixture::CONNECTION, HostFileFixture::ALIAS, HostFileFixture::SECOND] as $name) {
                DB::purge($name);
            }

            foreach ($this->locks as $lock) {
                @unlink($lock);
            }

            foreach ($this->files as $file) {
                SqliteFiles::remove($file);
            }
        });
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function journals(): array
    {
        return [
            'rollback journal' => [['journal_mode' => 'delete', 'busy_timeout' => 0]],
            'WAL' => [['journal_mode' => 'wal', 'busy_timeout' => 5000]],
        ];
    }

    /**
     * Rejects: any non-SQLite open of a host file.
     * Process A dispatches a Bus::batch() of three jobs; Laravel runs
     * its bulk() inside the batch repository's transaction on the file, and
     * A holds that transaction open right after the jobs insert. Another
     * client of the file (this process, busy_timeout 0) writes meanwhile.
     * Its write fails at once, as in the control, A commits, and every row
     * that both committed is present. (A plain PHP read of the file header
     * in A, as an earlier version did, drops A's locks: B's write then succeeds
     * and A's commit fails, and integrity_check alone does not show it.)
     *
     * @param  array<string, mixed>  $extra
     */
    #[DataProvider('journals')]
    public function test_header_read_never_drops_sqlite_locks(array $extra): void
    {
        $outcomes = [];

        foreach (['package' => [], 'control' => [WorkerProcess::FOREIGN_QUEUE => '1']] as $run => $control) {
            $file = $this->hostFile($extra);
            $barrier = Barrier::forTest();
            $a = WorkerProcess::start(['test:host-batch', '3'], $this->testEnvironment(), $this->hostEnvironment($file, $extra) + $control + $barrier->holdEnvironment(HostBatchCommand::AFTER_BULK));
            $a->waitUntil(static fn (): bool => ! $a->isRunning() || $barrier->arrived(HostBatchCommand::AFTER_BULK.'.reached'), 60, 'A holds its batch transaction after the jobs insert');
            self::assertTrue($barrier->arrived(HostBatchCommand::AFTER_BULK.'.reached'), $a->describe());

            $other = $this->otherClient($file);
            $held = $this->tryWrite($other, 'during');
            $barrier->arrive(HostBatchCommand::AFTER_BULK.'.release');
            $exit = $a->waitForExit(60);
            $after = $this->tryWrite($other, 'after');

            $outcomes[$run] = [
                'A exit' => $exit,
                'write while A holds' => $held,
                'write after A' => $after,
                'jobs' => $other->query("select queue, count(*) from jobs group by queue order by queue")->fetchAll(PDO::FETCH_NUM),
                'batches' => (int) $other->query('select count(*) from job_batches')->fetchColumn(),
                'integrity' => $other->query('pragma integrity_check')->fetchColumn(),
            ];
            $other = null;
        }

        self::assertSame($outcomes['control'], $outcomes['package'], 'The package changes nothing that the other client or A sees.');
        self::assertSame(0, $outcomes['package']['A exit']);
        self::assertSame('busy', $outcomes['package']['write while A holds'], 'A held its write lock.');
        self::assertSame([['after', 1], ['default', 3]], $outcomes['package']['jobs'], 'Every row that both committed is present.');
        self::assertSame(1, $outcomes['package']['batches']);
    }

    /**
     * Rejects: automatic reads in rollback mode (D18).
     *
     * A package SELECT on the file is held during its fetch in one process
     * (test:host-reader) while the application's native pop(),
     * deleteReserved(), pop() and deleteAndRelease() commit in another, with
     * the file's own busy timeout. A control runs the same calls on a copy of
     * the file without the package and without the reader. In rollback mode
     * no automatic package reader opens on the file (a reader's shared lock
     * would make the native commits fail with busy_timeout 0), the backlog
     * reads unknown and the health warning shows; in WAL mode the reader
     * reads through its read-only session. In both modes the native results
     * and the jobs rows equal the control's.
     *
     * @param  array<string, mixed>  $extra
     */
    #[DataProvider('journals')]
    public function test_rollback_journal_host_file_gets_no_automatic_read(array $extra): void
    {
        $rollback = $extra['journal_mode'] === 'delete';
        $file = $this->hostFile($extra);
        $control = $this->hostFile($extra);
        $this->seedJobs($file, 3);
        $this->seedJobs($control, 3);

        $barrier = Barrier::forTest();
        $reader = WorkerProcess::start(['test:host-reader'], $this->testEnvironment(), $this->hostEnvironment($file, $extra) + $barrier->holdEnvironment(HostReaderCommand::DURING_FETCH));
        $reader->waitUntil(static fn (): bool => ! $reader->isRunning() || $barrier->arrived(HostReaderCommand::DURING_FETCH.'.reached'), 60, 'the package read is held or refused');
        $native = WorkerProcess::start(['test:host-native', 'queue'], $this->testEnvironment(), $this->hostEnvironment($file, $extra));
        self::assertSame(0, $native->waitForExit(60), $native->describe());
        $barrier->arrive(HostReaderCommand::DURING_FETCH.'.release');
        self::assertSame(0, $reader->waitForExit(60), $reader->describe());

        $uninstrumented = WorkerProcess::start(['test:host-native', 'queue'], $this->testEnvironment(), $this->hostEnvironment($control, $extra) + [WorkerProcess::FOREIGN_QUEUE => '1']);
        self::assertSame(0, $uninstrumented->waitForExit(60), $uninstrumented->describe());

        self::assertSame(self::lastLine($uninstrumented), self::lastLine($native), 'The native calls give the results of the control.');
        self::assertSame([['pop', true, null], ['deleteReserved', true, null], ['pop', true, null], ['deleteAndRelease', true, null]], json_decode(self::lastLine($native), true));
        self::assertSame($this->jobRows($control), $this->jobRows($file), 'The jobs rows equal the control\'s.');
        $read = (array) json_decode(self::lastLine($reader), true);

        if ($rollback) {
            self::assertSame(['reader' => 'refused'], $read, 'No automatic package reader opens on a rollback-mode file.');

            // The dashboard of this process: the backlog is unknown, and the health warning shows.
            HostFileFixture::apply($this->app['config'], $file, $extra);
            $workload = collect($this->getJson('/orrery/api/workload')->assertOk()->json())->keyBy('connection');
            self::assertNull($workload[HostFileFixture::QUEUE]['length'], 'The backlog is unknown, never 0.');
            self::assertContains(HostFiles::READS_WARNING, array_column($this->getJson('/orrery/api/health')->assertOk()->json('sqlite_warnings'), 'message'));
            self::assertSame([], array_values(array_filter(PackageSession::sessionsForTesting(), static fn (array $entry): bool => $entry['name'] === HostFileFixture::CONNECTION)), 'No package session of this process opened on the file.');
        } else {
            self::assertSame(['reader' => 'read', 'rows' => 3, 'mode' => PackageSession::AUTOMATIC, 'writable' => false], $read, 'The reader read through its automatic read-only session.');
        }
    }

    /**
     * Rejects: admitting a file whose host locks exclusively.
     * The file is in WAL with busy_timeout 5,000 and the
     * application sets `locking_mode` EXCLUSIVE. Reader first: a package
     * reader process starts before the application's first database
     * operation, a bulk(); host first: the application process holds its
     * connection open after the bulk() while a package reader starts. The
     * committed rows and the results equal an uninstrumented control's, and
     * no package session opens on the file. An alias name of the file that
     * alone sets EXCLUSIVE (also as `main.locking_mode`) decides the same;
     * the ordinary NORMAL locking keeps the package's reads.
     */
    public function test_exclusive_locking_mode_gets_no_automatic_access(): void
    {
        $exclusive = ['journal_mode' => 'wal', 'busy_timeout' => 5000, 'pragmas' => ['locking_mode' => 'EXCLUSIVE']];

        // Reader first.
        $file = $this->hostFile(['journal_mode' => 'wal', 'busy_timeout' => 5000]);
        $control = $this->hostFile(['journal_mode' => 'wal', 'busy_timeout' => 5000]);
        $this->seedJobs($file, 1);
        $this->seedJobs($control, 1);
        $barrier = Barrier::forTest();
        $reader = WorkerProcess::start(['test:host-reader'], $this->testEnvironment(), $this->hostEnvironment($file, $exclusive) + $barrier->holdEnvironment(HostReaderCommand::DURING_FETCH));
        $reader->waitUntil(static fn (): bool => ! $reader->isRunning() || $barrier->arrived(HostReaderCommand::DURING_FETCH.'.reached'), 60, 'the package read is held or refused');
        $host = WorkerProcess::start(['test:host-native', 'bulk'], $this->testEnvironment(), $this->hostEnvironment($file, $exclusive));
        self::assertSame(0, $host->waitForExit(60), $host->describe());
        $barrier->arrive(HostReaderCommand::DURING_FETCH.'.release');
        self::assertSame(0, $reader->waitForExit(60), $reader->describe());
        $uninstrumented = WorkerProcess::start(['test:host-native', 'bulk'], $this->testEnvironment(), $this->hostEnvironment($control, $exclusive) + [WorkerProcess::FOREIGN_QUEUE => '1']);
        self::assertSame(0, $uninstrumented->waitForExit(60), $uninstrumented->describe());

        self::assertSame(self::lastLine($uninstrumented), self::lastLine($host), 'Reader first: the bulk() gives the result of the control.');
        self::assertSame(['reader' => 'refused'], json_decode(self::lastLine($reader), true), 'Reader first: no package session opens.');
        self::assertSame([['bulk', true, null]], json_decode(self::lastLine($host), true));
        self::assertSame($this->jobRows($control), $this->jobRows($file));

        // Host first: the application keeps its exclusive connection open.
        $file = $this->hostFile(['journal_mode' => 'wal', 'busy_timeout' => 5000]);
        $barrier = Barrier::forTest();
        $host = WorkerProcess::start(['test:host-native', 'bulk'], $this->testEnvironment(), $this->hostEnvironment($file, $exclusive) + $barrier->holdEnvironment(HostNativeCommand::AFTER_OPS));
        $host->waitUntil(static fn (): bool => ! $host->isRunning() || $barrier->arrived(HostNativeCommand::AFTER_OPS.'.reached'), 60, 'the application holds its connection');
        $reader = WorkerProcess::start(['test:host-reader'], $this->testEnvironment(), $this->hostEnvironment($file, $exclusive));
        self::assertSame(0, $reader->waitForExit(60), $reader->describe());
        $barrier->arrive(HostNativeCommand::AFTER_OPS.'.release');
        self::assertSame(0, $host->waitForExit(60), $host->describe());
        self::assertSame(['reader' => 'refused'], json_decode(self::lastLine($reader), true), 'Host first: no package session opens.');
        self::assertSame([['bulk', true, null]], json_decode(self::lastLine($host), true));
        self::assertSame(2, count($this->jobRows($file)));

        // An alias name of the same real file decides for every name; NORMAL keeps the reads.
        $config = $this->app['config'];
        $files = $this->app->make(HostFiles::class);

        foreach ([['locking_mode' => 'EXCLUSIVE'], ['main.locking_mode' => 'exclusive']] as $pragmas) {
            HostFileFixture::apply($config, $file, ['journal_mode' => 'wal', 'busy_timeout' => 5000], null, ['journal_mode' => 'wal', 'busy_timeout' => 5000, 'pragmas' => $pragmas]);
            HostFiles::resetForTesting();
            self::assertFalse($files->allowsReads(HostFileFixture::CONNECTION), json_encode($pragmas).' on the alias decides for the queue\'s name.');
            self::assertFalse($files->allowsReads(HostFileFixture::ALIAS));
        }

        $reader = WorkerProcess::start(['test:host-reader'], $this->testEnvironment(), $this->hostEnvironment($file, ['journal_mode' => 'wal', 'busy_timeout' => 5000]));
        self::assertSame(0, $reader->waitForExit(60), $reader->describe());
        self::assertSame(['reader' => 'read', 'rows' => 2, 'mode' => PackageSession::AUTOMATIC, 'writable' => false], json_decode(self::lastLine($reader), true), 'NORMAL locking keeps the reads.');
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function spyModes(): array
    {
        return [
            'rollback journal, busy_timeout 0' => [['journal_mode' => 'delete', 'busy_timeout' => 0], false],
            'rollback journal, busy_timeout unset' => [['journal_mode' => 'delete'], false],
            'WAL, busy_timeout 5000' => [['journal_mode' => 'wal', 'busy_timeout' => 5000], true],
        ];
    }

    /**
     * Rejects: a gate outside PackageSession::for(), a refusal reported as
     * an error or counted as a drop, a cache-store read without
     * cacheIsSafe(), and a read-write automatic session.
     *
     * An access spy (HostFileProvider) logs every package statement on the
     * application's file with its access mode, every Laravel statement on
     * it, and every error log line, in each worker process. The file holds
     * the queue, the failed jobs, the batches and the default cache store.
     * It is seeded in WAL with active rows, a job that the queue no longer
     * holds, a bound failed row, an unresolved bulk group and the fence
     * table, next to a second queue connection on a WAL file. Then, in each
     * mode: a real `schedule:run` (its orrery:tick runs as a subprocess), a
     * Bus::batch() dispatch with a bulk(), the polls of /api/workload,
     * /api/health and /api/batches, and orrery:reconcile.
     *
     * In the modes without automatic reads no automatic package statement
     * reaches the file, the values are unknown, and orrery:reconcile reads
     * it as an operator. In WAL every automatic package statement is a read
     * on a read-only session. In every mode: no error log line, no health
     * window (no error, no drop), a closed breaker, no "run orrery:install
     * --fence" hint, and the second connection is reconciled. The
     * operator-resolve clause and the due-operation fixture are tested elsewhere.
     *
     * The application's own statements on the file are not the package's:
     * the batch dispatch's, and those of the scheduler process itself (it
     * reads its interrupt key from the cache store). orrery:snapshot's lock
     * in the default cache store is the named interim exception until orrery_samples.
     *
     * @param  array<string, mixed>  $extra
     */
    #[DataProvider('spyModes')]
    public function test_host_file_access_spy(array $extra, bool $reads): void
    {
        $wal = ['journal_mode' => 'wal', 'busy_timeout' => 5000];
        $file = $this->hostFile($wal);
        $second = $this->files[] = SqliteFiles::create();
        $config = $this->app['config'];
        $config->set('database.connections.host_setup', HostFileFixture::connection($second, $wal));
        HostFileFixture::createTables(DB::connection('host_setup')->getSchemaBuilder(), true);
        DB::purge('host_setup');

        // The seed, in WAL: the package reads and binds as usual.
        HostFileFixture::apply($config, $file, $wal, $second);
        HostFileFixture::useHostTables($config);
        $this->app->forgetInstance('queue.failer');
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobFailed::class, function (\Illuminate\Queue\Events\JobFailed $event): void {
            $this->app['queue.failer']->log($event->connectionName, $event->job->getQueue(), $event->job->getRawBody(), $event->exception);
        });
        $app = \Illuminate\Support\Facades\Queue::connection(HostFileFixture::QUEUE);
        $app->push(new \Justrau\Orrery\Tests\Feature\Jobs\FailingJob);
        $options = new \Illuminate\Queue\WorkerOptions;
        $options->sleep = 0;
        $options->maxTries = 1;
        $this->app->make('queue.worker')->runNextJob(HostFileFixture::QUEUE, 'default', $options);
        $app->push(new BasicJob);
        $gone = \Illuminate\Support\Facades\Queue::connection(HostFileFixture::QUEUE)->push(new BasicJob);
        DB::connection(HostFileFixture::CONNECTION)->table('jobs')->where('id', $gone)->delete();
        $app->bulk([new BasicJob, new BasicJob]);
        $secondGone = \Illuminate\Support\Facades\Queue::connection(HostFileFixture::SECOND_QUEUE)->push(new BasicJob);
        DB::connection(HostFileFixture::SECOND)->table('jobs')->where('id', $secondGone)->delete();
        $mirror = $this->mirror();
        $mirror->table('orrery_jobs')->whereNotNull('bulk_floor_id')->update(['job_id_unresolved' => true, 'job_id' => null]);
        $row = static fn (string $connection, int $jobId): ?object => $mirror->table('orrery_jobs')->where('connection', $connection)->where(static function ($query) use ($jobId): void {
            $query->where('job_id', $jobId)->orWhere('prev_job_id', $jobId);
        })->first();
        self::assertNotNull($mirror->table('orrery_jobs')->where('status', 'failed')->value('provider_id'), 'The seed: a bound failed row.');
        self::assertSame(2, (int) $mirror->table('orrery_jobs')->where('job_id_unresolved', true)->count(), 'The seed: an unresolved bulk group.');
        self::assertNotNull($row(HostFileFixture::QUEUE, (int) $gone));
        self::assertNull($row(HostFileFixture::QUEUE, (int) $gone)->absent_since);
        $mirror->table('orrery_health_events')->delete();

        // Close every connection of this process to the file, then put the file in the mode of the case.
        PackageSession::releaseAll();
        DB::purge(HostFileFixture::CONNECTION);
        DB::purge(HostFileFixture::SECOND);
        HostFileFixture::apply($config, $file, $extra, $second);
        DB::connection(HostFileFixture::CONNECTION)->select('select 1');
        DB::purge(HostFileFixture::CONNECTION);
        HostFiles::resetForTesting();
        self::assertSame($reads ? 'wal' : 'delete', (new PDO('sqlite:'.$file))->query('pragma journal_mode')->fetchColumn());

        $spy = $file.'.spy';
        $this->locks[] = $spy;
        $environment = $this->hostEnvironment($file, $extra, [HostFileProvider::SECOND => $second, HostFileProvider::TABLES => '1', HostFileProvider::SPY => $spy]);

        $mirror->table('orrery_settings')->where('key', 'observer_tick_at')->delete();
        $schedule = WorkerProcess::start(['test:host-schedule'], $this->testEnvironment(), $environment);
        self::assertSame(0, $schedule->waitForExit(120), $schedule->describe());
        self::assertNotNull($mirror->table('orrery_settings')->where('key', 'observer_tick_at')->value('value'), 'The scheduled tick ran. '.$schedule->output());
        self::assertNotNull($row(HostFileFixture::SECOND_QUEUE, (int) $secondGone)->absent_since, 'The tick reconciled the second connection.');
        $batch = WorkerProcess::start(['test:host-batch', '2'], $this->testEnvironment(), $environment);
        self::assertSame(0, $batch->waitForExit(60), $batch->describe());

        $parent = [];
        \Justrau\Orrery\Tests\Support\PackageStatements::beforeEach($this->app, HostFileFixture::CONNECTION, static function (string $sql) use (&$parent): void {
            $parent[] = $sql;
        });
        $workload = collect($this->getJson('/orrery/api/workload')->assertOk()->json())->keyBy('connection');
        $health = $this->getJson('/orrery/api/health')->assertOk();
        $batches = $this->getJson('/orrery/api/batches')->assertOk();
        $afterTick = $row(HostFileFixture::QUEUE, (int) $gone)->absent_since;

        $reconcile = WorkerProcess::start(['orrery:reconcile'], $this->testEnvironment(), $environment);
        self::assertSame(0, $reconcile->waitForExit(120), $reconcile->describe());

        $log = array_map(static fn (string $line): array => (array) json_decode($line, true), array_values(array_filter(explode("\n", (string) @file_get_contents($spy)))));
        $package = array_values(array_filter($log, static fn (array $entry): bool => $entry['source'] === 'package'));
        $automatic = array_values(array_filter($package, static fn (array $entry): bool => $entry['mode'] !== PackageSession::OPERATOR));
        $foreign = array_values(array_filter($log, static fn (array $entry): bool => $entry['source'] === 'laravel'
            && ! in_array($entry['command'], ['test:host-batch', 'test:host-schedule'], true)));

        self::assertSame([], array_values(array_filter($log, static fn (array $entry): bool => $entry['source'] === 'log')), 'No error log line: no report.');
        self::assertSame([], $foreign, 'No statement of the package through the application\'s connections (a cache store read included).');
        self::assertSame(0, (int) $mirror->table('orrery_health_events')->count(), 'No health window: no error, no drop, no open breaker.');
        self::assertFalse(\Justrau\Orrery\Runtime\Watcher::instance()->breakerOpen());
        self::assertNotContains(HostFileFixture::QUEUE, $health->json('missing_fence_tables'), 'No "run orrery:install --fence" hint.');
        self::assertContains('orrery:reconcile', array_column(array_filter($package, static fn (array $entry): bool => $entry['mode'] === PackageSession::OPERATOR), 'command'), 'orrery:reconcile reads the file as an operator.');
        self::assertNotNull($row(HostFileFixture::QUEUE, (int) $gone)->absent_since, 'orrery:reconcile reconciled the file.');

        if (! $reads) {
            self::assertSame([], $automatic, 'No automatic package statement on the file.');
            // The interim operator sessions of the legacy retry phases write nothing here (no
            // retry is open), and the fence retention skips the file.
            self::assertSame(['orrery:reconcile'], array_values(array_unique(array_column($package, 'command'))), 'Only orrery:reconcile reaches the file.');
            self::assertSame([], $parent, 'No package statement of the polls on the file.');
            self::assertNull($afterTick, 'The tick did not reconcile against the file.');
            self::assertNull($workload[HostFileFixture::QUEUE]['length'], 'The backlog is unknown, never 0.');
            self::assertTrue((bool) $workload[HostFileFixture::QUEUE]['backlog_unknown']);
            self::assertNull($batches->json('batches'), 'The batches are unknown, never empty.');
            self::assertSame('sqlite_reads_not_allowed', $batches->json('unknown_reason'));
            self::assertContains(HostFileFixture::QUEUE, $health->json('fence_unknown_connections'));
            self::assertContains(HostFiles::READS_WARNING, array_column($health->json('sqlite_warnings'), 'message'));
            self::assertSame(2, (int) $mirror->table('orrery_jobs')->where('job_id_unresolved', true)->count(), 'The bulk group stays unresolved.');
        } else {
            self::assertNotSame([], $automatic, 'WAL: the package reads the file automatically.');
            self::assertSame([], array_values(array_filter($automatic, static fn (array $entry): bool => $entry['mode'] !== PackageSession::AUTOMATIC
                || ! in_array($entry['kind'], [PackageSession::EXECUTED], true)
                || preg_match('/^\s*(select|pragma)\b/i', (string) $entry['sql']) !== 1)), 'WAL: every automatic statement is a read on the read-only session.');
            self::assertIsInt($workload[HostFileFixture::QUEUE]['length']);
            self::assertIsArray($batches->json('batches'));
            self::assertNotContains(HostFileFixture::QUEUE, $health->json('fence_unknown_connections'));
            self::assertNotNull($afterTick, 'The tick reconciled the file.');
        }
    }

    /**
     * Seeds $count jobs into the jobs table of a file, through a plain PDO
     * that is closed again.
     */
    private function seedJobs(string $file, int $count): void
    {
        $pdo = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $insert = $pdo->prepare('insert into jobs (queue, payload, attempts, reserved_at, available_at, created_at) values (?, ?, 0, null, 1, 1)');

        for ($i = 1; $i <= $count; $i++) {
            $insert->execute(['default', (string) json_encode([
                'uuid' => sprintf('00000000-0000-4000-8000-%012d', $i),
                'displayName' => BasicJob::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries' => null,
                'maxExceptions' => null,
                'failOnTimeout' => false,
                'backoff' => null,
                'timeout' => null,
                'retryUntil' => null,
                'data' => ['commandName' => BasicJob::class, 'command' => serialize(new BasicJob)],
                'attempts' => 0,
            ])]);
        }
    }

    /**
     * The jobs rows of a file without their times: id, queue, attempts and
     * whether the row is reserved.
     *
     * @return list<list<mixed>>
     */
    private function jobRows(string $file): array
    {
        $pdo = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        return array_map(static fn (array $row): array => [(int) $row[0], (string) $row[1], (int) $row[2], $row[3] !== null], $pdo->query('select id, queue, attempts, reserved_at from jobs order by id')->fetchAll(PDO::FETCH_NUM));
    }

    /**
     * The last output line of a worker: the JSON line of a test command.
     */
    private static function lastLine(WorkerProcess $worker): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $worker->output())), static fn (string $line): bool => $line !== ''));

        return (string) end($lines);
    }

    /**
     * A new application file with the application's tables, built through a
     * connection that is closed again, so no PDO of this process stays open
     * on it.
     *
     * @param  array<string, mixed>  $extra
     */
    private function hostFile(array $extra): string
    {
        $file = $this->files[] = SqliteFiles::create();
        $this->locks[] = $file.'.orrery-tick.lock';
        $this->locks[] = $file.'.orrery-snapshot.lock';
        $config = $this->app['config'];
        $config->set('database.connections.host_setup', HostFileFixture::connection($file, $extra));
        HostFileFixture::createTables(DB::connection('host_setup')->getSchemaBuilder());
        DB::purge('host_setup');

        return $file;
    }

    /**
     * The worker CLI variables of a case.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, string>
     */
    private function hostEnvironment(string $file, array $extra, array $more = []): array
    {
        return [
            'ORRERY_TEST_PROVIDERS' => HostFileProvider::class,
            HostFileProvider::FILE => $file,
            HostFileProvider::CONFIG => (string) json_encode((object) $extra),
        ] + $more;
    }

    /**
     * Another client of the file: a plain SQLite PDO with busy_timeout 0.
     */
    private function otherClient(string $file): PDO
    {
        $pdo = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('pragma busy_timeout = 0');

        return $pdo;
    }

    /**
     * One insert of the other client: `ok`, or `busy` when the file is locked.
     */
    private function tryWrite(PDO $pdo, string $queue): string
    {
        try {
            $pdo->exec("insert into jobs (queue, payload, attempts, reserved_at, available_at, created_at) values ('{$queue}', 'x', 0, null, 1, 1)");

            return 'ok';
        } catch (PDOException $error) {
            if (str_contains($error->getMessage(), 'locked') || str_contains($error->getMessage(), 'busy')) {
                return 'busy';
            }

            throw $error;
        }
    }
}
