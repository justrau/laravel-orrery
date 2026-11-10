<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Observation;

use Closure;
use Illuminate\Queue\DatabaseQueue as LaravelDatabaseQueue;
use Illuminate\Support\Str;
use Justrau\Orrery\Lifecycle\Observation;
use Justrau\Orrery\Observation\CommitDeferral;
use Justrau\Orrery\Observation\DegradationDropCounter;
use Justrau\Orrery\Observation\TrackedRoot;
use Justrau\Orrery\Observation\WatchRegistry;
use Justrau\Orrery\Queue\BulkBatch;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Tests\Feature\Hooks\EndToEndTestCase;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\FreshSchema;
use ReflectionFunction;
use ReflectionProperty;

/**
 * The byte estimates that the swapped queue hooks pass to the commit
 * deferral (the payload strings and a fixed size per observation; a bulk
 * push all its payloads), and the decoded payload copies that a held
 * observation never keeps.
 */
#[FreshSchema]
final class DeferralByteBudgetHookTest extends EndToEndTestCase
{
    /**
     * Rejects: a hook that passes no byte estimate (the held bytes stay 0),
     * or one that passes the payload bytes without the factor.
     */
    public function test_push_and_push_raw_pass_their_payload_bytes(): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $this->push(new BasicJob);
        $pushed = strlen((string) $this->qJobs()[0]->payload);
        self::assertSame(CommitDeferral::estimate($pushed), $this->deferral()->heldBytes());

        $this->hooks()->pushRaw((string) json_encode(['uuid' => (string) Str::uuid(), 'job' => 'handler@handle', 'attempts' => 0]));
        $raw = strlen((string) $this->qJobs()[1]->payload);
        self::assertSame(CommitDeferral::estimate($pushed) + CommitDeferral::estimate($raw), $this->deferral()->heldBytes(), 'pushRaw() passes the stamped payload bytes.');

