<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Control\SignalState;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\Fixtures\HostFile\HostFileFixture;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\TestCase;

/**
 * Gate fix 3 (D18): the application's queue is on a SQLite
 * file in rollback mode, so the package reads no backlog there. A queue that
 * Orrery knows of (its mirror rows, an Orrery pause) is still listed by
 * /api/workload, with unknown values, never hidden.
 *
 * The test builds its own application file (HostFileFixture) next to the
 * harness, so the file is in rollback mode on every engine; the mirror is
 * the harness's.
 */
final class HostFileWorkloadTest extends TestCase
{
    private ?string $file = null;

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        Orrery::auth(static fn (): bool => true);

        $this->beforeApplicationDestroyed(function (): void {
            PackageSession::releaseAll();
            DB::purge(HostFileFixture::CONNECTION);
            // The fence migration's down() reaches every configured `database` queue connection: the file's goes first.
            $this->app['config']->set($this->saved);

            if ($this->file !== null) {
                SqliteFiles::remove($this->file);
            }
        });
    }

    /**
     * Rejects: a rollback-mode queue that /api/workload leaves out although
     * Orrery mirrors its pending jobs (the queue `idle`) or holds a pause of
     * it (the queue `held`), a 0 in place of an unknown backlog, and a pause
     * that the unknown row loses. It still passes an implementation that
     * lists every mirrored queue of the connection, also one whose rows
     * retention would delete next.
     */
    public function test_rollback_mode_lists_the_queues_that_orrery_knows(): void
    {
        $this->useRollbackFile();
        Queue::connection(HostFileFixture::QUEUE)->pushOn('idle', new BasicJob);
        Queue::connection(HostFileFixture::QUEUE)->pushOn('idle', new BasicJob);
        $this->app->make(SignalState::class)->pause('queue', HostFileFixture::QUEUE.':held', 'cli');

        self::assertSame(2, DB::connection(HostFileFixture::CONNECTION)->table('jobs')->where('queue', 'idle')->count(), 'the jobs wait in the file');
        self::assertSame(2, $this->mirror()->table('orrery_jobs')->where('connection', HostFileFixture::QUEUE)->where('queue', 'idle')->where('status', 'pending')->count(), 'the pushes are mirrored');

        $rows = [];

        foreach ((array) $this->getJson('/orrery/api/workload')->assertOk()->json() as $row) {
            self::assertIsArray($row);

            if ($row['connection'] === HostFileFixture::QUEUE) {
                $rows[(string) $row['name']] = $row;
            }
        }

        ksort($rows);
        self::assertSame(['default', 'held', 'idle'], array_keys($rows));

        foreach ($rows as $name => $row) {
            self::assertTrue($row['backlog_unknown'], $name);
            self::assertNull($row['length'], $name);
            self::assertNull($row['oldest_age'], $name);
            self::assertNull($row['wait'], $name);
        }

        self::assertSame(['queue'], array_column($rows['held']['pauses'], 'scope'), 'the Orrery pause of the unknown row');
        self::assertSame([], $rows['idle']['pauses']);
    }

    private function useRollbackFile(): void
    {
        $config = $this->app['config'];

        foreach (['database.connections.'.HostFileFixture::CONNECTION, 'queue.connections'] as $key) {
            $this->saved[$key] = $config->get($key);
        }

        $this->file = SqliteFiles::create();
        HostFileFixture::apply($config, $this->file, ['journal_mode' => 'delete', 'busy_timeout' => 0]);
        HostFileFixture::createTables(DB::connection(HostFileFixture::CONNECTION)->getSchemaBuilder(), true);
        PackageSession::releaseAll();
    }
}
