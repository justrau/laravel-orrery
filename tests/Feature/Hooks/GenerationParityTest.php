<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Justrau\Orrery\Tests\Feature\Classification\Jobs\NestingJob;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Jobs\FailingJob;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Generation parity before a worker runs.
 * Other tests cover bulk and the parity of a
 * native retry after R5.
 */
final class GenerationParityTest extends HooksTestCase
{
    /**
     * Rejects: a generation stamped after the insert, or a mirror payload that differs from the queue payload.
     */
    #[DataProvider('firstPublications')]
    public function test_first_publications_match_the_queue_row_before_a_worker_runs(string $publication, int $rows): void
    {
        match ($publication) {
            'push' => Queue::push(new BasicJob),
            'later' => Queue::later(60, new BasicJob),
            'nested' => Queue::push(new NestingJob),
            'pushRaw' => $this->queue()->pushRaw(self::rawPayload(strtolower((string) Str::uuid()), ['orreryGen' => str_repeat('c', 32)])),
        };

        $jobs = DB::table('jobs')->orderBy('id')->get();
        self::assertCount($rows, $jobs);
        self::assertSame($rows, $this->mirrorCount(), 'Each publication has its own mirror row.');

        foreach ($jobs as $job) {
            $payload = (string) $job->payload;
            $data = json_decode($payload, true);
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) ($data['orreryGen'] ?? ''), 'The queue row carries a generation.');

            $row = $this->rowOrFail(self::uuidOf($payload));
            self::assertSame('pending', $row->status);
            self::assertSame((int) $job->id, (int) $row->job_id);
            self::assertSame($data['orreryGen'], $row->cycle_gen, 'The mirror generation is the queue row\'s generation.');
            self::assertSame($payload, $row->payload, 'The mirror payload is the queue row\'s payload, byte for byte.');
        }

        if ($publication === 'pushRaw') {
            self::assertNotSame(str_repeat('c', 32), json_decode((string) $jobs[0]->payload, true)['orreryGen'], 'The republish got a new generation.');
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function firstPublications(): array
    {
        return [
            'push' => ['push', 1],
            'later' => ['later', 1],
            'nested dispatch during classification' => ['nested', 2],
            'native pushRaw of a uuid without a mirror row' => ['pushRaw', 1],
        ];
    }

    /**
     * Rejects: a republish that changes a failed row.
     */
    public function test_native_retry_of_a_failed_row_keeps_the_failed_cycle_before_its_first_reservation(): void
    {
        Queue::push(new FailingJob);
        $original = $this->onlyJobsRow();
        $uuid = self::uuidOf($original['payload']);
        $this->workAndLogFailures();

        $failed = $this->rowOrFail($uuid);
        self::assertSame('failed', $failed->status);
        self::assertSame($original['data']['orreryGen'], $failed->cycle_gen);

        // The failed payload carries an old package retry token.
        $record = DB::table('failed_jobs')->where('uuid', $uuid)->first();
        self::assertNotNull($record);
        $payload = json_decode((string) $record->payload, true);
        $payload['orreryRetry'] = ['cycle' => 2, 'token' => str_repeat('d', 64)];
        DB::table('failed_jobs')->where('uuid', $uuid)->update(['payload' => json_encode($payload)]);

        self::assertSame(0, Artisan::call('queue:retry', ['id' => ['all']]));

        $retried = $this->onlyJobsRow();
        self::assertSame($uuid, self::uuidOf($retried['payload']));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) ($retried['data']['orreryGen'] ?? ''));
        self::assertNotSame($original['data']['orreryGen'], $retried['data']['orreryGen'], 'The native republish has a new generation.');
        self::assertArrayNotHasKey('orreryRetry', $retried['data'], 'The stale package retry token is removed.');

        $row = $this->rowOrFail($uuid);
        self::assertSame('failed', $row->status, 'The failed row keeps its failed cycle until R5.');
        self::assertSame(1, (int) $row->cycle);
        self::assertSame($failed->cycle_gen, $row->cycle_gen, 'The republish did not change cycle_gen.');
        self::assertSame($failed->payload, $row->payload, 'The republish did not change the payload.');
        self::assertSame((int) $failed->job_id, (int) $row->job_id);
    }
}
