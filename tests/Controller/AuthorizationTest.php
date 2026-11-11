<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Closure;
use Illuminate\Bus\BatchRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Justrau\Orrery\Control\SignalState;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Failures\GroupKey;
use Justrau\Orrery\Http\Bulk\BulkRun;
use Justrau\Orrery\Http\Controllers\HomeController;
use Justrau\Orrery\Jobs\MonitorTag;
use Justrau\Orrery\Listeners\RecordWorkerHeartbeat;
use Justrau\Orrery\Monitoring\MonitoringVersion;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Controller\Fakes\AuthorizationJob;
use Justrau\Orrery\Tests\Controller\Fakes\GateProvider;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * Authorization of every package route.
 *
 * - Discovery: the router's routes under the package path, with a package
 *   route name, or with a package controller, whatever their middleware,
 *   must equal the request fixtures below, key by key ("METHOD uri").
 * - Fixtures: the failed job and the failed batch member come from the real
 *   queue:work command, so its JobFailed listener logs them to failed_jobs.
 *   The completed job is a direct write, and the counts frontier M is set to
 *   its minute, so the sample readers scan that minute.
 * - Cases: each route answers seven actors in turn. Between two cases the test
 *   resets the static gate callback, the user and the environment, and proves
 *   the reset. The case after the allowed one uses the same user without a
 *   callback, so a callback that leaks from one case into the next changes
 *   that case's status.
 * - Reads: an actor with the view ability gets 200, and the body shows the
 *   fixture resource where the route has one. A denied actor gets 401
 *   `unauthenticated` (a guest) or 403 `forbidden` (a user),
 *   and a snapshot of every table on both connections stays equal.
 * - Writes: each case starts from the same arranged state. An actor with
 *   the view and the operate ability changes the state and gets the route's
 *   effect, and a retry or forget response reports it as accepted. The queue's
 *   database has its fence table, as orrery:install --fence gives it. A
 *   denied actor gets 401 or 403 as for a read, a viewer without the operate
 *   ability 403 `forbidden_action`, and the snapshot stays equal.
 * - Snapshot: every snapshot reads the current table and column list and
 *   every row of every table on both connections, with exact values.
 * - Negative controls: a denied read that inserts, updates, deletes,
 *   changes binary bytes or changes a text after a NUL byte in a row on
 *   each connection before its 403 must fail the state check. So must denied reads that create a table and drop
 *   it again one request later. Representation controls require distinct
 *   snapshots for NULL and '' and for two adjacent decimals.
 *
 * This class does not extend ControllerTest, because that base class allows
 * every request.
 */
final class AuthorizationTest extends TestCase
{
    private const PATH = 'orrery';

    private const WORK_QUEUE = 'authz-work';

    private const PENDING_QUEUE = 'authz-pending';

    private const PAUSED_QUEUE = 'authz-paused';

    /** The queue of the group forget target: each allowed forget removes it, and the next case fails a new one. */
    private const FORGET_QUEUE = 'authz-forget';

    private const MONITORED_TAG = 'authz:monitored';

    private const ADDED_TAG = 'authz:added';

    private const REMOVED_TAG = 'authz:removed';

    private const CSRF_TOKEN = 'authorization-test-csrf-token-0123456789';

    /** The real gate (GateProvider), with the user that it allows. */
    private const ALLOWED = 'allowed user, gate';

    /** No callback outside local, with the same user as ALLOWED. */
    private const DEFAULT_USER = 'allowed user, no callback';

    private const DEFAULT_GUEST = 'guest, no callback';

    private const DENIED_USER = 'denied user, gate';

    private const DENIED_GUEST = 'guest, gate';

    /** The real gate: the user may view, but not operate. */
    private const VIEWER = 'viewer user, gate';

    /** No callback in the local environment. */
    private const LOCAL_GUEST = 'guest, no callback, local';

    /** The order matters: DEFAULT_USER directly after ALLOWED detects a leaked callback. */
    private const CASES = [
        self::ALLOWED,
        self::DEFAULT_USER,
        self::DEFAULT_GUEST,
        self::DENIED_USER,
        self::DENIED_GUEST,
        self::VIEWER,
        self::LOCAL_GUEST,
    ];

    /** The ref of the fixture bulk run. */
    private const BULK_REF = 'a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0';

    /** The token of the bulk run case, frozen by its arrange step. */
    private string $bulkToken = '';

    /** @var array{failed: string, member: string, batch: string, pending: string, completed: string, forget: string} */
    private array $ids = ['failed' => '', 'member' => '', 'batch' => '', 'pending' => '', 'completed' => '', 'forget' => ''];

    private string $environmentName = '';

    public function test_the_discovered_routes_equal_the_request_fixtures(): void
    {
        $this->assertSame(self::PATH, config('orrery.path'));

        $discovered = $this->discoveredRoutes();
        $fixtures = array_keys($this->requestFixtures());
        sort($fixtures);

        $this->assertSame(
            ['routes without a fixture' => [], 'fixtures without a route' => []],
            [
                'routes without a fixture' => array_values(array_diff($discovered, $fixtures)),
                'fixtures without a route' => array_values(array_diff($fixtures, $discovered)),
            ],
            'Every package route and method needs exactly one request fixture in AuthorizationTest::fixtureMap().',
        );

        // Also equal as lists, so a route registered twice fails too.
        $this->assertSame($fixtures, $discovered);
    }

    public function test_discovery_finds_package_routes_without_the_middleware(): void
    {
        $router = $this->app['router'];

        $router->get(self::PATH.'/api/discovery-probe', static fn (): string => 'probe');
        $router->get('discovery-probe/named', static fn (): string => 'probe')->name('orrery.discovery-probe');
        $router->get('discovery-probe/controller', [HomeController::class, 'index']);
        $router->get('discovery-probe/other', static fn (): string => 'probe');

        $discovered = $this->discoveredRoutes();

        $this->assertContains('GET '.self::PATH.'/api/discovery-probe', $discovered);
        $this->assertContains('HEAD '.self::PATH.'/api/discovery-probe', $discovered);
        $this->assertContains('GET discovery-probe/named', $discovered);
        $this->assertContains('GET discovery-probe/controller', $discovered);
        $this->assertNotContains('GET discovery-probe/other', $discovered);
    }

    public function test_every_read_route_answers_each_actor_as_the_gate_decides(): void
    {
        $this->createFixtures();

        $this->assertCases(array_filter($this->requestFixtures(), static fn (array $fixture): bool => $fixture['kind'] === 'read'));
    }

    /**
     * Rejects: an allowed retry or forget that answers 200 but does not do
     * its work, also on a SQLite file and in separate mode, where the queue's
     * database is not the mirror and needs its own fence table.
     */
    #[FreshSchema]
    public function test_every_write_route_changes_state_only_for_an_allowed_actor(): void
    {
        $this->installQueueFence();
        $this->createFixtures();

        // The writes, and the operator-only reads (a POST that changes nothing): both need the operate ability.
        $this->assertCases(array_filter($this->requestFixtures(), static fn (array $fixture): bool => $fixture['kind'] !== 'read'));
    }

