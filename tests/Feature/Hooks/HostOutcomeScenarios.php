<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Justrau\Orrery\Tests\Feature\Hooks\Jobs\EffectJob;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Throwable;

/**
 * The host outcomes of the isolation scenarios.
 * HostOutcomeControlTest runs them without the package provider, and
 * HookIsolationTest runs them with the package and a mirror outage. Both
 * compare with the same fixed outcomes.
 */
trait HostOutcomeScenarios
{
    /** A push inside a business transaction: no exception, and both rows commit. */
    protected const PUSH_OUTCOME = ['thrown' => null, 'business_rows' => 1, 'jobs' => 1, 'failed_jobs' => 0];

    /** A reservation and completion: the job ran once and is gone. */
    protected const RESERVE_OUTCOME = ['effects' => ['reserve:1'], 'jobs' => 0, 'failed_jobs' => 0];

    /** A release, a completion and a failure. */
    protected const WORK_OUTCOME = ['effects' => ['complete:1', 'fail:1', 'release:1', 'release:2'], 'jobs' => 0, 'failed_jobs' => 1];

    /** Laravel's own error when the jobs table is missing. */
    protected const POP_ERROR = QueryException::class;

    protected function createHostTables(): void
    {
        Schema::connection(TestEnvironment::PRIMARY)->dropIfExists(EffectJob::TABLE);
        Schema::connection(TestEnvironment::PRIMARY)->create(EffectJob::TABLE, static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('effect');
        });
        Schema::connection(TestEnvironment::PRIMARY)->dropIfExists('hook_business');
        Schema::connection(TestEnvironment::PRIMARY)->create('hook_business', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        $this->beforeApplicationDestroyed(static function (): void {
            Schema::connection(TestEnvironment::PRIMARY)->dropIfExists(EffectJob::TABLE);
            Schema::connection(TestEnvironment::PRIMARY)->dropIfExists('hook_business');
        });
    }

    /**
     * @return array{thrown: ?string, business_rows: int, jobs: int, failed_jobs: int}
     */
    protected function pushInBusinessTransaction(): array
    {
        $thrown = null;

        try {
            DB::connection(TestEnvironment::PRIMARY)->transaction(static function (): void {
                DB::connection(TestEnvironment::PRIMARY)->table('hook_business')->insert(['name' => 'order']);
                Queue::connection('database')->push(new EffectJob('push'));
            });
        } catch (Throwable $error) {
            $thrown = $error::class;
        }

        return [
            'thrown' => $thrown,
            'business_rows' => DB::connection(TestEnvironment::PRIMARY)->table('hook_business')->count(),
            'jobs' => DB::connection(TestEnvironment::PRIMARY)->table('jobs')->count(),
            'failed_jobs' => DB::connection(TestEnvironment::PRIMARY)->table('failed_jobs')->count(),
        ];
    }

    protected function pushReserveScenario(): void
    {
        Queue::connection('database')->push(new EffectJob('reserve'));
    }

    protected function pushWorkScenario(): void
    {
        Queue::connection('database')->push(new EffectJob('release', 'release_once'));
        Queue::connection('database')->push(new EffectJob('complete'));
        Queue::connection('database')->push(new EffectJob('fail', 'fail'));
    }

    /**
     * @return array{effects: list<string>, jobs: int, failed_jobs: int}
     */
    protected function workOutcome(): array
    {
        $effects = DB::connection(TestEnvironment::PRIMARY)->table(EffectJob::TABLE)->pluck('effect')->map(static fn ($effect): string => (string) $effect)->all();
        sort($effects);

        return [
            'effects' => $effects,
            'jobs' => DB::connection(TestEnvironment::PRIMARY)->table('jobs')->count(),
            'failed_jobs' => DB::connection(TestEnvironment::PRIMARY)->table('failed_jobs')->count(),
        ];
    }

    /**
     * Runs pop() while the jobs table is missing; returns the class of the error.
     */
    protected function popWithoutTheJobsTable(): ?string
    {
        $schema = Schema::connection(TestEnvironment::PRIMARY);
        $schema->rename('jobs', 'hook_jobs_hidden');

        try {
            Queue::connection('database')->pop();

            return null;
        } catch (Throwable $error) {
            return $error::class;
        } finally {
            $schema->rename('hook_jobs_hidden', 'jobs');
        }
    }
}
