<?php

namespace Justrau\Orrery\Runtime;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The health windows in orrery_health_events, on the
 * package session (PackageSession::mirror()). One row per (process, started_at): the unique key
 * `window`. Every time is bound through Clock::toDecimal().
 */
final class HealthEvents
{
    public const TABLE = 'orrery_health_events';

    /**
     * Inserts or updates the row of one window with the values of the
     * in-memory record.
     *
     * @param  array{started_at: float, ended_at: ?float, dropped: int, unmatched_observations: int, unverified: int, rollback_unverified?: int, last_error_operation: ?string, last_error_at: ?float}  $window
     */
    public static function write(string $context, string $process, array $window): void
    {
        $row = [
            'context' => substr($context, 0, 16),
            'process' => substr($process, 0, 255),
            'started_at' => Clock::toDecimal($window['started_at']),
            'ended_at' => $window['ended_at'] === null ? null : Clock::toDecimal($window['ended_at']),
            'dropped' => $window['dropped'],
            'unmatched_observations' => $window['unmatched_observations'],
            'unverified' => $window['unverified'],
            'rollback_unverified' => $window['rollback_unverified'] ?? 0,
            'last_error_operation' => $window['last_error_operation'] === null ? null : substr($window['last_error_operation'], 0, 191),
            'last_error_at' => $window['last_error_at'] === null ? null : Clock::toDecimal($window['last_error_at']),
        ];

        self::connection()->table(self::TABLE)->upsert(
            [$row],
            ['process', 'started_at'],
            ['context', 'ended_at', 'dropped', 'unmatched_observations', 'unverified', 'rollback_unverified', 'last_error_operation', 'last_error_at'],
        );
    }

    /**
     * Deletes the closed windows that ended before $cutoff, and the open
     * windows that started and had their last error before it
     * (a process that ended without its terminating flush, for example a
     * killed worker, leaves its window open). A live process whose window
     * the delete removed writes it again at its next flush. Returns the
     * deleted rows.
     */
    public static function pruneBefore(float $cutoff): int
    {
        $before = Clock::toDecimal($cutoff);

        return self::connection()->table(self::TABLE)
            ->where(static fn ($query) => $query
                ->where(static fn ($closed) => $closed->whereNotNull('ended_at')->where('ended_at', '<', $before))
                ->orWhere(static fn ($open) => $open->whereNull('ended_at')->where('started_at', '<', $before)
                    ->where(static fn ($error) => $error->whereNull('last_error_at')->orWhere('last_error_at', '<', $before))))
            ->delete();
    }

    private static function connection(): Connection
    {
        return Container::getInstance()->make(PackageSession::class)->mirror();
    }
}
