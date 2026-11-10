<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Support;

use Illuminate\Database\SQLiteConnection;
use Justrau\Orrery\Tests\Support\DescribesPackageTables;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The SQLite structure description of the schema tests (Support\DescribesPackageTables):
 * two schemas that differ in structure describe differently, and two texts of
 * one structure describe the same.
 */
final class DescribesPackageTablesTest extends TestCase
{
    use DescribesPackageTables;

    private const PARENT = 'CREATE TABLE "p" ("id" integer primary key)';

    /**
     * @return iterable<string, array{0: list<string>, 1: list<string>}>
     */
    public static function differentStructures(): iterable
    {
        yield 'a CHECK on a column' => [
            [self::PARENT, 'CREATE TABLE "t" ("id" integer primary key, "state" varchar check ("state" in (\'a\', \'b\')))'],
            [self::PARENT, 'CREATE TABLE "t" ("id" integer primary key, "state" varchar check ("state" in (\'a\', \'c\')))'],
        ];
        yield 'a table CHECK' => [
            [self::PARENT, 'CREATE TABLE "t" ("id" integer primary key, "n" integer, check ("n" > 0))'],
            [self::PARENT, 'CREATE TABLE "t" ("id" integer primary key, "n" integer)'],
        ];
        yield 'a foreign key' => [
            [self::PARENT, 'CREATE TABLE "t" ("id" integer primary key, "p_id" integer references "p" ("id") on delete cascade)'],
            [self::PARENT, 'CREATE TABLE "t" ("id" integer primary key, "p_id" integer)'],
        ];
        yield 'the predicate of a partial index' => [
            [self::PARENT, 'CREATE TABLE "t" ("id" integer primary key, "n" integer)', 'CREATE INDEX "t_n" ON "t" ("n") WHERE "n" > 0'],
            [self::PARENT, 'CREATE TABLE "t" ("id" integer primary key, "n" integer)', 'CREATE INDEX "t_n" ON "t" ("n") WHERE "n" > 1'],
        ];
        yield 'an expression index' => [
            [self::PARENT, 'CREATE TABLE "t" ("id" integer primary key, "n" varchar)', 'CREATE INDEX "t_n" ON "t" (lower("n"))'],
            [self::PARENT, 'CREATE TABLE "t" ("id" integer primary key, "n" varchar)', 'CREATE INDEX "t_n" ON "t" (upper("n"))'],
        ];
        yield 'the collation of an unindexed column' => [
            [self::PARENT, 'CREATE TABLE "t" ("id" integer primary key, "n" varchar collate nocase)'],
            [self::PARENT, 'CREATE TABLE "t" ("id" integer primary key, "n" varchar)'],
        ];
    }

    /**
     * Rejects: a description that does not see a CHECK constraint, a foreign key, the
     * predicate of a partial index, the expression of an expression index or the collation
     * of an unindexed column, so a schema change of one of them passes the baseline
     * comparison.
     * Still passes: a description that sees a difference only through the DDL text of
     * another part (the next test covers text that changes without structure).
     *
     * @param  list<string>  $first
     * @param  list<string>  $second
     */
    #[DataProvider('differentStructures')]
    public function test_a_structure_difference_changes_the_description(array $first, array $second): void
    {
        self::assertNotSame($this->describe($first), $this->describe($second));
    }

    /**
     * Rejects: a description that compares the raw DDL text, so another quoting, case or
     * spacing of the same structure (another Laravel release) fails the comparison.
     * Still passes: a description that ignores a difference (the test above).
     */
    public function test_another_text_of_one_structure_describes_the_same(): void
    {
        $first = [
            self::PARENT,
            'CREATE TABLE "t" ("id" integer primary key, "p_id" integer references "p" ("id"), "state" varchar check ("state" in (\'a\', \'B\')) collate nocase, check ("p_id" > 0))',
            'CREATE INDEX "t_state" ON "t" (lower("state")) WHERE "p_id" > 0',
        ];
        $second = [
            self::PARENT,
            "create table t (\n  id integer primary key,\n  p_id integer references p(id),\n  `state` varchar CHECK( state IN ('a','B') ) COLLATE NOCASE,\n  CHECK (p_id > 0)\n)",
            'create index t_state on t ( LOWER(state) ) where p_id > 0',
        ];

        self::assertSame($this->describe($first), $this->describe($second));
    }

    /**
     * @param  list<string>  $statements
     * @return array<string, mixed>
     */
    private function describe(array $statements): array
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));

        foreach ($statements as $statement) {
            $connection->statement($statement);
        }

        return self::describeSqliteTables($connection, ['p', 't']);
    }
}
