# bin/

Every tool prints its full usage with `--help`. The tests of these tools are in the group `scripts` (`tests/Scripts/`); after a change here, run that tool's own test file.

## bin/tia (test impact analysis)

The inner loop: it runs only the test classes that a change can affect. Logic in `bin/lib/tia.php`, recorder in `tests/Support/Tia/`, tests in `tests/Scripts/TiaTest.php`.

```bash
bin/tia                              # affected classes on in-memory SQLite
bin/tia sqlite-file                  # the same on a SQLite file (also mysql, pgsql)
bin/tia --dry-run                    # print the selection and the reasons only
bin/tia status                       # print the maps
bin/tia record [--engine=<engine>]   # record the map under Xdebug (minutes; heavy for servers)
bin/tia -- --stop-on-failure         # arguments after -- go to PHPUnit (not --filter)
```

- **The map** (`.tia/map-<engine>.json`, gitignored) records per test class the checkout files it ran (Xdebug coverage, included files, and the files of every PHP child, through `PHP_INI_SCAN_DIR` and `auto_prepend_file`), with hashes, the PHP version, the vendor hashes, the test variant and the commit. A worktree without its own `.tia/` uses the main checkout's map (hashes are by content). The recording refuses PHPUnit arguments and refuses to write when the tree changed during the run.
- **The selection.** A body-only change of a PHP file selects the classes that ran it; a surface change (constants, properties, signatures, hierarchy, imports) also the classes that ran files naming its classes, transitively. Comments and whitespace select nothing. Every change also selects classes whose files name the changed path in a string literal. The groups `scripts` and `workbench` run only through their own rules (their file, a helper only they ran, an invariant guard that names package code, their directory rule); other classes of those groups are "left to the chunk-end gate".
- **Full fallback** (with the reason) when the map cannot prove a selection: a changed PHP file that no class ran, a deleted file that a class ran, `composer.json`, `phpunit.xml(.dist)`, `tests/bootstrap.php`, `testbench.yaml`, `tests/Support/Tia/`, an unclassified file, another PHP minor, other vendor packages, another variant, or a map more than 500 commits behind (`--max-commits`). Record again when it says the map is stale.
- **Record only in the main checkout, with no untracked files** (`git status`). The map lists untracked files too. A worktree does not have them, so it would see them as deleted and fall back to the full scope. Ignore a stray local file in `.git/info/exclude`.
- **In a worktree, copy the main checkout's `composer.lock` before `composer install`.** Other vendor versions also make it fall back.
- It never runs `pnpm test`, `bin/check-assets` or the group `docker`; it prints a notice for them.
- **Limits.** It does not see: a `src/` change that breaks a `scripts` or `workbench` class; a file read through a path that no string literal names; `php -n` children; a child that starts after the next class began; skip paths on an engine other than the recorded one.

## bin/test

`bin/test <sqlite|mysql|pgsql> [--no-provision] [--sqlite-file] [--drop-database] [--alt] [--schema=<reuse|fresh>] [--timeout=<s>] [--slot-wait=<s>] [--php=<binary>] [--] [phpunit arguments]`

