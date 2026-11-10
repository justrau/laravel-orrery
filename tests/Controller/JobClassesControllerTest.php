<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use ErrorException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\ControllerTest;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * GET /api/classes and GET /api/classes/series: the job
 * classes of the `job` scope of orrery_samples, keyed by the job name across
 * queues, read through SampleCoverage (the read rule of the samples).
 */
final class JobClassesControllerTest extends ControllerTest
{
    /** The current minute: a minute boundary. */
    private const M = 1_790_000_040;

    /** The request time: 12.5 s into the current minute. */
    private const NOW = self::M + 12.5;

    private const ALPHA = 'App\\Jobs\\Alpha';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The trim horizons reach past every window of these tests.
        foreach (['completed', 'silenced', 'failed', 'unknown'] as $key) {
            $app['config']->set("orrery.trim.{$key}", 1440);
        }

        if ($this->name() === 'test_an_uninstrumented_connection_makes_the_arrivals_unknown') {
            $app['config']->set('orrery.swap_connector', false);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: one entry per queue instead of per class (Alpha runs on two queues), the stored
     * minutes only or the scanned current minute only, runtime_avg_ms as the mean of the
     * per-minute averages (233.3 here) instead of Σ runtime_ms_sum ÷ Σ runtime_count, a runtime
     * of 0 for a class without a timed completion, a null `pushed` for a known minute whose job
     * row has no arrivals, and an order other than completed + failed desc, then the name.
     * Still passes: a reader that takes retention_from from the oldest `job` row instead of the
     * oldest `total` row (both are M - 240 here).
     */
    public function test_each_class_sums_its_counts_across_queues_over_the_window(): void
    {
        $this->through(self::M);

        foreach ([self::M - 240, self::M - 180, self::M - 120, self::M - 60] as $minute) {
            $this->total($minute, 0);
        }

        $this->total(self::M - 120, 4);
        $this->job(self::ALPHA, self::M - 120, completed: 3, failed: 1, runtimeSum: 300, runtimeCount: 3, pushed: 4);
        // A job row without arrivals in a minute whose `total` row has a number: 0 arrivals.
        $this->job(self::ALPHA, self::M - 60, completed: 1, runtimeSum: 500, runtimeCount: 1);
        $this->job('App\\Jobs\\Beta', self::M - 240, failed: 3);
        $this->job('App\\Jobs\\Aardvark', self::M - 180, completed: 2, runtimeSum: 20, runtimeCount: 2);
        // The current minute is scanned: Alpha on two queues.
        $this->row(self::ALPHA, 'a', 'completed', ['pushed_at' => self::M + 0.5, 'finished_at' => self::M + 1.0, 'runtime_ms' => 50]);
        $this->row(self::ALPHA, 'b', 'completed', ['pushed_at' => self::M + 0.5, 'finished_at' => self::M + 2.0, 'runtime_ms' => 150]);

        $response = $this->getJson('/orrery/api/classes?minutes=5')->assertOk();

        self::assertSame(self::NOW, $response->json('as_of'));
        self::assertSame(self::M - 240, $response->json('from'), 'five minutes: the current one and four before it');
        self::assertSame(self::NOW, $response->json('to'));
        self::assertSame(self::kinds((float) (self::M - 240)), $response->json('retention_from'), 'the oldest `total` row, per kind');
        self::assertFalse($response->json('truncated'));
        self::assertNull($response->json('unknown_minutes'));

        $classes = $response->json('classes');
        self::assertSame([self::ALPHA, 'App\\Jobs\\Beta', 'App\\Jobs\\Aardvark'], array_column($classes, 'name'));
        self::assertSame(
            ['name' => self::ALPHA, 'completed' => 6, 'failed' => 1, 'pushed' => 6, 'complete' => true],
            array_diff_key($classes[0], ['runtime_avg_ms' => true]),
        );
        self::assertEqualsWithDelta(1000 / 6, $classes[0]['runtime_avg_ms'], 1e-9);
        self::assertSame(['name' => 'App\\Jobs\\Beta', 'completed' => 0, 'failed' => 3, 'pushed' => 0, 'runtime_avg_ms' => null, 'complete' => true], $classes[1]);
        self::assertSame(['name' => 'App\\Jobs\\Aardvark', 'completed' => 2, 'failed' => 0, 'pushed' => 0, 'runtime_avg_ms' => 10.0, 'complete' => true], $classes[2]);
    }

    /**
     * Rejects: arrivals reported as a known number while a database queue connection is uninstrumented:
     * such a connection mirrors only outcomes, so a job that waited longer than the recount window
     * is never counted. `pushed` is null in both endpoints; the outcome counts stay.
     * Still passes: a rule that nulls `pushed` for every class, also of an instrumented connection beside it.
     */
    public function test_an_uninstrumented_connection_makes_the_arrivals_unknown(): void
    {
        $this->through(self::M);

        foreach ([self::M - 240, self::M - 180, self::M - 120, self::M - 60] as $minute) {
            $this->total($minute, 0);
        }

        $this->job(self::ALPHA, self::M - 120, completed: 3, pushed: 4);

        $classes = $this->getJson('/orrery/api/classes?minutes=5')->assertOk()->json('classes');
        self::assertSame([['name' => self::ALPHA, 'completed' => 3, 'failed' => 0, 'pushed' => null, 'runtime_avg_ms' => null, 'complete' => true]], $classes);

        $buckets = $this->getJson('/orrery/api/classes/series?minutes=5&name='.rawurlencode(self::ALPHA))->assertOk()->json('buckets');
        self::assertNotSame([], $buckets);
        self::assertSame([null], array_values(array_unique(array_column($buckets, 'pushed'), SORT_REGULAR)));
        self::assertSame(3, array_sum(array_column($buckets, 'completed')));
    }

    /**
     * Rejects: a sum that counts a job row of a minute without a `total` row (Alpha's 5 at
     * M - 180), `complete: true` while a minute is unknown, an unknown minute counted as 0
     * arrivals (a non-null `pushed`), the unknown minutes not named, and a class listed from
     * rows in unknown minutes only (Gamma).
     */
    public function test_unknown_minutes_make_the_sums_a_lower_bound(): void
    {
        $this->through(self::M);
        $this->total(self::M - 240, 2);
        $this->total(self::M - 60, 0);
        $this->job(self::ALPHA, self::M - 240, completed: 2, pushed: 2);
        // M - 180 and M - 120 have no `total` row: unknown.
        $this->job(self::ALPHA, self::M - 180, completed: 5, pushed: 5);
        $this->job('App\\Jobs\\Gamma', self::M - 120, completed: 9);

        $response = $this->getJson('/orrery/api/classes?minutes=5')->assertOk();

        self::assertSame(
            [['name' => self::ALPHA, 'completed' => 2, 'failed' => 0, 'pushed' => null, 'runtime_avg_ms' => null, 'complete' => false]],
            $response->json('classes'),
        );
        self::assertSame(['count' => 2, 'first' => self::M - 180, 'last' => self::M - 120], $response->json('unknown_minutes'));
    }

    /**
     * Rejects: unknown minutes taken from the completions only (a short `failed` override leaves
     * the failures of M - 180 and M - 120 unknown while their completions are scanned), a scan
     * horizon from the global `trim.failed` instead of the shortest override (the
     * failures would read 0, a covered undercount), a series bucket dropped or zero-filled when
     * only one kind is unknown, and a single retention_from instead of each kind's own scan start
     * (the completions before the failures' horizon stay visible).
     */
    public function test_a_short_failed_override_leaves_the_older_failures_unknown(): void
    {
        $this->app['config']->set('orrery.trim_overrides', [['queue' => 'brief', 'failed' => 2]]);
        $this->through(self::M - 180);
        $this->row(self::ALPHA, 'a', 'completed', ['pushed_at' => self::M - 179.0, 'finished_at' => self::M - 178.0, 'runtime_ms' => 10]);
        $this->row(self::ALPHA, 'a', 'failed', ['pushed_at' => self::M - 119.0, 'failed_at' => self::M - 118.0, 'finished_at' => self::M - 118.0]);
        $this->row(self::ALPHA, 'a', 'failed', ['pushed_at' => self::M - 59.0, 'failed_at' => self::M - 58.0, 'finished_at' => self::M - 58.0]);

        $classes = $this->getJson('/orrery/api/classes?minutes=5')->assertOk();

        self::assertSame(
            [['name' => self::ALPHA, 'completed' => 1, 'failed' => 1, 'pushed' => null, 'runtime_avg_ms' => 10.0, 'complete' => false]],
            $classes->json('classes'),
        );
        self::assertSame(['count' => 3, 'first' => self::M - 240, 'last' => self::M - 120], $classes->json('unknown_minutes'));
        // Each kind from its own scan start: the completions from M, the failures and arrivals from NOW - 2 minutes.
        $retention = ['completed' => (float) (self::M - 180), 'failed' => self::NOW - 120, 'pushed' => self::NOW - 120];
        self::assertSame($retention, $classes->json('retention_from'));

        $series = $this->getJson('/orrery/api/classes/series?minutes=5&name='.rawurlencode(self::ALPHA))->assertOk();

        self::assertSame([
            ['at' => self::M - 180, 'completed' => 1, 'failed' => null, 'pushed' => null, 'runtime_avg_ms' => 10.0, 'partial' => false],
            ['at' => self::M - 120, 'completed' => 0, 'failed' => null, 'pushed' => null, 'runtime_avg_ms' => null, 'partial' => false],
            ['at' => self::M - 60, 'completed' => 0, 'failed' => 1, 'pushed' => 1, 'runtime_avg_ms' => null, 'partial' => false],
            ['at' => self::M, 'completed' => 0, 'failed' => 0, 'pushed' => 0, 'runtime_avg_ms' => null, 'partial' => true],
        ], $series->json('buckets'));
        self::assertSame($retention, $series->json('retention_from'));
    }

    /**
     * Rejects: a reader that reads a minute counted before schema step 7 (its `total` row has a
     * null `pushed`) as 0 arrivals, or that marks the outcome sums incomplete because only the
     * arrivals are unknown.
     */
    public function test_a_minute_without_an_arrivals_count_makes_pushed_null_only(): void
    {
        $this->through(self::M);
        $this->total(self::M - 240, 1);
        $this->total(self::M - 180, null);
        $this->total(self::M - 120, 0);
        $this->total(self::M - 60, 0);
        $this->job(self::ALPHA, self::M - 240, completed: 1, pushed: 1);
        $this->job(self::ALPHA, self::M - 180, completed: 4);

        $response = $this->getJson('/orrery/api/classes?minutes=5')->assertOk();

        self::assertSame(
            [['name' => self::ALPHA, 'completed' => 5, 'failed' => 0, 'pushed' => null, 'runtime_avg_ms' => null, 'complete' => true]],
            $response->json('classes'),
        );
        self::assertNull($response->json('unknown_minutes'));
    }

    /**
     * Rejects: no cap (501 entries), a cap that keeps the first 500 by name instead of the
     * largest by completed + failed, and `truncated` false at the cap.
     * Still passes: a cap of 500 by completed alone (the failures here do not change the order).
     */
    public function test_at_most_500_classes_by_completed_plus_failed(): void
    {
        $this->through(self::M);

        foreach ([self::M - 240, self::M - 180, self::M - 120, self::M - 60] as $minute) {
            $this->total($minute, 0);
        }

        $rows = [];

        for ($i = 0; $i < 501; $i++) {
            // Class 500 has the most outcomes; class 000 the fewest (the name order is the opposite).
            $rows[] = $this->jobRow(sprintf('App\\Jobs\\C%03d', $i), self::M - 120, 100 + $i, 1, 0, 0, null);
        }

        foreach (array_chunk($rows, 50) as $chunk) {
            $this->mirror()->table(SampleCounts::TABLE)->insert($chunk);
        }

        $response = $this->getJson('/orrery/api/classes?minutes=5')->assertOk();
        $names = array_column($response->json('classes'), 'name');

        self::assertTrue($response->json('truncated'));
        self::assertCount(500, $names);
        self::assertSame('App\\Jobs\\C500', $names[0]);
        self::assertSame('App\\Jobs\\C001', $names[499]);
        self::assertNotContains('App\\Jobs\\C000', $names);
    }

    /**
     * Rejects: a window other than the current minute and minutes - 1 before it, a default other
     * than 60, and a range check that accepts 4 or 61 minutes (the classes endpoint allows 5..60).
     */
    public function test_the_window_of_the_classes(): void
    {
        self::assertSame(self::M - 59 * 60, $this->getJson('/orrery/api/classes')->assertOk()->json('from'));
        self::assertSame(self::M - 59 * 60, $this->getJson('/orrery/api/classes?minutes=60')->assertOk()->json('from'));
        self::assertSame(self::M - 14 * 60, $this->getJson('/orrery/api/classes?minutes=15')->assertOk()->json('from'));
        self::assertSame([], $this->getJson('/orrery/api/classes?minutes=15')->json('classes'));
    }

    /**
     * Rejects: a parameter check that casts (`5abc`, `1e1`), clamps (4, 61) or ignores an array.
     */
    #[DataProvider('invalidClassMinutes')]
    public function test_an_invalid_minutes_value_of_the_classes_gives_422(string $query): void
    {
        $this->getJson('/orrery/api/classes?'.$query)
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_parameter')
            ->assertJsonPath('field', 'minutes');
    }

    /** @return array<string, array{string}> */
    public static function invalidClassMinutes(): array
    {
        return [
            'below' => ['minutes=4'],
            'above' => ['minutes=61'],
            'text' => ['minutes=abc'],
            'suffix' => ['minutes=5abc'],
            'exponent' => ['minutes=1e1'],
            'negative' => ['minutes=-5'],
            'array' => ['minutes[]=5'],
        ];
    }

    /**
     * Rejects: a series that folds case or trailing spaces (`App\Jobs\alpha` and `App\Jobs\Alpha `
     * are other classes; MySQL's collation folds both), one queue only (Alpha runs on two), a
     * zero-filled unknown minute (M - 180 is absent), a runtime of 0 for a minute without a timed
     * completion, and `partial` on a closed minute.
     */
    public function test_the_series_of_one_class_is_byte_exact_per_minute(): void
    {
        // M - 240 is stored, M - 180 has no `total` row (unknown), M - 120 and later are scanned.
        $this->through(self::M - 120);
        $this->total(self::M - 240, 3);
        $this->job(self::ALPHA, self::M - 240, completed: 2, failed: 1, runtimeSum: 100, runtimeCount: 2, pushed: 3);
        $this->job('App\\Jobs\\alpha', self::M - 240, completed: 7, pushed: 7);
        $this->job(self::ALPHA.' ', self::M - 240, completed: 8, pushed: 8);
        $this->job(self::ALPHA, self::M - 180, completed: 9, pushed: 9);

        foreach ([self::ALPHA, 'App\\Jobs\\alpha', self::ALPHA.' '] as $name) {
            $this->row($name, 'a', 'completed', ['pushed_at' => self::M - 119.0, 'finished_at' => self::M - 118.0, 'runtime_ms' => 40]);
        }

        $this->row(self::ALPHA, 'b', 'completed', ['pushed_at' => self::M - 119.0, 'finished_at' => self::M - 117.0, 'runtime_ms' => 80]);
        $this->row(self::ALPHA, 'b', 'failed', ['pushed_at' => self::M + 1.0, 'failed_at' => self::M + 2.0, 'finished_at' => self::M + 2.0]);

        $response = $this->getJson('/orrery/api/classes/series?minutes=5&name='.rawurlencode(self::ALPHA))->assertOk();

        self::assertSame(self::ALPHA, $response->json('name'));
        self::assertSame(self::NOW, $response->json('as_of'));
        self::assertSame(self::M - 240, $response->json('from'));
        self::assertSame(self::NOW, $response->json('to'));
        self::assertSame(60, $response->json('bucket_seconds'));
        self::assertSame(self::kinds((float) (self::M - 240)), $response->json('retention_from'));
        self::assertSame([
            ['at' => self::M - 240, 'completed' => 2, 'failed' => 1, 'pushed' => 3, 'runtime_avg_ms' => 50.0, 'partial' => false],
            ['at' => self::M - 120, 'completed' => 2, 'failed' => 0, 'pushed' => 2, 'runtime_avg_ms' => 60.0, 'partial' => false],
            ['at' => self::M - 60, 'completed' => 0, 'failed' => 0, 'pushed' => 0, 'runtime_avg_ms' => null, 'partial' => false],
            ['at' => self::M, 'completed' => 0, 'failed' => 1, 'pushed' => 1, 'runtime_avg_ms' => null, 'partial' => true],
        ], $response->json('buckets'));

        // The trailing-space class is its own series, also through a host's TrimStrings middleware.
        $spaced = $this->getJson('/orrery/api/classes/series?minutes=5&name='.rawurlencode(self::ALPHA.' '))->assertOk();
        self::assertSame(self::ALPHA.' ', $spaced->json('name'));
        self::assertSame([8, 1, 0, 0], array_column($spaced->json('buckets'), 'completed'));
    }

    /**
     * Rejects: a minute counted before schema step 7 read as 0 arrivals, and a bucket dropped
     * because only its arrivals are unknown.
     */
    public function test_a_series_minute_without_an_arrivals_count_has_a_null_pushed(): void
    {
        $this->through(self::M);
        $this->total(self::M - 120, null);
        $this->job(self::ALPHA, self::M - 120, completed: 3, runtimeSum: 30, runtimeCount: 3);

        $buckets = $this->getJson('/orrery/api/classes/series?minutes=5&name='.rawurlencode(self::ALPHA))->assertOk()->json('buckets');

        self::assertSame([
            ['at' => self::M - 120, 'completed' => 3, 'failed' => 0, 'pushed' => null, 'runtime_avg_ms' => 10.0, 'partial' => false],
            ['at' => self::M, 'completed' => 0, 'failed' => 0, 'pushed' => 0, 'runtime_avg_ms' => null, 'partial' => true],
        ], $buckets);
    }

    /**
     * Rejects: a missing, empty-array, over-long, invalid UTF-8 or NUL name sent to SQL (Postgres
     * fails on the last two), and a series range check of 5..60 (the series allows 5..1440).
     */
    #[DataProvider('invalidSeriesQueries')]
    public function test_an_invalid_series_request_gives_422(string $query, string $field): void
    {
        $this->getJson('/orrery/api/classes/series?'.$query)
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_parameter')
            ->assertJsonPath('field', $field);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidSeriesQueries(): array
    {
        return [
            'no name' => ['minutes=60', 'name'],
            'array name' => ['name[]=x', 'name'],
            'too long' => ['name='.str_repeat('a', 513), 'name'],
            'invalid UTF-8' => ['name=%FF', 'name'],
            'NUL' => ['name=a%00b', 'name'],
            'minutes above' => ['name=x&minutes=1441', 'minutes'],
            'minutes below' => ['name=x&minutes=4', 'minutes'],
        ];
    }

    /**
     * Rejects: a series limited to the classes range (60 minutes), and a 512-character name refused.
     */
    public function test_a_day_of_one_class_and_a_long_name_are_accepted(): void
    {
        $response = $this->getJson('/orrery/api/classes/series?minutes=1440&name='.rawurlencode(str_repeat('é', 512)))->assertOk();

        self::assertSame(self::M - 1439 * 60, $response->json('from'));
        self::assertSame(str_repeat('é', 512), $response->json('name'));
    }

    /**
     * Rejects: a failed read that reaches the host's exception handler as a 500, or that answers
     * 200 with empty or zero counts (unknown is never zero).
     */
    public function test_a_failed_read_answers_503(): void
    {
        Watcher::failOperationsForTesting(['http:classes']);

        $this->getJson('/orrery/api/classes')->assertStatus(503)->assertJsonPath('error', 'read_failed');
        $this->getJson('/orrery/api/classes/series?name=x')->assertStatus(503)->assertJsonPath('error', 'read_failed');
    }

    /**
     * Rejects: a statement timeout answered as another error (the spec's 503 `read_timeout`):
     * Postgres SQLSTATE 57014, MySQL error 3024 and MariaDB error 1969.
     */
    #[DataProvider('timeouts')]
    public function test_a_statement_timeout_answers_read_timeout(string $state, int $code): void
    {
        $session = $this->app->make(PackageSession::class)->mirror();
        $armed = true;
        $session->beforeExecuting(static function (string $sql) use ($session, $state, $code, &$armed): void {
            if ($armed && str_starts_with($sql, 'select') && str_contains($sql, SampleCounts::TABLE)) {
                $error = new PDOException('SQLSTATE['.$state.']: statement timeout');
                $error->errorInfo = [$state, $code, 'statement timeout'];

                throw new QueryException((string) $session->getName(), $sql, [], $error);
            }
        });

        $this->getJson('/orrery/api/classes')->assertStatus(503)->assertJsonPath('error', 'read_timeout');
        $this->getJson('/orrery/api/classes/series?name=x')->assertStatus(503)->assertJsonPath('error', 'read_timeout');
        $armed = false;
    }

    /** @return array<string, array{string, int}> */
    public static function timeouts(): array
    {
        return [
            'postgres' => ['57014', 7],
            'mysql' => ['HY000', 3024],
            'mariadb' => ['70100', 1969],
        ];
    }

    /**
     * Rejects: a retention_from that is not clamped to the samples' retention (the oldest
     * `total` row is 2 hours old, but `samples.retention_hours` is 1).
     */
    public function test_retention_from_is_never_before_the_samples_retention(): void
    {
        $this->app['config']->set('orrery.samples.retention_hours', 1);
        $this->through(self::M);
        $this->total(self::M - 7200, 0);

        $expected = self::kinds(self::NOW - 3600.0);
        self::assertSame($expected, $this->getJson('/orrery/api/classes?minutes=5')->assertOk()->json('retention_from'));
        self::assertSame($expected, $this->getJson('/orrery/api/classes/series?minutes=5&name=x')->assertOk()->json('retention_from'));
    }

    /**
     * Rejects: parse_str() over the raw query string, which warns past max_input_vars; Laravel
     * turns the warning into an ErrorException, a 500 that reaches the host's exception handler.
     * The package answers 422 `query_too_long` (RawQueryRoutesTest covers every raw-query route).
     *
     * Version-exact: symfony/http-foundation before its releases of 2026-10 (7.4.0, which the
     * floor laravel/framework ^13.30.0 allows) warns in HeaderUtils::parseQuery() itself, and
     * the framework's global HandleCors middleware reads Request::getQueryString(), which calls
     * it before any package code runs. There the test asserts that the 500 comes from the
     * framework, with no frame of the package.
     */
    public function test_a_query_string_past_max_input_vars_is_no_error(): void
    {
        Exceptions::fake();
        $request = Request::create('/orrery/api/classes/series', 'GET');
        $request->server->set('QUERY_STRING', str_repeat('a=1&', 1001).'name=x');

        $response = $this->app->make(Kernel::class)->handle($request);

        if (! self::symfonyParsesPastTheLimitQuietly()) {
            self::assertSame(500, $response->getStatusCode(), 'symfony/http-foundation warns past max_input_vars, so the framework fails first');
            $exception = $response->exception;
            self::assertInstanceOf(ErrorException::class, $exception);
            self::assertStringContainsString('max_input_vars', $exception->getMessage());
            self::assertStringEndsWith('HeaderUtils.php', $exception->getFile());
            $package = array_values(array_filter(
                array_map(static fn (array $frame): string => $frame['class'] ?? '', $exception->getTrace()),
                static fn (string $class): bool => str_starts_with($class, 'Justrau\\Orrery\\') && ! str_starts_with($class, 'Justrau\\Orrery\\Tests\\'),
            ));
            self::assertSame([], $package, 'the warning came from the package, not the framework');

            return;
        }

        self::assertNotSame(500, $response->getStatusCode());
        // The package refuses a query that PHP would cut at max_input_vars (RawQuery, QueryTooLong).
        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('query_too_long', json_decode((string) $response->getContent(), true)['error'] ?? null);
        Exceptions::assertNothingReported();
    }

    /**
     * Whether the installed symfony/http-foundation parses a query string past max_input_vars
     * without a warning (symfony/symfony 2490eb5, its releases of 2026-10).
     */
    private static function symfonyParsesPastTheLimitQuietly(): bool
    {
        $warned = false;
        $reporting = error_reporting(-1);
        // As Laravel's HandleExceptions: only a warning that the @ operator did not silence counts.
        set_error_handler(static function (int $level) use (&$warned): bool {
            $warned = $warned || (error_reporting() & $level) !== 0;

            return true;
        });

        try {
            HeaderUtils::parseQuery(str_repeat('a=1&', (int) ini_get('max_input_vars') + 1));
        } finally {
            restore_error_handler();
            error_reporting($reporting);
        }

        return ! $warned;
    }

    /**
     * @return array{completed: float, failed: float, pushed: float}
     */
    private static function kinds(float $value): array
    {
        return ['completed' => $value, 'failed' => $value, 'pushed' => $value];
    }

    private function through(int $minute): void
    {
        $this->mirror()->table('orrery_settings')->insert([
            ['key' => SampleCounts::COUNTED_THROUGH, 'value' => (string) $minute],
            ['key' => SampleCounts::COUNTED_AT, 'value' => Clock::toDecimal($minute + 5.0)],
        ]);
    }

    /**
     * A `total` row; a null $pushed is a minute counted before schema step 7. Its counts do
     * not matter to the classes.
     */
    private function total(int $minute, ?int $pushed): void
    {
        $this->mirror()->table(SampleCounts::TABLE)->upsert(
            [['scope' => 'total', 'series' => SampleCounts::series('total'), 'connection' => null, 'queue' => null, 'name' => null, 'minute' => $minute, 'completed' => 0, 'failed' => 0, 'runtime_ms_sum' => 0, 'runtime_count' => 0, 'pushed' => $pushed]],
            ['scope', 'series', 'minute'],
            ['pushed'],
        );
    }

    private function job(string $name, int $minute, int $completed = 0, int $failed = 0, int $runtimeSum = 0, int $runtimeCount = 0, ?int $pushed = null): void
    {
        $this->mirror()->table(SampleCounts::TABLE)->insert($this->jobRow($name, $minute, $completed, $failed, $runtimeSum, $runtimeCount, $pushed));
    }

    /**
     * @return array<string, mixed>
     */
    private function jobRow(string $name, int $minute, int $completed, int $failed, int $runtimeSum, int $runtimeCount, ?int $pushed): array
    {
        return [
            'scope' => 'job', 'series' => SampleCounts::series('job', $name), 'connection' => null, 'queue' => null, 'name' => $name, 'minute' => $minute,
            'completed' => $completed, 'failed' => $failed, 'runtime_ms_sum' => $runtimeSum, 'runtime_count' => $runtimeCount, 'pushed' => $pushed,
        ];
    }

    /**
     * @param  array<string, float|int>  $values
     */
    private function row(string $name, string $queue, string $status, array $values): void
    {
        $row = [
            'uuid' => strtolower((string) Str::uuid()),
            'connection' => 'database',
            'queue' => $queue,
            'name' => $name,
            'status' => $status,
            'payload' => '{}',
            'payload_bytes' => 2,
            'updated_at' => Clock::toDecimal(self::M - 1000.0),
        ];

        foreach ($values as $column => $value) {
            $row[$column] = $column === 'runtime_ms' ? $value : Clock::toDecimal((float) $value);
        }

        $this->mirror()->table('orrery_jobs')->insert($row);
    }
}
