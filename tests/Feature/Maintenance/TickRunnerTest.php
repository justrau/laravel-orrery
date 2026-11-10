<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Maintenance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Justrau\Orrery\Database\Models\Setting;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickPhase;
use Justrau\Orrery\Maintenance\TickRunner;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\TestCase;
use RuntimeException;

/**
 * The tick framework: tagged phases, priorities, the
 * cooperative deadline, missing tables and the tick liveness.
 */
final class TickRunnerTest extends TestCase
{
    /** @var list<string> */
    public static array $log = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::$log = [];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: an exception, or one warning per phase.
     */
    #[FreshSchema]
    public function test_missing_tables_warn_once_and_succeed(): void
    {
        $this->tagPhases(self::phase('first', 10), self::phase('second', 20));

        foreach (['orrery_jobs', 'orrery_settings'] as $table) {
            $this->mirrorSchema()->rename($table, $table.'_hidden');

            try {
                $status = Artisan::call('orrery:tick');
                $output = Artisan::output();
            } finally {
                $this->mirrorSchema()->rename($table.'_hidden', $table);
            }

            self::assertSame(0, $status, "the tick failed without {$table}");
            $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));
            self::assertCount(1, $lines, "not exactly one warning without {$table}:\n{$output}");
            self::assertStringContainsString('migrate', $lines[0]);
            self::assertSame([], self::$log, "a phase ran without {$table}");
        }
    }

    /**
     * Rejects: a value written only at the first run.
     */
    public function test_observer_tick_at_is_written_each_run(): void
    {
        $first = Carbon::createFromTimestamp(1_789_999_000.25, 'UTC');
        Carbon::setTestNow($first);
        self::assertSame(0, Artisan::call('orrery:tick'));
        self::assertSame(Clock::toDecimal(1_789_999_000.25), $this->tickAt());

        Carbon::setTestNow($first->copy()->addSeconds(60));
        self::assertSame(0, Artisan::call('orrery:tick'));
        self::assertSame(Clock::toDecimal(1_789_999_060.25), $this->tickAt());
    }

    /**
     * Rejects: an overrun record without the phase name.
     */
    public function test_overrun_is_recorded_with_its_phase(): void
    {
        $this->app['config']->set('orrery.tick.deadline_seconds', 30);

        // The phase takes 31 s on the monotonic clock, without a sleep.
        $report = $this->app->make(TickRunner::class)->run([
            self::phase('slow', 10, static fn () => Clock::advanceMonotonicForTesting(31.0)),
            self::phase('later', 20),
        ]);

        self::assertSame(['slow'], $report->ran());
        self::assertSame(['slow'], $report->overruns());
        self::assertSame(['later'], $report->skipped());
        self::assertSame(['slow'], self::$log, 'a phase ran after the deadline');

        $record = Degradation::record();
        self::assertNotNull($record);
        self::assertArrayHasKey('tick_overrun:slow', $record['errors']);
        self::assertArrayHasKey('tick_skipped:later', $record['errors']);
        self::assertArrayNotHasKey('tick_overrun:later', $record['errors'], 'a skipped phase is recorded as an overrun');

        // The durable record names the phase that exceeded the deadline, and
        // counts the skipped phase as dropped work.
        Degradation::flush();
        $row = $this->mirror()->table('orrery_health_events')->where('process', ProcessContext::current()->identity())->first();
        self::assertNotNull($row, 'the tick window was not flushed');
        self::assertSame('tick_overrun:slow', $row->last_error_operation, 'the stored overrun does not name the phase that exceeded the deadline');
        self::assertSame(1, (int) $row->dropped);
    }

    /**
     * Rejects: a recovery counter on the mirror session while the phases run on the maintenance session, so a successful phase never closes the window of an earlier phase error.
     */
    public function test_a_successful_phase_after_a_failed_phase_closes_the_window(): void
    {
        $this->app->make(TickRunner::class)->run([
            self::phase('broken', 10, static function (): never {
                throw new RuntimeException('the phase fails');
            }),
            self::phase('healthy', 20, static function (): void {
                Setting::query()->count();
            }),
        ]);

        $record = Degradation::record();
        self::assertNotNull($record);
        self::assertSame('tick:broken', $record['last_error_operation']);
        self::assertNotNull($record['ended_at'], 'the successful phase did not close the window in memory');

        Degradation::flush();
        $row = $this->mirror()->table('orrery_health_events')->where('process', ProcessContext::current()->identity())->first();
        self::assertNotNull($row, 'the tick window was not flushed');
        self::assertNotNull($row->ended_at, 'the flushed window has no end');
    }

    /**
     * Rejects: a recovery from a phase that runs no package statement, or from the flush of the health row itself.
     */
    public function test_a_no_op_phase_and_the_flush_keep_the_window_open(): void
    {
        $this->app->make(TickRunner::class)->run([
            self::phase('broken', 10, static function (): never {
                throw new RuntimeException('the phase fails');
            }),
            self::phase('idle', 20),
        ]);

        $record = Degradation::record();
        self::assertNotNull($record);
        self::assertNull($record['ended_at'], 'a phase without a package statement closed the window');

        Degradation::flush();
        Degradation::flush();
        $row = $this->mirror()->table('orrery_health_events')->where('process', ProcessContext::current()->identity())->first();
        self::assertNotNull($row, 'the tick window was not flushed');
        self::assertNull($row->ended_at, 'the flush closed the window');
        $record = Degradation::record();
        self::assertNotNull($record, 'the flush removed the open window from memory');
        self::assertNull($record['ended_at'], 'the flush closed the window in memory');
    }

    /**
     * Rejects: tick phases on the mirror session, which has no statement timeout, or a timeout that stays on the session after the tick.
     */
    public function test_phases_use_the_maintenance_session_with_its_statement_timeout(): void
    {
        $env = $this->testEnvironment();

        if (! $env->isServer()) {
            self::markTestSkipped('SQLite has no statement timeout (Spec B §3.1): run it through bin/test mysql or bin/test pgsql.');
        }

        $this->app['config']->set('orrery.sessions.statement_timeout_ms', 7000);
        $seen = [];
        $read = static function () use ($env): array {
            $connection = Setting::query()->getConnection();
            $timeout = $env->driver === 'mysql'
                ? (string) $connection->selectOne('select @@max_execution_time as t')->t
                : (string) $connection->selectOne('show statement_timeout')->statement_timeout;

            return [$connection->getName(), $timeout];
        };

        $this->app->make(TickRunner::class)->run([
            self::phase('probe', 10, static function () use (&$seen, $read): void {
                $seen = $read();
            }),
        ]);

        self::assertSame([self::mirrorSession(), $env->driver === 'mysql' ? '7000' : '7s'], $seen, 'the phase did not run on the maintenance session with its timeout');
        // One package session per database connection: every package
        // statement has the timeout, inside a tick and outside it.
        self::assertSame([self::mirrorSession(), $env->driver === 'mysql' ? '7000' : '7s'], $read(), 'the package session lost its statement timeout after the tick');
    }

    /**
     * Rejects: a runner that stops at the first exception.
     */
    public function test_a_throwing_phase_does_not_stop_later_phases(): void
    {
        $report = $this->app->make(TickRunner::class)->run([
            self::phase('broken', 10, static function (): never {
                throw new RuntimeException('the phase fails');
            }),
            self::phase('healthy', 20),
        ]);

        self::assertSame(['broken', 'healthy'], self::$log);
        self::assertSame(['broken'], $report->failed());
        self::assertSame(['broken', 'healthy'], $report->ran());
        self::assertSame('tick:broken', Degradation::record()['last_error_operation'] ?? null);
    }

    /**
     * Rejects: the container's tag order.
     */
    public function test_phases_run_in_priority_order(): void
    {
        $this->tagPhases(self::phase('third', 30), self::phase('first', -20), self::phase('second', 5));

        self::assertSame(0, Artisan::call('orrery:tick'));

        self::assertSame(['first', 'second', 'third'], self::$log);
    }

    private function tagPhases(TickPhase ...$phases): void
    {
        $names = [];

        foreach ($phases as $phase) {
            $name = 'test.tick-phase.'.$phase->name();
            $this->app->instance($name, $phase);
            $names[] = $name;
        }

        $this->app->tag($names, 'orrery.tick-phases');
    }

    private function tickAt(): ?string
    {
        $value = $this->mirror()->table('orrery_settings')->where('key', 'observer_tick_at')->value('value');

        return $value === null ? null : (string) $value;
    }

    private static function phase(string $name, int $priority, ?\Closure $work = null): TickPhase
    {
        return new class($name, $priority, $work) implements TickPhase
        {
            public function __construct(private string $name, private int $priority, private ?\Closure $work) {}

            public function name(): string
            {
                return $this->name;
            }

            public function priority(): int
            {
                return $this->priority;
            }

            public function run(TickBudget $budget): void
            {
                TickRunnerTest::$log[] = $this->name;

                if ($this->work !== null) {
                    ($this->work)();
                }
            }
        };
    }
}
