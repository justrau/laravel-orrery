<?php

declare(strict_types=1);

namespace Justrau\Orrery\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * The raw query string of a request, parsed as sent: a host middleware
 * (TrimStrings, ConvertEmptyStringsToNull) cannot change a value, an array
 * value (`tag[]=x`) stays an array, and dots in names stay.
 *
 * HeaderUtils::parseQuery() calls parse_str(), which warns past
 * max_input_vars and max_input_nesting_level, and Laravel turns the warning
 * into an ErrorException: a 500. symfony/http-foundation suppresses the
 * warning only since its releases of 2026-10 (symfony/symfony 2490eb5);
 * the floor of laravel/framework ^13.30.0 allows 7.4.0, which does not.
 *
 * So a query with at least max_input_vars pairs is refused (QueryTooLong):
 * the parse would drop the pairs past the limit, so a later filter would be
 * ignored. The parse itself runs with the warning suppressed, so a key
 * nested past max_input_nesting_level never gives a 500. (On a symfony
 * release before 2026-10 the framework's global HandleCors middleware
 * already fails on a query past max_input_vars, through
 * Request::getQueryString(), before the package runs;
 * JobClassesControllerTest asserts that path.)
 */
final class RawQuery
{
    /**
     * @return array<array-key, mixed>
     *
     * @throws QueryTooLong  when the query has at least max_input_vars pairs: the parse would drop the pairs past the limit
     */
    public static function of(Request $request): array
    {
        $raw = $request->server->get('QUERY_STRING', '');
        $raw = is_string($raw) ? $raw : '';
        $limit = (int) ini_get('max_input_vars');

        if ($limit > 0) {
            $pairs = count(array_filter(explode('&', $raw), static fn (string $pair): bool => $pair !== ''));

            if ($pairs >= $limit) {
                throw new QueryTooLong($pairs, $limit);
            }
        }

        return @HeaderUtils::parseQuery($raw);
    }
}
