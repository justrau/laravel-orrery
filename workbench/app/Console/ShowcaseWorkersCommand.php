<?php

namespace Workbench\App\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;
use Workbench\App\Support\WorkbenchShowcase;

/**
 * The workers of the showcase mode (`composer workbench:showcase-workers`): one child process
 * of workbench:showcase-worker per row of WORKERS. Ctrl-C, SIGINT or SIGTERM stops them all.
 */
#[AsCommand(name: 'workbench:showcase-workers')]
class ShowcaseWorkersCommand extends Command
{
    /** The host name and the queues of each showcase worker. */
    public const WORKERS = [
        'app-1' => 'default,emails,notifications',
        'app-2' => 'payments,webhooks,default',
        'app-3' => 'exports',
    ];

    /** @var string */
    protected $signature = 'workbench:showcase-workers {--max-time=1800 : Stop after this many seconds}';

    /** @var string */
    protected $description = 'Run the workers of the showcase mode under neutral host names.';

    public function handle(): int
    {
        if (! WorkbenchShowcase::enabled()) {
            throw new RuntimeException(sprintf('Run the showcase workers with %s=%s.', WorkbenchShowcase::VARIABLE, WorkbenchShowcase::ON));
        }

        $launcher = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'testbench';
        $workers = [];

        foreach (self::WORKERS as $host => $queues) {
            $process = new Process([PHP_BINARY, $launcher, 'workbench:showcase-worker', $host, '--queue='.$queues, '--max-time='.(int) $this->option('max-time')], dirname(__DIR__, 3));
            $process->setTimeout(null);
            $process->start();
            $workers[$host] = $process;
            $this->components->info(sprintf('Started %s on %s (pid %d).', $host, $queues, $process->getPid()));
        }

        $stopping = false;
        $this->trap([SIGINT, SIGTERM], static function () use (&$stopping): void {
            $stopping = true;
        });

        while (! $stopping && array_filter($workers, static fn (Process $process): bool => $process->isRunning()) !== []) {
            usleep(250_000);
        }

        foreach ($workers as $host => $process) {
            if ($process->isRunning()) {
                $process->stop(60, SIGTERM);
            }

            $this->components->info(sprintf('%s stopped (exit %s).', $host, var_export($process->getExitCode(), true)));
        }

        return self::SUCCESS;
    }
}
