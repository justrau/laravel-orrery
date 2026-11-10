<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retry;

use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\TestEnvironment;

/**
 * The time rules on the operation ledger: the deadline and
 * next_check_at of an operation are epoch seconds from the database clocks
 * (DatabaseClock), so neither the application timezone nor the database
 * session timezone moves them. The sessions here run in a zone 3 hours
 * east of UTC.
 */
final class RetryTimezoneTest extends RetryTestCase
{
    private string $phpTimezone = 'UTC';

    protected function setUp(): void
    {
        $this->phpTimezone = date_default_timezone_get();

        parent::setUp();

        date_default_timezone_set('Europe/Vilnius');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        date_default_timezone_set($this->phpTimezone);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.timezone', 'Europe/Vilnius');
        $zone = match (TestEnvironment::fromEnv()->driver) {
            'mysql' => '+03:00',
            'pgsql' => 'Europe/Moscow',
            default => null,
        };

        if ($zone !== null) {
            // Every package session is a clone of these connections, so it runs in that zone.
            foreach ([TestEnvironment::PRIMARY, TestEnvironment::MIRROR] as $connection) {
                $app['config']->set("database.connections.{$connection}.timezone", $zone);
            }
        }
    }

    /**
     * Rejects: a deadline from `NOW()` in a session's timezone, or from
     * PHP's local time: the claim's deadline is 60 s after the epoch now,
     * whatever the zones, and the runner cancels it only after that.
     */
    public function test_deadline_and_next_check_ignore_timezone_offsets(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $this->assertSessionTimezone();

        $before = Clock::now();
        $this->killAt(RetryService::AFTER_CLAIM);
        $this->expectKilled(fn () => $this->retry($uuid, $occurrence), RetryService::AFTER_CLAIM);
        $after = Clock::now();
        $operation = $this->onlyOperation();

        foreach (['deadline', 'next_check_at'] as $column) {
            self::assertGreaterThanOrEqual($before + 60 - 2, (float) $operation->{$column}, "{$column} is 60 s after the epoch now");
            self::assertLessThanOrEqual($after + 60 + 2, (float) $operation->{$column}, "{$column} is 60 s after the epoch now");
        }

        $this->moveDatabaseClocks(50);
        $this->runOnce();
        self::assertSame([Operations::CLAIMED, 'retrying'], [$this->operation((string) $operation->id)->state, $this->rowOrFail($uuid)->status], 'the claim is live before its deadline');

        $this->moveDatabaseClocks(65);
        $this->finishByRunnerOrOperator((string) $operation->id);
        self::assertSame(Operations::ABORTED, $this->operation((string) $operation->id)->state, 'cancelled after its deadline');
        self::assertSame(['failed', $occurrence], [$this->rowOrFail($uuid)->status, (int) $this->rowOrFail($uuid)->occurrence_id]);
    }

    private function assertSessionTimezone(): void
    {
        self::assertSame('Europe/Vilnius', date_default_timezone_get());
        $session = $this->app->make(PackageSession::class)->mirror();

        match ($session->getDriverName()) {
            'mysql', 'mariadb' => self::assertSame('+03:00', (string) $session->scalar('select @@session.time_zone')),
            // A pooled session sends no `timezone`: it keeps the server's default, a UTC zone here.
            'pgsql' => self::assertContains((string) $session->scalar("select current_setting('TimeZone')"), $this->testEnvironment()->pooled ? ['UTC', 'Etc/UTC'] : ['Europe/Moscow']),
            default => null,
        };
    }
}
