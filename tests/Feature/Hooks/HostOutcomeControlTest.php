<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks;

use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The control of HookIsolationTest: the same scenarios without the package
 * provider, so Laravel's own queue gives the host outcomes that the
 * isolation tests compare with. The scenarios run in this process, because
 * the worker CLI always loads the package.
 */
#[Group('process')]
#[FreshSchema]
final class HostOutcomeControlTest extends TestCase
{
    use HostOutcomeScenarios;

    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createHostTables();
        self::assertNotInstanceOf(\Justrau\Orrery\DatabaseQueue::class, Queue::connection('database'), 'The control runs without the package.');
        self::assertInstanceOf(DatabaseQueue::class, Queue::connection('database'));
    }

    /**
     * Rejects: a control whose expected push outcome is not Laravel's own.
     */
    public function test_push_in_a_business_transaction(): void
    {
        self::assertSame(self::PUSH_OUTCOME, $this->pushInBusinessTransaction());
    }

    /**
     * Rejects: a control whose expected reservation outcome is not Laravel's own.
     */
    public function test_reserve_and_complete(): void
    {
        $this->pushReserveScenario();
        $this->workUntilEmpty();

        self::assertSame(self::RESERVE_OUTCOME, $this->workOutcome());
    }

    /**
     * Rejects: a control whose expected release, completion and failure outcome is not Laravel's own.
     */
    public function test_release_completion_and_failure(): void
    {
        $this->pushWorkScenario();
        $this->workUntilEmpty();

        self::assertSame(self::WORK_OUTCOME, $this->workOutcome());
    }

    /**
     * Rejects: a control whose expected pop() error is not Laravel's own.
     */
    public function test_pop_without_the_jobs_table(): void
    {
        self::assertSame(self::POP_ERROR, $this->popWithoutTheJobsTable());
    }

    private function workUntilEmpty(): void
    {
        self::assertSame(0, Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0, '--memory' => 4096, '--tries' => 1]));
    }
}
