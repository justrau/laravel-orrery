<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Ping;

use Illuminate\Testing\TestResponse;
use Justrau\Orrery\Monitoring\LongWaitIncidents;
use Justrau\Orrery\Monitoring\LongWaitMeasured;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The fixtures of the ping tests: a fresh tick by default, and helpers that
 * make one check fail.
 */
abstract class PingTestCase extends TestCase
{
    protected const TOKEN = 'ping-secret-0123456789abcdef0123456789ab';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('orrery.ping.token', self::TOKEN);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tickAgo(10);
    }

    protected function tickAgo(float $seconds): void
    {
        $this->setting('observer_tick_at', Clock::toDecimal(Clock::now() - $seconds));
    }

    protected function setting(string $key, string $value): void
    {
        $this->mirror()->table('orrery_settings')->updateOrInsert(['key' => $key], ['value' => $value]);
    }

    protected function droppedWindow(int $dropped, float $startedAgo = 30): void
    {
        $this->mirror()->table('orrery_health_events')->insert([
            'context' => 'worker',
            'process' => 'host:1:1',
            'started_at' => Clock::toDecimal(Clock::now() - $startedAgo),
            'ended_at' => null,
            'dropped' => $dropped,
        ]);
    }

    /**
     * The long-wait phase's measurement state: its last run $ago seconds ago, complete or not, and its last
     * complete run $completeAgo seconds ago (null: never).
     */
    protected function measured(float $ago = 20, bool $complete = true, ?float $completeAgo = 20): void
    {
        $now = Clock::now();
        $this->setting(LongWaitMeasured::KEY, (string) json_encode(['at' => $now - $ago, 'complete' => $complete, 'complete_at' => $completeAgo === null ? null : $now - $completeAgo], JSON_PRESERVE_ZERO_FRACTION));
    }

    protected function window(string $context, int $dropped, float $startedAgo, ?float $endedAgo = null, ?float $errorAgo = null, string $process = 'host:9:9'): void
    {
        $now = Clock::now();

        $this->mirror()->table('orrery_health_events')->insert([
            'context' => $context,
            'process' => $process,
            'started_at' => Clock::toDecimal($now - $startedAgo),
            'ended_at' => $endedAgo === null ? null : Clock::toDecimal($now - $endedAgo),
            'dropped' => $dropped,
            'last_error_operation' => $errorAgo === null ? null : 'x',
            'last_error_at' => $errorAgo === null ? null : Clock::toDecimal($now - $errorAgo),
        ]);
    }

    protected function overLimit(string $connection = 'database', string $queue = 'default', float $lastSeenAgo = 20): void
    {
        $now = Clock::now();

        $this->setting(LongWaitIncidents::key($connection, $queue), (string) json_encode([
            'connection' => $connection, 'queue' => $queue, 'started_at' => $now - 300, 'channels' => [],
            'quiet' => 0, 'quiet_minute' => null, 'last_seen' => $now - $lastSeenAgo,
        ], JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function ping(?string $query = null, array $headers = [])
    {
        return $this->get('/orrery/ping'.($query === null ? '' : '?'.$query), $headers + ['X-Orrery-Token' => self::TOKEN]);
    }
}
