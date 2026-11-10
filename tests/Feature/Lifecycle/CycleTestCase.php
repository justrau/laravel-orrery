<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Lifecycle;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Lifecycle\StoreOutcome;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Tests\Feature\Hooks\HooksTestCase;
use Justrau\Orrery\Tests\Feature\Lifecycle\Jobs\ScriptedJob;
use Justrau\Orrery\Tests\Support\Fixtures\Lifecycle\BuildsObservations;

/**
 * The fixtures of the lifecycle tests: observations through the store, and
 * the real queue of HooksTestCase for the end-to-end cases.
 */
abstract class CycleTestCase extends HooksTestCase
{
    use BuildsObservations;

    protected function setUp(): void
    {
        parent::setUp();

        ScriptedJob::$rows = [];
    }

    protected function tearDown(): void
    {
        ScriptedJob::$rows = [];
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function store(): JobStore
    {
        return $this->app->make(JobStore::class);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $payload
     */
    protected function observe(string $kind, string $uuid, array $fields = [], array $payload = []): StoreOutcome
    {
        return $this->store()->observe(self::observation($kind, $uuid, $fields, $payload));
    }

    protected function uuid(): string
    {
        return strtolower((string) Str::uuid());
    }

    /**
     * The mirror row as an array, for a comparison of every column.
     *
     * @return array<string, mixed>
     */
    protected function snapshot(string $uuid): array
    {
        return (array) $this->rowOrFail($uuid);
    }

    /**
     * @return list<array<string, mixed>> the summaries of a job, by cycle
     */
    protected function summaries(string $uuid): array
    {
        return $this->mirror()->table('orrery_cycle_summaries')->where('job_uuid', $uuid)->orderBy('cycle')->get()
            ->map(static fn (object $row): array => (array) $row)->all();
    }

    protected function individualSummaries(string $uuid): int
    {
        return $this->mirror()->table('orrery_cycle_summaries')->where('job_uuid', $uuid)->where('merged', false)->count();
    }

    protected function historyRows(string $uuid, ?string $event = null): int
    {
        $query = $this->mirror()->table('orrery_job_history')->where('job_uuid', $uuid);

        return ($event === null ? $query : $query->where('event', $event))->count();
    }

    protected static function unmatched(): int
    {
        return Degradation::record()['unmatched_observations'] ?? 0;
    }

    /**
     * One job through the real queue:work command, which logs a failure in
     * failed_jobs.
     */
    protected function workOnce(): void
    {
        self::assertSame(0, Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--memory' => 4096, '--tries' => 1]));
    }

    protected function nativeRetry(): void
    {
        self::assertSame(0, Artisan::call('queue:retry', ['id' => ['all']]));
    }
}
