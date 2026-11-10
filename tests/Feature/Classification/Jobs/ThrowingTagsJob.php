<?php

namespace Justrau\Orrery\Tests\Feature\Classification\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * A job whose tags() throws.
 */
class ThrowingTagsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        throw new RuntimeException('tags() of ThrowingTagsJob failed');
    }

    public function handle(): void {}
}
