<?php

namespace Justrau\Orrery\Monitoring;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The stored long-wait incidents and their pending recovery messages, in
 * orrery_settings rows with no time limit.
 *
 * An incident: one row per (connection, queue), key PREFIX followed by the
 * sha1 of the names, value the JSON record {connection, queue, started_at,
 * channels, quiet, quiet_minute, last_seen}:
 * - `started_at`: the first tick at which the queue reached its threshold
 *   (WaitTime::alerting());
 * - `channels`: the channels whose start message was delivered
 *   (SendNotification adds them through delivered());
 * - `quiet`: the consecutive known minutes below the threshold, and
 *   `quiet_minute` the last minute counted;
 * - `last_seen`: the last tick at which the queue reached its threshold.
 *
 * advance() applies one tick's measured rows:
 * - a row that reaches its threshold opens an incident, or sets `quiet` to
 *   0 and `last_seen` to now. A stored incident whose `last_seen` is older
 *   than STALE_SECONDS is replaced by a new one;
 * - a known row (not `backlog_unknown`) below its threshold adds one quiet
 *   tick, at most one per minute (several servers or a manual tick cannot
 *   shorten the hysteresis); at QUIET_TICKS the incident ends (the phase
 *   closes it and dispatches LongWaitRecovered);
 * - an unknown row and a missing row set `quiet` to 0: the quiet minutes
 *   must be consecutive known minutes;
 * - an incident without a row is deleted, with no event, when its
 *   connection is no longer a database-driver queue connection or its
 *   `last_seen` is older than STALE_SECONDS (the queue left the
 *   measurement).
 *
 * Every write is guarded by the value that was read: a new row is an
 * insert-or-ignore, a change an update WHERE value = the stored value, so
 * a concurrent change wins and this one is dropped.
 *
 * A pending recovery: one row per ended incident whose recovery message
 * some channels did not get (down, failed or out of the budget), key
 * RECOVERY_PREFIX, value {connection, queue, seconds, started_at,
 * channels, until}. SendRecoveredNotification retries it on later ticks
 * until `until`.
 *
 * Every read and write goes through the mirror's package session.
 *
 * @phpstan-type Record array{connection: string, queue: string, started_at: float, channels: list<string>, quiet: int, quiet_minute: ?int, last_seen: float}
 * @phpstan-type Recovery array{connection: string, queue: string, seconds: int, started_at: float, channels: list<string>, until: float}
 */
final class LongWaitIncidents
{
    /** The key prefix of an incident row (no LIKE wildcard in it). */
    public const PREFIX = 'orrery:longwait:incident:';

    /** The key prefix of a pending recovery row (no LIKE wildcard in it). */
    public const RECOVERY_PREFIX = 'orrery:longwait:recovery:';

    /** The consecutive known quiet minutes that end an incident. */
    public const QUIET_TICKS = 3;

    /** An incident not seen alerting for this long is stale (the window of the measured queues). */
    public const STALE_SECONDS = WaitTime::SAMPLE_SECONDS;

    /** The watched operation of the incident reads and writes. */
    public const OPERATION = 'long_wait:incident';

    private const TABLE = 'orrery_settings';

    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public static function key(string $connection, string $queue): string
    {
        return self::PREFIX.sha1($connection."\0".$queue);
    }

