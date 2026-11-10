<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\FreshSchemaGuard;

use Justrau\Orrery\Tests\TestCase;

/**
 * The fixture of FreshSchemaGuardTest: a child PHPUnit run of it, in the
 * fresh mode on a SQLite file in separate mode, reproduces the cascade of
 * the wave-B matrix. PHPUnit runs it only when a test names this file: it
 * is not in a *Test.php file of a test suite.
 *
 * The first test clears orrery.database, as SchemaTest once did in its
 * finally block, so the rollback of the package migration runs on another
 * connection and the mirror file keeps its tables. Without the harness
 * guard, the migration of every later test then failed with "table
 * orrery_jobs already exists".
 */
final class MirrorLeftoverProbe extends TestCase
{
    public function test_a_rollback_that_misses_the_mirror(): void
    {
        self::assertTrue($this->mirror()->getSchemaBuilder()->hasTable('orrery_jobs'));

        $this->app['config']->set('orrery.database', null);
    }

    public function test_the_next_test_migrates(): void
    {
        self::assertTrue($this->mirror()->getSchemaBuilder()->hasTable('orrery_jobs'));
    }

    public function test_and_the_one_after_it(): void
    {
        self::assertTrue($this->mirror()->getSchemaBuilder()->hasTable('orrery_jobs'));
    }
}
