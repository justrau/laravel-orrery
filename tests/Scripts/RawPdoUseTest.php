<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Package sessions have no event dispatcher, so the test
 * recorder (PackageSession::recordForTesting()) sees only the round trips
 * that the session classes report. A raw PDO call that sends SQL outside
 * those classes would escape the hot-path budget. The test fails on every
 * getPdo(), getRawPdo(), getReadPdo() or getRawReadPdo() in src/ outside the
 * allowed files, and on every PDO that src/ opens itself (`new PDO`,
 * `PDO::connect()`, a driver subclass) outside the files of NEW_ALLOWED.
 */
#[Group('scripts')]
final class RawPdoUseTest extends TestCase
{
    /** file => why it may take the PDO */
    private const ALLOWED = [
        'src/Storage/PackageSession.php' => 'records each raw exec(), and takes the PDO of Laravel\'s connector',
        'src/Storage/PostgresSession.php' => 'records its combined begin and the relation map read',
        'src/Storage/PackageTransaction.php' => 'records its SQLite BEGIN IMMEDIATE, COMMIT, ROLLBACK and savepoints',
        'src/Database/Schema/PackageSchema.php' => 'records the BEGIN EXCLUSIVE, COMMIT and ROLLBACK of the sibling schema install',
        'src/Observation/CommitDeferral.php' => 'an identity check and inTransaction(): no SQL',
        'src/Retry/Publication.php' => 'a rollback that ends a broken publication before the session is released (C5b)',
    ];

    /** file => why it may open a PDO of its own */
    private const NEW_ALLOWED = [
        'src/Storage/HostFiles.php' => 'PRAGMA journal_mode on a read-only PDO of a host SQLite file (D18): no package table, no write',
        'src/Failed/CapturingUuidFailedJobProvider.php' => 'the SQLite library version of an in-memory PDO: no SQL on any database',
    ];

    /**
     * Rejects: a PDO that src/ opens itself outside NEW_ALLOWED, so its
     * statements reach no recorder and no budget, for example
     * `new PDO($dsn)`, `new \PDO(...)`, `PDO::connect(...)` or
     * `Pdo\Mysql::connect(...)`; and an allow-list entry that no longer
     * opens one.
     * Still passes: a PDO built through a variable class name or a
     * factory of another package.
     */
    public function test_src_opens_a_pdo_only_in_the_allowed_files(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        $seen = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src')) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);

            foreach (self::pdoOpens((string) file_get_contents($file->getPathname())) as $line) {
                if (isset(self::NEW_ALLOWED[$relative])) {
                    $seen[$relative] = true;

                    continue;
                }

                $found[] = $relative.':'.$line;
            }
        }

        self::assertSame([], $found, 'A PDO that src/ opens outside the allowed files.');
        self::assertSame([], array_values(array_diff(array_keys(self::NEW_ALLOWED), array_keys($seen))), 'The allow-list names a file that no longer opens a PDO.');
    }

    /**
     * Rejects: a scanner that misses one of the forms.
     */
    public function test_the_pdo_scanner_finds_every_form(): void
    {
        $source = <<<'PHP'
            <?php
            $a = new PDO('sqlite::memory:');
            $b = new \PDO('sqlite::memory:');
            $c = PDO::connect('sqlite::memory:');
            $d = \Pdo\Mysql::connect('mysql:host=x');
            $e = new Pdo\Sqlite('sqlite::memory:');
            $f = PDO::ATTR_TIMEOUT;
            $g = new PDOException('x');
            PHP;

        self::assertSame([2, 3, 4, 5, 6], self::pdoOpens($source));
    }

    /**
     * Rejects: a raw PDO call that sends SQL on a package session outside
     * the recording classes, for example an extra `$session->getPdo()->exec()`
     * on the push path.
     * Still passes: a call through another method name, for example a
     * reflection read of the PDO property.
     */
    public function test_raw_pdo_calls_stay_in_the_recording_classes(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        $seen = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src')) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $source = (string) file_get_contents($file->getPathname());

            if (preg_match_all('/->(getPdo|getRawPdo|getReadPdo|getRawReadPdo)\(\)/', $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            if (isset(self::ALLOWED[$relative])) {
                $seen[$relative] = true;

                continue;
            }

            foreach ($matches[0] as [$call, $offset]) {
                $found[] = $relative.':'.(substr_count(substr($source, 0, $offset), "\n") + 1).' '.$call;
            }
        }

        self::assertSame([], $found, 'A raw PDO call outside the classes that report to the recorder.');
        self::assertSame([], array_values(array_diff(array_keys(self::ALLOWED), array_keys($seen))), 'The allow-list names a file that no longer takes a PDO.');
    }

    /**
     * The lines that open a PDO: `new` of PDO or a Pdo\ driver class, and a
     * static connect() of one. Comments do not count.
     *
     * @return list<int>
     */
    private static function pdoOpens(string $source): array
    {
        $tokens = array_values(array_filter(token_get_all($source), static fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $lines = [];

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $name = strtolower(ltrim($token[1], '\\'));

            if ($name !== 'pdo' && ! str_starts_with($name, 'pdo\\')) {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;
            $after = $tokens[$i + 2] ?? null;

            if (is_array($previous) && $previous[0] === T_NEW) {
                $lines[] = $token[2];
            } elseif (is_array($next) && $next[0] === T_DOUBLE_COLON && is_array($after) && strtolower($after[1]) === 'connect') {
                $lines[] = $token[2];
            }
        }

        return $lines;
    }
}
