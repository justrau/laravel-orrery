<?php

namespace Justrau\Orrery\Listeners;

use Illuminate\Queue\Events\JobRetryRequested;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Observation\DatabaseDriverFilter;
use Justrau\Orrery\Observation\PayloadIdentity;
use Justrau\Orrery\Storage\Clock;

/**
 * The native retry intent: JobRetryRequested sets
 * retry_requested_at only. It is not a transition; a native retry becomes a
 * cycle at its reservation (nativeRetry).
 *
 * The event carries the failed record, not a connectionName, so the
 * provider's driver filter lets it through: the record's own connection
 * decides here, and a record of another driver (queue:retry of a Redis job)
 * sends no statement.
 */
final class ObserveRetryRequested
{
    public function __construct(private readonly JobStore $store) {}

    public function handle(JobRetryRequested $event): void
    {
        $record = $event->job;
        $connection = $record->connection ?? null;

        if (! is_string($connection) || ! DatabaseDriverFilter::isDatabase($connection)) {
            return;
        }

        $uuid = PayloadIdentity::uuidOf($record->payload ?? null);

        if ($uuid !== null) {
            $this->store->retryRequested($uuid, Clock::now());
        }
    }
}
