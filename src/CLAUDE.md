# src/

Where the code and a spec disagree, read the code and report the difference.

## Layout

| Directory | Job |
| --- | --- |
| `DatabaseQueue.php`, `Connectors/` | The swapped queue: calls the parent unchanged, then builds each observation from the parent's return value. |
| `Observation/` | Commit deferral, payload classification, tags, generations, payload identity. |
| `Queue/` | The bulk capture (`BulkCapture`, `BulkResolver`), the instrumentation report, queue targets. |
| `Lifecycle/` | The pure rules (`Rules`), `JobStore`, history, cycle summaries. |
| `Storage/` | `PackageSession` and its engines, connectors, `HostFiles`, `PackageTransaction`, `Clock`, `DatabaseClock`, `TrustedSql`. |
| `Runtime/` | The watcher, the breaker, degradation, health events, `SafeError`, `SafeText`, `Hooks`. |
| `Retry/` | The operation ledger, retry, forget, the fence, the publication, the runner, the resolver. |
| `Failed/` | The failed-record adapters, `CapturingUuidFailedJobProvider`, the verifying registrar. |
| `Failures/` | The failure groups (`GroupKey`, `KeyedFailures`, `FailureScan`). |
| `Monitoring/` | Wait times, the native pause read, samples, tag monitoring, stuck signals and incidents. |
| `Control/` | Signals, pause causes, worker control, the stop latch. |
| `Maintenance/` | The tick runner, retention, reconciliation, the sibling file mutex. |
| `Workers/`, `Markers/` | Heartbeats and live workers; restart and deploy markers. |
| `Database/` | Models, repositories, the schema. |
| `Actions/` | The operator actions on the application's queue tables (`WaitingJobs`: delete one, clear, the count; `DelayedJobs`: run now; `BatchCancel`: cancel a batch; `OperatorAudit`: the event `OperatorActionPerformed` and its log line). |
| `Http/` | The JSON API and the SPA page. |
| `Notifications/` | `RoutedNotification` (the base of the long-wait routes' messages), `LongWaitDetected`, `LongWaitRecovered`, `FailureSpikeDetected`, `StuckJobDetected`, `StuckJobsSummary`. |

## Registration modules

- `OrreryServiceProvider::MODULES` lists the nine modules in registration and boot order: `StorageModule`, `RuntimeModule`, `ObservationModule`, `LifecycleModule`, `OperationsModule`, `MaintenanceModule`, `MonitoringModule`, `ControlModule`, `HttpModule` (`Registration\Modules`). No priorities, no discovery. Each comment in the list says what a later module needs from that module.
- Each module implements `Registration\Module`: `register()`, `boot()`, `listeners()`, `resetForTesting()`. A module's listeners register just before its `boot()`, before the legacy `EventMap` entries (`JobFailed` → `MarshalFailedEvent`, `LongWaitDetected` → `SendNotification`, `LongWaitRecovered` → `SendRecoveredNotification`).
- The provider wraps every listener: a driver filter (only `database` queue connections), then the watcher. A listener that implements `Runtime\WatchesItself` (for example `RecordWorkerHeartbeat`) watches its own writes, so it also runs while the breaker is open.
- `Modules::addForTesting($module, $before)` adds a test module.
- **The off switch** (`Runtime\OffSwitch`, `orrery.enabled`): a singleton that the provider binds in `register()` and resolves in `boot()` on the base application (Testbench registers providers before `defineEnvironment()`), so each process decides once. Every lazy check reads that instance (`OffSwitch::enabled()`): the connector swap, the `queue.failer` extender, the route middleware, the commands and the ping; a later config change cannot give a half-on state (`SwitchDecisionTest`). While off, only a module that implements `BootsWhileDisabled` boots (`StorageModule`: `migrate` support), the `EventMap` listeners do not register, the routes answer 503 (`Http\Middleware\RefuseWhileDisabled`, after `Authenticate`), and the acting commands refuse (`Console\RefusesWhileDisabled`). The jobs-index publish is in `MonitoringModule::register()`, so it is offered also while off.

## Binding lifetimes

- Every service is a singleton, except three scoped bindings (one unit of work, flushed by a worker loop or an Octane request): `RuntimeClock`, `OpenBulkCaptures`, `OccurrenceSlot`.
- Each module lists its process state in `PROCESS_STATE`; `boot()` resolves it on the base application (`Modules::resolveAtBoot()`), so an Octane sandbox shares those instances.
- Constructors open no connection and send no SQL (`BindingLifetimeTest`). A class that cannot be built at boot is built on first use: the host's boot never fails.
- `CommitDeferral` stays a process singleton on purpose (its docblock gives the reason); token units give the unit-of-work semantics.
- `HotPathBudgetTest::test_warm_path_container_resolutions` pins the container lookups of the warm path.

## Commands

- `orrery:install [--fence] [--indexes]`: publishes config, provider stub and migrations; `--fence` writes only the missing fence migrations; `--indexes` builds the deferred `orrery_jobs` indexes (see `src/Database/CLAUDE.md`).
- `orrery:tick`: the tick (see `src/Maintenance/CLAUDE.md`). It records a failed phase in the health events and exits 0.
- `orrery:retry {uuid?} {--all}` with scope options: one retry, or a bulk run in-process with a confirmation (`--force` skips it). Exit codes: 1 error, 130 SIGINT, 3 any uncertain, 2 any refused or not_member, else 0.
- `orrery:forget [id] [--all]`: one forget, or the walk of `ForgetAllRecords` up to the bound of its preview, after a confirmation (`--force` skips it).
- `orrery:retry --all` and `orrery:forget --all` ask only when the input is interactive and STDIN is a terminal (`Console\Terminal`, bound in tests); else they act on nothing without `--force`.
- `orrery:retry`, `orrery:forget` and `orrery:clear` refuse a scope option given with an empty value (exit 1): it never reads as absent.
- `orrery:clear [connection] [--queue=]`: `Actions\WaitingJobs::clear()`, shared with `POST /api/queues/clear`. Only waiting jobs of the byte-exact queue, in batches of one transaction each on the queue connection (no fence row), with their mirror rows on every alias of the jobs table. A batch whose bulk-row probe fails is kept, and the command exits 1. A queue connection inside a transaction is refused; a later batch that fails ends the clear with the counts so far (exit 1). `orrery:clear-metrics`: clears the per-minute counts.
- `orrery:reconcile`, `orrery:resolve {id?} {--all}`: operator paths of reconciliation and of operations that wait for an operator.
- `orrery:pause`, `orrery:continue`, `orrery:terminate` (`--connection=`, `--queue=`, actor `cli`), `orrery:deployed {--label=} {--window=600}`.
- `orrery:ping {--json}`: the checks of `Monitoring\Ping` (the ping route's), exit 1 when one fails or the package is off; no token.
- `orrery:workers {--format=list|supervisor|systemd|forge}` (`--user`, `--php`, `--path`, `--prefix`, `--log-dir`): prints the process-manager configuration of `workers.pools` (`Workers\Pools` validates, `Workers\ProcessConfig` renders and quotes per format). Output only, no package storage: it runs while the package is off, and `CommandExitCodeTest` lists it with `orrery:install` as no operator work.
- While the package is off, `orrery:tick` prints a skip and exits 0 (`TickCommand::whileDisabled()`), and every other command except `orrery:install`, `orrery:workers`, `orrery:continue` and `orrery:resolve` (the removal procedure) uses `RefusesWhileDisabled`: exit 1 before `handle()` resolves its arguments.
- Every operator command whose requested work did not happen exits non-zero. `CommandExitCodeTest` checks a mirror outage for every `orrery:*` command except `orrery:install`, and fails when a command has no row; each command's own test covers its other failures. `orrery:resolve` also exits 1 when an operation stays open after its step. A queue pause or continue whose Laravel part happened before the package record failed says so (`Control\QueuePauseRecordFailed`) and exits 1. The watcher rule covers hooks and listeners, not operator commands.

## Configuration (`config/orrery.php`)

The file documents most keys (the first keys, `name`, `domain`, `path`, `proxy_path` and `middleware`, have no comment there: the table of `docs/configuration.md` describes them). Keys that change behaviour in non-obvious ways:

- `enabled` (`ORRERY_ENABLED`, first in the file): the off switch (`Runtime\OffSwitch`); only an explicit false word turns it off.
- `database` (`ORRERY_CONNECTION`): empty means the default connection, and on a SQLite file the sibling file.
- `guard`: the guard that decides 401 versus 403.
- `sessions.pooled`, `sessions.statement_timeout_ms` (10000; 0 off).
- `ping.token` (null: the ping route answers 404), `ping.tick_max_age` (300), `ping.long_waits` (false).
- `workers.pools`: the declared worker pools (stable names, explicit connection, queues, processes, `queue:work` options) for `orrery:workers`; a later drift check compares them with the live workers by `--name`.
- `sqlite_automatic_access` (true): D18.
- `swap_connector` (true): the kill switch of the connector swap.
- `trim.*` and `trim_overrides` (per connection and queue), `samples.*`, `operations.retention_days`, `retry.fence_retention_days`, `health.retention_days`.
- `stuck.*` (thresholds, `notify`), `bulk.forget_max` (1..100,000, else every bulk forget is refused), `redact.*`, `display_timezone`, `dashboard.*` caps.

Notifications: a Slack route that starts with `http://` or `https://` is a webhook (legacy `SlackMessage`); any other route is a Web API channel (Block Kit). A null route turns that channel off.