- Put wrapper options directly after the engine.
- `sqlite`: in-memory, no Docker. `sqlite --sqlite-file`: a SQLite file in WAL in the run's temporary directory (the sibling file `<file>.orrery.sqlite` in `same` mode); use it for the process tests.
- `mysql`, `pgsql`: take a lease and the name lock, start the `orrery-testing` containers when needed, recreate `<base>` and `<base>_mirror`, run PHPUnit, release.
- The base name is `ORRERY_TEST_DATABASE`; unset, `bin/test` generates `orrery_run_<8 hex>` and drops it. An explicit name stays unless `--drop-database`.
- `--alt` also uses the second server (`mysql-alt` on 33107, `pgsql-alt` on 54337). `--group alt` alone runs nothing: add `--exclude-group orrery-no-exclusion`.
- `--schema`: default `reuse` for server leases and `--sqlite-file`, `fresh` for in-memory and `--no-provision`.
- `--timeout`: a hard limit of the PHPUnit run (exit 124, naming the running test).
- Passed through: `ORRERY_TEST_MIRROR`, `ORRERY_TEST_PREFIX`, `ORRERY_TEST_PG_POOLED=1`, `ORRERY_TEST_SQLITE_JOURNAL=delete` (rollback mode; the whole suite there is only a diagnostic, because classes that rely on automatic reads fail by design).
- It unsets `DB_URL`, `DATABASE_URL`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_CONNECTION`.
- Exit codes: 64 usage, 72 slot wait passed, 73 another run holds the name, 74 internal failure (if the cleanup itself fails it prints the recovery command), 78 missing `--no-provision` variables, 124 timeout, else PHPUnit's.

## The heavy-run slot

One heavy run at a time on the machine: `bin/test mysql|pgsql` with a lease and `bin/matrix` (except `--ci`, `--dry-run`). It is a flock on `<registry>/slot.lock`, held by a helper until `bin/test` ends. A second heavy run waits (`--slot-wait`, default 7200 s; exit 72). The holder exports `ORRERY_SLOT_TOKEN` so nested runs enter. Never hold or delete `slot.lock`, `slot.json` or `slot-waiters/` by hand.

## The ownership contract

- Only `bin/lib/testdb.php` operates the containers, always as `docker compose --project-name orrery-testing --file docker/testing/compose.yaml`. No script touches another container. Do not start or stop this project by hand while a run holds a lease.
- Ports: `127.0.0.1:33106` (MySQL), `127.0.0.1:54336` (Postgres), and the `alt` profile's 33107 and 54337. A foreign process on a port makes `bin/test-db up` fail (69).
- Capacity: each server keeps its data in a tmpfs inside its memory limit (`ComposeCapacityTest`). When the Postgres tmpfs is full it refuses every connection, so drop each explicit database after its run. A test that seeds many rows must size itself (as `bin/benchmark-tick` does).
- Endpoint proof: `orrery_marker` holds a token equal to the registry's; the harness checks it before the first migration.
- The registry is `/tmp/orrery-testing-<uid>/` (mode 0700) for every checkout; `TMPDIR` does not change it.
- Names match `^orrery_[a-z0-9_]{1,40}$`, not ending in `_mirror`; `orrery_marker` is reserved. A run locks its names; a second run on a held name fails at once (73). A database without a live lock is stale and is recreated.
- The tests use the containers' admin accounts (`root`, `postgres`); the boundary is the container, the endpoint proof and the name locks. Databases are created and dropped only through `docker compose … exec`.

## One database name per run

Each parallel run (a worktree, an agent, a matrix leg) uses its own database name. Leave `ORRERY_TEST_DATABASE` unset when you can. For a fixed name, name it after your task (`ORRERY_TEST_DATABASE=orrery_a8_docs bin/test mysql --drop-database`, or `bin/test-db drop orrery_a8_docs` afterwards). Never use a name another run holds; on exit 73, choose another name.

## bin/test-db

Manages the containers and the registry; every command prints one JSON object. `up`, `down` (refuses, 75, while a lease is live; `--dry-run`), `create <base>`, `drop <base>`, `status`, `token`, `path`, `validate-name <base>`. `down --force` is for humans only: never run it from a script or an agent.

## bin/matrix

Every supported PHP × Laravel × engine combination (8 legs, hours), serially on purpose: the machine is shared. Run it before a release; CI runs the same legs in parallel (`.github/workflows/tests.yml` with `--ci`). `--quick` is a three-leg preset. `--memory-only` runs only the in-memory SQLite runs of each leg (the quick CI tier). `--dry-run` prints the legs, units, databases and runs; use it first. `--ci` is for GitHub Actions only. Runs go to `.matrix/<run-id>/` (gitignored; `summary.md`, `summary.json`). Exit codes: 0 pass (listed known failures allowed), 1 a leg failed, 64 invalid arguments, 72 slot wait passed, 255 internal error.

Schema modes: in-memory SQLite is always fresh. The file and server runs are fresh on the lowest legs and on php8.5-laravel13. The variant leg (php8.4-laravel12) runs its file runs fresh and its server runs in reuse; it has no separate reuse block. The other latest legs run in reuse. `--all-fresh` and `--ci` make every run fresh.

`composer.lock` is gitignored, so a worktree resolves the latest releases (Laravel 13 only); the Laravel 12 legs and the lowest versions run only in `bin/matrix`. The floors are `laravel/framework ^12.69.0|^13.30.0`.

## bin/check-assets

Builds the working checkout and compares the result with the committed `dist/`. It proves the committed sources only when `resources/js/`, `resources/css/` and the Vite configuration have no uncommitted changes (check with `git status`).

- Needs Node 24 (`.nvmrc`) and the pnpm of `packageManager` in `package.json` (`engines` holds both).
- Refuses to start when `dist/`, `package.json`, `pnpm-lock.yaml` or `pnpm-workspace.yaml` has uncommitted changes. Runs `pnpm install --frozen-lockfile --engine-strict` and `pnpm run build`.
- Fails on eval forms in `dist/app.js` and when `dist/app.js` passes `bundle_budget_bytes`.
- On a mismatch, commit the rebuilt `dist/` in its own commit, or fix the build.

## bin/benchmark-tick

`bin/benchmark-tick <sqlite|mysql|pgsql>` seeds `jobs` and the mirror and runs every tick phase one at a time. It is not a test run. On servers it refuses sizes that would overfill the tmpfs (`--dry-run` prints the plan). Its databases `orrery_b22_bm` and `orrery_b22_bm_probe` are always dropped. Method and results: `docs/performance/tick-benchmark.md`.
