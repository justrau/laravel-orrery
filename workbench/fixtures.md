# Workbench fixtures

`composer build` creates `workbench/database/database.sqlite` and seeds it with `Workbench\Database\Seeders\DatabaseSeeder`. This file lists every seeded state and its expected count.

## How the build works

`composer build` runs `php workbench/bin/testbench workbench:build` and then `workbench/check.php`. The build has four steps:

1. `workbench:drop-database` deletes the guarded file, the package's sibling mirror file `database.orrery.sqlite` next to it, and their `-wal`, `-shm` and `-journal` files. So every build starts from no database. The Workbench build ignores the exit code of each step. If a later step fails, the file is missing or empty, and the check fails. The counts of an earlier build can never pass.
2. `create-sqlite-db` creates the empty file.
3. `cache:clear` empties the file cache store in `workbench/storage/framework/cache/data`. A new database file does not empty it, so without this step a `queue:pause` flag or a `queue:restart` time of an earlier session would survive the build.
4. `migrate-fresh --force --seed` runs the migrations and the seeder. The package migration installs the package tables in the sibling file `workbench/database/database.orrery.sqlite`: `orrery.database` is empty and the default connection is a SQLite file, so the package never writes its mirror into the application's file. `workbench/check.php` attaches the sibling as `orrery`, so the unqualified package table names below read it.

`WorkbenchServiceProvider` forces the `local` environment, so an inherited `APP_ENV=production` cannot make `migrate:fresh` ask for a confirmation. It also pins every storage and service to an owned, local implementation: the SQLite file, the file cache store (in `workbench/storage/framework/cache/data`), database sessions, the file maintenance driver, the log mailer and the null broadcaster. The application storage path is `workbench/storage`, whatever `LARAVEL_STORAGE_PATH` says. The maintenance file, the compiled views, the local disk root, the session files directory and the log (`workbench/storage/logs/workbench.log`) are all inside it. `php workbench/bin/testbench workbench:ownership` uses each one once and prints where it resolves.

### Why the cache store is the file store

The dashboard reads Laravel's own pause state (`queue:pause`) through the default cache store. A `database` cache store on the Workbench's SQLite file is a host file for the package (D17): a `DatabaseStore` read deletes expired keys, so the package never reads that store automatically. Every `/api/workload` row then has `native_unknown: true`, and the dashboard shows each queue's pause state as unknown and the attention badge as "?". That is the correct answer for such an application, not a fault. The Workbench uses the file store, so the dashboard reads the pause state and a `php workbench/bin/testbench queue:pause database:default` shows as "Paused (queue:pause)". To see the unknown state, an application must put its cache in a `database` store on its own SQLite file; the Workbench has no switch for it. `WorkbenchSeederTest::test_the_workbench_cache_store_lets_the_dashboard_read_laravels_pause_state` checks the store.

In the strict-auth mode (`WORKBENCH_AUTH=strict`, see "The authorization modes") the provider forces the `production` environment instead of `local`, and the dashboard's sessions use the `file` driver in `workbench/storage/framework/sessions` instead of the database.

## The Workbench CLI launcher

Start every Workbench command with `php workbench/bin/testbench <command>`. The Composer scripts `prepare` (and so `post-autoload-dump`), `build`, `serve` and `workbench:worker` do this. The launcher pins the five bootstrap cache paths (`APP_SERVICES_CACHE`, `APP_PACKAGES_CACHE`, `APP_CONFIG_CACHE`, `APP_ROUTES_CACHE` and `APP_EVENTS_CACHE`) to `workbench/storage/bootstrap-cache` before Testbench starts. Laravel reads and writes the package and services manifests before any service provider runs, so a provider cannot keep them inside the Workbench. `WorkbenchBootstrapCache` lists when each path is used.

The launcher also makes the vendor link of Testbench's skeleton (`vendor/orchestra/testbench-core/laravel/vendor`) before Testbench starts, when Testbench would not accept what is there. Without that link, Testbench makes the link for each run and removes it at the end. It also deletes the skeleton's default package manifest, which ordinary package tests use, and the removal at the end writes the owned manifests again. `WorkbenchSkeleton` names the Testbench source lines. The launcher refuses a directory or a file at that path, also a directory with the same `autoload.php`: Testbench schedules the removal at the end of the run for every path that is not a link. Launchers check and make the link under one lock, `vendor/orchestra/testbench-core/laravel.workbench-vendor-link.lock`, so one launcher never removes the link that another one made.

The web server child of `serve` (`php -S`) inherits the pinned values. PHP's built-in server does not put the process environment in `$_SERVER` or `$_ENV`, where Laravel reads four of the paths. So `testbench.yaml` also has the five paths, relative to Testbench's skeleton, and they name the same files.

A direct `vendor/bin/testbench` call bypasses the launcher. Testbench then writes the two manifests at the inherited paths (or at the skeleton defaults in `vendor/orchestra/testbench-core/laravel/bootstrap/cache`), and `WorkbenchServiceProvider` refuses to go on. The refusal cannot undo those writes. `composer workbench:check` does not use the launcher, because `workbench/check.php` never starts Laravel.

The launcher protects against inherited environment values. It trusts the checkout. The refusal checks the real path of the directory that holds each cache file, not the file itself. So it does not refuse a symbolic link at `workbench/storage`, at `workbench/storage/bootstrap-cache`, or as one cache file in that directory, and Laravel follows such a link. Only the developer writes inside the checkout. The SQLite path guard is stricter and refuses links, because `DB_DATABASE` comes from the environment.

## The authorization modes

The Workbench has two authorization modes. `WorkbenchAuth` holds both, and `WorkbenchServiceProvider` reads the mode once per process from `WORKBENCH_AUTH`.

