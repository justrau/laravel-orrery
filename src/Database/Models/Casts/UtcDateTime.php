<?php

namespace Justrau\Orrery\Database\Models\Casts;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A `dateTime` column that holds UTC (orrery_signals.expires_at).
 * Laravel's `datetime` cast writes and reads the application's time
 * zone, and in a zone with daylight saving time one hour of local times
 * repeats each autumn: an expiry stored there reads back as another time,
 * and a string comparison of two such times can be wrong. In UTC the
 * stored strings follow the epoch, so the tick can compare them in SQL.
 *
 * @implements CastsAttributes<Carbon, DateTimeInterface|int|float|string>
 */
final class UtcDateTime implements CastsAttributes
{
    public const FORMAT = 'Y-m-d H:i:s';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $parsed = Carbon::createFromFormat(self::FORMAT, substr($value, 0, 19), 'UTC');

        return $parsed instanceof Carbon ? $parsed : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return self::format($value);
    }

    /**
     * A time as the stored UTC string; null for null.
     */
    public static function format(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof DateTimeInterface => Carbon::instance($value)->setTimezone('UTC')->format(self::FORMAT),
            is_int($value), is_float($value) => Carbon::createFromTimestamp($value, 'UTC')->format(self::FORMAT),
            default => Carbon::parse((string) $value, 'UTC')->setTimezone('UTC')->format(self::FORMAT),
        };
    }
}
