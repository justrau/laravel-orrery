<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retention;

use Illuminate\Support\Carbon;
use Justrau\Orrery\Maintenance\Retention;
use Justrau\Orrery\Maintenance\RetentionPolicy;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Support\FreshSchema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The retention overrides: `orrery.trim_overrides`
 * is a list of entries with a connection, a queue or both, and trim keys.
 * Per key, the precedence is connection+queue, queue, connection, then
 * `orrery.trim`; a monitored row keeps the larger of its state's effective
 * value and the effective `monitored`.
 */
final class RetentionOverridesTest extends RetentionTestCase
{
    private const GLOBAL = ['completed' => 60, 'silenced' => 30, 'failed' => 600, 'unknown' => 900, 'monitored' => 1000];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('orrery.trim', self::GLOBAL);
        $this->app['config']->set('queue.connections.other', ['driver' => 'database', 'table' => 'other_jobs', 'queue' => 'default']);
        $this->app['config']->set('queue.connections.redis', ['driver' => 'redis', 'queue' => 'default']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function policy(): RetentionPolicy
    {
        return $this->app->make(RetentionPolicy::class);
    }

    /**
     * @param  list<mixed>  $overrides
     */
    private function overrides(array $overrides): void
    {
        $this->app['config']->set('orrery.trim_overrides', $overrides);
    }

    /**
     * Rejects: a precedence that takes the first matching entry for every
     * key (the connection-only entry is listed first), or one that applies
     * an entry's missing keys as 0.
     */
    public function test_each_key_takes_the_most_specific_entry_that_sets_it(): void
    {
        $this->overrides([
            ['connection' => 'database', 'completed' => 4, 'failed' => 44, 'unknown' => 444],
            ['queue' => 'emails', 'completed' => 3, 'failed' => 33],
            ['connection' => 'database', 'queue' => 'emails', 'completed' => 2],
        ]);
        $policy = $this->policy();

        self::assertSame([], $policy->validate());
        self::assertSame(2, $policy->minutes('database', 'emails', 'completed', false), 'connection+queue');
        self::assertSame(33, $policy->minutes('database', 'emails', 'failed', false), 'queue: the connection+queue entry has no failed key');
        self::assertSame(444, $policy->minutes('database', 'emails', 'unknown', false), 'connection: neither queue entry has unknown');
        self::assertSame(30, $policy->minutes('database', 'emails', 'silenced', false), 'global: no entry sets silenced');

        self::assertSame(3, $policy->minutes('other', 'emails', 'completed', false), 'the queue entry on another connection');
        self::assertSame(900, $policy->minutes('other', 'emails', 'unknown', false), 'the connection entry does not match another connection');
        self::assertSame(4, $policy->minutes('database', 'default', 'completed', false), 'the connection entry on another queue');
        self::assertSame(60, $policy->minutes('other', 'default', 'completed', false), 'no entry matches');

        $effective = $policy->effective('database', 'emails');
        self::assertSame(['minutes' => 2, 'rule' => 'connection+queue'], $effective['completed']);
        self::assertSame(['minutes' => 33, 'rule' => 'queue'], $effective['failed']);
        self::assertSame(['minutes' => 444, 'rule' => 'connection'], $effective['unknown']);
        self::assertSame(['minutes' => 30, 'rule' => 'global'], $effective['silenced']);
        self::assertSame(['minutes' => 1000, 'rule' => 'global'], $effective['monitored']);
    }

    /**
     * Rejects: the monitored override alone for a monitored row, or the
     * global `monitored` when an override sets it.
     */
    public function test_a_monitored_row_keeps_the_larger_effective_value(): void
    {
        $this->overrides([
            ['queue' => 'short', 'completed' => 5, 'monitored' => 20],
            ['queue' => 'long', 'failed' => 5000, 'monitored' => 20],
        ]);
        $policy = $this->policy();

        self::assertSame(20, $policy->minutes('database', 'short', 'completed', true), 'max(5, the overridden monitored 20)');
        self::assertSame(5000, $policy->minutes('database', 'long', 'failed', true), 'max(the overridden failed 5000, 20)');
        self::assertSame(1000, $policy->minutes('database', 'default', 'completed', true), 'the global monitored');
    }

    /**
     * Rejects: a map keyed by `connection:queue` split at the first colon,
     * or a case-folding comparison.
     */
    public function test_queue_names_compare_byte_exactly_and_may_contain_a_colon(): void
    {
        $this->overrides([
            ['connection' => 'database', 'queue' => 'a:b', 'completed' => 7],
            ['queue' => 'High', 'completed' => 8],
        ]);
        $policy = $this->policy();

        self::assertSame([], $policy->validate());
        self::assertSame(7, $policy->minutes('database', 'a:b', 'completed', false));
        self::assertSame(60, $policy->minutes('database', 'a', 'completed', false));
        self::assertSame(60, $policy->minutes('database:a', 'b', 'completed', false));
        self::assertSame(8, $policy->minutes('database', 'High', 'completed', false));
        self::assertSame(60, $policy->minutes('database', 'high', 'completed', false));
        self::assertSame(60, $policy->minutes('database', 'High ', 'completed', false));
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function invalidOverrides(): array
    {
        return [
            'not a list' => [['database:emails' => ['completed' => 5]], 'orrery.trim_overrides must be a list'],
            'not an array' => ['emails', 'orrery.trim_overrides must be a list'],
            'an entry that is not an array' => [[5], 'orrery.trim_overrides.0 must be an array'],
            'no connection and no queue' => [[['completed' => 5]], 'orrery.trim_overrides.0 needs a connection, a queue or both'],
            'a queue that is not a string' => [[['queue' => 5, 'completed' => 5]], 'orrery.trim_overrides.0.queue must be a non-empty string'],
            'an empty connection' => [[['connection' => '', 'completed' => 5]], 'orrery.trim_overrides.0.connection must be a non-empty string'],
            'no trim key' => [[['queue' => 'emails']], 'orrery.trim_overrides.0 sets no trim key'],
            'an unknown key' => [[['queue' => 'emails', 'complete' => 5, 'failed' => 5]], 'orrery.trim_overrides.0.complete is not a trim key'],
            'a negative value' => [[['queue' => 'emails', 'failed' => -1]], 'orrery.trim_overrides.0.failed must be a whole number of minutes'],
            'a fraction' => [[['queue' => 'emails', 'failed' => 1.5]], 'orrery.trim_overrides.0.failed must be a whole number of minutes'],
            'another driver' => [[['connection' => 'redis', 'completed' => 5]], 'orrery.trim_overrides.0.connection names the queue connection "redis"'],
            'the same level twice' => [[['queue' => 'emails', 'completed' => 5], ['queue' => 'emails', 'failed' => 5]], 'orrery.trim_overrides.1 repeats the connection and queue of entry 0'],
        ];
    }

    /**
     * Rejects: a validation that accepts any of these entries.
     */
    #[DataProvider('invalidOverrides')]
    public function test_invalid_overrides_give_one_error(mixed $overrides, string $error): void
    {
        $this->app['config']->set('orrery.trim_overrides', $overrides);
        $errors = $this->policy()->validate();

        self::assertCount(1, $errors, json_encode($errors, JSON_THROW_ON_ERROR));
        self::assertStringContainsString($error, $errors[0]);
    }

    /**
     * Rejects: a validation that refuses an entry whose connection is not
     * configured (removed later), or two entries of different levels.
     */
    public function test_valid_overrides_give_no_error(): void
    {
        $this->overrides([
            ['connection' => 'removed', 'completed' => 5],
            ['connection' => 'database', 'queue' => 'emails', 'completed' => '0'],
            ['queue' => 'emails', 'failed' => 20160],
            ['connection' => 'database', 'monitored' => 1],
        ]);
        self::assertSame([], $this->policy()->validate());

        $this->app['config']->set('orrery.trim_overrides', null);
        self::assertSame([], $this->policy()->validate(), 'no overrides (a configuration published before D8)');
    }

    /**
     * Rejects: a shortest() over the global values only, or one that counts
     * an invalid value as 0.
     */
    public function test_shortest_is_the_minimum_over_the_global_value_and_every_override(): void
    {
        $this->overrides([
            ['connection' => 'removed', 'completed' => 5],
            ['queue' => 'emails', 'failed' => 20160, 'unknown' => 'never'],
        ]);

        self::assertSame(5, RetentionPolicy::shortest('completed'), 'also an entry that matches no configured connection');
        self::assertSame(600, RetentionPolicy::shortest('failed'), 'a longer override does not lengthen the horizon');
        self::assertSame(900, RetentionPolicy::shortest('unknown'), 'an invalid value deletes nothing');
        self::assertSame(30, RetentionPolicy::shortest('silenced'));

        $this->app['config']->set('orrery.trim.unknown', 'never');
        self::assertNull(RetentionPolicy::shortest('unknown'), 'no valid value: no horizon');
    }

    /**
     * Interleaved rows of two queues with different overrides: the exact
     * survivors.
     *
     * Rejects: a retention that applies one queue's override to the whole
     * connection, or the global value to both queues.
     */
    public function test_retention_trims_each_queue_by_its_own_effective_value(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_790_000_040, 'UTC'));
        $now = Clock::now();
        $this->overrides([
            ['connection' => 'database', 'queue' => 'stripe', 'completed' => 300],
            ['queue' => 'emails', 'completed' => 10],
        ]);
        $ages = [5, 20, 45, 90, 200, 400];
        $rows = [];

        foreach ($ages as $age) {
            foreach (['stripe', 'emails', 'default'] as $queue) {
                $rows[$queue][$age] = $this->jobRow(['queue' => $queue, 'finished_at' => $now - $age * 60]);
            }
        }

        $this->retention()->run();

        $survivors = [];

        foreach ($rows as $queue => $byAge) {
            foreach ($byAge as $age => $uuid) {
                if ($this->exists($uuid)) {
                    $survivors[$queue][] = $age;
                }
            }
        }

        self::assertSame([
            'stripe' => [5, 20, 45, 90, 200],
            'emails' => [5],
            'default' => [5, 20, 45],
        ], $survivors);
    }

