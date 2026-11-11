<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Ping;

use Closure;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Http\Request;

/**
 * An application's own session authentication (it implements
 * AuthenticatesSessions, as Laravel's AuthenticateSession does).
 */
final class SessionGuardMiddleware implements AuthenticatesSessions
{
    public function handle(Request $request, Closure $next): mixed
    {
        $request->session()->get('password_hash');

        return $next($request);
    }
}
