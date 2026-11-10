<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Lifecycle;

use Illuminate\Console\Command;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Lifecycle\Observation;
use RuntimeException;

/**
 * test:observe: one lifecycle observation through the
 * store in its own process, so a test can run two store transactions of one
 * job at the same time. The payload carries only the uuid and the
 * generation. The store rethrows its errors, so a failed observation exits
 * with an error.
 */
class ObserveCommand extends Command
{
    /** @var string */
    protected $signature = 'test:observe {kind} {uuid} {job_id} {attempt} {generation}';

    /** @var string */
    protected $description = 'Apply one lifecycle observation (test fixture).';

    public function handle(JobStore $store): int
    {
        $payload = (string) json_encode(['uuid' => $this->argument('uuid'), 'orreryGen' => $this->argument('generation')]);
        $observation = Observation::fromPayload((string) $this->argument('kind'), $payload, 'database', 'default', [
            'jobId' => (int) $this->argument('job_id'),
            'attempt' => (int) $this->argument('attempt'),
            'exception' => new RuntimeException('test:observe'),
        ]);

        if ($observation === null) {
            $this->error('The observation has no uuid.');

            return 1;
        }

        $outcome = $store->observe($observation);
        $this->line("rule={$outcome->rule}");

        return 0;
    }
}
