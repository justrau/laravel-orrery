<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\DatabaseQueue as LaravelDatabaseQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\DatabaseQueue as OrreryDatabaseQueue;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Tests\Support\Fixtures\Metrics\CountsSamples;
use Justrau\Orrery\Tests\Support\Fixtures\RawHandler;
use Justrau\Orrery\Tests\TestCase;
use LogicException;

/**
 * The fixtures of the end-to-end hook tests.
 *
 * The queue connection is `database` on the testbench connection. A test
 * runs with the connector swap off when its method name contains
 * `swap_off`, or when its data set name contains `swap off`: the swap is
 * decided when the queue manager resolves, so defineEnvironment() sets it.
 */
abstract class HooksTestCase extends TestCase
{
    use CountsSamples;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if ($this->swapOff()) {
            $app['config']->set('orrery.swap_connector', false);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        RawHandler::$runs = 0;
    }

    protected function tearDown(): void
    {
        RawHandler::$runs = 0;

        parent::tearDown();
    }

    /**
     * Runs one job through the real queue:work command, which logs a
     * failure in failed_jobs (TestCase::work() does not).
     */
    protected function workAndLogFailures(string $connection = 'database'): void
    {
        self::assertSame(0, Artisan::call('queue:work', ['connection' => $connection, '--once' => true, '--sleep' => 0, '--memory' => 4096, '--tries' => 1]));
    }

    protected function swapOff(): bool
    {
        return str_contains($this->name(), 'swap_off') || str_contains((string) $this->dataName(), 'swap off');
    }

    protected function queue(string $connection = 'database'): LaravelDatabaseQueue
    {
        /** @var QueueContract $queue */
        $queue = $this->app['queue']->connection($connection);

        if (! $queue instanceof LaravelDatabaseQueue) {
            throw new LogicException("The queue connection [{$connection}] is not a database queue.");
        }

        self::assertSame(! $this->swapOff(), $queue instanceof OrreryDatabaseQueue, 'The swap mode of the test is the swap mode of its queue.');

        return $queue;
    }

    /**
     * The mirror row of a uuid.
     */
    protected function row(string $uuid): ?object
    {
        return $this->mirror()->table('orrery_jobs')->where('uuid', strtolower($uuid))->first();
    }

    protected function rowOrFail(string $uuid): object
    {
        $row = $this->row($uuid);
        self::assertNotNull($row, "The mirror row of {$uuid} exists.");

        return $row;
    }

    protected function mirrorCount(): int
    {
        return $this->mirror()->table('orrery_jobs')->count();
    }

    /**
     * The only jobs row, decoded.
     *
     * @return array{id: int, payload: string, data: array<string, mixed>, available_at: int, attempts: int}
     */
    protected function onlyJobsRow(): array
    {
        $rows = DB::table('jobs')->get();
        self::assertCount(1, $rows, 'Laravel\'s jobs table has one row.');
        $row = $rows->first();

        return [
            'id' => (int) $row->id,
            'payload' => (string) $row->payload,
            'data' => (array) json_decode((string) $row->payload, true),
            'available_at' => (int) $row->available_at,
            'attempts' => (int) $row->attempts,
        ];
    }

    /**
     * The counted completions of the samples (the tick's
     * aggregation replaces the metric counters).
     *
     * @return array<string, array{throughput: int, runtime_sum: float}> keyed "scope:name"
     */
    protected function counters(): array
    {
        return $this->countedSamples();
    }

    protected static function uuidOf(string $payload): string
    {
        $uuid = json_decode($payload, true)['uuid'] ?? null;
        self::assertIsString($uuid, 'The payload has a uuid.');

        return strtolower($uuid);
    }

    protected static function unverified(): int
    {
        return Degradation::record()['unverified'] ?? 0;
    }

    protected static function dropped(): int
    {
        return Degradation::record()['dropped'] ?? 0;
    }

    /**
     * A raw payload of another producer: a uuid (when given), `job` and
     * `attempts`, and the extra keys.
     *
     * @param  array<string, mixed>  $extra
     */
    protected static function rawPayload(?string $uuid, array $extra = []): string
    {
        $payload = ($uuid === null ? [] : ['uuid' => $uuid]) + ['job' => RawHandler::class.'@handle', 'attempts' => 0] + $extra;

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
