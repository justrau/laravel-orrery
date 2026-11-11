<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\OffSwitch;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Jobs\FailingJob;

/**
 * test:dispatch-pair: pushes one succeeding and one failing job on the
 * `database` connection. Prints the queue class that pushed them.
 */
class DispatchPairCommand extends Command
{
    /** @var string */
    protected $signature = 'test:dispatch-pair';

    /** @var string */
    protected $description = 'Push a succeeding and a failing job (test fixture).';

    public function handle(): int
    {
        Queue::connection('database')->push(new BasicJob);
        Queue::connection('database')->push(new FailingJob);
        $this->line(Queue::connection('database')::class);

        return self::SUCCESS;
    }
}
