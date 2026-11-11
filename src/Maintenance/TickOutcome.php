<?php

namespace Justrau\Orrery\Maintenance;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The outcome of the last finished `orrery:tick`, in the orrery_settings
 * row KEY: `started_at` (the value of observer_tick_at it wrote),
 * `finished_at`, `failed` (the phases that threw) and `skip_streaks` (per
 * phase, the ticks in a row whose deadline skipped it; a run of the phase
 * resets it).
 *
 * The monitoring ping (Monitoring\Ping, `tick`) reads it: a failed phase
 * fails the check, and so does a phase that the deadline skipped on
 * SKIP_STREAK ticks in a row (one skip is no failure: the next tick runs
 * it first). A tick that started but never finished leaves the previous
 * outcome, whose `finished_at` then grows old.
 *
 * @phpstan-type Outcome array{started_at: float, finished_at: float, failed: list<string>, skip_streaks: array<string, int>}
 */
final class TickOutcome
{
    public const KEY = 'tick_outcome';

    /** The deadline skips in a row of one phase that count as a failure. */
    public const SKIP_STREAK = 3;

    public function record(float $startedAt, float $finishedAt, TickReport $report): void
    {
        $previous = $this->read()['skip_streaks'] ?? [];
        $streaks = [];

        foreach ($report->skipped() as $phase) {
            $streaks[$phase] = ($previous[$phase] ?? 0) + 1;
        }

        $outcome = [
            'started_at' => Clock::stored($startedAt),
            'finished_at' => Clock::stored($finishedAt),
            'failed' => array_values(array_unique($report->failed())),
            'skip_streaks' => (object) $streaks,
        ];

        $this->mirror()->table('orrery_settings')->upsert(
            [['key' => self::KEY, 'value' => json_encode($outcome, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)]],
            ['key'],
            ['value'],
        );
    }

    /**
     * The stored outcome, or null when none is stored or it does not decode.
     *
     * @return Outcome|null
     */
    public function read(): ?array
    {
        $value = $this->mirror()->table('orrery_settings')->where('key', self::KEY)->value('value');

        return is_string($value) ? self::decode($value) : null;
    }

    /**
     * @return Outcome|null
     */
    public static function decode(string $value): ?array
    {
        $outcome = json_decode($value, true);

        if (! is_array($outcome) || ! is_numeric($outcome['started_at'] ?? null) || ! is_numeric($outcome['finished_at'] ?? null)) {
            return null;
        }

        $failed = array_values(array_filter(is_array($outcome['failed'] ?? null) ? $outcome['failed'] : [], 'is_string'));
        $streaks = [];

        foreach (is_array($outcome['skip_streaks'] ?? null) ? $outcome['skip_streaks'] : [] as $phase => $count) {
            if (is_string($phase) && is_int($count)) {
                $streaks[$phase] = $count;
            }
        }

        return ['started_at' => (float) $outcome['started_at'], 'finished_at' => (float) $outcome['finished_at'], 'failed' => $failed, 'skip_streaks' => $streaks];
    }

    private function mirror(): Connection
    {
        return Container::getInstance()->make(PackageSession::class)->mirror();
    }
}
