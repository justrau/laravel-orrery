# src/Lifecycle/

`docs/architecture/lifecycle-rules.md` describes the rules as they are: it maps the old ids (P1, R1, …) to the methods of `Rules` and states the conventions of every rule. Read it before you change a rule.

## JobStore and Rules

- `JobStore::observe()` applies each observation to `orrery_jobs` with `Rules::decide()`. Each rule is a named method of `Rules` (`push`, `reserve`, `release`, `complete`, `fail`, `markUnknown`, …), and `Decision::$rule` holds its name. `Rules` is pure: it reads no database.
- A fast conditional statement serves the expected case: an insert-or-ignore for a push, one update for a reservation and a release. Every other case reads the row, decides, and writes with a guard. A guard miss repeats at most `JobStore::MAX_ATTEMPTS` (3) times, then counts a dropped observation.
- A completion writes `runtime_ms`. The store writes no metric: the tick counts minutes into `orrery_samples`.
- Side effects (tag rows, failed tags, history, the occurrence) run only in the package transaction of the write that changed the row.
- Every write whose `$set` contains `exception_class` or `exception_message` also writes `failure_key = Failures\GroupKey::of(…)`. The key is an HMAC keyed by `app.key`; rows keyed before the UI rebuild keep their unsalted key.
- Rows carry cycles, generations (`cycle_gen`), occurrences (`occurrence_id`), outcome accounting (`observed_attempts`, `late_outcomes`, `last_outcome_attempt`, `observed_gap`), bounded history (`History`), cycle summaries (`CycleSummaries`) and the operation hold (`operation_id`).
- Statuses: `pending`, `reserved`, `completed`, `failed`, `retrying`, `unknown`.
- `LifecycleModule` listens to `JobProcessing`, `JobExceptionOccurred`, `JobTimedOut`, `JobProcessed`, `JobFailed`, `JobAttempted` and `JobRetryRequested` on every database connection.

## The hot-path budget

`tests/Feature/Performance/HotPathBudgetTest.php` pins the warm cost of the success path exactly, per path and per statement. A round trip is one statement that a package session sends to the mirror (every `run()`, begin, commit, rollback and raw `exec()`, counted by `PackageStatements`).

| Path | Push | Reserve | Completion |
| --- | --- | --- | --- |
| Untagged | `insert orrery_jobs` | `update orrery_jobs` | `select orrery_jobs`, `update orrery_jobs` |
| Tagged | begin, `insert orrery_jobs`, `insert orrery_job_tags`, commit | `update orrery_jobs` | the same |

- `CEILING` (8 untagged, 11 tagged) is an upper bound next to the exact lists, so a later change cannot raise the budget by editing one list.
- The completion reads its row first: the rule needs the cycle, the accounting guard and the `reserved_at` fallback of the runtime.
- A tagged push refreshes the monitoring version and the monitored tags once per `MonitoredTagCache::TTL_SECONDS` (10 s); a separate test pins those two reads.
- A worker adds, outside the per-job budget: one signal read per second, one heartbeat write per 15 s, and one heartbeat write when a reservation's queue differs from the last written `current_queue`.
- The measured window runs on a frozen watcher clock (`Watcher::freezeMonotonicForTesting()`).
- `FailurePathBudgetTest` pins the statements of one failure with `CapturingUuidFailedJobProvider`, exactly per engine.
- Both run on every engine. Change a budget only with a spec change, and keep the tests exact.
