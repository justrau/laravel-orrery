<?php

namespace Justrau\Orrery\Tests\Feature\Classification\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A job with the tags that the test gives it.
 */
class ClassifiedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  list<mixed>  $tags
     */
    public function __construct(public array $tags = []) {}

    /**
     * @return list<mixed>
     */
    public function tags(): array
    {
        return $this->tags;
    }

    public function handle(): void {}
}
