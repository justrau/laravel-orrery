<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\TestCase;

/**
 * The storage contract of Clock: a time bound through
 * Clock::toDecimal() keeps its microseconds in a decimal(16,6) column on
 * every engine, whatever PHP's precision setting is.
 */
#[FreshSchema]
final class ClockStorageTest extends TestCase
{
    private const TABLE = 'clock_scratch';

    /**
     * Rejects: binding the float itself, which PHP's precision setting cuts to 14 digits.
     */
    public function test_decimal_times_round_trip_to_the_microsecond(): void
    {
        $connection = $this->mirror();
        $schema = $connection->getSchemaBuilder();
        $schema->dropIfExists(self::TABLE);
        $schema->create(self::TABLE, static function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->decimal('at', 16, 6);
        });
        $this->beforeApplicationDestroyed(static fn () => $connection->getSchemaBuilder()->dropIfExists(self::TABLE));

        // Ten-digit epoch values; the first two are one microsecond apart.
        $times = [1 => 1789999999.123456, 2 => 1789999999.123457, 3 => 1790000000.000001];
        $precision = (string) ini_get('precision');
        ini_set('precision', '14');

        try {
            foreach ($times as $id => $time) {
                $connection->table(self::TABLE)->insert(['id' => $id, 'at' => Clock::toDecimal($time)]);
            }

            foreach ($times as $id => $time) {
                $stored = $connection->table(self::TABLE)->where('id', $id)->value('at');

                // MySQL and Postgres return the decimal as a string, SQLite as a float.
                if ($this->testEnvironment()->isServer()) {
                    self::assertSame(Clock::toDecimal($time), $stored);
                }

                self::assertSame(Clock::toDecimal($time), Clock::toDecimal((float) $stored), "The time of row {$id} keeps its microseconds.");
                self::assertSame([$id], $this->ids($connection->table(self::TABLE)->where('at', Clock::toDecimal($time))), "An equality predicate finds only row {$id}.");
            }

            self::assertSame([1], $this->ids($connection->table(self::TABLE)->where('at', '<', Clock::toDecimal($times[2]))), 'A cutoff one microsecond later separates the first two rows.');
        } finally {
            ini_set('precision', $precision);
        }
    }

    /**
     * @return list<int>
     */
    private function ids(Builder $query): array
    {
        return array_map('intval', $query->orderBy('id')->pluck('id')->all());
    }
}
