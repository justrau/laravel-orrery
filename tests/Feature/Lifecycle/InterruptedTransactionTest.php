<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Lifecycle;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed as LaravelJobFailed;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\TestCase;
use RuntimeException;
use Throwable;

/**
 * A job timeout whose alarm fires while the mirror session is inside a
 * package transaction. Laravel's alarm handler dispatches
 * JobFailed and JobTimedOut at any opcode boundary, then kills the process,
 * so the server discards the interrupted transaction and everything that
 * joined it.
 */
final class InterruptedTransactionTest extends TestCase
{
    /**
     * Rejects: a timeout observation that joins the interrupted package
     * transaction as a savepoint, so the kill discards it and the row keeps
     * no timeout. Still passes: a listener that rolls back a package
     * transaction that is not interrupted (none is open at a listener in
     * normal work).
     */
    public function test_a_timeout_inside_a_package_transaction_is_kept(): void
    {
        $job = $this->reservedJob();

        $this->interrupt(fn () => $this->app['events']->dispatch(new JobTimedOut('database', $job, 60)));

        $row = $this->mirror()->table('orrery_jobs')->first();
        self::assertNotNull($row);
        self::assertSame(1, (int) $row->timeouts, 'the kill discarded the timeout observation');
        self::assertFalse($this->app->make(PackageTransaction::class)->active($this->packageMirror()), 'the mirror session stayed in a transaction');
    }

    /**
     * Rejects: a failure observation that joins the interrupted package
     * transaction, so the job is in failed_jobs while its mirror row stays
     * reserved.
     */
    public function test_a_failure_inside_a_package_transaction_is_kept(): void
    {
        $job = $this->reservedJob();

        $this->interrupt(fn () => $this->app['events']->dispatch(new LaravelJobFailed('database', $job, new RuntimeException('timed out'))));

        self::assertSame('failed', $this->mirror()->table('orrery_jobs')->value('status'), 'the kill discarded the failure observation');
    }

    /**
     * Runs $handler inside a package transaction on the mirror session, as
     * the alarm handler interrupts one, then ends that transaction as the
     * kill does: nothing more of it commits.
     */
    private function interrupt(callable $handler): void
    {
        try {
            $this->app->make(PackageTransaction::class)->run($this->packageMirror(), static function () use ($handler): never {
                $handler();

                throw new RuntimeException('the process is killed');
            });
        } catch (Throwable $error) {
            self::assertSame('the process is killed', $error->getMessage());
        }
    }

    private function reservedJob(): Job
    {
        Queue::push(new BasicJob);
        $job = $this->app['queue']->connection('database')->pop('default');
        self::assertInstanceOf(Job::class, $job);
        self::assertSame('reserved', $this->mirror()->table('orrery_jobs')->value('status'));

        return $job;
    }

    private function packageMirror(): \Illuminate\Database\Connection
    {
        return $this->app->make(PackageSession::class)->mirror();
    }
}
