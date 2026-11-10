<?php

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A job whose payload is larger than 1 MB. The seeder leaves it pending.
 */
class LargePayloadJob implements ShouldQueue
{
    use Queueable;

    public const BYTES = 1_100_000;

    public string $document;

    public function __construct()
    {
        $this->document = substr(str_repeat('workbench large payload line '.PHP_EOL, intdiv(self::BYTES, 30) + 1), 0, self::BYTES);
    }

    public function handle(): void {}
}
