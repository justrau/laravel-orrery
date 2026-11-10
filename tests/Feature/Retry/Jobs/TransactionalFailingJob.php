<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retry\Jobs;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A job that fails itself inside an application transaction.
 * Its first run fails outside any transaction. Each later run
 * writes one row into $table on $connection inside DB::transaction(), and
 * then calls fail(): Laravel dispatches JobFailed at once, so the failer
 * logs the failure inside that transaction. When DB::transaction() returns,
 * the job appends one line to $returnedFile.
 */
class TransactionalFailingJob extends RetryableJob
{
    public function __construct(
        string $runFile,
        public string $connectionName = 'testbench',
        public string $table = 'retry_audit',
        public ?string $returnedFile = null,
    ) {
        parent::__construct(runFile: $runFile, failRuns: PHP_INT_MAX);
    }

    public function handle(): void
    {
        file_put_contents($this->runFile, "run\n", FILE_APPEND | LOCK_EX);
        $run = self::runs($this->runFile);

        if ($run === 1) {
            $this->fail(new RuntimeException('TransactionalFailingJob fails at run 1.'));

            return;
        }

        DB::connection($this->connectionName)->transaction(function () use ($run): void {
            DB::connection($this->connectionName)->table($this->table)->insert(['note' => "run {$run}"]);
            $this->fail(new RuntimeException("TransactionalFailingJob fails inside its transaction at run {$run}."));
        });

        if ($this->returnedFile !== null) {
            file_put_contents($this->returnedFile, "returned\n", FILE_APPEND | LOCK_EX);
        }
    }
}
