<?php

namespace Justrau\Orrery\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Justrau\Orrery\Http\Controllers\PingController;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The outermost middleware of the ping route. The ping's own answers pass
 * unchanged (PingController marks them on the request). A redirect or a
 * server error of the middleware before the controller becomes an empty
 * 503 with `Cache-Control: no-store`:
 *
 * - a redirect (an `auth` middleware sending guests to a login page) must
 *   not reach a monitor that follows redirects and reads the login page's
 *   200 as up;
 * - an error page (a middleware that needs the session the ping does not
 *   start) can show the request, and so the token, with `app.debug` on.
 *
 * Other answers of that middleware (a 403 of an IP allow-list, a 404) pass.
 */
class PingGuard
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        /** @var SymfonyResponse $response */
        $response = $next($request);

        if ($request->attributes->get(PingController::ANSWERED) === true) {
            return $response;
        }

        if ($response->isRedirection() || $response->getStatusCode() >= 500) {
            return new Response('', 503, ['Cache-Control' => 'no-store, private']);
        }

        return $response;
    }
}
