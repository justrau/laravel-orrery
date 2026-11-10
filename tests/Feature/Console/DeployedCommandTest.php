<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Console;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Justrau\Orrery\Markers\Markers;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageTransaction;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * `orrery:deployed` on several servers: one marker per
 * cleaned label within the window, decided under the settings lock
 * `deploy_marker_lock`.
 */
#[Group('process')]
final class DeployedCommandTest extends TestCase
{
    private const NOW = 1_790_000_000;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: an insert on every call (N servers give N markers), and a lookup that ignores the label cleaning.
     */
    public function test_same_label_within_the_window_records_once(): void
    {
        $this->at(self::NOW);
        self::assertSame(0, Artisan::call('orrery:deployed', ['--label' => 'v1.2']));
        self::assertStringContainsString('Recorded a deploy marker.', Artisan::output());

        // A second server, 9 minutes later; its label cleans to the same text.
        $this->at(self::NOW + 540);
        self::assertSame(0, Artisan::call('orrery:deployed', ['--label' => "v1.2\n"]));
        $output = (string) preg_replace('/\s+/', ' ', Artisan::output());
        self::assertStringContainsString('A deploy marker with this label was recorded at '.Carbon::createFromTimestamp(self::NOW, date_default_timezone_get())->format('H:i:s').'; nothing recorded.', $output);

        // Without a label: NULL equals NULL (a later time, so the unique key cannot hide a missing lookup).
        self::assertSame(0, Artisan::call('orrery:deployed'));
        $this->at(self::NOW + 541);
        self::assertSame(0, Artisan::call('orrery:deployed'));
        self::assertStringContainsString('nothing recorded', Artisan::output());

        self::assertSame([[(float) self::NOW, 'v1.2'], [(float) (self::NOW + 540), null]], $this->markers());
    }

    /**
     * Rejects: a window keyed by anything but the label, a window that never ends, a case-insensitive label match (MySQL collations), and an ignored --window=0.
     */
    public function test_another_label_or_a_later_call_records_again(): void
    {
        // The unique key (kind, at, source) needs distinct times.
        $this->at(self::NOW);
        Artisan::call('orrery:deployed', ['--label' => 'v1.2']);
        $this->at(self::NOW + 1);
        Artisan::call('orrery:deployed', ['--label' => 'v1.3']);
        $this->at(self::NOW + 2);
        self::assertSame(0, Artisan::call('orrery:deployed', ['--label' => 'V1.2']));
        self::assertStringContainsString('Recorded a deploy marker.', Artisan::output());

        // After the window (600 s), the same label records again.
        $this->at(self::NOW + 601);
        self::assertSame(0, Artisan::call('orrery:deployed', ['--label' => 'v1.2']));
        self::assertStringContainsString('Recorded a deploy marker.', Artisan::output());

        // A shorter window, and --window=0, which always records.
        $this->at(self::NOW + 700);
        self::assertSame(0, Artisan::call('orrery:deployed', ['--label' => 'v1.2', '--window' => '60']));
        $this->at(self::NOW + 701);
        self::assertSame(0, Artisan::call('orrery:deployed', ['--label' => 'v1.2', '--window' => '0']));
        self::assertStringContainsString('Recorded a deploy marker.', Artisan::output());

        self::assertSame([
            [(float) self::NOW, 'v1.2'],
            [(float) (self::NOW + 1), 'v1.3'],
            [(float) (self::NOW + 2), 'V1.2'],
            [(float) (self::NOW + 601), 'v1.2'],
            [(float) (self::NOW + 700), 'v1.2'],
            [(float) (self::NOW + 701), 'v1.2'],
        ], $this->markers());

        // An invalid window is refused and records nothing.
        self::assertSame(1, Artisan::call('orrery:deployed', ['--window' => '-1']));
        self::assertSame(1, Artisan::call('orrery:deployed', ['--window' => 'ten']));
        self::assertCount(6, $this->markers());
    }

    /**
     * Rejects: the window check without the lock (both children find no marker and both insert), and a second child that merely started late (the test proves that it waited on the lock).
     */
    #[Group('shared-database')]
    public function test_two_processes_record_one_marker(): void
    {
        $env = $this->testEnvironment();

        if ($env->inMemory()) {
            self::markTestSkipped('Two orrery:deployed processes need a database that they share: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        $barrier = Barrier::forTest();
        $hold = Markers::BEFORE_DEPLOY_INSERT;

        // The first child holds before its insert, inside the transaction that holds the lock.
        $first = WorkerProcess::start(['orrery:deployed', '--label=v9'], $env, $barrier->holdEnvironment($hold));
        $barrier->await("{$hold}.reached", 60, $first->abandonIfExited());

        // The second child must wait on the lock. On SQLite it holds at its first
        // SQLITE_BUSY, so the busy budget (3 × 5 s) cannot run out.
        $sqlite = $env->driver === 'sqlite';
        $second = WorkerProcess::start(['orrery:deployed', '--label=v9'], $env, $sqlite ? $barrier->holdEnvironment(PackageTransaction::BUSY_HOOK) : []);

        if ($sqlite) {
            $barrier->await(PackageTransaction::BUSY_HOOK.'.reached', 30, $second->abandonIfExited());
        } else {
            $second->waitUntil(fn (): bool => $this->lockWaits() > 0, 30, 'the second child waited on the deploy marker lock');
        }

        self::assertTrue($second->isRunning(), 'the second child ended while the first held the lock: '.$second->describe());
        self::assertSame([], $this->markers(), 'a marker exists before the first child inserted it');

        $barrier->arrive("{$hold}.release");
        self::assertSame(0, $first->waitForExit(30), $first->describe());

        if ($sqlite) {
            $barrier->arrive(PackageTransaction::BUSY_HOOK.'.release');
        }

        self::assertSame(0, $second->waitForExit(30), $second->describe());

        self::assertStringContainsString('Recorded a deploy marker.', $first->output());
        self::assertStringContainsString('nothing recorded', (string) preg_replace('/\s+/', ' ', $second->output()));
        self::assertCount(1, $this->markers(), 'two processes recorded two markers');
    }

    /**
     * The sessions of this test's database that wait for a row lock.
     */
    private function lockWaits(): int
    {
        $mirror = $this->mirror();

        if ($mirror->getDriverName() === 'pgsql') {
            return (int) $mirror->selectOne("select count(*) as waits from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock' and pid <> pg_backend_pid()")->waits;
        }

        // MySQL: a session of this database that runs the locking read of the lock row now.
        // information_schema.innodb_trx does not list that waiter in this container (it showed
        // only the holder), and performance_schema is off, so the processlist is the proof.
        return (int) $mirror->selectOne("select count(*) as waits from information_schema.processlist where db = database() and id <> connection_id() and command in ('Query', 'Execute') and info like '%deploy_marker_lock%for update%'")->waits;
    }

    /**
     * @return list<array{0: float, 1: ?string}>
     */
    private function markers(): array
    {
        return $this->mirror()->table('orrery_markers')->where('kind', 'deploy')->orderBy('at')->orderBy('id')->get(['at', 'label'])
            ->map(static fn (object $row): array => [(float) $row->at, $row->label === null ? null : (string) $row->label])->all();
    }

    private function at(float $time): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp($time, 'UTC'));
        self::assertSame(Clock::toDecimal($time), Clock::toDecimal(Clock::now()));
    }
}
