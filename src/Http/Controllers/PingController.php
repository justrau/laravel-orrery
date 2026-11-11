<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Cache\RateLimiter;
use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Justrau\Orrery\Http\QueryTooLong;
use Justrau\Orrery\Http\RawQuery;
use Justrau\Orrery\Monitoring\Ping;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\HostFiles;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * `GET {orrery.path}/ping` (and HEAD): the check for an uptime monitor
 * that cannot sign in. It does not extend Controller, and its route keeps
 * the `orrery` middleware group without Authenticate and without the
 * session, cookie and CSRF middleware (STATELESS): the token is its access
 * check (the one exception to "every route needs the view ability",
 * src/Http/CLAUDE.md), and a ping writes no session and sets no cookie.
 *
 * - No `orrery.ping.token`, or one shorter than MIN_TOKEN_LENGTH: 404, as
 *   an unknown route (the endpoint is off; /api/health names a short token).
 * - The token from `Authorization: Bearer`, `X-Orrery-Token` or `?token=`
 *   (read raw), compared with hash_equals(); missing or wrong: 403 with an
 *   empty body.
 * - Failed token checks are limited (MAX_FAILURES per minute and client,
 *   Laravel's RateLimiter on `cache.limiter` or the default store): a
 *   client past the limit gets 429 before the comparison. A right token
 *   never counts, and a 404 never touches the cache. On a store that writes
 *   a host SQLite file (D17) the limit is skipped, and /api/health says so.
 * - The package off: 503 `{"status": "disabled"}`.
 * - Else Monitoring\Ping: 200 when every check passes, else 503.
 *
 * Every answer has `Cache-Control: no-store`.
 */
class PingController
{
    public const MIN_TOKEN_LENGTH = 32;

    /** The failed token checks per minute and client before 429. */
    public const MAX_FAILURES = 60;

    /** The middleware of the `orrery` group (by default `web`) that a ping never runs; a subclass is excluded too. */
    public const STATELESS = [
        'Illuminate\Cookie\Middleware\EncryptCookies',
        'Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse',
        'Illuminate\Session\Middleware\StartSession',
        'Illuminate\Session\Middleware\AuthenticateSession',
        'Illuminate\View\Middleware\ShareErrorsFromSession',
        // Laravel 13: PreventRequestForgery; Laravel 12: VerifyCsrfToken and ValidateCsrfToken.
        'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
    ];

    private const HEADERS = ['Cache-Control' => 'no-store, private'];

    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function __invoke(Request $request, Ping $ping): SymfonyResponse
    {
        $token = config('orrery.ping.token');

        if (self::tokenState($token) !== 'set') {
            abort(404, '', self::HEADERS);
        }

        /** @var string $token */
        $limiter = self::limiter();
        $key = 'orrery-ping:'.sha1((string) $request->ip());

        if ($limiter !== null && self::guarded(static fn (): bool => $limiter->tooManyAttempts($key, self::MAX_FAILURES)) === true) {
            $retry = self::guarded(static fn (): int => $limiter->availableIn($key)) ?? 60;

            return new Response('', 429, self::HEADERS + ['Retry-After' => (string) $retry]);
        }

        $given = self::given($request);

        if ($given === null || ! hash_equals($token, $given)) {
            if ($limiter !== null) {
                self::guarded(static fn (): int => $limiter->hit($key, 60));
            }

            return new Response('', 403, self::HEADERS);
        }

        $result = $ping->run();

        return new JsonResponse($result, $result['status'] === 'ok' ? 200 : 503, self::HEADERS, self::JSON);
    }

    /**
     * The ping's configuration for /api/health: `token` `missing`,
     * `too_short` (the route answers 404) or `set`; `rate_limit` `on`,
     * `off_host_sqlite` (the limiter's store writes a host SQLite file, so
     * failed checks are not limited) or null while the route is off.
     *
     * @return array{token: 'missing'|'too_short'|'set', rate_limit: 'on'|'off_host_sqlite'|null}
     */
    public static function state(): array
    {
        $token = self::tokenState(config('orrery.ping.token'));

        return ['token' => $token, 'rate_limit' => $token !== 'set' ? null : (self::limiterStoreSafe() ? 'on' : 'off_host_sqlite')];
    }

    /**
     * @return 'missing'|'too_short'|'set'
     */
    public static function tokenState(mixed $token): string
    {
        if (! is_string($token) || $token === '') {
            return 'missing';
        }

        return strlen($token) < self::MIN_TOKEN_LENGTH ? 'too_short' : 'set';
    }

    /** The limiter, or null on a store that writes a host SQLite file. */
    private static function limiter(): ?RateLimiter
    {
        if (! self::limiterStoreSafe()) {
            return null;
        }

        return self::guarded(static fn (): RateLimiter => Container::getInstance()->make(RateLimiter::class));
    }

    /** Whether Laravel's limiter store (`cache.limiter`, else the default store) writes no host SQLite file. */
    private static function limiterStoreSafe(): bool
    {
        $store = config('cache.limiter');

        return Container::getInstance()->make(HostFiles::class)->cacheIsSafe(is_string($store) && $store !== '' ? $store : null);
    }

    /**
     * A limiter call that never throws into the request: a failing cache
     * store is reported and limits nothing (null).
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T|null
     */
    private static function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (Throwable $error) {
            Watcher::instance()->report('ping:limiter', $error);

            return null;
        }
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
