<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures;

use Illuminate\Console\Command;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;

/**
 * A custom worker command: it runs Worker::daemon() directly, with its own
 * options, as some applications do. Its command line is not queue:work, so
 * the package must not guess its worker options.
 */
class CustomWorkerCommand extends Command
{
    /** @var string */
    protected $signature = 'test:custom-worker {connection=database} {--queue=default}';

    /** @var string */
    protected $description = 'Run a queue worker without queue:work (test fixture).';

    public function handle(): int
    {
        $options = new WorkerOptions;
        $options->sleep = 0;
        $options->maxTries = 9;
        $options->timeout = 99;
        $options->stopWhenEmpty = true;

        /** @var Worker $worker */
        $worker = $this->laravel->make('queue.worker');

        return (int) $worker->daemon((string) $this->argument('connection'), (string) $this->option('queue'), $options);
    }
}
