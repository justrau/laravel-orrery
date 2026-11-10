<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Support;

use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\UsesAltServer;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The alt fixture itself (part 0): the alt schema, and the
 * move of testbench_target between the servers. MySQL and Postgres, through
 * bin/test <engine> --alt; without --alt every test skips with the reason.
 */
#[Group('docker')]
#[Group('alt')]
#[FreshSchema]
final class UsesAltServerTest extends TestCase
{
    use UsesAltServer;

    /**
     * Rejects: migrate:fresh --database=testbench_alt, which leaves the alt fence table absent.
     */
    public function test_alt_schema_has_the_queue_and_fence_tables_and_the_primary_is_unchanged(): void
    {
        $primaryJobs = (int) $this->app['db']->connection(TestEnvironment::PRIMARY)->table('jobs')->count();
        $this->useAltServer();

        $alt = $this->app['db']->connection('testbench_alt');
        $primary = $this->app['db']->connection('testbench_primary');

        foreach (['jobs', 'job_batches', 'failed_jobs', Fence::TABLE] as $table) {
            self::assertTrue($alt->getSchemaBuilder()->hasTable($table), "the alt server has {$table}");
            self::assertSame(0, (int) $alt->table($table)->count(), "the alt {$table} is empty");
        }

        foreach (['jobs', 'job_batches', 'failed_jobs'] as $table) {
            self::assertTrue($primary->getSchemaBuilder()->hasTable($table), "the primary server still has {$table}");
        }

        self::assertSame($primaryJobs, (int) $primary->table('jobs')->count());
        self::assertNotSame($this->altServerFacts($primary), $this->altServerFacts($alt));

        // Equal inserts on both servers give equal numeric ids.
        $row = ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => 1, 'created_at' => 1];
        self::assertSame((int) $primary->table('jobs')->insertGetId($row), (int) $alt->table('jobs')->insertGetId($row));
    }

    /**
     * Rejects: a retarget without DB::purge() and a new package session, so
     * the cached target session keeps the primary server. Every
     * target session of a connection is one session, `orrery.session.<name>`,
     * keyed by the name and the endpoint identity (EndpointIdentity).
     */
    public function test_retarget_moves_new_target_sessions_and_restore_returns_them(): void
    {
        $this->useAltServer();
        $sessions = $this->app->make(PackageSession::class);
        $name = PackageSession::NAME_PREFIX.'testbench_target';
        $primaryFacts = $this->altServerFacts($this->app['db']->connection('testbench_primary'));
        $altFacts = $this->altServerFacts($this->app['db']->connection('testbench_alt'));

        // The target session of testbench_target is open before the retarget.
        $before = $sessions->for('testbench_target');
        self::assertSame($name, $before->getName());
        self::assertSame($primaryFacts, $this->altServerFacts($before));

        $this->retargetToAlt();

        $after = $sessions->for('testbench_target');
        self::assertSame($name, $after->getName());
        self::assertSame($altFacts, $this->altServerFacts($after), 'the target session after the retarget');
        self::assertSame($after, $sessions->for('testbench_target'), 'one cached session for the new endpoint');

        $opened = $this->sessionsOpenedSinceRetarget();
        self::assertContains(['session' => $name, 'port' => $this->altPort()], $opened, "{$name} opened on the alt port");
        self::assertSame([], array_values(array_filter($opened, fn (array $entry): bool => str_contains($entry['session'], 'testbench_target') && $entry['port'] !== $this->altPort())), 'no target session opened on another port');

        // The primary connection and the mirror session stay on the primary server.
        self::assertSame($primaryFacts, $this->altServerFacts($this->app['db']->connection('testbench_primary')));
        self::assertSame($primaryFacts, $this->altServerFacts($sessions->mirror()));

        $this->restorePrimary();

        $restored = $sessions->for('testbench_target');
        self::assertSame($name, $restored->getName());
        self::assertSame($primaryFacts, $this->altServerFacts($restored), 'the target session after the restore');
        self::assertNotSame($after, $restored, 'the restore opens a new session: the alt one is not reused');
    }
}
