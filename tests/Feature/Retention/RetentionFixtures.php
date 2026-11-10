<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retention;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Justrau\Orrery\Lifecycle\IncidentCleanup;
use Justrau\Orrery\Maintenance\Retention;
use Justrau\Orrery\Monitoring\MonitoredTagCache;
use Justrau\Orrery\Storage\Clock;

/**
 * The fixtures of the retention and monitoring tests: mirror rows
 * written by hand, their dependent rows (an incident row too), and
 * the tick. The class that uses this trait extends Tests\TestCase.
 */
trait RetentionFixtures
{
    protected function tick(): void
    {
        self::assertSame(0, Artisan::call('orrery:tick'), Artisan::output());
    }

    protected function retention(): Retention
    {
        return $this->app->make(Retention::class);
    }

    /**
     * A mirror row written by hand. The defaults: a completed, untagged job
     * of database:default that finished now, inserted on $on (default:
     * the mirror connection).
     *
     * @param  array<string, mixed>  $columns
     */
    protected function jobRow(array $columns = [], ?Connection $on = null): string
    {
        $uuid = strtolower((string) ($columns['uuid'] ?? Str::uuid()));
        $now = Clock::now();
        $finished = array_key_exists('finished_at', $columns) ? $columns['finished_at'] : $now;

        $row = array_replace([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'name' => 'App\\Jobs\\Retained',
            'type' => 'job',
            'status' => 'completed',
            'silenced' => false,
            'monitored' => false,
            'monitor_version' => null,
            'payload' => '{}',
            'payload_bytes' => 2,
            'pushed_at' => $finished ?? $now,
            'finished_at' => $finished,
            'updated_at' => $now,
        ], $columns, ['uuid' => $uuid]);

        foreach (['pushed_at', 'finished_at', 'updated_at', 'failed_at', 'reserved_at'] as $time) {
            if (isset($row[$time]) && is_float($row[$time])) {
                $row[$time] = Clock::toDecimal($row[$time]);
            }
        }

        ($on ?? $this->mirror())->table('orrery_jobs')->insert($row);

        return $uuid;
    }

    /**
     * @param  list<string>  $tags
     */
    protected function tagRows(string $uuid, array $tags, ?float $pushedAt = null): void
    {
        foreach ($tags as $tag) {
            $this->mirror()->table('orrery_job_tags')->insert(['tag' => $tag, 'job_uuid' => $uuid, 'pushed_at' => Clock::toDecimal($pushedAt ?? Clock::now())]);
        }
    }

    /**
     * @param  list<string>  $tags
     */
    protected function failedTagRows(string $uuid, array $tags, ?float $finishedAt = null): void
    {
        foreach ($tags as $tag) {
            $this->mirror()->table('orrery_failed_tags')->insert(['tag' => $tag, 'job_uuid' => $uuid, 'finished_at' => Clock::toDecimal($finishedAt ?? Clock::now())]);
        }
    }

    protected function historyRow(string $uuid, int $cycle = 1, string $event = 'exception'): void
    {
        $this->mirror()->table('orrery_job_history')->insert([
            'job_uuid' => $uuid,
            'cycle' => $cycle,
            'attempt' => 1,
            'event' => $event,
            'dedupe_key' => "{$event}:{$cycle}:1",
            'occurred_at' => Clock::toDecimal(Clock::now()),
        ]);
    }

    protected function summaryRow(string $uuid, int $cycle = 1): void
    {
        $this->mirror()->table('orrery_cycle_summaries')->insert(['job_uuid' => $uuid, 'cycle' => $cycle, 'outcome' => 'failed']);
    }

    /**
     * A finished forget operation of the uuid in the ledger.
     */
    protected function operationRow(string $uuid): void
    {
        $now = Clock::toDecimal(Clock::now());
        $this->mirror()->table('orrery_operations')->insert([
            'id' => substr(hash('sha256', $uuid), 0, 32), 'action' => 'forget', 'state' => 'done', 'mode' => 'none',
            'job_uuid' => $uuid, 'occurrence_id' => 1, 'queue_connection' => 'database', 'queue' => 'default',
            'queue_fingerprint' => str_repeat('a', 64), 'deadline' => $now, 'next_check_at' => $now, 'source' => 'cli',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    protected function incidentRow(string $uuid): void
    {
        // Delivered: the delivery deletes an undelivered incident of a job that left its signal's state.
        $this->mirror()->table(IncidentCleanup::TABLE)->insert(['job_uuid' => $uuid, 'cycle' => 1, 'signal' => 'outcome_not_observed', 'first_seen_at' => Clock::toDecimal(Clock::now()), 'evidence' => '{"gap":1}', 'delivered_at' => Clock::toDecimal(Clock::now())]);
    }

    /**
     * The rows of each table that name the uuid.
     *
     * @return array<string, int>
     */
    protected function rowsOf(string $uuid): array
    {
        $counts = ['jobs' => $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->count()];

        foreach (['orrery_job_tags', 'orrery_failed_tags', 'orrery_job_history', 'orrery_cycle_summaries', 'orrery_operations'] as $table) {
            $counts[$table] = $this->mirror()->table($table)->where('job_uuid', $uuid)->count();
        }

        if ($this->mirrorSchema()->hasTable(IncidentCleanup::TABLE)) {
            $counts[IncidentCleanup::TABLE] = $this->mirror()->table(IncidentCleanup::TABLE)->where('job_uuid', $uuid)->count();
        }

        return $counts;
    }

    protected function exists(string $uuid): bool
    {
        return $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->exists();
    }

    /**
     * The committed monitoring version V (absent: 0).
     */
    protected function version(): int
    {
        $value = $this->mirror()->table('orrery_settings')->where('key', MonitoredTagCache::SETTING)->value('value');

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Sets the monitored tags and V by hand.
     *
     * @param  list<string>  $tags
     */
    protected function setMonitoring(array $tags, int $version): void
    {
        $this->mirror()->table('orrery_monitored_tags')->delete();

        foreach ($tags as $tag) {
            $this->mirror()->table('orrery_monitored_tags')->insert(['tag' => $tag, 'created_at' => Clock::toDecimal(Clock::now())]);
        }

        $this->mirror()->table('orrery_settings')->updateOrInsert(['key' => MonitoredTagCache::SETTING], ['value' => (string) $version]);
        MonitoredTagCache::flush();
    }
}
