<?php

namespace Justrau\Orrery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Justrau\Orrery\Contracts\WorkloadRepository;

class WorkloadController extends Controller
{
    /**
     * The workload rows, sorted by queue name. Times and rates are numbers
     * (a whole float keeps its fraction, for example 1.0). The read runs in
     * the watcher: a part that fails alone gives null fields (the
     * repository), and a failure of the whole read (the live workers, the
     * measurement) a JSON 503.
     */
    public function index(WorkloadRepository $workload): JsonResponse
    {
        $rows = self::watchedRead('http:workload', static fn (): array => $workload->get(), 'The workload');

        if ($rows instanceof JsonResponse) {
            return $rows;
        }

        return new JsonResponse(
            collect($rows)->sortBy('name')->values()->all(),
            200,
            [],
            JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
}
