<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Ping;

use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Monitoring\LongWaitMeasured;
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

        if (str_starts_with($this->name(), 'test_long_waits_') && $this->name() !== 'test_long_waits_are_not_checked_by_default') {
            $app['config']->set('orrery.ping.long_waits', true);
        }

        if ($this->name() === 'test_a_short_token_turns_the_ping_off') {
            $app['config']->set('orrery.ping.token', str_repeat('a', 31));
        }

        if ($this->name() === 'test_a_host_sqlite_limiter_store_skips_the_limit') {
            $file = sys_get_temp_dir().'/orrery-ping-cache-'.getmypid().'.sqlite';
            touch($file);
            $app['config']->set('database.connections.hostcache', ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false]);
            $app['config']->set('cache.stores.hostdb', ['driver' => 'database', 'connection' => 'hostcache', 'table' => 'cache']);
            $app['config']->set('cache.limiter', 'hostdb');
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
     * Rejects: a ping that runs the session, cookie or CSRF middleware of `web`: StartSession would store the URL
     * with ?token= as `_previous.url` in a new session per monitor hit, and every answer would set cookies.
     */
    public function test_a_ping_with_a_query_token_starts_no_session_and_sets_no_cookie(): void
    {
        $directory = $this->sessionDirectory();
        // After setUp: the harness checks the identity of the session driver (array) before the first migration.
        $this->app['config']->set('session.driver', 'file');
        $this->app['config']->set('session.files', $directory);
        $this->app->forgetInstance('session');
        $this->app->forgetInstance('session.store');

        try {
            $response = $this->get('/orrery/ping?token='.self::TOKEN)->assertOk();

            self::assertSame([], $response->headers->getCookies());
            self::assertSame([], glob($directory.'/*') ?: [], 'a session file was written');
            // The dashboard on the same group still has its session (the test's environment works).
            $this->get('/orrery/api/health');
            self::assertNotSame([], glob($directory.'/*') ?: [], 'the dashboard wrote no session: the check proves nothing');
        } finally {
            array_map('unlink', glob($directory.'/*') ?: []);
            @rmdir($directory);
        }
    }

    /**
     * Rejects: a ping route that copies the configured middleware instead of using the `orrery` group, so an
     * application's addition to the group (an IP allow-list) does not cover it.
     */
    public function test_the_orrery_group_covers_the_ping(): void
    {
        $this->app['router']->pushMiddlewareToGroup('orrery', TeapotMiddleware::class);

        $this->ping()->assertStatus(418);
    }

    /**
     * Rejects: a limiter that counts successful checks, so a monitor that pings often gets 429.
     */
    public function test_a_monitor_with_the_right_token_is_never_rate_limited(): void
    {
        for ($i = 0; $i < 70; $i++) {
            self::assertSame(200, $this->ping()->status(), "request {$i}");
        }
    }

    /**
     * Rejects: a ping without a limit on failed token checks, and a 429 that a cache may store or that
     * compares the token first (a locked-out client learns nothing, also with the right token).
     */
    public function test_failed_token_checks_are_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            self::assertSame(403, $this->get('/orrery/ping?token=wrong')->status(), "request {$i}");
        }

        $limited = $this->get('/orrery/ping?token=wrong')->assertStatus(429);
        self::assertStringContainsString('no-store', (string) $limited->headers->get('Cache-Control'));
        self::assertNotNull($limited->headers->get('Retry-After'));
        $this->ping()->assertStatus(429);
    }

    /**
     * Rejects: a ping without a token that reads or writes the cache (the limiter), and a 404 that a cache may keep.
     */
    public function test_without_a_token_the_cache_is_not_touched(): void
    {
        $this->app['config']->set('orrery.ping.token', null);
        $events = [];
        $this->app['events']->listen('Illuminate\Cache\Events\*', static function (string $event) use (&$events): void {
            $events[] = $event;
        });

        $response = $this->get('/orrery/ping?token=x')->assertNotFound();

        self::assertSame([], $events);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * Rejects: a limiter that writes a host SQLite file (D17) through a `database` cache store on it, and a skipped
     * limit that /api/health does not report.
     */
    public function test_a_host_sqlite_limiter_store_skips_the_limit(): void
    {
        $file = (string) $this->app['config']->get('database.connections.hostcache.database');
        $statements = [];
        // Before each statement, also one that fails (the file has no cache table).
        $this->app['db']->connection('hostcache')->beforeExecuting(static function (string $sql) use (&$statements): void {
            $statements[] = $sql;
        });

        try {
            for ($i = 0; $i < 65; $i++) {
                self::assertSame(403, $this->get('/orrery/ping?token=wrong')->status(), "request {$i}");
            }

            self::assertSame([], $statements);
            Orrery::auth(static fn (): bool => true);
            $this->getJson('/orrery/api/health')->assertOk()->assertJsonPath('ping', ['token' => 'set', 'rate_limit' => 'off_host_sqlite']);
        } finally {
            @unlink($file);
        }
    }

    /**
     * Rejects: a token shorter than 32 characters that opens the ping, and a refusal that /api/health does not name.
     */
    public function test_a_short_token_turns_the_ping_off(): void
    {
        $this->get('/orrery/ping?token='.str_repeat('a', 31))->assertNotFound();

        Orrery::auth(static fn (): bool => true);
        $this->getJson('/orrery/api/health')->assertOk()->assertJsonPath('ping', ['token' => 'too_short', 'rate_limit' => null]);
    }

    public function test_health_reports_the_ping_state(): void
    {
        Orrery::auth(static fn (): bool => true);

        $this->getJson('/orrery/api/health')->assertOk()->assertJsonPath('ping', ['token' => 'set', 'rate_limit' => 'on']);
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

    /**
     * Rejects: an observations check that counts the drops of a web request (a dashboard read past its statement
     * timeout, the ping's own failed reads) or of a tick (a skipped phase): only a worker's window counts.
     * Still passes: a check that misses a dropped dispatch observation of a web request (documented: those windows
     * also count failed reads, so they cannot be told apart).
     */
    public function test_only_worker_windows_count_as_dropped_observations(): void
    {
        $this->window('web', 4, startedAgo: 30, errorAgo: 30, process: 'web:1');
        $this->window('tick', 2, startedAgo: 30, endedAgo: 20, errorAgo: 30, process: 'tick:1');
        $this->window('cli', 1, startedAgo: 30, endedAgo: 20, process: 'cli:1');
        $this->ping()->assertOk();

        $this->window('worker', 3, startedAgo: 30, errorAgo: 30, process: 'worker:1');
        $response = $this->ping()->assertStatus(503);
        self::assertSame(['ok' => false, 'reason' => 'dropped', 'dropped' => 3, 'at_least' => false], $response->json('checks.observations'));
    }

    /**
     * Rejects: an open window of a killed worker (no end, start and last error long ago) that fails the ping until
     * retention deletes it days later, and an open window that is still dropping that passes.
     */
    public function test_an_orphaned_open_window_does_not_fail_the_ping(): void
    {
        $this->window('worker', 5, startedAgo: 86400, errorAgo: 86000, process: 'worker:killed');
        $this->ping()->assertOk();

        $this->window('worker', 2, startedAgo: 86400, errorAgo: 30, process: 'worker:live');
        $this->ping()->assertStatus(503)->assertJsonPath('checks.observations.dropped', 2);
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
        $this->measured();
        $this->ping()->assertOk()->assertJsonPath('checks.long_waits', ['ok' => true]);

        $this->overLimit('database', 'default');
        $response = $this->ping()->assertStatus(503);
        self::assertSame(['ok' => false, 'reason' => 'over_limit', 'queues' => [['connection' => 'database', 'queue' => 'default']]], $response->json('checks.long_waits'));

        $this->artisan('orrery:pause')->assertExitCode(0);
        $this->ping()->assertOk();
    }

    /**
     * Rejects: a long-wait check that passes once the measurement turned unknown: the incident's last_seen stops
     * moving while the phase throws MeasurementIncomplete, so the incident alone would read as over.
     */
    public function test_long_waits_are_unknown_once_the_measurement_fails(): void
    {
        $this->overLimit(lastSeenAgo: 20);
        $this->measured();
        $this->ping()->assertStatus(503)->assertJsonPath('checks.long_waits.reason', 'over_limit');

        // The next runs could not measure: the incident is old, and the last run had unknown rows.
        $this->overLimit(lastSeenAgo: 320);
        $this->measured(ago: 10, complete: false, completeAgo: 320);
        $this->ping()->assertStatus(503)->assertJsonPath('checks.long_waits', ['ok' => false, 'reason' => 'unknown']);
    }

    /**
     * Rejects: a queue that was unknown from the first run (no complete measurement ever) that passes.
     */
    public function test_long_waits_are_unknown_without_a_complete_measurement(): void
    {
        $this->measured(ago: 10, complete: false, completeAgo: null);
        $this->ping()->assertStatus(503)->assertJsonPath('checks.long_waits', ['ok' => false, 'reason' => 'unknown']);

        $this->mirror()->table('orrery_settings')->where('key', LongWaitMeasured::KEY)->delete();
        $this->ping()->assertStatus(503)->assertJsonPath('checks.long_waits', ['ok' => false, 'reason' => 'unknown']);
    }

    /**
     * Rejects: a long-wait check that trusts an old complete measurement when the ticks since died before the phase
     * (the tick itself is fresh: observer_tick_at is written at the tick's start).
     */
    public function test_long_waits_are_unknown_when_the_ticks_end_before_the_phase(): void
    {
        $this->tickAgo(10);
        $this->measured(ago: 400, complete: true, completeAgo: 400);

        $response = $this->ping()->assertStatus(503);
        self::assertSame(['ok' => false, 'reason' => 'unknown'], $response->json('checks.long_waits'));
        self::assertTrue($response->json('checks.tick.ok'));
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
     * Rejects: a disabled package that answers anything but 503 disabled, or that answers it before the token check.
     */
    public function test_the_package_off_answers_disabled_after_the_token(): void
    {
        $this->get('/orrery/ping?token=wrong')->assertForbidden();

        $response = $this->ping()->assertStatus(503);
        self::assertSame(['status' => 'disabled'], $response->json());
    }

    private function sessionDirectory(): string
    {
        $directory = sys_get_temp_dir().'/orrery-ping-sessions-'.getmypid();

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        return $directory;
    }
}
