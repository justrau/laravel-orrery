<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Justrau\Orrery\Http\QueryTooLong;
use Justrau\Orrery\Tests\ControllerTest;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every route that reads the raw query string (RawQuery): a query with at
 * least max_input_vars pairs is refused with 422 `query_too_long`, because
 * PHP's parser would keep only the first max_input_vars variables and a
 * later filter would be ignored silently (a jobs list for `queue=critical`
 * would show every queue).
 *
 * The refused query has exactly max_input_vars pairs: PHP keeps all of them
 * and warns on none, so the framework's own reads of the query string pass
 * on every symfony/http-foundation release, and only the package's check
 * answers.
 */
final class RawQueryRoutesTest extends ControllerTest
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function routes(): array
    {
        return [
            'the jobs list' => ['GET', '/orrery/api/jobs'],
            'the stuck list' => ['GET', '/orrery/api/jobs/stuck'],
            'the batches' => ['GET', '/orrery/api/batches'],
            'the tags' => ['GET', '/orrery/api/tags'],
            'a tag unmonitor by query' => ['DELETE', '/orrery/api/tags/monitored'],
            'the job classes' => ['GET', '/orrery/api/classes'],
            'the job class series' => ['GET', '/orrery/api/classes/series'],
        ];
    }

    /**
     * Rejects: a route that parses a query cut at max_input_vars, so the
     * filter after the cut (`queue=critical`, a later `tag`) is ignored, or
     * that answers it with a 500 or a 200.
     */
    #[DataProvider('routes')]
    public function test_a_query_of_max_input_vars_pairs_is_refused(string $method, string $path): void
    {
        Exceptions::fake();
        $limit = (int) ini_get('max_input_vars');
        $response = $this->send($method, $path, 'status=failed&'.str_repeat('a=1&', $limit - 2).'queue=critical');

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['error' => QueryTooLong::CODE, 'message' => 'The query string has too many parameters to be read in full. Shorten it.', 'limit' => $limit], json_decode((string) $response->getContent(), true));
        Exceptions::assertNothingReported();
    }

    /**
     * Rejects: a limit check that refuses a query below max_input_vars (one
     * pair less), which PHP reads in full, or that counts the empty pieces
     * of `&&`.
     */
    #[DataProvider('routes')]
    public function test_a_query_below_max_input_vars_is_read(string $method, string $path): void
    {
        $limit = (int) ini_get('max_input_vars');
        $response = $this->send($method, $path, 'status=failed&&'.str_repeat('a=1&', $limit - 3).'&queue=critical');

        self::assertNotSame(QueryTooLong::CODE, json_decode((string) $response->getContent(), true)['error'] ?? null, (string) $response->getContent());
        self::assertNotSame(500, $response->getStatusCode(), (string) $response->getContent());
    }

    private function send(string $method, string $path, string $query): Response
    {
        // The raw query string only: Request::create() would parse a query in its URI itself.
        $request = Request::create($path, $method);
        $request->server->set('QUERY_STRING', $query);
        // The SPA's header of every write (Authenticate).
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        return $this->app->make(Kernel::class)->handle($request);
    }
}
