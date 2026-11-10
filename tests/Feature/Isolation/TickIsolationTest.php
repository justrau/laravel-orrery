<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Isolation;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\PhysicalTables;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;

/**
 * Isolation of tick: a tick during a mirror outage
 * exits successfully, changes no application table, and reports its window
 * after the outage.
 */
#[FreshSchema]
final class TickIsolationTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: a tick that exits non-zero or changes an application table during an outage.
     */
    public function test_tick_during_a_mirror_outage_exits_successfully(): void
    {
        // PHPUnit runs the command in its own process: the context stands for a tick process.
        ProcessContext::useForTesting(ProcessContext::current()->withKindForTesting('tick'));

        $primary = DB::connection(TestEnvironment::PRIMARY);
        $primary->table('jobs')->insert(['queue' => 'isolation', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        $before = $this->applicationState();

        $outage = $this->mirrorOutage();
        $status = Artisan::call('orrery:tick');
        self::assertSame($before, $this->applicationState(), 'the tick changed an application table during the outage');
        $outage->end();

        self::assertSame(0, $status, 'the tick failed during the outage: '.Artisan::output());
        self::assertSame($before, $this->applicationState());

        // The breaker of a server outage closes after 30 s. The terminating
        // callback then flushes the record that the outage kept in memory.
        Watcher::advanceMonotonicForTesting(31);
        $this->app->terminate();

        $windows = $this->mirror()->table('orrery_health_events')->where('process', ProcessContext::current()->identity())->get();
        self::assertCount(1, $windows, 'the tick reported no window after the outage');
        self::assertSame('tick', $windows[0]->context);
        self::assertStringStartsWith('tick:', (string) $windows[0]->last_error_operation);
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationState(): array
    {
        $primary = DB::connection(TestEnvironment::PRIMARY);

        return [
            // The package tables of the same mirror mode are renamed by the outage.
            'tables' => array_values(array_filter(PhysicalTables::of($primary), static fn (string $table): bool => ! str_contains($table, 'orrery_'))),
            'jobs' => $primary->table('jobs')->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all(),
            'failed_jobs' => $primary->table('failed_jobs')->count(),
            'job_batches' => $primary->table('job_batches')->count(),
        ];
    }
}
