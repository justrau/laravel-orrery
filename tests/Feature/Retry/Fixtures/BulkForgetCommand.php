<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retry\Fixtures;

use Illuminate\Console\Command;
use Justrau\Orrery\Retry\ForgetService;

/**
 * test:bulk-forget {uuid} {occurrence} {ref}: one ForgetService::forget()
 * of a bulk run's member (source `bulk`, the run's ref), under the null
 * failer, printed as one JSON line (see BulkCommandsProvider).
 */
final class BulkForgetCommand extends Command
{
    /** @var string */
    protected $signature = 'test:bulk-forget {uuid} {occurrence} {ref}';

    /** @var string */
    protected $description = 'Forget one member of a bulk run (test fixture).';

    public function handle(): int
    {
        $this->laravel['config']->set('queue.failed', ['driver' => 'null']);
        $this->laravel->forgetInstance('queue.failer');

        $result = $this->laravel->make(ForgetService::class)->forget((string) $this->argument('uuid'), (int) $this->argument('occurrence'), 'cli', 'bulk', (string) $this->argument('ref'));
        $this->line((string) json_encode(['result' => $result->result, 'code' => $result->code, 'operation' => $result->operationId]));

        return 0;
    }
}
