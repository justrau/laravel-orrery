<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\OffSwitch;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Route;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Runtime\OffSwitch;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Support\PackageStatements;

/**
 * ORRERY_ENABLED=false: every dashboard route answers 503 with the reason,
 * after the access check, and reads nothing of the package storage.
 */
final class DisabledRoutesTest extends ControllerTest
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('orrery.enabled', false);
    }

    /**
     * Rejects: a dashboard route (a new one included: the test walks the router) that answers anything but 503 while
     * the package is off, an API 503 without the stable code, a page 503 without the reason, and any statement on a
     * package table or session.
     * Still passes: a 503 whose message words change (the API test checks the code and the message constant).
     */
    public function test_every_dashboard_route_answers_503_without_touching_the_package_storage(): void
    {
        $tableStatements = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$tableStatements): void {
            if (str_contains($event->sql, 'orrery_')) {
                $tableStatements[] = $event->sql;
            }
        });

        $requests = [];

        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (! str_starts_with($route->uri(), 'orrery')) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $requests[] = [$method, '/'.self::concrete($route->uri())];
            }
        }

        self::assertGreaterThanOrEqual(40, count($requests), 'The router has the dashboard routes.');

        $statements = PackageStatements::record(function () use ($requests): void {
            foreach ($requests as [$method, $uri]) {
                $response = $this->json($method, $uri);
                $response->assertStatus(503);

                if (str_starts_with($uri, '/orrery/api')) {
                    $response->assertExactJson(['error' => OffSwitch::ERROR, 'message' => OffSwitch::MESSAGE]);
                } else {
                    self::assertStringContainsString(OffSwitch::MESSAGE, (string) $response->getContent(), $uri);
                    self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'), $uri);
                }
            }
        });

        self::assertSame([], $statements);
        self::assertSame([], $tableStatements);
    }

    /**
     * Rejects: a 503 that answers before the access check, so anyone learns the state of the package.
     */
    public function test_the_access_check_comes_first(): void
    {
        Orrery::auth(static fn (): bool => false);

        $this->getJson('/orrery/api/workload')->assertStatus(401);
        $this->get('/orrery')->assertStatus(401);
    }

    private static function concrete(string $uri): string
    {
        return (string) preg_replace_callback('/\{(\w+)\??\}/', static fn (array $match): string => match ($match[1]) {
            'uuid' => '0d7f2b2a-1111-4111-8111-111111111111',
            'id' => '1',
            'view' => 'jobs',
            default => 'x',
        }, $uri);
    }
}
