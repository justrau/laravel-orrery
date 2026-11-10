<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Performance;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Monitoring\MonitoredTagCache;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\CountingPdo;
use Justrau\Orrery\Tests\Support\Fixtures\Performance\TaggedBudgetJob;
use Justrau\Orrery\Tests\Support\MirrorSession;
use Justrau\Orrery\Tests\TestCase;
use PDOException;
use PHPUnit\Framework\AssertionFailedError;

/**
 * The hot-path budget.
 *
 * A round trip is one statement that a package session sends to the mirror
 * database. Package sessions have no event dispatcher, so the test
 * counts them through the session recorder (PackageSession::recordForTesting(),
 * Tests\Support\PackageStatements): every run() statement, every begin
 * (the combined begin of a pooled Postgres session counts once), commit and
 * rollback, and every raw exec(). On in-memory SQLite the mirror is the
 * application's own connection, so only its statements on the package
 * tables count (QueryExecuted), and PackageTransaction reports its own
 * BEGIN IMMEDIATE and COMMIT; the transaction events there belong to the
 * queue. Statements of the package sessions of other database connections
 * on application tables are observer reads: the success path has none.
 *
 * Each budget is the measured warm cost (a first job warms the sessions and
 * the monitoring cache), pinned exactly per path with the kind of every
 * statement:
 *
 * | Path | Push | Reserve | Completion | Total |
 * | --- | --- | --- | --- | --- |
 * | swap on, untagged | 1 | 1 | 2 | 4 |
 * | swap on, tagged | 4 | 1 | 2 | 7 |
 *
 * CEILING holds the numbers before the core phase (8 and 11) as an upper
 * bound next to the exact lists, so a later change cannot raise the budget
 * by editing one list.
 *
 * The completion is one read of the row and one guarded update in
 * autocommit, which also writes runtime_ms (the tick counts
 * the minutes into orrery_samples, so a completion writes no metric). The
 * read gives the rule its row: the cycle (a `completed` history row only
 * when cycle > 1), the accounting guard and the reserved_at
 * fallback of the runtime.
 */
final class HotPathBudgetTest extends TestCase
{
    /** The warm untagged success path with the swap on. */
    private const UNTAGGED = [
        'push' => ['insert orrery_jobs'],
        'reserve' => ['update orrery_jobs'],
        'complete' => ['select orrery_jobs', 'update orrery_jobs'],
    ];

    /** The warm tagged success path with the swap on (the monitoring cache is warm). */
    private const TAGGED = [
        'push' => ['begin', 'insert orrery_jobs', 'insert orrery_job_tags', 'commit'],
        'reserve' => ['update orrery_jobs'],
        'complete' => self::UNTAGGED['complete'],
    ];

    /** The upper bound of each path: the numbers before the core phase. */
    private const CEILING = ['untagged' => 8, 'tagged' => 11];

    /** The package's container lookups of the warm untagged path, per phase and binding (test_warm_path_container_resolutions()). */
    private const LOOKUPS = [
        'push' => ['CommitDeferral' => 1, 'JobStore' => 1, 'OpenBulkCaptures' => 4, 'PackageSession' => 1, 'PendingBulkCaptures' => 1, 'Watcher' => 1],
        'reserve' => ['CommitDeferral' => 3, 'Heartbeats' => 2, 'JobStore' => 1, 'ObserveJobProcessing' => 1, 'PackageSession' => 1, 'PendingBulkCaptures' => 1, 'ProcessState' => 4, 'RecordWorkerHeartbeat' => 3, 'RuntimeClock' => 1, 'Watcher' => 5],
        'complete' => ['Degradation' => 1, 'JobStore' => 1, 'ObserveJobAttempted' => 1, 'ObserveJobProcessed' => 1, 'PackageSession' => 2, 'PendingBulkCaptures' => 1, 'RuntimeClock' => 2, 'Watcher' => 2],
    ];

    private bool $recording = false;

    private string $phase = 'push';

    /** @var array<string, list<string>> the round trips of each phase, as labels */
    private array $trips = [];

    /** @var array<string, int> the observer reads of each phase */
    private array $observer = [];

    /** @var list<string> package-table statements on a connection that is not the mirror session */
    private array $stray = [];

    /** The wire calls of the mirror PDO while recording; null when the run counts none (only the pooled Postgres run does). */
    private ?int $wire = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The measured window runs on a stopped watcher clock, so a slow run
        // never expires the 10-second monitoring cache or the 30-second
        // breaker window. The reset contract lets the clock run again.
        Watcher::freezeMonotonicForTesting();

        $this->listen();
        $this->countWireCalls();

