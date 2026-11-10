<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Observation;

use ArrayObject;
use Illuminate\Database\Connection;
use Illuminate\Queue\DatabaseQueue as LaravelDatabaseQueue;
use Illuminate\Queue\Jobs\DatabaseJob;
use Justrau\Orrery\Tests\Feature\Hooks\EndToEndTestCase;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\FreshSchema;

/**
 * A contract test of the Laravel internals that the commit deferral reads:
 * the transactions that Laravel's own DatabaseQueue methods
 * begin, and the first statement of each. The hooks pass `ownBegins` 1 for
 * pop() and deleteAndRelease() and 0 for every insert hook, and the
 * deferral's beforeExecuting witness takes the first statement of the own
 * transaction as its record. It runs Laravel's base queue class, so it pins
 * the parent's behaviour on every matrix leg.
 */
#[FreshSchema]
final class QueueCallGraphTest extends EndToEndTestCase
{
    /**
     * Rejects: a Laravel change that would make the begin counter or the
     * own-record witness wrong (a second transaction in pop() or
     * deleteAndRelease(), a first statement that is not the locking read,
     * or a transaction in an insert hook).
     */
    public function test_own_transactions_of_pop_and_delete_and_release(): void
    {
        $q = $this->q();
        $queue = new LaravelDatabaseQueue($q, 'jobs', 'default', 90);
        $queue->setContainer($this->app);
        $queue->setConnectionName(self::QUEUE);

        /** @var ArrayObject<int, array{level: int, statements: list<string>}> $transactions */
        $transactions = new ArrayObject;
        $q->beforeStartingTransaction(static function (Connection $connection) use ($transactions): void {
            $transactions[] = ['level' => $connection->transactionLevel(), 'statements' => []];
        });
        $q->beforeExecuting(static function (string $query, array $bindings, Connection $connection) use ($transactions): void {
            $last = count($transactions) - 1;

            if ($last >= 0 && $connection->transactionLevel() > 0) {
                $entry = $transactions[$last];
                $entry['statements'][] = $query;
                $transactions[$last] = $entry;
            }
        });

        $insertHooks = [
            'push' => static fn () => $queue->push(new BasicJob),
            'later' => static fn () => $queue->later(60, new BasicJob),
            'pushRaw' => static fn () => $queue->pushRaw((string) json_encode(['uuid' => '6a0f3bb2-6a51-4a17-8fd3-4f43c8f7f7c1', 'job' => 'x', 'data' => []])),
            'bulk' => static fn () => $queue->bulk([new BasicJob, new BasicJob]),
        ];

        foreach ($insertHooks as $hook => $call) {
            $call();
            self::assertCount(0, $transactions, "{$hook}() begins no transaction.");
        }

        $q->table('jobs')->delete();
        $queue->push(new BasicJob);

        $job = $queue->pop();
        self::assertInstanceOf(DatabaseJob::class, $job);
        $this->assertOneTransaction($transactions, 'pop()');
        $transactions->exchangeArray([]);

        $job->release(0);
        $this->assertOneTransaction($transactions, 'deleteAndRelease()');
        $transactions->exchangeArray([]);

        // Inside a transaction of the caller, each one is one savepoint.
        $q->beginTransaction();
        $transactions->exchangeArray([]);
        $job = $queue->pop();
        self::assertInstanceOf(DatabaseJob::class, $job);
        self::assertCount(1, $transactions, 'pop() inside a transaction begins one savepoint.');
        self::assertSame(1, $transactions[0]['level'], 'It begins at the caller\'s level.');
        $q->rollBack();
    }

    /**
     * @param  ArrayObject<int, array{level: int, statements: list<string>}>  $transactions
     */
    private function assertOneTransaction(ArrayObject $transactions, string $method): void
    {
        self::assertCount(1, $transactions, "{$method} begins exactly one transaction.");
        self::assertSame(0, $transactions[0]['level'], "{$method} begins its own root.");
        $first = strtolower(ltrim($transactions[0]['statements'][0] ?? ''));
        self::assertStringStartsWith('select', $first, "The first statement of {$method}'s transaction is a read.");
        self::assertStringContainsString('jobs', $first, 'It reads the jobs table.');

        if ($this->testEnvironment()->driver !== 'sqlite') {
            self::assertStringContainsString('for update', $first, 'It is a locking read.');
        }
    }
}
