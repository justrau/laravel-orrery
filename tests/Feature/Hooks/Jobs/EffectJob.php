<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A job whose runs are the host outcome of the isolation tests. Each run
 * inserts "<label>:<attempt>" into the table `hook_effects` of the primary
 * connection, which every worker process shares. Its mode decides the rest:
 * `complete`, `release_once` (release at attempt 1, complete at 2), `fail`
 * (throw), or `release_then_fail` (release at attempt 1, throw at 2).
 */
class EffectJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const TABLE = 'hook_effects';

    public int $tries;

    public function __construct(public string $label, public string $mode = 'complete', int $tries = 3)
    {
        $this->tries = $mode === 'fail' ? 1 : $tries;
    }

    public function handle(): void
    {
        $attempt = $this->attempts();
        DB::connection('testbench')->table(self::TABLE)->insert(['effect' => "{$this->label}:{$attempt}"]);

        if ($this->mode === 'fail' || ($this->mode === 'release_then_fail' && $attempt >= 2)) {
            throw new RuntimeException("{$this->label} fails at attempt {$attempt}");
        }

        if (($this->mode === 'release_once' || $this->mode === 'release_then_fail') && $attempt === 1) {
            $this->release(0);
        }
    }
}
