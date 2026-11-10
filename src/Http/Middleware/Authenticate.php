<?php

namespace Justrau\Orrery\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Justrau\Orrery\Exceptions\ForbiddenException;
use Justrau\Orrery\Exceptions\UnauthenticatedException;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Runtime\Watcher;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The dashboard's authorization, on every package route.
 *
 * - Every request needs the view ability (viewOrrery, Orrery::check()).
 *   When it fails, the configured guard decides the status:
 *   no user on `orrery.guard` (null: the default guard) gives 401
 *   `unauthenticated`; a user gives 403 `forbidden`. An `orrery.guard` that
 *   is not a key of `auth.guards` is never passed to user() (it would
 *   throw), and a guard that cannot be built (user() throws
 *   InvalidArgumentException) counts as signed in: the request gets 403
 *   `forbidden`, and /api/health warns `guard_invalid`.
 * - Every request with a method that is not safe (POST, PUT, PATCH, DELETE
 *   and any other method except GET, HEAD, OPTIONS and TRACE) also needs the
 *   operate ability (operateOrrery, Orrery::canOperate()), else 403
 *   `forbidden_action`. The check is by method, so a new write route cannot
 *   forget it.
 * - Such a request also needs the header `X-Requested-With: XMLHttpRequest`,
 *   which the SPA sends with every request, else 403 `header_missing`:
 *   a cross-site form cannot set a custom header, so a
 *   middleware stack without CSRF protection (basic auth, an IP list, a
 *   proxy's single sign-on) still refuses a cross-site write.
 *
 * The view check runs first: a request without the view ability never
 * reaches the operate check. Every route except the page route answers JSON
 * `{"error": <code>}`; the page route answers plain HTTP errors.
 */
class Authenticate
{
    /** The name of the page route (routes/web.php). */
    private const PAGE_ROUTE = 'orrery.index';

    public function handle(Request $request, Closure $next): Response
    {
        $json = $request->route()?->getName() !== self::PAGE_ROUTE;

        if (! Orrery::check($request)) {
            throw self::signedIn($request) ? ForbiddenException::forbidden($json) : UnauthenticatedException::make($json);
        }

        if (! $request->isMethodSafe() && ! Orrery::canOperate($request)) {
            throw ForbiddenException::forbiddenAction($json);
        }

        if (! $request->isMethodSafe() && $request->headers->get('X-Requested-With') !== 'XMLHttpRequest') {
            throw ForbiddenException::headerMissing($json);
        }

        return $next($request);
    }

    /**
     * Whether `orrery.guard` cannot be used: true for a value that is not a
     * guard of `auth.guards` (an array entry with a name without a dot), and
     * for a guard that the auth manager cannot build (InvalidArgumentException:
     * an unknown driver, or a default guard that names no guard). Null and ''
     * mean the default guard, which is probed the same way. Null (unknown)
     * when building the guard throws another error, which is reported
     * (`health:guard`).
     */
    public static function guardInvalid(): ?bool
    {
        $guard = self::guard();

        if ($guard === false) {
            return true;
        }

        try {
            app('auth')->guard($guard);
        } catch (InvalidArgumentException) {
            return true;
        } catch (Throwable $error) {
            Watcher::instance()->report('health:guard', $error);

            return null;
        }

        return false;
    }

    /**
     * Whether the configured guard has a user. An invalid guard, a guard
     * that cannot be built, and a guard whose user() throws any other error
     * (reported as `auth:guard`) count as signed in: the package cannot know
     * that nobody is, so it answers 403, never 401 or 500.
     */
    private static function signedIn(Request $request): bool
    {
        $guard = self::guard();

        if ($guard === false) {
            return true;
        }

        try {
            return $request->user($guard) !== null;
        } catch (InvalidArgumentException) {
            return true;
        } catch (Throwable $error) {
            // Fail closed: a guard that could not be asked gives 403, never 401 or 500.
            Watcher::instance()->report('auth:guard', $error);

            return true;
        }
    }

    /**
     * The configured guard: null for the default guard, its name, or false
     * when the value is not the name of an array entry of `auth.guards`.
     * Orrery::actor() reads the default actor from the same guard.
     */
    public static function guard(): string|false|null
    {
        $config = app('config');
        $guard = $config->get('orrery.guard');

        // An empty value (ORRERY_GUARD= in .env) is the default guard, as auth()->guard('') is.
        if ($guard === null || $guard === '') {
            return null;
        }

        $guards = $config->get('auth.guards');

        // A dot would make config("auth.guards.{$name}") read a nested key, so such a name is never a guard.
        if (! is_string($guard) || str_contains($guard, '.') || ! is_array($guards) || ! array_key_exists($guard, $guards) || ! is_array($guards[$guard])) {
            return false;
        }

        return $guard;
    }
}
