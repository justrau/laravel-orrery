# Security

- **Access.** When the view check fails, the answer is 401 `unauthenticated` while `orrery.guard` has no user (the page asks you to sign in again), else 403 `forbidden`. A write without `operateOrrery` gets 403 `forbidden_action`, and the reads go on. A guard name that is not a key of `auth.guards` is never asked: every refusal is then 403, and `/orrery/api/health` warns `guard_invalid`. In `local`, a guest can view and operate.
- **Stored payloads.** The mirror stores the raw job payloads for the retention period, as `failed_jobs` does.
- **Redaction.** Every dashboard response with job data goes through one projection that redacts it. The browser never receives raw serialized PHP (the server reads it with a data-only parser that loads no class), and Laravel's hidden Context is never shown. The stored data is never changed, so a retry publishes the stored payload.
  - A value whose key matches is replaced by `[redacted]`. A key matches an entry by its last words (`stripe_api_key` and `apiKey` match `api_key`), or when any word is `password`, `passwd`, `pwd`, `secret` or `token`. The built-in entries also cover `authorization`, `cookie`, `client_secret`, `private_key`, `credit_card`, `ssn`, `webhook_url` and more.
  - Every string is also searched for bearer tokens, passwords in URLs, `key=value` and `key: value` pairs, JSON and PHP pairs whose key matches, and Slack, Discord and Microsoft webhook URLs.
  - A password in a URL is found only when it holds no unencoded `/`, space or backslash: the rule cannot tell such a password from a port and a path (`host:8080/path?mail=a@b`) or from the text after the URL. A URL needs these characters percent-encoded (`%2F`, `%20`, `%5C`) anyway; for another form, add a pattern.
  - Serialized PHP inside free text (for example a job payload in the message of a failed insert, or a serialized string in a failed cache write) is replaced by `[redacted]` as a whole, whatever `redact.defaults` says. On Laravel 13 you can also keep the bindings out of every query exception's message: set `mask_bindings_in_exception_messages` to `true` on the database connection.
  - `redact.keys` and `redact.patterns` add to the built-in lists; `redact.defaults` `false` removes them. A pattern is a complete PCRE with delimiters and flags, for example `'~ACME-\d{4}~i'`.
  - A value whose redaction fails shows `[redaction failed]`. An invalid pattern makes every job-data read answer 500 `redaction_config_invalid`; `/orrery/api/health` names its index, never its text.
  - Job, queue and connection names, tags and uuids are not redacted. Free text (an exception message, a batch name, a log line in a payload) can hold a secret that no rule matches: add a pattern, and do not grant `viewOrrery` to people who must not see job data. There is no payload search.
  - Write responses carry no job text.
- **Failure-group keys** are an HMAC of the group's text under `app.key`, so a key in a URL cannot be guessed back to a message. Rotating `app.key` splits each group until the old failures age out. With an empty `app.key`, no bulk forget starts (`app_key_missing`).
- **The actor of a control.** A pause, continue or terminate from the dashboard stores who did it, and every user with `viewOrrery` can see it ("Paused by admin@example.com"). The default is the email, else name, else key of the user of the dashboard's guard (`orrery.guard`); the commands store `cli`. To show an alias, set a resolver in the `boot()` method of your provider. It returns a string or null; the package keeps at most 191 characters of valid text, and a resolver that throws is reported and stores no actor.

  ```php
  use Illuminate\Http\Request;
  use Justrau\Orrery\Orrery;

  Orrery::actorUsing(fn (?Request $request): ?string => $request?->user()?->nickname);
  ```

- **Error reports carry no job value.** The package reports its own failures through `report()`, at most once per operation per 60 seconds, as `Justrau\Orrery\Exceptions\WatchedOperationFailed`: no previous exception, an empty trace, and a message from an allowlist (the operation, the exception class, the SQLSTATE, the driver code and the SQL with `?` placeholders, never the bindings; URLs keep only their scheme and host). Match on its fields `operation`, `causeClass`, `sqlState` and `driverCode`, not on the message. Out of the package's control: the SQL breadcrumbs of your error tracker, and the first line of your own exception that passes through a package operation (for example from your `actorUsing()` resolver).
- **Serialized cache values.** The package's own cache entries are arrays of scalars, so it works with `cache.serializable_classes => false`.

