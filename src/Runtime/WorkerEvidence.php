<?php

namespace Justrau\Orrery\Runtime;

/**
 * The worker options of a queue:work process, taken from its own command
 * line. ProcessContext::workerEvidence() is
 * null when the options are unknown.
 */
final class WorkerEvidence
{
    public function __construct(
        public readonly ?int $tries,
        public readonly ?int $timeout,
        public readonly ?string $connection,
        public readonly ?string $queue,
        public readonly bool $once,
    ) {}

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
