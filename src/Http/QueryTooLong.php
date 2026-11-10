<?php

declare(strict_types=1);

namespace Justrau\Orrery\Http;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A query string with at least max_input_vars `&`-separated pairs
 * (RawQuery::of()). PHP's parser keeps only the first max_input_vars
 * variables, so a later filter (`queue=critical` after 1,000 other pairs)
 * would be ignored silently and the list would show every queue. The routes
 * refuse such a request with 422 `query_too_long` instead.
 */
final class QueryTooLong extends RuntimeException
{
    public const CODE = 'query_too_long';

    public function __construct(public readonly int $pairs, public readonly int $limit)
    {
        parent::__construct("The query string has {$pairs} parameters, at or above max_input_vars ({$limit}).");
    }

    /**
     * The 422 answer: `{error, message, limit}`.
     */
    public function response(): JsonResponse
    {
        return new JsonResponse([
            'error' => self::CODE,
            'message' => 'The query string has too many parameters to be read in full. Shorten it.',
            'limit' => $this->limit,
        ], 422, [], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
