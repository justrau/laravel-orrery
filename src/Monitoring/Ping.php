<?php

namespace Justrau\Orrery\Monitoring;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Justrau\Orrery\Control\PauseCauses;
use Justrau\Orrery\Control\SignalState;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Database\Schema\SchemaStatus;
use Justrau\Orrery\Http\Controllers\HealthController;
use Justrau\Orrery\Runtime\OffSwitch;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The checks of the monitoring ping (`GET {path}/ping`, `orrery:ping`),
 * each one cheap: no count over a table.
 *
 * - `package_database`: one `select 1` on the mirror's session (with its
 *   connect timeout and statement timeout). A failure, a refused mirror or
 *   an open breaker is `unreachable`; then every other check is `unknown`,
 *   so a dead server costs one connect.
 * - `schema`: SchemaStatus::read(): the marker equals this release's
 *   version, else `migrate_needed` with both versions.
 * - `tick`: the age of `observer_tick_at` (the start of the last tick) at
 *   most `ping.tick_max_age` seconds, else `late` (the age is null when no
 *   tick has run).
 * - `observations`: no dropped observation in a health window that
 *   overlaps the last `ping.tick_max_age` seconds, else `dropped` with the
 *   sum (`at_least` when more windows overlap than one read takes).
 * - `long_waits`, only with `ping.long_waits`: no stored long-wait incident
 *   whose last alerting tick is that recent and whose quiet count is 0,
 *   unless a pause certainly holds its queue; else `over_limit` with the
 *   connection and queue names. It needs a tick in time: with a late tick
 *   it is `unknown`.
 *
 * A check that cannot be read fails with `unknown`: unknown is never ok.
 * Every read runs inside the watcher, so a failure is an answer, never an
 * exception. The result holds no job value, no process identity and no
 * configuration value: only versions, ages, counts and queue names.
 *
 * @phpstan-type Check array<string, mixed>
 * @phpstan-type Result array{status: 'ok'|'fail'|'disabled', checks?: array<string, Check>, as_of?: float}
 */
final class Ping
{
    public const DEFAULT_TICK_MAX_AGE = 300;

    public function __construct(private readonly Application $app) {}

    /**
     * @return Result
     */
    public function run(): array
    {
        $config = $this->app->make('config');

        if (! OffSwitch::enabled($config)) {
            return ['status' => 'disabled'];
        }

        $now = Clock::now();
        $maxAge = self::tickMaxAge($config);
        $sessions = $this->app->make(PackageSession::class);
        $watcher = Watcher::instance();
        $checks = [];

        $reachable = $sessions->mirrorRefusal() === null && SchemaStatus::siblingRefusal($sessions) === null
            && $watcher->attempt('ping:package_database', static fn (): mixed => $sessions->bounded($sessions->mirror(), static fn (): mixed => $sessions->mirror()->selectOne('select 1 as one')))->succeeded();
        $checks['package_database'] = $reachable ? ['ok' => true] : self::fail('unreachable');

        $checks['schema'] = $reachable ? self::schema($watcher, $sessions) : self::fail('unknown');
        $checks['tick'] = $reachable ? self::tick($watcher, $sessions, $now, $maxAge) : self::fail('unknown');
        $checks['observations'] = $reachable ? self::observations($watcher, $now, $maxAge) : self::fail('unknown');

        if (self::flag($config->get('orrery.ping.long_waits'))) {
            $checks['long_waits'] = $reachable && $checks['tick']['ok'] === true ? $this->longWaits($watcher, $now, $maxAge) : self::fail('unknown');
        }

        $ok = array_filter($checks, static fn (array $check): bool => $check['ok'] !== true) === [];

        return ['status' => $ok ? 'ok' : 'fail', 'checks' => $checks, 'as_of' => $now];
    }

    public static function tickMaxAge(Repository $config): int
    {
        $value = $config->get('orrery.ping.tick_max_age');

        return is_numeric($value) && (int) $value >= 1 ? (int) $value : self::DEFAULT_TICK_MAX_AGE;
    }

