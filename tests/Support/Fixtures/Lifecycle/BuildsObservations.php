<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Lifecycle;

use Justrau\Orrery\Lifecycle\Observation;
use Justrau\Orrery\Lifecycle\RowState;
use LogicException;

/**
 * Builds lifecycle observations with hand-made payloads for the lifecycle tests.
 *
 * The payload keys follow the payload keys contract:
 * `type` (string), `tags` (list of strings), `tagsTruncated` (bool),
 * `silenced` (bool), `pushedAt` (a decimal string of epoch seconds),
 * `orreryGen` (32 hex characters) and `orreryRetry` (`cycle` int, `token`
 * string). These payloads are written by hand.
 */
trait BuildsObservations
{
    /** Generation g1 of the rule table. */
    protected const G1 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** Generation g2 of the rule table. */
    protected const G2 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** The observation time of the tests. */
    protected const T = 1790000000.123456;

    protected const JOB_CLASS = 'App\\Jobs\\SendMail';

    /**
     * A Laravel-shaped payload with the package keys. An override with the
     * value null removes that key.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected static function payload(string $uuid, array $overrides = []): string
    {
        $payload = [
            'uuid' => $uuid,
            'displayName' => self::JOB_CLASS,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => null,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff' => null,
            'timeout' => null,
            'retryUntil' => null,
            'data' => ['commandName' => self::JOB_CLASS, 'command' => 'O:17:"App\\Jobs\\SendMail":0:{}'],
            'type' => 'job',
            'tags' => [],
            'tagsTruncated' => false,
            'silenced' => false,
            'pushedAt' => '1789999990.500000',
            'orreryGen' => self::G1,
        ];

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($payload[$key]);
            } else {
                $payload[$key] = $value;
            }
        }

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $fields  Observation constructor fields (jobId, attempt, at, instrumented, ...)
     * @param  array<string, mixed>  $payload  payload overrides
     */
    protected static function observation(string $kind, string $uuid, array $fields = [], array $payload = []): Observation
    {
        $observation = Observation::fromPayload(
            $kind,
            self::payload($uuid, $payload),
            $fields['connection'] ?? 'database',
            $fields['queue'] ?? 'default',
            array_diff_key($fields, ['connection' => true, 'queue' => true]) + ['at' => self::T, 'instrumented' => true],
        );

        if ($observation === null) {
            throw new LogicException('The test payload has no usable uuid.');
        }

        return $observation;
    }

    /**
     * The same observation without a worker: a reservation that names no
     * worker W, as one observed outside a worker process (R8 fills the
     * worker later). $attempt and $at replace the observation's own when
     * given.
     */
    protected static function workerless(Observation $o, ?int $attempt = null, ?float $at = null): Observation
    {
        return new Observation(
            kind: $o->kind,
            uuid: $o->uuid,
            connection: $o->connection,
            queue: $o->queue,
            jobId: $o->jobId,
            attempt: $attempt ?? $o->attempt,
            generation: $o->generation,
            retryCycle: $o->retryCycle,
            payload: $o->payload,
            at: $at ?? $o->at,
            instrumented: $o->instrumented,
            newJobId: $o->newJobId,
            delay: $o->delay,
            exception: $o->exception,
            worker: null,
            inWorker: false,
            workerId: null,
            bulkFloor: $o->bulkFloor,
            retryOperation: $o->retryOperation,
        );
    }

    /**
     * A mirror row as the database returns it, for RowState::fromRow().
     *
     * @param  array<string, mixed>  $columns
     */
    protected static function rowState(array $columns): RowState
    {
        return RowState::fromRow((object) ($columns + [
            'uuid' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
            'status' => 'pending',
            'cycle' => 1,
            'cycle_gen' => self::G1,
            'cycle_first_job_id' => null,
            'job_id' => null,
            'prev_job_id' => null,
            'attempts' => 0,
            'job_id_unresolved' => 0,
            'last_exception_attempt' => 0,
            'last_timeout_attempt' => 0,
            'last_outcome_attempt' => 0,
            'occurrence_id' => null,
            'batch_id' => null,
            'reserved_by' => null,
            'worker_evidence_attempt' => null,
            'exceptions' => 0,
            'timeouts' => 0,
            'releases' => 0,
            'reclaims' => 0,
            'reserved_at' => null,
            'unknown_since' => null,
            'name' => self::JOB_CLASS,
            'connection' => 'database',
            'queue' => 'default',
        ]));
    }
}
