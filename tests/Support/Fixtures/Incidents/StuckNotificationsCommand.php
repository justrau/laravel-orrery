<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Incidents;

use Illuminate\Console\Command;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Monitoring\Phases\StuckNotificationsPhase;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Storage\Clock;

/**
 * test:stuck-notifications: one run of the tick phase `stuck_notifications`
 * alone, with the mail route ops@example.test and the array mailer (see
 * IncidentCommandsProvider). The phase's errors reach the exit code.
 */
final class StuckNotificationsCommand extends Command
{
    /** @var string */
    protected $signature = 'test:stuck-notifications';

    /** @var string */
    protected $description = 'Run the stuck-job notifications phase once (test fixture).';

    public function handle(): int
    {
        $this->laravel['config']->set('mail.default', 'array');
        Orrery::routeMailNotificationsTo('ops@example.test');
        $this->laravel->make(StuckNotificationsPhase::class)->run(new TickBudget(Clock::monotonic() + 30));

        return 0;
    }
}
