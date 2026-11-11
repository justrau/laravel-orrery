<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Ping;

/**
 * The middleware of the ping route: the `orrery` group without its session,
 * cookie and CSRF middleware, or the stack of `ping.middleware`; and the
 * guard that keeps a redirect or an error of that stack from looking like
 * an answer.
 */
final class PingMiddlewareTest extends PingTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app['config'];

        match ($this->name()) {
            'test_an_app_session_authenticator_in_the_group_is_removed' => $config->set('orrery.middleware', ['web', SessionGuardMiddleware::class]),
            'test_a_session_reading_middleware_breaks_only_the_default_stack' => $config->set('orrery.middleware', ['web', SessionReadingMiddleware::class]),
            'test_ping_middleware_gives_the_ping_its_own_stack' => [
                $config->set('orrery.middleware', ['web', SessionReadingMiddleware::class]),
                $config->set('orrery.ping.middleware', [TeapotMiddleware::class]),
            ],
            'test_an_empty_ping_middleware_runs_none' => [
                $config->set('orrery.middleware', ['web', SessionReadingMiddleware::class]),
                $config->set('orrery.ping.middleware', []),
            ],
            'test_a_redirect_of_the_stack_is_never_the_answer' => $config->set('orrery.ping.middleware', [RedirectingMiddleware::class]),
            default => null,
        };
    }

    /**
     * Rejects: a STATELESS list that names the AuthenticatesSessions interface only as a string: Laravel's exclusion
     * matches classes, so an application's own session authenticator would still run and throw without a session.
     */
    public function test_an_app_session_authenticator_in_the_group_is_removed(): void
    {
        $this->ping()->assertOk();
    }

    /**
     * Rejects: a stack error that answers 500 with the exception page (with app.debug, Laravel's page shows the
     * request, so the query token), instead of an empty 503.
     */
    public function test_a_session_reading_middleware_breaks_only_the_default_stack(): void
    {
        $this->app['config']->set('app.debug', true);

        $response = $this->get('/orrery/ping?token='.self::TOKEN);

        self::assertSame(503, $response->status());
        self::assertSame('', (string) $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * Rejects: a ping.middleware option that the route ignores (the group's session reader would still run).
     */
    public function test_ping_middleware_gives_the_ping_its_own_stack(): void
    {
        $this->ping()->assertStatus(418);
    }

    public function test_an_empty_ping_middleware_runs_none(): void
    {
        $this->ping()->assertOk();
    }

    /**
     * Rejects: a redirect of the stack (an `auth` middleware sending guests to the login page) that reaches the
     * monitor: a monitor that follows redirects would read the login page's 200 as up.
     */
    public function test_a_redirect_of_the_stack_is_never_the_answer(): void
    {
        $response = $this->ping();

        self::assertSame(503, $response->status());
        self::assertNull($response->headers->get('Location'));
    }
}
