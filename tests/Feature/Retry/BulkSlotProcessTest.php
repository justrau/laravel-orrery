<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retry;

use Justrau\Orrery\Retry\ForgetResult;
use Justrau\Orrery\Retry\ForgetService;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Retry\Fixtures\BulkCommandsProvider;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The forget slot of a bulk run is atomic across processes:
 * two clients of one forget run, each a process held at
 * forget.before_claim after its eligibility checks, then released together.
 * The run confirmed one record, so exactly one claims and deletes, and the
 * other claims nothing (not_confirmed).
 */
#[Group('process')]
#[Group('shared-database')]
#[FreshSchema]
final class BulkSlotProcessTest extends TestCase
{
    private const REF = 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1';

    /**
     * Rejects: a check-then-act slot (claimed < confirmed_total read before
     * the claim, an unconditional count in it): both held clients have
     * passed the read, so both would delete.
     */
    public function test_two_held_clients_of_one_forget_run_delete_one_record(): void
    {
        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('A worker process needs a database that it shares with the test: run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }

        $now = Clock::toDecimal(Clock::now());
        $this->mirror()->table('orrery_bulk_runs')->insert([
            'ref' => self::REF, 'action' => 'forget', 'source' => '{"batch_id":"b","kind":"batch"}', 'source_hash' => str_repeat('0', 64),
            'max_occurrence' => 1_000_000, 'total' => 1, 'confirmed_total' => 1, 'created_at' => $now, 'last_run_at' => $now,
        ]);
        $members = [];

        foreach ([1, 2] as $number) {
            $uuid = sprintf('00000000-0000-4000-8000-%012d', $number);
            $members[] = [$uuid, 500 + $number];
            $this->mirror()->table('orrery_jobs')->insert([
                'uuid' => $uuid, 'connection' => 'database', 'queue' => 'slots', 'name' => 'App\\Jobs\\Slot', 'type' => 'job', 'status' => 'failed',
                'occurrence_id' => 500 + $number, 'payload' => '{}', 'payload_bytes' => 2, 'batch_id' => 'b',
                'pushed_at' => $now, 'failed_at' => $now, 'finished_at' => $now, 'updated_at' => $now,
            ]);
        }

        $barriers = [Barrier::forTest(), Barrier::forTest()];
        $children = [];

        foreach ($members as $index => [$uuid, $occurrence]) {
            $children[] = WorkerProcess::start(
                ['test:bulk-forget', $uuid, (string) $occurrence, self::REF],
                $this->testEnvironment(),
                ['ORRERY_TEST_PROVIDERS' => BulkCommandsProvider::class] + $barriers[$index]->holdEnvironment(ForgetService::BEFORE_CLAIM),
            );
        }

        foreach ($children as $index => $child) {
            $barriers[$index]->await(ForgetService::BEFORE_CLAIM.'.reached', 60, $child->abandonIfExited());
        }

        foreach ($barriers as $barrier) {
            $barrier->arrive(ForgetService::BEFORE_CLAIM.'.release');
        }

        $results = [];

        foreach ($children as $child) {
            self::assertSame(0, $child->waitForExit(60), $child->describe());
            $lines = array_values(array_filter(explode("\n", trim($child->output())), static fn (string $line): bool => str_starts_with($line, '{')));
            self::assertNotSame([], $lines, $child->describe());
            $decoded = json_decode((string) end($lines), true);
            self::assertIsArray($decoded);
            $results[] = $decoded['result'];
        }

        sort($results);
        self::assertSame([ForgetResult::DELETED, ForgetResult::NOT_CONFIRMED], $results);
        self::assertSame(1, $this->mirror()->table('orrery_jobs')->where('queue', 'slots')->count(), 'exactly the confirmed record is gone');
        self::assertSame(1, (int) $this->mirror()->table('orrery_bulk_runs')->where('ref', self::REF)->value('claimed'));
        self::assertSame(1, $this->mirror()->table('orrery_operations')->where('bulk_ref', self::REF)->count());
    }
}
