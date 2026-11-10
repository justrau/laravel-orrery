# Upgrading

- **From 0.1.0 on**, a schema change comes as a new published migration: run `php artisan vendor:publish --tag=orrery-migrations`, then `php artisan migrate`. The SQLite sibling file upgrades itself. Until `migrate` runs, a feature of the newer schema answers `schema_outdated` and the rest keeps working.
- **A code rollback after `migrate`:** `/orrery/api/health` `schema` names both versions with the hint "deploy the release that matches the installed schema". A migration that is newer than the installed code refuses to run.
- `/orrery/api/health` `schema` and `orrery:install` show the installed and the expected version with the exact steps.
- **A schema dump** (`php artisan schema:dump`) keeps no row of the marker table `orrery_schema`. A `migrate` that loads the dump writes the marker, but only when it loads it on the connection of the package tables (your default connection, or `--database=<ORRERY_CONNECTION>`). If you load a dump in another way, run `php artisan orrery:install`: it writes the marker and keeps every table and row. When `ORRERY_CONNECTION` is another connection, a dump of your default connection holds no package table but records the package migration, so `/orrery/api/health` `schema` asks you to install: run `php artisan migrate:refresh --path=database/migrations/2026_09_23_000000_create_orrery_tables.php --realpath` once, which creates the package tables on that connection.

**An install from a build before 0.1.0** takes this path once, because its schema cannot be told apart from the release's. **It drops every `orrery_*` table and its rows**: the job mirror, the history, the samples, the health events, the ledger with its audit rows, the fences, the bulk runs and the incidents. Only `jobs`, `failed_jobs` and `job_batches` keep their data. On MySQL and Postgres:

1. Run `php artisan vendor:publish --tag=orrery-migrations --force`: a copy published by an early build can hold an older schema.
2. Let the tick finish the open operations. Run `php artisan orrery:resolve --all` for those that wait for an operator, and `php artisan orrery:resolve <id>` for any other that stays open. The rollback refuses while an operation is open, and lists them.
3. Run exactly `php artisan migrate:refresh --path=database/migrations/2026_09_23_000000_create_orrery_tables.php --realpath`. Never a plain `migrate:rollback`, `--step` or `migrate:reset`: they roll back your application's migrations too. If it reports "Nothing to rollback", drop the remaining `orrery_*` tables, then run `php artisan migrate`.
4. Run `php artisan orrery:install --fence`, then `php artisan migrate`, then `php artisan orrery:install --indexes`.

On SQLite, stop the workers and delete every sibling file made by a build before 0.1.0, with its `-wal` and `-shm` files; the package rebuilds it. Delete it also when the package still opens it: it can lack a table of the release with the same schema version. The package refuses a sibling with an older schema or without a schema marker, and `/orrery/api/health` `schema` names the file and this step.

## Removing Orrery

In this order:

1. Stop the workers and the scheduler (a tick, a dashboard poll or a dispatch builds the SQLite sibling file again while the package is installed). Continue every paused queue (`php artisan orrery:continue` for each paused scope: Laravel keeps a queue pause until it is lifted), and finish or resolve the open operations (`php artisan orrery:resolve --all`). Remove `php artisan orrery:deployed` from your deploy script and the `orrery:tick` line from your schedule, if you added them: after the removal they fail.
2. Roll back the package migration:

   ```bash
   php artisan migrate:reset --path=vendor/justrau/laravel-orrery/database/migrations
   ```

   Use `migrate:reset`, not `migrate:rollback`: with a later batch of your application, `migrate:rollback --path=…` drops nothing and still exits 0. On the SQLite sibling the rollback does nothing; step 6 deletes the file.
3. If you published the optional `jobs` index (`vendor:publish --tag=orrery-jobs-index`), roll it back and delete it: `php artisan migrate:reset --path=database/migrations/<date>_add_orrery_jobs_index.php`, where `<date>` is the time prefix of the published file (an index published before 0.1.0 has `2026_09_23_000100`). It calls package classes.
4. Delete the published package migration `database/migrations/2026_09_23_000000_create_orrery_tables.php`. It calls package classes, so a kept copy breaks `migrate:fresh`, `RefreshDatabase` suites and the first `migrate` of a new environment. The fence migrations (`*_create_orrery_fences_on_<connection>.php`) use only Laravel classes and can stay. Their rollback drops nothing: to remove an `orrery_fences` table of another database, drop it by hand. On the default SQLite setup that table is in your application's own database file.
5. Remove `App\Providers\OrreryServiceProvider` from `bootstrap/providers.php` and delete `app/Providers/OrreryServiceProvider.php`: it extends a package class.
6. Delete `config/orrery.php`.
7. Run `composer remove justrau/laravel-orrery`.
8. Delete the files that the package wrote: the sibling file (`<database>.orrery.sqlite`) with its `-wal` and `-shm` files, the lock files next to it or to your SQLite file (`*.orrery.sqlite.create.lock`, `*.orrery-tick.lock`, `*.orrery-indexes.lock`), and `storage/framework/orrery-breaker-*`.