- **Default mode** (`WORKBENCH_AUTH` unset or empty): the `local` environment, database sessions, and an allow-all `viewOrrery` gate. The package lets every visitor operate in `local`. `composer serve` starts it.
- **Strict mode** (`WORKBENCH_AUTH=strict`): the `production` environment, so the package's `local` rule gives nobody access. Sessions use the `file` driver in `workbench/storage/framework/sessions`, the `web` group checks CSRF tokens, and `app.debug` is false. Start it with `composer serve:strict`, or with `WORKBENCH_AUTH=strict composer serve` (the build and the web server both run in the strict mode; the build passes `--force`, so `migrate:fresh` never asks).
- Any other value (for example `Strict` or `yes`) stops every Workbench command with "The Workbench refuses WORKBENCH_AUTH=[…]", so a typo never falls back to the allow-all mode.

The strict mode adds a Workbench-only login form at `/login` (the package has no login; a real application uses its own). `POST /login` signs a user in and opens `/orrery`; `POST /logout` signs out. Every build seeds three users in both modes, each with the password `password`:

| User | `viewOrrery` | `operateOrrery` | What the dashboard does |
| --- | --- | --- | --- |
| `viewer@example.com` | yes | no | Reads work; every write answers 403 `{"error": "forbidden_action"}`, and the SPA hides the write controls (`canOperate` false). |
| `operator@example.com` | yes | yes | Reads and writes work. The actor of its writes is its email. |
| `denied@example.com` | no | no | Every API route answers 403 `{"error": "forbidden"}`; the page answers a plain 403. |
| no user (a guest) | no | no | Every API route answers 401 `{"error": "unauthenticated"}`; the page answers a plain 401. |

To see the session-expired state, sign in, open `/orrery`, then log out in another tab (`/login`, "Log out"): the next poll gets 401. `WorkbenchStrictAuthTest` checks these cases through HTTP with sessions and CSRF tokens, and `WorkbenchServeModesTest` checks that the CLI and the web server child of `serve` both run the strict mode.

## The rollback-mode switch

`ORRERY_TEST_SQLITE_JOURNAL=delete` serves the dashboard with the SQLite file in rollback-journal mode, so the package makes no automatic access to it (D18) and the dashboard shows its unknown states (for example, the backlog of the queues reads unknown), and `/api/health` lists the file under `sqlite_warnings`. Start it with `ORRERY_TEST_SQLITE_JOURNAL=delete composer serve`; it combines with `WORKBENCH_AUTH=strict`.

- Only the web server uses the rollback mode: its first connection switches the file out of WAL. The build, `composer workbench:worker` and every other command keep WAL, so the build's counts hold; the next command switches the file back to WAL. Do not run a worker while the dashboard serves in rollback mode.
- Unset or empty means WAL. Any other value (for example `rollback`) stops every Workbench command with "The Workbench refuses ORRERY_TEST_SQLITE_JOURNAL=[…]".
- `WorkbenchServeModesTest` checks the switch, the WAL of the CLI and the warning of the served health read.

## The display zone

`ORRERY_DISPLAY_TIMEZONE=Asia/Tokyo composer serve` serves the dashboard with `orrery.display_timezone` set: every time shows in that zone with its abbreviation. The provider reads the variable from the process environment, because the package's `env()` does not see it in the web server child of `serve`. Unset or empty leaves the browser's zone; an invalid zone is ignored by the package (`displayTimezone` is null). `WorkbenchServeModesTest` checks that the served page carries the zone.

## The Content Security Policy

In both modes the Workbench serves the dashboard under the enforced policy of docs/security.md: `WorkbenchServiceProvider` adds `Workbench\App\Http\Middleware\ContentSecurityPolicy` to `orrery.middleware`. Each request gets a new nonce through `Vite::useCspNonce()`, which the package puts on its inlined style and script elements, and the header `Content-Security-Policy` (no `unsafe-inline`, no `unsafe-eval`, `frame-ancestors 'none'`). The login form of the strict mode is not a dashboard route and has no policy. `WorkbenchContentSecurityPolicyTest` checks the header, the nonce and the copy of the policy in docs/security.md. A browser check records violations with a `securitypolicyviolation` listener that runs before the page's scripts.

## Running the Workbench tests

Stop the Workbench dev server (`composer serve`) and the Workbench worker (`composer workbench:worker`) of this checkout before you run the `workbench` test group. This also applies to every run that includes that group, for example the full suite and `composer test:all`. The tests and a running server use the same `workbench/storage` directory and the same bootstrap cache directory, `workbench/storage/bootstrap-cache`. No lock coordinates them:

- `WorkbenchBootstrapCacheTest` runs `optimize`, which writes the config, routes and events caches there, and then `optimize:clear`, which deletes them and the two manifests. While the routes cache exists, the package skips its routes, so a running server returns 404 for the dashboard.
- A running server writes the two manifests again, while the test requires them to be absent after `optimize:clear`.

When a command of the cache test fails, or the test throws, a `finally` block removes the config, routes and events caches again. When the PHPUnit process is killed, that block does not run. Then clear the caches through the launcher:

```bash
php workbench/bin/testbench route:clear
php workbench/bin/testbench config:clear
php workbench/bin/testbench event:clear
```

`route:clear` removes the routes cache, so the dashboard routes load again. The package and services manifests need no action: the next Workbench command writes them again.

## How the seeder works

The seeder uses the real code paths of the package, with two kinds of exceptions that the steps below mark:

- **Clock moved.** States that need time to pass (an expired reservation, an observed gap, an absent job, the attempts of a rate-limited job) run with Laravel's clock moved into the past (`Carbon::setTestNow()`, which the package's clock follows) and then act at the real time. Every write still goes through the package's real code paths.
- **Written directly.** One state cannot happen in one healthy process: an operation that waits for an operator. The retry service claims it, the seeder stops the retry after its claim (the hook point `retry.after_claim`, as a killed request would stop it), and then marks it with the package's `Operations::await()`, as the runner marks it when its next step writes the host SQLite file. That mark is the only package-table write of the seeder.

The rest:

