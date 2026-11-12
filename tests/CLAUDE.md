# tests/

Run as few tests as possible (root `CLAUDE.md`, "Test policy"). The runners are in `bin/CLAUDE.md`. A direct `vendor/bin/phpunit` is only for in-memory SQLite: with server variables set (MySQL, Postgres or MariaDB), `tests/bootstrap.php` refuses and exits 78.

## The harness

- `TestCase` extends Orchestra Testbench. `Support\TestEnvironment` builds every connection completely from the `ORRERY_TEST_*` variables (`url => null`, driver, host, port, database, credentials, prefix, queue, failed-job and batch connections); it never falls back to SQLite silently. Without them, `testbench` is in-memory SQLite.
- Variables: `ORRERY_TEST_DRIVER`, `ORRERY_TEST_DATABASE`, `ORRERY_TEST_SQLITE_FILE`, `ORRERY_TEST_MIRROR` (`same`, `separate`), `ORRERY_TEST_PREFIX`, `ORRERY_TEST_PG_POOLED`, `ORRERY_TEST_SQLITE_JOURNAL`, `ORRERY_TEST_SERVER_VERSION` (the release that the identity check expects, from `TestEnvironment::SERVER_VERSION_CHOICES`: MariaDB `12.3` by default or `11.8`; any other value is refused; only the CI job `mariadb-previous-lts` sets `11.8`). `bin/test` sets the rest. `ORRERY_EXPECT_DRIVER` and `ORRERY_EXPECT_PHP` make the harness check the engine and PHP.
- The mirror: with `separate`, package tables are on `testbench_mirror`; with `same` on a SQLite file, in the sibling file (read through `testbench_sibling`); else on `testbench`. Read and write every `orrery_*` table through `TestCase::mirror()` and `mirrorSchema()`; keep `jobs`, `failed_jobs` and `job_batches` on the default connection.
- Before the first migration of each test, the harness proves the run's lease (server engines) and the identity of the connections, including the endpoint token.
- `TestCase::work($times)` runs queued jobs synchronously through the real `queue.worker`.
- **Package statements.** Package sessions have no event dispatcher. `Support\PackageStatements` records their statements through `PackageSession::recordForTesting()`, and `TestCase` bridges them to `QueryExecuted` and the transaction events by default (`$packageStatementEvents`), so a test can count them.
- **Clocks.** Never prove an interval with `usleep()`. `Clock::advanceMonotonicForTesting()`, `Clock::freezeMonotonicForTesting()`, `Watcher::freezeMonotonicForTesting()` and `advanceMonotonicForTesting()` move the monotonic clocks; `Carbon::setTestNow()` the wall clock; `Support\TestClock::shift($connection, $seconds)` a database clock, also in the workers that `WorkerProcess` starts. A frozen monotonic clock starts on the binary grid of `Clock::frozenBaseForTesting()`, so whole seconds and binary fractions add to it exactly.
- **Session bounds.** `PackageSession::useLockWaitSecondsForTesting()` and `useConnectTimeoutSecondsForTesting()` shorten the bounds of new sessions. Use 1 s for a lock timeout and 2 s for a connect failure (1 s can fail a healthy connect on a loaded machine). One test per engine keeps the production 5 s.
- **Shared cache.** The harness pins `cache.default = array`. `TestCase::useSharedCache()` gives workers a shared `file` store; call it before the first worker starts.
- `TestCase::mirrorOutage()` makes only the package storage fail. Call it outside every transaction; only a `#[FreshSchema]` test may use it.
- **Teardown** stops the workers, removes barriers and shared caches, ends outages, lets the application go, then drops every PDO (`setPdo(null)`, never `disconnect()`, which rolls transaction records back on Laravel 13) and releases the package sessions.
- Give every `uuid` column a valid UUID: Postgres rejects other values.
- Never write into Testbench's shared skeleton (`vendor/orchestra/testbench-core/laravel`). A test that runs `orrery:install`, publishes files or edits `bootstrap/providers.php` uses `Support\UsesPrivateSkeleton` (`startPrivateSkeleton()` before `parent::setUp()`, `endPrivateSkeleton()` after `parent::tearDown()`).
- Controller tests extend `ControllerTest`, which allows every request with `Orrery::auth(fn () => true)`.
- Version-exact tests: Laravel 13.31.0 names a direct connection `<name>::direct`, 13.30 by its plain name (`MainMigrationTest::directConnectionsHaveTheSuffix()`); Laravel 13.39.0 rolls the transactions manager back when a commit fails (`DeferralTestCase::failedCommitRollsTheManagerBack()` branches on it); PHPUnit 12.5.43 stopped reporting a failed `tearDown()` assertion after an error (`KnownFailuresCheckTest`). `QueueCallGraphTest` and `ProviderContractTest` pin the Laravel internals that the design reads on purpose.

## The schema modes (`ORRERY_TEST_SCHEMA`, `bin/test --schema=`)

