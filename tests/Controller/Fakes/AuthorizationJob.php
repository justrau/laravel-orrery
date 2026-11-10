<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller\Fakes;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * The fixture job of AuthorizationTest. It can be a batch member, it carries
 * the tags that it gets, and it throws when $fails is true, so the real
 * queue:work command records it in failed_jobs.
 */
final class AuthorizationJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /**
     * @param  list<string>  $fixtureTags
     */
    public function __construct(public bool $fails = false, public array $fixtureTags = [])
    {
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return $this->fixtureTags;
    }

    public function handle(): void
    {
        if ($this->fails) {
            throw new RuntimeException('The authorization fixture job failed on purpose.');
        }
    }
}
