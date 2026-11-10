<?php

namespace Justrau\Orrery\Database\Schema;

use Illuminate\Container\Container;
use RuntimeException;
use Throwable;

/**
 * A package table lacks an object of a later schema step:
 * new code runs before `php artisan
 * migrate`. The message starts with the code `schema_outdated`, names the
 * missing object, and ends with `php artisan migrate` and a pointer to
 * `/api/health` `schema`, which gives the exact steps for the installed
 * marker. It never names the destructive pre-v0.1
 * path (PackageSchema::UPGRADE_STEPS), and it holds no row data.
 */
final class SchemaOutdated extends RuntimeException
{
    public const CODE = 'schema_outdated';

    public static function table(string $table): self
    {
        return new self(self::CODE.": the table {$table} is missing. ".self::hint());
    }

    public static function column(string $table, string $column): self
    {
        return new self(self::CODE.": {$table}.{$column} is missing. ".self::hint());
    }

    /** The end of every message, with the dashboard path of config('orrery.path'). */
    public static function hint(): string
    {
        try {
            $path = Container::getInstance()->make('config')->get('orrery.path');
        } catch (Throwable) {
            $path = null;
        }

        $path = trim(is_string($path) ? $path : 'orrery', '/');
        $health = $path === '' ? '/api/health' : "/{$path}/api/health";

        return "Run php artisan migrate; GET {$health} (`schema`) gives the exact steps.";
    }
}
