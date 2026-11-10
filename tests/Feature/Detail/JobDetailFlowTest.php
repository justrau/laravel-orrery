<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Detail;

use Illuminate\Queue\Attributes\CountCrashesAsExceptions;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Detail\Jobs\CrashCountedJob;
use Justrau\Orrery\Tests\Feature\Projection\Jobs\CanaryJob;
use Justrau\Orrery\Tests\Feature\Retry\RetryTestCase;

/**
 * The job detail after real queue work (section 11 "Detail"):
 * failures, retries through the ledger, completions and an unknown cycle,
 * read back through GET /api/jobs/{uuid} and its history; and the canaries
 * of every position in the raw bodies of the three endpoints.
 */
final class JobDetailFlowTest extends RetryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');
        Orrery::auth(static fn (): bool => true);
    }

    /**
     * Fail, retry, fail, retry, complete.
     *
     * Rejects: a retry list that misses a cycle or reads its outcome from
     * the row, a current cycle read from a summary, a terminal explanation
     * for a completed cycle, and a manual fail() explained as a cause
     * (RetryableJob fails itself, with a retry deadline an hour ahead).
     */
    public function test_retry_outcomes_of_fail_retry_fail_retry_complete(): void
    {
        [$uuid, $occurrence] = $this->failWith($this->job([], 2));
        self::assertSame(RetryResult::ACCEPTED, $this->service()->retry($uuid, $occurrence, 'dashboard')->result);
        $occurrence = $this->failAgain($uuid);
        self::assertSame(RetryResult::ACCEPTED, $this->service()->retry($uuid, $occurrence, 'cli')->result);
        $this->work();
        self::assertSame('completed', $this->rowOrFail($uuid)->status);

        $detail = $this->detail($uuid);

        self::assertSame([3, 2, 1], array_column($detail->json('cycles'), 'cycle'));
        self::assertSame(['completed', 'failed', 'failed'], array_column($detail->json('cycles'), 'outcome'));
        self::assertNull($detail->json('cycles.0.terminal'));
        self::assertSame('other', $detail->json('cycles.1.terminal.kind'), 'a manual fail() gives conditions');
        self::assertFalse($detail->json('cycles.1.terminal.facts.deadline_passed'));
        self::assertSame('deadline', $detail->json('cycles.1.enforced_rule.kind'), 'the deadline is enforced at the failure, not the tries');
        self::assertSame('RetryableJob fails at run 1.', $detail->json('cycles.2.exception_message'));

        $retries = $detail->json('retries');
        self::assertSame([[3, 'cli', 'completed'], [2, 'dashboard', 'failed']], array_map(static fn (array $entry): array => [$entry['cycle'], $entry['source'], $entry['outcome']], $retries['entries']));
        self::assertSame(['retained' => 2, 'folded' => 0, 'unrecorded' => 0, 'count' => 2], [
            'retained' => $retries['retries_retained'], 'folded' => $retries['retries_folded'], 'unrecorded' => $retries['retries_unrecorded'], 'count' => $retries['retried_count'],
        ]);

        $events = array_map(static fn (array $entry): array => [$entry['cycle'], $entry['event']], $detail->json('history.entries'));
        self::assertContains([2, 'failed'], $events);
        self::assertContains([1, 'failed'], $events);
        $cycles = array_column($events, 0);
        $sorted = $cycles;
        rsort($sorted);
        self::assertSame($sorted, $cycles, 'newest cycle first');
    }

    /**
     * A retried job whose cycle becomes `unknown` (reconciliation found its
     * job gone) and is then revised to `completed` by its own late run.
     *
     * Rejects: a retry outcome or a current cycle that keeps a stale
     * outcome, and an unknown cycle shown as failed or completed.
     */
    public function test_a_retried_job_that_becomes_unknown_then_completed(): void
    {
        [$uuid, $occurrence] = $this->failWith($this->job());
        self::assertSame(RetryResult::ACCEPTED, $this->service()->retry($uuid, $occurrence, 'dashboard')->result);
        $row = $this->rowOrFail($uuid);
        self::assertSame(2, (int) $row->cycle);
        self::assertTrue($this->app->make(JobStore::class)->markUnknown($uuid, (int) $row->job_id, 2, 'absent', Clock::now()));

        $unknown = $this->detail($uuid);
        self::assertSame('unknown', $unknown->json('status'));
        self::assertSame('unknown', $unknown->json('cycles.0.outcome'));
        self::assertNull($unknown->json('cycles.0.terminal'));
        self::assertSame('unknown', $unknown->json('retries.entries.0.outcome'));

        $this->work();
        self::assertSame('completed', $this->rowOrFail($uuid)->status);

        $completed = $this->detail($uuid);
        self::assertSame('completed', $completed->json('cycles.0.outcome'));
        self::assertSame('completed', $completed->json('retries.entries.0.outcome'));
        self::assertSame(1, $completed->json('retries.retries_retained'));
    }

    /**
     * A release with #[CountCrashesAsExceptions] (13.40 has it, 13.30 does
     * not) fails a job with that attribute through
     * MaxAttemptsExceededException when its counted crashes reach
     * maxExceptions, also at an attempt far below its tries value
     * (Worker::markJobAsFailedIfAlreadyExceedsMaxExceptions()). The worker
     * here is Laravel's own, with its cache; the crash of the earlier
     * attempt is its marker left in the cache, and one crash was counted
     * already.
     *
     * Rejects: "the attempt limit was reached" for that failure (the
     * attempt 1 is within tries 10).
     */
    public function test_a_failure_from_counted_crashes_is_not_the_attempt_limit(): void
    {
        // Version-exact: the worker's check and the attribute come together (13.40 has them, 13.30 does not).
        $counted = method_exists(Worker::class, 'markJobAsFailedIfAlreadyExceedsMaxExceptions');
        self::assertSame($counted, class_exists(CountCrashesAsExceptions::class), 'the worker counts crashes exactly when the attribute exists');

        if (! $counted) {
            self::markTestSkipped('This Laravel release has no #[CountCrashesAsExceptions]: its worker never counts a crash.');
        }

        $this->recordLogFailures();
        $id = $this->app['queue']->connection('database')->push(new CrashCountedJob);
        $payload = (string) $this->queueTable()->where('id', $id)->value('payload');
        $uuid = self::uuidOf($payload);
        self::assertTrue(json_decode($payload, true)['countCrashesAsExceptions'], 'the payload counts crashes');

        $cache = $this->app['cache']->store();
        $cache->put('job-processing:'.$uuid, true, 3600);
        $cache->put('job-exceptions:'.$uuid, 1, 3600);
        $worker = $this->app->make('queue.worker');
        $worker->setCache($cache);
        $options = $this->workerOptions();
        $options->maxTries = 0;
        $worker->runNextJob('database', 'default', $options);

        $row = $this->rowOrFail($uuid);
        self::assertSame('failed', $row->status);
        self::assertSame(MaxAttemptsExceededException::class, $row->exception_class);

        $terminal = $this->detail($uuid)->json('cycles.0.terminal');
        self::assertSame('max_attempts_other', $terminal['kind']);
        self::assertSame(['tries' => 10, 'tries_source' => 'payload', 'max_exceptions' => 2, 'attempt' => 1], array_intersect_key($terminal['facts'], array_flip(['tries', 'tries_source', 'max_exceptions', 'attempt'])));
    }

    /**
     * The canaries in every position of a real failed job (the
     * command's properties, an array, a header record, a URL password, a
     * large string, a top-level payload key, the exception message and
     * trace, and Laravel's visible and hidden Context), searched in the raw
     * bodies of the detail, the history page and the failure entry; and the
     * reads work with cache.serializable_classes false (N-8).
     *
     * Rejects: a detail, history or entry endpoint that sends a raw value,
     * raw serialized PHP, or the hidden Context.
     */
    public function test_no_canary_reaches_the_detail_endpoints(): void
    {
        $this->app['config']->set('cache.serializable_classes', false);
        Queue::createPayloadUsing(static fn (): array => ['secret_token' => 'canary-top-level']);
        Context::add('api_token', 'canary-context-visible');
        Context::addHidden('internal', 'canary-context-hidden');

        [$uuid] = $this->failWith(new CanaryJob(null, true));
        $stored = (string) $this->mirror()->table(JobStore::TABLE)->where('uuid', $uuid)->value('payload');
        self::assertStringContainsString('canary-context-hidden', $stored, 'the stored payload keeps the hidden Context');

        $detail = $this->detail($uuid);
        $history = $this->getJson('/orrery/api/jobs/'.$uuid.'/history')->assertOk();
        $entry = collect($history->json('entries'))->firstWhere('event', 'failed');
        self::assertIsArray($entry);
        self::assertTrue($entry['has_exception']);
        $failure = $this->getJson('/orrery/api/jobs/'.$uuid.'/history/'.$entry['id'])->assertOk();
        self::assertIsString($failure->json('exception'));

        foreach (['detail' => $detail, 'history' => $history, 'entry' => $failure] as $name => $response) {
            $raw = (string) $response->getContent();

            foreach (['canary-public-password', 'canary-protected-apikey', 'canary-private-secret', 'canary-array-token', 'canary-header-value', 'canary-url-password', 'canary-large-end', 'canary-top-level', 'canary-context-visible', 'canary-context-hidden', 'canary-exception-message'] as $canary) {
                self::assertStringNotContainsString($canary, $raw, "{$name}: {$canary}");
            }

            self::assertDoesNotMatchRegularExpression('/[sO]:\d+:\\\\?"/', $raw, "{$name}: no serialized PHP");
        }

        self::assertSame(CanaryJob::class, $detail->json('command.class'));
        self::assertSame(['omitted' => 'hidden_context'], $detail->json('payload.illuminate:log:context.hidden'));
    }

    private function detail(string $uuid): TestResponse
    {
        return $this->getJson('/orrery/api/jobs/'.$uuid)->assertOk();
    }
}
