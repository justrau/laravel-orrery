<?php

namespace Justrau\Orrery\Tests\Feature\Bulk\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A unique after-commit job: Laravel 13's bulk() registers a rollback
 * callback for it.
 */
class UniqueAfterCommitJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public string $key)
    {
        $this->afterCommit = true;
    }

    public function uniqueId(): string
    {
        return $this->key;
    }

    public function handle(): void
    {
    }
}
