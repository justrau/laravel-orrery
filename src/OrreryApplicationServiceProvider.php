<?php

namespace Justrau\Orrery;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Justrau\Orrery\Http\Middleware\Authenticate;
use Justrau\Orrery\Runtime\Watcher;
use Throwable;

class OrreryApplicationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->authorization();
    }

    /**
     * Two abilities: viewOrrery opens the dashboard and its read routes, and
     * operateOrrery also allows the routes that change state. The local
     * environment always has both.
     *
     * The gates get the user of the dashboard's guard (`orrery.guard`, null:
     * the default guard), the guard that Authenticate asks for its 401 and
     * the actor is read from; never the user of another guard.
     */
    protected function authorization(): void
    {
        $this->gate();

        Orrery::auth(function ($request) {
            return static::allows('viewOrrery', $request)
                || app()->environment('local');
        });

        Orrery::operateUsing(function ($request) {
            return static::allows('operateOrrery', $request)
                || app()->environment('local');
        });
    }

    /**
     * Whether the gate allows the ability for the user of `orrery.guard`.
     * A value that is not a guard, a guard that cannot be built, and a guard
     * whose user() throws deny: the package cannot know who is signed in.
     * Another error than InvalidArgumentException is reported (`auth:guard`).
     */
    protected static function allows(string $ability, Request $request): bool
    {
        $guard = Authenticate::guard();

        if ($guard === false) {
            return false;
        }

        try {
            $user = $request->user($guard);
        } catch (InvalidArgumentException) {
            return false;
        } catch (Throwable $error) {
            Watcher::instance()->report('auth:guard', $error);

            return false;
        }

        return Gate::forUser($user)->check($ability);
    }

    protected function gate(): void
    {
        // Override in the published app provider stub.
        Gate::define('viewOrrery', function ($user = null) {
            return false;
        });

        Gate::define('operateOrrery', function ($user = null) {
            return false;
        });
    }

    public function register(): void
    {
        //
    }
}