    /**
     * @return Check
     */
    private static function schema(Watcher $watcher, PackageSession $sessions): array
    {
        $read = $watcher->attempt('ping:schema', static fn (): array => SchemaStatus::read($sessions));

        if (! $read->succeeded()) {
            return self::fail('unknown');
        }

        /** @var array{installed: int|null, expected: int, hint: string|null} $status */
        $status = $read->value();

        if ($status['hint'] === null && $status['installed'] === $status['expected']) {
            return ['ok' => true, 'version' => $status['installed']];
        }

        return self::fail('migrate_needed') + ['installed' => $status['installed'], 'expected' => PackageSchema::currentVersion()];
    }

    /**
     * @return Check
     */
    private static function tick(Watcher $watcher, PackageSession $sessions, float $now, int $maxAge): array
    {
        $read = $watcher->attempt('ping:tick', static fn (): mixed => $sessions->mirror()->table('orrery_settings')->where('key', 'observer_tick_at')->value('value'));

        if (! $read->succeeded()) {
            return self::fail('unknown');
        }

        $value = $read->value();

        if ($value === null) {
            return self::fail('late') + ['age' => null];
        }

        if (! is_numeric($value)) {
            return self::fail('unknown');
        }

        $age = (int) round($now - (float) $value);

        return $age <= $maxAge ? ['ok' => true, 'age' => $age] : self::fail('late') + ['age' => $age];
    }

    /**
     * @return Check
     */
    private static function observations(Watcher $watcher, float $now, int $maxAge): array
    {
        $read = $watcher->attempt('ping:observations', static fn (): array => HealthController::readWindows($now - $maxAge, $now));

        if (! $read->succeeded()) {
            return self::fail('unknown');
        }

        /** @var array{0: list<array{dropped: int}>, 1: bool} $windows */
        $windows = $read->value();
        $dropped = array_sum(array_column($windows[0], 'dropped'));

        if ($dropped === 0 && ! $windows[1]) {
            return ['ok' => true, 'dropped' => 0];
        }

        if ($dropped === 0) {
            // More windows overlap than one read takes: the rest are not known.
            return self::fail('unknown');
        }

        return self::fail('dropped') + ['dropped' => $dropped, 'at_least' => $windows[1]];
    }

    /**
     * @return Check
     */
    private function longWaits(Watcher $watcher, float $now, int $maxAge): array
    {
        $read = $watcher->attempt('ping:long_waits', function () use ($now, $maxAge): array {
            $over = [];

            foreach ($this->app->make(LongWaitIncidents::class)->all() as [, $record]) {
                if ($record['quiet'] === 0 && $now - $record['last_seen'] <= $maxAge) {
                    $over[] = [$record['connection'], $record['queue']];
                }
            }

            if ($over === []) {
                return ['over' => [], 'unknown' => false];
            }

            $signals = $this->app->make(SignalState::class)->pauseRows();
            $native = $this->app->make(NativePause::class)->read($over);
            $failing = [];
            $unknown = false;

            foreach ($over as [$connection, $queue]) {
                $causes = PauseCauses::of($signals, $native[NativePause::key($connection, $queue)], $connection, $queue);
                $certain = array_filter($causes['pauses'], static fn (array $cause): bool => $cause['possible'] !== true);

                if ($certain !== []) {
                    continue;
                }

                if ($causes['pauses'] !== [] || $causes['paused_unknown']) {
                    $unknown = true;

                    continue;
                }

                $failing[] = ['connection' => $connection, 'queue' => $queue];
            }

            return ['over' => $failing, 'unknown' => $unknown];
        });

        if (! $read->succeeded()) {
            return self::fail('unknown');
        }

        /** @var array{over: list<array{connection: string, queue: string}>, unknown: bool} $result */
        $result = $read->value();

        if ($result['over'] !== []) {
            return self::fail('over_limit') + ['queues' => $result['over']];
        }

        return $result['unknown'] ? self::fail('unknown') : ['ok' => true];
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || (is_string($value) && filter_var($value, FILTER_VALIDATE_BOOL) === true) || $value === 1;
    }

    /**
     * @return array{ok: false, reason: string}
     */
    private static function fail(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }
}
