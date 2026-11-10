<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations\Fixtures;

use Illuminate\Console\Command;
use Justrau\Orrery\Retry\RetryService;

/**
 * test:retry-operation: one RetryService::retry() call in its own
 * process, so a test can hold or kill it at a hold point. It prints the
 * RetryResult as one JSON line.
 */
class RetryOperationCommand extends Command
{
    /** @var string */
    protected $signature = 'test:retry-operation {uuid} {occurrence} {--source=cli}';

    /** @var string */
    protected $description = 'Retry one failed occurrence through the new retry protocol (test fixture).';

    public function handle(RetryService $retries): int
    {
        $outcome = $retries->retry((string) $this->argument('uuid'), (int) $this->argument('occurrence'), (string) $this->option('source'), 'cli');
        $result = ['result' => $outcome->result, 'code' => $outcome->code, 'operation_id' => $outcome->operationId, 'job_id' => $outcome->jobId, 'cycle' => $outcome->cycle, 'message' => $outcome->message];
        $this->line((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return 0;
    }
}
