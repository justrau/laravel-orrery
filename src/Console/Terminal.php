<?php

namespace Justrau\Orrery\Console;

/**
 * Whether a destructive bulk command may ask its confirmation: only when
 * STDIN is a terminal. A piped STDIN (`yes | php artisan orrery:forget
 * --all`) could answer it, so `orrery:forget --all` and `orrery:retry --all`
 * then act on nothing without `--force`. Resolved from the container, so a
 * test can bind its answer (STDIN of a test process may or may not be a
 * terminal).
 */
class Terminal
{
    public function stdinIsTerminal(): bool
    {
        return self::isTerminal(defined('STDIN') ? STDIN : null);
    }

    /**
     * Whether $stream is an open stream that is a terminal.
     */
    public static function isTerminal(mixed $stream): bool
    {
        try {
            return is_resource($stream) && stream_isatty($stream);
        } catch (\Throwable) {
            return false;
        }
    }
}
