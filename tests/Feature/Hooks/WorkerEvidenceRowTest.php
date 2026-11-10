<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Tests\Feature\Hooks\Jobs\EffectJob;
use Justrau\Orrery\Tests\Support\Fixtures\Hooks\FailPopObservationProvider;
use Justrau\Orrery\Tests\Support\Fixtures\Hooks\SwapOffProvider;
use Justrau\Orrery\Tests\Support\Fixtures\ReportReservationJob;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use PHPUnit\Framework\Attributes\Group;

/**
 * Per-attempt worker evidence on the row. The
 * workers are real queue:work processes on a shared database.
 */
#[Group('process')]
#[Group('shared-database')]
final class WorkerEvidenceRowTest extends HooksTestCase
{
    /** @var list<string> */
    private array $reports = [];

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('A worker process needs a database that two processes share: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        $this->queue();
    }

    protected function tearDown(): void
    {
        foreach ($this->reports as $report) {
            @unlink($report);
        }

        $this->reports = [];

        parent::tearDown();
    }

    /**
     * Rejects: evidence read from the heartbeat table.
     */
    public function test_reservation_writes_the_worker_options_for_its_attempt(): void
    {
        $path = $this->reportPath();
        Queue::push(new ReportReservationJob($path));
        $uuid = self::uuidOf($this->onlyJobsRow()['payload']);

        $worker = WorkerProcess::start(['queue:work', 'database', '--once', '--sleep=0', '--tries=4', '--timeout=20'], $this->testEnvironment());
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        $row = $this->report($path, $worker)['row'];
        self::assertIsArray($row, 'The job saw its mirror row.');
        self::assertSame('reserved', $row['status']);
        self::assertStringContainsString(':'.$worker->pid().':', (string) $row['reserved_by'], 'The verified reservation names its worker.');
        self::assertSame(4, (int) $row['worker_tries']);
        self::assertSame(20, (int) $row['worker_timeout']);
        self::assertSame(1, (int) $row['worker_evidence_attempt']);

        $final = $this->rowOrFail($uuid);
        self::assertSame('failed', $final->status);
        self::assertSame(4, (int) $final->worker_tries, 'The failure in the worker keeps the worker options.');
    }

    /**
     * Rejects: a worker's evidence kept after a failure outside a worker.
     */
    #[FreshSchema]
    public function test_failure_outside_a_worker_clears_the_evidence(): void
    {
        Queue::push(new EffectJob('outside', 'release_then_fail', 2));
        $uuid = self::uuidOf($this->onlyJobsRow()['payload']);
        $this->createEffectsTable();

        $worker = WorkerProcess::start(['queue:work', 'database', '--once', '--sleep=0', '--tries=4', '--timeout=20'], $this->testEnvironment());
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        $row = $this->rowOrFail($uuid);
        self::assertSame('pending', $row->status, 'The worker released attempt 1.');
        self::assertSame(4, (int) $row->worker_tries);
        self::assertSame(1, (int) $row->worker_evidence_attempt);

        // Attempt 2 fails in this process, which is not a worker. Its
        // reservation is not observed, so only the failure changes the evidence.
        Watcher::failOperationsForTesting(['hook:pop']);
        $this->work();
        Watcher::failOperationsForTesting([]);

        $row = $this->rowOrFail($uuid);
        self::assertSame('failed', $row->status);
        self::assertNull($row->worker_tries, 'A failure outside a worker clears the evidence.');
        self::assertNull($row->worker_timeout);
        self::assertNull($row->worker_evidence_attempt);
    }

    /**
     * Rejects: reading a heartbeat for the evidence.
     */
    #[FreshSchema]
    public function test_second_worker_values_win_after_a_dropped_reservation(): void
    {
        Queue::push(new EffectJob('second', 'release_then_fail', 2));
        $uuid = self::uuidOf($this->onlyJobsRow()['payload']);
        $this->createEffectsTable();

        $a = WorkerProcess::start(['queue:work', 'database', '--once', '--sleep=0', '--tries=2', '--timeout=11'], $this->testEnvironment());
        self::assertSame(0, $a->waitForExit(60), $a->describe());

        $row = $this->rowOrFail($uuid);
        self::assertSame('pending', $row->status, 'Worker A reserved and released.');
        self::assertSame(2, (int) $row->worker_tries);

        $b = WorkerProcess::start(
            ['queue:work', 'database', '--once', '--sleep=0', '--tries=5', '--timeout=22'],
            $this->testEnvironment(),
            ['ORRERY_TEST_PROVIDERS' => FailPopObservationProvider::class],
        );
        self::assertSame(0, $b->waitForExit(60), $b->describe());

        $row = $this->rowOrFail($uuid);
        self::assertSame('failed', $row->status, 'Worker B failed the job.');
        self::assertSame(5, (int) $row->worker_tries, "The row carries B's tries.");
        self::assertSame(22, (int) $row->worker_timeout, "The row carries B's timeout.");
        self::assertSame(2, (int) $row->worker_evidence_attempt);
    }

    /**
     * With the swap off, only the outcome is mirrored: the
     * job runs without a row, and the failure creates it with the failing
     * worker's evidence.
     *
     * Rejects: a kept swap-off push or reservation observation (the job sees
     * a row), and a failure without the worker's evidence.
     */
    public function test_swap_off_job_has_no_row_and_a_failure_supplies_the_evidence(): void
    {
        $path = $this->reportPath();
        Queue::push(new ReportReservationJob($path));
        $uuid = self::uuidOf($this->onlyJobsRow()['payload']);
        self::assertNull($this->row($uuid), 'A push of a queue that is not the package\'s is not mirrored.');

        $worker = WorkerProcess::start(
            ['queue:work', 'database', '--once', '--sleep=0', '--tries=3', '--timeout=17'],
            $this->testEnvironment(),
            ['ORRERY_TEST_PROVIDERS' => SwapOffProvider::class],
        );
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        self::assertNull($this->report($path, $worker)['row'], 'The job saw no mirror row: its reservation is not mirrored.');

        $final = $this->rowOrFail($uuid);
        self::assertSame('failed', $final->status);
        self::assertSame(3, (int) $final->worker_tries, "The failure supplies the failing worker's evidence.");
        self::assertSame(17, (int) $final->worker_timeout);
        self::assertSame(1, (int) $final->worker_evidence_attempt);
    }

    private function reportPath(): string
    {
        $path = sys_get_temp_dir().'/orrery-b9-report-'.bin2hex(random_bytes(8)).'.json';
        $this->reports[] = $path;

        return $path;
    }

    /**
     * @return array{row: array<string, mixed>|null, job_id: mixed}
     */
    private function report(string $path, WorkerProcess $worker): array
    {
        self::assertFileExists($path, 'The job wrote its report. '.$worker->describe());
        $report = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);

        /** @var array{row: array<string, mixed>|null, job_id: mixed} $report */
        return $report;
    }

    private function createEffectsTable(): void
    {
        $schema = $this->app['db']->connection('testbench')->getSchemaBuilder();
        $schema->dropIfExists(EffectJob::TABLE);
        $schema->create(EffectJob::TABLE, static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('effect');
        });
        $this->beforeApplicationDestroyed(static function () use ($schema): void {
            $schema->dropIfExists(EffectJob::TABLE);
        });
    }
}
