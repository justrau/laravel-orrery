<?php

namespace Justrau\Orrery\Events;

use Justrau\Orrery\JobPayload;
use Throwable;

class JobFailed
{
    public ?string $connectionName = null;

    public ?string $queue = null;

    public JobPayload $payload;

    public Throwable $exception;

    /** The jobs.id of the failed attempt (the failure rules match it). */
    public ?int $jobId = null;

    /** The attempt number of the failed attempt. */
    public ?int $attempts = null;

    public function __construct(Throwable $exception, JobPayload $payload)
    {
        $this->exception = $exception;
        $this->payload = $payload;
    }

    public function connection(string $name): static
    {
        $this->connectionName = $name;

        return $this;
    }

    public function queue(string $name): static
    {
        $this->queue = $name;

        return $this;
    }

    /**
     * The jobs.id and the attempt of the failed attempt.
     */
    public function attempt(?int $jobId, ?int $attempts): static
    {
        $this->jobId = $jobId;
        $this->attempts = $attempts;

        return $this;
    }
}
