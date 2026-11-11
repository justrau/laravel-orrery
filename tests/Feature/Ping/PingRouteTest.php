<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Ping;

use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Storage\Clock;

/**
 * GET {orrery.path}/ping: the check for an uptime monitor, behind a token
 * instead of the dashboard login.
 */
final class PingRouteTest extends PingTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if ($this->name() === 'test_without_a_token_the_endpoint_is_off') {
            $app['config']->set('orrery.ping.token', null);
        }

        if ($this->name() === 'test_long_waits_fail_only_when_enabled_and_not_paused') {
            $app['config']->set('orrery.ping.long_waits', true);
        }

        if ($this->name() === 'test_the_package_off_answers_disabled_after_the_token') {
            $app['config']->set('orrery.enabled', false);
        }
    }

    /**
     * Rejects: a ping that answers anything but a plain 404 without a configured token, also with a token given.
     */
    public function test_without_a_token_the_endpoint_is_off(): void
    {
        $this->get('/orrery/ping')->assertNotFound();
        $this->get('/orrery/ping?token=anything')->assertNotFound();
        $this->get('/orrery/ping', ['Authorization' => 'Bearer '])->assertNotFound();
    }

    /**
     * Rejects: a ping that accepts a missing, empty or wrong token on any transport, or that says why.
     * Still passes: a comparison that is not constant-time (hash_equals is not observable here).
     */
    public function test_a_missing_or_wrong_token_is_403_without_detail(): void
    {
        $responses = [
            $this->get('/orrery/ping'),
            $this->get('/orrery/ping?token='),
            $this->get('/orrery/ping?token=wrong'),
            // Read raw: the host's TrimStrings must not turn " <token>" into the token.
            $this->get('/orrery/ping?token=%20'.self::TOKEN),
            $this->get('/orrery/ping?token[]='.self::TOKEN),
            $this->get('/orrery/ping', ['Authorization' => 'Bearer wrong']),
            $this->get('/orrery/ping', ['X-Orrery-Token' => 'wrong']),
            $this->get('/orrery/ping', ['X-Orrery-Token' => self::TOKEN.'x']),
            $this->get('/orrery/ping', ['Authorization' => 'Basic '.base64_encode('a:'.self::TOKEN)]),
        ];

        foreach ($responses as $i => $response) {
            self::assertSame(403, $response->status(), "request {$i}");
            self::assertSame('', (string) $response->getContent(), "request {$i}");
        }
    }

    /**
     * Rejects: a transport that the docs name but the ping does not read, and an ok answer whose shape or headers
     * differ (no-store, every check with ok true).
     */
    public function test_the_right_token_on_each_transport_answers_ok(): void
    {
        foreach ([
            $this->get('/orrery/ping', ['Authorization' => 'Bearer '.self::TOKEN]),
            $this->get('/orrery/ping', ['X-Orrery-Token' => self::TOKEN]),
            $this->get('/orrery/ping?token='.self::TOKEN),
        ] as $response) {
            $response->assertOk();
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $body = $response->json();
            self::assertSame('ok', $body['status']);
            self::assertSame(['package_database', 'schema', 'tick', 'observations'], array_keys($body['checks']));
            self::assertSame(['ok' => true], $body['checks']['package_database']);
            self::assertSame(['ok' => true, 'version' => PackageSchema::currentVersion()], $body['checks']['schema']);
            self::assertTrue($body['checks']['tick']['ok']);
            self::assertEqualsWithDelta(10, $body['checks']['tick']['age'], 2);
            self::assertSame(['ok' => true, 'dropped' => 0], $body['checks']['observations']);
            self::assertIsNumeric($body['as_of']);
        }
    }

    /**
     * Rejects: a ping without the dashboard login that still needs the view ability.
     */
    public function test_the_dashboard_login_is_not_needed(): void
    {
        Orrery::auth(static fn (): bool => false);

        $this->ping()->assertOk();
        $this->getJson('/orrery/api/health')->assertStatus(401);
    }

    /**
     * Rejects: a HEAD that is refused or that carries a body.
     */
    public function test_head_works_like_get(): void
    {
        $response = $this->call('HEAD', '/orrery/ping', server: ['HTTP_X_ORRERY_TOKEN' => self::TOKEN]);

        self::assertSame(200, $response->status());
        self::assertSame('', (string) $response->getContent());
        self::assertSame(403, $this->call('HEAD', '/orrery/ping')->status());
    }

    /**
     * Rejects: a late tick (or no tick) that passes, and a late answer without the age.
     */
    public function test_a_late_tick_fails(): void
    {
        $this->tickAgo(301);
        $response = $this->ping()->assertStatus(503);

        self::assertSame('fail', $response->json('status'));
        self::assertSame(false, $response->json('checks.tick.ok'));
        self::assertSame('late', $response->json('checks.tick.reason'));
        self::assertGreaterThanOrEqual(301, $response->json('checks.tick.age'));
        self::assertTrue($response->json('checks.schema.ok'), 'only the tick fails');
        self::assertTrue($response->json('checks.observations.ok'), 'only the tick fails');

        $this->mirror()->table('orrery_settings')->where('key', 'observer_tick_at')->delete();
        $response = $this->ping()->assertStatus(503);
        self::assertSame(['ok' => false, 'reason' => 'late', 'age' => null], $response->json('checks.tick'));
    }

    /**
     * Rejects: a tick time that cannot be read and passes (unknown is never ok), or that reads as late.
     */
    public function test_an_unreadable_tick_is_unknown_and_fails(): void
    {
        $this->setting('observer_tick_at', 'garbage');

        $response = $this->ping()->assertStatus(503);
        self::assertSame(['ok' => false, 'reason' => 'unknown'], $response->json('checks.tick'));
    }

    /**
     * Rejects: a schema behind this release that passes.
     */
    public function test_an_old_schema_needs_migrate(): void
    {
        $marker = $this->mirror()->table(PackageSchema::MARKER)->where('id', 1);
        $marker->update(['version' => PackageSchema::currentVersion() - 1]);

        try {
            $response = $this->ping()->assertStatus(503);
            self::assertSame(['ok' => false, 'reason' => 'migrate_needed', 'installed' => PackageSchema::currentVersion() - 1, 'expected' => PackageSchema::currentVersion()], $response->json('checks.schema'));
            self::assertTrue($response->json('checks.tick.ok'), 'only the schema fails');
        } finally {
            // The reuse mode keeps the marker row for the next tests.
            $this->mirror()->table(PackageSchema::MARKER)->where('id', 1)->update(['version' => PackageSchema::currentVersion()]);
        }
    }

    /**
     * Rejects: dropped observations in a recent health window that pass, and an old window that still fails.
     */
    public function test_recent_dropped_observations_fail(): void
    {
        $this->droppedWindow(0);
        $this->ping()->assertOk();

        $this->droppedWindow(3, startedAgo: 20);
        $response = $this->ping()->assertStatus(503);
        self::assertSame(['ok' => false, 'reason' => 'dropped', 'dropped' => 3, 'at_least' => false], $response->json('checks.observations'));
        self::assertTrue($response->json('checks.tick.ok'), 'only the observations fail');
    }

    public function test_an_old_closed_window_with_drops_passes(): void
    {
        $this->mirror()->table('orrery_health_events')->insert([
            'context' => 'worker', 'process' => 'host:2:2', 'dropped' => 5,
            'started_at' => Clock::toDecimal(Clock::now() - 7200),
            'ended_at' => Clock::toDecimal(Clock::now() - 3600),
        ]);

        $this->ping()->assertOk();
    }

    /**
     * Rejects: a long-wait check that runs without ping.long_waits, that passes a queue over its limit, or that fails
     * a paused one.
     */
    public function test_long_waits_fail_only_when_enabled_and_not_paused(): void
    {
        $this->ping()->assertOk()->assertJsonPath('checks.long_waits', ['ok' => true]);

        $this->overLimit('database', 'default');
        $response = $this->ping()->assertStatus(503);
        self::assertSame(['ok' => false, 'reason' => 'over_limit', 'queues' => [['connection' => 'database', 'queue' => 'default']]], $response->json('checks.long_waits'));

        $this->artisan('orrery:pause')->assertExitCode(0);
        $this->ping()->assertOk();
    }

    public function test_long_waits_are_not_checked_by_default(): void
    {
        $this->overLimit();

        $response = $this->ping()->assertOk();
        self::assertArrayNotHasKey('long_waits', $response->json('checks'));
    }

    /**
     * Rejects: a body that carries the token, a job value or a configuration secret.
     */
    public function test_the_body_carries_no_secret(): void
    {
        $this->app['config']->set('app.key', 'base64:c2VjcmV0LWtleS1zZWNyZXQta2V5LXNlY3JldC0xMjM=');
        $this->droppedWindow(1);
        $body = (string) $this->ping(headers: ['Authorization' => 'Bearer '.self::TOKEN])->getContent();

        self::assertStringNotContainsString(self::TOKEN, $body);
        self::assertStringNotContainsString('c2VjcmV0', $body);
        self::assertStringNotContainsString('host:1:1', $body, 'no process identity');
    }

    /**
     * Rejects: a ping without a rate limit, so the token can be guessed at full speed.
     */
    public function test_requests_are_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            self::assertNotSame(429, $this->get('/orrery/ping?token=wrong')->status(), "request {$i}");
        }

        $this->get('/orrery/ping?token='.self::TOKEN)->assertStatus(429);
    }

    /**
     * Rejects: a disabled package that answers anything but 503 disabled, or that answers it before the token check.
     */
    public function test_the_package_off_answers_disabled_after_the_token(): void
    {
        $this->get('/orrery/ping?token=wrong')->assertForbidden();

        $response = $this->ping()->assertStatus(503);
        self::assertSame(['status' => 'disabled'], $response->json());
    }
}
