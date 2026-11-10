<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use ReflectionMethod;

/**
 * Whether the running Laravel parses `x::direct` as a PDO alias of `x`.
 * Laravel 13 does (the floor 13.30.0 too), and 12.69.0 does not. The check
 * reads the type part of the manager's own parse, which the package's
 * ConnectionName does not use, so a later 12.x that adds the alias is read
 * correctly.
 */
final class DirectAlias
{
    public static function supported(DatabaseManager $db): bool
    {
        $parsed = (new ReflectionMethod($db, 'parseConnectionName'))->invoke($db, 'x::direct');

        return is_array($parsed) && ($parsed[1] ?? null) === 'direct';
    }

    public static function unsupportedReason(): string
    {
        return 'This Laravel version ('.Application::VERSION.') reads "::direct" as a part of the connection name: its parser has no direct alias.';
    }

    public static function supportedReason(): string
    {
        return 'This Laravel version ('.Application::VERSION.') parses "::direct" as an alias, so no literal connection name has this suffix.';
    }
}