- Jobs go through the swapped `database` queue connector (`Justrau\Orrery\DatabaseQueue`).
- The real `queue:work` command works them, through `Artisan::call()`. So Laravel logs failed jobs in `failed_jobs`.
- One `orrery:tick` at the end counts the minutes into `orrery_samples` and samples the queues (`orrery:snapshot` is gone).
- The package's `MonitorTag` job monitors the tags, and the package's retry service (`RetryService::retry()`, source `cli`) retries a failed job.

The steps, in order:

0. Seed the three users of the strict-auth mode (see "The authorization modes").
0b. Run the stuck-incident phase once (`StuckIncidentsPhase`) before any job: its first run writes the settings row `incidents_since`, as on an install whose scheduler runs from the start, so the `unknown` row of step 15 gets an incident at step 17. It finds no row and dispatches nothing.
1. Monitor the tags `workbench:featured`, `campaign:spring` and `workbench:empty`. No job has `workbench:empty`: an empty tag view.
2. Push 48 `QuickJob`, 12 `TaggedJob` (tags `workbench:featured` and `customer:<n>`), 3 `SilencedJob` and 1 `ReleasesOnceJob` onto `database:default`, then work the queue. `ReleasesOnceJob` releases itself on its first attempt and completes on its second.
3. Push 60 `FailingJob` onto `database:default` and work the queue. Every tenth one (6 jobs) has the tag `workbench:featured`.
4. Dispatch the batch `Workbench import` with 5 `BatchMemberJob`. Member 3 fails. The batch allows failures. Work the queue.
5. Push 5 `DelayedJob` onto `database:default` with a one-hour delay, and 5 `QuickJob` onto `database:idle`. No worker reads them, so they stay pending.
6. Push 3 `QuickJob` onto the second queue connection `database-secondary` (queue `reports`), then work that queue.
7. Retry the current occurrence of the oldest failed `FailingJob` (#1) through `RetryService::retry()` with the source `cli`, and work the queue. A cache flag lets the retried job complete.
8. Failures across queues and classes: 210 `FlakyApiJob` on `database:emails`, all with the same message (one failure group of 210, above the 200 members of one selection); 300 `CampaignJob` on `database:campaigns` with the monitored tag `campaign:spring`, every hundredth one failing (300 tagged jobs, 3 failed); 1 `TimedOutJob` on `database:timeouts` (`timeout = 1`, `failOnTimeout = true`). Work each queue. A real timeout makes the worker kill its own process (SIGKILL), so `TimedOutJob` throws the exception that the timeout handler builds (`TimeoutExceededException::forJob()`), and the worker fails it through its normal exception path; Laravel's `JobTimedOut` event does not fire, so the row counts no timeout.
9. The redaction canaries (below): 2 `SecretsJob` on `database:secrets`, pushed with a visible and a hidden Context value and a payload hook that adds the top-level key `workbench_api_key`, then worked (both fail); and a queued closure on `database:payloads` that captures a canary (it stays pending).
10. Long values: 1 `LongValuesJob` on a queue whose name has 255 characters (`workbench-long-queue-abcdefghij…`). Its display name has 512 characters (a class name that long needs a file name longer than macOS allows; the mirror row keeps the first 255), and it fails with a message of 64 KB on one line. Work that queue.
11. The batch `Workbench partial import` with 6 `BatchMemberJob` on `database:partial` and the option `webhook_url` (a canary). Member 3 fails. Work 3 jobs (`--max-jobs=3`): 2 completed, 1 failed and 3 pending members.
12. Push a `LargePayloadJob` (a payload over 1 MB), an `EncryptedJob` (`ShouldBeEncrypted`, so its command is encrypted) and a queued Slack notification `WorkbenchAlert` to an anonymous webhook route (a canary) onto `database:payloads`. They stay pending.
13. Jobs that keep being released, 21 attempts each (above the stuck thresholds of 20): a `ThrottledJob` on `database:throttled` behind Laravel's `RateLimited` middleware (one job per hour; a first `ThrottledJob` takes that slot and completes; attempt limit 25; **clock moved**: each attempt two seconds later, from 60 seconds before the real time; so the first job's completion stays in the minutes that the tick counts, also when a minute boundary passes before step 17); an `UnlimitedReleaseJob` on `database:unlimited` (`tries = 0`); a `DeadlineReleaseJob` on `database:deadline` (`maxTries = 1` and a `retryUntil` one day ahead). All three stay pending.
14. Reservations (**clock moved**; the `database` connection's `retry_after` is 900 s): a `QuickJob` on `database:stuck-expired` reserved 1,000 s before the real time, whose lease expired; and a `QuickJob` on `database:stuck-gap` reserved 2,000 s before and reserved again 300 s before the real time, a reclaim whose first attempt has no observed outcome (`observed_gap` 1). Its lease has not expired: the lease of a row is its own connection's `retry_after` (`WorkerLiveness::retryAfter()`), 900 s, and the second reservation is 300 s old. `database-secondary` (`retry_after` 90 s) shares the jobs table but works the queue `reports`, so it does not shorten the lease.
15. A `QuickJob` on `database:vanished`, deleted from the jobs table by Laravel's own `queue:clear` (an application action outside the package). The reconciliation records its absence 1,000 s before the real time (**clock moved**) and marks it `unknown` (reason `absent`) at the real time, after its grace of 900 s.
16. A dashboard retry of `FailingJob` #2 (actor `operator@example.com`) held by an operation that awaits an operator (`awaiting_reason = sqlite_host_file`; **written directly**: the awaiting mark). The row is `retrying`, its failed record stays, and the operation is `claimed`. "Resolve" (or `php artisan orrery:resolve <id>`) in the default mode runs its next step.
16b. A finished bulk run: 2 `FailingJob` (#401, #402) fail on `database:bulk-retry`; the package's bulk run engine (`Http\Bulk\BulkRun`) freezes a retry of the failed tab's filters `connection = database`, `queue = bulk-retry` and runs it to its end (actor `operator@example.com`, source `bulk`); a cache flag lets the retried copies complete in cycle 2 when the queue is worked. The run leaves one `orrery_bulk_runs` row (2 claims, 2 accepted) and 2 `done` operations with its `bulk_ref`.
17. Run `orrery:tick` once. Its phase `stuck_incidents` creates the stuck incidents of the rows of steps 13 to 15 and dispatches each one once as `StuckJobDetected` (the Workbench has no listener for it: each delivery succeeds). The Workbench sets no notification route, so the tick's last phase, `stuck_notifications`, sends no stuck-job notification and ends each incident's notifications at once (`notified` `{}`, `notify_next_at` null).

## The redaction canaries

Every canary starts with `orrery-canary-` (`Workbench\App\Support\Canaries`), so a search of a response body or of the screen finds every one that the dashboard shows unredacted. None of them is a real credential. The stored data keeps them: redaction applies only to what the dashboard returns.

| Position | Where | Canary |
| --- | --- | --- |
| A top-level payload key | `workbench_api_key` of each `SecretsJob` payload | `orrery-canary-payload-top-level-key` |
| Public, protected and private properties | `SecretsJob::$password`, `$apiToken`, `$clientSecret` | `orrery-canary-public-password`, `orrery-canary-protected-api-token`, `orrery-canary-private-client-secret` |
| An array with the key forms | `SecretsJob::$credentials`: `stripe.secret_key`, `stripe.stripe_secret`, `db_password`, `aws_secret_access_key`, `auth_token`, `webhook_secret` (and `classname`, which is not a secret) | `orrery-canary-array-secret-key`, `orrery-canary-stripe-secret`, `orrery-canary-db-password`, `orrery-canary-aws-secret-access-key`, `orrery-canary-auth-token`, `orrery-canary-webhook-secret` |
| A header record | `SecretsJob::$headers`: `{name: Authorization, value: Bearer …}` | `orrery-canary-header-bearer` |
| A Slack webhook URL | `SecretsJob::$webhook_url` | `…/orrery-canary-webhook` |
| A string over 64 KB, secret at its end | `SecretsJob::$longNote` (`… api_key=…`) | `orrery-canary-long-string-tail` |
| A URL password at the end of a string over 64 KB (it starts at byte 65,500; its `@` comes after byte 65,536) | `SecretsJob::$longUrlNote` | `orrery-canary-long-url-password-tail` |
| Context, visible and hidden | `api_key` and the hidden `session_token` of each `SecretsJob` payload | `orrery-canary-context-visible`, `orrery-canary-context-hidden` |
| The exception message (and the history entries and the failure group) | `Authorization: Bearer …`, `password=…`, a URL password, and `token=…` across the 500-character cut of the first line (characters 490 to 527) | `orrery-canary-exception-bearer`, `orrery-canary-exception-password`, `orrery-canary-url-password`, `orrery-canary-first-line-cut-straddle` |
| The context of the exception (`orrery_jobs.context` and the history context) | `api_token`, and a header record `{name: Authorization, value: Bearer …}` | `orrery-canary-exception-context`, `orrery-canary-exception-context-header` |
| A signed closure capture | the pending closure on `database:payloads` | `orrery-canary-closure-capture` |
| A Slack route of a notification (`AnonymousNotifiable`) | the pending `WorkbenchAlert` on `database:payloads` | `…/orrery-canary-notification-route` |
| An encrypted command | `EncryptedJob::$note` (encrypted in the payload) | `orrery-canary-encrypted-command` |
| A batch option | `webhook_url` of `Workbench partial import` | `…/orrery-canary-batch-option` |

## Empty, unknown and error states

- An empty tag: the monitored tag `workbench:empty` has no jobs.
- An empty list: `database:reports` has no failed, pending or running job; a filter on a queue or class without rows gives "no rows match".
- Unknown values: the `unknown` row of step 15; the held row of step 16 (no Retry or Forget).
- The D18 unknown states (the backlog, the batches and the reconciliation of the host file are unknown): serve in rollback mode (see "The rollback-mode switch").
- The strict-auth mode gives the 401 and 403 states (see "The authorization modes").

## Current package behaviour in these counts

Some counts show package behaviour that a later fix can change. When a fix changes a count, update the table below in the same commit.

- **Batch jobs have their mirror rows before work.** `Batch::add()` pushes with `DatabaseQueue::bulk()`, which does not call `pushToDatabase()` and raises no `JobQueued`. The swapped `bulk()` observes the insert itself: each member gets a `pending` row (rule P2) before a worker reserves it, and the resolution on the observer session writes its `jobs.id`. The seeder works the batch at once, so the counts do not change. The payload callback classifies each batch payload, so it has the `type`, `tags`, `tagsTruncated`, `silenced`, `pushedAt` and `orreryGen` keys.
- **Every tag of every job has a tag row.** The 12 `TaggedJob` have two tags each (`workbench:featured` and `customer:<n>`), and 6 `FailingJob` have `workbench:featured`: 30 tag rows, 18 of them under `workbench:featured`. The 300 `CampaignJob` add 300 rows under `campaign:spring`, and its 3 failures 3 failed tag rows: 330 tag rows and 9 failed tag rows.
- **The per-minute samples.** The tick has no frontier yet, so it counts the five minutes before its own minute (five `total` rows; their counts depend on the clock: the seeded jobs of those minutes, the throttled jobs of step 13 among them) and sets the frontier M to its minute. The dashboard counts the seeded jobs of later minutes from the mirror rows (the read rule). The two queue connections share one jobs table, so the samples name the jobs of `database-secondary` under the primary alias `database` (`database:reports`). The queue state of the tick's minute has one row per measured queue: the 9 queues with rows in the jobs table, and, when the seeding crossed a minute boundary, the queues of the samples of its earlier minutes (the unknown-queue sources of `WaitTime`). The 9 are: `database:default` (no ready job: the delayed jobs are not ready), `database:idle` (5 ready jobs), `partial`, `payloads`, `throttled`, `unlimited`, `deadline`, `stuck-gap` and `stuck-expired`. The exact counts per minute depend on the clock, so the table checks only the rows that do not.
- **A retry keeps the uuid.** The retry service publishes the failed payload again under the same uuid, as cycle 2 of the same mirror row. So the retried `FailingJob` row is `completed` in cycle 2, with one frozen summary of cycle 1 and one `retried` history row, and no second row exists. The failer (`database-uuids`) uses the queue's own connection (mode `in_tx`), so the publication deletes the failed record inside its queue transaction: of the 61 failures of steps 3 and 4, `failed_jobs` keeps 60 rows. The held retry of step 16 has not published, so its record stays: `failed_jobs` has 278 rows and 277 failed mirror rows. The retry of step 7 leaves one `done` operation in the ledger and one `published` fence row in the queue's file (`main.orrery_fences`, from `workbench/database/migrations`, as `orrery:install --fence` writes it). The bulk run of step 16b adds two of each, and its two records are deleted by their publications too, so `failed_jobs` keeps its count.
- **The stuck incidents.** The tick of step 17 creates 8 incidents, one of each signal at least, each delivered by its first claim (`attempts_made` 1, `delivered_at` set): `reservation_expired` for `stuck-expired` (its reservation 1,000 s before is past its own connection's lease of 900 s; `stuck-gap`, reserved again 300 s before, is inside it, so the shorter `retry_after` of `database-secondary` on the same jobs table raises nothing); `outcome_not_observed` for `stuck-gap`; `repeated_releases` for `throttled`, `unlimited` and `deadline`; `attempts_not_capped` for `unlimited` (rule `none`) and `deadline` (rule `deadline`; the attempt limit of `throttled`, 25, caps it); and `unknown` (reason `absent`) for `vanished`.
- **Bound records.** Every failed row has `provider_id` and `provider_target`: `queue:work` logs each failure through the package's `CapturingUuidFailedJobProvider`, which binds the id of its own insert to the occurrence.

## Expected counts

`workbench/check.php` (the last step of `composer build`, and `composer workbench:check`) and `tests/Feature/WorkbenchSeederTest.php` read this table. Each row is one check: a label, one `SELECT` statement in backticks that returns one number, and the expected number. The counts hold directly after `composer build`. `composer workbench:worker` pushes one more job, so run `composer build` again before `composer workbench:check`.

<!-- manifest:begin -->
| Check | Query | Expected |
| --- | --- | --- |
| Required tables exist | `SELECT (SELECT COUNT(*) FROM main.sqlite_master WHERE type = 'table' AND name IN ('jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'orrery_fences')) + (SELECT COUNT(*) FROM orrery.sqlite_master WHERE type = 'table' AND name IN ('orrery_jobs', 'orrery_job_tags', 'orrery_failed_tags', 'orrery_job_history', 'orrery_cycle_summaries', 'orrery_operations', 'orrery_fences', 'orrery_monitored_tags', 'orrery_settings', 'orrery_worker_heartbeats', 'orrery_health_events', 'orrery_signals', 'orrery_markers', 'orrery_samples', 'orrery_schema', 'orrery_incidents'))` | 22 |
| Workbench users of the strict-auth mode | `SELECT COUNT(*) FROM users WHERE email IN ('viewer@example.com', 'operator@example.com', 'denied@example.com')` | 3 |
| Mirror rows | `SELECT COUNT(*) FROM orrery_jobs` | 675 |
| Completed jobs, not silenced | `SELECT COUNT(*) FROM orrery_jobs WHERE status = 'completed' AND silenced = 0` | 371 |
| Completed jobs, silenced | `SELECT COUNT(*) FROM orrery_jobs WHERE status = 'completed' AND silenced = 1` | 3 |
| Failed jobs | `SELECT COUNT(*) FROM orrery_jobs WHERE status = 'failed'` | 277 |
| Failed jobs bound to their record | `SELECT COUNT(*) FROM orrery_jobs WHERE status = 'failed' AND provider_id IS NOT NULL AND provider_target IS NOT NULL` | 277 |
| Pending jobs | `SELECT COUNT(*) FROM orrery_jobs WHERE status = 'pending'` | 20 |
| Reserved jobs | `SELECT COUNT(*) FROM orrery_jobs WHERE status = 'reserved'` | 2 |
| Completed QuickJob and TaggedJob on database:default | `SELECT COUNT(*) FROM orrery_jobs WHERE connection = 'database' AND queue = 'default' AND status = 'completed' AND name IN ('Workbench\App\Jobs\QuickJob', 'Workbench\App\Jobs\TaggedJob')` | 60 |
| Completed SilencedJob | `SELECT COUNT(*) FROM orrery_jobs WHERE name = 'Workbench\App\Jobs\SilencedJob' AND status = 'completed' AND silenced = 1` | 3 |
| Completed ReleasesOnceJob | `SELECT COUNT(*) FROM orrery_jobs WHERE name = 'Workbench\App\Jobs\ReleasesOnceJob' AND status = 'completed'` | 1 |
| Failed FailingJob | `SELECT COUNT(*) FROM orrery_jobs WHERE name = 'Workbench\App\Jobs\FailingJob' AND status = 'failed'` | 58 |
| Retried FailingJob, completed in cycle 2 | `SELECT COUNT(*) FROM orrery_jobs WHERE name = 'Workbench\App\Jobs\FailingJob' AND status = 'completed' AND cycle = 2 AND retried_count = 1 AND cycle_source = 'cli'` | 1 |
| Cycle summaries | `SELECT COUNT(*) FROM orrery_cycle_summaries` | 3 |
| Frozen failed cycle 1 of the retried jobs (step 7 and the bulk run) | `SELECT COUNT(*) FROM orrery_cycle_summaries s JOIN orrery_jobs j ON j.uuid = s.job_uuid WHERE s.cycle = 1 AND s.outcome = 'failed' AND j.cycle = 2` | 3 |
| Retried history rows | `SELECT COUNT(*) FROM orrery_job_history WHERE event = 'retried'` | 3 |
| Done retry operations | `SELECT COUNT(*) FROM orrery_operations WHERE action = 'retry' AND state = 'done' AND source = 'cli' AND mode = 'in_tx'` | 1 |
| Published fence rows in the queue's file | `SELECT COUNT(*) FROM main.orrery_fences WHERE slot = 'publish' AND state = 'published' AND job_id IS NOT NULL` | 3 |
| Rows that an operation holds | `SELECT COUNT(*) FROM orrery_jobs WHERE operation_id IS NOT NULL` | 1 |
| Pending DelayedJob on database:default | `SELECT COUNT(*) FROM orrery_jobs WHERE name = 'Workbench\App\Jobs\DelayedJob' AND connection = 'database' AND queue = 'default' AND status = 'pending'` | 5 |
| Pending jobs on database:idle | `SELECT COUNT(*) FROM orrery_jobs WHERE connection = 'database' AND queue = 'idle' AND status = 'pending'` | 5 |
| Completed jobs on database-secondary:reports | `SELECT COUNT(*) FROM orrery_jobs WHERE connection = 'database-secondary' AND queue = 'reports' AND status = 'completed'` | 3 |
| Completed batch members | `SELECT COUNT(*) FROM orrery_jobs WHERE name = 'Workbench\App\Jobs\BatchMemberJob' AND status = 'completed'` | 6 |
| Failed batch members | `SELECT COUNT(*) FROM orrery_jobs WHERE name = 'Workbench\App\Jobs\BatchMemberJob' AND status = 'failed'` | 2 |
| Rows in Laravel's jobs table | `SELECT COUNT(*) FROM jobs` | 22 |
| Rows in Laravel's jobs table on queue idle | `SELECT COUNT(*) FROM jobs WHERE queue = 'idle'` | 5 |
| Rows in failed_jobs | `SELECT COUNT(*) FROM failed_jobs` | 278 |
| failed_jobs rows with a failed mirror row | `SELECT COUNT(*) FROM failed_jobs f JOIN orrery_jobs j ON j.uuid = f.uuid WHERE j.status = 'failed'` | 277 |
| failed_jobs rows whose key is the mirror's provider_id (the held row keeps its record) | `SELECT COUNT(*) FROM failed_jobs f JOIN orrery_jobs j ON j.uuid = f.uuid WHERE j.provider_id = f.id` | 278 |
| Batches | `SELECT COUNT(*) FROM job_batches` | 2 |
| Batch with one failed member and one pending count | `SELECT COUNT(*) FROM job_batches WHERE name = 'Workbench import' AND total_jobs = 5 AND pending_jobs = 1 AND failed_jobs = 1 AND json_array_length(failed_job_ids) = 1 AND finished_at IS NULL AND cancelled_at IS NULL` | 1 |
| Batch failed id is a failed BatchMemberJob in failed_jobs | `SELECT COUNT(*) FROM job_batches b JOIN failed_jobs f ON f.uuid = json_extract(b.failed_job_ids, '$[0]') JOIN orrery_jobs j ON j.uuid = f.uuid WHERE j.status = 'failed' AND j.name = 'Workbench\App\Jobs\BatchMemberJob'` | 2 |
| Monitored tags | `SELECT COUNT(*) FROM orrery_monitored_tags` | 3 |
| Monitored tag workbench:featured | `SELECT COUNT(*) FROM orrery_monitored_tags WHERE tag = 'workbench:featured'` | 1 |
| Jobs under workbench:featured | `SELECT COUNT(*) FROM orrery_job_tags WHERE tag = 'workbench:featured'` | 18 |
| Failed jobs under failed:workbench:featured | `SELECT COUNT(*) FROM orrery_failed_tags WHERE tag = 'workbench:featured'` | 6 |
| Tag rows | `SELECT COUNT(*) FROM orrery_job_tags` | 330 |
| Failed tag rows | `SELECT COUNT(*) FROM orrery_failed_tags` | 9 |
| Retired metric tables | `SELECT COUNT(*) FROM orrery.sqlite_master WHERE type = 'table' AND name IN ('orrery_metric_counters', 'orrery_metric_snapshots', 'orrery_queue_samples')` | 0 |
| Covered minutes of the first count | `SELECT COUNT(*) FROM orrery_samples WHERE scope = 'total'` | 5 |
| Covered minutes whose arrivals are known (the arrivals are a number, 0 for a quiet minute) | `SELECT COUNT(*) FROM orrery_samples WHERE scope = 'total' AND pushed IS NOT NULL` | 5 |
| The counts frontier M | `SELECT COUNT(*) FROM orrery_settings WHERE key = 'samples_counted_through'` | 1 |
| The frontier M at the tick's minute, after the covered minutes | `SELECT COUNT(*) FROM orrery_settings s WHERE s.key = 'samples_counted_through' AND CAST(s.value AS INTEGER) = (SELECT MAX(minute) + 60 FROM orrery_samples WHERE scope = 'total')` | 1 |
| Sampled queue states of the tick's minute for the 9 queues with rows in the jobs table (a seeding that crosses a minute boundary also samples the queues of its earlier minutes, so the other queues are not counted) | `SELECT COUNT(*) FROM orrery_samples WHERE scope = 'queue' AND over_limit IS NOT NULL AND connection = 'database' AND queue IN ('default', 'idle', 'partial', 'payloads', 'throttled', 'unlimited', 'deadline', 'stuck-gap', 'stuck-expired')` | 9 |
| Ready jobs of database:idle in its queue state | `SELECT ready FROM orrery_samples WHERE scope = 'queue' AND connection = 'database' AND queue = 'idle' AND over_limit IS NOT NULL` | 5 |
| Ready jobs of database:default in its queue state | `SELECT ready FROM orrery_samples WHERE scope = 'queue' AND connection = 'database' AND queue = 'default' AND over_limit IS NOT NULL` | 0 |
| Failed FlakyApiJob on database:emails | `SELECT COUNT(*) FROM orrery_jobs WHERE name = 'Workbench\App\Jobs\FlakyApiJob' AND connection = 'database' AND queue = 'emails' AND status = 'failed'` | 210 |
| One failure group for every FlakyApiJob failure (more than 200 members) | `SELECT COUNT(DISTINCT failure_key) FROM orrery_jobs WHERE name = 'Workbench\App\Jobs\FlakyApiJob' AND failure_key IS NOT NULL` | 1 |
| Queues with failed rows (default, emails, campaigns, timeouts, secrets, partial and the long queue) | `SELECT COUNT(DISTINCT queue) FROM orrery_jobs WHERE status = 'failed'` | 7 |
| Job names with failed rows | `SELECT COUNT(DISTINCT name) FROM orrery_jobs WHERE status = 'failed'` | 7 |
| Jobs under campaign:spring | `SELECT COUNT(*) FROM orrery_job_tags WHERE tag = 'campaign:spring'` | 300 |
| Failed jobs under campaign:spring | `SELECT COUNT(*) FROM orrery_failed_tags WHERE tag = 'campaign:spring'` | 3 |
| Monitored tag workbench:empty without jobs | `SELECT COUNT(*) FROM orrery_monitored_tags m WHERE m.tag = 'workbench:empty' AND NOT EXISTS (SELECT 1 FROM orrery_job_tags t WHERE t.tag = m.tag)` | 1 |
| Failed TimedOutJob with the timeout exception and failOnTimeout | `SELECT COUNT(*) FROM orrery_jobs WHERE name = 'Workbench\App\Jobs\TimedOutJob' AND status = 'failed' AND exception_class = 'Illuminate\Queue\TimeoutExceededException' AND timeout = 1 AND fail_on_timeout = 1` | 1 |
| Failed SecretsJob with the exception canary | `SELECT COUNT(*) FROM orrery_jobs WHERE name = 'Workbench\App\Jobs\SecretsJob' AND status = 'failed' AND exception LIKE '%orrery-canary-exception-bearer%'` | 2 |
| SecretsJob payloads with the command, payload-key and Context canaries | `SELECT COUNT(*) FROM orrery_jobs WHERE name = 'Workbench\App\Jobs\SecretsJob' AND payload LIKE '%orrery-canary-private-client-secret%' AND payload LIKE '%orrery-canary-long-string-tail%' AND payload LIKE '%orrery-canary-payload-top-level-key%' AND payload LIKE '%orrery-canary-context-visible%' AND payload LIKE '%orrery-canary-context-hidden%'` | 2 |
| History entries with the exception canary (an exception and a failed entry per SecretsJob) | `SELECT COUNT(*) FROM orrery_job_history WHERE event IN ('exception', 'failed') AND exception LIKE '%orrery-canary-exception-password%'` | 4 |
| Pending jobs that carry a canary outside the closure and the notification (the Context does not leak) | `SELECT COUNT(*) FROM jobs WHERE payload LIKE '%orrery-canary%' AND payload NOT LIKE '%CallQueuedClosure%' AND json_extract(payload, '$.displayName') <> 'Workbench\App\Notifications\WorkbenchAlert'` | 0 |
| SecretsJob rows with the exception context canaries | `SELECT COUNT(*) FROM orrery_jobs WHERE name = 'Workbench\App\Jobs\SecretsJob' AND context LIKE '%"orrery-canary-exception-context"%' AND context LIKE '%Bearer orrery-canary-exception-context-header%'` | 2 |
| History entries with the exception context canary (an exception and a failed entry per SecretsJob) | `SELECT COUNT(*) FROM orrery_job_history WHERE event IN ('exception', 'failed') AND context LIKE '%"orrery-canary-exception-context"%'` | 4 |
| SecretsJob rows with the first-line-cut, long-URL and webhook-secret canaries | `SELECT COUNT(*) FROM orrery_jobs WHERE name = 'Workbench\App\Jobs\SecretsJob' AND exception LIKE '%orrery-canary-first-line-cut-straddle%' AND payload LIKE '%orrery-canary-long-url-password-tail%' AND payload LIKE '%orrery-canary-webhook-secret%'` | 2 |
| Pending Slack notification with a canary route | `SELECT COUNT(*) FROM jobs WHERE json_extract(payload, '$.displayName') = 'Workbench\App\Notifications\WorkbenchAlert' AND queue = 'payloads' AND payload LIKE '%orrery-canary-notification-route%'` | 1 |
| Pending closure that captures a canary | `SELECT COUNT(*) FROM jobs WHERE payload LIKE '%CallQueuedClosure%' AND payload LIKE '%orrery-canary-closure-capture%'` | 1 |
| Pending encrypted job whose payload hides its canary | `SELECT COUNT(*) FROM jobs WHERE json_extract(payload, '$.displayName') = 'Workbench\App\Jobs\EncryptedJob' AND payload NOT LIKE '%orrery-canary%'` | 1 |
| Pending payload over 1 MB | `SELECT COUNT(*) FROM orrery_jobs WHERE name = 'Workbench\App\Jobs\LargePayloadJob' AND status = 'pending' AND payload_bytes > 1048576` | 1 |
| Failed job on a queue of 255 characters, with its name cut to 255 and a message over 64 KB | `SELECT COUNT(*) FROM orrery_jobs WHERE status = 'failed' AND length(queue) = 255 AND length(name) = 255 AND exception_bytes > 65536` | 1 |
| Its failed record with the display name of 512 characters | `SELECT COUNT(*) FROM failed_jobs WHERE length(queue) = 255 AND length(json_extract(payload, '$.displayName')) = 512` | 1 |
| Partial batch: completed, failed and pending members, a canary in its options | `SELECT COUNT(*) FROM job_batches WHERE name = 'Workbench partial import' AND total_jobs = 6 AND pending_jobs = 4 AND failed_jobs = 1 AND finished_at IS NULL AND options LIKE '%orrery-canary-batch-option%'` | 1 |
| Pending members of the partial batch | `SELECT COUNT(*) FROM orrery_jobs WHERE name = 'Workbench\App\Jobs\BatchMemberJob' AND queue = 'partial' AND status = 'pending'` | 3 |
| ThrottledJob released by RateLimited, attempt limit 25 | `SELECT COUNT(*) FROM orrery_jobs WHERE name = 'Workbench\App\Jobs\ThrottledJob' AND status = 'pending' AND attempts = 21 AND releases = 21 AND max_tries = 25` | 1 |
| UnlimitedReleaseJob with tries 0 | `SELECT COUNT(*) FROM orrery_jobs WHERE name = 'Workbench\App\Jobs\UnlimitedReleaseJob' AND status = 'pending' AND attempts = 21 AND releases = 21 AND max_tries = 0 AND retry_until IS NULL` | 1 |
| DeadlineReleaseJob with maxTries 1 and a retryUntil | `SELECT COUNT(*) FROM orrery_jobs WHERE name = 'Workbench\App\Jobs\DeadlineReleaseJob' AND status = 'pending' AND attempts = 21 AND releases = 21 AND max_tries = 1 AND retry_until IS NOT NULL` | 1 |
| Reserved job whose lease expired before the tick's minute (clock moved) | `SELECT COUNT(*) FROM orrery_jobs WHERE queue = 'stuck-expired' AND status = 'reserved' AND attempts = 1 AND observed_gap = 0 AND reserved_at <= (SELECT CAST(value AS INTEGER) FROM orrery_settings WHERE key = 'samples_counted_through') - 900` | 1 |
| Reclaimed job with an observed gap, reserved within the database connection's 900 s at the tick's minute (clock moved) | `SELECT COUNT(*) FROM orrery_jobs WHERE queue = 'stuck-gap' AND status = 'reserved' AND attempts = 2 AND reclaims = 1 AND observed_gap = 1 AND reserved_at > (SELECT CAST(value AS INTEGER) FROM orrery_settings WHERE key = 'samples_counted_through') - 900` | 1 |
| Unknown row of a job deleted outside the package (clock moved) | `SELECT COUNT(*) FROM orrery_jobs WHERE queue = 'vanished' AND status = 'unknown' AND unknown_reason = 'absent'` | 1 |
| Retrying row held by an operation that awaits an operator (the awaiting mark written directly) | `SELECT COUNT(*) FROM orrery_jobs j JOIN orrery_operations o ON o.id = j.operation_id WHERE j.status = 'retrying' AND j.name = 'Workbench\App\Jobs\FailingJob' AND o.action = 'retry' AND o.state = 'claimed' AND o.awaiting_reason = 'sqlite_host_file' AND o.source = 'dashboard' AND o.actor = 'operator@example.com'` | 1 |
| Retry operations | `SELECT COUNT(*) FROM orrery_operations` | 4 |
| The finished bulk run | `SELECT COUNT(*) FROM orrery_bulk_runs WHERE action = 'retry' AND total = 2 AND total_at_least = 0 AND confirmed_total IS NULL AND claimed = 2 AND accepted = 2 AND refused = 0 AND uncertain = 0 AND not_member = 0 AND actor = 'operator@example.com'` | 1 |
| Done operations of the bulk run | `SELECT COUNT(*) FROM orrery_operations o JOIN orrery_bulk_runs r ON r.ref = o.bulk_ref WHERE o.action = 'retry' AND o.state = 'done' AND o.source = 'bulk'` | 2 |
| FailingJob of the bulk run, completed in cycle 2 | `SELECT COUNT(*) FROM orrery_jobs WHERE name = 'Workbench\App\Jobs\FailingJob' AND queue = 'bulk-retry' AND status = 'completed' AND cycle = 2 AND cycle_source = 'bulk'` | 2 |
| The first run of the stuck-incident phase (step 0b) | `SELECT COUNT(*) FROM orrery_settings WHERE key = 'incidents_since'` | 1 |
| Stuck incidents, each delivered by its first claim (step 17) | `SELECT COUNT(*) FROM orrery_incidents WHERE attempts_made = 1 AND delivered_at IS NOT NULL AND delivery_failed_at IS NULL` | 8 |
| Incident reservation_expired (stuck-expired only: stuck-gap is inside its own lease) | `SELECT COUNT(*) FROM orrery_incidents i JOIN orrery_jobs j ON j.uuid = i.job_uuid AND j.cycle = i.cycle WHERE i.signal = 'reservation_expired' AND j.queue IN ('stuck-expired', 'stuck-gap')` | 1 |
| Incident outcome_not_observed | `SELECT COUNT(*) FROM orrery_incidents i JOIN orrery_jobs j ON j.uuid = i.job_uuid WHERE i.signal = 'outcome_not_observed' AND j.queue = 'stuck-gap' AND i.evidence = '{"gap":1}'` | 1 |
| Incidents repeated_releases | `SELECT COUNT(*) FROM orrery_incidents i JOIN orrery_jobs j ON j.uuid = i.job_uuid WHERE i.signal = 'repeated_releases' AND j.queue IN ('throttled', 'unlimited', 'deadline') AND i.evidence = '{"releases":21}'` | 3 |
| Incidents attempts_not_capped | `SELECT COUNT(*) FROM orrery_incidents i JOIN orrery_jobs j ON j.uuid = i.job_uuid WHERE i.signal = 'attempts_not_capped' AND ((j.queue = 'unlimited' AND i.evidence = '{"attempts":21,"rule":"none"}') OR (j.queue = 'deadline' AND i.evidence LIKE '{"attempts":21,"rule":"deadline",%'))` | 2 |
| Incident unknown | `SELECT COUNT(*) FROM orrery_incidents i JOIN orrery_jobs j ON j.uuid = i.job_uuid WHERE i.signal = 'unknown' AND j.queue = 'vanished' AND i.evidence = '{"reason":"absent"}'` | 1 |
| Worker heartbeats (the seeder runs queue:work through Artisan::call() in a process that is no worker, so it writes none) | `SELECT COUNT(*) FROM orrery_worker_heartbeats` | 0 |
| Control-plane signals | `SELECT COUNT(*) FROM orrery_signals` | 0 |
<!-- manifest:end -->
