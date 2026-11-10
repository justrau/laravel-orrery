<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Lists;

use Illuminate\Database\Connection;
use Justrau\Orrery\Storage\Clock;

/**
 * Mirror rows for the list engine tests: orrery_jobs rows
 * with the times of their status, and tag rows. The test class extends
 * Tests\TestCase.
 */
trait ListFixtures
{
    /** The base time of the fixtures. */
    protected const BASE = 1_790_000_000.0;

    /** @var list<array<string, mixed>> */
    private array $pendingRows = [];

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $pendingTags = [];

    protected static function uuidOf(int $n): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $n);
    }

    /**
     * Queues one orrery_jobs row (flushRows() writes them). The status
     * decides the time columns: pushed_at = BASE + $at; reserved_at,
     * finished_at and failed_at at the same time for the statuses that
     * have them.
     *
     * @param  array<string, mixed>  $values
     */
    protected function addRow(int $n, string $status, float $at, array $values = []): void
    {
        $time = Clock::toDecimal(self::BASE + $at);
        $row = [
            'uuid' => self::uuidOf($n),
            'connection' => 'database',
            'queue' => 'default',
            'name' => 'App\\Jobs\\Sync',
            'type' => 'job',
            'status' => $status,
            'silenced' => false,
            'monitored' => false,
            'payload' => '{}',
            'payload_bytes' => 2,
            'attempts' => 1,
            'occurrence_id' => $n,
            'pushed_at' => $time,
            'reserved_at' => $status === 'reserved' ? $time : null,
            'finished_at' => in_array($status, ['completed', 'failed', 'retrying', 'unknown'], true) ? $time : null,
            'failed_at' => in_array($status, ['failed', 'retrying'], true) ? $time : null,
            'updated_at' => $time,
        ];

        $this->pendingRows[] = array_merge($row, $values);
    }

    protected function addTag(int $n, string $tag, float $at, bool $failed = false): void
    {
        $this->pendingTags[] = [self::uuidOf($n), $tag, Clock::toDecimal(self::BASE + $at), $failed ? 'failed' : 'job'];
    }

    protected function flushRows(): void
    {
        $mirror = $this->mirror();
        $columns = [];

        foreach ($this->pendingRows as $row) {
            $columns += array_flip(array_keys($row));
        }

        // One column set per insert, so a multi-row insert has the same columns in every row.
        $rows = array_map(static function (array $row) use ($columns): array {
            foreach (array_keys($columns) as $column) {
                $row[$column] ??= match ($column) {
                    'silenced', 'monitored' => false,
                    default => null,
                };
            }

            ksort($row);

            return $row;
        }, $this->pendingRows);

        // SQLite builds before 3.32 allow 999 bindings per statement; the servers allow far more.
        foreach (array_chunk($rows, $mirror->getDriverName() === 'sqlite' ? 40 : 1000) as $chunk) {
            $mirror->table('orrery_jobs')->insert($chunk);
        }

        foreach (array_chunk($this->pendingTags, 200) as $chunk) {
            $jobTags = [];
            $failedTags = [];

            foreach ($chunk as [$uuid, $tag, $time, $kind]) {
                if ($kind === 'failed') {
                    $failedTags[] = ['tag' => $tag, 'job_uuid' => $uuid, 'finished_at' => $time];
                } else {
                    $jobTags[] = ['tag' => $tag, 'job_uuid' => $uuid, 'pushed_at' => $time];
                }
            }

            if ($jobTags !== []) {
                $mirror->table('orrery_job_tags')->insert($jobTags);
            }

            if ($failedTags !== []) {
                $mirror->table('orrery_failed_tags')->insert($failedTags);
            }
        }

        $this->pendingRows = [];
        $this->pendingTags = [];
    }

    protected function analyze(Connection $connection): void
    {
        $table = $connection->getTablePrefix().'orrery_jobs';

        match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $connection->statement("ANALYZE TABLE `{$table}`, `{$connection->getTablePrefix()}orrery_job_tags`, `{$connection->getTablePrefix()}orrery_failed_tags`"),
            // VACUUM sets the visibility map, as autovacuum does on a live mirror, so index-only scans cost what they cost.
            'pgsql' => $connection->statement('VACUUM ANALYZE'),
            default => $connection->statement('ANALYZE'),
        };
    }
}
