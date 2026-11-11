# Configuration

`config/orrery.php`. A configuration file that you published before a release keeps working: every key that it lacks takes its default.

| Key | Default | Effect |
| --- | --- | --- |
| `enabled` | `true` (`ORRERY_ENABLED`) | The off switch. `false` (also `0`, `off`, `no`) turns Orrery off: it observes nothing, the dashboard answers 503 and the commands that act refuse. A cached configuration needs `php artisan config:cache`, and running workers need `php artisan queue:restart` (see [Turning Orrery off](operations.md#turning-orrery-off)). |
| `name` | `Orrery` (`ORRERY_NAME`) | The name in the page title and the notifications. |
| `domain` | `null` (`ORRERY_DOMAIN`) | The domain of the dashboard routes. |
| `path` | `orrery` (`ORRERY_PATH`) | The URL path of the dashboard. |
| `proxy_path` | `''` (`ORRERY_PROXY_PATH`) | A path prefix in front of `path` in the browser, when a reverse proxy serves the application under a sub-path. |
| `middleware` | `['web']` | The middleware of the dashboard routes. Add your Content Security Policy middleware here. Keep CSRF protection (the `web` group has it). Every write must also send the header `X-Requested-With: XMLHttpRequest`, which the dashboard sends; a write without it gets 403 `header_missing`. |
| `guard` | `null` (`ORRERY_GUARD`) | The auth guard that tells "signed out" (401) from "not allowed" (403). Null: the default guard. |
| `display_timezone` | `null` (`ORRERY_DISPLAY_TIMEZONE`) | An IANA zone name (for example `Europe/Vilnius`) for the dashboard's times. Null or an invalid name: the browser's zone. |
| `database` | `null` (`ORRERY_CONNECTION`) | The connection of the package tables. Null: the default connection, and on an SQLite file the sibling file. Set it when your application changes its default connection at runtime. It must not name the SQLite connection of your queue, failed jobs, batches or cache. |
| `load_migrations` | `true` (`ORRERY_LOAD_MIGRATIONS`) | Whether the package loads its migrations from its own directory. Set `false` when you run `migrate` for several databases: a rollback from any of them drops the package tables (see [Installation](installation.md#install-the-package)). |
| `sessions.pooled` | `false` (`ORRERY_SESSIONS_POOLED`) | Postgres connections behind a transaction pooler: `true` for all, or a list of connection names. In the environment: a boolean word (`1`, `true`, `on`) or a comma-separated list of names. |
| `sessions.statement_timeout_ms` | `10000` | The statement timeout of the package's own sessions, in milliseconds. `0` turns it off. |
| `sqlite_automatic_access` | `true` (`ORRERY_SQLITE_AUTOMATIC_ACCESS`) | Automatic reads of the application's SQLite files, when each file allows them. |
| `observer.breaker_seconds` | `30` | How long the package pauses its observations after the package database could not be reached. |
| `observer.shared_breaker` | `null` | Whether the pause reaches the next processes through a file: `null` only when PHP does not run as `cli` or `phpdbg` (PHP-FPM, mod_php, FrankenPHP, whose SAPI is `frankenphp` also in its worker mode; Octane on Swoole or RoadRunner runs as `cli` and keeps its pause in the process), `true` always, `false` never. |
| `observer.shared_breaker_path` | `null` | The directory of that file. Null: `storage/framework`. |
| `tick.deadline_seconds` | `30` | The time limit of one `orrery:tick` run. The later phases wait for the next run. |
| `workers.heartbeat_seconds` | `15` (`ORRERY_HEARTBEAT_SECONDS`) | The least time between two heartbeat writes of a worker. |
| `workers.pools` | `[]` | The intended worker pools, keyed by name, for `orrery:workers` (see [Worker pools](operations.md#worker-pools)). Orrery never starts workers. |
| `ping.token` | `null` (`ORRERY_PING_TOKEN`) | The token of the monitoring ping `GET /orrery/ping`, at least 32 characters. `null` or a shorter token turns the route off (404). See [Monitoring](operations.md#monitoring) and [Security](security.md#the-monitoring-ping). |
| `ping.tick_max_age` | `300` (`ORRERY_PING_TICK_MAX_AGE`) | The ping fails `late` when the last tick started longer ago, in seconds. It is also the window of the `observations` check. |
| `ping.long_waits` | `false` (`ORRERY_PING_LONG_WAITS`) | `true` adds the `long_waits` check: a queue over its long-wait limit that no pause holds fails the ping. |
| `control.stale_signals_seconds` | `900` | How long a worker keeps its last signals when it cannot read new ones. After that it ignores them. |
| `control.signal_cache_seconds` | `1` (`ORRERY_SIGNAL_CACHE_SECONDS`) | How long a worker keeps the signals that it read. |
| `retry.fence_retention_days` | `30` | Days to keep a fence row after its operation ended and its deadline passed. |
| `retry.publish_timeout` | `60` | Seconds that a retry may take from its claim to its publication. |
| `operations.retention_days` | `30` | Days to keep a finished retry or forget operation and the bulk-run audit rows. |
| `bulk.forget_max` | `10000` (`ORRERY_BULK_FORGET_MAX`) | The largest exact total of a bulk forget, from 1 to 100,000. Another value refuses every bulk forget. |
| `connections` | `[]` | More queues to measure: `'connection' => ['queue', …]`, or `'connection:queue'` entries. |
| `schedule` | `true` (`ORRERY_SCHEDULE`) | The package schedules `orrery:tick`. `false` schedules nothing. |
| `swap_connector` | `true` | The queue connector swap (see [What the numbers mean](dashboard.md#what-the-numbers-mean)). |
| `waits` | `['*' => 60]` | The long-wait thresholds in seconds, keyed `connection:queue`. `*` applies to every other queue. `0` turns an alert off. |
| `notifications.long_wait.recovered` | `true` | Send a recovery message when a long-wait incident ends, to the channels that got its start message. The event `LongWaitRecovered` fires also with `false`. |
| `notifications.failure_spike.per_minute` | `null` | The failed jobs of one minute (all queues) that send a failure-spike notice. `null` turns it off. |
| `notifications.failure_spike.cooldown` | `30` | Minutes that each channel waits after a failure-spike notice. |
| `trim.completed`, `trim.silenced` | `60`, `60` | Minutes to keep a completed job, and a silenced completed job. |
| `trim.failed`, `trim.unknown` | `10080`, `10080` | Minutes to keep a failed job in the mirror, and a job with the status `unknown`. |
| `trim.monitored` | `10080` | Minutes to keep a job with a monitored tag, when this is longer than its state's retention. |
| `trim_overrides` | `[]` | The retention per connection and queue (see below). |
| `dashboard.count_cap` | `1000000` | The largest count of `/orrery/api/counts` (the pending, completed and failed counts) and of a batch's failed members; a larger count is "at least". |
| `dashboard.group_count_cap` | `5000` | The largest count of a failure group; a larger count is "at least". |
| `dashboard.measure_cache_seconds` | `5` | How long the dashboard reads share one queue measurement through the default cache store. `0` turns it off. |
| `dashboard.list_count_cap` | `10000` | The largest Jobs list total (at least 101). |
| `dashboard.search_candidates` | `50000` | The rows that a filtered Jobs search examines per page. Then the answer says so, and the next page continues. |
| `dashboard.class_rows` | `36060` | The per-class rows that the Classes screen reads in all, newest minute first: enough for 500 classes in every minute of the hour. With more, the older minutes are not counted, and every sum is "at least". |
| `stuck.release_threshold`, `stuck.attempt_threshold` | `20`, `20` | The thresholds of the "repeated releases" and "attempts not capped" signals. `false` turns that signal off. |
| `stuck.worker_silence_seconds` | `60` | A running job's worker is silent when its reservation expired and its last heartbeat is this old. |
| `stuck.notify` | `true` (`ORRERY_STUCK_NOTIFY`) | Send the stuck-job notifications. `false` keeps the event only. |
| `redact.defaults`, `redact.keys`, `redact.patterns` | `true`, `[]`, `[]` | The redaction of job data (see [Security](security.md)). |
| `silenced` | `[]` | Job classes whose completed jobs show as silenced. |
| `silenced_tags` | `[]` | Tags that make a job silenced. |
| `tags.max_per_job` | `50` | The most tags that a job keeps. |
| `samples.count_seconds` | `5` | The time share of one count run of the per-minute samples. |
| `samples.count_wait_minutes` | `120` | Retention keeps a row whose minute is not counted yet while the counts moved at most this many minutes ago. Do not set it to `0` together with `trim.completed` `0`: then most recent minutes stay unknown. |
| `samples.retention_hours` | `24` | Hours to keep the per-minute samples. |
| `health.retention_days` | `7` | Days to keep a closed window of the health events. |

## Retention

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
