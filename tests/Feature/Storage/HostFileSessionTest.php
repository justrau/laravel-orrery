<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Storage;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Events\LongWaitDetected;
use Justrau\Orrery\Listeners\SendNotification;
use Justrau\Orrery\Maintenance\Reconciliation;
use Justrau\Orrery\Maintenance\SiblingFileMutex;
use Justrau\Orrery\Maintenance\SiblingLocks;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickPhase;
use Justrau\Orrery\Maintenance\TickRunner;
use Justrau\Orrery\Monitoring\Phases\StuckNotificationsPhase;
use Justrau\Orrery\Monitoring\StuckIncidents;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Registration\Modules\MaintenanceModule;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\FenceTargets;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\HostFileAccessNotAllowed;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\MirrorRefused;
use Justrau\Orrery\Storage\OperatorContext;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageSessionReleased;
use Justrau\Orrery\Storage\SiblingFileUnavailable;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Jobs\FailingJob;
use Justrau\Orrery\Tests\Support\PackageStatements;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The SQLite host files (D17, D18): the
 * sibling mirror file, the refusal of a shared mirror, the D18 admission
 * from the configuration, the package-owned initialization of a host-file
 * session, the access modes, and the rule that no automatic package write
 * reaches an application SQLite file.
 *
 * Each test builds its own application SQLite file (`app`, in WAL with a
 * busy timeout unless a test says otherwise) with the queue, failed-job,
 * batch and cache tables of an application, so it runs on every engine of
 * the harness. The harness's own connections stay as they are.
 */
