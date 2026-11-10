<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Failed;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Tests\Feature\Jobs\FailingJob;
use Justrau\Orrery\Tests\Feature\Operations\Jobs\TimeoutJob;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The id binding in a real worker (part of
 * test_provider_binds_the_inserted_id): WorkCommand's own logger calls the
 * package's provider after the package's F1 listener. Kind: new behaviour.
 */
#[Group('process')]
final class CapturingProviderProcessTest extends TestCase
{
    /**
     * Rejects: a binding that needs a later step of the worker (JobAttempted, a tick) or a lookup: a worker that the
     * timeout kills right after it logged its failure still has the id bound.
     */
    #[Group('shared-database')]
    public function test_a_timeout_killed_worker_has_its_id_bound(): void
    {
        $env = $this->sharedEnvironment();
        Queue::push(new TimeoutJob);

        $worker = WorkerProcess::start(['queue:work', 'database', '--max-jobs=1', '--sleep=0', '--tries=1', '--timeout=1'], $env);
        self::assertNotSame(0, $worker->waitForExit(60), 'The timeout killed the worker. '.$worker->describe());

        $record = DB::table('failed_jobs')->sole();
        $row = $this->mirror()->table('orrery_jobs')->where('uuid', $record->uuid)->first();

        self::assertNotNull($row, $worker->describe());
        self::assertSame('failed', $row->status, $worker->describe());
        self::assertSame((int) $record->id, $row->provider_id === null ? null : (int) $row->provider_id, $worker->describe());
    }

    /**
     * Rejects: a worker whose logger does not reach the package's provider, or whose F1 listener runs after the
     * logger (the slot would be empty at log()).
     */
    #[Group('shared-database')]
    public function test_a_worker_failure_binds_its_id(): void
    {
        $env = $this->sharedEnvironment();
        Queue::push(new FailingJob);

        $worker = WorkerProcess::start(['queue:work', 'database', '--once', '--sleep=0', '--tries=1'], $env);
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());

        $record = DB::table('failed_jobs')->sole();
        $row = $this->mirror()->table('orrery_jobs')->where('uuid', $record->uuid)->first();

        self::assertNotNull($row);
        self::assertSame((int) $record->id, $row->provider_id === null ? null : (int) $row->provider_id, $worker->describe());
        self::assertNotNull($row->provider_target);
    }

    private function sharedEnvironment(): TestEnvironment
    {
        $env = $this->testEnvironment();

        if ($env->inMemory()) {
            self::markTestSkipped('A worker process needs a database that two processes share. Run the process tests through bin/test sqlite --sqlite-file, bin/test mysql or bin/test pgsql.');
        }

        return $env;
    }
}
