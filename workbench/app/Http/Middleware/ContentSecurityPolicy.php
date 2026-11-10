<?php

namespace Workbench\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * The enforced Content Security Policy of the dashboard, as the example
 * middleware of docs/security.md sets it: a new nonce per request through
 * Laravel's Vite::useCspNonce(), which Orrery::css() and Orrery::js() put on
 * their inlined elements, and the policy with that nonce. No 'unsafe-inline'
 * and no 'unsafe-eval'; the bundled font is a data: URL; no frame may embed
 * the dashboard.
 *
 * WorkbenchServiceProvider adds it to `orrery.middleware`, so `composer serve`
 * and every browser check of the Workbench run under the policy.
 */
final class ContentSecurityPolicy
{
    /** The policy; `{nonce}` is the nonce of the request. docs/security.md shows the same text. */
    public const POLICY = "default-src 'none'; script-src 'nonce-{nonce}'; style-src 'nonce-{nonce}'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('Content-Security-Policy', str_replace('{nonce}', $nonce, self::POLICY));

        return $response;
    }
}