- `fresh` (PHPUnit default, in-memory SQLite, CI): every test migrates and rolls back. The strict mode.
- `reuse` (default of `bin/test mysql`, `pgsql`, `mariadb` and `sqlite --sqlite-file`): `Support\ReusedSchema` migrates once per process. After each test it rolls back open transactions, compares a schema fingerprint with the migrated state (on a difference: drops every table and fails the test, naming `#[FreshSchema]`), empties the tables that have rows and resets used counters, then asserts that every table is empty.
- **The limit.** Reuse compares captured schema properties; it does not trace DDL. Every test that runs DDL or changes schema or database settings carries `#[Support\FreshSchema]` (method, class or parent), including changes the fingerprint does not see. `MirrorOutage::begin()`, `UsesAltServer::useAltServer()` and `startPrivateSkeleton()` refuse without it.
- `ORRERY_TEST_HARNESS_TIMING=<file>` writes the time of each harness phase as JSON.

## Static state and the reset hook

Package state lives in container bindings; Testbench builds a new application per test. `tests/Scripts/StaticStateInventoryTest.php` fails on every static in `src/` that is not in its allow-list (the public static API of `Orrery` and `Tags`, and the test seams `Hooks::$callback`, `Clock::$monotonicOffset`, `Clock::$frozenMonotonic`, `DatabaseClock::$offsetForTesting`, `Modules::$extra`). `Support\ResetsPackageState::resetPackageState()` runs before and after each test: `Modules::resetAllForTesting()` calls every module's `resetForTesting()`, then it clears `Hooks::$callback` and `Queue::createPayloadUsing(null)`. Do not call these resets in single tests. New state goes in a binding with the lifetime it needs, and the test proves that lifetime.

## Worker processes

Process tests start real workers and need a shared database: a SQLite file, MySQL, Postgres or MariaDB. On in-memory SQLite they skip with a reason (group `shared-database`).

- `Support\WorkerProcess::start($artisanArgs, $env, $extraEnv)` starts `tests/Support/bin/artisan` and returns only after the child is registered and its identity line matches. Every wait has a deadline. It unsets inherited `DB_*`, `DATABASE_URL`, `ORRERY_TEST_*`, `ORRERY_EXPECT_*` and `ORRERY_IDENTITY_LOG` for the child.
- `tests/Support/bin/artisan` loads only the package provider and runs only `queue:work`, `queue:listen` and `orrery:*` except `orrery:install` (the parent owns the schema). Exit codes: 64 usage or refused command, 78 refused configuration, 79 a gate did not open, 80 a hold timed out, 1 the command threw. `ORRERY_TEST_FOREIGN_QUEUE=1` binds Laravel's own `DatabaseQueue` connector after the provider.
- `Support\Barrier` coordinates processes through marker files (`forTest()`, `arrive()`, `await()`). Sleeps never prove an interleaving.
- **Hold points.** `Runtime\Hooks::reach($point)`; each point is a constant of its class (`git grep -I "Hooks::reach" -- src`). `Barrier::holdEnvironment(...$points)` makes a worker arrive at `<point>.reached` and wait for `<point>.release` (at most `Barrier::HOLD_SECONDS`, also after the parent exits).
- If a worker stop fails, the teardown keeps the schema, barriers and outages and throws; every later test of that process refuses to start.

## Groups and the skip rule

- `harness`, `process`, `process-control` (the `ProcessHarnessTest` controls), `shared-database`: in the default selection. `scripts` (the `bin/` tools, with fake backends, and file scans), `workbench`: only `composer test:all`. `docker` (real containers; skips without `ORRERY_DOCKER_TESTS=1`): only `composer test:docker`. `alt` (second server; also in `docker`): only `bin/test <engine> --alt -- --group alt --exclude-group orrery-no-exclusion`.
- `phpunit.xml.dist` excludes `docker`, `scripts` and `workbench`. A command-line `--exclude-group` replaces that list.
- **The skip rule.** A test in `process` or `process-control` must never skip on a SQLite file or a server engine: `bin/matrix` fails the leg. Only `shared-database` members may skip, and only on in-memory SQLite. A test of a second server carries both `docker` and `alt`.
- `tests/Benchmark/` is in no suite; only `bin/benchmark-tick` runs it.

## Known failures

`tests/known-failures.md` lists tests that fail because of a known package bug. It is empty. Never add a row for a bug of your own task: fix it. A row is `` | `Class::method` | `<driver>/<mirror>/<prefix>` | `signature` | finding | fixed by | `` (`*` matches every engine value; one row per fault). A test in `tests/unconditional.txt` (a group or class per line) is never excused. `bin/check-known-failures` classifies the faults of one run for `bin/matrix`.

## Other

- `tests/js/`: see `resources/js/CLAUDE.md`.
- The browser smoke is `tools/browser/smoke.mjs` (Playwright, every screen under the enforced CSP); `bin/verify-install` drives it.
