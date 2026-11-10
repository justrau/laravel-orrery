<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retention;

use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Maintenance\RetentionPolicy;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Retention\Jobs\TaggedJob;

/**
 * The retention policy: the effective retention per
 * partition, and its validation at the maintenance entry points only.
 */
final class RetentionPolicyTest extends RetentionTestCase
{
    /**
     * Rejects: the monitored retention alone.
     */
    public function test_monitored_rows_use_the_larger_of_state_and_monitored_retention(): void
    {
        $this->app['config']->set('orrery.trim', ['completed' => 60, 'silenced' => 5, 'failed' => 600, 'unknown' => 900, 'monitored' => 120] + (array) $this->app['config']->get('orrery.trim'));
        $policy = $this->app->make(RetentionPolicy::class);

        self::assertSame(60, $policy->minutes('database', 'default', 'completed', false));
        self::assertSame(5, $policy->minutes('database', 'default', 'silenced', false));
        self::assertSame(600, $policy->minutes('database', 'default', 'failed', false));
        self::assertSame(900, $policy->minutes('database', 'default', 'unknown', false));

        self::assertSame(120, $policy->minutes('database', 'default', 'completed', true), 'a monitored completed row keeps the longer monitored retention');
        self::assertSame(120, $policy->minutes('database', 'default', 'silenced', true));
        self::assertSame(600, $policy->minutes('database', 'default', 'failed', true), 'a monitored failed row keeps its longer state retention');
        self::assertSame(900, $policy->minutes('database', 'default', 'unknown', true));
    }

    /**
     * Rejects: a policy that accepts a negative or missing value.
     */
    public function test_invalid_policies_give_validation_errors(): void
    {
        $policy = $this->app->make(RetentionPolicy::class);
        $valid = ['completed' => 60, 'silenced' => 60, 'failed' => 10080, 'unknown' => 10080, 'monitored' => 10080];

        $this->app['config']->set('orrery.trim', $valid);
        self::assertSame([], $policy->validate(), 'the shipped defaults are valid');

        $this->app['config']->set('orrery.trim', ['completed' => '0'] + $valid);
        self::assertSame([], $policy->validate(), 'a whole number of minutes in a string is valid, 0 included');

        $cases = [
            'negative' => [['completed' => -1] + $valid, 'trim.completed'],
            'missing' => [array_diff_key($valid, ['unknown' => true]), 'trim.unknown'],
            'null' => [['silenced' => null] + $valid, 'trim.silenced'],
            'fraction' => [['failed' => 1.5] + $valid, 'trim.failed'],
            'text' => [['monitored' => 'a week'] + $valid, 'trim.monitored'],
            'negative text' => [['failed' => '-5'] + $valid, 'trim.failed'],
        ];

        foreach ($cases as $case => [$trim, $key]) {
            $this->app['config']->set('orrery.trim', $trim);
            $errors = $policy->validate();

            self::assertCount(1, $errors, "{$case}: one error: ".json_encode($errors));
            self::assertStringContainsString($key, $errors[0], "{$case}: the error names the key");
        }

        $this->app['config']->set('orrery.trim', null);
        self::assertCount(5, $policy->validate(), 'without a trim array, each of the five keys is missing');
    }

    /**
     * Rejects: validation inside a hook (also of the overrides).
     */
    public function test_policy_is_never_validated_in_a_queue_hook(): void
    {
        $this->app['config']->set('orrery.trim', ['completed' => -5, 'failed' => 'never']);
        $this->app['config']->set('orrery.trim_overrides', ['database:default' => ['completed' => -1], 7]);

        $completed = $this->queue()->push(new TaggedJob(['alpha']));
        $failed = $this->queue()->push(new TaggedJob(['beta'], fails: true));
        self::assertIsInt($completed);
        self::assertIsInt($failed);

        $this->workAndLogFailures();
        $this->workAndLogFailures();

        self::assertSame(['completed', 'failed'], $this->mirror()->table('orrery_jobs')->orderBy('id')->pluck('status')->all(), 'the push, the reservations and the outcomes were observed');
        self::assertSame(0, Degradation::record()['dropped'] ?? 0, 'no hook dropped an observation');
        self::assertNull(Degradation::record()['last_error_operation'] ?? null, 'no hook recorded an error');

        // The maintenance entry point validates: tick deletes nothing, and records why.
        Queue::push(new TaggedJob);
        $this->mirror()->table('orrery_jobs')->update(['finished_at' => Clock::toDecimal(Clock::now() - 10 * 365 * 86400)]);
        $this->tick();

        self::assertSame(3, $this->mirror()->table('orrery_jobs')->count(), 'an invalid policy trims nothing');
        self::assertSame(['retention:config_invalid'], $this->mirror()->table('orrery_health_events')->pluck('last_error_operation')->all(), 'the tick records the invalid policy in the health events');
    }
}
