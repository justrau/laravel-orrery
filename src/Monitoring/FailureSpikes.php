<?php

namespace Justrau\Orrery\Monitoring;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Justrau\Orrery\Listeners\NotificationState;
use Justrau\Orrery\Listeners\SendNotification;
use Justrau\Orrery\Notifications\FailureSpikeDetected;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The failure-spike alert of the long-wait phase. Off while
 * `notifications.failure_spike.per_minute` is null (the default) or not a
 * whole number of at least 1.
 *
 * Each run reads the failures of the minutes in [now − 5 min, now) of the
 * `total` scope (all queues) through SampleCoverage::of(), so a minute
 * whose count is unknown triggers nothing. A minute whose count reaches
 * the threshold alerts once: a lasting orrery_settings row per minute
 * (MINUTE_PREFIX) is claimed with an insert-or-ignore, and the rows of the
 * minutes before the window are deleted. Then the notification
 * (FailureSpikeDetected: the count, the minute and the TOP_GROUPS largest
 * failure groups of the minute's rows by failed_at, every status as the
 * count has it) goes to the long-wait routes through
 * SendNotification::deliver(), with a per-channel cooldown of
 * `notifications.failure_spike.cooldown` minutes (30) after a delivery.
 * When no channel got it and one was not held by its cooldown, the claim
 * is released (guarded by its value), so a later tick tries again.
 *
 * The count is a lower bound: the current minute is still open, and a
 * failure observed late is counted in a later recount. The notice says
 * "at least".
 */
final class FailureSpikes
{
    /** The key prefix of a claimed minute (no LIKE wildcard in it). */
    public const MINUTE_PREFIX = 'orrery:failurespike:minute:';

    /** The window of minutes before now, in seconds. */
    public const WINDOW_SECONDS = 300;

    /** The failure groups that the notice names. */
    public const TOP_GROUPS = 3;

    public const DEFAULT_COOLDOWN_MINUTES = 30;

    public function __construct(private readonly SampleCoverage $coverage) {}

    public static function cooldownKey(string $channel): string
    {
        return 'orrery:notification:failurespike:cooldown:'.sha1($channel);
    }

    public function run(float $now): void
    {
        $threshold = $this->threshold();

        if ($threshold === null) {
            return;
        }

        $to = (int) floor($now);
        $from = $to - self::WINDOW_SECONDS;
        $window = $this->coverage->of('total', null, $from, $to, ['failed']);
        $series = SampleCounts::series('total');
        $mirror = $this->mirror();
        $mirror->table('orrery_settings')->where('key', 'like', self::MINUTE_PREFIX.'%')->where('key', '<', self::MINUTE_PREFIX.$window->from)->delete();

        foreach ($window->minutes() as $minute) {
            $count = $window->value($minute, $series, 'failed');

            if ($count === null || $count < $threshold) {
                continue;
            }

            if ($mirror->table('orrery_settings')->insertOrIgnore([['key' => self::MINUTE_PREFIX.$minute, 'value' => (string) $count]]) !== 1) {
                continue;
            }

            $outcomes = (new SendNotification)->deliver(
                NotificationState::current(),
                new FailureSpikeDetected($count, $minute, $this->groups($minute)),
                null,
                static fn (string $channel): string => self::cooldownKey($channel),
                $this->cooldownMinutes() * 60,
            );

            // No channel got it, and one was not held by its cooldown (down, failed, out of the budget): a later tick tries the minute again.
            if (SendNotification::delivered($outcomes) === [] && array_filter($outcomes, static fn (string $outcome): bool => $outcome !== SendNotification::COOLDOWN) !== []) {
                $mirror->table('orrery_settings')->where('key', self::MINUTE_PREFIX.$minute)->where('value', (string) $count)->delete();
            }
        }
    }

    /**
     * The largest failure groups of the failed rows of one minute, by
     * failed_at: the job class and exception class of each group's newest
     * row.
     *
     * @return list<array{name: string, exception_class: ?string, count: int}>
     */
    private function groups(int $minute): array
    {
        $mirror = $this->mirror();
        $top = $mirror->table('orrery_jobs')
            ->whereIn('status', Throughput::STATUSES)
            ->where('failed_at', '>=', Clock::toDecimal((float) $minute))
            ->where('failed_at', '<', Clock::toDecimal((float) ($minute + SampleCounts::MINUTE)))
            ->groupBy('failure_key')
            ->selectRaw('MAX(id) AS id, COUNT(*) AS n')
            ->orderByDesc('n')
            ->orderByDesc('id')
            ->limit(self::TOP_GROUPS)
            ->get();
        $counts = [];

        foreach ($top as $row) {
            $counts[(int) $row->id] = (int) $row->n;
        }

        if ($counts === []) {
            return [];
        }

        $rows = [];

        foreach ($mirror->table('orrery_jobs')->whereIn('id', array_keys($counts))->get(['id', 'name', 'exception_class']) as $row) {
            $rows[(int) $row->id] = $row;
        }

        $groups = [];

        foreach ($counts as $id => $count) {
            $row = $rows[$id] ?? null;

            if ($row !== null) {
                $groups[] = ['name' => (string) $row->name, 'exception_class' => is_string($row->exception_class) ? $row->exception_class : null, 'count' => $count];
            }
        }

        return $groups;
    }

    private function threshold(): ?int
    {
        $value = Container::getInstance()->make('config')->get('orrery.notifications.failure_spike.per_minute');

        return is_numeric($value) && (int) $value >= 1 ? (int) $value : null;
    }

    private function cooldownMinutes(): int
    {
        $value = Container::getInstance()->make('config')->get('orrery.notifications.failure_spike.cooldown', self::DEFAULT_COOLDOWN_MINUTES);

        return is_numeric($value) ? max(0, (int) $value) : self::DEFAULT_COOLDOWN_MINUTES;
    }

    private function mirror(): Connection
    {
        return Container::getInstance()->make(PackageSession::class)->mirror();
    }
}
