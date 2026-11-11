<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Justrau\Orrery\Http\QueryTooLong;
use Justrau\Orrery\Http\RawQuery;
use Justrau\Orrery\Monitoring\Ping;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * `GET {orrery.path}/ping` (and HEAD): the check for an uptime monitor
 * that cannot sign in. It does not extend Controller and its route group
 * has no Authenticate: the token is its access check (the one exception to
 * "every route needs the view ability", src/Http/CLAUDE.md).
 *
 * - No `orrery.ping.token`: 404, as an unknown route (the endpoint is off).
 * - The token from `Authorization: Bearer`, `X-Orrery-Token` or `?token=`,
 *   compared with hash_equals(); missing or wrong: 403 with an empty body.
 * - The package off: 503 `{"status": "disabled"}`.
 * - Else Monitoring\Ping: 200 when every check passes, else 503.
 *
 * The route group throttles every request (OrreryServiceProvider), and
 * every answer has `Cache-Control: no-store`.
 */
class PingController
{
    private const HEADERS = ['Cache-Control' => 'no-store, private'];

    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function __invoke(Request $request, Ping $ping): SymfonyResponse
    {
        $token = config('orrery.ping.token');

        if (! is_string($token) || $token === '') {
            abort(404);
        }

        $given = self::given($request);

        if ($given === null || ! hash_equals($token, $given)) {
            return new Response('', 403, self::HEADERS);
        }

        $result = $ping->run();

        return new JsonResponse($result, $result['status'] === 'ok' ? 200 : 503, self::HEADERS, self::JSON);
    }

    /**
     * The first non-empty token of the three transports, or null. The query
     * is read raw (RawQuery), as every package read: a host middleware such
     * as TrimStrings must not change the token. A query past
     * max_input_vars counts as no token.
     */
    private static function given(Request $request): ?string
    {
        try {
            $query = RawQuery::of($request)['token'] ?? null;
        } catch (QueryTooLong) {
            $query = null;
        }

        foreach ([$request->bearerToken(), $request->headers->get('X-Orrery-Token'), $query] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
