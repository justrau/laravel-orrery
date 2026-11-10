<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Justrau\Orrery\Storage\PackageSession;
use RuntimeException;

/**
 * A job that writes its own mirror row (the worker columns and the state)
 * into the file that it carries, as JSON, and then throws.
 * So the test sees the row as it was while the job ran: after the
 * reservation and before the failure. The write is atomic.
 */
class ReportReservationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $path)
    {
    }

    public function handle(): void
    {
        $uuid = strtolower((string) $this->job?->uuid());
        $row = Container::getInstance()->make(PackageSession::class)->mirror()->table('orrery_jobs')->where('uuid', $uuid)->first([
            'status', 'job_id', 'attempts', 'reserved_by', 'worker_tries', 'worker_timeout', 'worker_evidence_attempt',
        ]);

        $report = ['row' => $row === null ? null : (array) $row, 'job_id' => $this->job?->getJobId()];
        $temporary = $this->path.'.'.getmypid().'.tmp';

        if (file_put_contents($temporary, json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) === false || ! rename($temporary, $this->path)) {
            throw new RuntimeException("Cannot write the reservation report {$this->path}.");
        }

        throw new RuntimeException('ReportReservationJob fails after its report.');
    }
}
