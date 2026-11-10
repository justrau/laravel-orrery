<?php

namespace Justrau\Orrery\Contracts;

interface WorkloadRepository
{
    /**
     * Get the current workload of each queue: the Horizon-compatible fields
     * and the queue state, with the unknown backlog and
     * Laravel's native pause. DatabaseWorkloadRepository
     * documents each field.
     *
     * @return array<int, array{name: string, connection: string, connections: list<string>, length: int|null, wait: int|null, processes: int, split_queues: null|array<int, array{name: string, wait: int|null, length: int}>, oldest_age: float|null, threshold: int, over_limit: bool|null, running: int|null, in_per_min: float|null, out_per_min: float|null, rates_from: int, rates_to: int, pauses: list<array{scope: string, source: string, issued_at: float|null, issued_by: string|null, possible: bool}>, paused_unknown: bool, queue_cause_unknown: bool, native_unknown: bool, native_hint: string|null, backlog_unknown: bool, instrumented: bool, over_limit_since: int|null, over_limit_since_censored: bool}>
     */
    public function get(): array;
}
