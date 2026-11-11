# Operations

## Pause, continue and terminate

```bash
php artisan orrery:pause
php artisan orrery:pause --connection=database
php artisan orrery:pause --connection=database --queue=emails
php artisan orrery:continue --connection=database --queue=emails
php artisan orrery:terminate
```

- Without options, a command applies to all workers. `--queue` needs `--connection`. The connection must be a `database` queue connection and `connection:queue` at most 191 characters, else the command exits 1 and the API answers 422. A queue without jobs can be paused.
- A queue is paused when any of these holds: a global pause, a pause of its connection, or Laravel's own queue pause (`queue:pause`; on Laravel 13 also `queue:pause --all`). No pause overrides another, and a continue lifts exactly its own scope. `orrery:continue` says what still pauses the target.
- A queue pause is Laravel's `QueueManager::pause()`, the same as `php artisan queue:pause database:emails`; Orrery only adds who paused it, for the display. So `queue:continue` lifts an Orrery queue pause, and `orrery:continue` lifts one from `queue:pause`. A queue pause also sets the flag under each `failover` connection whose first connection is the paused one, because a worker on that connection asks under its own name; a `queue:continue` must then name both. The global and connection pauses are Orrery's own signals.
- A paused worker finishes its current job and reserves no new job from the paused queues.
- A terminate stops each matching worker after its current job. A worker that starts after the signal ignores it, and your process manager restarts the stopped ones. The signal time and its expiry (30 minutes) come from the clock of the package database's server, so a server with a wrong clock behaves correctly. A worker that cannot read that clock uses its own and records `control:clock_uncalibrated`. A terminate does not stop a `queue:listen` parent process. In a `queue:work` worker, a terminate needs neither `pcntl` nor `posix`; elsewhere it sends SIGTERM through `posix_kill()` when that exists.
- A worker reads the signals at most once per `control.signal_cache_seconds`. When it cannot read the package tables, it keeps its last signals for `control.stale_signals_seconds`, then ignores them.

Limits of a queue pause:

- Laravel keeps a queue pause in the default cache store. With several servers, use a store that every worker shares. With a `file` store a queue pause may not reach another server, and with an `array` store it has no effect; `/orrery/api/health` then has the hint `queue_pause_cache_local`. Global and connection pauses still work.
- `cache:clear`, `optimize:clear` and a store that evicts keys can lift a queue pause.
- A `failover` connection added after the pause does not get its flag: pause the queue again.
- `Queue::withoutInterruptionPolling()` and a pop callback (`Worker::popUsing()`) bypass Laravel's pause check. Global and connection pauses still work.
- With the default cache store on an application SQLite file, the dashboard cannot read Laravel's pause state: each queue shows `native_unknown`.

## Retry and forget

A retry or forget of a failed job is one operation in the ledger `orrery_operations`. A retry publishes the failed payload once, under the same uuid, as the next cycle of the job. A forget deletes the failed record and the mirror row.

