<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Justrau\Orrery\Http\Controllers\PingController;
use Justrau\Orrery\Http\Middleware\Authenticate;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\OrreryServiceProvider;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The operate ability (operateOrrery). The full
 * route-by-actor matrix is in AuthorizationTest. This class covers the
 * guard itself: its place on every route, its check by method, its 403
 * body (`forbidden_action`), the order of the two checks, and window.Orrery.canOperate.
 *
 * It does not extend ControllerTest, because that base class allows every
 * request.
 */
final class OperateAbilityTest extends TestCase
{
    private const PROBE = '/operate-probe';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The 403 bodies below are the production shape. Testbench 10 (Laravel 12) turns
        // app.debug on by default, which adds the exception, file and trace to the body.
        $app['config']->set('app.debug', false);
    }

    public function test_every_package_route_carries_the_authenticate_middleware(): void
    {
        $path = trim((string) config('orrery.path'), '/');
        $missing = [];
        $count = 0;

        /** @var Route $route */
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            if ($route->uri() !== $path && ! str_starts_with($route->uri(), $path.'/')) {
                continue;
            }

            $count++;

            // The one exception: the monitoring ping has its own token check (PingController, PingRouteTest).
            if (self::isPing($route)) {
                continue;
            }

            if (! in_array(Authenticate::class, $route->gatherMiddleware(), true)) {
                $missing[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertGreaterThan(20, $count, 'The package routes were not found.');
        $this->assertSame([], $missing, 'A package route does not carry the Authenticate middleware.');
    }

    /**
     * Rejects: an exception that covers more than the one GET ping route, or a ping route that lost its throttle.
     */
    public function test_only_the_throttled_ping_route_has_no_authenticate_middleware(): void
    {
        $exempt = [];

        /** @var Route $route */
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            if (self::isPing($route)) {
                $exempt[] = implode('|', $route->methods()).' '.$route->uri();
                $this->assertNotSame([], array_filter($route->gatherMiddleware(), static fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, ThrottleRequests::class.':')));
            }
        }

        $this->assertSame(['GET|HEAD orrery/ping'], $exempt);
    }

    /** The monitoring ping: exactly its name, its GET and HEAD methods and its controller. */
    private static function isPing(Route $route): bool
    {
        return $route->getName() === 'orrery.ping'
            && $route->methods() === ['GET', 'HEAD']
            && ltrim($route->getActionName(), '\\') === PingController::class;
    }

    public function test_every_non_get_package_route_is_denied_to_a_viewer_without_operate(): void
    {
        $path = trim((string) config('orrery.path'), '/');
        $writes = [];

        /** @var Route $route */
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), $path.'/')) {
                continue;
            }

            foreach (array_diff($route->methods(), ['GET', 'HEAD']) as $method) {
                $writes[] = [$method, '/'.str_replace(['{id}', '{tag}', '{uuid}', '{connection}', '{queue}'], ['00000000-0000-4000-8000-000000000001', 'probe', '00000000-0000-4000-8000-000000000001', 'database', 'default'], $route->uri())];
            }
        }

        // POST /api/control, /api/tags/monitored, /api/jobs/{uuid}/retry, /api/jobs/{uuid}/forget,
        // /api/jobs/{uuid}/delete, /api/jobs/{uuid}/run-now, /api/queues/waiting, /api/queues/clear,
        // /api/batches/{id}/cancel,
        // /api/bulk/freeze, /api/bulk/run and /api/operations/{id}/resolve, and DELETE /api/tags/monitored/{tag}
        // and /api/tags/monitored?tag=. The legacy writes are gone.
        $this->assertCount(14, $writes, 'The write routes changed: check that each one still needs the operate ability.');

        $this->viewerWithoutOperate();

        foreach ($writes as [$method, $uri]) {
            $this->json($method, $uri, ['tag' => 'probe'])
                ->assertStatus(403)
                ->assertExactJson(['error' => 'forbidden_action']);
        }
    }

    #[DataProvider('unsafeMethods')]
    public function test_a_viewer_without_operate_gets_403_on_a_new_write_route(string $method): void
    {
        $this->probeRoute();
        $this->viewerWithoutOperate();

        $this->json($method, self::PROBE)
            ->assertStatus(403)
            ->assertExactJson(['error' => 'forbidden_action']);
    }

    #[DataProvider('unsafeMethods')]
    public function test_a_user_with_view_and_operate_passes_a_write_route(string $method): void
    {
        $this->probeRoute();
        Orrery::auth(static fn (): bool => true);
        Orrery::operateUsing(static fn (): bool => true);

        $this->json($method, self::PROBE)->assertOk()->assertExactJson(['probe' => true]);
    }

    /**
     * Rejects: a write that a cross-site form can send: with a middleware stack without CSRF
     * protection, a form post reached the write. A write without `X-Requested-With: XMLHttpRequest` (or with
     * another value) gets 403 `header_missing`; a read needs no header.
     * Still passes: a check that compares the value without regard to letter case (`xmlhttprequest`), and a SPA
     * that stops sending the header: TestCase::setUp() sends it (RequestedWithHeaderTest scans dist/app.js).
     */
    #[DataProvider('unsafeMethods')]
    public function test_a_write_without_the_requested_with_header_is_refused(string $method): void
    {
        $this->probeRoute();
        Orrery::auth(static fn (): bool => true);
        Orrery::operateUsing(static fn (): bool => true);

        $this->withoutHeader('X-Requested-With')->json($method, self::PROBE)->assertStatus(403)->assertExactJson(['error' => 'header_missing']);
        $this->withHeader('X-Requested-With', 'fetch')->json($method, self::PROBE)->assertStatus(403)->assertExactJson(['error' => 'header_missing']);
        $this->withoutHeader('X-Requested-With')->getJson(self::PROBE)->assertOk();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeMethods(): array
    {
        return [
            'POST' => ['POST'],
            'PUT' => ['PUT'],
            'PATCH' => ['PATCH'],
            'DELETE' => ['DELETE'],
        ];
    }

    public function test_a_viewer_without_operate_passes_a_read_route(): void
    {
        $this->probeRoute();
        $this->viewerWithoutOperate();

        $this->getJson(self::PROBE)->assertOk()->assertExactJson(['probe' => true]);
        $this->call('OPTIONS', self::PROBE)->assertOk();
    }

    public function test_the_view_check_runs_before_the_operate_check(): void
    {
        $this->probeRoute();
        $operateCalls = 0;
        Orrery::auth(static fn (): bool => false);
        Orrery::operateUsing(static function () use (&$operateCalls): bool {
            $operateCalls++;

            return true;
        });

        // A guest: 401, and a signed-in user: 403 forbidden.
        $this->postJson(self::PROBE)->assertStatus(401)->assertExactJson(['error' => 'unauthenticated']);
        $this->actingAs(new User);
        $this->postJson(self::PROBE)->assertStatus(403)->assertExactJson(['error' => 'forbidden']);
        $this->assertSame(0, $operateCalls, 'The operate callback ran for a request without the view ability.');
    }

    public function test_operate_is_denied_by_default_outside_local(): void
    {
        $this->probeRoute();
        Orrery::auth(static fn (): bool => true);

        $this->assertFalse($this->app->environment('local'));
        $this->postJson(self::PROBE)->assertStatus(403)->assertExactJson(['error' => 'forbidden_action']);
        $this->assertNull(Orrery::$operateUsing);
    }

    public function test_operate_is_allowed_by_default_in_local(): void
    {
        $this->probeRoute();
        Orrery::auth(static fn (): bool => true);
        $this->app['env'] = 'local';

        $this->postJson(self::PROBE)->assertOk();
    }

    public function test_the_page_tells_a_viewer_without_operate_that_it_cannot_operate(): void
    {
        $this->viewerWithoutOperate();

        $this->assertFalse($this->pageVariables()['canOperate']);
    }

    public function test_the_page_tells_an_operator_that_it_can_operate(): void
    {
        Orrery::auth(static fn (): bool => true);
        Orrery::operateUsing(static fn ($request): bool => $request->user()?->email === 'operator@example.test');
        $this->actingAs((new User)->forceFill(['email' => 'operator@example.test']));

        $this->assertTrue($this->pageVariables()['canOperate']);
    }

    /**
     * A closure route inside the real package route group, with no
     * middleware of its own: the group alone must give it the view check
     * and, for a write, the operate check.
     */
    public function test_a_route_in_the_package_group_gets_the_view_and_operate_checks(): void
    {
        $this->app['config']->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');

        $router = $this->app['router'];
        $router->group(OrreryServiceProvider::routeGroup(), static function () use ($router): void {
            // POST only: a GET probe would be shadowed by the earlier catch-all GET routes.
            $router->post('api/group-probe', static fn (): array => ['probe' => true]);
        });
        $router->getRoutes()->refreshNameLookups();

        $route = $router->getRoutes()->match(Request::create('/orrery/api/group-probe', 'POST'));
        $this->assertSame([], $route->controllerMiddleware(), 'The probe must have no middleware of its own.');

        // The view check: 401 for a guest, 403 forbidden for a user.
        Orrery::auth(static fn (): bool => false);
        Orrery::operateUsing(static fn (): bool => true);
        $this->postJson('/orrery/api/group-probe')->assertStatus(401)->assertExactJson(['error' => 'unauthenticated']);
        $this->actingAs(new User);
        $this->postJson('/orrery/api/group-probe')->assertStatus(403)->assertExactJson(['error' => 'forbidden']);

        // The operate check.
        Orrery::auth(static fn (): bool => true);
        Orrery::operateUsing(static fn (): bool => false);
        $this->postJson('/orrery/api/group-probe')->assertStatus(403)->assertExactJson(['error' => 'forbidden_action']);

        Orrery::operateUsing(static fn (): bool => true);
        $this->postJson('/orrery/api/group-probe')->assertOk()->assertExactJson(['probe' => true]);
    }

    private function viewerWithoutOperate(): void
    {
        Orrery::auth(static fn (): bool => true);
        Orrery::operateUsing(static fn (): bool => false);
    }

    /**
     * A route of every method with only the Authenticate middleware, as a
     * future package route would have it.
     */
    private function probeRoute(): void
    {
        $this->app['router']
            ->match(['GET', 'HEAD', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE'], ltrim(self::PROBE, '/'), static fn (): array => ['probe' => true])
            ->middleware(Authenticate::class);
    }

    /**
     * The window.Orrery object of the dashboard page.
     *
     * @return array<string, mixed>
     */
    private function pageVariables(): array
    {
        $this->app['config']->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');

        $html = (string) $this->get('/orrery/dashboard')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<script>window\.Orrery = (\{.*?\});<\/script>/', $html, $match), 'The page has no window.Orrery.');

        $variables = json_decode($match[1], true);
        $this->assertIsArray($variables);
        $this->assertArrayHasKey('canOperate', $variables);

        return $variables;
    }
}