    /**
     * Applies one tick's measured rows to the stored incidents, and returns
     * the incidents that end now (not yet deleted: close() does that).
     *
     * @param  list<array{connection: string, queue: string, oldest_age: ?float, wait: ?int, threshold: int, backlog_unknown: bool}>  $rows
     * @return list<array{key: string, stored: string, record: Record}>
     */
    public function advance(array $rows, float $now): array
    {
        $stored = $this->all();
        $minute = SampleCounts::minuteOf($now);
        $alerting = [];

        foreach (WaitTime::alerting($rows) as $row) {
            $alerting[self::key($row['connection'], $row['queue'])] = true;
        }

        $seen = [];
        $ended = [];

        foreach ($rows as $row) {
            $key = self::key($row['connection'], $row['queue']);
            $seen[$key] = true;
            [$value, $record] = $stored[$key] ?? [null, null];

            if (isset($alerting[$key])) {
                $next = $record === null || $record['last_seen'] < $now - self::STALE_SECONDS
                    ? ['connection' => $row['connection'], 'queue' => $row['queue'], 'started_at' => $now, 'channels' => [], 'quiet' => 0, 'quiet_minute' => null, 'last_seen' => $now]
                    : ['quiet' => 0, 'quiet_minute' => null, 'last_seen' => $now] + $record;
                $this->write($key, $next, $value);

                continue;
            }

            if ($record === null) {
                continue;
            }

            if ($row['backlog_unknown']) {
                $this->resetQuiet($key, $record, (string) $value);

                continue;
            }

            if ($record['quiet_minute'] === $minute) {
                continue;
            }

            $next = ['quiet' => $record['quiet'] + 1, 'quiet_minute' => $minute] + $record;

            if ($next['quiet'] >= self::QUIET_TICKS) {
                $ended[] = ['key' => $key, 'stored' => (string) $value, 'record' => $next];

                continue;
            }

            $this->write($key, $next, $value);
        }

        foreach ($stored as $key => [$value, $record]) {
            if (isset($seen[$key])) {
                continue;
            }

            if ($record['last_seen'] < $now - self::STALE_SECONDS || ! $this->configured($record['connection'])) {
                $this->close($key, $value);
            } else {
                $this->resetQuiet($key, $record, $value);
            }
        }

        return $ended;
    }

    /**
     * Deletes a row, guarded by its stored value; true when this call
     * deleted it (so a recovery is dispatched once).
     */
    public function close(string $key, string $stored): bool
    {
        return $this->mirror()->table(self::TABLE)->where('key', $key)->where('value', $stored)->delete() === 1;
    }

    /**
     * Adds channels to the open incident of a queue; nothing when none is
     * open. A guarded update that misses (a concurrent change) reads the
     * record again and merges once more.
     *
     * @param  list<string>  $channels
     */
    public function delivered(string $connection, string $queue, array $channels): void
    {
        $key = self::key($connection, $queue);

        for ($try = 0; $try < 2; $try++) {
            $value = $this->mirror()->table(self::TABLE)->where('key', $key)->value('value');
            $record = is_string($value) ? self::decode($value) : null;

            if ($record === null) {
                return;
            }

            $merged = array_values(array_unique([...$record['channels'], ...$channels]));

            if ($merged === $record['channels'] || $this->write($key, ['channels' => $merged] + $record, $value)) {
                return;
            }
        }
    }

    /**
     * Every stored incident: its stored value and its record, by key. A row
     * that does not decode is skipped.
     *
     * @return array<string, array{0: string, 1: Record}>
     */
    public function all(): array
    {
        $incidents = [];

        foreach ($this->mirror()->table(self::TABLE)->where('key', 'like', self::PREFIX.'%')->get(['key', 'value']) as $row) {
            $record = is_string($row->value) ? self::decode($row->value) : null;

            if ($record !== null) {
                $incidents[(string) $row->key] = [(string) $row->value, $record];
            }
        }

        return $incidents;
    }

    /**
     * Stores the channels that did not get a recovery message, to retry
     * them until $until.
     *
     * @param  list<string>  $channels
     */
    public function keepRecovery(string $connection, string $queue, int $seconds, float $startedAt, array $channels, float $until): void
    {
        $key = self::RECOVERY_PREFIX.sha1($connection."\0".$queue."\0".sprintf('%.6F', $startedAt));
        $value = json_encode(['connection' => $connection, 'queue' => $queue, 'seconds' => $seconds, 'started_at' => $startedAt, 'channels' => $channels, 'until' => $until], self::JSON);
        $this->mirror()->table(self::TABLE)->insertOrIgnore([['key' => $key, 'value' => $value]]);
    }

