<?php

namespace Justrau\Orrery;

use Closure;
use DateTimeZone;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Justrau\Orrery\Http\Middleware\Authenticate;
use Justrau\Orrery\Runtime\SafeText;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\PackageSession;
use Throwable;

final class Orrery
{
    /** Decides the view ability (viewOrrery): every route. */
    public static ?Closure $authUsing = null;

    /** Decides the operate ability (operateOrrery): every route that changes state. */
    public static ?Closure $operateUsing = null;

    /** Names the actor of a dashboard control write (orrery_signals.issued_by). */
    public static ?Closure $actorUsing = null;

    public static ?string $email = null;

    public static ?string $slackWebhookUrl = null;

    public static ?string $slackChannel = null;

    public static ?string $smsNumber = null;

    /**
     * Whether the request may view the dashboard (viewOrrery). Only the
     * result `true` allows: any other value (an object such as a Gate
     * Response, 1, a string) denies. Without a callback, only the local
     * environment may.
     */
    public static function check(Request $request): bool
    {
        return (self::$authUsing ?: fn () => app()->environment('local'))($request) === true;
    }

    public static function auth(Closure $callback): static
    {
        self::$authUsing = $callback;

        return new self;
    }

    /**
     * Whether the request may change state: pause, continue and terminate,
     * retry a job or a batch, retry or forget the members of failure groups,
     * and add or remove a monitored tag. Only the result `true` allows, as
     * for check(). Without a callback, only the local environment may.
     */
    public static function canOperate(Request $request): bool
    {
        return (self::$operateUsing ?: fn () => app()->environment('local'))($request) === true;
    }

    public static function operateUsing(Closure $callback): static
    {
        self::$operateUsing = $callback;

        return new self;
    }

    /**
     * Sets the resolver of the actor that the dashboard's control writes
     * store: Closure(?Request): ?string.
     */
    public static function actorUsing(Closure $callback): static
    {
        self::$actorUsing = $callback;

        return new self;
    }

    /**
     * The actor of a dashboard write, cleaned (SafeText: only a string or an
     * integer, valid UTF-8, no control characters, at most 191 characters),
     * or null. A resolver that returns a model or another object gives null.
     *
     * - The resolver is the actorUsing() callback, else the default: the
     *   user of `orrery.guard` (null: the default guard; a value that is not
     *   a guard: no user): its `email`, else its `name`, else its key.
     * - A resolver that throws, or a cleaning that throws, is reported
     *   through the watcher, and the actor is null: the write proceeds.
     * - Only the HTTP control callers resolve it. The commands store `cli`,
     *   and worker signal reads never resolve it.
     */
    public static function actor(?Request $request): ?string
    {
        try {
            return SafeText::clean(self::$actorUsing !== null ? (self::$actorUsing)($request) : self::defaultActor($request));
        } catch (Throwable $error) {
            Watcher::instance()->report('control:actor', $error);

            return null;
        }
    }

    private static function defaultActor(?Request $request): mixed
    {
        // The guard of the dashboard (orrery.guard), never another guard's user; an invalid one names nobody.
        $guard = Authenticate::guard();
        $user = $guard === false ? null : $request?->user($guard);

        if ($user === null) {
            return null;
        }

        foreach (['email', 'name'] as $attribute) {
            $value = data_get($user, $attribute);

            if (SafeText::clean($value) !== null) {
                return $value;
            }
        }

        return $user->getAuthIdentifier();
    }

    /**
     * The page's stylesheet: dist/app.css (the tokens, the element defaults
     * and the components' CSS, with the bundled font), inlined in one style
     * element, because the package serves no asset files.
     */
    public static function css(): string
    {
        $contents = file_get_contents(__DIR__.'/../dist/app.css');
        $nonce = self::nonceAttribute();

        return "<style data-scheme=\"app\"{$nonce}>\n{$contents}\n</style>";
    }

    /**
     * The nonce attribute of the inlined elements: Laravel's
     * `Vite::cspNonce()`, which a CSP middleware sets with
     * `Vite::useCspNonce()`, HTML-escaped; an empty string when there is none.
     */
    private static function nonceAttribute(): string
    {
        $nonce = app(Vite::class)->cspNonce();

        return is_string($nonce) && $nonce !== '' ? ' nonce="'.e($nonce).'"' : '';
    }