    /**
     * Rejects: an override of 0 read as "no override" (the global value).
     */
    public function test_an_override_of_zero_trims_at_the_next_tick(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_790_000_040, 'UTC'));
        $this->overrides([['queue' => 'emails', 'completed' => 0]]);
        // Older than the counts floor (the current minute - 300 without counts).
        $old = Clock::now() - 400;
        $emails = $this->jobRow(['queue' => 'emails', 'finished_at' => $old]);
        $default = $this->jobRow(['queue' => 'default', 'finished_at' => $old]);

        $this->retention()->run();

        self::assertFalse($this->exists($emails));
        self::assertTrue($this->exists($default));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function nearNames(): array
    {
        return [
            'the override on the lower case' => ['emails', 'Emails'],
            'the override on the upper case' => ['Emails', 'emails'],
            'a trailing space' => ['emails', 'emails '],
        ];
    }

    /**
     * Queue names that a MySQL or MariaDB PAD SPACE, case-insensitive
     * collation folds together are separate partitions with their own
     * cutoffs.
     *
     * Rejects: a partition key from the column collation (the `Emails`
     * partition deletes the `emails` rows at the global 60 minutes).
     */
    #[DataProvider('nearNames')]
    public function test_queues_that_a_collation_folds_together_keep_their_own_retention(string $overridden, string $other): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_790_000_040, 'UTC'));
        $now = Clock::now();
        $this->app['config']->set('orrery.trim.completed', 60);
        $this->overrides([['queue' => $overridden, 'completed' => 43200]]);
        $short = $this->jobRow(['queue' => $other, 'finished_at' => $now - 3 * 3600]);
        $kept = $this->jobRow(['queue' => $overridden, 'finished_at' => $now - 2 * 3600]);

        $this->retention()->run();

        self::assertTrue($this->exists($kept), "the {$overridden} row keeps its 30 days");
        self::assertFalse($this->exists($short), "the {$other} row is trimmed at the global 60 minutes");
    }

    /**
     * A MySQL or MariaDB schema whose queue column still folds case (one
     * created before the byte-exact collation): discovery sees one
     * partition for `Emails` and `emails`, and the byte-exact residual
     * filter keeps that partition's delete to its own name.
     *
     * Rejects: a partition predicate without the residual filter (the
     * `Emails` partition deletes the `emails` row at the global 60 minutes).
     */
    #[FreshSchema]
    public function test_a_folding_queue_column_never_deletes_another_names_rows(): void
    {
        $mirror = $this->mirror();

        if (! in_array($mirror->getDriverName(), ['mysql', 'mariadb'], true)) {
            self::markTestSkipped('Only MySQL and MariaDB columns can fold case. This run uses '.$mirror->getDriverName().'.');
        }

        $mirror->statement('ALTER TABLE `'.$mirror->getTablePrefix().'orrery_jobs` MODIFY `queue` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        Carbon::setTestNow(Carbon::createFromTimestamp(1_790_000_040, 'UTC'));
        $now = Clock::now();
        $this->overrides([['queue' => 'emails', 'completed' => 43200]]);
        // Inserted first: the folded index entries are ordered by id, so discovery names `Emails`.
        $this->jobRow(['queue' => 'Emails', 'finished_at' => $now - 3 * 3600]);
        $kept = $this->jobRow(['queue' => 'emails', 'finished_at' => $now - 2 * 3600]);

        $this->retention()->run();

        self::assertTrue($this->exists($kept), 'the emails row keeps its 30 days');
    }

    /**
     * Two queue connections on one jobs table share their overrides through
     * the primary alias, as the samples and the settings
     * API name them.
     *
     * Rejects: an override that matches the row's connection name only, so
     * jobs pushed through the second alias lose the override.
     */
    public function test_an_override_applies_to_every_alias_of_a_shared_jobs_table(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_790_000_040, 'UTC'));
        $now = Clock::now();
        $this->app['config']->set('queue.connections.database-high', $this->app['config']->get('queue.connections.database'));
        $this->overrides([['connection' => 'database', 'queue' => 'stripe', 'completed' => 43200]]);
        $policy = $this->policy();

        self::assertSame([], $policy->validate());
        self::assertSame(['minutes' => 43200, 'rule' => 'connection+queue'], $policy->effective('database-high', 'stripe')['completed']);

        $stripe = $this->jobRow(['connection' => 'database-high', 'queue' => 'stripe', 'finished_at' => $now - 2 * 86400]);
        $other = $this->jobRow(['connection' => 'database-high', 'queue' => 'default', 'finished_at' => $now - 2 * 86400]);
        $this->retention()->run();

        self::assertTrue($this->exists($stripe), 'the database-high stripe row keeps 30 days');
        self::assertFalse($this->exists($other));

        $this->overrides([
            ['connection' => 'database', 'queue' => 'stripe', 'completed' => 43200],
            ['connection' => 'database-high', 'queue' => 'stripe', 'completed' => 5],
        ]);
        $errors = $policy->validate();
        self::assertCount(1, $errors, 'two aliases of one jobs table at the same level are a duplicate');
        self::assertStringContainsString('orrery.trim_overrides.1 repeats the connection and queue of entry 0', $errors[0]);
    }

    /**
     * A queue connection that cannot be resolved has an unknown jobs table
     * and aliases, so an override with a connection might not reach its
     * rows: the configuration is refused until it is fixed (fail closed).
     *
     * Rejects: a policy that maps the broken connection to itself and
     * trims its stripe row at the global 60 minutes.
     */
    public function test_an_unresolved_queue_connection_stops_retention_while_an_override_names_a_connection(): void
    {
        $this->app['config']->set('queue.connections', array_diff_key((array) $this->app['config']->get('queue.connections'), ['other' => true]));
        $this->app['config']->set('queue.connections.broken', ['driver' => 'database', 'connection' => 'missing', 'table' => 'jobs']);
        $this->overrides([['connection' => 'database', 'queue' => 'stripe', 'completed' => 43200]]);
        $row = $this->jobRow(['connection' => 'broken', 'queue' => 'stripe', 'finished_at' => Clock::now() - 2 * 86400]);

        $errors = $this->policy()->validate();
        self::assertSame(['orrery.trim_overrides: the queue connection "broken" cannot be resolved, so its jobs table and aliases are unknown; fix queue.connections.broken.'], $errors);

        // The retention phase alone: the whole tick's later long-wait phase records its own error for the broken connection.
        self::assertSame(0, $this->retention()->run());

        self::assertTrue($this->exists($row), 'the row survives');
        self::assertSame(Retention::CONFIG_INVALID, Degradation::record()['last_error_operation'] ?? null);

        $this->overrides([['queue' => 'stripe', 'completed' => 43200]]);
        self::assertSame([], $this->policy()->validate(), 'an override without a connection does not depend on the aliases');
    }

    /**
     * Rejects: a minutes() that treats an invalid value as 0 (it would trim
     * every row of the partition at once) instead of refusing.
     */
    public function test_minutes_refuses_an_invalid_value(): void
    {
        $this->app['config']->set('orrery.trim.completed', 'never');

        $this->expectException(\LogicException::class);
        $this->policy()->minutes('database', 'default', 'completed', false);
    }

    /**
     * Rejects: an error message that carries invalid UTF-8 from the
     * configuration (the health response would fail to encode: a 500).
     */
    public function test_errors_are_valid_utf8(): void
    {
        $this->overrides([['queue' => 'emails', "bad\xFFkey" => 5, 'failed' => "x\xFF"]]);
        $errors = $this->policy()->validate();

        self::assertCount(2, $errors);

        foreach ($errors as $error) {
            self::assertTrue(mb_check_encoding($error, 'UTF-8'), $error);
        }

        Orrery::auth(static fn (): bool => true);
        $this->getJson('/orrery/api/health')->assertOk();
    }

    /**
     * Rejects: a retention that runs with an invalid override, or records
     * the old operation name.
     */
    public function test_an_invalid_override_stops_retention_and_is_recorded(): void
    {
        // The whole tick runs: a connection without a jobs table would record its own long-wait error last.
        $this->app['config']->set('queue.connections', array_diff_key((array) $this->app['config']->get('queue.connections'), ['other' => true]));
        $this->overrides([['queue' => 'emails', 'completed' => -1]]);
        $uuid = $this->jobRow(['finished_at' => Clock::now() - 10 * 365 * 86400]);

        $this->tick();

        self::assertTrue($this->exists($uuid), 'an invalid configuration trims nothing');
        self::assertSame(['retention:config_invalid'], $this->mirror()->table('orrery_health_events')->pluck('last_error_operation')->all());
    }
}
