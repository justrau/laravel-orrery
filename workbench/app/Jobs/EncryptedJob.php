<?php

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Workbench\App\Support\Canaries;

/**
 * A job whose command Laravel encrypts in the payload. The seeder leaves it pending.
 */
class EncryptedJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public string $note;

    public function __construct()
    {
        // Not a property default: Laravel leaves a default value out of the serialized command.
        $this->note = Canaries::ENCRYPTED;
    }

    public function handle(): void
    {
    }
}
