<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Lifecycle;

use Justrau\Orrery\Lifecycle\Observation;
use Justrau\Orrery\Lifecycle\Rules;
use Justrau\Orrery\Tests\Support\Fixtures\Lifecycle\BuildsObservations;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The time invariants that the list engine reads:
 *
 * - every decision that leaves a row `completed`, `failed` or `unknown`
 *   writes `finished_at` (the terminal tabs order by it, and no list pages
 *   across NULL order values);
 * - every decision that leaves a row `failed` writes `failed_at` and
 *   `finished_at` from one time, so the queue filter of the failed tab can
 *   read the `retention` index on `finished_at` in the order of `failed_at`;
 * - no decision writes one of the two without the other while the row stays
 *   failed.
 *
 * The walk covers every observation kind against every status, with and
 * without a known row, swap on and off, a native retry request, and the
 * package retry payload.
 */
final class TerminalTimesTest extends TestCase
{
    use BuildsObservations;

    private const UUID = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';

    /**
     * Rejects: a terminal rule that leaves finished_at unset (an unknown or
     * a completed row without an order value), and a failure rule whose
     * failed_at and finished_at come from two times or are written apart.
     */
    public function test_every_terminal_decision_writes_finished_at_and_failed_at_equals_it(): void
    {
        $decided = [];

        foreach ($this->rows() as $rowName => $row) {
            foreach ($this->observations() as $observationName => $observation) {
                $decision = Rules::decide($row === null ? null : self::rowState($row), $observation);
                $set = $decision->set;

                if ($set === []) {
                    continue;
                }

                $status = $set['status'] ?? ($row['status'] ?? null);
                $case = "{$rowName} + {$observationName} ({$decision->rule})";
                $decided[$decision->rule] = true;

                if (array_key_exists('status', $set) && in_array($set['status'], ['completed', 'failed', 'unknown'], true)) {
                    self::assertNotNull($set['finished_at'] ?? null, "{$case}: a terminal decision writes finished_at");
                }

                if ($status === 'failed' && (array_key_exists('failed_at', $set) || array_key_exists('finished_at', $set))) {
                    self::assertArrayHasKey('failed_at', $set, "{$case}: failed_at with finished_at");
                    self::assertArrayHasKey('finished_at', $set, "{$case}: finished_at with failed_at");
                    self::assertSame($set['failed_at'], $set['finished_at'], "{$case}: one time for both");
                }
            }
        }

        foreach (['complete', 'completeWithoutRow', 'fail', 'failWithoutRow'] as $rule) {
            self::assertArrayHasKey($rule, $decided, "the walk reaches {$rule}");
        }
    }

    /**
     * Rejects: markUnknown without a finished_at (the unknown tab orders by it).
     */
    public function test_mark_unknown_writes_finished_at(): void
    {
        foreach (['pending', 'reserved'] as $status) {
            $decision = Rules::markUnknown(self::rowState(['status' => $status, 'job_id' => 10, 'cycle' => 1]), 10, 1, 'absent', self::T);

            self::assertSame('unknown', $decision->set['status']);
            self::assertNotNull($decision->set['finished_at']);
        }
    }

    /**
     * @return array<string, array<string, mixed>|null>
     */
    private function rows(): array
    {
        $rows = ['no row' => null];

        foreach (['pending', 'reserved', 'completed', 'failed', 'retrying', 'unknown'] as $status) {
            foreach ([1, 2] as $attempts) {
                $base = ['status' => $status, 'job_id' => 10, 'cycle_first_job_id' => 10, 'attempts' => $attempts];
                $rows["{$status} a{$attempts}"] = $base;
                $rows["{$status} a{$attempts} other generation"] = ['cycle_gen' => self::G2] + $base;
                $rows["{$status} a{$attempts} no generation"] = ['cycle_gen' => null] + $base;
                $rows["{$status} a{$attempts} unknown since"] = ['unknown_since' => '1789999000.000000'] + $base;
            }
        }

        // A native retry request after the failure (the swap-off native retry rule).
        $rows['failed, retry requested'] = ['status' => 'failed', 'job_id' => 10, 'cycle_first_job_id' => 10, 'attempts' => 1, 'failed_at' => '1789999000.000000', 'retry_requested_at' => '1789999500.000000'];

        return $rows;
    }

    /**
     * @return array<string, Observation>
     */
    private function observations(): array
    {
        $observations = [];

        foreach (Observation::KINDS as $kind) {
            foreach ([10, 11, 20] as $jobId) {
                foreach ([1, 2] as $attempt) {
                    foreach ([true, false] as $instrumented) {
                        foreach (['g1' => [], 'g2' => ['orreryGen' => self::G2], 'retry' => ['orreryRetry' => ['cycle' => 2, 'operation' => self::G1]]] as $payloadName => $payload) {
                            $fields = ['jobId' => $jobId, 'attempt' => $attempt, 'instrumented' => $instrumented];

                            if ($kind === Observation::RELEASE) {
                                $fields['newJobId'] = $jobId + 1;
                            }

                            if (in_array($kind, [Observation::FAIL, Observation::EXCEPTION], true)) {
                                $fields['exception'] = new RuntimeException('boom');
                            }

                            $name = sprintf('%s j%d a%d %s %s', $kind, $jobId, $attempt, $instrumented ? 'on' : 'off', $payloadName);
                            $observations[$name] = self::observation($kind, self::UUID, $fields, $payload);
                        }
                    }
                }
            }
        }

        return $observations;
    }
}
