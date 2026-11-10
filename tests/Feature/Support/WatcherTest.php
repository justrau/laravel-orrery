<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Support;

use Illuminate\Contracts\Database\LostConnectionDetector;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Justrau\Orrery\Exceptions\WatchedOperationFailed;
use Justrau\Orrery\OrreryServiceProvider;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\ErrorKinds;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Runtime\ProcessState;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Runtime\WatchResult;
use Justrau\Orrery\Storage\MirrorRefused;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\Fixtures\TimingOutListener;
use Justrau\Orrery\Tests\Support\MirrorSession;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * The watcher: isolation of watched work, the breaker, its
 * callbacks, the rate-limited reports and the explicit results.
 */
final class WatcherTest extends TestCase
{
    /** @var list<Throwable> the exceptions that reached Laravel's report() */
    private array $reported = [];

    private ?string $errorLog = null;

    private string|false $previousErrorLog = false;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp(1_789_999_000, 'UTC'));
        $this->recordReports();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if ($this->errorLog !== null) {
            ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
            @unlink($this->errorLog);
        }

        parent::tearDown();
    }

    /**
     * Rejects: a run() that rethrows, or that returns null in place of the given default.
     */
    public function test_run_returns_the_default_and_never_rethrows(): void
    {
        $watcher = $this->watcher();

        self::assertSame('value', $watcher->run('test:ok', static fn () => 'value', 'default'));
        self::assertSame('default', $watcher->run('test:data', static function (): never {
            throw new RuntimeException('a data error');
        }, 'default'));
        self::assertSame(['x'], $watcher->run('test:data', static function (): never {
            throw new QueryException(PackageSession::NAME_PREFIX.TestEnvironment::PRIMARY, 'select 1', [], self::pdoError('23000'));
        }, ['x']));
        self::assertNull($watcher->run('test:data', static function (): never {
            throw new RuntimeException('no default');
        }));
        self::assertFalse($watcher->breakerOpen());
    }

    /**
     * Rejects: treating application-connection errors as dependency errors.
     */
    public function test_dependency_error_on_a_package_session_opens_the_breaker(): void
    {
        $watcher = $this->watcher();

        // The same error on the application connection opens nothing.
        $application = $watcher->attempt('test:app', static function (): never {
            throw new QueryException('testbench', 'select 1', [], self::pdoError('08006'));
        });
        self::assertInstanceOf(QueryException::class, $application->error());
        self::assertFalse(ErrorKinds::isDependency($application->error()), 'an error of the application connection is a data error');
        self::assertFalse($watcher->breakerOpen(), 'an error of the application connection opened the breaker');

        $watcher->run('test:mirror', static function (): never {
            throw new QueryException(MirrorSession::name(), 'select 1', [], self::pdoError('08006'));
        });
        self::assertTrue($watcher->breakerOpen(), 'a dependency error of the mirror session did not open the breaker');

        $calls = 0;
        self::assertSame('skipped', $watcher->run('test:next', static function () use (&$calls): string {
            $calls++;

            return 'ran';
        }, 'skipped'));
        self::assertSame(0, $calls, 'the open breaker ran the work');
        // Two failed observations and one that the open breaker skipped.
        self::assertSame(3, Degradation::record()['dropped'] ?? null, 'the skipped work was not counted as dropped');

        Watcher::advanceMonotonicForTesting(29);
        self::assertTrue($watcher->breakerOpen(), 'the breaker closed before breaker_seconds');

        Watcher::advanceMonotonicForTesting(1);
        self::assertSame('ran', $watcher->run('test:next', static function () use (&$calls): string {
            $calls++;

            return 'ran';
        }, 'skipped'));
        self::assertSame(1, $calls);
        self::assertFalse($watcher->breakerOpen());
    }

    /**
     * Rejects: a callback per error, or a callback error that reaches the caller of the failed work.
     */
    public function test_breaker_open_callbacks_run_once_per_opening(): void
    {
        $watcher = $this->watcher();
        $calls = [];
        $watcher->whenBreakerOpens(static function () use (&$calls): void {
            $calls[] = 'first';

            throw new RuntimeException('the first callback fails');
        });
        $watcher->whenBreakerOpens(static function () use (&$calls): void {
            $calls[] = 'second';
        });

        $lost = static function (): never {
            throw new QueryException(MirrorSession::name(), 'select 1', [], self::pdoError('08006'));
        };

        // Two dependency errors in one open period: the outer work started
        // before the breaker opened, and it fails after the inner work opened it.
        $result = $watcher->attempt('test:outer', static function () use ($watcher, $lost): never {
            $watcher->run('test:inner', $lost);

            $lost();
        });

        self::assertFalse($result->succeeded());
        self::assertSame(['first', 'second'], $calls, 'the callbacks did not run exactly once for one opening');
        self::assertTrue($this->wasReported('the first callback fails'), 'the callback error was not reported');

        Watcher::advanceMonotonicForTesting(31);
        self::assertFalse($watcher->breakerOpen());

        $watcher->run('test:again', $lost);
        self::assertSame(['first', 'second', 'first', 'second'], $calls, 'the next opening did not call the callbacks again');
    }

    /**
     * Rejects: a refused mirror (D17) that drops only the one observation and retries every next one.
     */
    public function test_a_refused_mirror_is_a_dependency_error(): void
    {
        $watcher = $this->watcher();

        $watcher->run('test:refused', static function (): never {
            throw new MirrorRefused('orrery.database shares the SQLite file of connection [testbench].');
        });

        self::assertTrue($watcher->breakerOpen(), 'a refused mirror did not open the breaker');
    }

    /**
     * Rejects: a watcher that swallows the TimeoutExceededException that
     * Laravel's worker throws into the running code when
     * Worker::$killOnTimeout is false (the job then runs on past its
     * timeout), here and in a listener that watches itself.
     */
    public function test_a_worker_timeout_passes_through_the_watcher(): void
    {
        $watcher = $this->watcher();
        $timeout = new TimeoutExceededException('The job has timed out.');

        foreach (['run' => static fn () => $watcher->run('test:timeout', static fn (): never => throw $timeout, 'default'), 'attempt' => static fn () => $watcher->attempt('test:timeout', static fn (): never => throw $timeout)] as $method => $call) {
            try {
                $call();
                self::fail("{$method}() swallowed the worker timeout");
            } catch (TimeoutExceededException $caught) {
                self::assertSame($timeout, $caught);
            }
        }

        self::assertFalse($watcher->breakerOpen());

        $wrap = new \ReflectionMethod(OrreryServiceProvider::class, 'wrapListener');
        $listener = $wrap->invoke(new OrreryServiceProvider($this->app), TimingOutListener::class);

        try {
            $listener(new \stdClass);
            self::fail('a listener that watches itself swallowed the worker timeout');
        } catch (TimeoutExceededException) {
            // Passed through to the job.
        }
    }

    /**
     * Rejects: an application's lost-connection detector that throws, and
     * whose error escapes the watcher into the host's hook.
     */
    public function test_a_throwing_lost_connection_detector_stays_inside_the_watcher(): void
    {
        $this->app->instance(LostConnectionDetector::class, new class implements LostConnectionDetector
        {
            public function causedByLostConnection(Throwable $e): bool
            {
                throw new RuntimeException('The detector failed.');
            }
        });
        $watcher = $this->watcher();

        self::assertSame('default', $watcher->run('test:lost', static function (): never {
            throw new QueryException(PackageSession::NAME_PREFIX.TestEnvironment::PRIMARY, 'select 1', [], self::pdoError('HY000'));
        }, 'default'));
        self::assertSame('ran', $watcher->run('test:next', static fn (): string => 'ran'));
    }

    /**
     * Rejects: a data error that opens the breaker.
     */
    public function test_data_error_drops_only_that_observation(): void
    {
        $watcher = $this->watcher();

        self::assertNull($watcher->run('test:duplicate', static function (): never {
            throw new QueryException(PackageSession::NAME_PREFIX.TestEnvironment::PRIMARY, 'insert', [], self::pdoError('23000'));
        }));

        self::assertFalse($watcher->breakerOpen(), 'a data error opened the breaker');
        self::assertSame('next', $watcher->run('test:next', static fn () => 'next'));

        $record = Degradation::record();
        self::assertNotNull($record);
        self::assertSame(1, $record['dropped']);
        self::assertSame('test:duplicate', $record['last_error_operation']);
    }

    /**
     * Rejects: one report per error, or one limit for all operations.
     */
    public function test_report_is_rate_limited_per_operation(): void
    {
        $watcher = $this->watcher();
        $fail = static fn (string $message): \Closure => static function () use ($message): never {
            throw new RuntimeException($message);
        };

        $watcher->run('test:a', $fail('a-1'));
        $watcher->run('test:a', $fail('a-2'));
        $watcher->run('test:b', $fail('b-1'));

        Watcher::advanceMonotonicForTesting(59);
        $watcher->run('test:a', $fail('a-3'));

        Watcher::advanceMonotonicForTesting(2);
        $watcher->run('test:a', $fail('a-4'));

        // The summary ends with the cause's first line.
        self::assertSame(['a-1', 'b-1', 'a-4'], array_map(static fn (Throwable $e): string => substr($e->getMessage(), (int) strrpos($e->getMessage(), ': ') + 2), $this->reported));
        self::assertSame(['test:a', 'test:b', 'test:a'], array_map(static fn (Throwable $e): string => $e instanceof WatchedOperationFailed ? $e->operation : '', $this->reported));
    }

    /**
     * Rejects: a report call whose own exception leaves the watcher.
     */
    public function test_a_failing_report_falls_back_to_error_log(): void
    {
        $this->errorLog = tempnam(sys_get_temp_dir(), 'orrery-error-log-');
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->errorLog);

        $this->app->instance(ExceptionHandler::class, new class implements ExceptionHandler
        {
            public function report(Throwable $e): void
            {
                throw new RuntimeException('the exception handler fails');
            }

            public function shouldReport(Throwable $e): bool
            {
                return true;
            }

            public function render($request, Throwable $e)
            {
                throw $e;
            }

            public function renderForConsole($output, Throwable $e): void
            {
            }
        });

        self::assertSame('default', $this->watcher()->run('test:reported', static function (): never {
            throw new RuntimeException('the watched work fails');
        }, 'default'));

        $log = (string) file_get_contents($this->errorLog);
        self::assertSame(1, substr_count($log, "\n"), "the fallback is not one line:\n{$log}");
        self::assertStringContainsString('test:reported', $log);
        self::assertStringContainsString('the watched work fails', $log);
    }

    /**
     * Rejects: returning null for success.
     */
    public function test_attempt_returns_an_explicit_result(): void
    {
        $watcher = $this->watcher();

        $ok = $watcher->attempt('test:ok', static fn () => null);
        self::assertInstanceOf(WatchResult::class, $ok);
        self::assertTrue($ok->succeeded());
        self::assertNull($ok->value());
        self::assertNull($ok->error());

        $error = new RuntimeException('failed');
        $failed = $watcher->attempt('test:failed', static function () use ($error): never {
            throw $error;
        });
        self::assertFalse($failed->succeeded());
        self::assertSame($error, $failed->error());
        self::assertNull($failed->value());

        // With the breaker open, the work does not run, and the result is a failure.
        $watcher->run('test:lost', static function (): never {
            throw new QueryException(MirrorSession::name(), 'select 1', [], self::pdoError('08006'));
        });
        $skipped = $watcher->attempt('test:skipped', static fn () => 'ran');
        self::assertFalse($skipped->succeeded());
        self::assertNotNull($skipped->error());
    }

    /**
     * Rejects: a breaker or a report limit that measures its duration on the wall clock, so a clock change shortens or extends it.
     */
    public function test_breaker_and_report_limit_use_elapsed_monotonic_time(): void
    {
        $watcher = $this->watcher();
        $lost = static function (): never {
            throw new QueryException(MirrorSession::name(), 'select 1', [], self::pdoError('08006'));
        };

        $watcher->run('test:lost', $lost);
        self::assertTrue($watcher->breakerOpen());
        self::assertCount(1, $this->reported);

        // The wall clock jumps forward by two minutes, but no time elapses.
        Carbon::setTestNow(Carbon::now()->addSeconds(120));
        self::assertTrue($watcher->breakerOpen(), 'a wall-clock jump forward closed the breaker');
        $watcher->report('test:lost', 'a second report');
        self::assertCount(1, $this->reported, 'a wall-clock jump forward ended the report limit');

        // The wall clock jumps back by four minutes, and 31 s elapse.
        Carbon::setTestNow(Carbon::now()->subSeconds(240));
        Watcher::advanceMonotonicForTesting(31);
        self::assertFalse($watcher->breakerOpen(), 'a wall-clock jump back kept the breaker open after 31 s');
        $watcher->report('test:lost', 'a third report');
        self::assertCount(1, $this->reported, 'the report limit ended before 60 s elapsed');

        Watcher::advanceMonotonicForTesting(30);
        $watcher->report('test:lost', 'a fourth report');
        self::assertCount(2, $this->reported, 'the report limit did not end after 61 s elapsed');
        self::assertStringEndsWith(': a fourth report', $this->reported[1]->getMessage());
    }

    /**
     * The watcher, the degradation record and the process context are
     * container singletons: a new application, as the next
     * test builds it, has new ones. forgetNewApplicationState() drops them
     * as a new application would not have them.
     *
     * Rejects: state of the watcher, the degradation record or the process context kept outside the container (a static), so it survives into the next test.
     */
    public function test_a_new_application_has_no_watcher_state(): void
    {
        Watcher::rethrowForTesting();
        Watcher::failOperationsForTesting(['test:']);
        Degradation::error('test:state', 'a recorded error');
        $context = ProcessContext::current()->withKindForTesting('web');
        ProcessContext::useForTesting($context);

        $this->forgetNewApplicationState();

        self::assertNull(Degradation::record(), 'the degradation record survived the reset');
        self::assertNotSame($context, ProcessContext::current(), 'the test context survived the reset');
        self::assertSame('ran', $this->watcher()->run('test:after-reset', static fn () => 'ran', 'failed'), 'failOperationsForTesting() survived the reset');
        self::assertNull($this->watcher()->run('other:after-reset', static function (): never {
            throw new RuntimeException('rethrown?');
        }), 'rethrowForTesting() survived the reset');
    }

    /**
     * The runtime singletons of a new application: the container builds
     * them again on their next use.
     */
    private function forgetNewApplicationState(): void
    {
        foreach ([Watcher::class, Degradation::class, ProcessState::class] as $binding) {
            $this->app->forgetInstance($binding);
        }
    }

    private function watcher(): Watcher
    {
        return $this->app->make(Watcher::class);
    }

    private function recordReports(): void
    {
        $reported = &$this->reported;
        $this->app->instance(ExceptionHandler::class, new class($reported) implements ExceptionHandler
        {
            /** @param list<Throwable> $reported */
            public function __construct(private array &$reported)
            {
            }

            public function report(Throwable $e): void
            {
                $this->reported[] = $e;
            }

            public function shouldReport(Throwable $e): bool
            {
                return true;
            }

            public function render($request, Throwable $e)
            {
                throw $e;
            }

            public function renderForConsole($output, Throwable $e): void
            {
            }
        });
    }

    /**
     * Whether a report names $message: the watcher reports a
     * WatchedOperationFailed whose summary ends with the cause's first line.
     */
    private function wasReported(string $message): bool
    {
        foreach ($this->reported as $error) {
            if ($error instanceof WatchedOperationFailed && str_ends_with($error->getMessage(), ': '.$message)) {
                return true;
            }
        }

        return false;
    }

    private static function pdoError(string $sqlstate): PDOException
    {
        return new class($sqlstate) extends PDOException
        {
            public function __construct(string $sqlstate)
            {
                parent::__construct("SQLSTATE[{$sqlstate}]: a test error");
                $this->code = $sqlstate;
                $this->errorInfo = [$sqlstate, 1, 'a test error'];
            }
        };
    }
    /**
     * Rejects: a frozen clock that still moves with real time, or a freeze that a new application keeps.
     * Still passes: a clock that runs backwards after the reset (not checked).
     */
    public function test_a_frozen_monotonic_clock_moves_only_when_advanced_and_the_reset_restores_it(): void
    {
        Watcher::freezeMonotonicForTesting();
        $frozen = Watcher::monotonic();
        usleep(20_000);

        self::assertSame($frozen, Watcher::monotonic(), 'the frozen clock does not follow real time');

        Watcher::advanceMonotonicForTesting(10.0);
        self::assertSame($frozen + 10.0, Watcher::monotonic());

        $this->forgetNewApplicationState();
        $first = Watcher::monotonic();
        usleep(20_000);

        self::assertGreaterThan($first, Watcher::monotonic(), 'after the reset the clock runs again');
    }
}
