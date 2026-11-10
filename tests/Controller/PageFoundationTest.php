<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Tests\ControllerTest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The page of the SPA foundation: the
 * mount element without an in-DOM template (the runtime-only Vue build
 * compiles no template in the browser), the CSP nonce of Laravel's
 * `Vite::cspNonce()` on every inlined element, and the `window.Orrery`
 * additions `displayTimezone` and `downForMaintenance`.
 */
final class PageFoundationTest extends ControllerTest
{
    /**
     * Rejects: a layout that keeps the in-DOM template (the runtime-only build
     * would leave it unrendered, and a CSP without 'unsafe-eval' forbids its
     * compilation).
     */
    public function test_the_page_has_an_empty_mount_element(): void
    {
        $html = $this->page();

        self::assertStringContainsString('<div id="orrery"></div>', $html);
        self::assertStringNotContainsString('<alert', $html);
        self::assertStringNotContainsString('<orrery-shell', $html);
        self::assertSame(1, substr_count($html, 'id="orrery"'), 'one mount element');
    }

    /**
     * Rejects: a page that leaves one inlined element without the nonce (the
     * policy then blocks it), a nonce that is not HTML-escaped, and a
     * nonce attribute without a nonce.
     */
    public function test_every_inlined_element_carries_the_vite_nonce(): void
    {
        preg_match_all('/<(style|script)\b[^>]*>/', $this->page(), $plain);
        self::assertCount(3, $plain[0]);

        foreach ($plain[0] as $tag) {
            self::assertStringNotContainsString('nonce', $tag, 'no nonce attribute without a nonce');
        }

        $this->app->make(Vite::class)->useCspNonce('n0nce"><script>x');
        $html = $this->page();

        preg_match_all('/<(style|script)\b[^>]*>/', $html, $tags);
        self::assertCount(3, $tags[0], 'one style and two script elements (D8-19: only app.css)');

        foreach ($tags[0] as $tag) {
            self::assertStringContainsString('nonce="n0nce&quot;&gt;&lt;script&gt;x"', $tag);
        }

        self::assertStringNotContainsString('n0nce"><script>x', $html);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string|null}>
     */
    public static function timezones(): iterable
    {
        yield 'an IANA zone' => ['Europe/Vilnius', 'Europe/Vilnius'];
        yield 'UTC' => ['UTC', 'UTC'];
        yield 'a backward-compatible name' => ['US/Eastern', 'US/Eastern'];
        yield 'unset' => [null, null];
        yield 'empty' => ['', null];
        yield 'an unknown zone' => ['Mars/Phobos', null];
        yield 'an offset, not a zone' => ['+02:00', null];
        yield 'an abbreviation' => ['CEST', null];
        yield 'the placeholder zone Factory' => ['Factory', null];
        yield 'not a string' => [3, null];
        yield 'an array' => [['Europe/Vilnius'], null];
    }

    /**
     * Rejects: passing an invalid zone to the browser (Intl throws a
     * RangeError on it and every time on the page breaks), accepting an
     * offset or an abbreviation as a zone, and a missing key.
     */
    #[DataProvider('timezones')]
    public function test_the_display_timezone_is_a_valid_iana_zone_or_null(mixed $configured, ?string $expected): void
    {
        $this->app['config']->set('orrery.display_timezone', $configured);

        $variables = $this->pageVariables($this->page());

        self::assertArrayHasKey('displayTimezone', $variables);
        self::assertSame($expected, $variables['displayTimezone']);
    }

    /**
     * Rejects: a page that loses the maintenance flag when the shell prop
     * moves from the in-DOM template to window.Orrery, and a flag that is
     * always false.
     */
    public function test_the_maintenance_flag_comes_from_the_application(): void
    {
        self::assertFalse($this->pageVariables($this->page())['downForMaintenance']);

        $this->app->instance(MaintenanceMode::class, new class implements MaintenanceMode
        {
            public function activate(array $payload): void
            {
            }

            public function deactivate(): void
            {
            }

            public function active(): bool
            {
                return true;
            }

            public function data(): array
            {
                return [];
            }
        });

        // The page itself answers 503 while the application is down, so read the variables directly.
        self::assertTrue(Orrery::scriptVariables(Request::create('/orrery'))['downForMaintenance']);
    }

    /**
     * Rejects: a published configuration of before 2026-11 that breaks the page:
     * without the key the zone is null.
     */
    public function test_a_configuration_without_the_key_gives_no_zone(): void
    {
        $config = $this->app['config']->get('orrery');
        unset($config['display_timezone']);
        $this->app['config']->set('orrery', $config);

        self::assertNull($this->pageVariables($this->page())['displayTimezone']);
    }

    /**
     * Rejects: an `orrery.path` or `orrery.proxy_path` with a slash at an end
     * passed to the SPA as it is (the server mounts the routes with Laravel's
     * trimmed prefix, but the SPA builds "//orrery" or "/orrery//api" and
     * every request and route breaks), and a proxy path without its leading
     * slash.
     *
     * @return iterable<string, array{mixed, mixed, string, string}>
     */
    public static function paths(): iterable
    {
        yield 'plain' => ['orrery', '', 'orrery', ''];
        yield 'slashes around the path' => ['/orrery/', '', 'orrery', ''];
        yield 'nested path' => ['/tools/orrery/', '', 'tools/orrery', ''];
        yield 'root path' => ['/', '', '', ''];
        yield 'proxy path with a trailing slash' => ['orrery', '/sub/', 'orrery', '/sub'];
        yield 'proxy path without a leading slash' => ['orrery', 'sub', 'orrery', '/sub'];
        yield 'proxy path of a slash' => ['orrery', '/', 'orrery', ''];
        yield 'null proxy path' => ['orrery', null, 'orrery', ''];
    }

    #[DataProvider('paths')]
    public function test_the_paths_reach_the_spa_without_slashes_at_their_ends(mixed $path, mixed $proxy, string $expectedPath, string $expectedProxy): void
    {
        $this->app['config']->set('orrery.path', $path);
        $this->app['config']->set('orrery.proxy_path', $proxy);

        $variables = Orrery::scriptVariables(Request::create('/orrery'));

        self::assertSame($expectedPath, $variables['path']);
        self::assertSame($expectedProxy, $variables['proxy_path']);
    }

    private function page(): string
    {
        return (string) $this->get('/orrery/overview')->assertOk()->getContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function pageVariables(string $html): array
    {
        self::assertSame(1, preg_match('/<script(?: nonce="[^"]*")?>window\.Orrery = (\{.*?\});<\/script>/', $html, $match), 'The page has no window.Orrery.');

        $variables = json_decode($match[1], true);
        self::assertIsArray($variables);

        return $variables;
    }
}
