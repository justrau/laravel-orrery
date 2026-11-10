<?php

namespace Workbench\App\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Workbench\App\Support\WorkbenchDatabase;

/**
 * The first Workbench build step: delete the guarded SQLite file, the package's sibling mirror
 * file next to it (database.orrery.sqlite), and their sidecar files.
 *
 * Every build then starts from no database. A step that fails later leaves no file or an
 * empty one, so workbench/check.php can never pass on the counts of an earlier build.
 * Testbench's own `drop-sqlite-db` step deletes only databasePath()/database.sqlite and
 * leaves the -wal and -shm files, which SQLite can apply to the new file.
 */
#[AsCommand(name: 'workbench:drop-database')]
class DropDatabaseCommand extends Command
{
    /**
     * The sidecar files that SQLite creates next to a database file.
     */
    public const SIDECARS = ['-wal', '-shm', '-journal'];

    /** @var string */
    protected $signature = 'workbench:drop-database';

    /** @var string */
    protected $description = 'Delete the Workbench SQLite file, the Orrery sibling file and their -wal, -shm and -journal files.';

    public function handle(): int
    {
        $database = WorkbenchDatabase::resolve(WorkbenchDatabase::configured());

        // Close an open connection first, so no later query writes to the deleted file.
        DB::purge();

        // Check every path before deleting any, so a refusal leaves all files in place.
        $files = [];

        // The package's sibling mirror file goes with the database.
        $sibling = WorkbenchDatabase::sibling($database);
        $paths = [];

        foreach ([$database, $sibling] as $base) {
            $paths[] = $base;

            foreach (self::SIDECARS as $suffix) {
                $paths[] = $base.$suffix;
            }
        }

        foreach ($paths as $file) {
            clearstatcache(true, $file);

            // unlink() removes a symbolic link itself, never its target.
            if (is_link($file) || is_file($file)) {
                $files[] = $file;
            } elseif (file_exists($file)) {
                throw new RuntimeException(sprintf('Refusing to delete [%s]: it is not a regular file.', $file));
            }
        }

        foreach ($files as $file) {
            if (! unlink($file)) {
                throw new RuntimeException(sprintf('Cannot delete the Workbench database file [%s].', $file));
            }
        }

        $this->components->info(sprintf('Deleted %d Workbench database file(s) for [%s].', count($files), $database));

        return self::SUCCESS;
    }
}
