<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Monitoring\MonitoredTagCache;
use Justrau\Orrery\Monitoring\MonitoringVersion;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Retention\Jobs\TaggedJob;
use Justrau\Orrery\Tests\Feature\Retention\RetentionTestCase;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\PackageSessionHelpers;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The monitoring isolation cases (committed reads only,
 * the read order): a producer never reads an uncommitted monitoring
 * change, and a change between the version read and the tag-set read is
 * repaired before trim.
 */
final class MonitoringIsolationTest extends RetentionTestCase
{
    use PackageSessionHelpers;

    private const TAG = 'tenant:7';

    /** The MySQL init command that stands for a server default of READ UNCOMMITTED. */
    private const MYSQL_READ_UNCOMMITTED = 'SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED';

    /**
     * @return array<string, array{0: string}>
     */
    public static function levels(): array
    {
        return [
            'configured isolation_level' => ['configured'],
            'server default' => ['server default'],
        ];
    }

    /**
     * Rejects: a mirror session that keeps the configured level.
     */
    #[DataProvider('levels')]
    #[FreshSchema]
    public function test_uncommitted_monitoring_stop_never_reaches_a_producer(string $source): void
    {
        $this->skipOnInMemory();
        $env = $this->testEnvironment();

        if ($env->driver === 'sqlite' && $source === 'server default') {
            self::markTestSkipped('SQLite has no server default isolation level; the configured case covers SQLite.');
        }

        if ($env->driver === 'pgsql' && $env->pooled && $source === 'server default') {
            // A pooled session sends no session setting: its autocommit reads run at the
            // database's default level, which Postgres reads as READ COMMITTED also when it says
            // READ UNCOMMITTED. Its package transactions begin READ COMMITTED.
            self::markTestSkipped('C4: a pooled Postgres session keeps the database default level in autocommit.');
        }

        $mirrorSource = $this->mirrorSource();
        $database = (string) $this->app['config']->get("database.connections.{$mirrorSource}.database");
        $admin = $this->db()->connection(TestEnvironment::PRIMARY);

        try {
            if ($source === 'configured' && $env->isServer()) {
                $this->app['config']->set("database.connections.{$mirrorSource}.isolation_level", 'READ UNCOMMITTED');
            } elseif ($source === 'server default' && $env->driver === 'mysql') {
                $attribute = class_exists(\Pdo\Mysql::class) ? \Pdo\Mysql::ATTR_INIT_COMMAND : PDO::MYSQL_ATTR_INIT_COMMAND;
                $this->app['config']->set("database.connections.{$mirrorSource}.options", [$attribute => self::MYSQL_READ_UNCOMMITTED] + (array) $this->app['config']->get("database.connections.{$mirrorSource}.options"));
            } elseif ($source === 'server default') {
                $admin->statement(sprintf('alter database "%s" set default_transaction_isolation = \'read uncommitted\'', $database));
            }

            $this->db()->purge($mirrorSource);
            PackageSession::releaseAll();

            if ($env->isServer()) {
                self::assertSame($env->driver === 'mysql' ? 'READ-UNCOMMITTED' : 'read uncommitted', $this->reportedLevel($this->db()->connection($mirrorSource)), 'The mirror connection reads uncommitted rows without the package.');
            }

            $this->scenario();

            // A transparent reconnect of the mirror session runs at the selected level.
            $mirror = $this->sessions()->mirror();
            $mirror->disconnect();
            self::assertSame(1, $mirror->table('orrery_jobs')->count(), 'The reconnected mirror session reads.');

            if ($env->isServer()) {
                self::assertSame($this->expectedLevel($mirrorSource), $this->reportedLevel($mirror), 'The new PDO runs at the selected level.');
            }
        } finally {
            if ($source === 'server default' && $env->driver === 'pgsql') {
                $this->db()->connection(TestEnvironment::PRIMARY)->statement(sprintf('alter database "%s" reset default_transaction_isolation', $database));
            }
        }
    }

