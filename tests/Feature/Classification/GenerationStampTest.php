<?php

namespace Justrau\Orrery\Tests\Feature\Classification;

use Illuminate\Queue\DatabaseQueue as LaravelDatabaseQueue;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Justrau\Orrery\DatabaseQueue;
use Justrau\Orrery\Tests\Feature\Classification\Jobs\ClassifiedJob;
use Justrau\Orrery\Tests\Feature\Classification\Jobs\ReleasingOnceJob;
use Justrau\Orrery\Tests\TestCase;

class GenerationStampTest extends TestCase
{
    private const GENERATION = '/^[0-9a-f]{32}$/';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if ($this->name() === 'test_swap_off_push_raw_is_not_restamped') {
            $app['config']->set('orrery.swap_connector', false);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        ReleasingOnceJob::$rawBodies = [];
    }

    protected function tearDown(): void
    {
        ReleasingOnceJob::$rawBodies = [];

        parent::tearDown();
    }

    protected function workerOptions(): WorkerOptions
    {
        $options = parent::workerOptions();
        $options->maxTries = 3;

        return $options;
    }

    /**
     * Rejects: a stamp only in push().
     */
    public function test_push_later_and_bulk_payloads_carry_a_32_hex_generation(): void
    {
        Queue::push(new ClassifiedJob(['push']));
        Queue::later(60, new ClassifiedJob(['later']));
        Queue::bulk([new ClassifiedJob(['bulk-1']), new ClassifiedJob(['bulk-2'])]);

        $payloads = $this->payloads();
        $this->assertSame([['push'], ['later'], ['bulk-1'], ['bulk-2']], array_column($payloads, 'tags'));

        foreach ($payloads as $payload) {
            $this->assertMatchesRegularExpression(self::GENERATION, $payload['orreryGen'] ?? '');
        }

        $this->assertCount(4, array_unique(array_column($payloads, 'orreryGen')));
    }

    /**
     * Rejects: a per-process generation.
     */
    public function test_each_push_gets_a_new_generation(): void
    {
        $job = new ClassifiedJob(['same']);

        Queue::push($job);
        Queue::push($job);
        Queue::push($job);

        $generations = array_column($this->payloads(), 'orreryGen');

        $this->assertCount(3, $generations);
        $this->assertCount(3, array_unique($generations));
    }

    /**
     * Rejects: keeping the old orreryGen or orreryRetry.
     */
    public function test_native_push_raw_restamps_and_removes_a_stale_retry_key(): void
    {
        $this->assertInstanceOf(DatabaseQueue::class, Queue::connection('database'));

        $old = str_repeat('a', 32);
        $original = [
            'uuid' => (string) Str::uuid(),
            'displayName' => ClassifiedJob::class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['commandName' => ClassifiedJob::class, 'command' => 'O:8:"stdClass":0:{}', 'options' => new \stdClass],
            'type' => 'job',
            'tags' => ['kept'],
            'tagsTruncated' => false,
            'silenced' => true,
            'pushedAt' => '1790000000.123456',
            'orreryGen' => $old,
            'orreryRetry' => ['cycle' => 2, 'token' => str_repeat('b', 64), 'replaces' => 17],
        ];

        $id = Queue::pushRaw(json_encode($original, JSON_UNESCAPED_UNICODE));

        $raw = DB::table('jobs')->where('id', $id)->value('payload');
        $stored = json_decode($raw, true);

        $this->assertMatchesRegularExpression(self::GENERATION, $stored['orreryGen']);
        $this->assertNotSame($old, $stored['orreryGen']);
        $this->assertArrayNotHasKey('orreryRetry', $stored);

        // Every other key stays, and an empty JSON object stays an object.
        $expected = $original;
        unset($expected['orreryRetry'], $expected['orreryGen'], $stored['orreryGen']);
        $expected['data']['options'] = [];
        $this->assertSame($expected, $stored);
        $this->assertStringContainsString('"options":{}', $raw);

        // A payload that is not a JSON object goes to the parent unchanged.
        $notJson = Queue::pushRaw('not json');
        $this->assertSame('not json', DB::table('jobs')->where('id', $notJson)->value('payload'));
        $list = Queue::pushRaw('[1,2]');
        $this->assertSame('[1,2]', DB::table('jobs')->where('id', $list)->value('payload'));
    }

    /**
     * Rejects: a stamp in Laravel's own DatabaseQueue.
     */
    public function test_swap_off_push_raw_is_not_restamped(): void
    {
        $queue = Queue::connection('database');
        $this->assertInstanceOf(LaravelDatabaseQueue::class, $queue);
        $this->assertNotInstanceOf(DatabaseQueue::class, $queue);

        $payload = json_encode([
            'uuid' => (string) Str::uuid(),
            'displayName' => 'Raw',
            'job' => 'Raw@handle',
            'data' => [],
            'orreryGen' => str_repeat('c', 32),
            'orreryRetry' => ['cycle' => 2, 'token' => str_repeat('d', 64), 'replaces' => null],
        ]);

        $id = Queue::pushRaw($payload);

        $this->assertSame($payload, DB::table('jobs')->where('id', $id)->value('payload'));

        // The payload callback runs in both swap modes.
        $pushed = Queue::push(new ClassifiedJob(['swap-off']));
        $stored = json_decode(DB::table('jobs')->where('id', $pushed)->value('payload'), true);
        $this->assertMatchesRegularExpression(self::GENERATION, $stored['orreryGen']);
        $this->assertSame(['swap-off'], $stored['tags']);
    }

    /**
     * Rejects: a release that re-encodes the payload.
     */
    public function test_release_keeps_the_payload_byte_identical(): void
    {
        Queue::push(new ReleasingOnceJob);
        $pushed = DB::table('jobs')->value('payload');

        $this->work();

        $this->assertCount(1, ReleasingOnceJob::$rawBodies);
        $reserved = ReleasingOnceJob::$rawBodies[0];
        $released = DB::table('jobs')->sole();

        $this->assertSame($pushed, $reserved);
        $this->assertSame($reserved, $released->payload);
        $this->assertSame(1, (int) $released->attempts);

        $this->work();

        $this->assertSame([$pushed, $pushed], ReleasingOnceJob::$rawBodies);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payloads(): array
    {
        return DB::table('jobs')->orderBy('id')->pluck('payload')
            ->map(static fn (string $payload): array => json_decode($payload, true))
            ->values()
            ->all();
    }
}
