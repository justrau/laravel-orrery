<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations\Fixtures;

use Illuminate\Console\Command;
use Justrau\Orrery\Retry\ForgetAllRecords;

/**
 * test:forget-all (see ForgetsCommandsProvider).
 */
final class ForgetAllCommand extends Command
{
    /** @var string */
    protected $signature = 'test:forget-all {--connection=} {--queue=}';

    /** @var string */
    protected $description = 'Forget every failed record of a scope (test fixture).';

    public function handle(ForgetAllRecords $all): int
    {
        $connection = $this->option('connection');
        $queue = $this->option('queue');
        $report = $all->run(is_string($connection) ? $connection : null, is_string($queue) ? $queue : null);
        $this->line((string) json_encode($report));

        return 0;
    }
}
