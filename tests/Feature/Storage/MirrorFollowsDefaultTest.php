<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Storage;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Justrau\Orrery\Storage\MirrorMoved;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\TestCase;
use Throwable;

/**
 * With orrery.database empty the mirror follows database.default.
 * An application that switches its default connection at runtime
 * (a multi-tenant package) learns it from one report per process.
 */
final class MirrorFollowsDefaultTest extends TestCase
{
    /**
     * Rejects: a mirror that follows a changed default connection in
     * silence, a report on every call after the change, and a report while
     * orrery.database names a fixed connection. Would still pass: a report
     * that names the connections in another wording (the test reads the
     * fields).
     */
    public function test_a_changed_default_connection_is_reported_once(): void
    {
        $config = $this->app['config'];
        $sessions = $this->app->make(PackageSession::class);
        $default = (string) $config->get('database.default');
        $configured = $config->get('orrery.database');
        $reports = [];
        $this->app->make(ExceptionHandler::class)->reportable(static function (Throwable $error) use (&$reports): bool {
            if ($error instanceof MirrorMoved) {
                $reports[] = [$error->from, $error->to];
            }

            return false;
        });
        $config->set('database.connections.m8_tenant', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $config->set('database.connections.m8_fixed', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        try {
            // A fixed mirror connection does not follow the default.
            $config->set('orrery.database', 'm8_fixed');
            $sessions->mirror();
            $config->set('database.default', 'm8_tenant');
            $sessions->mirror();
            $config->set('database.default', $default);
            self::assertSame([], $reports, 'A fixed orrery.database is not reported.');

            $config->set('orrery.database', null);
            $sessions->mirror();
            $sessions->mirror();
            self::assertSame([], $reports, 'A stable default connection is not reported.');

            $config->set('database.default', 'm8_tenant');
            $sessions->mirror();
            $sessions->mirror();
            $config->set('database.default', $default);
            $sessions->mirror();
        } finally {
            $config->set('database.default', $default);
            $config->set('orrery.database', $configured);
        }

        self::assertSame([[$default, 'm8_tenant']], $reports, 'One report per process.');
    }
}
