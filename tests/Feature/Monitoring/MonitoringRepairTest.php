<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring;

use Illuminate\Database\Connection;
use Justrau\Orrery\Monitoring\MonitoringRepair;
use Justrau\Orrery\Monitoring\MonitoringVersion;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Retention\RetentionTestCase;
use Justrau\Orrery\Tests\Support\PackageSessionHelpers;

/**
 * Monitoring repair: rows
 * with an old monitor_version are recomputed by their version, whatever
 * their id, and trim never deletes a row before its flag is verified.
 */
final class MonitoringRepairTest extends RetentionTestCase
{
    use PackageSessionHelpers;

    /**
     * Rejects: a delete without the re-applied monitored predicate.
     */
    public function test_reverse_commit_order_is_repaired_before_trim(): void
    {
        $this->skipUnlessDriver('mysql', 'pgsql');
        $this->setMonitoring([], 1);
        $old = Clock::now() - 7200;

        // Transaction A allocates the lower id and stays open. B allocates a
        // higher id and commits first.
        $late = $this->lateSession();
        $late->beginTransaction();

        try {
            $a = $this->lateRow($late, $old, 1);
            $b = $this->jobRow(['finished_at' => $old, 'monitor_version' => 1]);
            $this->tagRows($b, ['vip']);
            $untagged = $this->jobRow(['finished_at' => $old]);

            // A monitoring start and a repair between the two commits.
            $this->app->make(MonitoringVersion::class)->monitor('vip');
            self::assertSame(1, $this->app->make(MonitoringRepair::class)->run(), 'The repair sees only the committed row B.');
            self::assertSame([1, 2], [(int) $this->rowOrFail($b)->monitored, (int) $this->rowOrFail($b)->monitor_version]);

            $late->commit();
        } finally {
            if ($late->transactionLevel() > 0) {
                $late->rollBack();
            }
        }

        self::assertLessThan((int) $this->rowOrFail($b)->id, (int) $this->rowOrFail($a)->id, 'A has the lower id and committed last.');
        self::assertSame([0, 1], [(int) $this->rowOrFail($a)->monitored, (int) $this->rowOrFail($a)->monitor_version], 'A committed with the version of its insert.');

        // Trim at the ordinary cutoff (trim.completed = 60) before any repair.
        $this->retention()->run();

        self::assertTrue($this->exists($a), 'Trim verified the late row inline and kept it.');
        self::assertSame([1, 2], [(int) $this->rowOrFail($a)->monitored, (int) $this->rowOrFail($a)->monitor_version], 'The inline verification wrote the flag and V.');
        self::assertTrue($this->exists($b));
        self::assertFalse($this->exists($untagged), 'Trim deleted the untagged row of the same partition.');
    }

    /**
     * Rejects: a repair without a row limit per tick.
     */
    public function test_repair_recomputes_stale_rows_in_bounded_chunks(): void
    {
        $this->setMonitoring(['vip'], 3);
        $monitored = [];
        $plain = [];

        for ($i = 0; $i < 3; $i++) {
            $monitored[] = $uuid = $this->jobRow(['status' => 'pending', 'finished_at' => null, 'monitor_version' => 1 + $i % 2]);
            $this->tagRows($uuid, ['vip', 'other']);
            $plain[] = $uuid = $this->jobRow(['status' => 'pending', 'finished_at' => null, 'monitored' => true, 'monitor_version' => 2]);
            $this->tagRows($uuid, ['other']);
        }

        $current = $this->jobRow(['status' => 'pending', 'finished_at' => null, 'monitored' => false, 'monitor_version' => 3]);
        $this->tagRows($current, ['vip']);

        MonitoringRepair::useLimitsForTesting(chunk: 2, rows: 4);
        self::assertSame(4, $this->app->make(MonitoringRepair::class)->run(), 'One run repairs at most its row limit, in chunks.');
        self::assertSame(2, $this->mirror()->table('orrery_jobs')->where('monitor_version', '<', 3)->count());

        self::assertSame(2, $this->app->make(MonitoringRepair::class)->run());

        foreach ($monitored as $uuid) {
            self::assertSame([1, 3], [(int) $this->rowOrFail($uuid)->monitored, (int) $this->rowOrFail($uuid)->monitor_version]);
        }

        foreach ($plain as $uuid) {
            self::assertSame([0, 3], [(int) $this->rowOrFail($uuid)->monitored, (int) $this->rowOrFail($uuid)->monitor_version]);
        }

        self::assertSame(0, (int) $this->rowOrFail($current)->monitored, 'A row at the current V is not recomputed.');
        self::assertSame(0, $this->app->make(MonitoringRepair::class)->run());
    }

    /**
     * The repair compares a tag byte for byte, as the hot path does (an
     * isset() on the monitored tags). The trailing-space tag row is written
     * directly: the normalizer never stores one.
     *
     * Rejects: a repair that joins the tags under the column collation
     * alone (MySQL's utf8mb4_bin pads trailing spaces, so `orders ` is
     * monitored for `orders`) or folds case.
     */
    public function test_repair_compares_the_tags_byte_for_byte(): void
    {
        $this->setMonitoring(['orders'], 2);
        $padded = $this->jobRow(['status' => 'pending', 'finished_at' => null, 'monitor_version' => 1]);
        $this->tagRows($padded, ['orders ']);
        $cased = $this->jobRow(['status' => 'pending', 'finished_at' => null, 'monitor_version' => 1]);
        $this->tagRows($cased, ['ORDERS']);
        $exact = $this->jobRow(['status' => 'pending', 'finished_at' => null, 'monitor_version' => 1]);
        $this->tagRows($exact, ['orders']);

        self::assertSame(3, $this->app->make(MonitoringRepair::class)->run());
        self::assertSame([0, 2], [(int) $this->rowOrFail($padded)->monitored, (int) $this->rowOrFail($padded)->monitor_version], 'a padded tag is another tag');
        self::assertSame(0, (int) $this->rowOrFail($cased)->monitored, 'case is kept apart');
        self::assertSame(1, (int) $this->rowOrFail($exact)->monitored, 'the exact tag is monitored');
    }

    /**
     * A second session of the mirror's database, outside the package.
     */
    private function lateSession(): Connection
    {
        $this->defineConnection('b18_late', $this->mirrorSource());
        $connection = $this->db()->connection('b18_late');
        $this->beforeApplicationDestroyed(fn () => $this->db()->purge('b18_late'));

        return $connection;
    }

    /**
     * The insert of a producer whose cached snapshot is V = $version and an
     * empty monitored-tag set: the row and its tag row, in $late's open
     * transaction.
     */
    private function lateRow(Connection $late, float $finishedAt, int $version): string
    {
        $uuid = strtolower((string) \Illuminate\Support\Str::uuid());
        $now = Clock::toDecimal(Clock::now());
        $late->table('orrery_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'name' => 'App\\Jobs\\Late',
            'status' => 'completed',
            'monitored' => false,
            'monitor_version' => $version,
            'payload' => '{}',
            'payload_bytes' => 2,
            'pushed_at' => Clock::toDecimal($finishedAt),
            'finished_at' => Clock::toDecimal($finishedAt),
            'updated_at' => $now,
        ]);
        $late->table('orrery_job_tags')->insert(['tag' => 'vip', 'job_uuid' => $uuid, 'pushed_at' => Clock::toDecimal($finishedAt)]);

        return $uuid;
    }
}
