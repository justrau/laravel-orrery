<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Every write of the dashboard needs `X-Requested-With: XMLHttpRequest`
 * (Http\Middleware\Authenticate, else 403 `header_missing`). The PHP tests
 * get the header from `TestCase::setUp()`, so none of them sees a SPA that
 * stops sending it. This scan reads the built dist/app.js: the HTTP client
 * of the screens (`resources/js/support/http.js`, the `$http` of app.js)
 * sets the header on the headers of every request, after the headers of the
 * request config, just before it calls fetch(). tests/js/http.test.js
 * checks the client's behaviour.
 *
 * The test reads files only (group scripts: `composer test:all` runs it).
 */
#[Group('scripts')]
final class RequestedWithHeaderTest extends TestCase
{
    /**
     * Rejects: a build that no longer sets the header (a removed line in
     * resources/js/support/http.js), one that sets another value, one that
     * sets it in a second place too (an old default left over), and a
     * client that no longer calls fetch().
     * Still passes: a header line in code that no request runs, and one
     * that a request config can overwrite; tests/js/http.test.js rejects
     * both for the client itself.
     */
    public function test_the_built_spa_sends_the_header_on_every_request(): void
    {
        $bundle = (string) file_get_contents(dirname(__DIR__, 2).'/dist/app.js');
        self::assertNotSame('', $bundle, 'dist/app.js is missing or empty.');

        $found = preg_match_all('/[\w$\]\)]\[([`"\'])X-Requested-With\1\]\s*=\s*([`"\'])([^`"\']*)\2/', $bundle, $matches);
        self::assertSame(1, $found, 'dist/app.js does not set X-Requested-With on the headers of a request exactly once.');
        self::assertSame('XMLHttpRequest', $matches[3][0], 'dist/app.js sets X-Requested-With to another value.');

        self::assertSame(1, preg_match('/\bfetch\(/', $bundle), 'dist/app.js does not call fetch(): the HTTP client is gone.');
    }
}
