<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Ping;

use Closure;
use Illuminate\Http\Request;

/**
 * An application middleware in `web` that reads the session: without
 * StartSession it throws ("Session store not set on request.").
 */
final class SessionReadingMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        $request->session()->get('locale');

        return $next($request);
    }
}
