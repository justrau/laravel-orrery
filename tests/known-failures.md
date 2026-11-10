# Known runtime failures

A test in this table fails because of a package bug that a later
change fixes. The known-failure check excuses a failure only when a row matches
it exactly. A failure without a matching row fails the run. A test in
`tests/unconditional.txt` (by group or class) is never excused, even with a
row here.

Columns:

- **test**: the fully qualified test id, `Class::method`, without a data set.
- **engine**: `<driver>/<mirror>/<prefix>`. The driver is `sqlite`, `mysql` or
  `pgsql`. The mirror is `same` or `separate` (`ORRERY_TEST_MIRROR`). The
  prefix is `prefix` (any `ORRERY_TEST_PREFIX`) or `none`. `*` matches
  any value in its position.
- **signature**: a substring that the failure or error text in the JUnit file
  must contain.
- **finding**: the finding id of the full-package review of 2026-09-22.
- **fixed by**: the spec whose implementation removes the row.

**Observed runs.** These runs were recorded with PHP 8.3.30, Laravel
13.33.0 and PHPUnit 12.5.35. Every failure in them matches a row below, and
every row below failed in at least one of them. "The fast suite" is the
default selection of `phpunit.xml.dist` (what `composer test` and `bin/test`
run). It excludes the groups `scripts`, `workbench` and `docker`.

- SQLite in memory, all four combinations of same or separate and prefix or
  none: the fast suite.
- SQLite in a file (`bin/test sqlite --sqlite-file`), all four combinations:
  the fast suite.
- MySQL 8.4, same mode without a prefix: the fast suite.
- Postgres 18, same mode without a prefix: the fast suite (no failure).
- Postgres 18, separate mode with a prefix: the harness group and eleven
  mirror-touching files (`QueueLifecycleTest`, `PayloadPreparationTest`,
  `TagRepositoryTest`, `WorkerHeartbeatTest`, `ControlPlaneTest`,
  `MetricsRepositoryTest`, `RetryReferenceTest`, `MonitoredTagsTest`,
  `MonitoringControllerTest`, `ForgetFailedCommandTest`, `TickCommandTest`).

The groups that the fast suite excludes ran only in these gates, with no
failure:

- `composer test:all`: every group except `docker`, on SQLite in memory, same
  mode without a prefix.
- `composer test:docker`: the `docker` group. Its cases start their own runs
  through `bin/test` against the MySQL and Postgres containers.

The Laravel 12 leg (PHP 8.4, Laravel 12.69, PHPUnit 11.5) showed the same
failures in the configurations that it ran.

**Predicted, not observed.** The `*` in an engine pattern also matches runs
that were not recorded: MySQL with a prefix, MySQL and Postgres in separate
mode without a prefix, Postgres in same mode with a prefix, and the rest of the
suite on Postgres in separate mode with a prefix. A row excuses a failure
there only by its test, engine pattern and signature. The row does not show
that such a run passes its other tests or its harness gates.

| test | engine | signature | finding | fixed by |
| --- | --- | --- | --- | --- |
