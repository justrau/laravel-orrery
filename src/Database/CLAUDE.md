# src/Database/

## Schema rules

- `Schema\PackageSchema` holds every package table, `VERSION` (10) and `BASELINE` (10, the schema version of v0.1.0). `upgrades()` holds only steps above `BASELINE` (none yet). The marker table `orrery_schema` holds the installed version.
- **The baseline is frozen.**
  - `tests/Fixtures/schema-baseline/{sqlite,mysql,pgsql,mariadb}.sql` (with a `{prefix}` placeholder) record what `create()` builds at `BASELINE`. The MariaDB fixture differs from the MySQL one only in MariaDB's byte-exact collation.
  - `SchemaBaselineTest` checks that the baseline plus every `*_upgrade_orrery_schema_to_v<N>.php` migration equals a fresh `create()`.
  - `MigrationFreezeTest` (group `scripts`) holds the hashes of the main migration and of the fixtures, and checks that there is one upgrade migration per version.
- **The main migration is never edited.** It is a wrapper: `getConnection()` is `PackageSchema::migrationConnection()` (`orrery.database`, else null), `up()` is `install()`, `down()` is `uninstall()`. So `migrate --pretend` and the migration's transaction apply to the mirror connection, and the install policy changes in `src/` only. `install()` decides `mirrorRefusal()` and the sibling against the application's default: the default at `CommandStarting` (recorded by `StorageModule`, before `migrate --database=<name>` swaps it), else the default that `migrationConnection()` recorded before Laravel's `runMethod()` made the migration connection the default. Laravel dispatches `CommandStarting` in tests only with `WithConsoleEvents`. `install()`, `uninstall()` and `upgradeTo()` use the migrator's own connection object when its base name is the mirror's (a `<name>::direct` connection).
- **`install()`** refuses a mirror that `mirrorRefusal()` refuses. On the sibling it runs `ensure()`, except under `--pretend`. On a server mirror, by the marker: from `BASELINE` up to `VERSION` it does nothing (a second `migrate` from another migrations table); above `VERSION` it refuses with `downgradeHint()`; a marker table without a row gets `adoptUnmarked()`; no package table gets `create()` and `mark()`; anything else is refused with `UPGRADE_STEPS`.
- **`uninstall()`** runs `drop()` on a server mirror and nothing on the sibling. `drop()` refuses while an operation is open; the test environment turns that guard off with `useOpenOperationGuardForTesting()`.
- **A marker table without a row** is the state of a database loaded from `php artisan schema:dump` (structure and the migrations rows only). `create()` builds the marker table last, so it proves a complete schema. `adoptUnmarked()` runs every step above `BASELINE` and writes the marker. It refuses with `downgradeHint()` when a migrations table on the mirror's connection records an upgrade migration above `VERSION` (a dump of a newer release; with the migrations table on another connection this is not detected); `SchemaStatus` then gives `downgradeHint()`. It runs from the `SchemaLoaded` listener (`StorageModule`; it reports its errors and never stops `migrate`), `install()`, `upgradeTo()` and `orrery:install`.
- **A schema edit before the tag:**
  1. Edit `create()`.
  2. Re-capture the fixtures on each engine: `ORRERY_CAPTURE_SCHEMA_BASELINE=1 ORRERY_TEST_PREFIX=pfx_ bin/test <engine> -- --filter test_the_baseline_and_the_upgrade_migrations`.
  3. Update the hashes in the same commit.
- **A schema edit after the tag:**
  1. Raise `VERSION`.
  2. Add an idempotent step N, which checks each object with `hasTable()`, `hasColumn()` or `hasIndex()` first.
  3. Change `create()`.
  4. Add an upgrade migration whose `getConnection()` is `PackageSchema::migrationConnection()`, whose `up()` is `PackageSchema::upgradeTo(PackageSchema::configuredConnection(), N)` and whose `down()` is empty.
- **`upgradeTo(N)`:**
  - It refuses an N above `currentVersion()`.
  - On a pretending connection (`migrate --pretend`) it does nothing: every read answers nothing there.
  - On the sibling it runs `ensure()`.
  - On a server it adopts a marker table without a row (`adoptUnmarked()`), refuses a mirror without package tables, a missing marker table or a pre-baseline marker (`UPGRADE_STEPS`), and a marker above `VERSION`, and runs only the steps above the marker.
  - Postgres and SQLite run the steps in one transaction; MySQL writes the marker last.
- **The sibling installs itself.** `ensure()` runs under `BEGIN EXCLUSIVE`. It refuses a marker below `BASELINE`, or package tables without a marker (`PRE_BASELINE_SIBLING`: delete the file and it is rebuilt), and a newer marker. Below `VERSION`, it runs every step above `BASELINE` (not only those above the installed version: each step is guarded, so a step that already ran changes nothing), creates what is missing, and writes the marker, in one transaction.
- **`SchemaStatus`** is read on each call, by the health `schema` field and by `orrery:install`:
  - no package tables gives `INSTALL_HINT`;
  - a marker table without a row gives `UNMARKED_HINT` (`orrery:install` writes the marker; nothing is dropped);
  - a pre-baseline marker, or tables without the marker table, gives `UPGRADE_STEPS`;
  - a marker from `BASELINE` up to below `VERSION` gives `MIGRATE_HINT`;
  - a marker above `VERSION` gives `downgradeHint()`;
  - a refused sibling gives its refusal message.
- **The hint texts.** `SchemaOutdated` never names `UPGRADE_STEPS`. `UPGRADE_STEPS` starts with `vendor:publish --tag=orrery-migrations --force` and drops every package table and its rows. `SchemaUpgradePathTest` checks its commands.
- Every table has a primary key, so the schema installs with `sql_require_primary_key = ON`. Every index name comes from `IndexName`. Add an index only together with the query that reads it; `SchemaTest` lists the indexes exactly.
- Every package time is epoch seconds in a `decimal(16,6)` column (see `src/Storage/CLAUDE.md` for the exceptions).

## Deferred indexes

The stuck-signal indexes of `orrery_jobs` (`PackageSchema::DEFERRED_INDEXES`) are built by `create()` on a fresh install, but by no forward step: the sibling installs itself on its first open, possibly inside a host push, and must not index a large table under `BEGIN EXCLUSIVE`. For an existing schema the operator runs `orrery:install --indexes` (`IndexBuild`, on a dedicated connection). Until then `DeferredIndexes::missing()` names them, and their readers answer `index_missing`.

## One statement per table on MySQL and MariaDB

`create()` puts each table's keys inside Laravel's own `create table` statement (`InlineKeys`), instead of one `alter table … add index` per key: a large cut of the migration time. A key whose statement is not a plain `index` or `unique` clause stays a statement of its own, and an unexpected `create table` form gets Laravel's separate statements. Postgres and SQLite keep Laravel's statements. `PackageSchemaStatementsTest` checks the statements; `SchemaTest::test_the_inline_keys_build_the_tables_of_separate_key_statements` compares the built tables with those of the separate statements.

## Other

- The optional index (`queue`, `reserved_at`, `available_at`) for the application's `jobs` table is in `database/optional/`. The package never runs it; `vendor:publish --tag=orrery-jobs-index` publishes it under the time of the publish (`OrreryServiceProvider::publishWithCurrentDate()`), so it runs after the application's jobs table migration. Under `migrate --pretend` the queue connection of a jobs table on another endpoint pretends too.
- `orrery.load_migrations` false stops `loadMigrationsFrom()` (an application that runs `migrate` for several databases).
- The user's `jobs`, `failed_jobs` and `job_batches` tables stay authoritative. The package keeps mirrors and indexes of them.
