<?php

namespace Justrau\Orrery\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;
use Justrau\Orrery\Monitoring\MonitoringVersion;

/**
 * Stops monitoring a tag. The command runs synchronously, never on a queue:
 * one package transaction changes orrery_monitored_tags and the monitoring
 * version, for the normalised tag. It updates no job row.
 */
class StopMonitoringTag
{
    use Dispatchable;

    public function __construct(public string $tag) {}

    public function handle(MonitoringVersion $monitoring): void
    {
        $monitoring->stopMonitoring($this->tag);
    }
}
