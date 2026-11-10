<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Justrau\Orrery\Tests\ControllerTest;

/**
 * The dashboard page: the app identity in window.Orrery,
 * which the sidebar shows, and the page shell without the dark stylesheet
 * and without a font of its own.
 */
final class PageShellTest extends ControllerTest
{
    public function test_the_page_gives_the_spa_the_app_name_and_the_environment(): void
    {
        $this->app['config']->set('app.name', 'acme-shop');
        $this->app['env'] = 'staging';

        $variables = $this->pageVariables($this->page());

        $this->assertSame('acme-shop', $variables['appName']);
        $this->assertSame('staging', $variables['environment']);
    }

    /**
     * Rejects: a retry confirmation that cannot tell a SQLite queue
     * ("On SQLite, a retry briefly locks the database"): the page
     * lists exactly the `database` queue connections whose database
     * connection is SQLite, from the configuration alone.
     */
    public function test_the_page_lists_the_sqlite_queue_connections(): void
    {
        $config = $this->app['config'];
        $config->set('database.connections.page_sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $config->set('database.connections.page_mysql', ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'x', 'prefix' => '']);
        $config->set('queue.connections', [
            'on_sqlite' => ['driver' => 'database', 'connection' => 'page_sqlite', 'table' => 'jobs'],
            'on_sqlite_write' => ['driver' => 'database', 'connection' => 'page_sqlite::write', 'table' => 'jobs'],
            'on_mysql' => ['driver' => 'database', 'connection' => 'page_mysql', 'table' => 'jobs'],
            'redis_named_like_sqlite' => ['driver' => 'redis', 'connection' => 'page_sqlite'],
            'sync' => ['driver' => 'sync'],
        ]);

        $this->assertSame(['on_sqlite', 'on_sqlite_write'], $this->pageVariables($this->page())['sqliteQueueConnections']);
    }

    public function test_an_app_name_with_markup_cannot_close_the_script_element(): void
    {
        $name = '</script><script>window.pwned=1</script> & \'quotes\' "double"';
        $this->app['config']->set('app.name', $name);

        $html = $this->page();

        $this->assertStringNotContainsString('<script>window.pwned', $html);
        $this->assertSame($name, $this->pageVariables($html)['appName']);
    }

    public function test_an_app_name_that_is_not_utf8_still_renders_the_page(): void
    {
        $this->app['config']->set('app.name', "acme-\xB1shop");

        $this->assertSame("acme-\u{FFFD}shop", $this->pageVariables($this->page())['appName']);
    }

    public function test_a_missing_app_name_is_null(): void
    {
        $this->app['config']->set('app.name', null);

        $this->assertNull($this->pageVariables($this->page())['appName']);
    }

    /**
     * Orrery::css() inlines only dist/app.css.
     *
     * Rejects: the Bootstrap stylesheet (dist/styles.css, `data-scheme="light"`)
     * still inlined, a dark stylesheet, and an external font or stylesheet
     * of any host (a `<link>`, an `@import`, a `url()` that is not a `data:`
     * URL, a script or image by URL, a `FontFace` load), and a font of its
     * own (an `@font-face`: the page uses the system font).
     * Still passes: Bootstrap rules copied into app.css (LegacyGoneTest
     * guards the sources).
     */
    public function test_the_page_inlines_only_the_app_stylesheet_and_no_external_font(): void
    {
        $html = $this->page();

        $this->assertSame(1, preg_match_all('/<style\b[^>]*>/', $html, $styles), 'The page has one style element.');
        $this->assertSame('<style data-scheme="app">', $styles[0][0]);
        $this->assertStringContainsString((string) file_get_contents(__DIR__.'/../../dist/app.css'), $html);
        $this->assertStringNotContainsString('data-scheme="light"', $html);
        $this->assertStringNotContainsString('data-scheme="dark"', $html);
        $this->assertStringNotContainsString('fonts.bunny.net', $html);

        // No font or stylesheet from any host, not only from one name.
        $this->assertSame(0, preg_match('/<link\b/i', $html), 'the page links a resource');
        $this->assertSame(0, preg_match('/@import\b/i', $html), 'the stylesheet imports another one');
        $this->assertSame(1, preg_match('/<style data-scheme="app">(.*?)<\/style>/s', $html, $style));
        preg_match_all('/url\(\s*([\'"]?)([^\'")\s]*)/i', $style[1], $urls);
        $external = array_values(array_filter($urls[2], static fn (string $url): bool => ! str_starts_with($url, 'data:') && ! str_starts_with($url, '#')));
        $this->assertSame([], $external, 'the stylesheet loads a url() that is not inline');
        $this->assertSame(0, preg_match('/@font-face\b/i', $style[1]), 'the stylesheet declares a font: the page uses the system font');
        $this->assertSame(0, preg_match('/<(?:script|img|iframe)\b[^>]*\bsrc\s*=/i', $html), 'the page loads a script or an image by URL');
        $this->assertSame(0, preg_match('/\bnew\s+FontFace\s*\(/', $html), 'the script loads a font');
    }

    private function page(): string
    {
        return (string) $this->get('/orrery/dashboard')->assertOk()->getContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function pageVariables(string $html): array
    {
        $this->assertSame(1, preg_match('/<script>window\.Orrery = (\{.*?\});<\/script>/', $html, $match), 'The page has no window.Orrery.');

        $variables = json_decode($match[1], true);
        $this->assertIsArray($variables);

        return $variables;
    }
}
