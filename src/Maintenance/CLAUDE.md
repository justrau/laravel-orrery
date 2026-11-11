# src/Maintenance/

## The tick

- `MaintenanceModule` schedules `orrery:tick` every minute (`onOneServer()->withoutOverlapping(10)`, or the sibling file mutex on a host SQLite store). With `orrery.schedule` false, the package schedules nothing and the app adds the line itself (`docs/installation.md`, "The scheduler").
- `orrery:tick` writes `observer_tick_at`, then `TickRunner` runs the phases tagged `TickCommand::PHASES_TAG` in priority order, each inside the watcher as `tick:<name>`, with a cooperative deadline of `tick.deadline_seconds` (30). A phase that ends after the deadline records `tick_overrun:<phase>`, and the later phases are skipped, except a `RunsPastDeadline` phase (`stuck_events` and the two notification phases, which are last): it gets the rest of the budget or its own share, whichever ends later, through `TickBudget::atLeast()`, which keeps the run (`TickBudget::run()`) for the run-scoped measurement.
- After the phases, `orrery:tick` stores the run's outcome in the settings row `tick_outcome` (`TickOutcome`: start, finish, failed phases, deadline skips in a row per phase) for the monitoring ping, which fails on a failed phase or on `TickOutcome::SKIP_STREAK` (3) skips in a row. During the phases it is the previous tick's outcome: `sample_counts` measures the blind-gap window from its `started_at` (`Monitoring\BlindGaps`, see `src/Monitoring/CLAUDE.md`).
- On a host SQLite file no phase writes (D17), and reads need D18.
- A phase lives in the `Phases/` directory of its area. Each docblock states its work per tick.

| Priority | Phase | Area |
| --- | --- | --- |
| 90 | `monitoring_repair` | Monitoring |
| 97 | `sample_counts` | Monitoring (before retention can delete a row) |
| 100 | `retention` | Maintenance |
| 200 | `heartbeat_sweep` | Workers |
| 250 | `bulk_resolution` | Queue |
| 260 | `reconcile_active` | Maintenance |
| 300 | `signal_expiry` | Control |
| 405 | `samples` | Monitoring |
| 410 | `stuck_incidents` | Monitoring |
| 420 | `restart_markers` | Markers |
| 460 | `reconcile_failed` | Maintenance |
| 480 | `operations` | Retry |
| 520 | `operation_retention` | Retry (also the bulk-run audit rows) |
| 870 | `sample_retention` | Monitoring |
| 875 | `marker_retention` | Markers |
| 900 | `health_retention` | Maintenance |
| 940 | `stuck_events` | Monitoring (StuckJobDetected: a slow listener delays no earlier phase) |
| 950 | `stuck_notifications` | Monitoring (a slow channel delays no earlier phase) |
| 960 | `long_wait` | Monitoring (last: its sends delay no other phase) |

## Retention (`Retention`)

It runs inside the tick with its 10 s share (`MAX_SECONDS`) and no row cap.

- **What it trims.** Only `completed`, `failed` and `unknown` rows, by the retention of their state (`trim.*`, with `trim_overrides` per connection and queue, `RetentionPolicy`); never `pending`, `reserved` or `retrying` rows, and never a row that an operation holds (`operation_id` set). The counts floor of the samples lowers the cutoff.
- **Discovery.** One index seek per distinct (`connection`, `queue`, `status`, `silenced`, `monitored`) tuple, a row-value comparison with `ORDER BY … LIMIT 1` (MySQL and MariaDB: nested OR terms). A seek that returns a tuple again throws. `RetentionDiscoveryPlanTest` checks the plan per engine.
- **Batches.** One batch of each partition in turn (500 rows), until all are done, 10 s pass, or the tick budget ends. The first batch always runs. The settings row `retention_start` rotates the start.
- **Lock modes** (`lockMode()`): `file` (a SQLite mirror: it pauses 150 ms before a batch that follows a write, so the package's own writers get the lock) and `rows` (MySQL, Postgres, in-memory SQLite).
- **State.** The settings row `retention_state` holds `{at, deleted, behind, lag_seconds, partitions_unchecked}`; `/api/health` returns it and the footer warns from it.

## Reconciliation

`reconcile_active` checks `pending` and `reserved` rows against `jobs`, and checks the rows of a connection that is no longer a `database` queue connection against every configured queue table, by job id and payload uuid, in chunks after a rotating cursor (`reconcile_removed_cursor`); a table that the tick may not read (D18) or whose read fails is skipped, and a row becomes `unknown` (reason `connection_removed`, no stuck incident) after the grace only when every table was read; `reconcile_failed` checks failed rows with a bound record (`SqlFailedRecords` only) by (`id`, `uuid`). A row whose native retry was requested scans the jobs of its queue for the retried job: at most `NATIVE_RETRY_SCANS` scans per pass, none after the share `NATIVE_RETRY_SECONDS`. `orrery:reconcile` is the operator path of all three (the removed connections' rows only without `--connection`), also on a SQLite file that the tick does not read.
