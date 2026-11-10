<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Performance;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Failed\CapturingUuidFailedJobProvider;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Failed\CapturingFailer;
use Justrau\Orrery\Tests\Feature\Jobs\FailingJob;
use Justrau\Orrery\Tests\TestCase;

/**
 * The statements of one failure with the package's capturing failed-job
 * provider live: the mirror round trips
 * of the failing attempt, and the statements on the failed-job table, from
 * the reservation to the logged and bound record. Counted as
 * HotPathBudgetTest counts (the session recorder; on in-memory SQLite the
 * QueryExecuted events of the application's connection), warm (a first
 * failure warms the sessions), pinned exactly per engine: the id of the new
 * record comes back differently per engine (Postgres `insert … returning
 * "id"`, MySQL and SQLite the PDO's last insert id), and SQLite's package
 * transaction reports its BEGIN IMMEDIATE and COMMIT itself.
 */
final class FailurePathBudgetTest extends TestCase
{
    use CapturingFailer;

    /**
     * The failing attempt on the mirror (17 round trips): the failure (F1:
     * Laravel's JobFailed comes first; the row read, one package transaction
     * with its guarded update, the history insert and its bound), the
     * binding of the new record (provider_id and provider_target, one
     * guarded update), and the exception (E1, JobExceptionOccurred: the row
     * read, one package transaction).
     */
    private const FAILING_ATTEMPT = [
        'select orrery_jobs', 'begin', 'update orrery_jobs', 'select orrery_jobs', 'insert orrery_job_history', 'select orrery_job_history', 'update orrery_jobs', 'commit',
        'update orrery_jobs',
        'select orrery_jobs', 'begin', 'update orrery_jobs', 'select orrery_jobs', 'insert orrery_job_history', 'select orrery_job_history', 'select orrery_job_history', 'commit',
    ];

    /** The failing attempt by engine: Postgres's first history insert returns its id. */
    private const MIRROR = [
        'sqlite' => self::FAILING_ATTEMPT,
        'mysql' => self::FAILING_ATTEMPT,
        'pgsql' => [
            'select orrery_jobs', 'begin', 'update orrery_jobs', 'select orrery_jobs', 'insert orrery_job_history returning', 'select orrery_job_history', 'update orrery_jobs', 'commit',
            'update orrery_jobs',
            'select orrery_jobs', 'begin', 'update orrery_jobs', 'select orrery_jobs', 'insert orrery_job_history', 'select orrery_job_history', 'select orrery_job_history', 'commit',
        ],
    ];

    /**
     * The statements on the failed-job table, by the receipt of the new id:
     * the provider's one insert, with `returning "id"` on SQLite and
     * Postgres, and the PDO's last insert id (no extra statement) on MySQL.
     * On Postgres one capability read comes first (may the role read the
     * `id` it inserts: postgresCanReturn()), once per failure. The binding
     * reads nothing back.
     */
    private const FAILED_TABLE = [
        'sqlite' => ['laravel: insert failed_jobs returning'],
        'mysql' => ['laravel: insert failed_jobs'],
        'pgsql' => ['laravel: select capability', 'laravel: insert failed_jobs returning'],
    ];

    private bool $recording = false;

    private string $phase = 'reserve';

    /** @var array<string, list<string>> the mirror round trips of each phase, as labels */
    private array $trips = [];

    /** @var list<string> the statements on the failed-job table */
    private array $failedTable = [];

