<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Performance;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Database\Schema\IndexName;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Queue\BulkResolver;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\TestCase;

/**
 * The tick's reads of unresolved bulk rows.
 * The tick benchmark found that they read every pending mirror row. They now
 * start with `job_id_unresolved = true`, which the index
 * (`job_id_unresolved`, `bulk_floor_id`, `id`) serves, so a mirror without
 * unresolved rows costs one index probe.
 */
final class BulkUnresolvedQueryTest extends TestCase
{
    /**
     * Rejects: a query of unresolved rows that starts with `status`, so it reads every pending row.
     * Still passes: an engine planner that ignores the index (the test pins the query shape and the index, not the plan).
     */
    public function test_unresolved_reads_start_with_the_unresolved_flag_and_still_resolve(): void
    {
        // Pending rows that are resolved, and one unresolved bulk row.
        for ($i = 0; $i < 5; $i++) {
            Queue::push(new BasicJob);
        }

        $jobId = (int) DB::connection('testbench')->table('jobs')->max('id');
        $uuid = (string) $this->mirror()->table('orrery_jobs')->where('job_id', $jobId)->value('uuid');
        $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->update(['job_id' => null, 'job_id_unresolved' => true, 'bulk_floor_id' => $jobId - 1]);

        $reads = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$reads): void {
            if (str_starts_with(strtolower($event->sql), 'select') && str_contains($event->sql, 'job_id_unresolved') && str_contains($event->sql, 'orrery_jobs')) {
                $reads[] = $event->sql;
            }
        });

        $resolved = $this->app->make(BulkResolver::class)->resolveUnresolved(new TickBudget(Clock::monotonic() + 60));

        self::assertSame(1, $resolved);
        self::assertSame($jobId, (int) $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->value('job_id'));
        self::assertNotEmpty($reads);

        foreach ($reads as $sql) {
            self::assertMatchesRegularExpression('/\bwhere\s+["`]?job_id_unresolved["`]?\s*=/i', $sql, 'each read of unresolved rows starts with the flag');
        }

        $name = IndexName::for($this->mirror(), 'orrery_jobs', 'unresolved');
        $index = collect($this->mirrorSchema()->getIndexes('orrery_jobs'))->first(static fn (array $index): bool => strtolower((string) $index['name']) === strtolower($name));

        self::assertNotNull($index, 'the index of the unresolved rows exists');
        self::assertSame(['job_id_unresolved', 'bulk_floor_id', 'connection', 'queue', 'id'], $index['columns']);
    }
}
