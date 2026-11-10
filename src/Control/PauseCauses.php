<?php

namespace Justrau\Orrery\Control;

use Justrau\Orrery\Monitoring\NativePause;

/**
 * The effective pause of a queue and its causes, the one
 * rule of /api/workload and /api/control.
 *
 * A queue (c, q) is paused when a global pause row exists, or a connection
 * pause row for c exists, or Laravel's isPaused(c, q) is true (which on
 * Laravel 13 also covers its global pause). The scopes are an OR: no row
 * overrides another, and there are no continue rows.
 *
 * of() gives:
 * - `pauses`: every cause, each `{scope, source, issued_at, issued_by,
 *   possible}`, in the order global, connection, queue. Sources: `orrery`
 *   (a signal row, or the annotation of a queue pause), `queue:pause`
 *   (Laravel's queue pause without an annotation; issued_* null) and
 *   `queue:pause --all` (Laravel's global pause, scope `global`). `possible`
 *   is true only for an Orrery queue annotation whose native flag is
 *   unknown: the queue may be paused, so it never counts as a definite
 *   pause and never suppresses the attention rule.
 * - An annotation is display-only. With a known native flag it shows only
 *   while the flag is on (a native queue:continue ends it); with an unknown
 *   flag it is a possible cause.
 * - `paused_unknown`: the signal rows could not be read, or isPaused()
 *   failed.
 * - `queue_cause_unknown`: Laravel's global pause masks the queue's own
 *   flag (NativePause::MASKED).
 * - `native_unknown`: the native state was not read, because the default
 *   cache store is on a host SQLite file (NativePause::UNSAFE), with
 *   `native_hint`.
 *
 * @phpstan-import-type State from NativePause
 *
 * @phpstan-type Cause array{scope: string, source: string, issued_at: float|null, issued_by: string|null, possible: bool}
 * @phpstan-type SignalRow array{signal: string, scope: string, scope_value: string, issued_at: float, issued_by: ?string}
 */
final class PauseCauses
{
    public const ORRERY = 'orrery';

    public const QUEUE_PAUSE = 'queue:pause';

    public const QUEUE_PAUSE_ALL = 'queue:pause --all';

    /**
     * @param  list<SignalRow>|null  $rows  the unexpired pause rows (SignalState::pauseRows()), or null when the read failed
     * @param  State  $native  NativePause::read() for (connection, queue)
     * @return array{pauses: list<Cause>, paused_unknown: bool, queue_cause_unknown: bool, native_unknown: bool, native_hint: ?string}
     */
    public static function of(?array $rows, array $native, string $connection, string $queue): array
    {
        $pauses = [];
        $global = $rows === null ? null : self::row($rows, 'global', '');
        $own = $rows === null ? null : self::row($rows, 'connection', $connection);
        $annotation = $rows === null ? null : self::row($rows, 'queue', "{$connection}:{$queue}");

        if ($global !== null) {
            $pauses[] = self::cause('global', self::ORRERY, $global, false);
        }

        if ($native['global'] === true) {
            $pauses[] = self::cause('global', self::QUEUE_PAUSE_ALL, null, false);
        }

        if ($own !== null) {
            $pauses[] = self::cause('connection', self::ORRERY, $own, false);
        }

        if ($native['queue'] === true) {
            $pauses[] = $annotation !== null
                ? self::cause('queue', self::ORRERY, $annotation, false)
                : self::cause('queue', self::QUEUE_PAUSE, null, false);
        } elseif ($native['queue'] === null && $annotation !== null) {
            $pauses[] = self::cause('queue', self::ORRERY, $annotation, true);
        }

        return [
            'pauses' => $pauses,
            'paused_unknown' => $rows === null || $native['unknown'] === NativePause::FAILED,
            'queue_cause_unknown' => $native['unknown'] === NativePause::MASKED,
            'native_unknown' => $native['unknown'] === NativePause::UNSAFE,
            'native_hint' => $native['unknown'] === NativePause::UNSAFE ? NativePause::UNSAFE_HINT : null,
        ];
    }

    /**
     * The newest pause row of exactly this scope, or null.
     *
     * @param  list<SignalRow>  $rows
     * @return SignalRow|null
     */
    private static function row(array $rows, string $scope, string $value): ?array
    {
        $found = null;

        foreach ($rows as $row) {
            if ($row['signal'] === SignalState::PAUSE && $row['scope'] === $scope && $row['scope_value'] === $value
                && ($found === null || $row['issued_at'] > $found['issued_at'])) {
                $found = $row;
            }
        }

        return $found;
    }

    /**
     * @param  SignalRow|null  $row
     * @return Cause
     */
    private static function cause(string $scope, string $source, ?array $row, bool $possible): array
    {
        return [
            'scope' => $scope,
            'source' => $source,
            'issued_at' => $row['issued_at'] ?? null,
            'issued_by' => $row['issued_by'] ?? null,
            'possible' => $possible,
        ];
    }
}
