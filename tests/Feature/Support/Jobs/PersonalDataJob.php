<?php

namespace Justrau\Orrery\Tests\Feature\Support\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A job whose payload carries a personal value (SafeReportTest).
 */
class PersonalDataJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public string $email = '')
    {
    }

    public function handle(): void
    {
    }
}
