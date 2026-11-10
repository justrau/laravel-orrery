<?php

namespace Justrau\Orrery\Monitoring\Phases;

use Justrau\Orrery\Database\Schema\SchemaOutdated;
use Justrau\Orrery\Lifecycle\IncidentCleanup;
use Justrau\Orrery\Maintenance\RunsPastDeadline;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Monitoring\StuckIncidents;
use Justrau\Orrery\Monitoring\StuckNotifications;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The notifications of the stuck incidents (StuckNotifications):
 * after `health_retention` and before `long_wait`, the
 * last phase (priority 950), so a slow or failing channel delays no
 * earlier phase.
 * Inside a share of StuckNotifications::SHARE_SECONDS and the tick budget;
 * it runs also after the tick's deadline, with its share (RunsPastDeadline).
 *
 * A mirror without the notification state of schema step 10 fails this
 * phase with SchemaOutdated (`schema_outdated`); the incidents and their
 * events (`stuck_incidents`) go on. It writes only to the mirror.
 */
final class StuckNotificationsPhase implements RunsPastDeadline
{
    public function __construct(
        private readonly PackageSession $sessions,
        private readonly IncidentCleanup $cleanup,
        private readonly StuckNotifications $notifications,
    ) {}

    public function name(): string
    {
        return 'stuck_notifications';
    }

    public function priority(): int
    {
        return 950;
    }

    public function shareSeconds(): float
    {
        return StuckNotifications::SHARE_SECONDS;
    }

    public function run(TickBudget $budget): void
    {
        $started = Clock::monotonic();
        $mirror = $this->sessions->mirror();

        if (! $this->cleanup->notifyColumns($mirror)) {
            throw $this->cleanup->exists($mirror)
                ? SchemaOutdated::column(StuckIncidents::TABLE, 'notify_next_at')
                : SchemaOutdated::table(StuckIncidents::TABLE);
        }

        $this->notifications->run($mirror, static fn (): bool => Clock::monotonic() >= $started + StuckNotifications::SHARE_SECONDS || $budget->exhausted());
    }
}
