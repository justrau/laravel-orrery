<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Projection\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * An encrypted job: its command is the encrypter's envelope.
 */
class EncryptedCanaryJob implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;

    public string $note;

    public function __construct()
    {
        $this->note = 'canary-encrypted-note';
    }

    public function handle(): void
    {
    }
}
