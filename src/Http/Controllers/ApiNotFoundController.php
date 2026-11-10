<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * An unknown path under /api: a JSON 404, not the SPA's HTML page, so an API
 * client never reads a missing endpoint as a success. It sits behind the same
 * middleware as every API route. The body is the API's not-found shape.
 */
class ApiNotFoundController extends Controller
{
    public function show(): JsonResponse
    {
        return new JsonResponse(['error' => 'not_found'], 404);
    }
}
