<?php

namespace Justrau\Orrery\Notifications;

/**
 * A failure spike: a minute whose failed jobs (all queues, the `total`
 * samples) reached `notifications.failure_spike.per_minute`. The texts name
 * the count, the minute (UTC) and the largest failure groups of that
 * minute by job class and exception class, never a message or a payload.
 * Every count is a lower bound ("at least"): the minute may still be open,
 * and a late failure counts in a later recount.
 */
class FailureSpikeDetected extends RoutedNotification
{
    /**
     * @param  list<array{name: string, exception_class: ?string, count: int}>  $groups
     */
    public function __construct(public int $count, public int $minute, public array $groups) {}

    public function smsText(): string
    {
        return StuckJobDetected::gsm(sprintf(
            '%s: at least %s jobs failed in the minute of %s UTC.',
            mb_substr(StuckJobDetected::cut((string) config('orrery.name')), 0, 20),
            $this->count,
            gmdate('Y-m-d H:i', $this->minute),
        ));
    }

    protected function title(): string
    {
        return 'Failure Spike Detected';
    }

    protected function summary(): string
    {
        $groups = array_map(static fn (array $group): string => sprintf(
            'at least %s x %s (%s)',
            $group['count'],
            StuckJobDetected::cut($group['name']),
            StuckJobDetected::cut($group['exception_class'] ?? 'unknown exception'),
        ), $this->groups);

        return sprintf(
            '[%s] At least %s jobs failed in the minute of %s UTC.%s',
            StuckJobDetected::cut((string) config('orrery.name')),
            $this->count,
            gmdate('Y-m-d H:i', $this->minute),
            $groups === [] ? '' : ' Largest groups: '.implode('; ', $groups).'.',
        );
    }
}
