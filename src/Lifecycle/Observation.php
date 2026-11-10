<?php

namespace Justrau\Orrery\Lifecycle;

use Justrau\Orrery\Runtime\WorkerEvidence;
use Justrau\Orrery\Storage\Clock;
use Throwable;
use UnitEnum;

use function Illuminate\Support\enum_value;

/**
 * One queue observation of a job: the uuid, the jobs.id j,
 * the attempt n and the payload's generation g.
 *
 * Tests build this constructor in tests with named arguments.
 * A later field goes at the end, with a default.
 */
final class Observation
{
    public const PUSH = 'push';

    public const BULK_PUSH = 'bulk_push';

    public const REPUBLISH = 'republish';

    public const RESERVE = 'reserve';

    public const RELEASE = 'release';

    public const EXCEPTION = 'exception';

    public const TIMEOUT = 'timeout';

    public const COMPLETE = 'complete';

    public const FAIL = 'fail';

    public const KINDS = [
        self::PUSH, self::BULK_PUSH, self::REPUBLISH, self::RESERVE, self::RELEASE,
        self::EXCEPTION, self::TIMEOUT, self::COMPLETE, self::FAIL,
    ];

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di';

    /** @var array<string, mixed>|null the decoded payload, once */
    private ?array $decoded = null;

    public function __construct(
        public readonly string $kind,
        public readonly string $uuid,            // lower-case canonical
        public readonly string $connection,
        public readonly string $queue,
        public readonly ?int $jobId,             // j
        public readonly ?int $attempt,           // n
        public readonly ?string $generation,     // g, the payload's orreryGen
        public readonly ?int $retryCycle,        // the payload's orreryRetry.cycle
        public readonly string $payload,         // the raw payload string
        public readonly float $at,
        public readonly bool $instrumented,      // observed through the package DatabaseQueue
        public readonly ?int $newJobId = null,   // j' of a release
        public readonly ?int $delay = null,
        public readonly ?Throwable $exception = null,
        public readonly ?WorkerEvidence $worker = null,
        public readonly bool $inWorker = false,
        public readonly ?string $workerId = null,        // W: a verified swap-on reservation, or a failure in a worker
        public readonly ?int $bulkFloor = null,
        public readonly ?string $retryOperation = null,  // the payload's orreryRetry.operation
    ) {
    }

    /**
     * Builds an observation from a raw payload. The uuid is the payload's
     * `uuid`, else its `id`, when it is a canonical UUID (lower-cased).
     *
     * $fields holds the constructor arguments that the payload does not
     * carry, by name: jobId, attempt, at (default Clock::now()), instrumented
     * (default true), newJobId, delay, exception, worker, inWorker, workerId,
     * and bulkFloor.
     *
     * The queue is normalised with queueName(): Laravel 12 gives a
     * UnitEnum queue name to the hooks and the events unchanged.
     *
     * @param  array<string, mixed>  $fields
     * @return self|null null when the payload has neither a canonical-UUID uuid nor id
     */
    public static function fromPayload(string $kind, string $payload, string $connection, UnitEnum|string|null $queue, array $fields): ?self
    {
        $queue = self::queueName($queue);
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return null;
        }

        $uuid = self::canonicalUuid($decoded['uuid'] ?? null) ?? self::canonicalUuid($decoded['id'] ?? null);

        if ($uuid === null) {
            return null;
        }

        $generation = $decoded['orreryGen'] ?? null;
        $retry = is_array($decoded['orreryRetry'] ?? null) ? $decoded['orreryRetry'] : [];
        $cycle = $retry['cycle'] ?? null;
        $operation = $retry['operation'] ?? null;

        $observation = new self(
            kind: $kind,
            uuid: $uuid,
            connection: $connection,
            queue: $queue,
            jobId: self::intOrNull($fields['jobId'] ?? null),
            attempt: self::intOrNull($fields['attempt'] ?? null),
            generation: is_string($generation) && $generation !== '' && strlen($generation) <= 64 ? $generation : null,
            retryCycle: is_int($cycle) && $cycle >= 1 ? $cycle : null,
            payload: $payload,
            at: isset($fields['at']) ? (float) $fields['at'] : Clock::now(),
            instrumented: (bool) ($fields['instrumented'] ?? true),
            newJobId: self::intOrNull($fields['newJobId'] ?? null),
            delay: self::intOrNull($fields['delay'] ?? null),
            exception: ($fields['exception'] ?? null) instanceof Throwable ? $fields['exception'] : null,
            worker: ($fields['worker'] ?? null) instanceof WorkerEvidence ? $fields['worker'] : null,
            inWorker: (bool) ($fields['inWorker'] ?? false),
            workerId: isset($fields['workerId']) && is_string($fields['workerId']) && $fields['workerId'] !== '' ? $fields['workerId'] : null,
            bulkFloor: self::intOrNull($fields['bulkFloor'] ?? null),
            retryOperation: is_string($operation) && preg_match('/^[0-9a-f]{32}$/D', $operation) === 1 ? $operation : null,
        );

        $observation->decoded = $decoded;

        return $observation;
    }

    /**
     * The queue name as Laravel stores it in its jobs row: a BackedEnum
     * gives its value, a UnitEnum its case name, and null gives ''. The
     * getQueue() of Laravel 12 returns a UnitEnum queue name
     * unchanged, and the query builder converts it only in its bindings, so
     * every queue name that the package reads from a hook, an event or a
     * job goes through this method.
     */
    public static function queueName(mixed $queue): string
    {
        $name = enum_value($queue, '');

        return is_string($name) || is_int($name) ? (string) $name : '';
    }

    /**
     * The decoded payload, or an empty array when it is not a JSON object.
     *
     * @return array<string, mixed>
     */
    public function payloadData(): array
    {
        if ($this->decoded === null) {
            $decoded = json_decode($this->payload, true);
            $this->decoded = is_array($decoded) ? $decoded : [];
        }

        return $this->decoded;
    }

    /**
     * Frees the decoded payload copy once the observation's write ran: a
     * held observation keeps it only until then. payloadData() decodes the
     * payload again when it is read later.
     */
    public function forgetDecoded(): void
    {
        $this->decoded = null;
    }

    /**
     * Whether the payload is a package retry of the operation ledger:
     * orreryRetry.operation equals the observation's own
     * generation. A republish gets a fresh generation, so it never matches a
     * stale orreryRetry.
     */
    public function isOperationRetry(): bool
    {
        return $this->retryOperation !== null && $this->generation !== null && hash_equals($this->retryOperation, $this->generation);
    }

    /**
     * A reservation that the swapped pop() hook verified: it names
     * its worker W.
     */
    public function isVerifiedReservation(): bool
    {
        return $this->kind === self::RESERVE && $this->instrumented && $this->workerId !== null;
    }

    private static function canonicalUuid(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::UUID_PATTERN, $value) === 1 ? strtolower($value) : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