    public static function js(): string
    {
        // The hex flags keep a value such as "</script>" in the app name from
        // closing the script element. An app name that is not valid UTF-8 gets
        // U+FFFD for its bad bytes instead of failing the page.
        $vars = json_encode(
            self::scriptVariables(request()),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
        $contents = file_get_contents(__DIR__.'/../dist/app.js');
        $nonce = self::nonceAttribute();

        return <<<HTML
<script{$nonce}>window.Orrery = {$vars};</script>
<script type="module"{$nonce}>{$contents}</script>
HTML;
    }

    /**
     * The variables of the SPA (window.Orrery). `canOperate` tells the SPA
     * whether to show the write actions; the server checks the operate
     * ability on every write request itself. `appName` and `environment` are
     * the app identity that the sidebar shows. `displayTimezone` and
     * `downForMaintenance` are later additions; the maintenance
     * flag was a prop of the in-DOM template before.
     *
     * @return array<string, mixed>
     */
    public static function scriptVariables(Request $request): array
    {
        return [
            'path' => self::spaPath(config('orrery.path')),
            'proxy_path' => self::spaProxyPath(config('orrery.proxy_path', '')),
            'canOperate' => self::canOperate($request),
            'appName' => is_string($name = config('app.name')) ? $name : null,
            'environment' => app()->environment(),
            'sqliteQueueConnections' => self::sqliteQueueConnections(),
            'displayTimezone' => self::displayTimezone(),
            'downForMaintenance' => app()->isDownForMaintenance(),
        ];
    }

    /**
     * `orrery.path` as the SPA joins it: without a slash at either end, as
     * Laravel's route prefix trims it, so "/orrery/" gives the same URLs as
     * "orrery" ("" for the root).
     */
    private static function spaPath(mixed $path): string
    {
        return is_string($path) ? trim($path, '/') : '';
    }

    /**
     * `orrery.proxy_path` as the SPA joins it: "" or one leading slash and
     * no trailing slash ("/sub/" and "sub" give "/sub").
     */
    private static function spaProxyPath(mixed $proxy): string
    {
        $trimmed = is_string($proxy) ? trim($proxy, '/') : '';

        return $trimmed === '' ? '' : '/'.$trimmed;
    }

    /**
     * The display time zone of the dashboard (`orrery.display_timezone`):
     * the value when it is an IANA zone name (backward-compatible
     * names included), else null, and the browser then uses its own zone. An
     * offset or an abbreviation is not a zone: the browser's Intl would throw
     * on some of them. `Factory` is tzdb's placeholder for an unset zone, not
     * a place, so it is refused too. A browser can still lack a name that
     * PHP knows: every consumer must wrap its Intl call in try/catch and fall
     * back to the browser's own zone.
     */
    public static function displayTimezone(): ?string
    {
        $zone = config('orrery.display_timezone');

        if (! is_string($zone) || $zone === '' || $zone === 'Factory') {
            return null;
        }

        return in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true) ? $zone : null;
    }

    /**
     * The `database` queue connections whose database connection is SQLite:
     * the retry confirmation of their failures says that a
     * retry briefly locks the database. Read from the configuration only.
     *
     * @return list<string>
     */
    public static function sqliteQueueConnections(): array
    {
        $names = [];
        $sessions = app(PackageSession::class);

        foreach ((array) config('queue.connections', []) as $name => $queue) {
            if (! is_array($queue) || ($queue['driver'] ?? null) !== 'database') {
                continue;
            }

            try {
                $database = $sessions->databaseConnectionFor((string) $name);
            } catch (Throwable) {
                continue;
            }

            if (config("database.connections.{$database}.driver") === 'sqlite') {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * Null or an empty string clears the route: no notification goes to
     * mail, and nothing throws (for example the null of env() after
     * config:cache).
     */
    public static function routeMailNotificationsTo(?string $email): static
    {
        self::$email = self::route($email);

        return new self;
    }

    /**
     * The route is a webhook URL (http:// or https://), which gets the
     * legacy Slack message, with $channel as its channel override; or a
     * channel name or id for the Web API channel, which gets the Block Kit
     * message. Null or an empty string clears the route.
     */
    public static function routeSlackNotificationsTo(?string $url, ?string $channel = null): static
    {
        self::$slackWebhookUrl = self::route($url);
        self::$slackChannel = self::route($channel);

        return new self;
    }

    /**
     * Null or an empty string clears the route.
     */
    public static function routeSmsNotificationsTo(?string $number): static
    {
        self::$smsNumber = self::route($number);

        return new self;
    }

    /**
     * Null or an empty string clears the route.
     */
    public static function routeVonageNotificationsTo(?string $number): static
    {
        return self::routeSmsNotificationsTo($number);
    }

    private static function route(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