## The monitoring ping

`GET /orrery/ping` (see [Monitoring](operations.md#monitoring)) is the one route without the dashboard login: an uptime monitor cannot sign in. A token protects it instead.

- **Off by default.** Without `ORRERY_PING_TOKEN`, or with a token shorter than 32 characters, the route answers 404, as an unknown route. `/orrery/api/health` (`ping.token`: `too_short`) and `orrery:install` name a token that is too short.
- **The token.** Generate a long random value, for example `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`, and set `ORRERY_PING_TOKEN` in your `.env` (then `php artisan config:cache`). A request sends it as `Authorization: Bearer <token>`, as the header `X-Orrery-Token`, or as `?token=` for a monitor that takes only a URL. The comparison takes constant time (`hash_equals`). A missing or wrong token gets 403 with an empty body.
- **Prefer a header.** A token in the query string reaches web server and proxy access logs, and the browser history. Use HTTPS always, send the token in a header where your monitor allows it, and rotate the token when it may have leaked.
- **The rate limit.** Only failed token checks count: 60 per minute and client. Past the limit, every request of that client gets 429 (with `Retry-After`) before the token is compared, also one with the right token. A monitor that sends the right token never reaches the limit, and a request while the route is off does not touch the cache. The limit uses Laravel's rate limiter store: `cache.limiter` when it is set, else the default cache store. When that store writes your application's SQLite file (a `database` store on SQLite), Orrery does not write that file automatically, so the limit is off: `/orrery/api/health` says `ping.rate_limit`: `off_host_sqlite`, and `orrery:install` warns. Set `cache.limiter` to another store to turn the limit on.
- **Behind a proxy or a load balancer**, configure Laravel's `TrustProxies` middleware for it. Without it, every request has the proxy's address, so all clients share one limit, and failed checks of one client lock out your monitor.
- **The middleware.** The route uses the `orrery` middleware group (`orrery.middleware`, by default `web`), so middleware that you add to that group, such as an IP allow-list, also covers it. It removes the session, cookie and CSRF middleware of that group (`StartSession`, `AuthenticateSession` and every other class that implements `AuthenticatesSessions`, `EncryptCookies`, `AddQueuedCookiesToResponse`, `ShareErrorsFromSession` and the CSRF check, and their subclasses): a ping writes no session and sets no cookie. It does not use the `viewOrrery` and `operateOrrery` gates.
- **A middleware that needs the session** (one of yours in `web` that calls `$request->session()`) fails without it. The ping then answers an empty 503, and so does every redirect of the middleware before it: an error page with `app.debug` on would show the request and the token, and a monitor that follows a redirect to a login page would read its 200 as up. Give the ping its own stack with `orrery.ping.middleware`, a list of middleware (for example your IP allow-list only, or `[]`). Never put `auth` in it: a guest would get the redirect, so the ping would always answer 503.
- **What the answer shows.** The status of each check, versions, ages, counts and the names of queues over their limit. Never a job value, a payload, an exception message, a process identity or a configuration value. Every answer, also the 403, 404 and 429, has `Cache-Control: no-store`.

## Content Security Policy

The dashboard runs under a strict policy: no `unsafe-inline`, no `unsafe-eval`. Its CSS and JavaScript are inlined into the page, and each inlined element carries the nonce of Laravel's `Vite::cspNonce()` when your middleware sets one. The dashboard uses the system font, and the page loads no font and no external script. The package sets no policy itself: add a middleware to `orrery.middleware`, for example:

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

final class OrreryContentSecurityPolicy
{
    private const POLICY = "default-src 'none'; script-src 'nonce-{nonce}'; style-src 'nonce-{nonce}'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $response = $next($request);
        $response->headers->set('Content-Security-Policy', str_replace('{nonce}', $nonce, self::POLICY));

        return $response;
    }
}
```

```php
// config/orrery.php
'middleware' => ['web', \App\Http\Middleware\OrreryContentSecurityPolicy::class],
```

If your application already sets a policy for every route, give the dashboard routes this one, or add the dashboard's needs to yours.
