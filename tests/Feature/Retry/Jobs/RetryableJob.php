<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retry\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The job of the retry tests. Every counter is a file, so a
 * worker process and the test process share it:
 * - handle() adds one line to $runFile per run. The runs up to $failRuns
 *   fail explicitly (without an attempt exception); the later runs complete.
 * - retryUntil() adds one line to $untilFile (when set) per call. Laravel
 *   calls it when it creates the payload, and the retry preparation calls it
 *   again (RetryCommand::refreshRetryUntil()). While $throwFile exists, it
 *   throws: application code that fails in the preparation.
 * - tags() gives $jobTags.
 */
class RetryableJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  list<string>  $jobTags
     */
    public function __construct(
        public string $runFile,
        public int $failRuns = 1,
        public array $jobTags = [],
        public ?string $untilFile = null,
        public ?string $throwFile = null,
    ) {}

    public static function runs(string $file): int
    {
        clearstatcache(true, $file);

        return is_file($file) ? count(array_filter(explode("\n", (string) file_get_contents($file)))) : 0;
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return $this->jobTags;
    }

    public function retryUntil(): Carbon
    {
        if ($this->untilFile !== null) {
            file_put_contents($this->untilFile, "call\n", FILE_APPEND | LOCK_EX);
        }

        if ($this->throwFile !== null && is_file($this->throwFile)) {
            throw new RuntimeException('retryUntil() fails in the preparation (test).');
        }

        return Carbon::now()->addHour();
    }

    public function handle(): void
    {
        file_put_contents($this->runFile, "run\n", FILE_APPEND | LOCK_EX);
        $run = self::runs($this->runFile);

        if ($run <= $this->failRuns) {
            $this->fail(new RuntimeException("RetryableJob fails at run {$run}."));
        }
    }
}
