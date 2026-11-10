<?php

namespace Justrau\Orrery\Tests\Feature\Operations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\PackageSession;

/**
 * Writes the database clocks that its worker reads (the queue database's
 * and the mirror's) to a file, as JSON.
 */
class ClockProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $file) {}

    public function handle(): void
    {
        $sessions = Container::getInstance()->make(PackageSession::class);

        file_put_contents($this->file.'.tmp', json_encode([
            'queue' => DatabaseClock::now($sessions->forQueue('database')),
            'mirror' => DatabaseClock::now($sessions->mirror()),
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        rename($this->file.'.tmp', $this->file);
    }
}
