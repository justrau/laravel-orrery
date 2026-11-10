<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\HostFile;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * test:host-schedule: a real `schedule:run` in the worker CLI. The worker
 * CLI allows only its own commands, so this test command calls the
 * scheduler's command in-process; the scheduler starts each due event
 * (orrery:tick every minute) as a subprocess of the worker CLI
 * (ARTISAN_BINARY), with this process's environment. The scheduler's clock
 * is frozen at the start of the current hour, so every event of a coarser
 * frequency is due on every run too (Event::isDue() reads the clock when it
 * checks; the subprocesses run on the real clock).
 */
class HostScheduleCommand extends Command
{
    /** @var string */
    protected $signature = 'test:host-schedule';

    /** @var string */
    protected $description = 'Run the scheduler once (test fixture).';

    public function handle(): int
    {
        Carbon::setTestNow(Carbon::now()->startOfHour());

        try {
            return $this->call('schedule:run');
        } finally {
            Carbon::setTestNow();
        }
    }
}
