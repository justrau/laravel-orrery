<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Storage;

use Justrau\Orrery\Storage\EndpointIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The endpoint keys of EndpointIdentity:
 * the server that a configuration reaches is part of the
 * hash, so a record with the same id and uuid on another server is another
 * target (ReachableTargetTest plants one on the alt server).
 */
final class EndpointIdentityTest extends TestCase
{
    private const MYSQL = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'database' => 'app', 'username' => 'root', 'prefix' => ''];

    private const PGSQL = ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432, 'database' => 'app', 'username' => 'postgres', 'prefix' => '', 'search_path' => 'public'];

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function endpointChanges(): array
    {
        return [
            'the host' => [self::MYSQL, ['host' => '10.0.0.2']],
            'the port' => [self::MYSQL, ['port' => 3307]],
            'the unix socket' => [self::MYSQL, ['unix_socket' => '/tmp/mysql.sock']],
            'another unix socket' => [self::MYSQL + ['unix_socket' => '/tmp/a.sock'], ['unix_socket' => '/tmp/b.sock']],
            'connect_via_port' => [self::PGSQL, ['connect_via_port' => 6432]],
            'the Postgres port' => [self::PGSQL, ['port' => 5433]],
        ];
    }

    /**
     * Rejects: an identity without the host, the port, the MySQL socket or
     * Postgres's connect_via_port: each change reaches another server, so
     * the hash changes.
     * Still passes: an identity that hashes every configuration key (the
     * next test rejects that for the keys that do not move the endpoint).
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('endpointChanges')]
    public function test_each_endpoint_key_changes_the_hash(array $base, array $change): void
    {
        self::assertNotSame(EndpointIdentity::of($base)->hash(), EndpointIdentity::of(array_replace($base, $change))->hash());
    }

    /**
     * Rejects: a hash over every key: the order of a host list (Laravel
     * shuffles it), and a key that no connector reads (for example a pool
     * size), leave the endpoint the same.
     */
    public function test_keys_that_do_not_move_the_endpoint_keep_the_hash(): void
    {
        $hosts = array_replace(self::MYSQL, ['host' => ['10.0.0.1', '10.0.0.2']]);

        self::assertSame(EndpointIdentity::of($hosts)->hash(), EndpointIdentity::of(array_replace($hosts, ['host' => ['10.0.0.2', '10.0.0.1']]))->hash());
        self::assertSame(EndpointIdentity::of(self::MYSQL)->hash(), EndpointIdentity::of(self::MYSQL + ['pool' => 5])->hash());
        // A socket takes the place of the host: then the host does not count.
        $socket = self::MYSQL + ['unix_socket' => '/tmp/mysql.sock'];
        self::assertSame(EndpointIdentity::of($socket)->hash(), EndpointIdentity::of(array_replace($socket, ['host' => '10.0.0.9']))->hash());
    }
}
