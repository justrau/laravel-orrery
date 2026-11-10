<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;

/**
 * The API namespace is reserved: an unknown path
 * under /api answers a JSON 404, not the SPA's HTML page.
 */
final class ApiNotFoundTest extends ControllerTest
{
    /**
     * Rejects: the SPA catch-all answering an unknown API path with its HTML page and status 200.
     */
    public function test_an_unknown_api_path_is_a_json_404(): void
    {
        // `/orrery/api` alone answered the SPA page (a review nit); it is reserved too.
        foreach (['/orrery/api/not-an-endpoint', '/orrery/api/jobs/pending/extra/segments', '/orrery/api/healthz', '/orrery/api', '/orrery/api/'] as $path) {
            $response = $this->actingAs(new User)->get($path);
            $response->assertNotFound();
            self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'), $path);
            self::assertSame(['error' => 'not_found'], $response->json(), $path);
        }

        // The SPA routes and the known API routes are unchanged.
        $this->actingAs(new User)->get('/orrery/dashboard')->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk();
    }

    /**
     * Phase 2b: GET /api/stats is removed (no SPA screen read it). It now
     * answers the catch-all's JSON 404.
     *
     * Rejects: the route or DashboardStatsController left in place, and a
     * removal that lets the path fall through to the SPA's HTML page.
     */
    public function test_the_removed_stats_endpoint_is_a_json_404(): void
    {
        $response = $this->actingAs(new User)->get('/orrery/api/stats');

        $response->assertNotFound();
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        self::assertSame(['error' => 'not_found'], $response->json());
        self::assertFalse(class_exists('Justrau\Orrery\Http\Controllers\DashboardStatsController'), 'the controller of the removed endpoint still exists');
    }

    /**
     * The legacy read routes are gone.
     * Each answers the catch-all's JSON 404; the new routes that share a
     * prefix (`/api/jobs/{uuid}`, `/api/batches/{id}`, `/api/tags`) still
     * answer.
     *
     * Rejects: a legacy route or controller left in place (it answers 200
     * with the old shape), and a removal that lets a path fall through to
     * the SPA's HTML page.
     */
    public function test_the_removed_legacy_reads_are_json_404s(): void
    {
        $uuid = '00000000-0000-4000-8000-000000000001';

        foreach ([
            '/orrery/api/jobs/pending', '/orrery/api/jobs/completed', '/orrery/api/jobs/silenced',
            '/orrery/api/jobs/failed', '/orrery/api/jobs/failed/'.$uuid,
            '/orrery/api/legacy/jobs/'.$uuid, '/orrery/api/legacy/batches', '/orrery/api/legacy/batches/b-1',
            '/orrery/api/monitoring', '/orrery/api/monitoring/orders',
            '/orrery/api/metrics/jobs', '/orrery/api/metrics/jobs/App%5CJobs%5CSend', '/orrery/api/metrics/queues', '/orrery/api/metrics/queues/default',
        ] as $path) {
            $response = $this->actingAs(new User)->get($path);
            $response->assertNotFound();
            self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'), $path);
            self::assertSame(['error' => 'not_found'], $response->json(), $path);
        }

        foreach ([
            'PendingJobsController', 'CompletedJobsController', 'SilencedJobsController', 'FailedJobsController', 'JobsController',
            'RetryController', 'MonitoringController', 'JobMetricsController', 'QueueMetricsController',
            'FailureSelectionController', 'FailureOperationsController', 'LegacyBatchesController',
        ] as $controller) {
            self::assertFalse(class_exists('Justrau\\Orrery\\Http\\Controllers\\'.$controller), $controller.' still exists');
        }

        $this->actingAs(new User)->getJson('/orrery/api/jobs/'.$uuid)->assertNotFound()->assertJsonPath('error', 'not_found');
        $this->actingAs(new User)->getJson('/orrery/api/jobs?status=failed')->assertOk()->assertJsonStructure(['jobs']);
        $this->actingAs(new User)->getJson('/orrery/api/tags')->assertOk()->assertJsonStructure(['tags']);
        $this->actingAs(new User)->getJson('/orrery/api/batches')->assertOk();
    }

    /**
     * The legacy write routes are gone. No route
     * takes the write: a POST or DELETE answers 405 (the catch-all is a
     * GET route), and nothing is written.
     *
     * Rejects: a legacy write route left in place (it answers 200 with a
     * retry or forget result, or changes the monitored tags).
     */
    public function test_the_removed_legacy_writes_take_no_request(): void
    {
        $uuid = '00000000-0000-4000-8000-000000000001';

        foreach ([
            ['POST', '/orrery/api/jobs/retry/'.$uuid],
            ['POST', '/orrery/api/batches/retry/b-1'],
            ['POST', '/orrery/api/failures/selection'],
            ['POST', '/orrery/api/failures/retry'],
            ['POST', '/orrery/api/failures/forget'],
            ['POST', '/orrery/api/monitoring'],
            ['DELETE', '/orrery/api/monitoring/orders'],
        ] as [$method, $path]) {
            $status = $this->actingAs(new User)->json($method, $path, ['tag' => 'orders'])->getStatusCode();
            self::assertContains($status, [404, 405], "{$method} {$path}");
        }

        self::assertSame(0, $this->mirror()->table('orrery_monitored_tags')->count(), 'a removed route monitored a tag');
        self::assertSame(0, $this->mirror()->table('orrery_operations')->count(), 'a removed route started an operation');
    }
}
