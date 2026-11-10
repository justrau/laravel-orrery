<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Every write of the dashboard needs `X-Requested-With: XMLHttpRequest`
 * (Http\Middleware\Authenticate, else 403 `header_missing`). The PHP tests
 * get the header from `TestCase::setUp()`, so none of them sees a SPA that
 * stops sending it. This scan reads the built dist/app.js: it sets the
 * header on axios' defaults before it creates the instance that the screens
 * use (`$http`), because `axios.create()` copies the defaults only when it
 * runs.
 *
 * The test reads files only (group scripts: `composer test:all` runs it).
 */
#[Group('scripts')]
final class RequestedWithHeaderTest extends TestCase
{
    /**
     * Rejects: a build that no longer sets the header (a removed line in
     * resources/js/app.js), one that sets another value, and one that sets
     * it only after `axios.create()`, so the instance of the screens never
     * sends it.
     * Still passes: a screen that posts through a request config that
     * removes the header again: the scan reads the default only.
     */
    public function test_the_built_spa_sends_the_header_on_every_request(): void
    {
        $bundle = (string) file_get_contents(dirname(__DIR__, 2).'/dist/app.js');
        self::assertNotSame('', $bundle, 'dist/app.js is missing or empty.');

        $found = preg_match('/([A-Za-z_$][\w$]*)\.defaults\.headers\.common\[(["\'])X-Requested-With\2\]\s*=\s*(["\'])XMLHttpRequest\3/', $bundle, $match, PREG_OFFSET_CAPTURE);
        self::assertSame(1, $found, 'dist/app.js does not set X-Requested-With: XMLHttpRequest on the axios defaults.');

        $axios = $match[1][0];
        $create = strpos($bundle, $axios.'.create(');
        self::assertNotFalse($create, 'dist/app.js does not create the axios instance from the same axios.');
        self::assertGreaterThan($match[0][1], $create, 'dist/app.js creates the axios instance before it sets the header, so the instance does not send it.');
    }
}
