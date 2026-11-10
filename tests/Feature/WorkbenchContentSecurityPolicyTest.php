<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature;

use Justrau\Orrery\Tests\ControllerTest;
use PHPUnit\Framework\Attributes\Group;
use Workbench\App\Http\Middleware\ContentSecurityPolicy;

/**
 * The Workbench serves the dashboard under the enforced Content Security
 * Policy of the README (Workbench\App\Http\Middleware\ContentSecurityPolicy,
 * the README's example middleware), so every browser check of the Workbench
 * runs under it. These tests add the middleware to the package's `orrery`
 * group themselves, so they check the middleware and the README;
 * WorkbenchServeModesTest::test_the_web_server_of_serve_sends_the_enforced_policy
 * checks that the Workbench provider registers it for `composer serve`.
 */
#[Group('workbench')]
final class WorkbenchContentSecurityPolicyTest extends ControllerTest
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('router')->pushMiddlewareToGroup('orrery', ContentSecurityPolicy::class);
    }

    /**
     * Rejects: a page without the enforced header (a report-only header
     * enforces nothing), a policy with 'unsafe-inline' or 'unsafe-eval', a
     * policy that a frame can embed, a header nonce that the inlined
     * elements do not carry (the browser then blocks the dashboard), and one
     * nonce for every request.
     */
    public function test_the_page_carries_the_enforced_policy_and_its_nonce(): void
    {
        $nonces = [];

        foreach ([1, 2] as $request) {
            $response = $this->get('/orrery')->assertOk();
            $policy = (string) $response->headers->get('Content-Security-Policy');

            self::assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
            self::assertSame(1, preg_match("/script-src 'nonce-([A-Za-z0-9]{40})'/", $policy, $match), $policy);
            $nonce = $match[1];
            self::assertStringContainsString("style-src 'nonce-{$nonce}'", $policy);
            self::assertStringContainsString("frame-ancestors 'none'", $policy);
            self::assertStringNotContainsString('unsafe-', $policy);

            $html = (string) $response->getContent();
            preg_match_all('/<(?:script|style)\b[^>]*>/', $html, $tags);
            self::assertCount(3, $tags[0], 'the stylesheet, window.Orrery and the application');
            foreach ($tags[0] as $tag) {
                self::assertStringContainsString('nonce="'.$nonce.'"', $tag, 'every inlined element carries the nonce of the header');
            }

            $nonces[] = $nonce;
        }

        self::assertNotSame($nonces[0], $nonces[1], 'a new nonce per request');
    }

    /**
     * Rejects: a README that documents another policy than the one the
     * Workbench enforces (the browser check would then prove a policy that
     * no host is told to set).
     */
    public function test_the_readme_documents_the_enforced_policy(): void
    {
        $policy = (string) $this->get('/orrery')->headers->get('Content-Security-Policy');
        $documented = str_replace('{nonce}', 'NONCE', ContentSecurityPolicy::POLICY);
        $readme = (string) file_get_contents(dirname(__DIR__, 2).'/README.md');

        self::assertSame($documented, (string) preg_replace("/'nonce-[A-Za-z0-9]+'/", "'nonce-NONCE'", $policy));
        self::assertStringContainsString('"'.ContentSecurityPolicy::POLICY.'"', $readme, 'the README example middleware holds the same policy text');
    }
}
