<?php

namespace Justrau\Orrery\Tests\Feature\Bulk\Jobs;

use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A job whose tags() runs the test's $during callback, for example an
 * inner bulk inside a transaction, while an outer bulk classifies it.
 */
class InnerBulkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @var (Closure(): void)|null */
    public static ?Closure $during = null;

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        $during = self::$during;
        self::$during = null;

        if ($during !== null) {
            $during();
        }

        return ['outer-of-inner-bulk'];
    }

    public function handle(): void
    {
    }
}
