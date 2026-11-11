# src/Control/ (and src/Workers/)

## Workers

- Heartbeats (`Listeners\RecordWorkerHeartbeat`, `Workers\Heartbeats`) are written only in worker processes, on database connections: one `orrery_worker_heartbeats` row per `worker_id` (`host:pid:start`), at most once per `workers.heartbeat_seconds` (15), from `Looping`, `JobProcessing` and `pop()`. `WorkerStarting` writes nothing. A reservation whose queue differs from the last written `current_queue` is written at once, outside the throttle.
- Each heartbeat row's `options` holds its writer's interval (`Heartbeats::INTERVAL_OPTION`, in the same upsert). `LiveWorkers::quietSeconds()` judges each row by its own write period (max of that interval, `sleep`, `rest`; 0 counts as 15): quiet after 2 periods + 5 s, listed until 25 s later (at least 60 s).
- `queue:listen` children are grouped under their parent by host, ppid and the parent's start time; only the newest child (and older children with an active lease) show. `Workers\LiveWorkers::list()` gives the live workers (a fresh heartbeat or an active lease); each row's `state` is `live` (a lease or a heartbeat inside the quiet window), else `quiet` on an instrumented connection and `unknown` on an uninstrumented one.
- `Listeners\ForgetStoppedWorker` deletes the worker's own row on `WorkerStopping` and on the outermost `CommandFinished` (a `--once` worker), never for a `queue:listen` child or after `JobTimedOut`.

## The pause model

A queue (c, q) is paused when an Orrery global pause row exists, or an Orrery connection pause row for c, or Laravel's `QueueManager::isPaused(c, q)` is true (on Laravel 13 this also covers its global pause). The scopes are an OR: there are no continue rows, and no scope overrides another.

- `SignalState::pause()` of global or connection writes its row (`orrery_signals`) and deletes no narrower pause. A queue pause calls Laravel's `pause(c, q)`, also under each `failover` connection whose first connection is c (its worker asks under its own name, `DatabaseDriverFilter::failoversOf()`), and upserts a display-only annotation row with the actor. `NativePause` reads the flag under those names too, and `continue()` resumes them.
- `continue()` deletes the pause of exactly that scope; for a queue it calls Laravel's `resume(c, q)`. Its `ContinueResult` names every cause that still pauses the target. Each pause and continue locks the settings row `control_version`.
- `PauseCauses::of()` is the one rule of `/api/workload` and `/api/control`: `pauses` (each `{scope, source, issued_at, issued_by, possible}`), `paused_unknown`, `queue_cause_unknown` (Laravel's global pause masks the queue's own flag), and `native_unknown` with `native_hint` (the cache store is on a host SQLite file).
- Workers read only the global and connection rows (`SignalState::connectionPaused()`): Laravel's worker skips a natively paused queue before it calls `pop()`.
- `ControlTarget::resolve()` checks every target: a `database` queue connection, and `connection:queue` within 191 characters, never for a connection whose name holds `:` (the value would be ambiguous). Else 422, or exit 1.
- The actor: `pause()` and `terminate()` store `issued_by`, cleaned by `SafeText::clean()`. `ControlController` resolves it with `Orrery::actor($request)` (the `actorUsing()` callback, else the `email`, `name`, or auth key of the user of `orrery.guard`; a throwing resolver gives null). Commands store `cli`.

## Terminate

- One-shot: the newest applicable, unexpired signal decides, and it applies when its `issued_at` is later than the worker's start.
- It uses the mirror server's clock: `terminate()` stores `issued_at` and `expires_at` (now + `TERMINATE_TTL_MINUTES`, 30; a `dateTime` in UTC) from it, and a worker with an applicable row calibrates once (`offset = db_now − local_now`). A failed calibration records `control:clock_uncalibrated`. A worker without an applicable row runs no clock statement.
- In a worker, `pop()` and the `Looping` listener apply signals through `WorkerControl`. A terminate sets the stop latch (`StopLatch`) and `shouldQuit`; it sends SIGTERM only when no `Worker` instance is bound.

## Signal reads

A worker reads the signals at most once per `control.signal_cache_seconds` (1, `SignalCache`). When the mirror cannot be read, the last signals stay in effect for `control.stale_signals_seconds` (900); then the worker fails open and records `control:signals_stale`.
