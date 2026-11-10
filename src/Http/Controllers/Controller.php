<?php

namespace Justrau\Orrery\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Justrau\Orrery\Http\Lists\JobList;
use Justrau\Orrery\Http\Middleware\Authenticate;
use Justrau\Orrery\Runtime\Watcher;

class Controller extends BaseController
{
    public function __construct()
    {
        $this->middleware(Authenticate::class);
    }

    /**
     * The JSON 404 of an unknown job, failed job or batch.
     */
    protected function notFound(): JsonResponse
    {
        return new JsonResponse(['message' => 'Not found.'], 404);
    }

    /**
     * Runs a read of the package storage inside the watcher, which reports a
     * failure without its job values and never rethrows it: the read's value,
     * or a JSON 503 (`read_timeout` past the statement timeout, else
     * `read_failed`) whose message names $what ("The throughput").
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T|JsonResponse
     */
    protected static function watchedRead(string $operation, Closure $read, string $what): mixed
    {
        $result = Watcher::instance()->attempt($operation, $read);

        if ($result->succeeded()) {
            /** @var T */
            return $result->value();
        }

        $error = $result->error();
        $timeout = $error !== null && JobList::isTimeout($error);

        return new JsonResponse([
            'error' => $timeout ? 'read_timeout' : 'read_failed',
            'message' => $timeout ? "{$what} could not be read in time." : "{$what} could not be read.",
        ], 503, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * A query value as a string, or null when it is absent or not a string
     * (for example an array from `name[]=x`): such a value is treated as
     * absent, never cast.
     */
    protected static function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }

    /**
     * A query value as the request carries it: a string, an array (from
     * `name[]=x`) or null. Typed mixed, because Laravel 12 documents
     * query() as string|null, while an array is possible.
     */
    protected static function rawQuery(Request $request, string $key): mixed
    {
        return $request->query($key);
    }
}
