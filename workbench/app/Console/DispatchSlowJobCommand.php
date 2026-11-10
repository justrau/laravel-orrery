<?php

namespace Workbench\App\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Workbench\App\Jobs\SlowJob;

#[AsCommand(name: 'workbench:slow')]
class DispatchSlowJobCommand extends Command
{
    /** @var string */
    protected $signature = 'workbench:slow {--seconds='.SlowJob::DEFAULT_SECONDS.' : How long the job runs, in seconds}';

    /** @var string */
    protected $description = 'Push one slow job onto the "slow" queue of the database connection.';

    public function handle(): int
    {
        $seconds = (int) $this->option('seconds');

        SlowJob::dispatch($seconds)->onConnection('database')->onQueue('slow');

        $this->components->info(sprintf('Pushed one SlowJob (%d s) onto database:slow.', $seconds));

        return self::SUCCESS;
    }
}
