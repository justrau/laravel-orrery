<?php

namespace Justrau\Orrery\Monitoring;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The stored long-wait incidents: one orrery_settings row per
 * (connection, queue), with no time limit. Its key is PREFIX followed by
 * the sha1 of the names, and its value the JSON record
 * {connection, queue, started_at, channels, quiet, last_seen}:
 *
 * - `started_at`: the first tick at which the queue reached its threshold
 *   (WaitTime::alerting());
 * - `channels`: the channels whose start message was delivered
 *   (SendNotification adds them through delivered());
 * - `quiet`: the consecutive known ticks below the threshold;
 * - `last_seen`: the last tick at which the queue reached its threshold.
 *
 * advance() applies one tick's measured rows:
 * - a row that reaches its threshold opens an incident, or sets `quiet` to
 *   0 and `last_seen` to now;
 * - a known row (not `backlog_unknown`) below its threshold adds one quiet
 *   tick; at QUIET_TICKS the incident ends (the phase closes it and
 *   dispatches LongWaitRecovered);
 * - an unknown row sets `quiet` to 0: the quiet ticks must be consecutive
 *   known ticks;
 * - an incident without a row stays open, unless its connection is no
 *   longer a database-driver queue connection: then it is deleted, with
 *   no event.
 *
 * Every read and write goes through the mirror's package session.
 *
 * @phpstan-type Record array{connection: string, queue: string, started_at: float, channels: list<string>, quiet: int, last_seen: float}
 */
final class LongWaitIncidents
{
    /** The key prefix of an incident row (no LIKE wildcard in it). */
    public const PREFIX = 'orrery:longwait:incident:';

    /** The consecutive known quiet ticks that end an incident. */
    public const QUIET_TICKS = 3;

    /** The watched operation of the incident reads and writes. */
    public const OPERATION = 'long_wait:incident';

    private const TABLE = 'orrery_settings';

    public static function key(string $connection, string $queue): string
    {
        return self::PREFIX.sha1($connection."\0".$queue);
    }

    /**
     * Applies one tick's measured rows to the stored incidents, and returns
     * the incidents that end now (not yet deleted: close() does that). It
     * writes each changed record and deletes the incidents of removed
     * connections.
     *
     * @param  list<array{connection: string, queue: string, oldest_age: ?float, wait: ?int, threshold: int, backlog_unknown: bool}>  $rows
     * @return list<array{key: string, stored: string, record: Record}>
     */
    public function advance(array $rows, float $now): array
    {
        $stored = $this->all();
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
                $next = $record === null
                    ? ['connection' => $row['connection'], 'queue' => $row['queue'], 'started_at' => $now, 'channels' => [], 'quiet' => 0, 'last_seen' => $now]
                    : ['quiet' => 0, 'last_seen' => $now] + $record;
                $this->write($key, $next);

                continue;
            }

            if ($record === null) {
                continue;
            }

            if ($row['backlog_unknown']) {
                if ($record['quiet'] !== 0) {
                    $this->write($key, ['quiet' => 0] + $record);
                }

                continue;
            }

            $next = ['quiet' => $record['quiet'] + 1] + $record;

            if ($next['quiet'] >= self::QUIET_TICKS) {
                $ended[] = ['key' => $key, 'stored' => (string) $value, 'record' => $next];

                continue;
            }

            $this->write($key, $next);
        }

        foreach ($stored as $key => [$value, $record]) {
            if (! isset($seen[$key]) && ! $this->configured($record['connection'])) {
                $this->mirror()->table(self::TABLE)->where('key', $key)->where('value', $value)->delete();
            }
        }

        return $ended;
    }

    /**
     * Deletes an ended incident, guarded by its stored value; true when
     * this call deleted it (so its recovery is dispatched once).
     */
    public function close(string $key, string $stored): bool
    {
        return $this->mirror()->table(self::TABLE)->where('key', $key)->where('value', $stored)->delete() === 1;
    }

    /**
     * Adds channels to the open incident of a queue; nothing when none is
     * open.
     *
     * @param  list<string>  $channels
     */
    public function delivered(string $connection, string $queue, array $channels): void
    {
        $key = self::key($connection, $queue);
        $value = $this->mirror()->table(self::TABLE)->where('key', $key)->value('value');
        $record = is_string($value) ? self::decode($value) : null;

        if ($record === null) {
            return;
        }

        $merged = array_values(array_unique([...$record['channels'], ...$channels]));

        if ($merged !== $record['channels']) {
            $this->mirror()->table(self::TABLE)->where('key', $key)->where('value', $value)->update(['value' => self::encode(['channels' => $merged] + $record)]);
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
     * @param  Record  $record
     */
    private function write(string $key, array $record): void
    {
        $this->mirror()->table(self::TABLE)->upsert([['key' => $key, 'value' => self::encode($record)]], ['key'], ['value']);
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
            'last_seen' => $record['last_seen'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
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
