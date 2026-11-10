<?php

namespace Justrau\Orrery\Observation;

/**
 * The publication generation `orreryGen`: one random 128-bit
 * id per publication, as 32 lower-case hex characters.
 */
final class Generation
{
    public static function new(): string
    {
        return bin2hex(random_bytes(16));
    }
}