    /**
     * Every pending recovery: its stored value and its record, by key.
     *
     * @return array<string, array{0: string, 1: Recovery}>
     */
    public function recoveries(): array
    {
        $recoveries = [];

        foreach ($this->mirror()->table(self::TABLE)->where('key', 'like', self::RECOVERY_PREFIX.'%')->get(['key', 'value']) as $row) {
            $data = is_string($row->value) ? json_decode($row->value, true) : null;

            if (! is_array($data) || ! is_string($data['connection'] ?? null) || ! is_string($data['queue'] ?? null) || ! is_int($data['seconds'] ?? null)
                || ! is_numeric($data['started_at'] ?? null) || ! is_array($data['channels'] ?? null) || ! is_numeric($data['until'] ?? null)) {
                continue;
            }

            $recoveries[(string) $row->key] = [(string) $row->value, [
                'connection' => $data['connection'],
                'queue' => $data['queue'],
                'seconds' => $data['seconds'],
                'started_at' => (float) $data['started_at'],
                'channels' => array_values(array_filter($data['channels'], 'is_string')),
                'until' => (float) $data['until'],
            ]];
        }

        return $recoveries;
    }

    /**
     * Keeps only $channels of a pending recovery (guarded by its stored
     * value), or deletes it when none is left.
     *
     * @param  Recovery  $recovery
     * @param  list<string>  $channels
     */
    public function narrowRecovery(string $key, string $stored, array $recovery, array $channels): void
    {
        if ($channels === []) {
            $this->close($key, $stored);

            return;
        }

        if ($channels !== $recovery['channels']) {
            $this->mirror()->table(self::TABLE)->where('key', $key)->where('value', $stored)
                ->update(['value' => json_encode(['channels' => $channels] + $recovery, self::JSON)]);
        }
    }

    /**
     * @param  Record  $record
     */
    private function resetQuiet(string $key, array $record, string $stored): void
    {
        if ($record['quiet'] !== 0 || $record['quiet_minute'] !== null) {
            $this->write($key, ['quiet' => 0, 'quiet_minute' => null] + $record, $stored);
        }
    }

    /**
     * A new row ($stored null) with an insert-or-ignore, else an update
     * guarded by the stored value. True when the row was written.
     *
     * @param  Record  $record
     */
    private function write(string $key, array $record, ?string $stored): bool
    {
        $value = self::encode($record);

        if ($stored === null) {
            return $this->mirror()->table(self::TABLE)->insertOrIgnore([['key' => $key, 'value' => $value]]) === 1;
        }

        return $this->mirror()->table(self::TABLE)->where('key', $key)->where('value', $stored)->update(['value' => $value]) === 1;
    }

    /**
     * @param  Record  $record
     */
    private static function encode(array $record): string
    {
        return json_encode([
            'connection' => $record['connection'],
            'queue' => $record['queue'],
            'started_at' => $record['started_at'],
            'channels' => $record['channels'],
            'quiet' => $record['quiet'],
            'quiet_minute' => $record['quiet_minute'],
            'last_seen' => $record['last_seen'],
        ], self::JSON);
    }

    /**
     * @return Record|null
     */
    private static function decode(string $value): ?array
    {
        $data = json_decode($value, true);

        if (! is_array($data) || ! is_string($data['connection'] ?? null) || ! is_string($data['queue'] ?? null)
            || ! is_numeric($data['started_at'] ?? null) || ! is_array($data['channels'] ?? null) || ! is_int($data['quiet'] ?? null) || ! is_numeric($data['last_seen'] ?? null)) {
            return null;
        }

        return [
            'connection' => $data['connection'],
            'queue' => $data['queue'],
            'started_at' => (float) $data['started_at'],
            'channels' => array_values(array_filter($data['channels'], 'is_string')),
            'quiet' => $data['quiet'],
            'quiet_minute' => is_int($data['quiet_minute'] ?? null) ? $data['quiet_minute'] : null,
            'last_seen' => (float) $data['last_seen'],
        ];
    }

    private function configured(string $connection): bool
    {
        $settings = Container::getInstance()->make('config')->get('queue.connections.'.$connection);

        return is_array($settings) && ($settings['driver'] ?? null) === 'database';
    }

    private function mirror(): Connection
    {
        return Container::getInstance()->make(PackageSession::class)->mirror();
    }
}
