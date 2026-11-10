<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\HostFile;

use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Bus;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Tests\Feature\Bulk\Jobs\BatchedJob;

/**
 * test:host-batch {count}: a Bus::batch() of {count} jobs on `app_queue`,
 * with its batch table on the application file. Laravel's Batch::add()
 * runs the queue's bulk() inside the batch repository's transaction on the
 * file. The hook point AFTER_BULK is reached right after the jobs insert,
 * inside that transaction, so a test can hold the transaction open there.
 * Prints one JSON line: the batch id.
 */
class HostBatchCommand extends Command
{
    public const AFTER_BULK = 'host_batch.after_bulk';

    /** @var string */
    protected $signature = 'test:host-batch {count}';

    /** @var string */
    protected $description = 'Dispatch a batch on the application SQLite file (test fixture).';

    public function handle(): int
    {
        $config = $this->laravel['config'];
        $config->set('queue.batching', ['database' => HostFileFixture::CONNECTION, 'table' => 'job_batches']);
        $this->laravel->forgetInstance(BatchRepository::class);
        $this->laravel->forgetInstance(DatabaseBatchRepository::class);
        $reached = false;

        $this->laravel['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$reached): void {
            if (! $reached && $event->connectionName === HostFileFixture::CONNECTION && str_starts_with(strtolower($event->sql), 'insert into "jobs"')) {
                $reached = true;
                Hooks::reach(self::AFTER_BULK);
            }
        });

        $jobs = [];

        for ($i = 0; $i < (int) $this->argument('count'); $i++) {
            $jobs[] = new BatchedJob;
        }

        $batch = Bus::batch($jobs)->onConnection(HostFileFixture::QUEUE)->onQueue('default')->dispatch();
        $this->line((string) json_encode(['batch' => $batch->id, 'reached' => $reached]));

        return self::SUCCESS;
    }
}