    protected function setUp(): void
    {
        parent::setUp();

        Watcher::freezeMonotonicForTesting();
        $this->useCapturingFailer();
        self::assertSame(CapturingUuidFailedJobProvider::class, $this->app['queue.failer']::class);

        // WorkCommand::logFailedJob(): TestCase::work() runs the worker without the command.
        $this->app['events']->listen(JobFailed::class, function (JobFailed $event): void {
            $this->app['queue.failer']->log($event->connectionName, $event->job->getQueue(), $event->job->getRawBody(), $event->exception);
        });
        $this->app['events']->listen(JobProcessing::class, function (): void {
            if ($this->recording) {
                $this->phase = 'fail';
            }
        });

        $mirror = fn (): string => $this->app->make(PackageSession::class)->mirrorName();
        $memory = fn (): bool => $this->app->make(PackageSession::class)->isInMemoryConnection(null);

        PackageSession::recordForTesting(function (string $kind, string $sql, ?string $session) use ($mirror): void {
            if (! $this->recording || $kind === PackageSession::EXECUTED) {
                return;
            }

            if (self::onFailedTable($sql)) {
                $this->failedTable[] = 'package: '.$this->label($sql);
            } elseif ($session === $mirror()) {
                $this->trips[$this->phase][] = $kind === PackageSession::STATEMENT ? $this->label($sql) : $kind;
            }
        });
        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $event) use ($mirror, $memory): void {
            if (! $this->recording) {
                return;
            }

            if (self::onFailedTable($event->sql)) {
                $this->failedTable[] = 'laravel: '.$this->label($event->sql);
            } elseif ($memory() && $event->connectionName === $mirror() && str_contains($event->sql, 'orrery_')) {
                $this->trips[$this->phase][] = $this->label($event->sql);
            }
        });
    }

    /**
     * Rejects: an extra statement on the failure path once the capturing
     * provider is live (a read-back of the new record, a second mirror
     * write for the binding outside F1's transaction, or a statement on the
     * failed-job table from package code).
     * Still passes: the same count with other statements (the labels pin
     * their verb and table, not their columns).
     */
    public function test_one_failure_costs_its_pinned_statements(): void
    {
        $this->failOnce();

        $this->trips = ['reserve' => [], 'fail' => []];
        $this->failedTable = [];
        $this->phase = 'reserve';
        Queue::push(new FailingJob);
        $this->recording = true;

        try {
            $this->work();
        } finally {
            $this->recording = false;
        }

        $driver = $this->testEnvironment()->driver;
        self::assertSame(['update orrery_jobs'], $this->trips['reserve'], 'the reservation');
        self::assertCount(17, $this->trips['fail'], 'the failing attempt in total: '.json_encode($this->trips['fail']));
        self::assertSame(self::MIRROR[$driver], $this->trips['fail'], 'the failing attempt on the mirror');
        self::assertSame(self::FAILED_TABLE[$driver], $this->failedTable, 'the statements on the failed-job table');

        $row = $this->mirror()->table('orrery_jobs')->orderByDesc('id')->first();
        self::assertNotNull($row);
        self::assertSame('failed', $row->status);
        self::assertNotNull($row->provider_id, 'The failure is bound to its record.');
    }

    /**
     * A first failure without recording: it warms the sessions.
     */
    private function failOnce(): void
    {
        Queue::push(new FailingJob);
        $this->work();
    }

    /**
     * A statement on the failed-job table: it names the table, or it is the
     * provider's Postgres capability read (to_regclass() of the bound table
     * name, CapturingUuidFailedJobProvider::postgresCanReturn()).
     */
    private static function onFailedTable(string $sql): bool
    {
        return str_contains($sql, 'failed_jobs') || str_contains($sql, 'to_regclass(');
    }

    private function label(string $sql): string
    {
        if (str_contains($sql, 'to_regclass(')) {
            return 'select capability';
        }

        $sql = strtolower(trim($sql));
        preg_match('/^(select|insert|update|delete)\b/', $sql, $verb);
        preg_match('/\b(?:from|into|update)\s+(?:["`]?[a-z0-9_]+["`]?\.)?["`]?([a-z0-9_]+)/', $sql, $table);

        $kind = preg_match('/ on conflict .* do update | on duplicate key update /', $sql) === 1 ? 'upsert' : ($verb[1] ?? 'other');
        $name = $table[1] ?? '';
        $prefix = $this->testEnvironment()->prefix;

        if ($prefix !== '' && str_starts_with($name, $prefix)) {
            $name = substr($name, strlen($prefix));
        }

        return trim($kind.' '.$name).(str_contains($sql, ' returning ') ? ' returning' : '');
    }
}