        $q->commit();
        self::assertSame(0, $this->deferral()->heldBytes());
        self::assertSame(2, $this->mirrorCount());
    }

    /**
     * Rejects: a bulk item that passes no estimate, or the bytes of one
     * payload only.
     */
    public function test_a_bulk_push_passes_the_sum_of_its_payloads(): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $this->hooks()->bulk([new BasicJob, new BasicJob, new BasicJob]);

        $sum = array_sum(array_map(static fn (object $row): int => strlen((string) $row->payload), $this->qJobs()));
        self::assertCount(3, $this->qJobs());
        self::assertSame(1, $this->deferral()->held(), 'The bulk insert is one item.');
        self::assertSame(CommitDeferral::estimate($sum, 3), $this->deferral()->heldBytes());

        $q->commit();
        self::assertSame(0, $this->deferral()->heldBytes());
        self::assertSame(3, $this->mirrorCount());
    }

    /**
     * Rejects: a reservation hook that passes no estimate.
     */
    public function test_a_reservation_in_a_transaction_passes_its_payload_bytes(): void
    {
        $this->push(new BasicJob);
        $q = $this->q();

        $q->beginTransaction();
        $job = $this->popJob();
        self::assertSame(CommitDeferral::estimate(strlen($job->getRawBody())), $this->deferral()->heldBytes());
        $q->rollBack();

        self::assertSame(0, $this->deferral()->heldBytes());
    }

    /**
     * Rejects: a release hook that passes no estimate.
     */
    public function test_a_release_in_a_transaction_passes_its_payload_bytes(): void
    {
        $this->push(new BasicJob);
        $job = $this->popJob();
        $q = $this->q();

        $q->beginTransaction();
        $job->release();
        self::assertSame(1, $this->deferral()->held(), 'The release waits for the commit.');
        self::assertSame(CommitDeferral::estimate(strlen($job->getRawBody())), $this->deferral()->heldBytes());
        $q->rollBack();

        self::assertSame(0, $this->deferral()->heldBytes());
    }

    /**
     * Laravel 13 inserts the after-commit jobs of a bulk in the commit walk;
     * the observation of that insert joins the walking root of the push.
     *
     * Rejects: a deferred bulk insert that passes no estimate, or the bytes
     * of one payload only.
     */
    public function test_an_after_commit_bulk_in_a_walk_passes_the_sum_of_its_payloads(): void
    {
        if (! method_exists(LaravelDatabaseQueue::class, 'partitionJobsByAfterCommit')) {
            self::markTestSkipped('This Laravel release has no DatabaseQueue::partitionJobsByAfterCommit(), so its bulk() never defers an insert.');
        }

        $q = $this->q();
        $inside = null;
        $expected = null;

        $q->beginTransaction();
        $this->hooks()->bulk([(new BasicJob)->afterCommit(), (new BasicJob)->afterCommit()]);
        // After Laravel's deferred insert and its observation, before the package's root callback.
        $this->manager()->addCallback(function () use (&$inside, &$expected): void {
            $rows = $this->qJobs();
            self::assertCount(3, $rows, 'The push and the two deferred jobs.');
            $expected = CommitDeferral::estimate(strlen((string) $rows[0]->payload))
                + CommitDeferral::estimate(strlen((string) $rows[1]->payload) + strlen((string) $rows[2]->payload), 2);
            $inside = $this->deferral()->heldBytes();
        });
        $this->push(new BasicJob);
        $q->commit();

        self::assertNotNull($expected, 'The callback ran in the walk.');
        self::assertSame($expected, $inside, 'The deferred insert joined the walk with the bytes of both payloads.');
        self::assertSame(0, $this->deferral()->heldBytes());
        self::assertSame(3, $this->mirrorCount());
    }

    /**
     * A Bus::batch() dispatches all its jobs in one bulk inside a
     * transaction, so the whole batch is one held item. At memory_limit
     * 128M (a byte budget of 16 MB), 4,000 jobs of about 1.5 KB are held.
     *
     * Rejects: an estimate that still counts a decoded copy per payload
     * (four times the payload bytes drops this batch).
     */
    public function test_a_large_batch_in_a_transaction_is_held_at_a_128m_memory_limit(): void
    {
        $deferral = new CommitDeferral(new DegradationDropCounter, Watcher::instance(), $this->app->make(WatchRegistry::class), null, '128M');
        $this->app->instance(CommitDeferral::class, $deferral);
        self::assertSame(16 * 1024 * 1024, $deferral->byteBudget());

        $jobs = [];

        for ($index = 0; $index < 4000; $index++) {
            $jobs[] = new PaddedJob(str_repeat('x', 600));
        }

        $q = $this->q();
        $q->beginTransaction();
        $this->hooks()->bulk($jobs);

        $sizes = array_map(static fn (object $row): int => strlen((string) $row->payload), $this->qJobs());
        self::assertCount(4000, $sizes);
        self::assertGreaterThan(1400, min($sizes), 'Each payload has about 1.5 KB.');
        self::assertLessThan(1800, max($sizes));
        self::assertGreaterThan($deferral->byteBudget(), 4 * array_sum($sizes), 'The batch would not fit with a decoded copy per payload.');

        self::assertSame(1, $deferral->held(), 'The batch is held as one item.');
        self::assertSame(CommitDeferral::estimate(array_sum($sizes), 4000), $deferral->heldBytes());
        self::assertSame(0, Degradation::record()['unverified'] ?? 0, 'Nothing is dropped.');
        $q->rollBack();

        self::assertSame(0, $deferral->heldBytes());
    }

    /**
     * Rejects: an observation that keeps its decoded payload copy while it
     * waits for the commit, or one that keeps the copy that its write
     * decoded again.
     */
    public function test_a_held_observation_keeps_no_decoded_copy(): void
    {
        $q = $this->q();

        $q->beginTransaction();
        $this->push(new BasicJob);
        $this->hooks()->bulk([new BasicJob, new BasicJob]);

        [$push, $bulk] = $this->heldRuns();
        $observation = (new ReflectionFunction($push))->getStaticVariables()['observation'] ?? null;
        $batch = (new ReflectionFunction($bulk))->getStaticVariables()['batch'] ?? null;
        self::assertInstanceOf(Observation::class, $observation);
        self::assertInstanceOf(BulkBatch::class, $batch);
        $observations = [$observation, ...$batch->observations()];

        foreach ($observations as $held) {
            self::assertNull(self::decodedOf($held), 'A held observation keeps only its payload string.');
        }

        $q->commit();

        self::assertSame(3, $this->mirrorCount(), 'The writes ran.');
        self::assertSame(BasicJob::class, $this->rowOrFail($observation->uuid)->name ?? null, 'The write decoded the payload again.');

        foreach ($observations as $ran) {
            self::assertNull(self::decodedOf($ran), 'The write freed the decoded copy.');
        }
    }

    /**
     * The run closures of the held items, in hold order.
     *
     * @return list<Closure>
     */
    private function heldRuns(): array
    {
        $deferral = $this->deferral();

        /** @var array<int, TrackedRoot> $roots */
        $roots = (new ReflectionProperty(CommitDeferral::class, 'roots'))->getValue($deferral);
        $runs = [];

        foreach ($roots as $root) {
            foreach ($root->items as $item) {
                $runs[] = $item['run'];
            }
        }

        return $runs;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodedOf(Observation $observation): ?array
    {
        /** @var array<string, mixed>|null */
        return (new ReflectionProperty(Observation::class, 'decoded'))->getValue($observation);
    }
}

/**
 * A job with a padded payload (the large-batch test).
 */
final class PaddedJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Bus\Queueable;

    public function __construct(public string $padding = '')
    {
    }

    public function handle(): void
    {
    }
}
