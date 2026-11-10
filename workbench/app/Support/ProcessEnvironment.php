<?php

namespace Workbench\App\Support;

use Illuminate\Support\Env;

/**
 * Reads a Workbench switch from the process environment, as the raw string.
 *
 * The web server child of `serve` (PHP's built-in server) inherits the variables of the
 * launcher, but Laravel's Env repository does not see them there: it reads $_SERVER and $_ENV,
 * which the built-in server leaves without the process environment. getenv() reads them.
 */
final class ProcessEnvironment
{
    public static function get(string $name): ?string
    {
        // The raw value: Env::get() turns `false`, `(false)`, `empty`, `(empty)`, `null` and
        // `(null)` into false, '' or null, so a mistyped switch would select its default.
        $value = Env::getRepository()->get($name);

        if ($value === null) {
            $process = getenv($name);
            $value = $process === false ? null : $process;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
