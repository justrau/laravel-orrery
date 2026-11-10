# Orrery

Orrery (`justrau/laravel-orrery`) is a Horizon-style dashboard for the `database` queue driver of Laravel.

- It shows the queues with their wait and backlog, the failed jobs in failure groups, every job in one Jobs list with a job detail, the stuck jobs, batches, tags, job classes and workers.
- It retries and forgets failed jobs, one at a time or in a confirmed bulk run, and it pauses, continues and terminates workers.
- It is a watcher only. It never starts, supervises or scales queue workers. You run `queue:work` with systemd, supervisord, Forge or another process manager.
- Your `jobs`, `failed_jobs` and `job_batches` tables stay authoritative. Orrery keeps its own mirror tables next to them.

## Status and stability

- The first release is 0.1.0. The GitHub repository `justrau/laravel-orrery` is public. The package is not on Packagist yet: see [Installation](#installation).
- **Nothing is stable in 0.x.** The configuration keys, the commands and their options, the events (`LongWaitDetected`, `StuckJobDetected`, `JobFailed`), the `Orrery::` static API and the HTTP API under `/orrery/api` can change in any 0.x release. `CHANGELOG.md` names each change. A stability promise waits for 1.0.

## Requirements

| Item | Supported |
| --- | --- |
| PHP | `^8.3` (tested on 8.3, 8.4 and 8.5) |
| Laravel | 12.69.0 or later, and 13.30.0 or later |
| Databases | MySQL 8.4, Postgres 18, and the SQLite of PHP 8.3 to 8.5 (the tested versions). MariaDB and other MySQL and Postgres versions are not tested yet. |
| Queue driver | `database` only |
| Failed-job driver | `database-uuids` (retry and forget) or `null` (retry from the mirror's copy of the payload, forget in the mirror only). Every other failed-job driver is read-only: its failures show, and retry and forget refuse them. See [Retry and forget](#retry-and-forget). |

- **Extensions.** `pcntl` is recommended: Laravel's worker needs it to stop cleanly on a signal and to enforce `--timeout`. `posix` is recommended for a terminate in a process that runs no Laravel `Worker` (see [Pause, continue and terminate](#pause-continue-and-terminate)).
- **Notification channels (optional).** Slack needs `laravel/slack-notification-channel` 3.x (tested from 3.8). SMS needs `laravel/vonage-notification-channel`.

## Installation

Orrery is not on Packagist yet. Composer installs it from the public GitHub repository.

1. Add the repository to the `composer.json` of your application:

   ```bash
   composer config repositories.orrery vcs https://github.com/justrau/laravel-orrery
   ```

2. Require the package:

   ```bash
   composer require justrau/laravel-orrery:^0.1
   ```

3. Run the installer and the migrations:

   ```bash
   php artisan orrery:install
   php artisan migrate
   ```

   The installer:

   - publishes `config/orrery.php` (tag `orrery-config`);
   - publishes `app/Providers/OrreryServiceProvider.php` (tag `orrery-provider`) and adds it to `bootstrap/providers.php`. Without that file, add the provider to your providers list yourself;
   - publishes the package migration into `database/migrations` (tag `orrery-migrations`);
   - writes one fence migration for each other database that needs the fence table `orrery_fences` (see [Retry and forget](#retry-and-forget));
   - reports the package database, the schema state, the missing fence tables and invalid retention settings. It exits 1 when it refuses the package database (an `orrery.database` on the SQLite file of your queue, failed jobs, batches or cache).

   The migration creates the `orrery_*` tables on the connection `orrery.database` (`ORRERY_CONNECTION`), or on your default connection when it is empty. When `orrery.database` is empty, the package tables follow the default connection at every use. If your application changes its default connection or database at runtime (for example a multi-tenant package), set `ORRERY_CONNECTION` to a fixed connection. Otherwise the package rows go to each tenant database. The package reports the first such change in a process to your exception handler.

   The package migration does nothing when the package tables of this release already exist, so a second `migrate` from another migrations table keeps them.

   **A rollback of the package migration drops every package table and its rows, from whichever database's migrations table it runs.** `migrate:rollback`, `migrate:reset` or `migrate:refresh` on any database that recorded the package migration drops the tables on `ORRERY_CONNECTION`. If you run `migrate` for several databases (a database per tenant), set `ORRERY_LOAD_MIGRATIONS=false`, move the published package migration out of the tenants' migration path, and run it with `--path` on the database of `ORRERY_CONNECTION` only.

### The queue

- Use the `database` queue connection (`QUEUE_CONNECTION=database`). Create the `jobs` table with `php artisan make:queue-table` when your application does not have it.
- Use the failed-job driver `database-uuids` (`QUEUE_FAILED_DRIVER=database-uuids`, Laravel's default), so retry and forget work.
- When you use job batches, create the batches table with `php artisan make:queue-batches-table`.
- When a queue connection or the failed-job connection is on another database than the package tables, run `php artisan orrery:install --fence`, then `php artisan migrate`. Run it again after you add a queue connection: it writes only the missing fence migrations.

### SQLite

- Set `journal_mode` to `wal` and a `busy_timeout` above 0 on every connection of an SQLite file that the queue, the failed jobs, the batches or a cache store use. Without them, the package does not read that file automatically (see [SQLite files](#sqlite-files)).
- Set `'transaction_mode' => 'IMMEDIATE'` on these connections, for your application's own sake: in the default DEFERRED mode, Laravel's database queue fails against every concurrent writer (PHP 8.4 or later).
- With `orrery.database` empty and an SQLite file as the default connection, the package keeps its tables in a sibling file: `database/database.sqlite` gets `database/database.orrery.sqlite`. The package creates and installs this file itself.

### Access

The host application must authenticate its users. Orrery has two abilities, which you define in `app/Providers/OrreryServiceProvider.php`:

- `viewOrrery` opens the dashboard and every read route of the API.
- `operateOrrery` allows every write: pause, continue and terminate; the retry and the forget of a failed job; a bulk run; the resolve step of an operation; adding and removing a monitored tag. The server checks it by the HTTP method, so every route except GET, HEAD, OPTIONS and TRACE needs it.
- In the `local` environment, every user (also a guest) can view and operate. In other environments, the published stub allows nobody until you add users.
- A user who needs `operateOrrery` also needs `viewOrrery`.

For example, one admin can view and operate, and a support colleague can only view:

```php
protected function gate(): void
{
    Gate::define('viewOrrery', function ($user = null) {
        return in_array($user?->email ?? '', [
            'admin@example.com',
            'support@example.com',
        ]);
    });

    Gate::define('operateOrrery', function ($user = null) {
        return in_array($user?->email ?? '', [
            'admin@example.com',
        ]);
    });
}
```

The gates get the user of `orrery.guard` (`ORRERY_GUARD`; empty: the default guard). Set it when your dashboard users sign in on another guard (for example `admin`). A value that is not a usable guard denies both abilities. See [Security](#security) for the 401 and 403 answers.

### Workers

Run your workers as usual, under an external process manager (systemd, supervisord or Forge):

```bash
php artisan queue:work database --queue=default
```

**Configure every connection that a worker uses in the application that serves the dashboard too.** The dashboard decides when a reservation expires from the `retry_after` of the job's queue connection in its own configuration. Without it, a running job has an unknown lease: the dashboard shows no expiry for it, never flags it as expired, and decides whether its worker is live from the heartbeat only.

### The scheduler

The package schedules `orrery:tick` every minute, with `onOneServer()` and `withoutOverlapping(10)`. Your application must run the Laravel scheduler:

```cron
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

- `onOneServer()` needs a cache store that all servers share (for example Redis, Memcached, database or DynamoDB). With a `file` or `array` store on several servers, each server runs the tick.
- To run the tick on one dedicated server only, set `orrery.schedule` to `false` (`ORRERY_SCHEDULE=false`) and add the line to your own schedule. Keep `onOneServer()` and `withoutOverlapping()`: two ticks at the same time do the same work twice.

  ```php
  use Illuminate\Support\Facades\Schedule;

  Schedule::command('orrery:tick')->everyMinute()->onOneServer()->withoutOverlapping(10);
  ```

- The tick runs in the foreground of `schedule:run`, before your own scheduled tasks. A slow tick (up to `orrery.tick.deadline_seconds`, plus the shares of the three notification phases: stuck events, stuck notifications and long-wait alerts) delays them. To run it in the background, set `orrery.schedule` to `false` and add `->runInBackground()` to the line above. Do not do this when the tick uses the file lock of a SQLite setup (see "SQLite files"): that lock belongs to the `schedule:run` process.
- Without the tick, the dashboard still shows the jobs. But old rows stay, the charts get no counts, interrupted retries stay open, and no alert is sent.

### Open the dashboard

Open `/orrery` in your browser (`ORRERY_PATH` changes the path). Then dispatch a job and see it:

```bash
php artisan tinker --execute="dispatch(fn () => logger('Hello from Orrery'));"
php artisan queue:work database --once
```

The job shows in the Jobs list as pending, then as completed.

You do not need Node or npm: the dashboard CSS and JavaScript ship prebuilt in `dist/`, and the page inlines them.

## What the numbers mean

- **Unknown is never zero.** A value that the package does not have (a minute without counts, a lost reading, a worker whose current job is not known) shows as "—", a gap or "unknown", never as 0, "idle" or "healthy". A capped count says "at least".
- **Degradation.** The package never lets its own errors reach your queue. An observation that it cannot write is counted in its health events, which `GET /orrery/api/health` returns in `windows`: `dropped` (package operations that did not run or failed, mostly observations not written; one operation can hold several observations, a tick phase none), `unmatched_observations`, `unverified` (deferred observations dropped without a proof of their outcome) and `rollback_unverified` (observations under a transaction that rolled back). When the package database cannot be reached, the package pauses its observations for `orrery.observer.breaker_seconds` (30) and counts them as dropped. Under PHP-FPM each request starts a new application, so the pause reaches the next requests through a small file in `storage/framework` (`observer.shared_breaker`); when the pause ends, about one request at a time tries the database again. When the file cannot be written, the package reports it once per process, so once per request under PHP-FPM. While the pause lasts, the package does not write its health events: a web request or a command that ends during it writes its record to the log instead, so an outage that a web request sees may not show in `windows`. Two lock timeouts of the package's writes within a minute also pause the observations, but only in that process: under PHP-FPM a web request seldom reaches two, so a blocked package table still costs each request its lock timeout. The dashboard footer warns of a recent degradation.
- **"Unreported gaps cannot be ruled out."** The health report always carries this notice. A gap that the package cannot see is never reported.
- **Uninstrumented connections.** The package replaces the `database` queue connector with its own, which records each push, reservation and release (`orrery.swap_connector`, default `true`). When the swap is off, or another package's connector wins the `database` binding, the package mirrors only the outcomes: a pushed or reserved job gets no mirror row, running jobs and arrivals show as unknown, and the children of `queue:listen` do not obey the controls. The ready backlog and the wait still come from the `jobs` table. `/orrery/api/health` lists the connection in `uninstrumented_connections`, and the footer names it. Set the swap to `false` only when your application registers its own `database` connector.
- **Uncertain operations.** A retry or forget whose outcome is not known yet (a lost COMMIT answer) is `uncertain`. The tick decides it from the fence; it is never retried as new work. An operation whose next step needs an operator waits as "awaiting resolution" (`awaiting_resolution` in `/orrery/api/health`, the footer, a Resolve button on the job page). Run `php artisan orrery:resolve <id>` or `orrery:resolve --all`.
- **The per-minute counts.** The tick counts the completed and failed jobs, the arrivals and the runtimes of each minute into `orrery_samples`. Each run recounts the last 5 minutes, so an outcome that arrives up to 5 minutes late counts. After an outage, each tick catches up at most 65 minutes. A counted quiet minute is 0. A minute that was not counted, whose jobs were deleted before they were counted, or that is older than `samples.retention_hours`, is unknown. `/orrery/api/health` returns `samples` (`counted_through` and the lag), and the footer warns "Counts are behind" after 10 minutes.
- **Late arrivals.** An observation that arrives more than about 5 minutes after its job (for example a push held back by a long transaction of the application) is not counted in its minute, and that minute still reads as counted.
- **Clocks.** The tick counts minutes by the clock of its server. Keep the clocks of the tick servers and the workers within a few seconds of each other (NTP).

## The dashboard

- **Overview:** what needs attention (a queue that waits longer than its limit and is not paused, a failure group that failed in the last 15 minutes), one card per queue with a wait gauge and Pause or Continue, the completed and failed jobs per minute of the last hour with the deploy and restart markers, and the latest failure groups.
- **Queues:** each queue with its wait against its limit, the ready and running jobs, the in and out rates of the last 5 complete minutes, and the workers. A selected queue shows its backlog of the last hour, its workers, its recent failure groups and how long its jobs are kept.
- **Failure groups:** the failed jobs grouped by exception class, first line of the message and job class, for the last hour, 24 hours or 7 days, with a queue filter. A group shows its message, occurrences, jobs and stack trace. Retry and Forget start a bulk run.
- **Jobs and Job detail:** every job that the package keeps, in tabs (pending, running, completed, silenced, failed, retrying, unknown and stuck), with filters (queue, job class, tag, time range) and a search (a full uuid, a part of the job name, or an exact tag). The detail shows the status, the cycles, the retries, the history, the payload as a tree, the exception and the context, all redacted, with Retry, Forget and Resolve.
- **Batches:** Laravel's job batches with their progress and member counts. Retry failed members starts a bulk run.
- **Job classes:** the completed, failed and pushed jobs and the average runtime per class over 15 or 60 minutes.
- **Tags:** the monitored tags and their jobs.
- **Workers:** the live workers and their current jobs. A worker is live when it wrote a heartbeat in the last 60 seconds (or in the last two `workers.heartbeat_seconds`, when that is longer) or holds a reservation that did not expire. The children of `queue:listen` show under their parent.

The old paths `/`, `/dashboard`, `/metrics…`, `/monitoring…`, `/failed/<id>`, `/failed-legacy` and `/jobs/<type>…` redirect to these screens.

- The write buttons show only for users with `operateOrrery`.
- Times show in the browser's time zone, or in `orrery.display_timezone`. Every absolute time has its zone label.
- Below 1024 px the sidebar is behind a toggle, and below 768 px a list and its detail are two screens. The keyboard reaches every control, and Escape closes a sheet, the inspector or the sidebar.
- There is no dark mode yet.

## Configuration

`config/orrery.php`. A configuration file that you published before a release keeps working: every key that it lacks takes its default.

| Key | Default | Effect |
| --- | --- | --- |
| `name` | `Orrery` (`ORRERY_NAME`) | The name in the page title and the notifications. |
| `domain` | `null` (`ORRERY_DOMAIN`) | The domain of the dashboard routes. |
| `path` | `orrery` (`ORRERY_PATH`) | The URL path of the dashboard. |
| `proxy_path` | `''` (`ORRERY_PROXY_PATH`) | A path prefix in front of `path` in the browser, when a reverse proxy serves the application under a sub-path. |
| `middleware` | `['web']` | The middleware of the dashboard routes. Add your Content Security Policy middleware here. Keep CSRF protection (the `web` group has it). Every write must also send the header `X-Requested-With: XMLHttpRequest`, which the dashboard sends; a write without it gets 403 `header_missing`. |
| `guard` | `null` (`ORRERY_GUARD`) | The auth guard that tells "signed out" (401) from "not allowed" (403). Null: the default guard. |
| `display_timezone` | `null` (`ORRERY_DISPLAY_TIMEZONE`) | An IANA zone name (for example `Europe/Vilnius`) for the dashboard's times. Null or an invalid name: the browser's zone. |
| `database` | `null` (`ORRERY_CONNECTION`) | The connection of the package tables. Null: the default connection, and on an SQLite file the sibling file. Set it when your application changes its default connection at runtime. It must not name the SQLite connection of your queue, failed jobs, batches or cache. |
| `load_migrations` | `true` (`ORRERY_LOAD_MIGRATIONS`) | Whether the package loads its migrations from its own directory. Set `false` when you run `migrate` for several databases: a rollback from any of them drops the package tables (see [Installation](#installation)). |
| `sessions.pooled` | `false` (`ORRERY_SESSIONS_POOLED`) | Postgres connections behind a transaction pooler: `true` for all, or a list of connection names. In the environment: a boolean word (`1`, `true`, `on`) or a comma-separated list of names. |
| `sessions.statement_timeout_ms` | `10000` | The statement timeout of the package's own sessions, in milliseconds. `0` turns it off. |
| `sqlite_automatic_access` | `true` (`ORRERY_SQLITE_AUTOMATIC_ACCESS`) | Automatic reads of the application's SQLite files, when each file allows them. |
| `observer.breaker_seconds` | `30` | How long the package pauses its observations after the package database could not be reached. |
| `observer.shared_breaker` | `null` | Whether the pause reaches the next processes through a file: `null` only when PHP does not run as `cli` or `phpdbg` (PHP-FPM, mod_php, FrankenPHP, whose SAPI is `frankenphp` also in its worker mode; Octane on Swoole or RoadRunner runs as `cli` and keeps its pause in the process), `true` always, `false` never. |
| `observer.shared_breaker_path` | `null` | The directory of that file. Null: `storage/framework`. |
| `tick.deadline_seconds` | `30` | The time limit of one `orrery:tick` run. The later phases wait for the next run. |
| `workers.heartbeat_seconds` | `15` (`ORRERY_HEARTBEAT_SECONDS`) | The least time between two heartbeat writes of a worker. |
| `control.stale_signals_seconds` | `900` | How long a worker keeps its last signals when it cannot read new ones. After that it ignores them. |
| `control.signal_cache_seconds` | `1` (`ORRERY_SIGNAL_CACHE_SECONDS`) | How long a worker keeps the signals that it read. |
| `retry.fence_retention_days` | `30` | Days to keep a fence row after its operation ended and its deadline passed. |
| `retry.publish_timeout` | `60` | Seconds that a retry may take from its claim to its publication. |
| `operations.retention_days` | `30` | Days to keep a finished retry or forget operation and the bulk-run audit rows. |
| `bulk.forget_max` | `10000` (`ORRERY_BULK_FORGET_MAX`) | The largest exact total of a bulk forget, from 1 to 100,000. Another value refuses every bulk forget. |
| `connections` | `[]` | More queues to measure: `'connection' => ['queue', …]`, or `'connection:queue'` entries. |
| `schedule` | `true` (`ORRERY_SCHEDULE`) | The package schedules `orrery:tick`. `false` schedules nothing. |
| `swap_connector` | `true` | The queue connector swap (see [What the numbers mean](#what-the-numbers-mean)). |
| `waits` | `['*' => 60]` | The long-wait thresholds in seconds, keyed `connection:queue`. `*` applies to every other queue. `0` turns an alert off. |
| `trim.completed`, `trim.silenced` | `60`, `60` | Minutes to keep a completed job, and a silenced completed job. |
| `trim.failed`, `trim.unknown` | `10080`, `10080` | Minutes to keep a failed job in the mirror, and a job with the status `unknown`. |
| `trim.monitored` | `10080` | Minutes to keep a job with a monitored tag, when this is longer than its state's retention. |
| `trim_overrides` | `[]` | The retention per connection and queue (see below). |
| `dashboard.count_cap` | `1000000` | The largest count of `/orrery/api/counts` (the pending, completed and failed counts) and of a batch's failed members; a larger count is "at least". |
| `dashboard.group_count_cap` | `5000` | The largest count of a failure group; a larger count is "at least". |
| `dashboard.measure_cache_seconds` | `5` | How long the dashboard reads share one queue measurement through the default cache store. `0` turns it off. |
| `dashboard.list_count_cap` | `10000` | The largest Jobs list total (at least 101). |
| `dashboard.search_candidates` | `50000` | The rows that a filtered Jobs search examines per page. Then the answer says so, and the next page continues. |
| `stuck.release_threshold`, `stuck.attempt_threshold` | `20`, `20` | The thresholds of the "repeated releases" and "attempts not capped" signals. `false` turns that signal off. |
| `stuck.worker_silence_seconds` | `60` | A running job's worker is silent when its reservation expired and its last heartbeat is this old. |
| `stuck.notify` | `true` (`ORRERY_STUCK_NOTIFY`) | Send the stuck-job notifications. `false` keeps the event only. |
| `redact.defaults`, `redact.keys`, `redact.patterns` | `true`, `[]`, `[]` | The redaction of job data (see [Security](#security)). |
| `silenced` | `[]` | Job classes whose completed jobs show as silenced. |
| `silenced_tags` | `[]` | Tags that make a job silenced. |
| `tags.max_per_job` | `50` | The most tags that a job keeps. |
| `samples.count_seconds` | `5` | The time share of one count run of the per-minute samples. |
| `samples.count_wait_minutes` | `120` | Retention keeps a row whose minute is not counted yet while the counts moved at most this many minutes ago. Do not set it to `0` together with `trim.completed` `0`: then most recent minutes stay unknown. |
| `samples.retention_hours` | `24` | Hours to keep the per-minute samples. |
| `health.retention_days` | `7` | Days to keep a closed window of the health events. |

### Retention

- Each tick gives the retention 10 seconds. It deletes expired mirror rows in batches of 500, with no row limit, one partition (connection, queue, state) after another. It never deletes a pending, reserved or retrying row, or a row that a retry or forget holds.
- An invalid `trim` value or `trim_overrides` entry stops the retention, and the footer warns. `orrery:install` reports invalid values.
- `/orrery/api/health` returns the last state as `retention`: `{state: "ok", at, deleted, behind, lag_seconds, partitions_unchecked}`, `{state: "none"}` before the first run, or `{state: "unreadable"}`.

`trim_overrides` is a list. Each entry has a `connection`, a `queue` or both, and one or more `trim` keys in minutes:

```php
'trim_overrides' => [
    ['connection' => 'database', 'queue' => 'stripe', 'completed' => 43200],
    ['queue' => 'emails', 'failed' => 20160],
],
```

- Per key, an entry with the connection and the queue wins, then one with the queue only, then one with the connection only, then `trim`. A monitored row keeps the longer of its state's value and the effective `monitored`.
- Names compare exactly (case and spaces count, also on MySQL), and a queue name may contain `:`. An entry's connection also covers the other queue connections on the same `jobs` table.
- `0` means "delete at the next tick". The overrides delete only mirror rows, never your `jobs` or `failed_jobs` rows.
- `connection` must name a `database` queue connection. An entry whose connection is not configured, or that matches no known queue, is listed as unmatched in `/orrery/api/health` (`retention_config`). `GET /orrery/api/settings/retention` returns the effective values per queue, and the Queues screen shows them as "Kept for".

## Notifications

When a queue waits longer than its threshold in `orrery.waits`, `orrery:tick` dispatches the event `Justrau\Orrery\Events\LongWaitDetected` and sends the notification `LongWaitDetected`. Set the routes in the `boot()` method of `app/Providers/OrreryServiceProvider.php`:

```php
use Justrau\Orrery\Orrery;

Orrery::routeMailNotificationsTo('admin@example.com');
Orrery::routeSlackNotificationsTo(config('services.slack.notifications.orrery_webhook_url'));
Orrery::routeSmsNotificationsTo('15556667777');
```

- Read a route from `config()`, not `env()`: after `php artisan config:cache`, `env()` returns null in a provider. For the Slack example, add `'orrery_webhook_url' => env('ORRERY_SLACK_WEBHOOK_URL')` under `services.slack.notifications` in `config/services.php`.
- A null or empty route turns its channel off.
- A Slack route that starts with `http://` or `https://` is a webhook: the package sends the legacy Slack message, and the optional second argument names the channel (`Orrery::routeSlackNotificationsTo($url, '#ops')`). Any other route (`#ops`, `C0123`) is a channel for the Slack Web API: the package sends a Block Kit message with the bot token of `services.slack.notifications.bot_user_oauth_token`.
- A channel whose package is not installed is skipped, with one log warning per day.
- One long-wait notification per queue and channel at most every 5 minutes. The cooldown lives in the default cache store; when that store keeps its entries for one process only (`array`, `null`), is a `failover` store, or is down, it lives in the package tables. A channel that fails is skipped for every queue for a short backoff, which grows after a further failure.
- Set `mail.mailers.smtp.timeout` to 5–10 seconds: a hanging mail server holds the tick phase until the mailer gives up. The legacy Slack webhook times out after 5 s; the Web API channel takes its HTTP client's default of 30 s.

### Stuck jobs

The Stuck tab and the job detail show the jobs that look stuck, each with the facts behind its signal:

- **reservation expired:** a reserved job whose reservation (`reserved_at` + the connection's `retry_after`) has passed;
- **outcome not observed:** a job whose attempts went past its observed outcomes (a worker died during an attempt);
- **repeated releases:** a job released at least `stuck.release_threshold` times in its current cycle;
- **attempts not capped:** a job with at least `stuck.attempt_threshold` attempts while Laravel enforces no attempt limit.

A running job also shows its worker's liveness (live, silent or unknown). That is a fact, not a stuck signal: a long job inside its reservation is live.

The tick records one incident per job, cycle and signal and dispatches `Justrau\Orrery\Events\StuckJobDetected` for it, with the job's uuid, cycle, signal, connection, queue, name and the signal's numbers. The event carries no payload, exception text or context. Each incident is dispatched at most 3 times (again after 1 and 5 minutes when a listener throws). A tick that stops between a dispatch and its record can dispatch the same incident again, within those 3.

```php
use Illuminate\Support\Facades\Event;
use Justrau\Orrery\Events\StuckJobDetected;

Event::listen(function (StuckJobDetected $event) {
    logger()->warning('Stuck job', ['uuid' => $event->uuid, 'signal' => $event->signal]);
});
```

A late tick phase also sends each incident to the long-wait routes, without a listener:

- Each channel gets up to 3 tries per incident. A failed channel waits 60 s, then 300 s after the next failure, and the failure goes to the health events, never into the tick.
- One message per connection, queue, signal and channel every 5 minutes. The next message says "and N more", or a summary goes out when the 5 minutes end.
- The text holds only the event's values, never the payload. An SMS is at most 160 GSM characters.
- `ORRERY_STUCK_NOTIFY=false` sends no stuck-job notification and keeps the event. Turning it on again sends nothing old.

## Security

- **Access.** When the view check fails, the answer is 401 `unauthenticated` while `orrery.guard` has no user (the page asks you to sign in again), else 403 `forbidden`. A write without `operateOrrery` gets 403 `forbidden_action`, and the reads go on. A guard name that is not a key of `auth.guards` is never asked: every refusal is then 403, and `/orrery/api/health` warns `guard_invalid`. In `local`, a guest can view and operate.
- **Stored payloads.** The mirror stores the raw job payloads for the retention period, as `failed_jobs` does.
- **Redaction.** Every dashboard response with job data goes through one projection that redacts it. The browser never receives raw serialized PHP (the server reads it with a data-only parser that loads no class), and Laravel's hidden Context is never shown. The stored data is never changed, so a retry publishes the stored payload.
  - A value whose key matches is replaced by `[redacted]`. A key matches an entry by its last words (`stripe_api_key` and `apiKey` match `api_key`), or when any word is `password`, `passwd`, `pwd`, `secret` or `token`. The built-in entries also cover `authorization`, `cookie`, `client_secret`, `private_key`, `credit_card`, `ssn`, `webhook_url` and more.
  - Every string is also searched for bearer tokens, passwords in URLs, `key=value` and `key: value` pairs, JSON and PHP pairs whose key matches, and Slack, Discord and Microsoft webhook URLs.
  - A password in a URL is found only when it holds no unencoded `/`, space or backslash: the rule cannot tell such a password from a port and a path (`host:8080/path?mail=a@b`) or from the text after the URL. A URL needs these characters percent-encoded (`%2F`, `%20`, `%5C`) anyway; for another form, add a pattern.
  - Serialized PHP inside free text (for example a job payload in the message of a failed insert, or a serialized string in a failed cache write) is replaced by `[redacted]` as a whole, whatever `redact.defaults` says. On Laravel 13 you can also keep the bindings out of every query exception's message: set `mask_bindings_in_exception_messages` to `true` on the database connection.
  - `redact.keys` and `redact.patterns` add to the built-in lists; `redact.defaults` `false` removes them. A pattern is a complete PCRE with delimiters and flags, for example `'~ACME-\d{4}~i'`.
  - A value whose redaction fails shows `[redaction failed]`. An invalid pattern makes every job-data read answer 500 `redaction_config_invalid`; `/orrery/api/health` names its index, never its text.
  - Job, queue and connection names, tags and uuids are not redacted. Free text (an exception message, a batch name, a log line in a payload) can hold a secret that no rule matches: add a pattern, and do not grant `viewOrrery` to people who must not see job data. There is no payload search.
  - Write responses carry no job text.
- **Failure-group keys** are an HMAC of the group's text under `app.key`, so a key in a URL cannot be guessed back to a message. Rotating `app.key` splits each group until the old failures age out. With an empty `app.key`, no bulk forget starts (`app_key_missing`).
- **The actor of a control.** A pause, continue or terminate from the dashboard stores who did it, and every user with `viewOrrery` can see it ("Paused by admin@example.com"). The default is the email, else name, else key of the user of the dashboard's guard (`orrery.guard`); the commands store `cli`. To show an alias, set a resolver in the `boot()` method of your provider. It returns a string or null; the package keeps at most 191 characters of valid text, and a resolver that throws is reported and stores no actor.

  ```php
  use Illuminate\Http\Request;
  use Justrau\Orrery\Orrery;

  Orrery::actorUsing(fn (?Request $request): ?string => $request?->user()?->nickname);
  ```

- **Error reports carry no job value.** The package reports its own failures through `report()`, at most once per operation per 60 seconds, as `Justrau\Orrery\Exceptions\WatchedOperationFailed`: no previous exception, an empty trace, and a message from an allowlist (the operation, the exception class, the SQLSTATE, the driver code and the SQL with `?` placeholders, never the bindings; URLs keep only their scheme and host). Match on its fields `operation`, `causeClass`, `sqlState` and `driverCode`, not on the message. Out of the package's control: the SQL breadcrumbs of your error tracker, and the first line of your own exception that passes through a package operation (for example from your `actorUsing()` resolver).
- **Serialized cache values.** The package's own cache entries are arrays of scalars, so it works with `cache.serializable_classes => false`.

### Content Security Policy

The dashboard runs under a strict policy: no `unsafe-inline`, no `unsafe-eval`. Its CSS and JavaScript are inlined into the page, and each inlined element carries the nonce of Laravel's `Vite::cspNonce()` when your middleware sets one. The bundled Inter font is a `data:` URL, and the page loads no external font or script. The package sets no policy itself: add a middleware to `orrery.middleware`, for example:

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

final class OrreryContentSecurityPolicy
{
    private const POLICY = "default-src 'none'; script-src 'nonce-{nonce}'; style-src 'nonce-{nonce}'; img-src 'self' data:; font-src data:; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $response = $next($request);
        $response->headers->set('Content-Security-Policy', str_replace('{nonce}', $nonce, self::POLICY));

        return $response;
    }
}
```

```php
// config/orrery.php
'middleware' => ['web', \App\Http\Middleware\OrreryContentSecurityPolicy::class],
```

If your application already sets a policy for every route, give the dashboard routes this one, or add the dashboard's needs to yours.

## Storage

### Database sessions

- Orrery reads and writes through its own database sessions: one per connection name, with its own PDO. A session uses the writer of the connection (never a read replica), sends no Laravel event (so Telescope, Pulse and query counts do not see it), and never joins a transaction of the application.
- Each session connects with a timeout of 5 seconds and does not connect again after a failed connect, so an unreachable server costs at most one connect timeout per statement. A connection that drops after a successful connect is opened again once. A connector that the application binds as `db.connector.<driver>` is used as it is.
- Each session has a lock timeout of 5 seconds and the statement timeout `sessions.statement_timeout_ms`:
  - MySQL and MariaDB: `innodb_lock_wait_timeout` and `lock_wait_timeout`, `max_execution_time` (MariaDB `max_statement_time`), autocommit, after the application's own init command. MySQL's `max_execution_time` ends only reads. MySQL has no idle-in-transaction timeout.
  - Postgres: `lock_timeout`, `statement_timeout`, `idle_in_transaction_session_timeout` of 30 seconds, and READ COMMITTED.
  - SQLite: `busy_timeout` of 5 seconds. A package transaction that gets `SQLITE_BUSY` starts again, at most three times, each time with that wait, so a write-locked file can hold one package write for up to 15 seconds.
- **Postgres behind a transaction pooler** (PgBouncer, Supavisor and similar, in transaction mode): name the connection in `sessions.pooled`. A pooled session sends no session `SET`; it sets its timeouts with `SET LOCAL` in each transaction and in the same query as each statement outside one. It supports only the server's default `search_path`: remove a `search_path` or `schema` key from the connection, or set it to the server's default (Laravel's default `database.php` sets `public`, which is not the default `"$user", public`). A `SET ROLE` that another client left on a pooled backend still changes the privileges of the package's statements.
- An unreachable package database pauses the observations for `observer.breaker_seconds`. Another unreachable queue database affects only the work on that database.

### SQLite files

- **The sibling file.** The package creates the sibling file, installs its schema and upgrades it. It never drops it: `migrate:rollback` and `migrate:fresh` leave it as it is. Laravel's default `.gitignore` (`database/*.sqlite*`) covers it. Each process of Laravel's parallel testing gets its own sibling. Tests that use `RefreshDatabase` keep its rows between tests; the tick marks a row whose job is gone as `unknown`.
- An `orrery.database` that names the SQLite file of a queue, the failed jobs, the batches or a cache store (also through a link, another letter case, or a `file:` URI of that connection), or a SQLite `file:` URI, is refused (`sqlite_shared_mirror` in `/orrery/api/health`). Name a file of its own, or leave the key empty.
- **No automatic write to your files.** The package never writes to an application SQLite file (a queue, the failed jobs, the batches, or a cache store) on its own. Only an operator's action writes there, in short transactions: a retry, a forget, a bulk run, `orrery:clear`, `orrery:forget` and `orrery:resolve`. A retry briefly locks the database, like a native `queue:retry`. A step of an interrupted operation that needs such a write waits for an operator ("awaiting resolution").
- **Automatic reads only in WAL.** The package reads an application SQLite file automatically only when every connection of the file sets `journal_mode` `wal`, a `busy_timeout` above 0 and no `EXCLUSIVE` locking mode, and `sqlite_automatic_access` is true. In rollback mode a reader can make the application's COMMIT fail. On any other file the values that the package would read show as unknown (the backlog and wait of its queues, the batches, the fence check, the restart markers), and `/orrery/api/health` (`sqlite_warnings`) and `orrery:install` warn: "SQLite: enable WAL (journal_mode wal) and a busy_timeout so Orrery can observe the queue without affecting it." Run `php artisan orrery:reconcile` to reconcile against such a file. The package never changes the application's journal mode. Set `sqlite_automatic_access` to `false` when your application switches a file to `EXCLUSIVE` locking at runtime.
- When the default cache store is a `database` store on an application SQLite file, the package does not use it: the measurement cache is off, the long-wait lock and cooldowns go to the package tables, and Laravel's native pause state of each queue is unknown. When the scheduler's cache store is such a store, the tick takes a file lock next to the sibling (`<file>.orrery-tick.lock`) instead of `onOneServer()`: that setup has one server.
- With `SESSION_DRIVER=database` on an SQLite file, the `web` middleware writes the session row on every dashboard poll. Use `file` or `cookie` sessions, or change `orrery.middleware`.

### The optional `jobs` index

The long-wait check and the dashboard read the backlog of your `jobs` table with one grouped read per queue connection, which visits every row of the table. With an index on (`queue`, `reserved_at`, `available_at`) the read visits every entry of that smaller index instead of the table rows; its cost still grows with the number of rows. Add it when your `jobs` table often holds many thousands of rows:

```bash
php artisan vendor:publish --tag=orrery-jobs-index
php artisan migrate
```

The package never runs this migration itself.

The build locks the `jobs` table. On Postgres the migration uses `CREATE INDEX CONCURRENTLY`, so pushes and reservations go on while it builds. On MySQL and MariaDB the build is online for most table formats. On SQLite every write to the database waits until the build ends, so run it at a quiet time on a large table.

### The deferred indexes

The stuck-job lists read three `orrery_jobs` indexes (`st_attempts`, `st_gap`, `st_releases`). A new install creates them with the tables. On an existing install, no migration builds them, because a build on a large table holds locks. Build them once after `php artisan migrate`:

```bash
php artisan orrery:install --indexes
```

- The build runs on a connection of its own, without the package's statement timeout. `--timeout=<seconds>` sets one (Postgres; MariaDB `max_statement_time`); the default 0 means none.
- Postgres: `CREATE INDEX CONCURRENTLY`, so writes continue; one build at a time; an invalid index from an interrupted build is built again. Behind a transaction pooler the build uses the connection's `direct` endpoint, else name a direct connection with `--connection=<name>`.
- MySQL and MariaDB: `ALGORITHM=INPLACE, LOCK=NONE`. While the build waits for a metadata lock (5 s, at most 12 tries), the package's own writes to `orrery_jobs` wait too and can count as dropped.
- SQLite: the build locks the package database file while it runs.
- Until the indexes exist, the stuck lists answer `index_missing`, and `/orrery/api/health` lists them in `missing_indexes` with the command.

### The measurement cache

The dashboard's queue read measures the backlog of your `jobs` tables, and each open dashboard polls it every 10 seconds. The reads share one measurement through the default cache store for `dashboard.measure_cache_seconds`. With several servers, a shared store lets them share it. The cache key holds no credentials. A `queue:pause` or `queue:continue` from the command line can show up to that many seconds late; a pause or continue from Orrery shows at the next poll. A measurement slower than the lifetime is never served from the cache, and the health events record `measure_cache:slow`. The tick never uses this cache: an alert always comes from a new measurement.

## Operations

### Pause, continue and terminate

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

### Retry and forget

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

### Bulk runs

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

### Deploys and restarts

Add `orrery:deployed` to your deploy script, after the new code is live:

```bash
php artisan migrate --force
php artisan queue:restart
php artisan orrery:deployed --label="v1.4.2"
```

- It records a deploy marker on the charts, once per label within `--window` seconds (default 600), so every server can run it. `--window=0` always records.
- The tick records a restart marker when the value of `queue:restart` in the default cache store changes. With several servers the store must be shared, as `queue:restart` needs anyway. A marker shows that a restart was requested, not that the workers stopped.
- Markers are kept for 7 days.

### Commands

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

Every command except `orrery:tick` exits non-zero when its requested work did not happen. `orrery:clear` also deletes the mirror rows of waiting bulk jobs whose id the package has not resolved yet; a bulk push whose mirror rows are written after `orrery:clear` ran keeps those rows.

## Known limits

- Only the `database` queue driver. For Redis, use Laravel Horizon.
- Laravel's own `queue:retry`, `queue:forget` and `queue:flush` bypass the ledger and the fence. A `queue:retry` of the same record at the same moment as an Orrery retry can publish the job twice. The package follows a native retry into the job's next cycle. After `queue:forget` or `queue:flush`, the tick removes the failed mirror rows whose bound record stays absent for max(`retry_after`, 600) seconds; a failure without a bound record ages out by `trim.failed`.
- Read-only failed-job drivers: see [Retry and forget](#retry-and-forget).
- `unknown` is a status, not a failure: a pending or reserved job whose row in `jobs` stays absent for max(`retry_after`, 600) seconds without an observed outcome (for example after `queue:clear`). When its queue connection is removed from the configuration or renamed, the tick and `orrery:reconcile` look for the job in every configured queue table: a renamed connection on the same table keeps its waiting jobs, and a job that no configured table holds becomes `unknown` with the reason `connection_removed`, which raises no stuck alert. While a table cannot be read (by the tick: a SQLite file in rollback mode), no job is marked this way; `orrery:reconcile` reads such a file. It is kept for `trim.unknown`.
- The in and out rates and the throughput counts are observations, not exact event counts: a retry of the same job is not a new arrival, and only the latest outcome of a job counts.
- A failure group is keyed by the exact first line of the message: messages with ids ("Order 123 not found") make a group per id.
- `GET /orrery/api/classes` reads every per-class sample of its window before it keeps the 500 busiest classes, so job names that change per job make it grow without a bound.
- Without [the optional `jobs` index](#the-optional-jobs-index), the queue measurement scans the whole `jobs` table. On SQLite no statement timeout applies, and the tick checks its deadline only between phases. So on a large table, the first tick after a big backlog can run past `orrery.tick.deadline_seconds`, and the later phases are skipped until the next tick. The three notification phases still run, each with its own share, so the alerts go on. Add the index for large queues; `docs/performance/tick-benchmark.md` has the measurements.
- "Over limit since" comes from the minute samples of the tick: a tick more than about a minute late starts the time again.
- A bulk dispatch (`Queue::bulk()`, `Bus::batch()`) costs statements on the package connection per job inside `dispatch()`, after one read of the new `jobs` rows: two for an untagged job (the insert of its mirror row and the update that stores its job id), five for a tagged job (a transaction with the insert of its tags). Laravel inserts the jobs in one statement. So a batch of many jobs makes `dispatch()` slower by that many round trips, and the dispatching process holds each payload a few times until the call returns.
- A package session has a connect timeout but no read timeout. A statement on an open connection to a server that stops answering without closing the connection (a host that goes down, a firewall that drops packets) waits until the operating system gives up on the connection. The server's statement timeout does not help there. Set TCP keepalive on the hosts, or keep the package tables on the application's own database server.
- `migrate:fresh`, `db:wipe` and Laravel's `DatabaseMigrations` trait drop the package tables without the migration's check for open operations, but only when they are on the wiped connection. With `ORRERY_CONNECTION` on another connection, the package tables and their rows stay, and the next `migrate` keeps them.
- No dark mode yet.

## Performance

- With the connector swap on, an untagged job that succeeds costs 4 statements on the package connection (1 for the push, 1 for the reservation, 2 for the completion). A tagged push adds a transaction and the insert of its tags: 7 in all. A worker also reads the signals at most once per second and writes a heartbeat at most once per 15 seconds.
- These are the costs on a warm connection. The package opens its own connection to its database, separate from the application's: each PHP process that dispatches or works jobs (under PHP-FPM, each request that dispatches) opens one more connection to that server. Size the server's connection limit for two connections per such process.
- The tick phases do bounded work per run within the 30-second deadline. `docs/performance/tick-benchmark.md` has the measured tick phases, the retention throughput, the effect of the optional `jobs` index and the build times of the deferred indexes.

## Upgrading

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

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

Orrery is open-source software under the MIT License. See [LICENSE](LICENSE). Parts of the code come from Laravel Horizon, and the dashboard bundles the Inter font and npm packages: [NOTICE.md](NOTICE.md) lists them, and `dist/THIRD_PARTY_NOTICES.txt` holds the licenses of the bundled npm packages.
