<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Http\Lists\JobList;
use Justrau\Orrery\Http\Lists\ListRequest;
use Justrau\Orrery\Monitoring\MonitoringRepair;
use Justrau\Orrery\Monitoring\MonitoringVersion;
use Justrau\Orrery\Observation\TagNormalizer;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Retention\Jobs\TaggedJob;
use Justrau\Orrery\Tests\Feature\Retention\RetentionTestCase;
use RuntimeException;

/**
 * The monitoring version V: one transaction per monitoring
 * change, no version on an untagged row, synchronous commands, and the
 * normalised tag.
 */
final class MonitoringVersionTest extends RetentionTestCase
{
    /**
     * Rejects: a version change outside the tag table's transaction.
     */
    public function test_monitor_and_stop_change_the_version_in_one_transaction(): void
    {
        $monitoring = $this->app->make(MonitoringVersion::class);
        $mirror = $this->app->make(PackageSession::class)->mirrorName();
        $writes = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$writes, $mirror): void {
            // The creation of the V row (ensureRow()) runs before the transaction, by design.
            if ($event->connectionName === $mirror && preg_match('/^\s*(insert|update|delete)/i', $event->sql) === 1 && preg_match('/^\s*insert.*orrery_settings/i', $event->sql) !== 1) {
                $writes[] = ['sql' => $event->sql, 'in_transaction' => PackageTransaction::isOpen($event->connection)];
            }
        });

        self::assertSame(0, $this->version());
        self::assertTrue($monitoring->monitor('orders'));
        self::assertSame(1, $this->version());
        self::assertSame(['orders'], $this->monitoredTags());

        self::assertFalse($monitoring->monitor('orders'), 'a tag that is monitored already changes nothing');
        self::assertSame(1, $this->version());

        self::assertNotSame([], $writes);
        self::assertSame([], array_values(array_filter($writes, static fn (array $write): bool => ! $write['in_transaction'])), 'Every write of the change runs in its transaction: '.json_encode($writes));

        // A failure after the tag change and before the version write undoes both.
        Hooks::$callback = static function (string $point): void {
            if ($point === MonitoringVersion::BEFORE_VERSION) {
                throw new RuntimeException('between the tag change and the version');
            }
        };

        try {
            $monitoring->stopMonitoring('orders');
            self::fail('The injected failure reaches the caller.');
        } catch (RuntimeException $error) {
            self::assertSame('between the tag change and the version', $error->getMessage());
        } finally {
            Hooks::$callback = null;
        }

        self::assertSame(['orders'], $this->monitoredTags(), 'The failed stop left the tag monitored.');
        self::assertSame(1, $this->version(), 'The failed stop left V unchanged.');

        self::assertTrue($monitoring->stopMonitoring('orders'));
        self::assertSame([], $this->monitoredTags());
        self::assertSame(2, $this->version());
        self::assertFalse($monitoring->stopMonitoring('orders'));
        self::assertSame(2, $this->version());
    }

    /**
     * Rejects: a version on an untagged row.
     */
    public function test_untagged_row_never_gets_a_version(): void
    {
        $this->app->make(MonitoringVersion::class)->monitor('orders');

        Queue::push(new BasicJob);
        $untagged = self::uuidOf((string) DB::table('jobs')->orderByDesc('id')->value('payload'));
        Queue::push(new TaggedJob(['orders']));
        $tagged = self::uuidOf((string) DB::table('jobs')->orderByDesc('id')->value('payload'));

        self::assertSame([], json_decode((string) $this->rowOrFail($untagged)->payload, true)['tags'] ?? null, 'BasicJob carries no tags.');
        self::assertNull($this->rowOrFail($untagged)->monitor_version, 'An untagged row has no version.');
        self::assertFalse((bool) $this->rowOrFail($untagged)->monitored);
        self::assertSame(1, (int) $this->rowOrFail($tagged)->monitor_version, 'A tagged row stores the V of its insert.');

        // A later monitoring change and a repair never give it one.
        $this->app->make(MonitoringVersion::class)->monitor('other');
        $this->tick();

        self::assertNull($this->rowOrFail($untagged)->monitor_version);
        self::assertSame(2, (int) $this->rowOrFail($tagged)->monitor_version, 'The repair verified the tagged row.');
    }

    /**
     * Rejects: a queued monitor job.
     */
    public function test_monitoring_runs_synchronously_on_a_paused_queue(): void
    {
        Orrery::auth(static fn (): bool => true);
        Orrery::operateUsing(static fn (): bool => true);
        self::assertSame(0, Artisan::call('orrery:pause'));

        $this->postJson('/orrery/api/tags/monitored', ['tag' => 'orders'])->assertOk();

        self::assertSame(['orders'], $this->monitoredTags(), 'The tag is monitored when the request returns.');
        self::assertSame(1, $this->version());
        self::assertSame(0, DB::table('jobs')->count(), 'Nothing was queued.');

        $this->deleteJson('/orrery/api/tags/monitored/orders')->assertOk();

        self::assertSame([], $this->monitoredTags(), 'The tag is not monitored when the request returns.');
        self::assertSame(2, $this->version());
        self::assertSame(0, DB::table('jobs')->count(), 'Nothing was queued.');
    }

    /**
     * Rejects: monitoring the raw tag.
     */
    public function test_long_tag_matches_its_monitored_form(): void
    {
        Orrery::auth(static fn (): bool => true);
        Orrery::operateUsing(static fn (): bool => true);
        $raw = str_repeat('é', 150);
        $normalized = TagNormalizer::normalize($raw);
        self::assertIsString($normalized);
        self::assertSame(300, strlen($raw));
        self::assertNotSame($raw, $normalized);

        $this->postJson('/orrery/api/tags/monitored', ['tag' => $raw])->assertOk();
        self::assertSame([$normalized], $this->monitoredTags(), 'The monitored form is the normalised tag.');

        Queue::push(new TaggedJob([$raw]));
        $uuid = self::uuidOf((string) DB::table('jobs')->value('payload'));

        self::assertTrue((bool) $this->rowOrFail($uuid)->monitored, 'The job with the raw tag is monitored.');
        self::assertSame([$normalized], $this->mirror()->table('orrery_job_tags')->where('job_uuid', $uuid)->pluck('tag')->all());

        $this->getJson('/orrery/api/tags')->assertOk()->assertJsonPath('tags', [['tag' => $normalized, 'count' => ['count' => 1, 'at_least' => false], 'failed' => ['count' => 0, 'at_least' => false]]]);
        $this->getJson('/orrery/api/jobs?status=all&tag='.rawurlencode($raw))->assertOk()->assertJsonPath('total', ['count' => 1, 'at_least' => false])->assertJsonPath('jobs.0.id', $uuid);
        $this->getJson('/orrery/api/jobs?status=all&tag='.rawurlencode($normalized))->assertOk()->assertJsonPath('total', ['count' => 1, 'at_least' => false]);

        $this->deleteJson('/orrery/api/tags/monitored/'.rawurlencode($raw))->assertOk();
        self::assertSame([], $this->monitoredTags(), 'Stopping the raw tag stops its monitored form.');
    }

    /**
     * Rejects: a tag comparison that folds case in the database (a MySQL tag column without a binary collation).
     */
    public function test_case_variant_tags_are_different_tags(): void
    {
        $monitoring = $this->app->make(MonitoringVersion::class);
        $tagTotal = fn (string $tag): array => $this->app->make(JobList::class)->total(ListRequest::from(['status' => 'all', 'tag' => $tag]));
        self::assertTrue($monitoring->monitor('vip'));

        Queue::push(new TaggedJob(['VIP']));
        $upper = self::uuidOf((string) DB::table('jobs')->orderByDesc('id')->value('payload'));
        Queue::push(new TaggedJob(['vip']));
        $lower = self::uuidOf((string) DB::table('jobs')->orderByDesc('id')->value('payload'));

        self::assertSame([0, 1], [(int) $this->rowOrFail($upper)->monitored, (int) $this->rowOrFail($upper)->monitor_version], 'The push compares tags exactly: VIP is not monitored.');
        self::assertSame([1, 1], [(int) $this->rowOrFail($lower)->monitored, (int) $this->rowOrFail($lower)->monitor_version]);

        $this->work(2);

        // An unrelated change of V makes both rows unverified; repair and retention must use the same exact rule.
        self::assertTrue($monitoring->monitor('other'));
        Carbon::setTestNow(Carbon::now()->addMinutes(61));
        $this->tick();

        self::assertFalse($this->exists($upper), 'The repair kept VIP unmonitored, so the ordinary cutoff trimmed it.');
        self::assertSame([1, 2], [(int) $this->rowOrFail($lower)->monitored, (int) $this->rowOrFail($lower)->monitor_version], 'The monitored row is verified and kept.');
        self::assertSame([1, 0], [$tagTotal('vip')['count'], $tagTotal('VIP')['count']], 'The tag lookups compare exactly.');

        // A case variant is a monitored tag of its own.
        self::assertTrue($monitoring->monitor('VIP'));
        self::assertEqualsCanonicalizing(['VIP', 'other', 'vip'], $this->monitoredTags(), 'The order of the database collation does not matter.');
        self::assertTrue($monitoring->stopMonitoring('VIP'));
        self::assertSame(['other', 'vip'], $this->monitoredTags());

        if (in_array($this->testEnvironment()->driver, ['mysql', 'mariadb'], true)) {
            foreach (['orrery_job_tags', 'orrery_failed_tags', 'orrery_monitored_tags'] as $table) {
                $collation = $this->mirror()->selectOne('select collation_name from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?', [$this->mirror()->getTablePrefix().$table, 'tag']);
                self::assertSame('utf8mb4_bin', ((array) $collation)['collation_name'] ?? ((array) $collation)['COLLATION_NAME'] ?? null, "{$table}.tag compares bytes.");
            }
        }
    }

    /**
     * Rejects: a locking read of a missing version row, which locks nothing on MySQL and Postgres.
     */
    public function test_the_version_row_exists_before_its_first_lock(): void
    {
        foreach (['retention' => fn () => $this->retention()->run(), 'repair' => fn () => $this->app->make(MonitoringRepair::class)->run(), 'monitor' => fn () => $this->app->make(MonitoringVersion::class)->monitor('orders')] as $caller => $run) {
            $this->mirror()->table('orrery_settings')->where('key', 'monitoring_version')->delete();
            $run();

            self::assertNotNull($this->mirror()->table('orrery_settings')->where('key', 'monitoring_version')->value('value'), "{$caller}: the row of V exists after the first run.");
        }
    }

    /**
     * @return list<string>
     */
    private function monitoredTags(): array
    {
        return $this->mirror()->table('orrery_monitored_tags')->orderBy('tag')->pluck('tag')->map(static fn (mixed $tag): string => (string) $tag)->all();
    }
}
