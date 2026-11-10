<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Storage;

use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * orrery.sessions.pooled as it comes from the environment:
 * Laravel's env() leaves `1`, `on` or a list of names as a string, and each
 * of those must mean what it says. The test reads no server; isPooled()
 * decides from the configuration alone.
 */
final class PooledSettingTest extends TestCase
{
    /**
     * Rejects: a reader that accepts only the literal true or an array, so
     * ORRERY_SESSIONS_POOLED=1 or a comma-separated list of names silently
     * means "not pooled" and the package sends session SETs through the
     * pooler. Would still pass: a reader that also accepts other truthy
     * words than filter_var() does (no case here).
     *
     * @param  list<string>  $pooled  the connections that must be pooled; pg_a, pg_b and pg_c are asked
     */
    #[DataProvider('values')]
    public function test_the_setting_is_parsed(mixed $value, array $pooled): void
    {
        $this->app['config']->set('orrery.sessions.pooled', $value);
        $sessions = $this->app->make(PackageSession::class);

        $actual = array_values(array_filter(['pg_a', 'pg_b', 'pg_c'], static fn (string $name): bool => $sessions->isPooled($name, ['driver' => 'pgsql'])));

        self::assertSame($pooled, $actual);
        self::assertFalse($sessions->isPooled('pg_a', ['driver' => 'mysql']), 'Only Postgres is ever pooled.');
    }

    /**
     * @return array<string, array{0: mixed, 1: list<string>}>
     */
    public static function values(): array
    {
        $all = ['pg_a', 'pg_b', 'pg_c'];

        return [
            'true' => [true, $all],
            'false' => [false, []],
            'null' => [null, []],
            'the string 1' => ['1', $all],
            'the string true' => ['true', $all],
            'the string on' => ['On', $all],
            'the string yes' => ['yes', $all],
            'the string 0' => ['0', []],
            'the string false' => ['false', []],
            'the string off' => ['off', []],
            'an empty string' => ['', []],
            'one name' => ['pg_b', ['pg_b']],
            'a list of names' => [' pg_a , pg_c,', ['pg_a', 'pg_c']],
            'an array of names' => [['pg_a', 'pg_b'], ['pg_a', 'pg_b']],
        ];
    }
}
