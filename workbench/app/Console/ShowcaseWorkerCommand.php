<?php

namespace Workbench\App\Console;

use Illuminate\Console\Command;
use Justrau\Orrery\Runtime\ProcessContext;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Workbench\App\Support\WorkbenchShowcase;

/**
 * One worker of the showcase mode: the real `queue:work` command, in this process, under a
 * neutral host name, so the dashboard's worker list shows "app-1" and not the machine's name.
 *
 * The package takes the host name from gethostname(). This command replaces the process facts
 * with ProcessContext::forTesting() (the package's seam for them) before the worker starts, so
 * the heartbeats, the leases and the current job go through the package's own write paths.
 */
#[AsCommand(name: 'workbench:showcase-worker')]
class ShowcaseWorkerCommand extends Command
{
    /** @var string */
    protected $signature = 'workbench:showcase-worker
        {host : The host name that the dashboard shows, for example app-1}
        {--queue=default : The queues, in order}
        {--max-time=1800 : Stop after this many seconds}';

    /** @var string */
    protected $description = 'Run one queue:work worker of the showcase mode under a neutral host name.';

    public function handle(): int
    {
        if (! WorkbenchShowcase::enabled()) {
            throw new RuntimeException(sprintf('Run the showcase worker with %s=%s.', WorkbenchShowcase::VARIABLE, WorkbenchShowcase::ON));
        }

        $host = (string) $this->argument('host');

        if (preg_match('/^[a-z0-9][a-z0-9-]{0,30}$/', $host) !== 1) {
            throw new RuntimeException(sprintf('The showcase worker refuses the host name [%s].', $host));
        }

        ProcessContext::useForTesting(ProcessContext::forTesting(ProcessContext::WORKER, $host, getmypid() ?: 0, microtime(true)));

        return $this->call('queue:work', [
            'connection' => 'database',
            '--queue' => (string) $this->option('queue'),
            '--sleep' => 1,
            '--max-time' => (int) $this->option('max-time'),
        ]);
    }
}
