<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use Justrau\Orrery\Storage\PackageSession;
use PDO;

/**
 * A sibling SQLite mirror file of the test's own, on every engine of
 * the run: the default connection becomes a new SQLite file and
 * orrery.database is emptied, so the package's mirror is that file's
 * sibling `<file>.orrery.sqlite`. The test calls restoreMirror() in a
 * finally block: the teardown's rollback needs the run's own connections.
 */
trait UsesSiblingMirror
{
    /** @var array<string, mixed> */
    private array $savedMirrorConfig = [];

    private ?string $siblingHostFile = null;

    /**
     * Makes the mirror a new sibling file and returns its path. With
     * $marker (null: no marker row) and $tables, the file is created
     * before the package opens it, as an earlier build left it.
     *
     * @param  list<string>  $tables  the tables to create in the file before the package opens it
     */
    private function useSiblingMirror(array $tables = [], ?int $marker = null): string
    {
        $config = $this->app['config'];

        foreach (['database.default', 'orrery.database', 'database.connections.sibling_host'] as $key) {
            $this->savedMirrorConfig[$key] = $config->get($key);
        }

        $this->siblingHostFile = SqliteFiles::create();
        $config->set('database.connections.sibling_host', ['driver' => 'sqlite', 'url' => null, 'database' => $this->siblingHostFile, 'prefix' => '', 'foreign_key_constraints' => false]);
        $config->set('database.default', 'sibling_host');
        $config->set('orrery.database', null);
        PackageSession::releaseAll();

        $sibling = $this->app->make(PackageSession::class)->siblingPath();
        self::assertIsString($sibling);

        if ($tables !== []) {
            $pdo = new PDO('sqlite:'.$sibling, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            foreach ($tables as $table) {
                $pdo->exec($table === 'orrery_schema'
                    ? 'create table orrery_schema (id integer not null primary key, version integer not null)'
                    : "create table {$table} (id integer not null primary key)");
            }

            if ($marker !== null) {
                $pdo->exec('insert into orrery_schema (id, version) values (1, '.$marker.')');
            }
        }

        return $sibling;
    }

    /** The sibling file's marker, read without the package; null without the table or its row. */
    private function siblingMarker(string $sibling): ?int
    {
        $pdo = new PDO('sqlite:'.$sibling, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        if ($pdo->query("select count(*) from sqlite_master where type = 'table' and name = 'orrery_schema'")?->fetchColumn() == 0) {
            return null;
        }

        $version = $pdo->query('select version from orrery_schema where id = 1')?->fetchColumn();

        return $version === false || $version === null ? null : (int) $version;
    }

    private function restoreMirror(): void
    {
        foreach ($this->savedMirrorConfig as $key => $value) {
            $this->app['config']->set($key, $value);
        }

        $this->savedMirrorConfig = [];
        PackageSession::releaseAll();
        $this->app['db']->purge('sibling_host');

        if ($this->siblingHostFile !== null) {
            SqliteFiles::remove($this->siblingHostFile);
            $this->siblingHostFile = null;
        }
    }
}
