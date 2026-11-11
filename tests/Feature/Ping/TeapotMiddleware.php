<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Ping;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A middleware that an application pushes to the `orrery` group (for
 * example an IP allow-list): it answers 418 before the route.
 */
final class TeapotMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        return new Response('teapot', 418);
    }
}
