# Changelog

All notable changes to Orrery (`justrau/laravel-orrery`) are listed in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

**Nothing is stable in 0.x.** The configuration keys, the commands and their options, the events, the `Orrery::` static API and the HTTP API under `/orrery/api` can change in any 0.x release. Each entry below names every such change. A stability promise waits for 1.0.

## [Unreleased]

### Added

- **Dark mode.** The dashboard follows the dark or light appearance of the operating system (`prefers-color-scheme`). There is no toggle. Every colour, shadow and gradient comes from a design token with a light and a dark value, and the body text keeps WCAG AA contrast in both. The README shows the Overview in dark mode.
- **Long-wait recovery.** A long wait is now an incident with a start and an end: it ends after 3 consecutive known minutes below the threshold, so a queue that moves around its limit sends one start and one recovery. The new event `LongWaitRecovered` (with the duration and the channels of the start message) fires once per incident; the new notification `LongWaitRecovered` goes only to the channels that got the start message, and a channel that is down is tried again for 10 minutes (`notifications.long_wait.recovered`, default `true`). Bind `Contracts\LongWaitRecoveredNotification` to send your own.
- **Failure-spike notices.** `notifications.failure_spike.per_minute` (default `null`, off) sends one notice for a minute whose failed jobs on all queues reach that number, with the count (at least) and the three largest failure groups; then each channel waits `notifications.failure_spike.cooldown` minutes (default 30).
- **Delete a waiting job.** "Delete…" on the detail of a pending job (`POST /orrery/api/jobs/{uuid}/delete`) deletes it from its queue before a worker takes it. The sheet says when the job's batch then never finishes, and when a chain is dropped.
- **Clear a queue from the dashboard.** "Clear waiting jobs…" on the Queues screen counts the waiting jobs first (`POST /orrery/api/queues/waiting`), then clears them (`POST /orrery/api/queues/clear`) within 15 seconds per request; both take `{connection, queue}` in the JSON body. It shares its code with `orrery:clear`. A clear whose later batch fails answers with the counts so far.
- **Run a delayed job now.** "Run now…" on the detail of a delayed job (`POST /orrery/api/jobs/{uuid}/run-now`) makes it available at once, by the workers' clock, and adds a `run_now` history entry.
- **Cancel a batch.** "Cancel batch…" on an open batch (`POST /orrery/api/batches/{id}/cancel`) marks it cancelled and finished, as `$batch->cancel()` does. Laravel's `BatchCanceled` event is not dispatched.
- **The event `OperatorActionPerformed`** and one `info` log line for each of these actions, with the actor. There is no stored activity log.

### Changed

- **Failure groups merge messages that differ only in an id.** The group key (`GroupKey::VERSION` `v2`) replaces datetimes, UUIDs, ULIDs, email addresses, long hexadecimal runs, numbers of 4 or more digits and the value of a MySQL duplicate entry in the first line, keeps the SQLSTATE code and the driver's error number, and cuts it at " (Connection: ". Short numbers stay, so "HTTP 404" and "HTTP 503" stay apart. Old failures keep their stored key, so a group can show twice until `trim.failed` removes the old rows (see [Upgrading](docs/upgrading.md)).
- **`running` of `/api/workload`** counts each reserved job by the `retry_after` of its own connection, when the connections of one jobs table have different leases. The backlog keeps the shortest lease of the table, so a job whose reservation expired for the shortest lease counts in both `running` and `length`.
- **A byte budget for the observations that wait for a commit.** Besides the count cap, the observations held for the commit of an application transaction now have a byte budget: the smaller of 32 MB and an eighth of `memory_limit`, estimated from the payload sizes plus a small fixed size per job. A bulk push or a `Bus::batch()` is one observation: when its payloads exceed the budget, the whole bulk is dropped, and its jobs appear only when a worker reserves them. A dropped observation is counted in `unverified`. The first drop of each reason in a process is reported through the application's exception handler as `deferral.count_cap` or `deferral.byte_budget`, and a health window without another error shows the reason as its last error. A held observation keeps only its payload string: its decoded copy is freed when it is held, and its write decodes the payload again; an observation that runs at once is decoded only once. A dropped push stays invisible until its reservation creates the row; [Known limits](docs/known-limits.md) says so.
- `orrery:clear` also deletes the mirror rows of the other connection names on the same jobs table, compares the queue name byte by byte (on MySQL `high` no longer clears `HIGH`), and refuses a queue connection that is inside a transaction.
- `GET /orrery/api/queues/{connection}/{queue}/history` is now `GET /orrery/api/queues/history?connection=&queue=`: a browser resolved `.` and `..` segments of a queue name in the path, so `x/../default` read another queue.
- `GET /orrery/api/jobs/{uuid}` adds `chained` (pending jobs) and `actions.delete`, `actions.pending_reason` and `actions.run_now`.

