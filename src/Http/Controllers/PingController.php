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
 *   an IPv6 client by its /64: clientKey(); Laravel's RateLimiter on
 *   `cache.limiter` or the default store): a client past the limit gets
 *   429 before the comparison. A right token never counts, and a 404 never
 *   touches the cache. On a missing store, or one that writes a host SQLite
 *   file (D17), the limit is skipped; on an `array` or `null` store it keeps
 *   nothing between requests. /api/health says which (state()).
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
        // An interface: Laravel's exclusion matches classes only, so excluded() adds each implementer.
        'Illuminate\Contracts\Session\Middleware\AuthenticatesSessions',
    ];

    /** The request attribute that marks an answer of this controller (Http\Middleware\PingGuard). */
    public const ANSWERED = 'orrery.ping.answered';

    private const HEADERS = ['Cache-Control' => 'no-store, private'];

    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function __invoke(Request $request, Ping $ping): SymfonyResponse
    {
        $token = config('orrery.ping.token');
        $request->attributes->set(self::ANSWERED, true);

        if (self::tokenState($token) !== 'set') {
            abort(404, '', self::HEADERS);
        }

        /** @var string $token */
        $limiter = self::limiter();
        $key = 'orrery-ping:'.sha1(self::clientKey((string) $request->ip()));

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
     * The middleware names of the ping route, after PingGuard:
     * `orrery.ping.middleware` when it is a list (an application's own
     * stack for the ping), else the `orrery` group.
     *
     * @return list<string>
     */
    public static function middleware(): array
    {
        $configured = config('orrery.ping.middleware');

        return is_array($configured) ? array_values(array_filter($configured, 'is_string')) : ['orrery'];
    }

    /**
     * The classes of $resolved (the route's resolved middleware) that
     * implement an interface of STATELESS: the route excludes them too,
     * because Laravel's exclusion matches classes and their subclasses only.
     *
     * @param  array<array-key, mixed>  $resolved
     * @return list<string>
     */
    public static function implementers(array $resolved): array
    {
        $interfaces = array_values(array_filter(self::STATELESS, 'interface_exists'));
        $excluded = [];

        foreach ($resolved as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            $class = explode(':', $middleware, 2)[0];

            foreach ($interfaces as $interface) {
                if (class_exists($class) && is_a($class, $interface, true)) {
                    $excluded[] = $class;
                }
            }
        }

        return array_values(array_unique($excluded));
    }

    /**
     * The ping's configuration for /api/health: `token` `missing`,
     * `too_short` (the route answers 404) or `set`; `rate_limit` `on`,
     * `off` or null while the route is off, and `rate_limit_off` the reason:
     * `host_sqlite` (the limiter's store writes a host SQLite file, so the
     * limit is skipped), `store_missing` (no such store: the limit is
     * skipped) or `store_not_persistent` (an `array` or `null` store keeps
     * nothing between requests).
     *
     * @return array{token: 'missing'|'too_short'|'set', rate_limit: 'on'|'off'|null, rate_limit_off: 'host_sqlite'|'store_missing'|'store_not_persistent'|null}
     */
    public static function state(): array
    {
        $token = self::tokenState(config('orrery.ping.token'));

        if ($token !== 'set') {
            return ['token' => $token, 'rate_limit' => null, 'rate_limit_off' => null];
        }

        $off = self::limiterOff();

        return ['token' => $token, 'rate_limit' => $off === null ? 'on' : 'off', 'rate_limit_off' => $off];
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

    /**
     * The limiter key of a client address: an IPv4 address whole (also one
     * mapped into IPv6), an IPv6 address by its /64 network, because one
     * client usually holds a whole /64 and could rotate through it.
     */
    public static function clientKey(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return 'v4:'.$ip;
        }

        $packed = inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return 'ip:'.$ip;
        }

        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return 'v4:'.inet_ntop(substr($packed, 12));
        }

        return 'v6:'.bin2hex(substr($packed, 0, 8));
    }

    /** The limiter, or null on a store that is missing or writes a host SQLite file. */
    private static function limiter(): ?RateLimiter
    {
        if (in_array(self::limiterOff(), ['host_sqlite', 'store_missing'], true)) {
            return null;
        }

        return self::guarded(static fn (): RateLimiter => Container::getInstance()->make(RateLimiter::class));
    }

    /**
     * Why Laravel's limiter store (`cache.limiter`, else the default store)
     * does not limit across requests, or null when it does.
     *
     * @return 'host_sqlite'|'store_missing'|'store_not_persistent'|null
     */
    private static function limiterOff(): ?string
    {
        $config = config();
        $store = $config->get('cache.limiter');
        $store = is_string($store) && $store !== '' ? $store : $config->get('cache.default');

        if (! is_string($store) || ! is_array($config->get("cache.stores.{$store}"))) {
            return 'store_missing';
        }

        if (! Container::getInstance()->make(HostFiles::class)->cacheIsSafe($store)) {
            return 'host_sqlite';
        }

        return self::persists($store, 0) ? null : 'store_not_persistent';
    }

    /** Whether a store keeps its values between requests: not `array` or `null`; a failover store when one of its stores does. */
    private static function persists(string $store, int $depth): bool
    {
        $definition = config("cache.stores.{$store}");
        $driver = is_array($definition) ? ($definition['driver'] ?? null) : null;

        if ($driver === 'failover' && $depth < 5) {
            foreach ((array) ($definition['stores'] ?? []) as $inner) {
                if (is_string($inner) && is_array(config("cache.stores.{$inner}")) && self::persists($inner, $depth + 1)) {
                    return true;
                }
            }

            return false;
        }

        return is_string($driver) && ! in_array($driver, ['array', 'null'], true);
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
