<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Projection\Jobs;

use Illuminate\Mail\Mailable;

/**
 * A mailable with a reset token: queued, it is a SendQueuedMailable command.
 */
class CanaryMailable extends Mailable
{
    public string $resetToken;

    public string $greeting;

    public function __construct()
    {
        $this->resetToken = 'canary-mail-token';
        $this->greeting = 'plain greeting';
    }

    public function build(): self
    {
        return $this->html('Hello');
    }
}