### Fixed

- **A stopped commit walk no longer holds later observations.** When an `afterCommit` callback of the application threw before the package's callback, every later observation of that connection waited until the end of the job, command or request, and a long-running command lost them at the count cap. Now the next observation sees that the walk is no longer on the call stack: the stopped transaction's observations are counted in `unverified`, and the later ones are written at once or at their own commit.
- **MySQL package sessions work with multi-statements off.** The package settings (autocommit, the lock waits, the statement timeout) were joined to the application's `PDO::MYSQL_ATTR_INIT_COMMAND`, so an application that set an init command and turned `PDO::MYSQL_ATTR_MULTI_STATEMENTS` off got a syntax error at each package connect. The application's init command now stays unchanged, and the package runs its settings as one statement after the connect.

## [0.1.0] - 2026-11-10

The first release: a Horizon-style dashboard for the `database` queue driver of Laravel. Orrery is a watcher only. It never starts, supervises or scales queue workers; you run `queue:work` under systemd, supervisord, Forge or another process manager. Your `jobs`, `failed_jobs` and `job_batches` tables stay authoritative, and Orrery keeps its own `orrery_*` mirror tables next to them.

**Supported versions:**

- PHP `^8.3` (tested on 8.3, 8.4 and 8.5).
- Laravel 12.69.0 or later, and 13.30.0 or later.
- Tested databases: SQLite (the versions of PHP 8.3 to 8.5), MySQL 8.4 and Postgres 18. MariaDB has code paths but is not tested yet.
- The `database` queue driver only. Retry and forget need the failed-job driver `database-uuids` (Laravel's default) or `null`; every other failed-job driver is read-only.

**Known limits.** [docs/known-limits.md](docs/known-limits.md) lists them. In short: only the `database` queue driver; Laravel's own `queue:retry`, `queue:forget` and `queue:flush` bypass the ledger and the fence; the throughput counts are observations, not exact event counts; without the optional `jobs` index the queue measurement scans the whole `jobs` table; and there is no dark mode yet.

### Added

- **Installation.** `orrery:install` publishes the configuration, the provider stub with the `viewOrrery` and `operateOrrery` gates, and the package migration, and writes the fence migrations for the other databases that need them. The SQLite sibling file (`database.orrery.sqlite`) installs and upgrades itself. The dashboard assets ship prebuilt; no Node or npm is needed.
- **Observation.** The package swaps the `database` queue connector, so it records each push, bulk push, reservation, release and outcome without a change to your jobs. Observations wait for the commit of your application's transaction. Errors of the package never reach your queue: they are counted in the health events.
- **Dashboard screens:**
  - Overview: what needs attention, one card per queue with a wait gauge and Pause or Continue, the completed and failed jobs per minute of the last hour with the deploy and restart markers, and the latest failure groups.
  - Queues: the wait against its limit, the ready and running jobs, the in and out rates, the workers, the backlog of the last hour, and the retention per queue.
  - Failure groups: the failed jobs grouped by exception class, first line of the message and job class, over 1 hour, 24 hours or 7 days.
  - Jobs and Job detail: every mirrored job in tabs (pending, running, completed, silenced, failed, retrying, unknown and stuck), with filters and a search. The detail shows the cycles, the history, the payload, the exception and the context, all redacted.
  - Batches, Job classes, Tags (monitored tags) and Workers (live workers and their current jobs, with `queue:listen` children under their parent).
  - The write buttons show only for users with `operateOrrery`. The old Horizon-style paths redirect to these screens.
- **Retry and forget** of failed jobs through an operation ledger (`orrery_operations`) and a fence table (`orrery_fences`), so an Orrery retry publishes a job at most once, also after a crash or a lost COMMIT answer. An operation that needs an operator waits as "awaiting resolution".
- **Bulk runs** of retry and forget over failure groups, a filter of the Failed tab or the failed members of a batch, with a frozen selection, a typed confirmation for a forget, and an audit row per run.
- **Controls.** Pause and continue globally, per connection or per queue (a queue pause is Laravel's own `queue:pause`), and terminate the workers after their current job. Each control stores who issued it.
- **Per-minute samples** of the completed and failed jobs, arrivals and runtimes, and the queue state per minute (backlog, oldest age, over limit). A minute that was not counted shows as unknown, never as 0.
- **Long-wait alerts.** The event `LongWaitDetected` and its notification by mail, Slack (webhook or Web API) or SMS, per the thresholds in `orrery.waits`.
- **Stuck jobs.** Four signals (reservation expired, outcome not observed, repeated releases, attempts not capped), the event `StuckJobDetected`, and notifications to the long-wait routes.
- **Deploy and restart markers** on the charts (`orrery:deployed`, and the value of `queue:restart`).
- **Retention** of the mirror rows by state, with overrides per connection and queue (`trim`, `trim_overrides`), and retention of the samples, operations, fences, markers and health events.
- **Health report.** `GET /orrery/api/health` lists the degradation windows, the tick, the retention and samples state, missing fence tables and indexes, uninstrumented connections, read-only failed-job drivers, operations that wait for an operator and SQLite warnings. The dashboard footer warns from it.
- **Commands:**
  - `orrery:install` (with `--fence` and `--indexes`) and `orrery:tick`, which the package schedules every minute;
  - `orrery:pause`, `orrery:continue` and `orrery:terminate` (with `--connection` and `--queue`);
  - `orrery:retry` and `orrery:forget` (one job, or `--all` with a scope);
  - `orrery:resolve`, `orrery:reconcile`, `orrery:clear`, `orrery:clear-metrics` and `orrery:deployed`.
- **Storage.** The package reads and writes through its own database sessions, with connect, lock and statement timeouts, so its statements never join your transactions and never reach Telescope, Pulse or your query counts. Postgres behind a transaction pooler is supported (`sessions.pooled`).
- **SQLite.** The package never writes to your application's SQLite files on its own; only an operator action does. It reads them automatically only in WAL mode with a `busy_timeout`.
- **The optional `jobs` index** (`vendor:publish --tag=orrery-jobs-index`) for large queues.

### Security

- Two abilities: `viewOrrery` for every read, and `operateOrrery` for every write, checked by the HTTP method. Without a definition, only the `local` environment has access.
- Every response with job data is redacted by key and by pattern (`redact.*`). The browser never receives raw serialized PHP, and Laravel's hidden Context is never shown.
- The dashboard runs under a strict Content Security Policy (no `unsafe-inline`, no `unsafe-eval`) with Laravel's CSP nonce, and loads no external font or script.
- The package's error reports (`WatchedOperationFailed`) carry no job value: no bindings, no previous exception and no trace.
- Failure-group keys are an HMAC under `app.key`, so a key in a URL does not reveal its message.

[Unreleased]: https://github.com/justrau/laravel-orrery/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/justrau/laravel-orrery/releases/tag/v0.1.0
