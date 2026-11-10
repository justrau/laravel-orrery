<?php

namespace Justrau\Orrery\Http\Projection;

use RuntimeException;

/**
 * The serialized text is not a value that the parser supports: another
 * token, a length mismatch, trailing bytes, an integer outside the signed
 * 64-bit range. The message names the step only, never an input byte.
 */
final class UnsupportedValue extends RuntimeException
{
    public function __construct(string $step)
    {
        parent::__construct("The serialized value is not supported ({$step}).");
    }
}
