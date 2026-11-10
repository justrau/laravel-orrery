<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Justrau\Orrery\Exceptions\WatchedOperationFailed;
use Justrau\Orrery\Http\Middleware\Authenticate;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Tests\Controller\Fakes\GateProvider;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\TestCase;
use RuntimeException;
use Throwable;

/**
 * The 401/403 split of Authenticate:
 *
 * - the view check fails and the configured guard (orrery.guard, null: the
 *   default guard) has no user: 401 {"error": "unauthenticated"};
 * - the view check fails and a user is present: 403 {"error": "forbidden"};
 * - a write without the operate ability: 403 {"error": "forbidden_action"};
 * - an orrery.guard that is not a key of auth.guards is never passed to
 *   user(): a denied request gets 403 forbidden, and /api/health warns
 *   guard_invalid;
 * - the page route answers the same statuses as plain HTTP errors.
 *
 * The route-by-actor matrix is in AuthorizationTest. This class does not
 * extend ControllerTest, because that base class allows every request.
 */
final class AuthenticationSplitTest extends TestCase
{
    private const ADMIN_GUARD = 'orrery-admin';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The bodies below are the production shape: Testbench may turn app.debug on.
        $app['config']->set('app.debug', false);
        $app['config']->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');
        $app['config']->set('auth.guards.'.self::ADMIN_GUARD, ['driver' => 'session', 'provider' => 'users']);
    }

    /**
     * Rejects: a guest that gets 403 (today's single answer), a 401 without
     * the error code, and a body that keeps Laravel's "message" key.
     */
    public function test_a_guest_gets_401_unauthenticated_from_the_api(): void
    {
        Orrery::auth(static fn (): bool => false);

        $this->getJson('/orrery/api/workload')->assertStatus(401)->assertExactJson(['error' => 'unauthenticated']);
        $this->postJson('/orrery/api/control', [])->assertStatus(401)->assertExactJson(['error' => 'unauthenticated']);
    }

    /**
     * Rejects: a view check that returns the callback's value uncast: a callback that gives null for a guest
     * (`$request->user()?->is_admin`) ended every request with a 500 instead of the 401.
     */
    public function test_a_null_view_callback_is_a_denial(): void
    {
        Orrery::auth(static fn () => null);

        $this->getJson('/orrery/api/workload')->assertStatus(401)->assertExactJson(['error' => 'unauthenticated']);
    }

    /**
     * Rejects: a check that casts the callback's result to bool: `Gate::inspect()` returns a
     * Response object, which is truthy also when it denies, so every guest was let in. Only `true` allows, for
     * the view and the operate callbacks alike.
     * Still passes: a check that accepts only `true` and some other exact value.
     */
    public function test_only_a_true_result_of_a_callback_allows(): void
    {
        $denied = \Illuminate\Auth\Access\Response::deny();
        Orrery::auth(static fn () => $denied);

        $this->getJson('/orrery/api/workload')->assertStatus(401)->assertExactJson(['error' => 'unauthenticated']);

        foreach ([1, 'yes', $denied] as $value) {
            Orrery::auth(static fn () => true);
            Orrery::operateUsing(static fn () => $value);

            $this->postJson('/orrery/api/control', ['action' => 'pause'])->assertStatus(403)->assertExactJson(['error' => 'forbidden_action']);
        }
    }

    /**
     * Rejects: a 401 that depends on the callback (the 401 asks
     * the guard, not the callback), so a guest without a callback outside
     * local must get 401 too.
     */
    public function test_a_guest_without_a_callback_outside_local_gets_401(): void
    {
        $this->assertFalse($this->app->environment('local'));

        $this->getJson('/orrery/api/workload')->assertStatus(401)->assertExactJson(['error' => 'unauthenticated']);
    }

    /**
     * Rejects: a signed-in user who is denied and gets 401 (a reload would
     * not help), and a 403 without the error code.
     */
    public function test_a_denied_user_gets_403_forbidden(): void
    {
        Orrery::auth(static fn (): bool => false);
        $this->actingAs(new User);

        $this->getJson('/orrery/api/workload')->assertStatus(403)->assertExactJson(['error' => 'forbidden']);
        $this->postJson('/orrery/api/control', [])->assertStatus(403)->assertExactJson(['error' => 'forbidden']);
    }

    /**
     * Rejects: a viewer's write answered with "forbidden" (the SPA would end
     * the session for one refused action), and a read refused to a viewer.
     */
    public function test_a_viewer_gets_403_forbidden_action_on_a_write_and_still_reads(): void
    {
        Orrery::auth(static fn (): bool => true);
        Orrery::operateUsing(static fn (): bool => false);
        $this->actingAs(new User);

        $this->postJson('/orrery/api/control', [])->assertStatus(403)->assertExactJson(['error' => 'forbidden_action']);
        $this->getJson('/orrery/api/control')->assertOk();
    }

    /**
     * A callback on a non-default guard: orrery.guard names it.
     *
     * Rejects: Authenticate asking the default guard while orrery.guard
     * names another: the admin user would get 401 (no user on `web`), and a
     * user on `web` only would get 403 instead of 401.
     */
    public function test_the_401_rule_asks_the_configured_guard(): void
    {
        $this->app['config']->set('orrery.guard', self::ADMIN_GUARD);
        Orrery::auth(static fn (Request $request): bool => $request->user(self::ADMIN_GUARD)?->getAttribute('email') === 'admin@example.test');

        // A signed-in admin who is denied: 403.
        $this->actingAs((new User)->forceFill(['email' => 'other@example.test']), self::ADMIN_GUARD);
        $this->getJson('/orrery/api/workload')->assertStatus(403)->assertExactJson(['error' => 'forbidden']);

        // A user of the default guard only: no user on the configured guard, so 401.
        $this->app['auth']->forgetGuards();
        $this->actingAs((new User)->forceFill(['email' => 'web@example.test']), 'web');
        $this->getJson('/orrery/api/workload')->assertStatus(401)->assertExactJson(['error' => 'unauthenticated']);

        // The allowed admin.
        $this->app['auth']->forgetGuards();
        $this->actingAs((new User)->forceFill(['email' => 'admin@example.test']), self::ADMIN_GUARD);
        $this->getJson('/orrery/api/workload')->assertOk();
    }

    /**
     * Without orrery.guard, the default guard decides.
     *
     * Rejects: a 401 rule that asks every guard (or a fixed one): a user of
     * only the admin guard must be a guest for the default guard.
     */
    public function test_without_a_configured_guard_the_default_guard_decides(): void
    {
        $this->assertNull(config('orrery.guard'));
        Orrery::auth(static fn (): bool => false);

        $this->actingAs(new User, self::ADMIN_GUARD);
        $this->app['auth']->shouldUse('web');
        $this->getJson('/orrery/api/workload')->assertStatus(401)->assertExactJson(['error' => 'unauthenticated']);
    }

    /**
     * An undefined orrery.guard.
     *
     * Rejects: passing the name to user() (InvalidArgumentException, so a
     * 500), and a 401 for it (the package cannot know that nobody is signed
     * in): a denied request gets 403 forbidden, and /api/health warns.
     */
    public function test_an_undefined_guard_gives_403_never_500_and_a_health_warning(): void
    {
        $this->app['config']->set('orrery.guard', 'no-such-guard');
        Orrery::auth(static fn (): bool => false);

        $this->getJson('/orrery/api/workload')->assertStatus(403)->assertExactJson(['error' => 'forbidden']);

        Orrery::auth(static fn (): bool => true);
        $this->getJson('/orrery/api/health')->assertOk()->assertJsonPath('guard_invalid', true);

        $this->app['config']->set('orrery.guard', self::ADMIN_GUARD);
        $this->getJson('/orrery/api/health')->assertOk()->assertJsonPath('guard_invalid', false);

        $this->app['config']->set('orrery.guard', null);
        $this->getJson('/orrery/api/health')->assertOk()->assertJsonPath('guard_invalid', false);
    }

    /**
     * Rejects: a guard value that is not a string (an array or a number from a
     * published configuration) passed to user(), or taken as the default; and
     * an empty value flagged as invalid.
     */
    public function test_a_guard_value_that_is_not_a_string_is_invalid(): void
    {
        foreach ([['web'], 5, true] as $value) {
            $this->app['config']->set('orrery.guard', $value);
            $this->assertTrue(Authenticate::guardInvalid(), var_export($value, true));
        }

        $this->app['config']->set('orrery.guard', 'web');
        $this->assertFalse(Authenticate::guardInvalid());

        // ORRERY_GUARD= in .env: the default guard, as auth()->guard('') is.
        $this->app['config']->set('orrery.guard', '');
        $this->assertFalse(Authenticate::guardInvalid());
    }

    /**
     * A host guard whose driver throws another error than
     * InvalidArgumentException while /api/health probes it.
     *
     * Rejects: a probe that lets the error out (every /api/health a 500), and
     * one that answers true or false for a guard it could not check (unknown
     * is never "valid"); the error is reported without its message.
     */
    public function test_a_guard_probe_that_throws_gives_guard_invalid_null(): void
    {
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(static function (Throwable $error) use (&$reported): bool {
            $reported[] = $error;

            return false;
        });
        $this->app['auth']->extend('throwing', static fn () => throw new RuntimeException('no secret'));
        $this->app['config']->set('auth.guards.custom', ['driver' => 'throwing']);
        $this->app['config']->set('orrery.guard', 'custom');
        Orrery::auth(static fn (): bool => true);

        $this->getJson('/orrery/api/health')->assertOk()->assertJsonPath('guard_invalid', null);

        self::assertCount(1, $reported);
        self::assertInstanceOf(WatchedOperationFailed::class, $reported[0]);
        self::assertSame('health:guard', $reported[0]->operation);
    }

    /**
     * A host guard whose user() throws another error than
     * InvalidArgumentException on a denied request: fail closed.
     *
     * Rejects: letting the error out (a 500), answering 401 for a guard that
     * could not be asked, and dropping the report.
     */
    public function test_a_denied_request_on_a_throwing_guard_gets_403(): void
    {
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(static function (Throwable $error) use (&$reported): bool {
            $reported[] = $error;

            return false;
        });
        $this->app['auth']->extend('throwing', static fn () => throw new RuntimeException('no secret'));
        $this->app['config']->set('auth.guards.custom', ['driver' => 'throwing']);
        $this->app['config']->set('orrery.guard', 'custom');
        Orrery::auth(static fn (): bool => false);

        $this->getJson('/orrery/api/workload')->assertStatus(403)->assertExactJson(['error' => 'forbidden']);

        self::assertCount(1, $reported);
        self::assertInstanceOf(WatchedOperationFailed::class, $reported[0]);
        self::assertSame('auth:guard', $reported[0]->operation);
    }

    /**
     * Rejects: a broken default guard (auth.defaults.guard names no guard)
     * that gives a 500 or a 401, or that /api/health calls valid.
     */
    public function test_a_broken_default_guard_gives_403_and_a_health_warning(): void
    {
        $this->assertNull(config('orrery.guard'));
        $this->app['config']->set('auth.defaults.guard', 'missing');
        $this->app['auth']->forgetGuards();

        Orrery::auth(static fn (): bool => false);
        $this->getJson('/orrery/api/workload')->assertStatus(403)->assertExactJson(['error' => 'forbidden']);

        Orrery::auth(static fn (): bool => true);
        $this->getJson('/orrery/api/health')->assertOk()->assertJsonPath('guard_invalid', true);
    }

    /**
     * Rejects: a page route that answers the JSON codes, a page route that
     * keeps 403 for a guest, and a JSON decision by the Accept header
     * (expectsJson()) instead of by the route.
     */
    public function test_the_page_route_answers_plain_http_errors(): void
    {
        Orrery::auth(static fn (): bool => false);

        $guest = $this->get('/orrery/overview', ['Accept' => 'text/html'])->assertStatus(401);
        $this->assertStringNotContainsString('unauthenticated', (string) $guest->getContent());

        // JSON by route, never by Accept: an API route answers its code to a browser
        // request, and the page route answers no code to a JSON request.
        $this->get('/orrery/api/workload', ['Accept' => 'text/html'])->assertStatus(401)->assertExactJson(['error' => 'unauthenticated']);
        $this->getJson('/orrery/overview')->assertStatus(401)->assertJsonMissingPath('error');

        $this->actingAs(new User);
        $denied = $this->get('/orrery/overview', ['Accept' => 'text/html'])->assertStatus(403);
        $this->assertStringNotContainsString('"error"', (string) $denied->getContent());
    }

    /**
     * A guard that is configured but cannot be built (an unknown driver), or
     * a name with a dot (config() would read a nested key): never a 500.
     *
     * Rejects: passing such a guard to user() unguarded (InvalidArgumentException,
     * so a 500), a 401 for it, and a health response that calls it valid.
     */
    public function test_a_broken_guard_gives_403_never_500_and_a_health_warning(): void
    {
        $this->app['config']->set('auth.guards.broken', ['driver' => 'nope']);
        $this->app['config']->set('orrery.guard', 'broken');
        Orrery::auth(static fn (): bool => false);

        $this->getJson('/orrery/api/workload')->assertStatus(403)->assertExactJson(['error' => 'forbidden']);

        Orrery::auth(static fn (): bool => true);
        $this->getJson('/orrery/api/health')->assertOk()->assertJsonPath('guard_invalid', true);

        // A guard entry that is not an array, and a dotted name that reaches into a guard's keys.
        foreach (['auth.guards.scalar' => 'session', 'orrery.guard' => 'scalar'] as $key => $value) {
            $this->app['config']->set($key, $value);
        }
        $this->assertTrue(Authenticate::guardInvalid(), 'A guard entry that is not an array.');

        $this->app['config']->set('orrery.guard', 'web.driver');
        $this->assertTrue(Authenticate::guardInvalid(), 'A dotted guard name.');

        // A literal key with a dot: the auth manager would build the nested entry
        // auth.guards.admin.x, not this one, so it is never used.
        $session = ['driver' => 'session', 'provider' => 'users'];
        $this->app['config']->set('auth.guards', ['web' => $session, 'admin' => $session + ['x' => $session], 'admin.x' => $session]);
        $this->app['config']->set('orrery.guard', 'admin.x');
        $this->assertTrue(Authenticate::guardInvalid(), 'A dotted key that the auth manager reads as a nested entry.');
    }

    /**
     * The shipped provider's gates on a non-default orrery.guard: the gates
     * get the user of that guard, never the default guard's user. The users
     * are set with setUser(): actingAs() would make the guard the default
     * guard and hide the difference.
     *
     * Rejects: a gate checked for the default guard's user (the staff member
     * on the configured guard gets 403, and a default-guard account with a
     * listed email may view and operate), and a gate that asks every guard.
     */
    public function test_the_shipped_gates_check_the_user_of_the_configured_guard(): void
    {
        $this->app['config']->set('orrery.guard', self::ADMIN_GUARD);
        $this->app->register(GateProvider::class, force: true);

        // The staff member signs in on the configured guard only.
        $this->app['auth']->guard(self::ADMIN_GUARD)->setUser((new User)->forceFill(['email' => GateProvider::ALLOWED_EMAIL]));
        $this->assertSame('web', $this->app['auth']->getDefaultDriver());
        $this->assertNull($this->app['auth']->guard()->user());

        $this->getJson('/orrery/api/workload')->assertOk();
        $this->postJson('/orrery/api/control', [])->assertStatus(422);

        // A default-guard account with the listed email, and nobody on the configured guard.
        $this->app['auth']->forgetGuards();
        $this->app['auth']->guard('web')->setUser((new User)->forceFill(['email' => GateProvider::ALLOWED_EMAIL]));
        $this->assertNull($this->app['auth']->guard(self::ADMIN_GUARD)->user());

        $this->getJson('/orrery/api/workload')->assertStatus(401)->assertExactJson(['error' => 'unauthenticated']);
        $this->postJson('/orrery/api/control', [])->assertStatus(401)->assertExactJson(['error' => 'unauthenticated']);
        // The 401 comes before the operate check, so ask the operate gate itself.
        $this->assertFalse(Orrery::canOperate(request()), 'the operate gate asked the default guard');

        // A viewer on the configured guard, and a listed operator on the default guard: the viewer may not write.
        $this->app['auth']->guard(self::ADMIN_GUARD)->setUser((new User)->forceFill(['email' => GateProvider::VIEWER_EMAIL]));
        $this->getJson('/orrery/api/workload')->assertOk();
        $this->postJson('/orrery/api/control', [])->assertStatus(403)->assertExactJson(['error' => 'forbidden_action']);
    }

    /**
     * The shipped provider's gates when orrery.guard cannot give a user: a
     * name that is not a guard, a guard that cannot be built, and a guard
     * whose user() throws. Each denies, while the default guard holds a user
     * with a listed email.
     *
     * Rejects: a gate that falls back to the default guard's user for a
     * guard it cannot ask (the request gets 200), and a guard error that
     * leaves the gate as a 500.
     */
    public function test_the_shipped_gates_deny_when_the_configured_guard_cannot_be_asked(): void
    {
        $this->app['auth']->extend('throwing', static fn () => throw new RuntimeException('no secret'));
        $this->app['config']->set('auth.guards.custom', ['driver' => 'throwing']);
        $this->app['config']->set('auth.guards.broken', ['driver' => 'nope']);
        $this->app->register(GateProvider::class, force: true);

        foreach (['no-such-guard', 'broken', 'custom'] as $guard) {
            $this->app['config']->set('orrery.guard', $guard);
            $this->app['auth']->forgetGuards();
            $this->app['auth']->guard('web')->setUser((new User)->forceFill(['email' => GateProvider::ALLOWED_EMAIL]));

            $this->getJson('/orrery/api/workload')->assertStatus(403)->assertExactJson(['error' => 'forbidden']);
            $this->assertFalse(Orrery::canOperate(request()), "The operate gate on the guard {$guard}.");
        }
    }
}
