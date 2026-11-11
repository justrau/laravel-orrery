<?php

namespace Justrau\Orrery\Runtime;

/**
 * The worker options of a queue:work process, taken from its own command
 * line. ProcessContext::workerEvidence() is
 * null when the options are unknown.
 *
 * `sleep` and `rest` are the waits of the worker loop (--sleep, --rest),
 * for the heartbeat's write period (loopOptions(), LiveWorkers). They are
 * not in toArray(): that array is the job evidence that the job rows keep.
 * A queue:listen child gets --sleep from its parent, and its rest is the
 * parent's --rest (ProcessContext::listenRest()).
 */
final class WorkerEvidence
{
    public function __construct(
        public readonly ?int $tries,
        public readonly ?int $timeout,
        public readonly ?string $connection,
        public readonly ?string $queue,
        public readonly bool $once,
        public readonly ?float $sleep = null,
        public readonly ?float $rest = null,
    ) {}

    /**
     * The known loop waits: `sleep` and `rest`, each only when known.
     *
     * @return array{sleep?: float, rest?: float}
     */
    public function loopOptions(): array
    {
        return array_filter(['sleep' => $this->sleep, 'rest' => $this->rest], static fn (?float $value): bool => $value !== null);
    }

    /**
     * @return array{tries: ?int, timeout: ?int, connection: ?string, queue: ?string, once: bool}
     */
    public function toArray(): array
    {
        return [
            'tries' => $this->tries,
            'timeout' => $this->timeout,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'once' => $this->once,
        ];
    }
}