    /**
     * The negative control of the state check for denied reads.
     *
     * First, the unchanged package passes the cases of one read route. Then a
     * RouteMatched listener changes a sentinel row before the middleware runs.
     * The denied requests still get 401 or 403, but the state check of assertCases()
     * must fail and name the changed tables. The listener changes one row on
     * the primary connection and one row on the mirror connection, which is a
     * second database in separate mode.
     *
     * The binary data set uses two tables with a binary column, which the
     * test creates and drops. Its updates switch the value between the
     * invalid UTF-8 bytes 0x80 and 0x81.
     *
     * The nul text data set switches the text columns cache.value and
     * orrery_settings.value between the texts 'a\0b' and 'a\0c'. Before
     * the requests it writes both values and reads back their storage type
     * (SQLite typeof(), MySQL CHARSET()) and their hex bytes. Postgres text
     * cannot hold a NUL byte, so on Postgres the data set requires that
     * Postgres refuses the value.
     */
    #[DataProvider('sentinelChanges')]
    #[FreshSchema]
    public function test_a_denied_read_that_changes_a_row_fails_the_state_check(string $change): void
    {
        $this->rememberEnvironment();

        $key = 'GET orrery/api/workload';
        $fixtures = [$key => $this->requestFixtures()[$key]];

        $primary = $this->app['db']->connection(TestEnvironment::PRIMARY);

        /** @var list<array{0: Connection, 1: string}> $targets */
        $targets = $change === 'binary'
            ? [[$primary, 'authz_bytes_primary'], [$this->mirror(), 'authz_bytes_mirror']]
            : [[$primary, 'cache'], [$this->mirror(), 'orrery_settings']];

        if ($change === 'nul text' && $primary->getDriverName() === 'pgsql') {
            $this->assertPostgresRefusesNulText($targets);

            return;
        }

        try {
            $changedTables = [];

            foreach ($targets as [$connection, $table]) {
                $this->seedSentinels($connection, $table, $change);
                $changedTables[] = $connection->getName().'.'.$connection->getTablePrefix().$table;
            }

            sort($changedTables, SORT_STRING);

            if ($change === 'nul text') {
                $this->assertNulTextIsStored($targets);
            }

            // The unchanged package: no denied case changes the state.
            $this->assertCases($fixtures);

            $calls = 0;

            $this->app['events']->listen(RouteMatched::class, function () use ($targets, $change, &$calls): void {
                $calls++;

                foreach ($targets as [$connection, $table]) {
                    $this->changeSentinel($connection, $table, $change, $calls);
                }
            });

            $expected = [];

            foreach (self::CASES as $case) {
                $expected[$key][$case] = $this->allows($case)
                    ? $this->expectedOutcome('GET', $fixtures[$key], $case)
                    : $this->denial($case, $fixtures[$key], 'GET').', state changed in '.implode(' ', $changedTables);
            }

            $this->assertStateCheckFails($fixtures, $expected);
        } finally {
            if ($change === 'binary') {
                foreach ($targets as [$connection, $table]) {
                    $connection->getSchemaBuilder()->dropIfExists($table);
                }
            }
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sentinelChanges(): array
    {
        return [
            'insert' => ['insert'],
            'update' => ['update'],
            'delete' => ['delete'],
            'binary' => ['binary'],
            'nul text' => ['nul text'],
        ];
    }

    /**
     * The encoding of a SQLite row value, on constant values in a separate
     * in-memory SQLite database: text, blob and NULL stay apart, text keeps
     * the bytes after a NUL, and integers and reals keep their exact value.
     *
     * The spelling of a real depends on the SQLite version: 3.53.4 prints
     * the next double after 0.1 as 0.10000000000000002, and 3.51.0 prints
     * 1.000000000000000195e-01. So the test does not pin the spelling of a
     * real. It requires an untagged number that reads back to the same
     * double (realEncodingProblem()), and distinct encodings.
     */
    public function test_the_sqlite_value_encoding_keeps_types_and_bytes_apart(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $encode = fn (string $sql): string => (string) $pdo->query('SELECT '.$this->sqliteRow(['c']).' FROM (SELECT '.$sql.' AS c)')->fetchColumn();

        $exact = [
            "text 'a'" => ["CAST(X'61' AS TEXT)", 't:61'],
            "blob X'61'" => ["X'61'", 'b:61'],
            'NULL' => ['NULL', 'NULL'],
            "text ''" => ["CAST(X'' AS TEXT)", 't:'],
            "blob X''" => ["X''", 'b:'],
            "text 'a\\0b'" => ["CAST(X'610062' AS TEXT)", 't:610062'],
            "text 'a\\0c'" => ["CAST(X'610063' AS TEXT)", 't:610063'],
            'integer 1' => ['1', '1'],
            "text '1'" => ["'1'", 't:31'],
        ];

        $this->assertSame(
            array_map(static fn (array $value): string => $value[1], $exact),
            array_map(static fn (array $value): string => $encode($value[0]), $exact),
        );

        $next = $this->nextDoubleAfter(0.1);

        $reals = [
            'real 1.0' => ['1.0', 1.0],
            'real 0.1' => [sprintf("CAST('%.17g' AS REAL)", 0.1), 0.1],
            'next real after 0.1' => [sprintf("CAST('%.17g' AS REAL)", $next), $next],
        ];

        $encodedReals = [];

        foreach ($reals as $label => [$sql, $double]) {
            $encodedReals[$label] = $encode($sql);
            $this->assertNull($this->realEncodingProblem($encodedReals[$label], $double), "The encoding of {$label}.");
        }

        $this->assertNotSame($encodedReals['real 0.1'], $encodedReals['next real after 0.1'], 'Two adjacent doubles have one encoding.');

        $ones = [$encode('1'), $encodedReals['real 1.0'], $encode("'1'")];
        $this->assertSame($ones, array_values(array_unique($ones)), "Integer 1, real 1.0 and text '1' do not have three encodings.");
    }

    /**
     * The real check accepts both spellings that SQLite versions print for
     * the next double after 0.1, and it rejects a wrong value and a tagged
     * text.
     */
    public function test_the_real_encoding_check_accepts_every_exact_spelling(): void
    {
        $next = $this->nextDoubleAfter(0.1);

        $this->assertNull($this->realEncodingProblem('0.10000000000000002', $next), 'SQLite 3.53.4 spelling');
        $this->assertNull($this->realEncodingProblem('1.000000000000000195e-01', $next), 'SQLite 3.51.0 spelling');
        $this->assertNotNull($this->realEncodingProblem('0.1', $next), 'A different double must be rejected.');
        $this->assertNotNull($this->realEncodingProblem('t:302E31', 0.1), 'A tagged text must be rejected.');
    }

    /**
     * Null when $encoded is an untagged decimal number (plain or with an
     * exponent) that reads back to exactly the double $expected, else the
     * problem. PHP's float conversion rounds correctly, so every exact
     * spelling of the same double passes.
     */
    private function realEncodingProblem(string $encoded, float $expected): ?string
    {
        if (preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?$/D', $encoded) !== 1) {
            return "'{$encoded}' is not an untagged number";
        }

        if ((float) $encoded !== $expected) {
            return "'{$encoded}' reads back as ".var_export((float) $encoded, true).', not '.var_export($expected, true);
        }

        return null;
    }

    /**
     * The next representable double after $value, towards positive infinity.
     */
    private function nextDoubleAfter(float $value): float
    {
        $bits = unpack('q', pack('d', $value))[1];

        return unpack('d', pack('q', $bits + 1))[1];
    }

    /**
     * The negative control of the table list in each snapshot.
     *
     * A RouteMatched listener acts only on denied requests. The first denied
     * request creates a table on each connection, and the next one drops it
     * again. So each route ends with the table list that it started with,
     * but every denied request must fail its own state check.
     */
    #[FreshSchema]
    public function test_a_denied_read_that_creates_or_drops_a_table_fails_the_state_check(): void
    {
        $this->rememberEnvironment();

        $key = 'GET orrery/api/workload';
        $fixtures = [$key => $this->requestFixtures()[$key]];

        /** @var list<array{0: Connection, 1: string}> $targets */
        $targets = [
            [$this->app['db']->connection(TestEnvironment::PRIMARY), 'authz_probe_primary'],
            [$this->mirror(), 'authz_probe_mirror'],
        ];

        try {
            $changedTables = [];

            foreach ($targets as [$connection, $table]) {
                $changedTables[] = $connection->getName().' (tables)';
                $changedTables[] = $connection->getName().'.'.$connection->getTablePrefix().$table;
            }

            $changedTables = array_values(array_unique($changedTables));
            sort($changedTables, SORT_STRING);

            // The unchanged package: no denied case changes the state.
            $this->assertCases($fixtures);

            $this->app['events']->listen(RouteMatched::class, function (RouteMatched $event) use ($targets): void {
                if (Orrery::check($event->request)) {
                    return;
                }

                foreach ($targets as [$connection, $table]) {
                    $schema = $connection->getSchemaBuilder();

                    if ($schema->hasTable($table)) {
                        $schema->drop($table);
                    } else {
                        $schema->create($table, static function (Blueprint $blueprint): void {
                            $blueprint->string('key')->primary();
                        });
                    }
                }
            });

            $expected = [];

            foreach (self::CASES as $case) {
                $expected[$key][$case] = $this->allows($case)
                    ? $this->expectedOutcome('GET', $fixtures[$key], $case)
                    : $this->denial($case, $fixtures[$key], 'GET').', state changed in '.implode(' ', $changedTables);
            }

            $this->assertStateCheckFails($fixtures, $expected);

            // Four denied requests: create, drop, create, drop.
            foreach ($targets as [$connection, $table]) {
                $this->assertFalse($connection->getSchemaBuilder()->hasTable($table), "The last denied request did not drop {$table}.");
            }
        } finally {
            foreach ($targets as [$connection, $table]) {
                $connection->getSchemaBuilder()->dropIfExists($table);
            }
        }
    }

    /**
     * Representation controls: the snapshot tells apart values that an
     * engine stores differently. The test writes the first value, takes a
     * snapshot, writes the second value, takes another snapshot, and requires
     * exactly the changed table. It also reads both values back, so a value
     * that the engine itself rounds or merges fails before the comparison.
     */
    #[DataProvider('representationPairs')]
    public function test_the_snapshot_tells_apart_values_that_the_engine_keeps_apart(string $pair): void
    {
        $mirror = $this->mirror();

        if ($pair === 'null and empty string') {
            $table = 'orrery_settings';
            $rows = $mirror->table($table)->where('key', 'authz-representation');

            $mirror->table($table)->insert(['key' => 'authz-representation', 'value' => null]);
            $this->assertNull((clone $rows)->value('value'));
            $before = $this->databaseState();

            (clone $rows)->update(['value' => '']);
            $this->assertSame('', (clone $rows)->value('value'));
        } else {
            // Two decimal(16,6) values one unit of the last place apart (the package keeps no double column).
            $table = 'orrery_samples';
            $rows = $mirror->table($table)->where('scope', 'queue')->where('series', str_repeat('a', 64));

            $mirror->table($table)->insert([
                'scope' => 'queue',
                'series' => str_repeat('a', 64),
                'connection' => 'database',
                'queue' => 'authz-representation',
                'minute' => 1_790_000_040,
                'oldest_age' => '0.100000',
            ]);
            $this->assertSame('0.100000', number_format((float) (clone $rows)->value('oldest_age'), 6, '.', ''));
            $before = $this->databaseState();

            (clone $rows)->update(['oldest_age' => '0.100001']);
            $this->assertSame('0.100001', number_format((float) (clone $rows)->value('oldest_age'), 6, '.', ''));
        }

        $this->assertSame(
            [$mirror->getName().'.'.$mirror->getTablePrefix().$table],
            $this->changedTables($before, $this->databaseState()),
            "The snapshot does not tell apart the {$pair} values.",
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function representationPairs(): array
    {
        return [
            'null and empty string' => ['null and empty string'],
            'adjacent decimals' => ['adjacent decimals'],
        ];
    }

    /**
     * Runs assertCases() and requires it to fail with exactly the expected
     * outcome matrix. A passing assertCases() fails the test.
     *
     * @param  array<string, array<string, mixed>>  $fixtures
     * @param  array<string, array<string, string>>  $expected
     */
    private function assertStateCheckFails(array $fixtures, array $expected): void
    {
        try {
            $this->assertCases($fixtures);
        } catch (ExpectationFailedException $failure) {
            $this->assertSame(
                $expected,
                $failure->getComparisonFailure()?->getActual(),
                'The state check failed, but not with the expected change of every denied read.',
            );

            return;
        }

        $this->fail('The state check did not see the change of the denied reads.');
    }

    /**
     * The request fixture of every route and method: fixtureMap() plus a HEAD
     * fixture for each GET fixture (the router adds HEAD to every GET route).
     *
     * @return array<string, array<string, mixed>>
     */
    private function requestFixtures(): array
    {
        $fixtures = $this->fixtureMap();

        foreach ($fixtures as $key => $fixture) {
            if (str_starts_with($key, 'GET ')) {
                $fixtures['HEAD '.substr($key, 4)] = $fixture;
            }
        }

        return $fixtures;
    }

    /**
     * One request per route and method, with valid parameters and existing
     * resources. The key is the method and the route URI as the router lists
     * them. A read has a content check for the allowed cases. A write has an
     * arrange step, a probe before the request and an effect check after it.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fixtureMap(): array
    {
        $api = '/'.self::PATH.'/api';

        return [
            'GET orrery/api/workload' => $this->read(fn (): string => $api.'/workload'),
            'GET orrery/api/workers' => $this->read(
                fn (): string => $api.'/workers',
                fn (TestResponse $response): ?string => $this->problem(
                    $this->contains($response->json(), 'pid', (string) getmypid()),
                    'the worker of the fixture heartbeat is missing',
                ),
            ),
            'GET orrery/api/health' => $this->read(
                fn (): string => $api.'/health',
                fn (TestResponse $response): ?string => $this->problem(
                    $response->json('notice') === 'Unreported gaps cannot be ruled out.',
                    'the health notice is missing',
                ),
            ),
            'GET orrery/api/counts' => $this->read(
                fn (): string => $api.'/counts',
                fn (TestResponse $response): ?string => $this->problem(
                    is_int($response->json('failed.count')) && $response->json('failed.at_least') === false,
                    'the counts body is missing',
                ),
            ),
            'GET orrery/api' => array_replace($this->read(
                fn (): string => $api,
                fn (TestResponse $response): ?string => $this->problem(
                    $response->json('error') === 'not_found',
                    'the API 404 body is missing',
                ),
            ), ['status' => 404]),
            'GET orrery/api/{any}' => array_replace($this->read(
                fn (): string => $api.'/not-an-endpoint',
                fn (TestResponse $response): ?string => $this->problem(
                    $response->json('error') === 'not_found',
                    'the API 404 body is missing',
                ),
            ), ['status' => 404]),
            'GET orrery/api/control' => $this->read(fn (): string => $api.'/control'),
            'GET orrery/api/markers' => $this->read(
                fn (): string => $api.'/markers',
                fn (TestResponse $response): ?string => $this->problem(
                    is_array($response->json('markers')) && $response->json('truncated') === false,
                    'the markers body is missing',
                ),
            ),
            'GET orrery/api/throughput' => $this->read(
                fn (): string => $api.'/throughput?minutes=5',
                fn (TestResponse $response): ?string => $this->problem(
                    $response->json('bucket_seconds') === 60 && is_array($response->json('buckets')) && is_array($response->json('markers')),
                    'the throughput body is missing',
                ),
            ),
            'GET orrery/api/settings/retention' => $this->read(
                fn (): string => $api.'/settings/retention',
                fn (TestResponse $response): ?string => $this->problem(
                    is_array($response->json('global')) && is_array($response->json('effective')) && $response->json('errors') === [],
                    'the retention settings body is missing',
                ),
            ),
            'GET orrery/api/classes' => $this->read(
                fn (): string => $api.'/classes?minutes=5',
                fn (TestResponse $response): ?string => $this->problem(
                    is_array($response->json('classes')) && $response->json('truncated') === false,
                    'the job classes body is missing',
                ),
            ),
            'GET orrery/api/classes/series' => $this->read(
                fn (): string => $api.'/classes/series?minutes=5&name=x',
                fn (TestResponse $response): ?string => $this->problem(
                    $response->json('bucket_seconds') === 60 && $response->json('name') === 'x',
                    'the job class series body is missing',
                ),
            ),
            'GET orrery/api/queues/history' => $this->read(
                fn (): string => $api.'/queues/history?connection=database&queue='.self::WORK_QUEUE,
                fn (TestResponse $response): ?string => $this->problem(
                    $response->json('bucket_seconds') === 60 && $response->json('queue') === self::WORK_QUEUE,
                    'the queue history body is missing',
                ),
            ),
            'POST orrery/api/control' => $this->write(
                fn (): string => $api.'/control',
                ['action' => 'pause', 'scope' => 'queue', 'connection' => 'database', 'queue' => self::PAUSED_QUEUE],
                function (): void {
                    app(SignalState::class)->continue('queue', 'database:'.self::PAUSED_QUEUE);
                },
                fn (): mixed => null,
                fn (): ?string => $this->problem(
                    $this->mirror()->table('orrery_signals')
                        ->where('signal', 'pause')
                        ->where('scope', 'queue')
                        ->where('scope_value', 'database:'.self::PAUSED_QUEUE)
                        ->exists(),
                    'no pause signal for database:'.self::PAUSED_QUEUE,
                ),
            ),
            'GET orrery/api/tags' => $this->read(
                fn (): string => $api.'/tags',
                fn (TestResponse $response): ?string => $this->problem(
                    $this->contains($response->json('tags'), 'tag', self::MONITORED_TAG),
                    'the monitored fixture tag is missing',
                ),
            ),
            'POST orrery/api/tags/monitored' => $this->write(
                fn (): string => $api.'/tags/monitored',
                ['tag' => self::ADDED_TAG],
                fn () => $this->arrangeTag(self::ADDED_TAG, monitored: false),
                fn (): mixed => null,
                fn (): ?string => $this->tagChange(self::ADDED_TAG, monitored: true),
                fn (TestResponse $response): ?string => $this->problem($response->json('changed') === true, 'the monitor change is not reported'),
            ),
            'DELETE orrery/api/tags/monitored' => $this->write(
                fn (): string => $api.'/tags/monitored?tag='.rawurlencode(self::REMOVED_TAG),
                [],
                fn () => $this->arrangeTag(self::REMOVED_TAG, monitored: true),
                fn (): mixed => null,
                fn (): ?string => $this->tagChange(self::REMOVED_TAG, monitored: false),
                fn (TestResponse $response): ?string => $this->problem($response->json('changed') === true, 'the unmonitor change is not reported'),
            ),
            'DELETE orrery/api/tags/monitored/{tag}' => $this->write(
                fn (): string => $api.'/tags/monitored/'.rawurlencode(self::REMOVED_TAG),
                [],
                fn () => $this->arrangeTag(self::REMOVED_TAG, monitored: true),
                fn (): mixed => null,
                fn (): ?string => $this->tagChange(self::REMOVED_TAG, monitored: false),
                fn (TestResponse $response): ?string => $this->problem($response->json('changed') === true, 'the unmonitor change is not reported'),
            ),
            'GET orrery/api/jobs' => $this->read(
                fn (): string => $api.'/jobs?status=pending',
                fn (TestResponse $response): ?string => $this->problem(
                    $this->contains($response->json('jobs'), 'id', $this->ids['pending']),
                    'the pending fixture job is missing from the list engine',
                ),
            ),
            'GET orrery/api/jobs/stuck' => $this->read(
                fn (): string => $api.'/jobs/stuck?signal=outcome_not_observed',
                fn (TestResponse $response): ?string => $this->problem(
                    is_array($response->json('jobs')) && $response->json('signal') === 'outcome_not_observed',
                    'not the stuck list of the signal',
                ),
            ),
            // The per-job writes, with the occurrence that the page read.
            'POST orrery/api/jobs/{uuid}/retry' => $this->write(
                fn (): string => $api.'/jobs/'.$this->ids['failed'].'/retry',
                fn (): array => ['occurrence_id' => (int) $this->mirror()->table('orrery_jobs')->where('uuid', $this->ids['failed'])->value('occurrence_id')],
                fn () => $this->failAgainIfRetried($this->ids['failed']),
                fn (): int => $this->queuedRetriesOf($this->ids['failed']),
                fn (mixed $before): ?string => $this->problem(
                    $this->queuedRetriesOf($this->ids['failed']) === $before + 1,
                    'no new queued retry of the failed job',
                ),
                fn (TestResponse $response): ?string => $this->problem(
                    $response->json('result') === 'accepted' && is_int($response->json('job_id')),
                    'the response does not report an accepted retry: '.$response->getContent(),
                ),
            ),
            'POST orrery/api/jobs/{uuid}/forget' => $this->write(
                fn (): string => $api.'/jobs/'.$this->ids['forget'].'/forget',
                fn (): array => ['occurrence_id' => (int) $this->mirror()->table('orrery_jobs')->where('uuid', $this->ids['forget'])->value('occurrence_id')],
                fn () => $this->failForgetTarget(),
                fn (): string => $this->ids['forget'],
                fn (mixed $before): ?string => $this->problem(
                    $this->mirror()->table('orrery_jobs')->where('uuid', $before)->doesntExist()
                        && $this->app['db']->connection(TestEnvironment::PRIMARY)->table('failed_jobs')->where('uuid', $before)->doesntExist(),
                    'the forget target still exists',
                ),
                fn (TestResponse $response): ?string => $this->problem(
                    $response->json('result') === 'deleted',
                    'the response does not report a forget: '.$response->getContent(),
                ),
            ),
            // The writes on waiting jobs: one waiting job per case, on a queue of its own.
            'POST orrery/api/jobs/{uuid}/delete' => $this->write(
                fn (): string => $api.'/jobs/'.$this->waitingTarget.'/delete',
                [],
                fn () => $this->arrangeWaiting(self::DELETE_QUEUE),
                fn (): string => $this->waitingTarget,
                fn (mixed $before): ?string => $this->problem(
                    $this->mirror()->table('orrery_jobs')->where('uuid', $before)->doesntExist() && $this->waitingOn(self::DELETE_QUEUE) === 0,
                    'the waiting job still exists',
                ),
                fn (TestResponse $response): ?string => $this->problem(
                    $response->json('result') === 'deleted',
                    'the response does not report a delete: '.$response->getContent(),
                ),
            ),
            'POST orrery/api/jobs/{uuid}/run-now' => $this->write(
                fn (): string => $api.'/jobs/'.$this->waitingTarget.'/run-now',
                [],
                fn () => $this->arrangeDelayed(),
                fn (): string => $this->waitingTarget,
                fn (mixed $before): ?string => $this->problem(
                    (int) $this->app['db']->connection(TestEnvironment::PRIMARY)->table('jobs')->where('queue', self::LATER_QUEUE)->value('available_at') <= time(),
                    'the delayed job is still delayed',
                ),
                fn (TestResponse $response): ?string => $this->problem(
                    $response->json('result') === 'available',
                    'the response does not report a run-now: '.$response->getContent(),
                ),
            ),
            'POST orrery/api/batches/{id}/cancel' => $this->write(
                fn (): string => $api.'/batches/'.self::CANCEL_BATCH.'/cancel',
                [],
                fn () => $this->arrangeCancel(),
                fn (): string => self::CANCEL_BATCH,
                fn (mixed $before): ?string => $this->problem(
                    $this->app['db']->connection(TestEnvironment::PRIMARY)->table('job_batches')->where('id', $before)->value('cancelled_at') !== null,
                    'the batch was not cancelled',
                ),
                fn (TestResponse $response): ?string => $this->problem(
                    $response->json('result') === 'cancelled',
                    'the response does not report a cancel: '.$response->getContent(),
                ),
            ),
            'POST orrery/api/queues/waiting' => $this->operatorRead(
                fn (): string => $api.'/queues/waiting',
                fn (): array => ['connection' => 'database', 'queue' => self::CLEAR_QUEUE],
                fn () => $this->arrangeWaiting(self::CLEAR_QUEUE),
                fn (TestResponse $response): ?string => $this->problem(
                    $response->json('waiting') === 1 && $response->json('ready') === 1,
                    'the count does not hold the waiting job: '.$response->getContent(),
                ),
            ),
            'POST orrery/api/queues/clear' => $this->write(
                fn (): string => $api.'/queues/clear',
                ['connection' => 'database', 'queue' => self::CLEAR_QUEUE],
                fn () => $this->arrangeWaiting(self::CLEAR_QUEUE),
                fn (): int => $this->waitingOn(self::CLEAR_QUEUE),
                fn (mixed $before): ?string => $this->problem(
                    $before === 1 && $this->waitingOn(self::CLEAR_QUEUE) === 0,
                    'the waiting job of the queue was not cleared',
                ),
                fn (TestResponse $response): ?string => $this->problem(
                    $response->json('deleted') === 1 && $response->json('complete') === true,
                    'the response does not report the clear: '.$response->getContent(),
                ),
            ),
            // The bulk runs. The freeze only reads; the run retries the failed batch member.
            'POST orrery/api/bulk/freeze' => $this->operatorRead(
                fn (): string => $api.'/bulk/freeze',
                fn (): array => ['action' => 'retry', 'source' => ['kind' => 'batch', 'batch_id' => $this->ids['batch']]],
                fn () => $this->failAgainIfRetried($this->ids['member']),
                fn (TestResponse $response): ?string => $this->problem(
                    is_string($response->json('frozen.token')) && $response->json('frozen.total') === ['count' => 1, 'at_least' => false],
                    'the freeze does not hold the failed batch member: '.$response->getContent(),
                ),
            ),
            'POST orrery/api/bulk/run' => $this->write(
                fn (): string => $api.'/bulk/run',
                fn (): array => ['token' => $this->bulkToken, 'after' => null, 'confirmed_total' => null],
                function (): void {
                    $this->failAgainIfRetried($this->ids['member']);
                    $this->bulkToken = $this->app->make(BulkRun::class)->freeze('retry', ['kind' => 'batch', 'batch_id' => $this->ids['batch']])['frozen']['token'];
                },
                fn (): int => $this->queuedRetriesOf($this->ids['member']),
                fn (mixed $before): ?string => $this->problem(
                    $this->queuedRetriesOf($this->ids['member']) === $before + 1,
                    'no new queued retry of the failed batch member',
                ),
                fn (TestResponse $response): ?string => $this->acceptedTotals($response, 'refused', 'uncertain', 'not_member', 'already_done', 'not_confirmed'),
            ),
            'GET orrery/api/bulk' => $this->read(
                fn (): string => $api.'/bulk',
                fn (TestResponse $response): ?string => $this->problem(
                    $this->contains($response->json('runs'), 'ref', self::BULK_REF),
                    'the fixture bulk run is missing',
                ),
            ),
            'GET orrery/api/bulk/{ref}' => $this->read(
                fn (): string => $api.'/bulk/'.self::BULK_REF,
                fn (TestResponse $response): ?string => $this->problem(
                    $response->json('ref') === self::BULK_REF && is_array($response->json('operations')),
                    'not the fixture bulk run',
                ),
            ),
            'GET orrery/api/jobs/{uuid}' => $this->read(
                fn (): string => $api.'/jobs/'.$this->ids['failed'],
                fn (TestResponse $response): ?string => $this->problem(
                    $response->json('id') === $this->ids['failed'] && $response->json('status') === 'failed' && is_array($response->json('cycles')),
                    'not the detail of the failed fixture job',
                ),
            ),
            'GET orrery/api/jobs/{uuid}/history' => $this->read(
                fn (): string => $api.'/jobs/'.$this->ids['failed'].'/history',
                fn (TestResponse $response): ?string => $this->problem(
                    in_array('failed', array_column((array) $response->json('entries'), 'event'), true),
                    'the failure of the failed fixture job is not in its history',
                ),
            ),
            'GET orrery/api/jobs/{uuid}/history/{id}' => $this->read(
                fn (): string => $api.'/jobs/'.$this->ids['failed'].'/history/'.$this->failureEntry($this->ids['failed']),
                fn (TestResponse $response): ?string => $this->problem(
                    $response->json('event') === 'failed' && is_string($response->json('exception')),
                    'not the failure entry of the failed fixture job',
                ),
            ),
            'GET orrery/api/batches' => $this->read(
                fn (): string => $api.'/batches',
                fn (TestResponse $response): ?string => $this->problem(
                    $this->contains($response->json('batches'), 'id', $this->ids['batch']),
                    'the fixture batch is missing',
                ),
            ),
            'GET orrery/api/batches/{id}' => $this->read(
                fn (): string => $api.'/batches/'.$this->ids['batch'],
                fn (TestResponse $response): ?string => $this->problem(
                    $response->json('batch.id') === $this->ids['batch']
                        && $response->json('members.failed.count') >= 1,
                    'not the fixture batch with its failed member',
                ),
            ),
            'GET orrery/api/failures/groups' => $this->read(
                fn (): string => $api.'/failures/groups?'.$this->failureWindow(),
                fn (TestResponse $response): ?string => $this->problem(
                    $this->contains($response->json('groups'), 'key', $this->failureKey($this->ids['failed'])),
                    'the group of the failed fixture job is missing',
                ),
            ),
            'GET orrery/api/failures/groups/{key}' => $this->read(
                fn (): string => $api.'/failures/groups/'.$this->failureKey($this->ids['failed']).'?'.$this->failureWindow(),
                fn (TestResponse $response): ?string => $this->problem(
                    $this->contains($response->json('jobs'), 'uuid', $this->ids['failed']),
                    'the failed fixture job is not a member of its group',
                ),
            ),
            // The operator's resolve of a published retry operation, whose step only writes the mirror.
            'POST orrery/api/operations/{id}/resolve' => $this->write(
                fn (): string => $api.'/operations/'.self::RESOLVE_OPERATION.'/resolve',
                [],
                fn () => $this->arrangeResolve(),
                fn (): string => self::RESOLVE_OPERATION,
                fn (mixed $before): ?string => $this->problem(
                    $this->mirror()->table('orrery_operations')->where('id', $before)->value('state') === 'done',
                    'the operation was not resolved',
                ),
            ),
            'GET orrery/{view?}' => $this->read(
                fn (): string => '/'.self::PATH.'/dashboard',
                fn (TestResponse $response): ?string => $this->problem(
                    str_contains((string) $response->getContent(), '<div id="orrery"'),
                    'not the dashboard shell',
                ),
                html: true,
            ),
        ];
    }

    /** The queue of the delete fixture. */
    private const DELETE_QUEUE = 'authz-delete';

    /** The queue of the clear and count fixtures. */
    private const CLEAR_QUEUE = 'authz-clear';

    /** The batch of the cancel fixture: open again before every case. */
    private const CANCEL_BATCH = 'c4c4c4c4-c4c4-4c4c-8c4c-c4c4c4c4c4c4';

    private function arrangeCancel(): void
    {
        $table = $this->app['db']->connection(TestEnvironment::PRIMARY)->table('job_batches');
        $table->where('id', self::CANCEL_BATCH)->delete();
        $table->insert([
            'id' => self::CANCEL_BATCH, 'name' => 'authorization cancel', 'total_jobs' => 1, 'pending_jobs' => 1, 'failed_jobs' => 0,
            'failed_job_ids' => '[]', 'options' => null, 'created_at' => time(), 'cancelled_at' => null, 'finished_at' => null,
        ]);
    }

    /** The queue of the run-now fixture. */
    private const LATER_QUEUE = 'authz-later';

    /**
     * Exactly one waiting job on LATER_QUEUE, delayed by an hour: a new one when there is none, else the
     * existing one is delayed again.
     */
    private function arrangeDelayed(): void
    {
        if ($this->waitingOn(self::LATER_QUEUE) === 0) {
            AuthorizationJob::dispatch()->onConnection('database')->onQueue(self::LATER_QUEUE)->delay(3600);
        }

        $this->app['db']->connection(TestEnvironment::PRIMARY)->table('jobs')->where('queue', self::LATER_QUEUE)->update(['available_at' => time() + 3600]);
        $this->mirror()->table('orrery_jobs')->where('queue', self::LATER_QUEUE)->update(['available_at' => Clock::toDecimal(time() + 3600)]);
        $this->mirror()->table('orrery_job_history')->whereIn('job_uuid', $this->mirror()->table('orrery_jobs')->where('queue', self::LATER_QUEUE)->pluck('uuid')->all())->delete();
        $this->assertSame(1, $this->waitingOn(self::LATER_QUEUE));
        $this->waitingTarget = (string) $this->mirror()->table('orrery_jobs')->where('queue', self::LATER_QUEUE)->where('status', 'pending')->value('uuid');
        $this->assertNotSame('', $this->waitingTarget, 'The delayed job has no mirror row.');
    }

    /** The uuid of the waiting job of the last arrangeWaiting(). */
    private string $waitingTarget = '';

    /**
     * Exactly one waiting job on $queue, pushed through the swapped queue when there is none.
     */
    private function arrangeWaiting(string $queue): void
    {
        if ($this->waitingOn($queue) === 0) {
            AuthorizationJob::dispatch()->onConnection('database')->onQueue($queue);
        }

        $this->assertSame(1, $this->waitingOn($queue));
        $this->waitingTarget = (string) $this->mirror()->table('orrery_jobs')->where('queue', $queue)->where('status', 'pending')->value('uuid');
        $this->assertNotSame('', $this->waitingTarget, 'The waiting job has no mirror row.');
    }

    private function waitingOn(string $queue): int
    {
        return $this->app['db']->connection(TestEnvironment::PRIMARY)->table('jobs')->where('queue', $queue)->whereNull('reserved_at')->count();
    }

    /** The operation of the resolve fixture. */
    private const RESOLVE_OPERATION = 'c5b0c5b0c5b0c5b0c5b0c5b0c5b0c5b0';

    /**
     * A published retry operation whose row is gone: its resolve ends it done (row_moved) on the mirror alone.
     */
    private function arrangeResolve(): void
    {
        $mirror = $this->mirror();
        $mirror->table('orrery_operations')->where('id', self::RESOLVE_OPERATION)->delete();
        $operations = $this->app->make(Operations::class);
        $operations->insertClaimed($mirror, [
            'id' => self::RESOLVE_OPERATION, 'action' => 'retry', 'mode' => 'none', 'job_uuid' => '0c5b0c5b-0c5b-4c5b-8c5b-0c5b0c5b0c5b',
            'occurrence_id' => 1, 'queue_connection' => 'database', 'queue' => 'default', 'queue_fingerprint' => str_repeat('0', 64),
            'deadline' => 1000.0, 'next_check_at' => 1000.0, 'source' => 'dashboard',
        ]);
        $operations->markPublished($mirror, self::RESOLVE_OPERATION, 1);
    }

    /**
     * A read. The content check proves that the allowed response used the
     * fixture resource. Routes without a fixture resource have none.
     *
     * @param  Closure(): string  $uri
     * @param  (Closure(TestResponse): ?string)|null  $content  null means that the content is as expected
     * @return array<string, mixed>
     */
    private function read(Closure $uri, ?Closure $content = null, bool $html = false): array
    {
        return ['kind' => 'read', 'uri' => $uri, 'body' => [], 'html' => $html, 'status' => 200, 'content' => $content];
    }

    /**
     * A POST that only reads, for operators only (POST /api/bulk/freeze):
     * it needs the operate ability, as every unsafe method, and changes no state.
     *
     * @param  Closure(): string  $uri
     * @param  Closure(): array<string, mixed>  $body
     * @param  Closure(): void  $arrange  puts the target into the same state before every case
     * @param  Closure(TestResponse): ?string  $content
     * @return array<string, mixed>
     */
    private function operatorRead(Closure $uri, Closure $body, Closure $arrange, Closure $content): array
    {
        return ['kind' => 'operator_read', 'uri' => $uri, 'body' => $body, 'html' => false, 'status' => 200, 'content' => $content, 'arrange' => $arrange];
    }

    /**
     * @param  Closure(): string  $uri
     * @param  array<string, mixed>|(Closure(): array<string, mixed>)  $body  a closure is evaluated at the request, after the arrange step
     * @param  Closure(): void  $arrange  puts the target into the same state before every case
     * @param  Closure(): mixed  $probe  reads what the effect check compares with
     * @param  Closure(mixed): ?string  $effect  checks the route's effect; null means that it happened
     * @param  (Closure(TestResponse): ?string)|null  $content  checks that the response reports the effect
     * @return array<string, mixed>
     */
    private function write(Closure $uri, array|Closure $body, Closure $arrange, Closure $probe, Closure $effect, ?Closure $content = null): array
    {
        return [
            'kind' => 'write',
            'uri' => $uri,
            'body' => $body,
            'html' => false,
            'status' => 200,
            'content' => $content,
            'arrange' => $arrange,
            'probe' => $probe,
            'effect' => $effect,
        ];
    }

    /**
     * The totals of a batch or group operation report exactly one accepted
     * item and none of the other outcomes, so the dashboard shows the work
     * that happened.
     */
    private function acceptedTotals(TestResponse $response, string ...$others): ?string
    {
        $totals = $response->json('totals');
        $reported = is_array($totals) && ($totals['accepted'] ?? null) === 1;

        foreach ($others as $outcome) {
            $reported = $reported && is_array($totals) && ($totals[$outcome] ?? null) === 0;
        }

        return $this->problem($reported, 'the response does not report one accepted item: '.$response->getContent());
    }

    /**
     * The package's routes, whatever their middleware: every route under the
     * configured path, with an orrery.* name, or with a package
     * controller. One "METHOD uri" key per method, sorted.
     *
     * @return list<string>
     */
    private function discoveredRoutes(): array
    {
        $path = trim((string) config('orrery.path'), '/');
        $keys = [];

        /** @var Route $route */
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            $package = $uri === $path
                || str_starts_with($uri, $path.'/')
                || str_starts_with((string) $route->getName(), 'orrery.')
                || str_starts_with(ltrim($route->getActionName(), '\\'), 'Justrau\\Orrery\\');

            if (! $package) {
                continue;
            }

            foreach ($route->methods() as $method) {
                $keys[] = $method.' '.$uri;
            }
        }

        sort($keys);

        return $keys;
    }

    /**
     * The fixtures, through the package's real code paths where they work in
     * every test configuration. It asserts them before any route is tested.
     */
    private function createFixtures(): void
    {
        $this->rememberEnvironment();

        // A monitored tag, through the package's own job, before the push that carries it.
        dispatch_sync(new MonitorTag(self::MONITORED_TAG));

        // Pushes through the swapped database queue, so the push hook and JobQueued fill the mirror.
        AuthorizationJob::dispatch(true, [self::MONITORED_TAG])->onConnection('database')->onQueue(self::WORK_QUEUE);

        $batch = Bus::batch([new AuthorizationJob(true)])
            ->name('authorization')
            ->allowFailures()
            ->onConnection('database')
            ->onQueue(self::WORK_QUEUE)
            ->dispatch();

        AuthorizationJob::dispatch()->onConnection('database')->onQueue(self::PENDING_QUEUE);

        // The real queue:work command fails both jobs. Its JobFailed listener
        // logs them to failed_jobs, and the batch records its failed member.
        foreach ([1, 2] as $run) {
            $status = Artisan::call('queue:work', [
                'connection' => 'database',
                '--queue' => self::WORK_QUEUE,
                '--once' => true,
                '--tries' => 1,
                '--sleep' => 0, '--memory' => 4096,
            ]);

            $this->assertSame(0, $status, "queue:work run {$run} failed: ".Artisan::output());
        }

        $this->assertSame(0, Queue::connection('database')->size(self::WORK_QUEUE), 'queue:work left a fixture job on '.self::WORK_QUEUE.'.');

        $failed = $this->app['db']->connection(TestEnvironment::PRIMARY)->table('failed_jobs')->pluck('uuid')->map(static fn (mixed $uuid): string => (string) $uuid)->all();
        $this->assertCount(2, $failed, 'queue:work did not log both fixture jobs to failed_jobs.');

        $stored = $this->app->make(BatchRepository::class)->find($batch->id);
        $this->assertNotNull($stored, 'The fixture batch is not stored.');
        $this->assertSame(1, $stored->failedJobs, 'The fixture batch did not record its failed member.');
        $this->assertCount(1, $stored->failedJobIds);

        $member = (string) $stored->failedJobIds[0];
        $this->assertContains($member, $failed, 'The failed id of the batch is not a failed_jobs row.');

        $others = array_values(array_diff($failed, [$member]));
        $this->assertCount(1, $others);
        $plain = $others[0];

        $this->assertSame('failed', $this->mirror()->table('orrery_jobs')->where('uuid', $plain)->value('status'), 'The mirror does not show the failed fixture job.');
        $this->assertSame('failed', $this->mirror()->table('orrery_jobs')->where('uuid', $member)->value('status'), 'The mirror does not show the failed batch member.');

        // The batch retry finds its members by batch_id in the mirror. Payloads of
        // Laravel releases before 12.51 carry no data.batchId, so the mirror has no batch
        // membership there (a documented limit). The fixture then writes it directly.
        $this->mirror()->table('orrery_jobs')->where('uuid', $member)->whereNull('batch_id')->update(['batch_id' => (string) $batch->id]);

        // A finished bulk run, for the bulk reads.
        $at = Clock::toDecimal(Clock::now());
        $this->mirror()->table('orrery_bulk_runs')->insert([
            'ref' => self::BULK_REF, 'action' => 'retry', 'source' => '{"batch_id":"authorization","kind":"batch"}', 'source_hash' => str_repeat('0', 64),
            'max_occurrence' => 1, 'total' => 1, 'actor' => 'authorization', 'created_at' => $at, 'last_run_at' => $at,
        ]);

        $this->assertSame([self::MONITORED_TAG], $this->monitoredTags());
        $this->assertSame([$plain], $this->mirror()->table('orrery_job_tags')->where('tag', self::MONITORED_TAG)->pluck('job_uuid')->all(), 'The failed fixture job does not carry the monitored tag.');

        // queue:work through Artisan::call() inside this PHPUnit process is no
        // worker process, so it writes no heartbeat. The fixture
        // writes the heartbeat of a worker process with this PID.
        $this->assertSame(0, $this->mirror()->table('orrery_worker_heartbeats')->count());
        ProcessContext::useForTesting(ProcessContext::current()->withKindForTesting(ProcessContext::WORKER));
        RecordWorkerHeartbeat::resetThrottle();
        RecordWorkerHeartbeat::write('database');
        ProcessContext::useForTesting(null);
        $heartbeats = $this->mirror()->table('orrery_worker_heartbeats')->get();
        $this->assertCount(1, $heartbeats);
        $this->assertSame(getmypid(), (int) $heartbeats[0]->pid);

        $pending = $this->mirror()->table('orrery_jobs')->where('queue', self::PENDING_QUEUE)->get();
        $this->assertCount(1, $pending);
        $this->assertSame('pending', $pending[0]->status);

        // Direct writes: the worker's counter upsert fails with a table prefix.
        $completed = (string) Str::uuid();
        $now = microtime(true);
        $payload = (string) json_encode(['uuid' => $completed, 'displayName' => AuthorizationJob::class]);

        $this->mirror()->table('orrery_jobs')->insert([
            'uuid' => $completed,
            'connection' => 'database',
            'queue' => self::WORK_QUEUE,
            'name' => AuthorizationJob::class,
            'type' => 'job',
            'status' => 'completed',
            'silenced' => false,
            'monitored' => false,
            'payload' => $payload,
            'payload_bytes' => strlen($payload),
            'pushed_at' => $now - 2,
            'reserved_at' => $now - 1,
            'finished_at' => $now,
            'updated_at' => $now,
        ]);

        // The counts frontier M at the completion's minute: the sample readers scan that minute
        // and every later one, so a minute boundary during the test keeps the fixture known.
        $this->mirror()->table('orrery_settings')->insert([
            ['key' => SampleCounts::COUNTED_THROUGH, 'value' => (string) SampleCounts::minuteOf($now)],
            ['key' => SampleCounts::COUNTED_AT, 'value' => Clock::toDecimal($now)],
        ]);

        $this->ids = [
            'failed' => $plain,
            'member' => $member,
            'batch' => (string) $batch->id,
            'pending' => (string) $pending[0]->uuid,
            'completed' => $completed,
            'forget' => '',
        ];
    }

    /**
     * The id of the `failed` history entry of a job.
     */
    private function failureEntry(string $uuid): int
    {
        return (int) $this->mirror()->table('orrery_job_history')->where('job_uuid', $uuid)->where('event', 'failed')->orderByDesc('id')->value('id');
    }

    /**
     * The fence table on the queue's database, as orrery:install --fence and
     * migrate give an application whose queue database is not the mirror:
     * a SQLite file, whose
     * package tables are in the sibling mirror file, and the separate
     * mode. In the same mode on a server, the mirror migration created it.
     * Without it, every retry and forget is refused with fence_missing.
     * The table is dropped when the application ends, before the harness
     * rolls its migrations back.
     */
    private function installQueueFence(): void
    {
        $primary = $this->app['db']->connection(TestEnvironment::PRIMARY);
        $schema = $primary->getSchemaBuilder();

        if ($schema->hasTable(Fence::TABLE)) {
            return;
        }

        PackageSchema::create($schema, [Fence::TABLE]);

        $this->beforeApplicationDestroyed(function (): void {
            PackageSession::releaseAll();
            $this->app['db']->connection(TestEnvironment::PRIMARY)->getSchemaBuilder()->dropIfExists(Fence::TABLE);
        });
    }

    /**
     * The environment of the test outside local, which beginCase() restores
     * before every case.
     */
    private function rememberEnvironment(): void
    {
        $this->environmentName = (string) $this->app['env'];
        $this->assertNotSame('local', $this->environmentName, 'The denied cases need an environment other than local.');
    }

    /**
     * Runs every case on every route and compares all outcomes at once, so a
     * failure lists every route and case that differs.
     *
     * @param  array<string, array<string, mixed>>  $fixtures
     */
    private function assertCases(array $fixtures): void
    {
        $this->assertNotSame([], $fixtures);

        $expected = [];
        $actual = [];

        foreach ($fixtures as $key => $fixture) {
            [$method, $uri] = explode(' ', $key, 2);

            foreach (self::CASES as $case) {
                $expected[$key][$case] = $this->expectedOutcome($method, $fixture, $case);
                $actual[$key][$case] = $this->runCase($method, $uri, $fixture, $case);
            }
        }

        $this->assertSame($expected, $actual, 'A route did not answer an actor as the gate decides.');
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function expectedOutcome(string $method, array $fixture, string $case): string
    {
        $write = $fixture['kind'] === 'write';

        if (! $this->allows($case, $fixture['kind'] !== 'read')) {
            return $this->denial($case, $fixture, $method).', state unchanged';
        }

        $outcome = [(string) $fixture['status']];

        if ($write) {
            $outcome[] = 'state changed';
            $outcome[] = 'effect ok';
        }

        if ($method !== 'HEAD' && $fixture['content'] !== null) {
            $outcome[] = 'content ok';
        }

        return implode(', ', $outcome);
    }

    /**
     * One request of one actor. Every write and every denied request compares
     * a snapshot of every table on both connections before and after the
     * request. An allowed read has no snapshot.
     *
     * The outcome also names the matched route when the request did not
     * reach the route of its key.
     *
     * @param  array<string, mixed>  $fixture
     */
    private function runCase(string $method, string $uri, array $fixture, string $case): string
    {
        $this->beginCase($case);

        $write = $fixture['kind'] === 'write';
        $allowed = $this->allows($case, $fixture['kind'] !== 'read');

        if (isset($fixture['arrange'])) {
            ($fixture['arrange'])();
        }

        $probe = $write ? ($fixture['probe'])() : null;
        $before = $write || ! $allowed ? $this->databaseState() : [];

        $response = $this->send($method, $fixture);

        $after = $write || ! $allowed ? $this->databaseState() : [];
        $outcome = [(string) $response->getStatusCode()];

        $route = $response->baseRequest?->route();
        $matched = $route instanceof Route ? $route->uri() : 'no route';

        if ($matched !== $uri) {
            $outcome[] = 'matched '.$matched;
        }

        if ($response->getStatusCode() >= 500 && $response->exception !== null) {
            $outcome[] = 'exception '.get_class($response->exception).': '.Str::limit($response->exception->getMessage(), 300);
        }

        if (! $allowed) {
            // The JSON routes name the denial; the page route answers a plain HTTP error, and HEAD has no body.
            if (! $fixture['html'] && $method !== 'HEAD') {
                $error = $response->json('error');
                $outcome[0] .= ' '.(is_string($error) ? $error : 'without an error code');
            }

            $changed = $this->changedTables($before, $after);
            $outcome[] = $changed === [] ? 'state unchanged' : 'state changed in '.implode(' ', $changed);

            return implode(', ', $outcome);
        }

        if ($write) {
            $outcome[] = $after === $before ? 'state unchanged' : 'state changed';
            $problem = ($fixture['effect'])($probe);
            $outcome[] = $problem === null ? 'effect ok' : 'effect: '.$problem;
        }

        if ($method !== 'HEAD' && $fixture['content'] !== null) {
            $problem = ($fixture['content'])($response);
            $outcome[] = $problem === null ? 'content ok' : 'content: '.$problem;
        }

        return implode(', ', $outcome);
    }

    /**
     * The status of a denied request, with the error code of a
     * JSON route (a HEAD answer has no body): a guest gets 401 `unauthenticated`, a signed-in user
     * without the view ability 403 `forbidden`, and a viewer's write 403
     * `forbidden_action`.
     *
     * @param  array<string, mixed>  $fixture
     */
    private function denial(string $case, array $fixture, string $method): string
    {
        [$status, $error] = match ($case) {
            self::DEFAULT_GUEST, self::DENIED_GUEST => ['401', 'unauthenticated'],
            self::VIEWER => ['403', 'forbidden_action'],
            default => ['403', 'forbidden'],
        };

        return $fixture['html'] || $method === 'HEAD' ? $status : $status.' '.$error;
    }

    /**
     * A read needs the view ability. A write needs the view and the operate
     * ability. Without a callback, both are for the local environment only.
     */
    private function allows(string $case, bool $write = false): bool
    {
        if ($case === self::VIEWER) {
            return ! $write;
        }

        return $case === self::ALLOWED || $case === self::LOCAL_GUEST;
    }

    /**
     * The reset between two cases, and the actor of the next case.
     */
    private function beginCase(string $case): void
    {
        self::resetPackageState();
        $this->app['auth']->forgetGuards();
        $this->app['env'] = $this->environmentName;

        $this->assertNull(Orrery::$authUsing, "Case '{$case}': the gate callback of the previous case survived the reset.");
        $this->assertNull(Orrery::$operateUsing, "Case '{$case}': the operate callback of the previous case survived the reset.");
        $this->assertFalse($this->app->environment('local'), "Case '{$case}': the environment is still local.");
        $this->assertTrue($this->app['auth']->guard()->guest(), "Case '{$case}': the user of the previous case is still signed in.");

        switch ($case) {
            case self::ALLOWED:
                $this->registerGate();
                $this->actingAs($this->user(GateProvider::ALLOWED_EMAIL));
                break;

            case self::DEFAULT_USER:
                $this->actingAs($this->user(GateProvider::ALLOWED_EMAIL));
                break;

            case self::DEFAULT_GUEST:
                break;

            case self::DENIED_USER:
                $this->registerGate();
                $this->actingAs($this->user(GateProvider::DENIED_EMAIL));
                break;

            case self::DENIED_GUEST:
                $this->registerGate();
                break;

            case self::VIEWER:
                $this->registerGate();
                $this->actingAs($this->user(GateProvider::VIEWER_EMAIL));
                break;

            case self::LOCAL_GUEST:
                $this->app['env'] = 'local';
                break;

            default:
                $this->fail("Unknown case '{$case}'.");
        }
    }

    /**
     * Registers the application's provider again, so its boot() sets the
     * static callback after the reset.
     */
    private function registerGate(): void
    {
        $this->app->register(GateProvider::class, force: true);

        $this->assertNotNull(Orrery::$authUsing, 'GateProvider did not set the gate callback.');
        $this->assertNotNull(Orrery::$operateUsing, 'GateProvider did not set the operate callback.');
    }

    private function user(string $email): User
    {
        return (new User)->forceFill(['email' => $email]);
    }

    /**
     * Sends the request as the SPA does: with the session's CSRF token in the
     * X-CSRF-TOKEN header (resources/js/app.js). Laravel checks the token
     * outside the testing environment, so the local case needs it.
     *
     * @param  array<string, mixed>  $fixture
     */
    private function send(string $method, array $fixture): TestResponse
    {
        $uri = ($fixture['uri'])();

        $this->withSession(['_token' => self::CSRF_TOKEN]);

        if ($fixture['html']) {
            return $this->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => 'text/html', 'HTTP_X_CSRF_TOKEN' => self::CSRF_TOKEN]);
        }

        $body = $fixture['body'] instanceof Closure ? ($fixture['body'])() : $fixture['body'];

        return $this->json($method, $uri, $body, ['X-CSRF-TOKEN' => self::CSRF_TOKEN]);
    }

    /**
     * The state of the testbench connection and, in separate mode, of the
     * mirror connection, read at the moment of the call.
     *
     * - "<connection> (tables)": the current physical tables, each with its
     *   columns in order. A created, dropped or altered table changes it.
     * - "<connection>.<physical table>": the sorted rows of that table, one
     *   string per row with the exact value of every column.
     *
     * @return array<string, list<string>>
     */
    private function databaseState(): array
    {
        $state = [];

        foreach ($this->stateConnections() as $name => $connection) {
            $inventory = $this->inventory($connection);

            $state[$name.' (tables)'] = array_map(
                static fn (string $table, array $columns): string => $table.': '.implode(', ', $columns),
                array_keys($inventory),
                $inventory,
            );

            foreach ($this->tableRows($connection, $inventory) as $table => $rows) {
                sort($rows, SORT_STRING);
                $state[$name.'.'.$table] = $rows;
            }
        }

        ksort($state, SORT_STRING);

        return $state;
    }

    /**
     * The current physical tables of one connection, each with its column
     * names in order, read from the engine's catalog in one query. The
     * filters equal those of Support\PhysicalTables.
     *
     * @return array<string, list<string>>
     */
    private function inventory(Connection $connection): array
    {
        $sql = match ($connection->getDriverName()) {
            'sqlite' => "SELECT m.name, p.name FROM sqlite_master AS m JOIN pragma_table_info(m.name) AS p
                WHERE m.type = 'table' AND m.name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY m.name, p.cid",
            'mysql', 'mariadb' => "SELECT c.TABLE_NAME, c.COLUMN_NAME FROM information_schema.COLUMNS AS c
                JOIN information_schema.TABLES AS t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
                WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE' ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION",
            'pgsql' => "SELECT c.relname, a.attname FROM pg_catalog.pg_class AS c
                JOIN pg_catalog.pg_namespace AS n ON n.oid = c.relnamespace
                LEFT JOIN pg_catalog.pg_attribute AS a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
                WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') ORDER BY c.relname, a.attnum",
            default => throw new \LogicException('Unsupported driver '.$connection->getDriverName()),
        };

        $inventory = [];

        foreach ($connection->getPdo()->query($sql)->fetchAll(PDO::FETCH_NUM) as [$table, $column]) {
            $inventory[(string) $table] ??= [];

            if ($column !== null) {
                $inventory[(string) $table][] = (string) $column;
            }
        }

        return $inventory;
    }

    /**
     * The testbench connection and, in separate mode, the mirror connection.
     *
     * @return array<string, Connection>
     */
    private function stateConnections(): array
    {
        $connections = [TestEnvironment::PRIMARY => $this->app['db']->connection(TestEnvironment::PRIMARY)];
        $mirror = $this->mirror();
        $connections[$mirror->getName()] = $mirror;

        return $connections;
    }

    /**
     * Every row of every table in the inventory of one connection, through
     * its PDO. Each row becomes one string with the exact value of every
     * column:
     *
     * - SQLite: every column with a type tag (sqliteRow()): text and blobs
     *   as tagged hex of all bytes, NULL, integers and reals through
     *   quote(). So the string keeps the storage class and every byte.
     * - Postgres: the row's text form (x::text). Its fields print text with
     *   quotes, NULL as an empty field, float8 in the shortest exact form,
     *   and bytea as hex.
     * - MySQL: JSON_ARRAY() of every column. JSON keeps NULL apart from '',
     *   and it prints binary values as base64.
     *
     * One UNION ALL query reads every table of the connection, so a
     * snapshot costs two round trips per connection on a server: the
     * inventory and the rows.
     *
     * @param  array<string, list<string>>  $inventory
     * @return array<string, list<string>>
     */
    private function tableRows(Connection $connection, array $inventory): array
    {
        $rows = array_fill_keys(array_keys($inventory), []);

        if ($inventory === []) {
            return $rows;
        }

        $pdo = $connection->getPdo();
        $driver = $connection->getDriverName();
        $selects = [];

        foreach ($inventory as $table => $columns) {
            $name = $pdo->quote($table);

            $selects[] = match ($driver) {
                'sqlite' => 'SELECT '.$name.' AS t, '.$this->sqliteRow($columns).' AS r FROM "'.$table.'"',
                'pgsql' => 'SELECT '.$name.'::text AS t, x::text AS r FROM "'.$table.'" AS x',
                // MariaDB: the hex of each value's bytes (its JSON_ARRAY() keeps the column
                // collations, which a union cannot mix, and loses the bytes of a blob); NULL stays null.
                'mariadb' => 'SELECT CONVERT('.$name.' USING utf8mb4) COLLATE utf8mb4_bin AS t, CONVERT(JSON_ARRAY('.implode(', ', array_map(static fn (string $column): string => 'HEX(CAST(`'.$column.'` AS BINARY))', $columns)).') USING utf8mb4) COLLATE utf8mb4_bin AS r FROM `'.$table.'`',
                default => 'SELECT '.$name.' AS t, JSON_ARRAY('.implode(', ', array_map(static fn (string $column): string => '`'.$column.'`', $columns)).') AS r FROM `'.$table.'`',
            };
        }

        foreach ($pdo->query(implode(' UNION ALL ', $selects))->fetchAll(PDO::FETCH_NUM) as [$table, $row]) {
            $rows[(string) $table][] = (string) $row;
        }

        return $rows;
    }

    /**
     * The SQLite expression of one row: one value per column, joined with
     * commas.
     *
     * - A text value becomes "t:" and the hex of all its bytes. quote() is
     *   not used for text, because it stops at the first NUL byte:
     *   'a\0b' and 'a\0c' both quote to 'a'.
     * - A blob becomes "b:" and the hex of its bytes.
     * - NULL, an integer and a real keep quote(), which prints NULL, the
     *   integer, or a real that reads back to the same double. hex() of a
     *   real would go through a rounded text form.
     *
     * Hex digits, the tags and quote()'s numbers contain no comma, so the
     * string is unambiguous.
     *
     * @param  list<string>  $columns
     */
    private function sqliteRow(array $columns): string
    {
        return implode(" || ',' || ", array_map(static fn (string $column): string => sprintf(
            "CASE typeof(%1\$s) WHEN 'text' THEN 't:' || hex(%1\$s) WHEN 'blob' THEN 'b:' || hex(%1\$s) ELSE quote(%1\$s) END",
            '"'.$column.'"',
        ), $columns));
    }

    /**
     * The sentinel rows of a negative control: one row that an update
     * changes, and eight rows that the deletes remove one at a time. A binary
     * data set first creates its table with a binary value column.
     */
    private function seedSentinels(Connection $connection, string $table, string $change): void
    {
        if ($change === 'binary') {
            $connection->getSchemaBuilder()->create($table, static function (Blueprint $blueprint): void {
                $blueprint->string('key')->primary();
                $blueprint->binary('value')->nullable();
            });

            $connection->table($table)->insert(['key' => 'authz-sentinel', 'value' => $connection->raw($this->byteLiteral($connection, '80'))]);

            return;
        }

        if ($change === 'nul text') {
            $connection->table($table)->insert(array_merge(
                $this->sentinelRow($table, 'authz-sentinel', ''),
                ['value' => $connection->raw($this->textLiteral($connection, '610062'))],
            ));

            return;
        }

        $connection->table($table)->insert($this->sentinelRow($table, 'authz-sentinel', 'before'));

        for ($number = 1; $number <= 8; $number++) {
            $connection->table($table)->insert($this->sentinelRow($table, sprintf('authz-sentinel-%02d', $number), 'before'));
        }
    }

    /**
     * One change of a negative control. Each call changes the table, so
     * every request of a case sequence makes its own change. The binary
     * change switches between the bytes 0x81 (odd calls) and 0x80. The nul
     * text change switches between the texts 'a\0c' (odd calls) and 'a\0b'.
     */
    private function changeSentinel(Connection $connection, string $table, string $change, int $call): void
    {
        match ($change) {
            'insert' => $connection->table($table)->insert($this->sentinelRow($table, 'authz-sentinel-new-'.Str::random(16), 'inserted')),
            'update' => $connection->table($table)->where('key', 'authz-sentinel')->update(['value' => Str::random(16)]),
            'delete' => $connection->table($table)
                ->where('key', (string) $connection->table($table)->where('key', 'like', 'authz-sentinel-0%')->orderBy('key')->value('key'))
                ->delete(),
            'binary' => $connection->table($table)
                ->where('key', 'authz-sentinel')
                ->update(['value' => $connection->raw($this->byteLiteral($connection, $call % 2 === 1 ? '81' : '80'))]),
            'nul text' => $connection->table($table)
                ->where('key', 'authz-sentinel')
                ->update(['value' => $connection->raw($this->textLiteral($connection, $call % 2 === 1 ? '610063' : '610062'))]),
        };
    }

    /**
     * A one-byte binary literal of the connection's SQL dialect.
     */
    private function byteLiteral(Connection $connection, string $hex): string
    {
        return $connection->getDriverName() === 'pgsql' ? "'\\x{$hex}'::bytea" : "X'{$hex}'";
    }

    /**
     * A text (not a blob) with the given bytes, in the connection's SQL
     * dialect.
     */
    private function textLiteral(Connection $connection, string $hex): string
    {
        return match ($connection->getDriverName()) {
            'sqlite' => "CAST(X'{$hex}' AS TEXT)",
            'mysql', 'mariadb' => "CONVERT(X'{$hex}' USING utf8mb4)",
            default => "convert_from('\\x{$hex}'::bytea, 'UTF8')",
        };
    }

    /**
     * The storage type and the hex bytes of the sentinel value: SQLite
     * typeof() (text, blob, …), MySQL CHARSET() (a character set for text,
     * "binary" for bytes).
     *
     * @return array{0: string, 1: string}
     */
    private function storedValue(Connection $connection, string $table): array
    {
        $value = $connection->getQueryGrammar()->wrap('value');
        $type = $connection->getDriverName() === 'sqlite' ? "typeof({$value})" : "CHARSET({$value})";

        $row = $connection->table($table)
            ->where('key', 'authz-sentinel')
            ->selectRaw("{$type} AS kind, hex({$value}) AS bytes")
            ->first();

        return [(string) $row?->kind, strtoupper((string) $row?->bytes)];
    }

    /**
     * Writes both nul texts into every target and reads each one back: the
     * storage type must be text, and the bytes must be exactly 61 00 62 or
     * 61 00 63. The target ends with 'a\0b', as seeded.
     *
     * @param  list<array{0: Connection, 1: string}>  $targets
     */
    private function assertNulTextIsStored(array $targets): void
    {
        foreach ($targets as [$connection, $table]) {
            $kind = $connection->getDriverName() === 'sqlite' ? 'text' : 'utf8mb4';

            foreach (['610063', '610062'] as $hex) {
                $connection->table($table)->where('key', 'authz-sentinel')->update(['value' => $connection->raw($this->textLiteral($connection, $hex))]);

                $this->assertSame([$kind, $hex], $this->storedValue($connection, $table), "{$table} does not hold the text bytes {$hex}.");
            }
        }
    }

    /**
     * Postgres text cannot contain a NUL byte, so no request can store
     * 'a\0b'. This requires the refusal (SQLSTATE 22021) on every target, so
     * a Postgres that accepts the value fails here and needs the data set.
     *
     * @param  list<array{0: Connection, 1: string}>  $targets
     */
    private function assertPostgresRefusesNulText(array $targets): void
    {
        foreach ($targets as [$connection, $table]) {
            try {
                $this->seedSentinels($connection, $table, 'nul text');
            } catch (QueryException $exception) {
                $this->assertSame('22021', $exception->errorInfo[0] ?? null, "Postgres refused the text in {$table} for another reason: ".$exception->getMessage());

                continue;
            }

            $this->fail("Postgres stored a text with a NUL byte in {$table}. The nul text data set now needs its denied-read check on Postgres.");
        }
    }

    /**
     * @return array<string, int|string>
     */
    private function sentinelRow(string $table, string $key, string $value): array
    {
        // Laravel's cache table also needs an expiration.
        return $table === 'cache'
            ? ['key' => $key, 'value' => $value, 'expiration' => 0]
            : ['key' => $key, 'value' => $value];
    }

    /**
     * @param  array<string, list<string>>  $before
     * @param  array<string, list<string>>  $after
     * @return list<string>
     */
    private function changedTables(array $before, array $after): array
    {
        $tables = array_unique([...array_keys($before), ...array_keys($after)]);
        sort($tables, SORT_STRING);

        return array_values(array_filter($tables, static fn (string $table): bool => ($before[$table] ?? null) !== ($after[$table] ?? null)));
    }

    /**
     * The arrange step of a monitoring write: the tag in its start state, and
     * an empty default queue, so a later drain runs only what the request
     * queued. A job that a wrongly allowed earlier case queued is removed
     * here. That case already reports its state change.
     */
    private function arrangeTag(string $tag, bool $monitored): void
    {
        $tags = app(MonitoringVersion::class);
        $monitored ? $tags->monitor($tag) : $tags->stopMonitoring($tag);

        $this->app['db']->connection(TestEnvironment::PRIMARY)->table('jobs')->where('queue', 'default')->delete();
    }

    /**
     * The effect of a monitoring write is a tag change. The tags API changes
     * the tag synchronously; the check still drains the default
     * queue first, so a write that queued a job instead is seen too, then it
     * reads the monitored tags.
     */
    private function tagChange(string $tag, bool $monitored): ?string
    {
        for ($run = 0; $run < 3 && Queue::connection('database')->size('default') > 0; $run++) {
            $this->work();
        }

        if (Queue::connection('database')->size('default') > 0) {
            return 'the worker did not drain the default queue';
        }

        if (in_array($tag, $this->monitoredTags(), true) !== $monitored) {
            return "'{$tag}' is ".($monitored ? 'not ' : 'still ').'monitored';
        }

        return null;
    }

    /**
     * The queued retries of a failed job. The retry service publishes the
     * failed payload again under the same uuid, with its orreryRetry token,
     * so a retry is a queued job with the failed job's uuid
     * and an orreryRetry key.
     */
    private function queuedRetriesOf(string $uuid): int
    {
        return $this->app['db']->connection(TestEnvironment::PRIMARY)->table('jobs')
            ->pluck('payload')
            ->filter(static function (mixed $payload) use ($uuid): bool {
                $decoded = json_decode((string) $payload, true);

                return is_array($decoded) && strtolower((string) ($decoded['uuid'] ?? '')) === $uuid && isset($decoded['orreryRetry']);
            })
            ->count();
    }

    /**
     * A retry of an earlier case left the job pending: the real queue:work
     * command fails it again, so the next case retries a current failure.
     * It runs before the state snapshot of the case.
     */
    private function failAgainIfRetried(string $uuid): void
    {
        // Another fixture job's retry can wait ahead of it on the same queue.
        for ($run = 0; $run < 3 && $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->value('status') !== 'failed'; $run++) {
            $status = Artisan::call('queue:work', [
                'connection' => 'database',
                '--queue' => self::WORK_QUEUE,
                '--once' => true,
                '--tries' => 1,
                '--sleep' => 0, '--memory' => 4096,
            ]);

            $this->assertSame(0, $status, 'queue:work failed: '.Artisan::output());
        }

        $this->assertSame('failed', $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->value('status'), "The retried fixture job {$uuid} did not fail again.");
    }

    /**
     * The failure-group window of the fixtures: the last day and the next hour.
     */
    private function failureWindow(): string
    {
        $now = microtime(true);

        return http_build_query(['from' => sprintf('%.6F', $now - 86400), 'to' => sprintf('%.6F', $now + 3600)]);
    }

    /**
     * The failure-group key of a mirror row.
     */
    private function failureKey(string $uuid): string
    {
        $row = $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->first();
        $this->assertNotNull($row, "The mirror row of {$uuid} is missing.");

        return GroupKey::of($row->exception_class, $row->exception_message, (string) $row->name);
    }

    /**
     * The forget target of the next case: the current one while it is still
     * failed, else a new job that the real queue:work command fails on its
     * own queue. It runs before the state snapshot of the case.
     */
    private function failForgetTarget(): void
    {
        if ($this->ids['forget'] !== '' && $this->mirror()->table('orrery_jobs')->where('uuid', $this->ids['forget'])->value('status') === 'failed') {
            return;
        }

        AuthorizationJob::dispatch(true)->onConnection('database')->onQueue(self::FORGET_QUEUE);

        $status = Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => self::FORGET_QUEUE,
            '--once' => true,
            '--tries' => 1,
            '--sleep' => 0, '--memory' => 4096,
        ]);

        $this->assertSame(0, $status, 'queue:work failed: '.Artisan::output());

        $uuid = $this->mirror()->table('orrery_jobs')->where('queue', self::FORGET_QUEUE)->where('status', 'failed')->value('uuid');
        $this->assertIsString($uuid, 'The forget target did not fail.');
        $this->ids['forget'] = $uuid;
    }

    /**
     * The monitored tags, read from the mirror.
     *
     * @return list<string>
     */
    private function monitoredTags(): array
    {
        return array_map('strval', $this->mirror()->table('orrery_monitored_tags')->orderBy('tag')->pluck('tag')->all());
    }

    private function contains(mixed $rows, string $field, string $value): bool
    {
        if (! is_array($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row[$field] ?? '') === $value) {
                return true;
            }
        }

        return false;
    }

    private function problem(bool $ok, string $message): ?string
    {
        return $ok ? null : $message;
    }
}
