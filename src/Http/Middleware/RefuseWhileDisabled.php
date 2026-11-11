<?php

namespace Justrau\Orrery\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as PlainResponse;
use Justrau\Orrery\Runtime\OffSwitch;
use Symfony\Component\HttpFoundation\Response;

/**
 * The dashboard while the package is off (Runtime\OffSwitch): every route
 * answers 503 with the reason, and no controller reads the package storage.
 * It runs after Authenticate (OrreryServiceProvider::routeGroup()), so a
 * visitor without the view ability still gets 401 or 403 and does not
 * learn the state of the package. The page route answers plain text; every
 * other route JSON `{"error": "orrery_disabled", "message": ...}`.
 *
 * The check reads the configuration of each request, so it also holds for
 * cached routes.
 */
class RefuseWhileDisabled
{
    /** The name of the page route (routes/web.php). */
    private const PAGE_ROUTE = 'orrery.index';

    public function handle(Request $request, Closure $next): Response
    {
        if (OffSwitch::enabled()) {
            return $next($request);
        }

        if ($request->route()?->getName() === self::PAGE_ROUTE) {
            return new PlainResponse(OffSwitch::MESSAGE."\n", 503, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return new JsonResponse(['error' => OffSwitch::ERROR, 'message' => OffSwitch::MESSAGE], 503);
    }
}
