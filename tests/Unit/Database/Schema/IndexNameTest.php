<?php

namespace Justrau\Orrery\Tests\Unit\Database\Schema;

use Illuminate\Database\Connection;
use Justrau\Orrery\Database\Schema\IndexName;
use PHPUnit\Framework\TestCase;

class IndexNameTest extends TestCase
{
    /**
     * Rejects: a hint with a name that Laravel's grammars refuse: a table prefix such as `my-app_`
     * installs, and then every forced list read threw ("Index name contains invalid characters"), so the job
     * lists, the stuck list and the bulk freeze answered 503 on MySQL, MariaDB and SQLite. Such a name gets no
     * hint; a valid one keeps it, and Postgres never gets one.
     * Still passes: a rule that drops the hint also for a valid name with `$` (none of the package's names has one).
     */
    public function test_a_hint_is_forced_only_with_a_name_the_grammar_takes(): void
    {
        $sql = static function (string $driver, string $prefix): string {
            $connection = match ($driver) {
                'sqlite' => new \Illuminate\Database\SQLiteConnection(static fn () => null, ':memory:', $prefix, ['driver' => 'sqlite']),
                'mysql' => new \Illuminate\Database\MySqlConnection(static fn () => null, 'app', $prefix, ['driver' => 'mysql']),
                default => new \Illuminate\Database\PostgresConnection(static fn () => null, 'app', $prefix, ['driver' => 'pgsql']),
            };

            return IndexName::force($connection->table('orrery_jobs'), $connection, 'orrery_jobs', 'st_finished')->toSql();
        };

        $this->assertStringContainsString('indexed by pfx_orrery_jobs_st_finished', $sql('sqlite', 'pfx_'));
        $this->assertStringContainsString('force index (pfx_orrery_jobs_st_finished)', $sql('mysql', 'pfx_'));
        $this->assertStringNotContainsString('index', $sql('pgsql', 'pfx_'));

        foreach (['sqlite', 'mysql'] as $driver) {
            $this->assertStringNotContainsString('index', $sql($driver, 'my-app_'), $driver);
        }
    }

    /**
     * Rejects: a name without the connection's table prefix, which collides between two prefixed installations in one database.
     */
    public function test_the_name_is_the_prefix_the_table_and_the_suffix(): void
    {
        $connection = new Connection(static fn () => null, '', 'pfx_');

        $this->assertSame('pfx_orrery_jobs_pushed', IndexName::for($connection, 'orrery_jobs', 'pushed'));
        $this->assertSame('orrery_jobs_pushed', IndexName::compose('', 'orrery_jobs', 'pushed'));
    }

    /**
     * Rejects: a name that grows past the Postgres limit of 63 characters, which Postgres cuts silently.
     */
    public function test_a_long_prefix_gives_a_bounded_deterministic_and_distinct_name(): void
    {
        $prefix = str_repeat('p', 40);

        $first = IndexName::compose($prefix, 'orrery_worker_heartbeats', 'host_ppid');
        $second = IndexName::compose($prefix, 'orrery_worker_heartbeats', 'seen');

        $this->assertSame(IndexName::MAX_LENGTH, strlen($first));
        $this->assertSame($first, IndexName::compose($prefix, 'orrery_worker_heartbeats', 'host_ppid'));
        $this->assertNotSame($first, $second);
        $this->assertStringStartsWith(substr($prefix.'orrery_worker_heartbeats_host_ppid', 0, 50).'_', $first);
    }

    /**
     * Rejects: a hash for every name, which hides the table and the key in the catalog.
     */
    public function test_a_name_within_the_limit_is_not_changed(): void
    {
        $name = IndexName::compose(str_repeat('p', 16), 'orrery_operations', 'claim_ref');

        $this->assertSame(str_repeat('p', 16).'orrery_operations_claim_ref', $name);
    }
    /**
     * Rejects: a byte cut inside a multibyte character, which gives an invalid UTF-8 identifier that the server refuses.
     */
    public function test_a_long_unicode_table_name_is_cut_on_a_character_boundary(): void
    {
        // 18 characters of 3 bytes each: 54 bytes, so byte 50 is inside the 17th character.
        $table = str_repeat('日', 18);
        $full = $table.'_orrery_backlog';

        $name = IndexName::compose('', $table, 'orrery_backlog');

        $this->assertTrue(mb_check_encoding($name, 'UTF-8'), 'The name is valid UTF-8.');
        $this->assertLessThanOrEqual(IndexName::MAX_LENGTH, strlen($name));
        $this->assertSame(str_repeat('日', 16).'_'.substr(hash('sha256', $full), 0, 12), $name);
        $this->assertSame($name, IndexName::compose('', $table, 'orrery_backlog'));
        $this->assertNotSame($name, IndexName::compose('', $table, 'orrery_other'));
    }
}
