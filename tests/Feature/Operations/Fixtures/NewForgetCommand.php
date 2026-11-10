<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations\Fixtures;

use Illuminate\Console\Command;
use Justrau\Orrery\Retry\ForgetService;

/**
 * test:new-forget (see ForgetsCommandsProvider).
 */
final class NewForgetCommand extends Command
{
    /** @var string */
    protected $signature = 'test:new-forget {uuid} {occurrence} {--null-failer}';

    /** @var string */
    protected $description = 'Forget one failed occurrence through the new forget protocol (test fixture).';

    public function handle(ForgetService $forgets): int
    {
        if ($this->option('null-failer') === true) {
            $this->laravel['config']->set('queue.failed', ['driver' => 'null']);
            $this->laravel->forgetInstance('queue.failer');
        }

        $result = $forgets->forget((string) $this->argument('uuid'), (int) $this->argument('occurrence'), 'cli', 'cli');
        $this->line((string) json_encode(['result' => $result->result, 'code' => $result->code, 'operation' => $result->operationId]));

        return 0;
    }
}
