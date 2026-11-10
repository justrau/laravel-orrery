<?php

namespace Justrau\Orrery\Storage;

use Illuminate\Database\Query\Expression;
use InvalidArgumentException;

/**
 * The only place of the package that asserts a literal-string for raw SQL.
 *
 * Laravel 13 declares the value of an Expression as a literal-string, so that
 * static analysis finds raw SQL built from input. Some package expressions
 * contain a runtime integer or a value that the connection escaped, for
 * example `COALESCE(cycle_first_job_id, 42)`. PHPStan never treats such a
 * string as a literal, so no honest type describes it.
 *
 * So the API accepts only parts that are safe by construction:
 *  - $template is a literal-string: PHPStan checks this at every call site.
 *    Its only placeholders are `%s` and `%N$s`;
 *  - every other part is an int, which this class formats itself, or an
 *    EscapedValue, which only Connection::escape() makes.
 * The built string therefore holds only literal SQL, integers and values that
 * the connection escaped, and the one `@var literal-string` below is true.
 */
final class TrustedSql
{
    /**
     * @param  literal-string  $template  SQL whose only placeholders are `%s` and `%N$s`
     * @return Expression<literal-string>
     *
     * @throws InvalidArgumentException when the template has another `%` sequence
     */
    public static function expression(string $template, int|EscapedValue ...$parts): Expression
    {
        if (str_contains((string) preg_replace('/%(?:[1-9][0-9]*\$)?s/', '', $template), '%')) {
            throw new InvalidArgumentException("The SQL template [{$template}] may contain only the placeholders %s and %N\$s.");
        }

        $fragments = array_map(
            static fn (int|EscapedValue $part): string => $part instanceof EscapedValue ? $part->sql : (string) $part,
            array_values($parts),
        );

        /** @var literal-string $sql A literal template with integers and connection-escaped values only (see the class comment). */
        $sql = vsprintf($template, $fragments);

        return new Expression($sql);
    }
}
