<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Bulk;

use Illuminate\Queue\DatabaseQueue as LaravelDatabaseQueue;
use Justrau\Orrery\Queue\BulkCapture;
use Justrau\Orrery\Tests\Feature\Hooks\EndToEndTestCase;

/**
 * The fixtures of the bulk tests. The queue
 * connection `hooks` uses Q = `hooks_q` (EndToEndTestCase).
 *
 * The swapped bulk() has one capture path on every Laravel release:
 * parent::bulk() runs unchanged. The data sets of paths() name
 * the Laravel lines: the Laravel 13 set runs only on a release whose
 * DatabaseQueue has partitionJobsByAfterCommit() (its bulk() can defer an
 * insert), and the Laravel 12 set runs on every release.
 */
abstract class BulkTestCase extends EndToEndTestCase
{
    protected const PATH_12 = 'laravel 12 path';

    protected const PATH_13 = 'laravel 13 path';

    /**
     * @return array<string, array{string}>
     */
    public static function paths(): array
    {
        return [
            self::PATH_12 => [self::PATH_12],
            self::PATH_13 => [self::PATH_13],
        ];
    }

    protected function usePath(string $path): void
    {
        if ($path === self::PATH_13) {
            $this->requireLaravel13Path();
        }
    }

    protected function requireLaravel13Path(): void
    {
        if (! method_exists(LaravelDatabaseQueue::class, 'partitionJobsByAfterCommit')) {
            self::markTestSkipped('This Laravel release has no DatabaseQueue::partitionJobsByAfterCommit(), so its bulk() never defers an insert (core spec C8).');
        }
    }

    /**
     * Also no pending bulk capture and no open capture.
     */
    protected function assertNothingDeferred(): void
    {
        parent::assertNothingDeferred();

        self::assertSame(0, BulkCapture::pendingCount(), 'No bulk capture is pending.');
        self::assertSame(0, BulkCapture::openCount(), 'No bulk capture is open.');
    }

    /**
     * One bulk() call on the queue connection `hooks`.
     *
     * @param  list<object>  $jobs
     */
    protected function bulk(array $jobs, string $queue = 'default'): mixed
    {
        return $this->hooks()->bulk($jobs, '', $queue);
    }

    /**
     * Every jobs row of Q has its `pending` mirror row with the resolved
     * job_id, the payload's generation and the payload byte for byte; and
     * the mirror has no other row. Returns the rows by jobs.id.
     *
     * @return array<int, object>
     */
    protected function assertPendingParity(string $message = ''): array
    {
        $rows = [];

        foreach ($this->qJobs() as $job) {
            $payload = (string) $job->payload;
            $data = json_decode($payload, true);
            $row = $this->rowOrFail(self::uuidOf($payload));

            self::assertSame('pending', $row->status, $message);
            self::assertSame((int) $job->id, (int) $row->job_id, 'The mirror row names its own jobs row. '.$message);
            self::assertFalse((bool) $row->job_id_unresolved, 'The row is resolved. '.$message);
            self::assertSame($data['orreryGen'] ?? null, $row->cycle_gen, 'The mirror generation is the queue row\'s generation. '.$message);
            self::assertSame($payload, $row->payload, 'The mirror payload is the queue row\'s payload, byte for byte. '.$message);

            $rows[(int) $job->id] = $row;
        }

        self::assertSame(count($rows), $this->mirrorCount(), 'The mirror has one row per jobs row and no other. '.$message);

        return $rows;
    }

    /**
     * The mirror rows that P2 created (they carry a bulk floor).
     */
    protected function bulkRows(): int
    {
        return $this->mirror()->table('orrery_jobs')->whereNotNull('bulk_floor_id')->count();
    }
}