final class HostFileSessionTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    /** @var list<string> the lock files that a test made next to a SQLite file */
    private array $locks = [];

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        Orrery::auth(static fn (): bool => true);

        $this->beforeApplicationDestroyed(function (): void {
            PackageSession::releaseAll();
            DB::purge('app');
            DB::purge('app_alias');
            $this->app['config']->set($this->saved);

            foreach ($this->locks as $lock) {
                @unlink($lock);
            }

            foreach ($this->files as $file) {
                SqliteFiles::remove($file);
            }
        });
    }

    /**
     * Rejects: a mirror in the queue's file by default.
     */
    public function test_sqlite_default_uses_a_sibling_mirror_file(): void
    {
        $file = $this->useHostFile();
        $sibling = dirname($file).'/primary.orrery.sqlite';
        $sessions = $this->app->make(PackageSession::class);

        self::assertSame($sibling, $sessions->siblingPath());
        self::assertSame($sibling, $sessions->mirror()->getDatabaseName());

        $this->runJob();

        self::assertSame(1, $this->siblingPdo($sibling)->query("select count(*) from orrery_jobs where status = 'completed'")->fetchColumn(), 'The mirror row is in the sibling file.');
        self::assertSame([], $this->packageTables($file, ['orrery_publications']), "The queue's file holds no package table.");
        self::assertSame(PackageSchema::VERSION, (int) $this->siblingPdo($sibling)->query('select version from orrery_schema')->fetchColumn(), 'The sibling installed its own schema.');
    }

    /**
     * Rejects: a warning-only mode for an explicit orrery.database on the queue's SQLite file,
     * and a health response that leaves guard_invalid out, or fixes it, while the mirror is refused.
     */
    public function test_explicit_shared_sqlite_mirror_is_refused(): void
    {
        $file = $this->useHostFile();
        $this->app['config']->set('database.connections.app_alias', $this->app['config']->get('database.connections.app'));
        $this->app['config']->set('orrery.database', 'app_alias');
        $sessions = $this->app->make(PackageSession::class);

        try {
            $sessions->mirror();
            self::fail('The shared mirror is refused.');
        } catch (MirrorRefused $refused) {
            self::assertSame('orrery.database shares the SQLite file of connection [app]; leave orrery.database empty (a sibling file) or name a file of its own.', $refused->getMessage());
        }

        Queue::connection('app_queue')->push(new BasicJob);

        self::assertSame([], $this->packageTables($file, ['orrery_publications']), 'No package write reached the shared file.');
        self::assertTrue(Watcher::instance()->breakerOpen(), 'The watcher stays degraded.');
        $health = $this->getJson('/orrery/api/health');
        $health->assertOk();
        self::assertNull($health->json('windows'), 'The stored windows are unknown, never empty.');
        self::assertSame($sessions->mirrorRefusal(), $health->json('sqlite_shared_mirror'));
        // The guard warning comes from the configuration, so it is known on this branch too.
        self::assertFalse($health->json('guard_invalid'));

        $this->app['config']->set('orrery.guard', 'no-such-guard');
        $refused = $this->getJson('/orrery/api/health')->assertOk();
        self::assertSame($sessions->mirrorRefusal(), $refused->json('sqlite_shared_mirror'));
        self::assertTrue($refused->json('guard_invalid'), 'The mirror-refusal branch hides an invalid guard.');
    }

    /**
     * Rejects: a shared-mirror refusal that compares spellings only,
     * so an orrery.database that reaches the queue's file through a
     * `file:` URI or a hard link (or another letter case on a
     * case-insensitive file system) is accepted and every observation
     * writes into the application's file. It would still pass a refusal of
     * every `file:` URI, also of one that names a file of its own; that is
     * the rule.
     */
    public function test_a_mirror_that_reaches_the_queue_file_by_another_name_is_refused(): void
    {
        $file = $this->useHostFile();
        $link = $file.'.link.sqlite';
        self::assertTrue(link($file, $link));
        $this->beforeApplicationDestroyed(static fn () => @unlink($link));
        $config = $this->app['config'];

        foreach (['by a hard link' => $link, 'by a file: URI' => 'file:'.$file] as $case => $database) {
            $config->set('database.connections.app_alias', array_replace((array) $config->get('database.connections.app'), ['database' => $database]));
            $config->set('orrery.database', 'app_alias');
            PackageSession::releaseAll();

            self::assertNotNull($this->app->make(PackageSession::class)->mirrorRefusal(), "A mirror that reaches the queue's file {$case} was accepted.");
        }

        self::assertSame(PackageSession::MIRROR_FILE_URI_REFUSAL, $this->app->make(PackageSession::class)->mirrorRefusal());
    }

    /**
     * Rejects: a shared-mirror refusal that works in one direction only: a
     * queue connection given as a `file:` URI (with a query, an authority or
     * percent-encoded) and an orrery.database that names the same file by
     * its plain path was accepted, so every observation wrote into the
     * application's file (D17).
     * Still passes: a refusal that also refuses a mirror on another file
     * when the URI's file cannot be resolved; here it resolves.
     */
    public function test_a_mirror_on_the_file_of_a_queue_uri_is_refused(): void
    {
        $file = $this->useHostFile();
        $own = $this->newFile();
        $config = $this->app['config'];
        $app = (array) $config->get('database.connections.app');

        foreach (['file:'.$file.'?mode=rwc', 'file://localhost'.$file, 'file:'.str_replace('/', '%2F', $file)] as $uri) {
            $config->set('database.connections.app', array_replace($app, ['database' => $uri]));
            $config->set('database.connections.app_alias', array_replace($app, ['database' => $file]));
            $config->set('orrery.database', 'app_alias');
            PackageSession::releaseAll();

            self::assertSame('orrery.database shares the SQLite file of connection [app]; leave orrery.database empty (a sibling file) or name a file of its own.', $this->app->make(PackageSession::class)->mirrorRefusal(), "A mirror on the file of the URI {$uri} was accepted.");

            $config->set('database.connections.app_alias', array_replace($app, ['database' => $own]));
            PackageSession::releaseAll();
            self::assertNull($this->app->make(PackageSession::class)->mirrorRefusal(), "A mirror on a file of its own was refused beside the URI {$uri}.");
        }
    }

    /**
     * orrery.database that names the queue's own SQLite connection is
     * refused too: no observation writes there, and
     * /api/health and orrery:install name the refusal. The main migration
     * is frozen, so it still runs create() there.
     *
     * Rejects: a refusal that skips the configured name itself when it looks
     * for another connection on the file. Would still pass: a refusal whose
     * text names another used connection of the same file (the test names
     * the only one).
     */
    public function test_the_queues_own_connection_as_mirror_is_refused(): void
    {
        $file = $this->useHostFile();
        $this->app['config']->set('orrery.database', 'app');
        $sessions = $this->app->make(PackageSession::class);
        $message = 'orrery.database shares the SQLite file of connection [app]; leave orrery.database empty (a sibling file) or name a file of its own.';

        self::assertSame($message, $sessions->mirrorRefusal());

        try {
            $sessions->mirror();
            self::fail('The shared mirror is refused.');
        } catch (MirrorRefused $refused) {
            self::assertSame($message, $refused->getMessage());
        }

        Queue::connection('app_queue')->push(new BasicJob);

        self::assertSame([], $this->packageTables($file, ['orrery_publications']), 'No package write reached the shared file.');
        self::assertSame($message, $this->getJson('/orrery/api/health')->assertOk()->json('sqlite_shared_mirror'), '/api/health names the refusal.');
    }

    /**
     * Rejects: reading the top-level keys only, a WAL check that cares about case, and an EXCLUSIVE locking mode that is admitted.
     *
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('admissions')]
    public function test_effective_busy_timeout_reads_pragmas(array $config, bool $allowed): void
    {
        $file = $this->useHostFile($config + ['busy_timeout' => null, 'journal_mode' => null]);

        self::assertSame($allowed, $this->app->make(HostFiles::class)->configurationAllowsReads((string) realpath($file)));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function admissions(): array
    {
        return [
            'pragmas.busy_timeout 0 with the key null' => [['pragmas' => ['busy_timeout' => 0, 'journal_mode' => 'wal']], false],
            'pragmas.busy_timeout 0 with busy_timeout 5000' => [['busy_timeout' => 5000, 'pragmas' => ['busy_timeout' => 0, 'journal_mode' => 'wal']], true],
            'pragmas.journal_mode wal with the key null' => [['busy_timeout' => 5000, 'pragmas' => ['journal_mode' => 'wal']], true],
            'WAL in capitals' => [['busy_timeout' => 5000, 'journal_mode' => 'WAL'], true],
            'rollback journal' => [['busy_timeout' => 5000, 'journal_mode' => 'delete'], false],
            'no busy timeout key: PDO SQLite 60 s' => [['journal_mode' => 'wal'], true],
            'a PDO timeout of 0' => [['journal_mode' => 'wal', 'options' => [PDO::ATTR_TIMEOUT => 0]], false],
            'locking_mode EXCLUSIVE' => [['busy_timeout' => 5000, 'journal_mode' => 'wal', 'locking_mode' => 'EXCLUSIVE'], false],
            'main.locking_mode exclusive in pragmas' => [['busy_timeout' => 5000, 'journal_mode' => 'wal', 'pragmas' => ['main.locking_mode' => 'exclusive']], false],
        ];
    }

    /**
     * Rejects: an admission per connection name: an alias name of the same real file that alone sets EXCLUSIVE, or has no busy timeout, refuses every name.
     */
    public function test_every_name_of_the_real_file_decides(): void
    {
        $file = $this->useHostFile();
        $files = $this->app->make(HostFiles::class);
        self::assertTrue($files->allowsReads('app'));

        $this->app['config']->set('database.connections.app_alias', array_replace($this->app['config']->get('database.connections.app'), ['pragmas' => ['main.locking_mode' => 'EXCLUSIVE']]));
        HostFiles::endUnit();
        self::assertFalse($files->allowsReads('app'), 'An alias that alone sets EXCLUSIVE refuses the file.');

        $this->app['config']->set('database.connections.app_alias', array_replace($this->app['config']->get('database.connections.app'), ['busy_timeout' => 0]));
        self::assertFalse($files->allowsReads('app'), 'An alias without a busy timeout refuses the file.');

        $this->app['config']->set('orrery.sqlite_automatic_access', false);
        $this->app['config']->set('database.connections.app_alias', $this->app['config']->get('database.connections.app'));
        self::assertFalse($files->allowsReads('app'), 'The switch off refuses every file.');
        self::assertSame([realpath($file)], array_values(array_intersect($files->filesWithoutReads(), [realpath($file)])));
    }

    /**
     * Rejects: an admission from the configuration alone: a file configured for WAL that is in rollback mode gets no automatic read (D18).
     */
    public function test_the_journal_mode_is_confirmed_on_the_file(): void
    {
        $file = $this->useHostFile();
        PackageSession::releaseAll();
        DB::purge('app');
        $pdo = new PDO('sqlite:'.$file);
        $pdo->exec('PRAGMA journal_mode = delete');
        $pdo = null;
        $files = $this->app->make(HostFiles::class);

        self::assertTrue($files->configurationAllowsReads((string) realpath($file)));
        self::assertFalse($files->allowsReads('app'), 'The confirmation read answers delete.');
        self::assertArrayHasKey((string) realpath($file), HostFiles::hints(), 'One health hint.');

        try {
            $this->app->make(PackageSession::class)->for('app');
            self::fail('No automatic session opens on the file.');
        } catch (HostFileAccessNotAllowed $refused) {
            self::assertSame('app', $refused->connection);
        }
    }

    /**
     * Rejects: copying the connector's pragmas into a host-file session (D18).
     */
    public function test_host_file_session_ignores_inherited_pragmas(): void
    {
        $file = $this->useHostFile();
        // The application's own settings, after its tables exist.
        $this->app['config']->set('database.connections.app.pragmas', ['user_version' => 7]);
        $this->app['config']->set('database.connections.app.synchronous', 'off');
        DB::purge('app');
        $sessions = $this->app->make(PackageSession::class);

        $session = $sessions->for('app');
        self::assertSame(0, (int) $session->scalar('pragma user_version'));
        $session->reconnect();
        self::assertSame(0, (int) $session->scalar('pragma user_version'), 'Also after a reconnect.');
        self::assertSame(5000, (int) $session->scalar('pragma busy_timeout'), 'The package-owned busy timeout.');
        PackageSession::releaseAll();

        $header = new PDO('sqlite:'.$file);
        self::assertSame(0, (int) $header->query('pragma user_version')->fetchColumn(), 'The package session changed the file header.');
        $header = null;

        // The control: the application's own connection still applies its settings.
        DB::connection('app')->scalar('select 1');
        self::assertSame(7, (int) DB::connection('app')->scalar('pragma user_version'));
    }

    /**
     * Rejects: a policy per connection name, a policy over every configured connection, and a cache check of the data connection only.
     */
    public function test_host_files_are_computed_per_real_file(): void
    {
        $file = $this->useHostFile();
        $other = $this->newFile();
        $unused = $this->newFile();
        $config = $this->app['config'];
        $link = dirname($file).'/linked.sqlite';
        symlink($file, $link);
        $this->locks[] = $link;
        $config->set('database.connections.app_link', array_replace($config->get('database.connections.app'), ['database' => $link]));
        $config->set('database.connections.failed_file', $this->sqlite($other));
        $config->set('database.connections.stock', $this->sqlite($unused));
        $config->set('queue.failed', ['driver' => 'database-uuids', 'database' => 'failed_file', 'table' => 'failed_jobs']);
        $config->set('cache.default', 'array');
        $config->set('cache.stores.split', ['driver' => 'database', 'connection' => 'testbench_mysql_like', 'lock_connection' => 'app_link', 'table' => 'cache']);
        $config->set('cache.stores.failover', ['driver' => 'failover', 'stores' => ['array', 'split']]);
        $files = $this->app->make(HostFiles::class);

        self::assertTrue($files->isHost('app_link'), 'A symlinked name reaches the same real file.');
        self::assertContains((string) realpath($file), $files->files());
        self::assertContains((string) realpath($other), $files->files(), 'The failed-record connection on another file.');
        self::assertCount(count(array_unique($files->files())), $files->files(), 'Each real file once.');
        self::assertFalse($files->isHost('stock'), 'A configured SQLite file that the package does not use.');
        self::assertFalse($files->cacheIsSafe('split'), 'A lock connection on a host file makes the store unsafe.');
        self::assertFalse($files->cacheIsSafe('failover'), 'A failover store with an unsafe store is unsafe.');
        self::assertTrue($files->cacheIsSafe('array'));
        self::assertNull(MaintenanceModule::lockFile(), 'A safe schedule store keeps onOneServer().');

        // The effective schedule store, as Laravel's Kernel::scheduleCache() computes it.
        $config->set('cache.stores.database', ['driver' => 'database', 'connection' => 'app', 'table' => 'cache']);
        $config->set('cache.schedule_store', 'database');
        self::assertSame('database', $files->scheduleStore());
        self::assertSame($this->app->make(PackageSession::class)->siblingPath(), MaintenanceModule::lockFile(), 'The tick locks next to the sibling mirror file.');

        $config->set('cache.schedule_store', null);
        self::assertSame('array', $files->scheduleStore(), 'A present null schedule store means the default store.');
    }

    /**
     * Rejects: role clones: one session per database connection; the automatic read-only and the operator read-write PDO of a host file are the one allowed pair.
     */
    public function test_one_pdo_per_database_connection(): void
    {
        $this->useHostFile();
        $sessions = $this->app->make(PackageSession::class);

        $automatic = $sessions->for('app');
        self::assertSame($automatic, $sessions->forQueue('app_queue'));
        self::assertSame($automatic, $sessions->forAction('app'), 'Outside an operator action the action session is the automatic one.');

        $sessions->operator(function (OperatorContext $context) use ($sessions, $automatic): void {
            $operator = $sessions->for('app', $context);
            self::assertNotSame($automatic, $operator);
            self::assertSame($operator, $sessions->forAction('app'));
            $modes = array_count_values(array_map(static fn (array $entry): string => $entry['mode'], array_filter(PackageSession::sessionsForTesting(), static fn (array $entry): bool => $entry['name'] === 'app')));
            self::assertSame([PackageSession::AUTOMATIC => 1, PackageSession::OPERATOR => 1], $modes);
        });
    }

    /**
     * Rejects: one cached PDO switched between modes, and an automatic caller inheriting a writable lease, with the real admission (no seam).
     */
    public function test_session_access_modes_are_leased_with_the_real_admission(): void
    {
        $file = $this->useHostFile();
        $sessions = $this->app->make(PackageSession::class);
        $print = $sessions->fingerprint('app');
        $held = null;

        $sessions->operator(static function (OperatorContext $context) use ($sessions, &$held): void {
            $held = $sessions->for('app', $context);
            $held->table('cache')->insert(['key' => 'operator', 'value' => 'x', 'expiration' => 1]);
        });

        try {
            $sessions->for('app')->table('cache')->insert(['key' => 'automatic', 'value' => 'x', 'expiration' => 1]);
            self::fail('The automatic session is read-only.');
        } catch (QueryException $readOnly) {
            self::assertStringContainsString('readonly', $readOnly->getMessage());
        }

        try {
            $held?->table('cache')->insert(['key' => 'after', 'value' => 'x', 'expiration' => 1]);
            self::fail('A released operator session never reconnects.');
        } catch (PackageSessionReleased) {
            // The lease ended.
        }

        self::assertSame(['operator'], (new PDO('sqlite:'.$file))->query('select key from cache')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame($print, $sessions->fingerprint('app'));
    }

    /**
     * Rejects: a mirror, sample, package-session, package cache or schedule-mutex write on a host file (D17), and a mutex decision keyed on cache.default.
     *
     * The application's cache store is a `database` store on the queue's
     * file. The hot path, every tick phase, a workload poll and a long-wait
     * notification write only the sibling. The tick's schedule event takes
     * the sibling file mutex and no onOneServer().
     *
     * No interim exception is left: orrery:snapshot went with the
     * counters, and the legacy retry protocol, its phases and its
     * provider subclasses are gone; the operation runner and
     * the fence retention write nothing to a host file (HostFileRetryTest).
     */
    public function test_mirror_writes_never_reach_the_queue_file(): void
    {
        $file = $this->useHostFile();
        $config = $this->app['config'];
        $config->set('cache.stores.database', ['driver' => 'database', 'connection' => 'app', 'table' => 'cache', 'lock_connection' => 'app', 'lock_table' => 'cache_locks']);
        $config->set('cache.default', 'database');
        $config->set('orrery.dashboard.measure_cache_seconds', 30);
        $config->set('orrery.waits', ['*' => 1]);
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');
        $sessions = $this->app->make(PackageSession::class);
        $writes = [];
        PackageStatements::beforeEach($this->app, 'app', static function (string $sql) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $sql) === 1) {
                $writes[] = $sql;
            }
        });

        $this->runJob();
        Queue::connection('app_queue')->push(new BasicJob);
        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        self::assertSame(0, Artisan::call('orrery:tick'));
        $this->getJson('/orrery/api/workload')->assertOk();
        Orrery::routeMailNotificationsTo('ops@example.com');
        $this->app->make(SendNotification::class)->handle(new LongWaitDetected('app_queue', 'default', 120));

        self::assertSame([], $writes, 'No automatic package statement wrote to the queue\'s file.');
        self::assertSame(0, (int) DB::connection('app')->table('cache')->count(), 'No package cache entry in the application\'s cache table.');
        self::assertSame(0, (int) DB::connection('app')->table('cache_locks')->count(), 'No package lock in the application\'s cache.');
        self::assertSame([], $this->packageTables($file, ['orrery_publications']));
        self::assertSame(0, (int) DB::connection('app')->table('orrery_publications')->count());
        self::assertGreaterThan(0, (int) $sessions->mirror()->table('orrery_settings')->where('key', 'like', 'orrery:notification:%')->count(), 'The notification state is in the mirror.');

        // The schedule: the effective store is unsafe, so the tick takes the sibling file mutex.
        $event = collect($this->app->make(Schedule::class)->events())->first(static fn ($event): bool => str_contains((string) $event->command, 'orrery:tick'));
        self::assertNotNull($event);
        self::assertInstanceOf(SiblingFileMutex::class, $event->mutex);
        self::assertFalse($event->onOneServer);
        $this->locks[] = $event->mutex->path;
        self::assertSame($sessions->siblingPath().'.orrery-tick.lock', $event->mutex->path);
        self::assertTrue($event->mutex->create($event));
        self::assertTrue($event->mutex->exists($event), 'The held lock blocks an overlapping run.');
        $event->mutex->forget($event);
        self::assertFalse($event->mutex->exists($event));
        self::assertSame(0, (int) DB::connection('app')->table('cache')->count(), 'The mutex wrote no cache row.');

        // The held locks live in a container singleton (SiblingLocks): when it goes
        // with its application, PHP closes the lock file and the lock ends.
        self::assertTrue($event->mutex->create($event));
        $this->app->forgetInstance(SiblingLocks::class);
        self::assertFalse($event->mutex->exists($event), 'The end of the holder released the held lock.');
    }

    /**
     * Rejects: an install that only an `orrery:install` or the host's migrations table decides, a check-then-act install, a schema that a lower marker from the baseline leaves as it is, a sibling from before the baseline or with a higher marker that is used, and a boot-time path.
     */
    public function test_sibling_lifecycle(): void
    {
        $file = $this->useHostFile();
        $sessions = $this->app->make(PackageSession::class);
        $sibling = (string) $sessions->siblingPath();
        $sessions->mirror()->table('orrery_settings')->insert(['key' => 'kept', 'value' => '1']);

        // migrate (and migrate:fresh) run the main migration again: ensure() keeps every row.
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_09_23_000000_create_orrery_tables.php';
        $migration->up();
        $migration->down();
        self::assertSame('1', $sessions->mirror()->table('orrery_settings')->where('key', 'kept')->value('value'), 'up() and down() keep the sibling\'s rows.');

        // A marker below the baseline (a sibling from before Orrery 0.1): refused, and nothing changes.
        $this->siblingPdo($sibling)->exec('update orrery_schema set version = '.(PackageSchema::BASELINE - 1));
        PackageSession::releaseAll();

        try {
            $sessions->mirror();
            self::fail('A sibling from before the baseline is refused.');
        } catch (SiblingFileUnavailable $old) {
            self::assertStringContainsString(PackageSchema::PRE_BASELINE_SIBLING, $old->getMessage());
        }

        self::assertSame(PackageSchema::BASELINE - 1, (int) $this->siblingPdo($sibling)->query('select version from orrery_schema')->fetchColumn());
        self::assertSame('1', $this->siblingPdo($sibling)->query("select value from orrery_settings where key = 'kept'")->fetchColumn());

        // A marker from the baseline below the code's version is upgraded forward, without losing a row.
        $next = PackageSchema::VERSION + 1;
        $this->siblingPdo($sibling)->exec('update orrery_schema set version = '.PackageSchema::VERSION);
        PackageSession::releaseAll();
        PackageSchema::useUpgradesForTesting([$next => static function (): void {}], $next);

        try {
            $sessions->mirror()->table('orrery_settings')->count();
            self::assertSame($next, (int) $this->siblingPdo($sibling)->query('select version from orrery_schema')->fetchColumn());
            self::assertSame('1', $sessions->mirror()->table('orrery_settings')->where('key', 'kept')->value('value'));

            // Another process upgrades between this process's marker read and its lock: the second read sees it.
            $this->siblingPdo($sibling)->exec('update orrery_schema set version = '.PackageSchema::VERSION);
            PackageSession::releaseAll();
            $raced = false;
            Hooks::$callback = function (string $point) use ($sibling, $next, &$raced): void {
                if ($point === PackageSchema::BEFORE_LOCK && ! $raced) {
                    $raced = true;
                    $this->siblingPdo($sibling)->exec('update orrery_schema set version = '.$next);
                    $this->siblingPdo($sibling)->exec("insert into orrery_settings (key, value) values ('raced', '1')");
                }
            };
            PackageSchema::useUpgradesForTesting([$next => static function (): void {
                throw new RuntimeException('The upgrade ran again after another process finished it.');
            }], $next);

            $sessions->mirror()->table('orrery_settings')->count();
        } finally {
            Hooks::$callback = null;
            PackageSchema::useUpgradesForTesting(null);
        }

        self::assertTrue($raced);
        $this->siblingPdo($sibling)->exec('update orrery_schema set version = '.PackageSchema::VERSION);
        PackageSession::releaseAll();
        self::assertSame('1', $sessions->mirror()->table('orrery_settings')->where('key', 'raced')->value('value'));

        // A higher marker than the code's: a hint, and no write.
        $this->siblingPdo($sibling)->exec('update orrery_schema set version = '.(PackageSchema::VERSION + 1));
        PackageSession::releaseAll();

        try {
            $sessions->mirror();
            self::fail('A newer schema is refused.');
        } catch (SiblingFileUnavailable $newer) {
            self::assertStringContainsString('is newer than this release', $newer->getMessage());
        }

        $this->siblingPdo($sibling)->exec('update orrery_schema set version = '.PackageSchema::VERSION);
        PackageSession::releaseAll();

        // The sibling deleted at runtime: within a second the warm process installs a new one.
        $warm = $sessions->mirror();
        $warm->table('orrery_settings')->count();
        PackageSession::releaseAll();
        $warm = $sessions->mirror();
        $warm->scalar('select 1');
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($sibling.$suffix);
        }

        Clock::advanceMonotonicForTesting(PackageSession::SIBLING_STAT_SECONDS + 0.1);
        $sessions->mirror()->table('orrery_settings')->insert(['key' => 'after', 'value' => '1']);
        self::assertFileExists($sibling);
        self::assertSame('1', $this->siblingPdo($sibling)->query("select value from orrery_settings where key = 'after'")->fetchColumn(), 'The first write after the deletion reached the new file.');

        // The path is derived at connection time: parallel testing gives each process its own sibling.
        foreach (['_test_1', '_test_2'] as $token) {
            touch($file.$token);
            $this->locks[] = $file.$token;
            $this->locks[] = $file.$token.'.orrery.sqlite';
            $this->locks[] = $file.$token.'.orrery.sqlite-wal';
            $this->locks[] = $file.$token.'.orrery.sqlite-shm';
            $this->app['config']->set('database.connections.app.database', $file.$token);
            self::assertSame(dirname($file).'/primary.sqlite'.$token.'.orrery.sqlite', $sessions->mirror()->getDatabaseName());
        }
    }

    /**
     * Rejects: a sibling that the host's migrations decide: `migrate:fresh`
     * (twice) wipes only the application's file and keeps the sibling's
     * rows, and the next reconciliation finds the mirror rows whose jobs
     * are gone.
     */
    public function test_migrate_fresh_keeps_the_sibling_rows(): void
    {
        $this->useHostFile();
        $sessions = $this->app->make(PackageSession::class);
        Queue::connection('app_queue')->push(new BasicJob);
        $mirror = $sessions->mirror();
        self::assertSame(1, (int) $mirror->table('orrery_jobs')->where('status', 'pending')->count());

        foreach ([1, 2] as $run) {
            self::assertSame(0, Artisan::call('migrate:fresh', ['--database' => 'app', '--path' => dirname(__DIR__, 3).'/database/migrations', '--realpath' => true, '--force' => true]), Artisan::output());
            self::assertSame(1, (int) $sessions->mirror()->table('orrery_jobs')->where('status', 'pending')->count(), "migrate:fresh {$run} kept the sibling's rows.");
        }

        // The job is gone from the application's queue table (Laravel's file wipe under WAL
        // keeps what the open connection's WAL holds, so the test removes the row itself).
        DB::connection('app')->table('jobs')->delete();
        $this->app->make(Reconciliation::class)->reconcileActive('app_queue');

        self::assertNotNull($sessions->mirror()->table('orrery_jobs')->value('absent_since'), 'The reconciliation found the gone job.');
    }

    /**
     * Rejects: a sibling file that cannot be created reported as a data error, so every observation retries.
     */
    public function test_a_sibling_that_cannot_be_created_opens_the_breaker(): void
    {
        $file = $this->useHostFile();
        $directory = dirname($file).'/locked';
        mkdir($directory, 0500);

        try {
            $this->app['config']->set('database.connections.app.database', $directory.'/app.sqlite');
            Watcher::instance()->run('test:sibling', fn () => $this->app->make(PackageSession::class)->mirror());

            self::assertTrue(Watcher::instance()->breakerOpen(), 'The breaker is open.');
        } finally {
            chmod($directory, 0700);
            rmdir($directory);
        }
    }

    /**
     * Rejects: a sibling whose permission bits come from the umask of the
     * process that created it. With the application's
     * file at 0660 and a umask of 022, the sibling and its lock file are
     * 0660, so another user of the group (a web server, a worker) opens
     * them.
     */
    public function test_the_sibling_gets_the_permissions_of_the_application_file(): void
    {
        $file = $this->useHostFile();
        chmod($file, 0660);
        $umask = umask(0022);

        try {
            $sibling = (string) $this->app->make(PackageSession::class)->mirror()->getDatabaseName();
        } finally {
            umask($umask);
        }

        clearstatcache();
        self::assertSame('660', decoct(fileperms($sibling) & 0777));
        self::assertSame('660', decoct(fileperms($sibling.PackageSession::SIBLING_CREATE_LOCK) & 0777));
    }

    /**
     * Rejects: the permission bits read from the configured path as given.
     * A relative `database` resolves against the base
     * path, as Laravel's connector resolves it, not against the working
     * directory: a web request runs in public/. With the application's file
     * at 0664 and a umask of 022, the sibling is 0664.
     */
    public function test_the_sibling_gets_the_permissions_of_a_relative_application_file(): void
    {
        $file = $this->useHostFile();
        $directory = dirname($file);
        $base = $this->app->basePath();
        $cwd = (string) getcwd();
        mkdir($directory.'/public', 0700);
        chmod($file, 0664);
        $this->app->setBasePath($directory);
        $this->app['config']->set('database.connections.app.database', basename($file));
        PackageSession::releaseAll();
        DB::purge('app');
        chdir($directory.'/public');
        $umask = umask(0022);

        try {
            $sibling = (string) $this->app->make(PackageSession::class)->mirror()->getDatabaseName();
        } finally {
            umask($umask);
            chdir($cwd);
            $this->app->setBasePath($base);
            PackageSession::releaseAll();
            rmdir($directory.'/public');
        }

        clearstatcache();
        self::assertSame($directory.'/primary.orrery.sqlite', $sibling);
        self::assertSame('664', decoct(fileperms($sibling) & 0777));
    }

    /**
     * Rejects: a sibling created next to the -wal and -shm files of a
     * deleted one. Only the main file is deleted while
     * a process still has the old sibling open with committed pages in its
     * WAL. The new sibling must not take the old journal: it starts empty
     * and passes integrity_check, and the old process's rows stay out.
     */
    public function test_a_new_sibling_never_takes_a_stale_journal(): void
    {
        $this->useHostFile();
        $sessions = $this->app->make(PackageSession::class);
        $sibling = (string) $sessions->mirror()->getDatabaseName();
        $sessions->mirror()->table('orrery_settings')->insert(['key' => 'before', 'value' => '1']);
        PackageSession::releaseAll();

        // Another process: its WAL holds a committed row that no checkpoint moved into the main file.
        $old = $this->siblingPdo($sibling);
        $old->exec('pragma journal_mode = wal');
        $old->exec('pragma wal_autocheckpoint = 0');
        $old->exec("insert into orrery_settings (key, value) values ('old', '1')");
        self::assertFileExists($sibling.'-wal');
        unlink($sibling);

        $mirror = $sessions->mirror();
        $mirror->table('orrery_settings')->insert(['key' => 'after', 'value' => '1']);

        // The old process goes on writing to its own (deleted) file while the new sibling is in use.
        for ($i = 0; $i < 20; $i++) {
            $old->exec("insert into orrery_settings (key, value) values ('old{$i}', '".str_repeat('x', 2000)."')");
            $mirror->table('orrery_settings')->insert(['key' => "after{$i}", 'value' => '1']);
        }

        $keys = $mirror->table('orrery_settings')->where('key', 'like', 'after%')->count();
        PackageSession::releaseAll();
        $old = null;
        self::assertSame(21, $keys, 'The new sibling reads its own rows while the old process writes.');

        $fresh = $this->siblingPdo($sibling);
        self::assertSame('ok', $fresh->query('pragma integrity_check')->fetchColumn());
        self::assertSame(0, (int) $fresh->query("select count(*) from orrery_settings where key like 'old%' or key = 'before'")->fetchColumn(), 'The new sibling holds only its own rows.');
        self::assertSame(21, (int) $fresh->query("select count(*) from orrery_settings where key like 'after%'")->fetchColumn());
        self::assertSame(PackageSchema::VERSION, (int) $fresh->query('select version from orrery_schema')->fetchColumn());
    }

    /**
     * Rejects: a tick that is one unit of work. The
     * automatic reader of a host file that one phase opened is closed before
     * the next phase starts, also after a phase that throws.
     */
    public function test_each_tick_phase_is_a_unit_of_work(): void
    {
        $this->useHostFile();
        $sessions = $this->app->make(PackageSession::class);
        $open = static fn (): int => count(array_filter(PackageSession::sessionsForTesting(), static fn (array $entry): bool => $entry['name'] === 'app' && $entry['mode'] === PackageSession::AUTOMATIC));
        $seen = [];
        $phase = static function (string $name, int $priority, \Closure $work): TickPhase {
            return new class($name, $priority, $work) implements TickPhase
            {
                public function __construct(private string $phaseName, private int $phasePriority, private \Closure $work) {}

                public function name(): string
                {
                    return $this->phaseName;
                }

                public function priority(): int
                {
                    return $this->phasePriority;
                }

                public function run(TickBudget $budget): void
                {
                    ($this->work)();
                }
            };
        };

        $report = $this->app->make(TickRunner::class)->run([
            $phase('reads', 1, static function () use ($sessions, $open, &$seen): void {
                $sessions->for('app')->table('jobs')->count();
                $seen['reads'] = $open();
            }),
            $phase('throws', 2, static function () use ($sessions, $open, &$seen): void {
                $seen['throws'] = $open();
                $sessions->for('app')->table('jobs')->count();

                throw new RuntimeException('test phase failure');
            }),
            $phase('after', 3, static function () use ($open, &$seen): void {
                $seen['after'] = $open();
            }),
        ]);

        self::assertSame(['throws'], $report->failed());
        self::assertSame(['reads' => 1, 'throws' => 0, 'after' => 0], $seen, 'Each phase starts without the reader of the one before.');
        self::assertSame(0, $open());
    }

    /**
     * Rejects: a failure on a file without reads that records a problem or
     * stays unbound: on a rollback-mode file
     * with busy_timeout 0 (Laravel's default SQLite), the package's provider
     * binds the record from its own insert (a mirror write on the sibling),
     * and no error is recorded.
     */
    public function test_a_failure_on_a_file_without_reads_records_no_namespace_problem(): void
    {
        $this->useHostFile(['journal_mode' => 'delete', 'busy_timeout' => 0]);
        $this->failJob();
        $mirror = $this->app->make(PackageSession::class)->mirror();

        self::assertSame([], array_keys(Degradation::record()['errors'] ?? []), 'No error.');
        self::assertSame((int) DB::connection('app')->table('failed_jobs')->value('id'), (int) $mirror->table('orrery_jobs')->value('provider_id'), 'The record is bound.');
    }

    /**
     * Rejects: a failed-row check on a rollback-mode file that only the tick
     * would run (the operator part): the
     * tick does not read the file, so a record that a native forget removed
     * stays unchecked; orrery:reconcile checks it as an operator action and
     * records the first absence.
     */
    public function test_orrery_reconcile_checks_failed_rows_on_a_file_without_reads(): void
    {
        $this->useHostFile(['journal_mode' => 'delete', 'busy_timeout' => 0]);
        $this->failJob();
        $mirror = $this->app->make(PackageSession::class)->mirror();
        self::assertNotNull($mirror->table('orrery_jobs')->value('provider_id'), 'The fixture: a bound failed row.');
        DB::connection('app')->table('failed_jobs')->delete();

        self::assertSame(0, Artisan::call('orrery:tick'));
        self::assertNull($mirror->table('orrery_jobs')->value('absent_since'), 'The tick does not read the file.');

        self::assertSame(0, Artisan::call('orrery:reconcile'), Artisan::output());
        self::assertNotNull($mirror->table('orrery_jobs')->value('absent_since'), 'The operator check records the first absence.');
    }

    /**
     * Rejects: a catch-all that swallows a host-file refusal. The
     * caller's own allowsReads() check passes, and the session's check, a
     * moment later, refuses (the journal mode changed between them). The
     * fence report then calls the connection unknown, never missing (no
     * "run orrery:install --fence" hint), and the failed-row reconciliation
     * records no problem.
     */
    public function test_a_late_host_file_refusal_maps_to_unknown(): void
    {
        $this->useHostFile();
        $this->failJob();
        self::assertNotNull($this->app->make(PackageSession::class)->mirror()->table('orrery_jobs')->value('provider_id'), 'The fixture: a bound failed row.');
        $calls = 0;
        $lateRefusal = static function (string $connection) use (&$calls): ?bool {
            return $connection === 'app' ? ++$calls === 1 : null;
        };

        HostFiles::useForTesting(null, $lateRefusal);
        $report = $this->app->make(FenceTargets::class)->report($this->app->make(Fence::class), $this->app->make(HostFiles::class));
        self::assertContains('app_queue', $report['unknown']);
        self::assertNotContains('app_queue', $report['missing'], 'A refusal is no missing fence table.');

        $calls = 0;
        PackageSession::releaseAll();
        $this->app->make(Reconciliation::class)->reconcileFailed(new TickBudget(Clock::monotonic() + 30));
        self::assertNull($this->app->make(PackageSession::class)->mirror()->table('orrery_jobs')->value('absent_since'), 'Unknown: nothing is recorded as absent.');

        self::assertSame([], array_keys(Degradation::record()['errors'] ?? []), 'No provider_namespace_unknown problem, no error.');
        self::assertSame(0, (int) (Degradation::record()['dropped'] ?? 0));
        self::assertFalse(Watcher::instance()->breakerOpen());
    }

    /**
     * Rejects: a stuck-job notification state or marker on the application's
     * file (D17): with the cache store on that file, the tick's incident
     * phase sends the mail, records the skipped SMS channel's warning marker
     * in the mirror, and writes nothing to the file.
     */
    public function test_stuck_notifications_write_nothing_to_the_queue_file(): void
    {
        $this->useHostFile();
        $config = $this->app['config'];
        $config->set('cache.stores.database', ['driver' => 'database', 'connection' => 'app', 'table' => 'cache', 'lock_connection' => 'app', 'lock_table' => 'cache_locks']);
        $config->set('cache.default', 'database');
        $config->set('mail.default', 'array');
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');
        $sessions = $this->app->make(PackageSession::class);
        $this->runJob();
        $mirror = $sessions->mirror();
        $uuid = (string) $mirror->table('orrery_jobs')->value('uuid');
        // A job that is still in the state of its signal: a round ends the incident of a finished one.
        $mirror->table('orrery_jobs')->where('uuid', $uuid)->update(['status' => 'reserved']);
        $now = Clock::toDecimal(Clock::now());
        $mirror->table('orrery_incidents')->insert([
            'job_uuid' => $uuid, 'cycle' => 1, 'signal' => 'outcome_not_observed', 'first_seen_at' => $now,
            'evidence' => '{"gap":1}', 'attempts_made' => 1, 'delivered_at' => $now, 'notify_next_at' => $now,
        ]);
        $writes = [];
        PackageStatements::beforeEach($this->app, 'app', static function (string $sql) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $sql) === 1) {
                $writes[] = $sql;
            }
        });
        Orrery::routeMailNotificationsTo('ops@example.com');
        // laravel/vonage-notification-channel is not installed: the SMS channel is skipped with a warning marker.
        Orrery::routeSmsNotificationsTo('+15550100');

        $this->app->make(StuckIncidents::class)->run(new TickBudget(Clock::monotonic() + 30));
        $this->app->make(StuckNotificationsPhase::class)->run(new TickBudget(Clock::monotonic() + 30));

        self::assertSame(['vonage' => 'skipped', 'mail' => 'sent'], json_decode((string) $mirror->table('orrery_incidents')->value('notified'), true));
        self::assertSame([], $writes, 'No package statement wrote to the queue\'s file.');
        self::assertSame(0, (int) DB::connection('app')->table('cache')->count(), 'No package cache entry in the application\'s cache table.');
        self::assertSame(1, (int) $mirror->table('orrery_settings')->where('key', 'like', 'orrery:notification:stuck-missing:%')->count(), 'The warning marker is in the mirror.');
    }

    /**
     * A file of the application: `app`, the default connection, the queue
     * connection `app_queue`, the failed jobs, the batches and the cache
     * tables, with orrery.database empty.
     *
     * @param  array<string, mixed>  $changes
     */
    private function useHostFile(array $changes = []): string
    {
        $file = $this->newFile();
        $config = $this->app['config'];
        $keys = ['database.default', 'database.connections.app', 'database.connections.app_alias', 'queue.connections', 'queue.failed', 'queue.batching', 'orrery.database', 'cache.default', 'cache.stores', 'cache.schedule_store', 'orrery.sqlite_automatic_access', 'orrery.dashboard', 'orrery.waits'];

        foreach ($keys as $key) {
            $this->saved[$key] = $config->get($key);
        }

        $config->set('database.connections.app', array_replace($this->sqlite($file), $changes));
        $config->set('queue.connections.app_queue', ['driver' => 'database', 'connection' => 'app', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false]);
        $config->set('queue.failed', ['driver' => 'database-uuids', 'database' => 'app', 'table' => 'failed_jobs']);
        $config->set('queue.batching', ['database' => 'app', 'table' => 'job_batches']);
        $config->set('orrery.database', null);
        PackageSession::releaseAll();

        // The application's tables, through its own connection (the file stays in WAL).
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
        $schema->create('cache', static function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        $schema->create('cache_locks', static function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
        $schema->create('orrery_publications', static function (Blueprint $table): void {
            $table->char('token', 64)->primary();
            $table->string('state', 16);
            $table->unsignedBigInteger('job_id')->nullable();
            $table->decimal('created_at', 16, 6);
        });

        // The default connection is the file, so the package keeps its tables in the sibling.
        $config->set('database.default', 'app');

        return $file;
    }

    private function newFile(): string
    {
        return $this->files[] = SqliteFiles::create();
    }

    /**
     * @return array<string, mixed>
     */
    private function sqlite(string $file): array
    {
        return ['driver' => 'sqlite', 'url' => null, 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000, 'journal_mode' => 'wal'];
    }

    private function siblingPdo(string $sibling): PDO
    {
        return new PDO('sqlite:'.$sibling, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /**
     * The package tables of a file, without the named ones.
     *
     * @param  list<string>  $except
     * @return list<string>
     */
    private function packageTables(string $file, array $except): array
    {
        $tables = (new PDO('sqlite:'.$file))->query("select name from sqlite_master where type = 'table' and name like 'orrery_%' order by name")->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_diff($tables, $except));
    }

    private function failJob(): void
    {
        // The listener of WorkCommand::logFailedJob(), which runNextJob() does not register.
        $this->app->forgetInstance('queue.failer');
        Event::listen(JobFailed::class, function (JobFailed $event): void {
            $this->app['queue.failer']->log($event->connectionName, $event->job->getQueue(), $event->job->getRawBody(), $event->exception);
        });
        Queue::connection('app_queue')->push(new FailingJob);
        $options = new WorkerOptions;
        $options->sleep = 0;
        $options->maxTries = 1;
        $this->app->make('queue.worker')->runNextJob('app_queue', 'default', $options);
    }

    private function runJob(): void
    {
        Queue::connection('app_queue')->push(new BasicJob);
        $options = new WorkerOptions;
        $options->sleep = 0;
        $options->maxTries = 1;
        $this->app->make('queue.worker')->runNextJob('app_queue', 'default', $options);
    }
}
