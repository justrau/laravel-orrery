<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Http;

use ErrorException;
use Illuminate\Http\Request;
use Justrau\Orrery\Http\QueryTooLong;
use Justrau\Orrery\Http\RawQuery;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class RawQueryTest extends TestCase
{
    /**
     * Rejects: a parse that lets parse_str() warn past
     * max_input_nesting_level. Laravel's error handler turns that warning
     * into an ErrorException, a 500. Only the lowest matrix legs reject a
     * removed `@`: symfony/http-foundation 7.4.0 (which the floor
     * laravel/framework ^13.30.0 allows) warns, while its releases since
     * 2026-10 suppress the warning themselves, so on them this test passes
     * without the `@` too.
     * Still passes: a reader that drops the nested key (PHP's own parsing
     * drops it as well: the test asserts no value of it).
     */
    public function test_a_query_past_the_nesting_limit_parses_without_a_warning(): void
    {
        $nested = 'deep'.str_repeat('[x]', (int) ini_get('max_input_nesting_level') + 1).'=1';
        $request = Request::create('/orrery/api/jobs');
        $request->server->set('QUERY_STRING', 'name=first&'.$nested.'&tail=x');

        // parse_str() warns about the nesting only while display_errors is off, as in production.
        $display = ini_set('display_errors', '0');
        $reporting = error_reporting(-1);
        set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0): bool {
            // As Laravel's HandleExceptions: a reported warning becomes an exception.
            if ((error_reporting() & $level) !== 0) {
                throw new ErrorException($message, 0, $level, $file, $line);
            }

            return true;
        });

        try {
            $query = RawQuery::of($request);
        } finally {
            restore_error_handler();
            error_reporting($reporting);
            ini_set('display_errors', (string) $display);
        }

        self::assertSame('first', $query['name'] ?? null);
        self::assertSame('x', $query['tail'] ?? null);
    }

    /**
     * Rejects: a parse that cuts a query at max_input_vars silently (the
     * filters after the cut would be ignored), and a refusal that counts the
     * empty pieces of `&&` or refuses one pair below the limit.
     */
    public function test_a_query_of_max_input_vars_pairs_is_refused(): void
    {
        $limit = (int) ini_get('max_input_vars');
        $request = Request::create('/orrery/api/jobs');
        $request->server->set('QUERY_STRING', 'a=1&&'.str_repeat('b=1&', $limit - 3).'&last=x');

        self::assertSame('x', RawQuery::of($request)['last'] ?? null, 'one pair below the limit is read in full');

        $request->server->set('QUERY_STRING', 'a=1&'.str_repeat('b=1&', $limit - 1).'last=x');

        try {
            RawQuery::of($request);
            self::fail('A query of max_input_vars pairs was parsed.');
        } catch (QueryTooLong $tooLong) {
            self::assertSame([$limit + 1, $limit], [$tooLong->pairs, $tooLong->limit]);
        }
    }

    /**
     * Rejects: the raw value changed on the way (a trimmed or nulled value),
     * an array value flattened, a dot in a name turned into an underscore, or
     * a QUERY_STRING that is not a string read as one.
     */
    public function test_the_values_stay_as_sent(): void
    {
        $request = Request::create('/orrery/api/tags');
        $request->server->set('QUERY_STRING', 'tag%5B%5D=a&tag%5B%5D=b&q=%20x%20&e=&a.b=1');

        self::assertSame(['tag' => ['a', 'b'], 'q' => ' x ', 'e' => '', 'a.b' => '1'], RawQuery::of($request));

        $request->server->set('QUERY_STRING', ['not' => 'a string']);
        self::assertSame([], RawQuery::of($request));
    }

    /**
     * The parsed request reads that stay in the controllers, each with its
     * reason. Every other read goes through RawQuery::of() or the raw JSON
     * body, because a host middleware (TrimStrings,
     * ConvertEmptyStringsToNull) changes the parsed values:
     * - Controller: queryString() and rawQuery(), the helpers of the reads
     *   below;
     * - ControlController: the form body of a request without a JSON body;
     * - HealthController `from` and `to`, JobClassesController,
     *   QueueHistoryController and ThroughputController `minutes`,
     *   JobController `cycle`: numbers, which TrimStrings cannot change into
     *   another valid value; JobClassesController refuses a query of
     *   max_input_vars pairs first (tooLong());
     * - JobController `cursor`: a base64 token without edge whitespace.
     */
    private const PARSED_READS = [
        'Http/Controllers/ControlController.php' => ['$request->all('],
        'Http/Controllers/Controller.php' => ['$request->query(', '$request->query('],
        'Http/Controllers/HealthController.php' => ['$request->query(', '$request->query('],
        'Http/Controllers/JobClassesController.php' => ['$request->query(', '$request->query('],
        'Http/Controllers/JobController.php' => ['$request->query(', '$request->query('],
        'Http/Controllers/QueueHistoryController.php' => ['$request->query('],
        'Http/Controllers/ThroughputController.php' => ['$request->query('],
    ];

    /**
     * Rejects: a controller that reads the parsed request (its
     * getQueryString(), fullUrl(), query(), query->all(), input(), all(),
     * validate()), which TrimStrings and max_input_vars change, beyond
     * PARSED_READS, by any form that parsedReads() finds. Every controller
     * is scanned, so a new one is too.
     */
    public function test_the_controllers_read_no_other_parsed_request_value(): void
    {
        $root = dirname(__DIR__, 3).'/src/';
        $found = [];

        foreach (glob($root.'Http/Controllers/*.php') ?: [] as $path) {
            $file = substr($path, strlen($root));
            $code = '';

            foreach (token_get_all((string) file_get_contents($path)) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= is_array($token) ? $token[1] : $token;
            }

            $reads = self::parsedReads($code);

            if ($reads !== []) {
                $found[$file] = $reads;
            }
        }

        ksort($found);
        $expected = self::PARSED_READS;
        ksort($expected);
        self::assertGreaterThan(20, count(glob($root.'Http/Controllers/*.php') ?: []), 'The scan reads the controllers.');
        self::assertSame($expected, $found);
    }

    /**
     * Rejects: a scan that sees only the literal `$request->`, so a read
     * through another parameter name, the request() helper, the Request
     * facade or a request property passes.
     * Still passes: a request passed on to a helper of another class, or
     * held in a variable that is not a typed parameter.
     */
    public function test_the_parsed_read_scan_finds_every_form(): void
    {
        $code = <<<'PHP'
            public function index(Request $request, \Illuminate\Http\Request $other): array
            {
                $a = $request->input('a');
                $b = $other->query('b');
                $c = request()->all();
                $d = Request::input('d');
                $e = $this->request->query->all();
                $f = $request->query->get('f');
                $g = $query->get();
                $h = $other->getContent();
            }
            PHP;

        self::assertSame(['$request->input(', '$other->query(', 'request()->all(', 'Request::input(', '$this->request->query->', '$request->query->'], self::parsedReads($code));
    }

    /**
     * The parsed request reads of a controller source without comments: by
     * the `$request` variable or any parameter typed Request, the request()
     * helper, a `$this->request` property, or the Request facade.
     *
     * @return list<string>
     */
    private static function parsedReads(string $code): array
    {
        preg_match_all('/\bRequest\s+&?\$(\w+)/', $code, $typed);
        $variables = array_values(array_unique(['request', ...$typed[1]]));
        $receiver = '(?:\$(?:'.implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), $variables)).')\b|\brequest\(\s*\)|\$this->request\b)\s*->\s*';
        $methods = '(?:getQueryString|fullUrl|fullUrlWithQuery|query|input|all|only|except|string|integer|boolean|float|date|enum|collect|has|filled|validate|validated|post|json|get)';

        preg_match_all('/'.$receiver.$methods.'\s*\(|'.$receiver.'(?:query|request|json)\s*->|(?<!\w)Request::'.$methods.'\s*\(/', $code, $matches);

        return array_map(static fn (string $match): string => (string) preg_replace('/\s+/', '', $match), $matches[0]);
    }

    /**
     * Rejects: a controller that parses the raw query string itself
     * (HeaderUtils::parseQuery() or parse_str()), so the warning past the
     * input limits reaches it again.
     */
    public function test_only_raw_query_parses_a_query_string_in_src(): void
    {
        $root = dirname(__DIR__, 3).'/src';
        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php' || $file->getPathname() === $root.'/Http/RawQuery.php') {
                continue;
            }

            if (preg_match('/HeaderUtils::parseQuery\s*\(|\bparse_str\s*\(/', (string) file_get_contents($file->getPathname())) === 1) {
                $found[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        self::assertSame([], $found);
    }
}
