<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Justrau\Orrery\Runtime\ProcessContext;
use RuntimeException;

/**
 * A job that writes ProcessContext::current() of the process that runs it,
 * as JSON, into the file that it carries. The write is atomic (a temporary
 * file and a rename), so the test never reads a partial report.
 */
class ReportProcessContextJob implements ShouldQueue
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
        $report = ProcessContext::current()->toArray() + ['reported_by_pid' => getmypid()];
        $temporary = $this->path.'.'.getmypid().'.tmp';

        if (file_put_contents($temporary, json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) === false || ! rename($temporary, $this->path)) {
            throw new RuntimeException("Cannot write the process context report {$this->path}.");
        }
    }
}
