<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Ping;

use Illuminate\Support\Facades\Artisan;

/**
 * orrery:ping: the checks of the ping route for a cron monitor, without a
 * token.
 */
final class PingCommandTest extends PingTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if ($this->name() === 'test_it_needs_no_token') {
            $app['config']->set('orrery.ping.token', null);
        }

        if ($this->name() === 'test_the_package_off_exits_one') {
            $app['config']->set('orrery.enabled', false);
        }
    }

    /**
     * Rejects: a command that exits 0 on a failing check, or 1 when all pass.
     */
    public function test_the_exit_code_follows_the_checks(): void
    {
        self::assertSame(0, Artisan::call('orrery:ping'));
        $output = Artisan::output();
        self::assertMatchesRegularExpression('/tick\s+\|\s+ok\s+\|\s+age \d+ s/', $output);

        $this->tickAgo(900);
        self::assertSame(1, Artisan::call('orrery:ping'));
        self::assertMatchesRegularExpression('/tick\s+\|\s+late\s+\|\s+age 9\d\d s/', Artisan::output());
    }

    /**
     * Rejects: a --json body that differs from the route's body.
     */
    public function test_json_is_the_body_of_the_route(): void
    {
        $this->droppedWindow(2);

        self::assertSame(1, Artisan::call('orrery:ping', ['--json' => true]));
        $command = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $route = $this->ping()->assertStatus(503)->json();

        unset($command['as_of'], $route['as_of'], $command['checks']['tick']['age'], $route['checks']['tick']['age']);
        self::assertSame($route, $command);
        self::assertSame('dropped', $command['checks']['observations']['reason']);
    }

    public function test_it_needs_no_token(): void
    {
        self::assertSame(0, Artisan::call('orrery:ping'));
    }

    /**
     * Rejects: a disabled package that passes the check.
     */
    public function test_the_package_off_exits_one(): void
    {
        self::assertSame(1, Artisan::call('orrery:ping', ['--json' => true]));
        self::assertSame(['status' => 'disabled'], json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
    }
}