    /**
     * Rejects: the tag set read before V.
     */
    public function test_change_between_the_version_and_tag_reads_is_repaired_before_trim(): void
    {
        $this->setMonitoring([], 3);
        $hooked = 0;

        Hooks::$callback = function (string $point) use (&$hooked): void {
            if ($point === MonitoredTagCache::BETWEEN_READS && $hooked++ === 0) {
                // Another change commits between the two cache reads.
                $this->app->make(MonitoringVersion::class)->monitor(self::TAG);
            }
        };

        try {
            Queue::push(new TaggedJob([self::TAG]));
        } finally {
            Hooks::$callback = null;
        }

        self::assertSame(1, $hooked);
        $uuid = self::uuidOf((string) DB::table('jobs')->value('payload'));
        self::assertSame([1, 3], [(int) $this->rowOrFail($uuid)->monitored, (int) $this->rowOrFail($uuid)->monitor_version], 'The row pairs the newer tag set with the older V.');
        self::assertSame(4, $this->version());

        $this->work();

        // Trim at the ordinary cutoff (trim.completed = 60): repair or inline verification first.
        Carbon::setTestNow(Carbon::now()->addMinutes(61));
        $this->tick();

        self::assertTrue($this->exists($uuid), 'The monitored job is kept until its monitored retention.');
        self::assertSame([1, 4], [(int) $this->rowOrFail($uuid)->monitored, (int) $this->rowOrFail($uuid)->monitor_version], 'The repair recomputed the row.');

        Carbon::setTestNow(Carbon::now()->addMinutes(10080));
        $this->tick();
        self::assertFalse($this->exists($uuid));
    }

    /**
     * Start at committed V with the tag monitored. Another session stops
     * monitoring (V + 1) and keeps its transaction open while a producer
     * refreshes its cache and pushes a job with the tag. The stop rolls
     * back, the job completes, and an unrelated change commits V + 1. Trim
     * at the ordinary cutoff keeps the job.
     */
    private function scenario(): void
    {
        $this->setMonitoring([self::TAG], 5);
        $env = $this->testEnvironment();

        $this->defineConnection('b18_stopper', $this->mirrorSource());
        $stopper = $this->db()->connection('b18_stopper');
        $this->beforeApplicationDestroyed(fn () => $this->db()->purge('b18_stopper'));

        $stopper->beginTransaction();

        try {
            $stopper->table('orrery_monitored_tags')->where('tag', self::TAG)->delete();
            $stopper->table('orrery_settings')->where('key', MonitoredTagCache::SETTING)->update(['value' => '6']);

            MonitoredTagCache::flush();

            if ($env->isServer()) {
                Queue::push(new TaggedJob([self::TAG]));
            } else {
                // SQLite: the open stop holds the write lock, so the producer
                // takes its snapshot now and pushes after the rollback.
                $this->app->make(MonitoredTagCache::class)->snapshot();
            }
        } finally {
            $stopper->rollBack();
        }

        if (! $env->isServer()) {
            Queue::push(new TaggedJob([self::TAG]));
        }

        $uuid = self::uuidOf((string) DB::table('jobs')->value('payload'));
        self::assertSame([1, 5], [(int) $this->rowOrFail($uuid)->monitored, (int) $this->rowOrFail($uuid)->monitor_version], 'The producer read only committed data: V and the tag monitored.');

        $this->work();
        $this->app->make(MonitoringVersion::class)->monitor('unrelated');
        self::assertSame(6, $this->version(), 'The committed version is V + 1.');

        Carbon::setTestNow(Carbon::now()->addMinutes(61));
        $this->tick();

        self::assertTrue($this->exists($uuid), 'The job survives the ordinary cutoff: it is monitored.');
        self::assertSame([1, 6], [(int) $this->rowOrFail($uuid)->monitored, (int) $this->rowOrFail($uuid)->monitor_version]);
    }
}