        // The worker fires JobProcessing after pop() returned: every statement
        // before it belongs to the reservation, every one after it to the
        // completion.
        $this->app['events']->listen(JobProcessing::class, function (): void {
            if ($this->recording) {
                $this->phase = 'complete';
            }
        });
    }

    /**
     * The pooled Postgres run counts the wire calls of the mirror PDO too
     * (Tests\Support\CountingPdo): the recorder counts
     * logical round trips, so a statement that sends two calls (for example
     * its SET LOCAL bounds in an exec() of their own) would pass it. Every
     * budget assertion then also asserts that the wire calls equal the
     * recorded round trips.
     */
    private function countWireCalls(): void
    {
        $env = $this->testEnvironment();

        if ($env->driver !== 'pgsql' || ! $env->pooled) {
            return;
        }

        $this->wire = 0;
        PackageSession::usePdoFactoryForTesting(function (string $sessionName, array $config): ?\PDO {
            if ($sessionName !== $this->app->make(PackageSession::class)->mirrorName()) {
                return null;
            }

            return CountingPdo::postgres($config, function (): void {
                if ($this->recording && $this->wire !== null) {
                    $this->wire++;
                }
            });
        });
        PackageSession::releaseAll();
    }

    /**
     * The recorder of every package session, and on in-memory SQLite the
     * QueryExecuted events of the application's connection.
     */
    private function listen(): void
    {
        $mirror = fn (): string => $this->app->make(PackageSession::class)->mirrorName();

        PackageSession::recordForTesting(function (string $kind, string $sql, ?string $session) use ($mirror): void {
            if (! $this->recording || $kind === PackageSession::EXECUTED) {
                return;
            }

            if ($session === $mirror()) {
                $this->trips[$this->phase][] = $kind === PackageSession::STATEMENT ? $this->label($sql) : $kind;
            } elseif (str_contains($sql, 'orrery_')) {
                $this->stray[] = $session.': '.$sql;
            } else {
                $this->observer[$this->phase] = ($this->observer[$this->phase] ?? 0) + 1;
            }
        });

        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $event) use ($mirror): void {
            if ($this->recording && $this->app->make(PackageSession::class)->isInMemoryConnection(null) && $event->connectionName === $mirror() && str_contains($event->sql, 'orrery_')) {
                $this->trips[$this->phase][] = $this->label($event->sql);
            }
        });
    }

    /**
     * Rejects: an extra mirror statement on the success path.
     */
    public function test_untagged_success_path_costs_four_round_trips(): void
    {
        $this->runJob(new BasicJob);

        $this->recordJob(new BasicJob);

        $this->assertBudget(self::UNTAGGED, 4);
        self::assertSame([], $this->observer, 'the swap-on path reads no application table');
        $this->assertCompletedRow();
    }

    /**
     * Rejects: one tag insert per tag.
     */
    public function test_tagged_job_costs_seven(): void
    {
        $this->runJob(new TaggedBudgetJob);

        $uuid = $this->recordJob(new TaggedBudgetJob);

        $this->assertBudget(self::TAGGED, 7);
        $this->assertCompletedRow();
        self::assertSame(
            TaggedBudgetJob::TAGS,
            $this->mirror()->table('orrery_job_tags')->where('job_uuid', $uuid)->orderBy('tag')->pluck('tag')->all(),
        );
    }

    /**
     * Rejects: a cache that never refreshes, or a refresh that reads more than the version and the tag set.
     * Still passes: a cache that refreshes earlier than its 10 seconds (the clock moves by the whole TTL).
     */
    public function test_a_tagged_push_after_the_cache_window_adds_the_two_monitoring_reads(): void
    {
        $this->runJob(new TaggedBudgetJob);
        Watcher::advanceMonotonicForTesting(MonitoredTagCache::TTL_SECONDS);

        $this->recordJob(new TaggedBudgetJob);

        $this->assertBudget(['push' => ['select orrery_settings', 'select orrery_monitored_tags', ...self::TAGGED['push']]] + self::TAGGED, 9);
        $this->assertCompletedRow();
    }

    /**
     * Rejects: a count that misses the separate mirror connection.
     */
    public function test_budget_holds_with_a_prefix_and_both_mirror_modes(): void
    {
        $environment = $this->testEnvironment();

        $this->runJob(new BasicJob);
        $this->recordJob(new BasicJob);

        $this->assertBudget(self::UNTAGGED, 4);
        self::assertSame([], $this->stray, 'every package statement ran on the counted mirror session');

        // The counted session reaches the configured mirror connection:
        // testbench_mirror in separate mode, with the run's table prefix.
        $mirror = $this->app->make(PackageSession::class)->mirror();
        self::assertSame($environment->prefix, $mirror->getTablePrefix());
        self::assertSame($environment->mirrorConnectionName(), $this->app['config']->get('orrery.database') ?: $this->app['config']->get('database.default'));
        self::assertSame($this->mirror()->getDatabaseName(), $mirror->getDatabaseName());

        if ($environment->mirror === 'separate') {
            self::assertNotSame(DB::connection('testbench')->getPdo(), $mirror->getPdo(), 'the separate mirror is another database');
            self::assertSame(DB::connection('testbench_mirror')->getDatabaseName(), $mirror->getDatabaseName());
        }

        $this->assertCompletedRow();
    }

    /**
     * Rejects: a warm cost that includes a connect-time statement, or a cold
     * cost that verifies a session (no isolation verification, no binlog
     * probe).
     */
    public function test_cold_and_warm_costs_are_reported(): void
    {
        $cold = $this->recordJob(new BasicJob, true);
        $coldTrips = $this->total();

        $this->recordJob(new BasicJob);

        $this->assertBudget(self::UNTAGGED, 4);

        // A new mirror session reads nothing before its first statement,
        // except the sibling SQLite mirror file, which reads its schema
        // marker once per process, and a pooled Postgres session,
        // which reads the server's search path and resolves its relations.
        $sessions = $this->app->make(PackageSession::class);
        $pooled = $this->testEnvironment()->pooled;
        $extra = $sessions->mirrorIsSibling() ? 1 : 0;

        if ($pooled) {
            self::assertGreaterThanOrEqual(4, $coldTrips, 'the cold round trips: '.json_encode($this->trips));
        } else {
            self::assertSame(4 + $extra, $coldTrips, 'the cold round trips: '.json_encode($this->trips));
        }

        self::assertNotNull($this->mirror()->table('orrery_jobs')->where('uuid', $cold)->value('uuid'));
        $this->assertCompletedRow();
    }

    /**
     * Rejects: counters that do not measure the package's statements.
     */
    public function test_disabled_watcher_fails_the_budget(): void
    {
        $this->runJob(new BasicJob);
        $this->openBreaker();

        $uuid = $this->recordJob(new BasicJob);

        try {
            $this->assertBudget(self::UNTAGGED, 4);
        } catch (AssertionFailedError) {
            self::assertSame(0, $this->total(), 'the open breaker skipped every package statement');
            self::assertNull($this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->value('uuid'));

            return;
        }

        self::fail('The budget held with the watcher disabled, so the counters do not measure the package statements.');
    }

    /**
     * The package's container lookups on the warm success path,
     * per phase and per binding, as the statement budget pins
     * the round trips. Each make() of a package binding counts, a resolved
     * singleton too: the lookup is the cost.
     *
     * Rejects: a static facade that resolves its instance again for every
     * call on the hot path, for example a recorder that reads the session
     * from the container for each statement.
     * Still passes: a lookup through another container method than make()
     * (for example offsetGet() of a bound instance, which make() serves too).
     */
    public function test_warm_path_container_resolutions(): void
    {
        $this->runJob(new BasicJob);

        $counting = false;
        $phase = 'push';
        $lookups = ['push' => [], 'reserve' => [], 'complete' => []];
        $this->app->beforeResolving(static function (string $abstract) use (&$counting, &$phase, &$lookups): void {
            if ($counting && str_starts_with($abstract, 'Justrau\\Orrery\\')) {
                $short = substr($abstract, (int) strrpos($abstract, '\\') + 1);
                $lookups[$phase][$short] = ($lookups[$phase][$short] ?? 0) + 1;
            }
        });
        $this->app['events']->listen(JobProcessing::class, static function () use (&$counting, &$phase): void {
            if ($counting) {
                $phase = 'complete';
            }
        });

        $counting = true;

        try {
            Queue::push(new BasicJob);
            $phase = 'reserve';
            $this->work();
        } finally {
            $counting = false;
        }

        foreach ($lookups as &$each) {
            ksort($each);
        }

        self::assertSame(self::LOOKUPS, $lookups, json_encode($lookups, JSON_THROW_ON_ERROR));
    }

    /**
     * Pushes and works one job without recording.
     */
    private function runJob(object $job): void
    {
        Queue::push($job);
        $this->work();
    }

    /**
     * Records the push and the work of one job, and returns its uuid.
     */
    private function recordJob(object $job, bool $cold = false): string
    {
        if ($cold) {
            // Every package session is new for this job.
            PackageSession::releaseAll();
        }

        $this->recordPush(static function () use ($job): void {
            Queue::push($job);
        });

        $uuid = $this->lastUuid();
        $this->recordWork();

        return $uuid;
    }

    private function recordPush(callable $push): void
    {
        $this->trips = ['push' => [], 'reserve' => [], 'complete' => []];
        $this->wire = $this->wire === null ? null : 0;
        $this->observer = [];
        $this->stray = [];
        $this->phase = 'push';
        $this->recording = true;

        try {
            $push();
        } finally {
            $this->recording = false;
        }
    }

    private function recordWork(): void
    {
        $this->phase = 'reserve';
        $this->recording = true;

        try {
            $this->work();
        } finally {
            $this->recording = false;
        }
    }

    /**
     * @param  array<string, list<string>>  $expected
     */
    private function assertBudget(array $expected, int $total): void
    {
        self::assertSame($total, $this->total(), 'the round trips in total: '.json_encode($this->trips));
        self::assertSame($expected, $this->trips, 'the round trips of each phase');

        if ($this->wire !== null) {
            self::assertSame($total, $this->wire, 'the wire calls of the pooled mirror PDO equal the recorded round trips');
        }

        // The independent upper bound: the budget only goes down.
        $ceiling = in_array('insert orrery_job_tags', $expected['push'] ?? [], true) ? self::CEILING['tagged'] : self::CEILING['untagged'];
        $warm = array_sum(array_map('count', [$expected['push'] ?? [], $expected['reserve'] ?? [], $expected['complete'] ?? []]));
        $refresh = count(array_filter($expected['push'] ?? [], static fn (string $label): bool => in_array($label, ['select orrery_settings', 'select orrery_monitored_tags'], true)));
        self::assertLessThanOrEqual($ceiling, $warm - $refresh, 'the pinned budget is above the ceiling of the numbers before the core phase');
    }

    private function total(): int
    {
        return array_sum(array_map('count', $this->trips));
    }

    /**
     * The completed row carries its runtime, and the completion wrote no
     * per-minute sample: only the tick's aggregation writes orrery_samples.
     */
    private function assertCompletedRow(): void
    {
        $row = $this->mirror()->table('orrery_jobs')->where('uuid', $this->lastUuid())->first();

        self::assertNotNull($row);
        self::assertSame('completed', $row->status);
        self::assertSame(1, (int) $row->cycle);
        self::assertSame(1, (int) $row->attempts);
        self::assertSame(1, (int) $row->observed_attempts);
        self::assertSame(1, (int) $row->last_outcome_attempt);
        self::assertSame(0, (int) $row->observed_gap);
        self::assertNotNull($row->finished_at);
        self::assertNotNull($row->runtime_ms, 'the completion update writes the runtime');
        self::assertSame(0, $this->mirror()->table('orrery_samples')->count(), 'a completion writes no sample');
        self::assertSame(0, (int) DB::connection('testbench')->table('jobs')->count(), 'the queue is empty');
    }

    private function lastUuid(): string
    {
        $payload = DB::connection('testbench')->table('jobs')->orderByDesc('id')->value('payload');

        if (is_string($payload)) {
            return (string) json_decode($payload, true)['uuid'];
        }

        return (string) $this->mirror()->table('orrery_jobs')->orderByDesc('id')->value('uuid');
    }

    /**
     * A label for one statement: its verb and its package table without the
     * table prefix, `upsert` for an insert with a conflict clause.
     */
    private function label(string $sql): string
    {
        $sql = strtolower(trim($sql));
        preg_match('/^(select|insert|update|delete)\b/', $sql, $verb);
        // A pooled Postgres session names its tables with their schema.
        preg_match('/\b(?:from|into|update)\s+(?:["`]?[a-z0-9_]+["`]?\.)?["`]?([a-z0-9_]+)/', $sql, $table);

        $kind = preg_match('/ on conflict .* do update | on duplicate key update /', $sql) === 1 ? 'upsert' : ($verb[1] ?? 'other');
        $name = $table[1] ?? '';
        $prefix = $this->testEnvironment()->prefix;

        if ($prefix !== '' && str_starts_with($name, $prefix)) {
            $name = substr($name, strlen($prefix));
        }

        return trim($kind.' '.$name);
    }

    /**
     * A dependency error of the package storage opens the mirror breaker.
     */
    private function openBreaker(): void
    {
        $watcher = $this->app->make(Watcher::class);
        $watcher->run('test:lost', static function (): never {
            throw new QueryException(MirrorSession::name(), 'select 1', [], new class extends PDOException
            {
                public function __construct()
                {
                    parent::__construct('SQLSTATE[08006]: connection failure');
                    $this->code = '08006';
                    $this->errorInfo = ['08006', 7, 'connection failure'];
                }
            });
        });

        self::assertTrue($watcher->breakerOpen());
    }
}
