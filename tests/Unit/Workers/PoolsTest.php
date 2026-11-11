<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Workers;

use Illuminate\Config\Repository;
use Justrau\Orrery\Workers\Pool;
use Justrau\Orrery\Workers\Pools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PoolsTest extends TestCase
{
    private const VALID = ['connection' => 'database', 'queues' => ['high', 'default'], 'processes' => 2];

    /**
     * Rejects: a reader that drops the declared order of queues or options, or that fills in options the pool
     * did not set (Laravel's defaults must apply).
     */
    public function test_a_valid_pool_keeps_its_queues_and_only_its_set_options(): void
    {
        $read = Pools::read(self::config(['default' => self::VALID + ['timeout' => 90, 'tries' => 3, 'force' => false]]));

        self::assertSame([], $read['errors']);
        self::assertCount(1, $read['pools']);
        $pool = $read['pools'][0];
        self::assertSame(['default', 'database', ['high', 'default'], 2, ['tries' => 3, 'timeout' => 90, 'force' => false]], [$pool->name, $pool->connection, $pool->queues, $pool->processes, $pool->options]);
        self::assertSame(['queue:work', 'database', '--queue=high,default', '--name=default', '--tries=3', '--timeout=90'], $pool->arguments());
    }

    public function test_no_pools_and_a_missing_key_are_valid(): void
    {
        self::assertSame(['pools' => [], 'errors' => []], Pools::read(self::config([])));
        self::assertSame(['pools' => [], 'errors' => []], Pools::read(new Repository(['orrery' => ['workers' => ['heartbeat_seconds' => 15]], 'queue' => []])));
    }

    /**
     * Rejects: a validator that accepts this value, or whose error does not name the pool and the field.
     * Still passes: an error whose wording after the field name changes.
     *
     * @param  array<array-key, mixed>  $pools
     */
    #[DataProvider('invalid')]
    public function test_an_invalid_pool_is_an_error_naming_its_pool_and_field(mixed $pools, string $field): void
    {
        $read = Pools::read(self::config($pools));

        self::assertSame([], $read['pools']);
        self::assertNotEmpty($read['errors']);
        self::assertStringStartsWith($field.': ', $read['errors'][0], implode("\n", $read['errors']));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalid(): array
    {
        $key = 'orrery.workers.pools';

        return [
            'pools not an array' => ['nope', $key],
            'a list instead of names' => [[self::VALID], "{$key}.0"],
            'an uppercase name' => [['Default' => self::VALID], "{$key}.Default"],
            'a name with a space' => [['my pool' => self::VALID], "{$key}.my pool"],
            'a pool that is no array' => [['default' => 'database'], "{$key}.default"],
            'an unknown key' => [['default' => self::VALID + ['queue' => 'x']], "{$key}.default.queue"],
            'a missing connection' => [['default' => ['queues' => ['a'], 'processes' => 1]], "{$key}.default.connection"],
            'a missing queues' => [['default' => ['connection' => 'database', 'processes' => 1]], "{$key}.default.queues"],
            'a missing processes' => [['default' => ['connection' => 'database', 'queues' => ['a']]], "{$key}.default.processes"],
            'an unknown connection' => [['default' => ['connection' => 'nope'] + self::VALID], "{$key}.default.connection"],
            'a redis connection' => [['default' => ['connection' => 'redis'] + self::VALID], "{$key}.default.connection"],
            'an empty queue list' => [['default' => ['queues' => []] + self::VALID], "{$key}.default.queues"],
            'queues as a string' => [['default' => ['queues' => 'default'] + self::VALID], "{$key}.default.queues"],
            'queues keyed' => [['default' => ['queues' => ['a' => 'default']] + self::VALID], "{$key}.default.queues"],
            'an empty queue name' => [['default' => ['queues' => ['']] + self::VALID], "{$key}.default.queues"],
            'a queue with a comma' => [['default' => ['queues' => ['a,b']] + self::VALID], "{$key}.default.queues"],
            'a queue with a newline' => [['default' => ['queues' => ["a\nb"]] + self::VALID], "{$key}.default.queues"],
            'a queue twice' => [['default' => ['queues' => ['a', 'a']] + self::VALID], "{$key}.default.queues"],
            'zero processes' => [['default' => ['processes' => 0] + self::VALID], "{$key}.default.processes"],
            'processes as a string' => [['default' => ['processes' => '2'] + self::VALID], "{$key}.default.processes"],
            'negative tries' => [['default' => self::VALID + ['tries' => -1]], "{$key}.default.tries"],
            'timeout as a float' => [['default' => self::VALID + ['timeout' => 1.5]], "{$key}.default.timeout"],
            'sleep negative' => [['default' => self::VALID + ['sleep' => -0.5]], "{$key}.default.sleep"],
            'rest as a string' => [['default' => self::VALID + ['rest' => '1']], "{$key}.default.rest"],
            'backoff with a string' => [['default' => self::VALID + ['backoff' => [1, '5']]], "{$key}.default.backoff"],
            'backoff empty' => [['default' => self::VALID + ['backoff' => []]], "{$key}.default.backoff"],
            'memory zero' => [['default' => self::VALID + ['memory' => 0]], "{$key}.default.memory"],
            'max_jobs as a bool' => [['default' => self::VALID + ['max_jobs' => true]], "{$key}.default.max_jobs"],
            'max_time negative' => [['default' => self::VALID + ['max_time' => -5]], "{$key}.default.max_time"],
            'force as an int' => [['default' => self::VALID + ['force' => 1]], "{$key}.default.force"],
        ];
    }

    /**
     * Rejects: a reader that stops at the first invalid pool, or that returns a pool with an error.
     */
    public function test_each_invalid_pool_is_reported_and_the_valid_ones_are_kept(): void
    {
        $read = Pools::read(self::config([
            'bad' => ['processes' => 0] + self::VALID,
            'good' => self::VALID,
            'worse' => ['connection' => 'redis'] + self::VALID,
        ]));

        self::assertSame(['good'], array_map(static fn (Pool $pool): string => $pool->name, $read['pools']));
        self::assertCount(2, $read['errors']);
    }

    private static function config(mixed $pools): Repository
    {
        return new Repository([
            'orrery' => ['workers' => ['pools' => $pools]],
            'queue' => ['connections' => [
                'database' => ['driver' => 'database', 'table' => 'jobs'],
                'redis' => ['driver' => 'redis'],
            ]],
        ]);
    }
}
