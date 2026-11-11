<?php

namespace Justrau\Orrery\Monitoring;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The measurement state of the long-wait phase, in the orrery_settings row
 * KEY: `at` (its last run), `complete` (whether that run measured every
 * queue it may read) and `complete_at` (its last complete run, or null).
 *
 * LongWaitPhase records it after each measurement, before it can throw
 * MeasurementIncomplete. A queue on an application SQLite file without
 * automatic reads (D18) is unknown by design and does not make a run
 * incomplete. The monitoring ping (Ping, `long_waits`) reads it: the stored
 * long-wait incidents only say what the last complete measurement saw,
 * because an unknown row never refreshes an incident's `last_seen`.
 *
 * @phpstan-type State array{at: float, complete: bool, complete_at: float|null}
 */
final class LongWaitMeasured
{
    public const KEY = 'long_wait_measured';

    public function record(bool $complete, float $now): void
    {
        $previous = $this->read();
        $state = [
            'at' => Clock::stored($now),
            'complete' => $complete,
            'complete_at' => $complete ? Clock::stored($now) : ($previous['complete_at'] ?? null),
        ];

        $this->mirror()->table('orrery_settings')->upsert(
            [['key' => self::KEY, 'value' => json_encode($state, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)]],
            ['key'],
            ['value'],
        );
    }

    /**
     * The stored state, or null when none is stored or it does not decode.
     *
     * @return State|null
     */
    public function read(): ?array
    {
        $value = $this->mirror()->table('orrery_settings')->where('key', self::KEY)->value('value');
        $state = is_string($value) ? json_decode($value, true) : null;

        if (! is_array($state) || ! is_numeric($state['at'] ?? null) || ! is_bool($state['complete'] ?? null)) {
            return null;
        }

        $completeAt = $state['complete_at'] ?? null;

        return [
            'at' => (float) $state['at'],
            'complete' => $state['complete'],
            'complete_at' => is_numeric($completeAt) ? (float) $completeAt : null,
        ];
    }

    private function mirror(): Connection
    {
        return Container::getInstance()->make(PackageSession::class)->mirror();
    }
}
