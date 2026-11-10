<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Observation;

use Closure;
use Illuminate\Support\Str;
use Justrau\Orrery\Lifecycle\Observation;
use Justrau\Orrery\Observation\CommitDeferral;
use Justrau\Orrery\Observation\TrackedRoot;
use Justrau\Orrery\Queue\BulkBatch;
use Justrau\Orrery\Tests\Feature\Hooks\EndToEndTestCase;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\FreshSchema;
use ReflectionFunction;
use ReflectionProperty;

/**
 * The byte estimates that the swapped queue hooks pass to the commit
 * deferral (the payload bytes times the fixed factor, the sum over the
 * payloads for a bulk push), and the decoded payload copies that an
 * observation frees once its write ran.
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
        self::assertSame(CommitDeferral::estimate($pushed + $raw), $this->deferral()->heldBytes(), 'pushRaw() passes the stamped payload bytes.');

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
        self::assertSame(CommitDeferral::estimate($sum), $this->deferral()->heldBytes());

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
     * Rejects: an observation whose decoded payload copy stays in memory
     * after its write ran (it lives on in the held item until Laravel
     * drops the transaction record).
     */
    public function test_a_held_observation_frees_its_decoded_copy_after_its_write(): void
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
            self::assertNotNull(self::decodedOf($held), 'A held observation keeps the copy that its write reads.');
        }

        $q->commit();

        self::assertSame(3, $this->mirrorCount(), 'The writes ran.');

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