| Failed-job driver | Retry | Forget |
| --- | --- | --- |
| `database-uuids` on a table whose ids are never reused | yes | the exact record |
| `database-uuids` on another table | refused (`ids_may_be_reused`) | refused |
| `null` | from the mirror's copy of the payload | the mirror row only |
| any other (`file`, DynamoDB, the legacy `database` driver, a subclass or wrapper of Laravel's provider, another table or connection) | refused (`provider_read_only`) | refused |

- The package binds its own provider only when `queue.failer` is exactly Laravel's `DatabaseUuidFailedJobProvider` for the configured connection and table. It writes the same row and binds the id of its own insert to the failure.
- **Ids that are never reused:** an SQLite `INTEGER PRIMARY KEY AUTOINCREMENT` (Laravel's migration), MySQL 8.0+, MariaDB 10.2.4+, and a Postgres identity or serial column. Never `TRUNCATE` the failed-job table or reset its counter or sequence.
- **Failures without a proven record.** The provider binds nothing on SQLite below 3.35, on Postgres inside an open transaction of the failed-job connection or when the role cannot read the `id` column, and on MySQL when another statement ran on the connection during the insert. Retry and forget refuse such a failure (`occurrence_unprovable`): use `queue:retry` or `queue:forget`. `/orrery/api/health` counts them (`failed_records.unprovable_failures`).
- **Modes.** When the failed-job connection is the queue's own connection name, a retry deletes the record and publishes the job in one transaction. Otherwise it first deletes the record in a fenced transaction of its own, and restores it when the publication fails.
- **The fence.** Each effect outside the package tables writes a fence row in the same transaction, on the database of the effect, so a stale or concurrent actor loses. The queue's database needs `orrery_fences`: until it exists, a retry refuses (`fence_missing`), and `/orrery/api/health` lists the connection in `missing_fence_tables` with the hint "Run php artisan orrery:install --fence, then php artisan migrate."
- **A changed target.** An operation keeps the identity of its tables (host, port, database, username, prefix, schema). When the configuration names another target, the operation waits (`target_changed`): restore the old settings, run `php artisan orrery:resolve <id>`, then switch again.

## Bulk runs

A bulk run retries or forgets every failed job of a selection: failure groups, a filter of the Failed tab, or the failed members of a batch. Each job goes through the ledger one at a time, as a single retry or forget does.

1. **The freeze.** The confirmation reads the selection once: its total ("at least N" at a cap), how many jobs a retry already holds, and up to 20 of its jobs. Failures after that moment are not included.
2. **A forget** needs an exact total of at most `bulk.forget_max`, which you type to confirm. The run never forgets more than that total, also from two browser tabs.
3. **The pages.** The run acts on at most 200 jobs per request and shows the totals per outcome (accepted, refused with the reason, uncertain, not a member, already done). Stop ends it after the current page; nothing is undone.
4. **After a reload.** Each run has an audit row (`orrery_bulk_runs`). `GET /orrery/api/bulk` lists the 20 most recent runs and `GET /orrery/api/bulk/{ref}` shows one with its ledger states; every user with `viewOrrery` can see the actor and the selection. Continuing needs a new freeze. A run idle for more than an hour expires.

```bash
php artisan orrery:retry <uuid>
php artisan orrery:retry --all --connection=database --queue=emails
php artisan orrery:retry --all --class='App\Jobs\SendInvoice' --from=1762600000 --to=1762686400
php artisan orrery:retry --all --batch=<batch id>
php artisan orrery:retry --all --key=<group key> --key=<group key> --from=<epoch> --to=<epoch>
```

`--all` prints the freeze and asks for a confirmation. `--force` skips it. Without `--force` it acts on nothing when the input is not interactive (`--no-interaction`) or STDIN is not a terminal (a pipe could answer the question). Ctrl-C stops after the current job. A scope option given with an empty value (for example `--batch=` with an unset shell variable) is refused. Exit codes, by precedence: 1 an error or a declined confirmation, 130 interrupted, 3 any uncertain job, 2 any refused job or a job that is no longer a member, 0 otherwise.

## Waiting jobs

These actions change your queue tables, so they need `operateOrrery`. Each one runs as an operator action: on an SQLite queue file it writes only when you start it.

- **Delete one job.** "Delete…" on the detail of a pending job deletes it from its queue before a worker takes it, with its rows in Orrery. A job that a worker took meanwhile is kept, and the answer says so. A job in a batch leaves that batch unfinished forever, so the sheet offers to cancel the batch instead; a job that carries a chain drops the rest of the chain. The delete of a bulk-pushed job waits until Orrery knows its row in the queue table: try again in a minute.
- **Clear a queue.** "Clear waiting jobs…" on the Queues screen first counts the waiting jobs of the queue (ready and delayed, and how many belong to batches, whose batches then never finish). It deletes every job of the queue that no worker has reserved, with the rows in Orrery of every connection name on the same jobs table. From the dashboard it stops after 15 seconds and says so; clear again to go on. If a later batch fails, the jobs of the earlier batches are gone and the answer counts them. `orrery:clear` does the same work without a time limit. Neither runs while the queue connection is inside a transaction.

- **Run a delayed job now.** "Run now…" on the detail of a delayed job makes it available at once, by the clock of your workers (PHP's, not the database's). Its attempts and releases stay as they are. The history shows a `run now` entry.

## Batches

"Cancel batch…" on a batch that is neither finished nor cancelled marks it cancelled and finished, as Laravel's `$batch->cancel()` does. Jobs that check the batch (the `SkipIfBatchCancelled` middleware or `batch()->cancelled()`) skip their work. Other jobs still run, and running jobs finish. Laravel's `BatchCanceled` event is not dispatched: it needs a `Batch` object, and Orrery never unserializes a batch's options. Only Laravel's database batch repository is supported.

**Audit.** Each delete, clear, run-now and batch cancel dispatches the event `Justrau\Orrery\Events\OperatorActionPerformed` (the action, its target, the actor, the time, the result and the counts) and writes one `info` line to your log, also while Orrery's storage is unreachable. Control characters in names are escaped in the line and its context. A refused batch cancel is recorded too. Orrery keeps no activity log of its own: listen to the event to store one.

## Deploys and restarts

Add `orrery:deployed` to your deploy script, after the new code is live:

```bash
php artisan migrate --force
php artisan queue:restart
php artisan orrery:deployed --label="v1.4.2"
```

- It records a deploy marker on the charts, once per label within `--window` seconds (default 600), so every server can run it. `--window=0` always records.
- The tick records a restart marker when the value of `queue:restart` in the default cache store changes. With several servers the store must be shared, as `queue:restart` needs anyway. A marker shows that a restart was requested, not that the workers stopped.
- Markers are kept for 7 days.

## Turning Orrery off

If Orrery causes trouble in production, turn it off with an environment change. You do not need a code deploy:

```bash
# .env
ORRERY_ENABLED=false
```

```bash
php artisan config:cache
php artisan queue:restart
```

- **Refresh the configuration cache.** With a cached configuration, Laravel does not read `.env`. Run `php artisan config:cache` (or your deploy's cache step) after the change. Without a cached configuration, a new request or command reads the new value.
- **Restart the workers.** A running worker keeps the configuration that it loaded at its start. Run `php artisan queue:restart`. Until each worker restarts, it observes as before.
- **What stops.** Orrery then behaves as if it were not installed. Your `database` queues use Laravel's own `DatabaseQueue`, and failed jobs go to Laravel's own failed-job provider. Orrery registers no queue, job or worker listener, stamps no payload, writes no heartbeat, reads no signal, defers nothing to a commit and does not schedule `orrery:tick`. Web requests, dispatches and workers open no connection to the package tables. The migrations still load, so `php artisan migrate` works.
- **The dashboard** answers every page and API request with 503 and the text "Orrery is disabled (ORRERY_ENABLED=false).". The API body is `{"error": "orrery_disabled", "message": …}`. The access check comes first: a visitor without access still gets 401 or 403. `/orrery/api/health` also answers 503.
- **The commands** `orrery:tick`, `orrery:retry`, `orrery:forget`, `orrery:clear`, `orrery:clear-metrics`, `orrery:pause`, `orrery:terminate`, `orrery:reconcile` and `orrery:deployed` refuse with that text and exit 1. If you schedule `orrery:tick` yourself (`orrery.schedule` false), that line fails every minute while Orrery is off: remove it or ignore the failures. `orrery:install` and the two commands of [the removal](upgrading.md#removing-orrery), `orrery:continue` and `orrery:resolve`, still run.
- **Pauses.** While Orrery is off, its global and connection pauses do not hold the workers: only Orrery reads them. A queue pause still holds, because it is Laravel's own pause. Run `orrery:continue` (it works while Orrery is off) or `queue:continue` to lift it.

To turn Orrery on again, set `ORRERY_ENABLED=true` (or remove the line), then run `php artisan config:cache` and `php artisan queue:restart`. Observation starts again from that moment. The dashboard then shows the time while Orrery was off as follows:

- A job pushed while Orrery was off has no row in the Jobs list until a worker reserves it. The queue's waiting count and wait time are read from the `jobs` table, so they include it at once.
- A job that ran or failed while Orrery was off has no row. A job that failed then is only in `failed_jobs`: the Failed list and the failure groups do not show it. `queue:failed` and `queue:retry` still see it, and `orrery:forget --all` also forgets failed records that have no row.
- A job that was waiting when you turned Orrery off, and then ran while it was off, keeps its row as waiting. When it is absent from `jobs` for max(`retry_after`, 600) seconds, the tick sets it to `unknown` ([Known limits](known-limits.md)), and the stuck-job notifications report it.
- The per-minute charts count the minutes while Orrery was off from the job rows, which do not exist, so those minutes show no jobs (zero), not unknown. To show them as unknown, run `php artisan orrery:clear-metrics` right after you turn Orrery on: every earlier minute then reads as unknown, also the minutes before Orrery was off.
- A worker shows on the Workers screen again after it restarts with Orrery on.
- A retry or forget that was in progress waits, and the first tick after you turn Orrery on continues it.

## Commands

| Command | What it does |
| --- | --- |
| `orrery:install` | Publishes the configuration, the provider and the migration, and writes the fence migrations. |
| `orrery:install --fence` | Writes only the missing fence migrations. |
| `orrery:install --indexes [--timeout=] [--connection=]` | Builds the deferred indexes on an existing install. |
| `orrery:tick` | The maintenance run. The scheduler runs it every minute. It always exits 0 and records each failed phase in the health events. |
| `orrery:pause`, `orrery:continue`, `orrery:terminate` | The controls. Options: `--connection=`, `--queue=`. |
| `orrery:retry {uuid}`, `orrery:retry --all [scope] [--force]` | Retries one job's current failure, or a bulk run. Scope options: `--connection`, `--queue`, `--class`, `--tag`, `--batch`, `--key` with `--from` and `--to`. |
| `orrery:forget {uuid}` | Forgets one job's current failure through the ledger. |
| `orrery:forget --all [--connection=] [--queue=]` | Forgets every failed record of the scope, up to the largest id at its start: a mirrored record only when its row is bound to it, a record without a mirror row by its exact `id` and `uuid`. `--queue` alone matches that queue name on every connection. It first prints the number of records (at most; it stops at the last record that existed when it counted) and the scope, and asks for a confirmation. `--force` skips it. Without `--force` it forgets nothing when the input is not interactive (`--no-interaction`) or STDIN is not a terminal (a pipe could answer the question). It prints the deleted, provider-only and skipped counts, and exits 1 when it skipped a record. A uuid together with `--all` (also an empty one), and a scope option given with an empty value, are refused (exit 1). |
| `orrery:resolve {id}`, `orrery:resolve --all` | Runs the pending step of an operation that waits for an operator. It exits 1 when an operation stays open after its step, for example one whose outcome is decided only after its deadline. |
| `orrery:reconcile [--connection=]` | Checks the mirror rows of waiting, reserved and failed jobs against the queue and failed-job tables, also on an SQLite file that the tick does not read. Without `--connection` it also checks the waiting and reserved rows of queue connections that are no longer configured. It exits 1 and names what it did not check when a read of those tables fails. When its time share for the failed jobs ends first, it says so and exits 0: run it again to continue. |
| `orrery:clear [connection] [--queue=] [--force]` | Deletes the waiting (unreserved) jobs of one queue and their mirror rows. Unlike `queue:clear`, it keeps reserved jobs. An empty connection or `--queue=` is refused. |
| `orrery:clear-metrics` | Deletes the per-minute counts. The queue state stays, and every earlier minute reads as unknown. |
| `orrery:deployed [--label=] [--window=600]` | Records a deploy marker. |

Every command except `orrery:tick` exits non-zero when its requested work did not happen. While Orrery is off (`ORRERY_ENABLED=false`), the commands that act refuse and exit 1 (see [Turning Orrery off](#turning-orrery-off)). `orrery:clear` also deletes the mirror rows of waiting bulk jobs whose id the package has not resolved yet, on every connection name of the same jobs table; a bulk push whose mirror rows are written after `orrery:clear` ran keeps those rows. The queue name is compared byte by byte: `high` never clears `HIGH`.
