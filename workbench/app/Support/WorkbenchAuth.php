<?php

namespace Workbench\App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Workbench\App\Http\Controllers\LoginController;
use Workbench\App\Models\User;

/**
 * The two authorization modes of the Workbench.
 *
 * - The default mode (WORKBENCH_AUTH unset or empty) runs in the `local` environment. Every
 *   visitor may view the dashboard, and the package's local rule lets every visitor operate.
 * - The strict mode (WORKBENCH_AUTH=strict) runs in the `production` environment, so the local
 *   rule of OrreryApplicationServiceProvider gives nobody access. Sessions use the `file` driver,
 *   the web group checks CSRF tokens, a Workbench-only login form signs a user in, and the gates
 *   allow only the users of USERS.
 *
 * Any other value of WORKBENCH_AUTH refuses to start: a mistyped mode must never fall back to
 * the allow-all mode. WorkbenchServiceProvider calls every method here; WorkbenchStrictAuthTest
 * applies the same methods without the provider, which refuses to run outside the launcher.
 */
final class WorkbenchAuth
{
    public const VARIABLE = 'WORKBENCH_AUTH';

    public const STRICT = 'strict';

    /** The environment of each mode. */
    public const DEFAULT_ENVIRONMENT = 'local';

    public const STRICT_ENVIRONMENT = 'production';

    /** The password of every seeded user. The Workbench is a local development app. */
    public const PASSWORD = 'password';

    /** The abilities of the package (OrreryApplicationServiceProvider). */
    public const VIEW = 'viewOrrery';

    public const OPERATE = 'operateOrrery';

    /**
     * The seeded users and their abilities in the strict mode.
     *
     * @var array<string, array{name: string, abilities: list<string>}>
     */
    public const USERS = [
        'viewer@example.com' => ['name' => 'Viewer', 'abilities' => [self::VIEW]],
        'operator@example.com' => ['name' => 'Operator', 'abilities' => [self::VIEW, self::OPERATE]],
        'denied@example.com' => ['name' => 'Denied', 'abilities' => []],
    ];

    /** The login form and the logout route of the strict mode. */
    public const LOGIN_PATH = 'login';

    public const LOGOUT_PATH = 'logout';

    /**
     * Whether the strict mode is on, from the process environment.
     */
    public static function strict(): bool
    {
        return self::parse(ProcessEnvironment::get(self::VARIABLE));
    }

    /**
     * Whether $value selects the strict mode. Null and '' select the default mode; `strict`
     * selects the strict mode; every other value throws.
     */
    public static function parse(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === false) {
            return false;
        }

        if ($value === self::STRICT) {
            return true;
        }

        throw new RuntimeException(sprintf(
            'The Workbench refuses %s=[%s]: leave it unset for the allow-all mode, or set it to `%s`.',
            self::VARIABLE,
            is_scalar($value) ? (string) $value : get_debug_type($value),
            self::STRICT,
        ));
    }

    public static function environment(bool $strict): string
    {
        return $strict ? self::STRICT_ENVIRONMENT : self::DEFAULT_ENVIRONMENT;
    }

    /**
     * The authentication settings of both modes, and the sessions of the strict mode.
     *
     * The guard and its provider are pinned in both modes, so the dashboard's guard (the default
     * guard, `orrery.guard` null) always reads the Workbench users. The strict mode keeps its
     * sessions in files under the owned storage path (session.files, which
     * WorkbenchConfiguration pins), so a login never writes the application's database file.
     */
    public static function apply(Repository $config, bool $strict): void
    {
        $config->set('auth.defaults', ['guard' => 'web', 'passwords' => 'users']);
        $config->set('auth.guards', ['web' => ['driver' => 'session', 'provider' => 'users']]);
        $config->set('auth.providers', ['users' => ['driver' => 'eloquent', 'model' => User::class]]);
        $config->set('auth.passwords', []);
        // The seeder hashes three passwords at every build. The lowest cost keeps the build fast;
        // a hash of another cost still verifies.
        $config->set('hashing.driver', 'bcrypt');
        $config->set('hashing.bcrypt.rounds', 4);
        $config->set('orrery.guard', null);

        if ($strict) {
            $config->set('session.driver', 'file');
            $config->set('session.connection', null);
            $config->set('app.debug', false);
        }
    }

    /**
     * The gates of the dashboard. The default mode allows every visitor to view; the strict
     * mode allows only the abilities of USERS.
     */
    public static function defineGates(bool $strict): void
    {
        if (! $strict) {
            Gate::define(self::VIEW, static fn ($user = null): bool => true);

            return;
        }

        foreach ([self::VIEW, self::OPERATE] as $ability) {
            Gate::define($ability, static fn ($user = null): bool => self::allows($user, $ability));
        }
    }

    public static function allows(mixed $user, string $ability): bool
    {
        if (! $user instanceof Authenticatable || ! isset($user->email) || ! is_string($user->email)) {
            return false;
        }

        return in_array($ability, self::USERS[$user->email]['abilities'] ?? [], true);
    }

    /**
     * Inserts the users of USERS, each with PASSWORD. Both modes seed them; only the strict
     * mode asks for a login.
     */
    public static function seedUsers(): void
    {
        foreach (self::USERS as $email => $user) {
            User::query()->create(['name' => $user['name'], 'email' => $email, 'password' => self::PASSWORD]);
        }
    }

    /**
     * The login form, the login and the logout, in the web group (sessions and CSRF).
     */
    public static function routes(Registrar $router): void
    {
        $router->group(['middleware' => 'web'], static function (Registrar $router): void {
            $router->get(self::LOGIN_PATH, [LoginController::class, 'show'])->name('workbench.login');
            $router->post(self::LOGIN_PATH, [LoginController::class, 'login'])->name('workbench.login.attempt');
            $router->post(self::LOGOUT_PATH, [LoginController::class, 'logout'])->name('workbench.logout');
        });
    }
}
