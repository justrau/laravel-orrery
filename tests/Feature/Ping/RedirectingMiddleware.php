<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Ping;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A middleware that sends guests to a login page, as `auth` does.
 */
final class RedirectingMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        return new RedirectResponse('/login');
    }
}
