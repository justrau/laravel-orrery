<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\Feature\Lifecycle\CycleTestCase;
use Justrau\Orrery\Tests\Feature\Monitoring\Jobs\DeadlineJob;
use Justrau\Orrery\Tests\Feature\Monitoring\Jobs\RateLimitedJob;

/**
 * The stuck signals of real jobs (the earlier
 * stuck cases re-based): jobs run by Laravel's own worker in this process
 * (TestCase::work()), and the signals read through GET /api/jobs/stuck and
 * the job detail. The time is frozen and moved by the test, so a release
 * delay never needs a sleep.
 */
final class StuckScenariosTest extends CycleTestCase
{
    private int $workerTries = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp(1_790_000_000));
        Orrery::auth(static fn (): bool => true);
    }

    /**
     * Rejects: releases by Laravel's RateLimited middleware that are not
     * counted (the signal never shows), a payload maxTries of 0 overridden
     * by the worker's --tries=1 (the job would fail, and "attempts not
     * capped" would not show), and signals listed before their thresholds.
     */
    public function test_rate_limited_releases_and_max_tries_zero_with_worker_tries_one(): void
    {
        RateLimiter::for(RateLimitedJob::LIMITER, static fn (): Limit => Limit::perHour(1));
        Queue::push(new RateLimitedJob);
        $uuid = self::uuidOf($this->onlyJobsRow()['payload']);

        $this->workAndAdvance(19);
        $row = $this->rowOrFail($uuid);
        self::assertSame('pending', $row->status);
        self::assertSame(19, (int) $row->releases);
        self::assertSame([], $this->stuckIds('repeated_releases'), 'below the threshold');
        self::assertSame([], $this->stuckIds('attempts_not_capped'));

        $this->workAndAdvance(1);
        $row = $this->rowOrFail($uuid);
        self::assertSame('pending', $row->status, 'the payload maxTries 0 wins over the worker --tries=1');
        self::assertSame(20, (int) $row->attempts);
        self::assertSame(20, (int) $row->releases, 'every attempt after the first was released by the middleware');
        self::assertSame([$uuid], $this->stuckIds('repeated_releases'));
        self::assertSame([$uuid], $this->stuckIds('attempts_not_capped'));

        $detail = $this->detail($uuid);
        self::assertSame(['repeated_releases', 'attempts_not_capped'], array_column($detail->json('signals'), 'signal'));
        self::assertSame(['attempts' => 20, 'rule' => 'none'], $detail->json('signals.1.facts'));
        self::assertSame('none', $detail->json('enforced_rule.kind'));
        self::assertSame(0, $detail->json('enforced_rule.tries'));
        self::assertSame('payload', $detail->json('enforced_rule.tries_source'));
    }

    /**
     * Rejects: an implementation that treats the payload's maxTries (2) as
     * the enforced limit while a retry deadline is set: Laravel keeps
     * running the job past 20 attempts and 20 releases, and the signal
     * "attempts not capped" shows with the deadline.
     */
    public function test_a_future_retry_until_keeps_the_attempts_uncapped(): void
    {
        $this->workerTries = 3;
        $deadline = Carbon::now()->getTimestamp() + 3600;
        Queue::push(new DeadlineJob(2, $deadline, 'alternate'));
        $uuid = self::uuidOf($this->onlyJobsRow()['payload']);

        $this->workAndAdvance(41);
        $row = $this->rowOrFail($uuid);
        self::assertSame('pending', $row->status, 'Laravel does not enforce the attempt count before the deadline');
        self::assertSame(41, (int) $row->attempts);
        self::assertGreaterThanOrEqual(20, (int) $row->releases);
        self::assertGreaterThanOrEqual(20, (int) $row->exceptions);

        self::assertSame([$uuid], $this->stuckIds('attempts_not_capped'));
        self::assertSame([$uuid], $this->stuckIds('repeated_releases'));
        $detail = $this->detail($uuid);
        self::assertContains(['signal' => 'attempts_not_capped', 'facts' => ['attempts' => 41, 'rule' => 'deadline', 'deadline' => $deadline]], $detail->json('signals'));
        self::assertSame('deadline', $detail->json('enforced_rule.kind'));
        self::assertSame($deadline, $detail->json('enforced_rule.deadline'));
        self::assertSame(2, $detail->json('enforced_rule.tries'), 'shown as not enforced until the deadline');
    }

    /**
     * Rejects: an implementation that ignores a deadline once it has passed:
     * while the job waits after its release, the rule reads "deadline
     * passed" and "attempts not capped" does not show; the next reservation
     * fails the job after far fewer than its 100 tries.
     */
    public function test_an_expired_retry_until_removes_the_signal_and_fails_the_job(): void
    {
        $deadline = Carbon::now()->getTimestamp() + 600;
        Queue::push(new DeadlineJob(100, $deadline));
        $uuid = self::uuidOf($this->onlyJobsRow()['payload']);

        $this->workAndAdvance(20);
        self::assertSame([$uuid], $this->stuckIds('attempts_not_capped'));
        self::assertSame('deadline', $this->detail($uuid)->json('enforced_rule.kind'));

        Carbon::setTestNow(Carbon::createFromTimestamp($deadline + 1));
        self::assertSame([], $this->stuckIds('attempts_not_capped'), 'Laravel fails the job at its next check');
        self::assertSame([$uuid], $this->stuckIds('repeated_releases'), 'the releases still show');
        $detail = $this->detail($uuid);
        self::assertSame('deadline_passed', $detail->json('enforced_rule.kind'));
        self::assertNotContains('attempts_not_capped', array_column($detail->json('signals'), 'signal'));

        $this->work();
        $row = $this->rowOrFail($uuid);
        self::assertSame('failed', $row->status);
        self::assertSame(MaxAttemptsExceededException::class, $row->exception_class);
        self::assertLessThan(100, (int) $row->attempts);
        self::assertSame('deadline_passed', $this->detail($uuid)->json('cycles.0.enforced_rule.kind'));
        self::assertSame([], $this->stuckIds('repeated_releases'), 'a failed job has no stuck signal');
    }

    /**
     * Rejects: the gap formula without late outcomes (late
     * outcomes): a reservation expires, a second worker reclaims the job,
     * attempt 2's release arrives before attempt 1's exception, then attempt
     * 3 is reserved. Every attempt has an outcome, so "outcome not observed"
     * does not show. Without attempt 1's late exception it shows with gap 1.
     */
    public function test_late_outcomes_give_no_gap(): void
    {
        foreach ([true, false] as $late) {
            $uuid = $this->uuid();
            $this->observe('push', $uuid, ['jobId' => 10]);
            $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 1]);
            $this->observe('reserve', $uuid, ['jobId' => 10, 'attempt' => 2]);
            $this->observe('release', $uuid, ['jobId' => 10, 'attempt' => 2, 'newJobId' => 11, 'delay' => 0]);

            if ($late) {
                $this->observe('exception', $uuid, ['jobId' => 10, 'attempt' => 1, 'exception' => new \RuntimeException('late')]);
            }

            $this->observe('reserve', $uuid, ['jobId' => 11, 'attempt' => 3]);
            self::assertSame('reserved', $this->rowOrFail($uuid)->status);

            $signals = array_column($this->detail($uuid)->json('signals'), 'signal');
            $listed = in_array($uuid, $this->stuckIds('outcome_not_observed'), true);

            if ($late) {
                self::assertNotContains('outcome_not_observed', $signals, 'a late outcome closes the gap');
                self::assertFalse($listed);
            } else {
                self::assertContains(['signal' => 'outcome_not_observed', 'facts' => ['gap' => 1]], $this->detail($uuid)->json('signals'));
                self::assertTrue($listed);
            }
        }
    }

    /**
     * Rejects: a "repeated releases" list read from the attempts range
     * (st_attempts with attempts >= threshold): after a reclaim, two
     * lineages release side by side, the row reaches 4 releases at 3
     * attempts, and the detail and the list must agree.
     */
    public function test_two_lineages_after_a_reclaim_are_listed_as_the_detail_shows(): void
    {
        $this->app['config']->set('orrery.stuck.release_threshold', 4);
        $uuid = $this->uuid();
        $this->observe('push', $uuid, ['jobId' => 1]);
        $this->observe('reserve', $uuid, ['jobId' => 1, 'attempt' => 1]);
        $this->observe('reserve', $uuid, ['jobId' => 1, 'attempt' => 2]);
        $this->observe('release', $uuid, ['jobId' => 1, 'attempt' => 1, 'newJobId' => 2, 'delay' => 0]);
        $this->observe('release', $uuid, ['jobId' => 1, 'attempt' => 2, 'newJobId' => 3, 'delay' => 0]);
        $this->observe('reserve', $uuid, ['jobId' => 2, 'attempt' => 2]);
        $this->observe('release', $uuid, ['jobId' => 2, 'attempt' => 2, 'newJobId' => 4, 'delay' => 0]);
        $this->observe('reserve', $uuid, ['jobId' => 3, 'attempt' => 3]);
        $this->observe('release', $uuid, ['jobId' => 3, 'attempt' => 3, 'newJobId' => 5, 'delay' => 0]);
        $this->observe('reserve', $uuid, ['jobId' => 4, 'attempt' => 3]);
        $this->observe('release', $uuid, ['jobId' => 4, 'attempt' => 3, 'newJobId' => 6, 'delay' => 0]);

        $row = $this->rowOrFail($uuid);
        self::assertSame(4, (int) $row->releases);
        self::assertSame(3, (int) $row->attempts);
        self::assertContains(['signal' => 'repeated_releases', 'facts' => ['releases' => 4]], $this->detail($uuid)->json('signals'));
        self::assertSame([$uuid], $this->stuckIds('repeated_releases'), 'the list shows what the detail shows');
    }

    protected function workerOptions(): WorkerOptions
    {
        $options = parent::workerOptions();
        $options->maxTries = $this->workerTries;

        return $options;
    }

    /**
     * Runs $times jobs, moving the frozen time 2 seconds after each, so a
     * 1-second release delay has passed before the next reservation.
     */
    private function workAndAdvance(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->work();
            Carbon::setTestNow(Carbon::now()->addSeconds(2));
        }
    }

    /**
     * @return list<string>
     */
    private function stuckIds(string $signal): array
    {
        $response = $this->actingAs(new User)->getJson('/orrery/api/jobs/stuck?signal='.$signal)->assertOk();

        return array_column($response->json('jobs'), 'id');
    }

    private function detail(string $uuid): TestResponse
    {
        return $this->actingAs(new User)->getJson('/orrery/api/jobs/'.$uuid)->assertOk();
    }
}
