# src/Observation/

## Classification

One payload callback (`Queue::createPayloadUsing()`, `PayloadClassifier`) classifies every `push`, `later` and `bulk` payload of a `database` connection. An object job gets `type`, normalised `tags` (at most `tags.max_per_job`), `tagsTruncated`, `silenced`, `pushedAt` and the generation `orreryGen` (32 random hex characters). A string job gets the same without tags. A classification error or an open breaker adds no key, and the job is still queued.

## The swapped queue

With `swap_connector` on (the default), `Connectors\DatabaseConnector` returns the package's `DatabaseQueue`. Every hook calls its parent exactly once, outside any watcher, and builds its observation from the parent's return value. The parent's return value and exception reach the caller unchanged.

- `pushToDatabase()` (push, later) observes the insert, unless it belongs to `pushRaw()` or to a release of this instance (a one-shot owned-insert flag).
- `pushRaw()` stamps a new generation and observes a republish.
- `pop()`: the heartbeat (worker processes only), the control check, then the reservation with this worker's identity. It forwards every argument.
- `deleteAndRelease()` and `release()`: the release with the new job id.
- `bulk()`: `parent::bulk()` unchanged with the call's capture (`Queue\BulkCapture`), then `BulkResolver::observe()` of the records built during the call, and of Laravel 13's after-commit insert at its own time. One path serves every Laravel release.
- A payload without a canonical-UUID `uuid` or `id` is never mirrored.

**Uninstrumented connections.** With the swap off, or when another package's connector wins the `database` binding, only outcomes are mirrored: no `pending` or `reserved` row. `Queue\Instrumentation::uninstrumented()` lists those connections (`/api/health`, `/api/workload` with `instrumented: false`).

## Commit deferral

`CommitDeferral::around()` captures the queue connection instance before the parent call and decides after it. It uses only Laravel's public transaction API (the level, the `beforeStartingTransaction()` and `beforeExecuting()` callbacks, the transaction records and their callbacks).

- At level 0 after the call, the item runs at once.
- Else it joins the last pending level-1 record of the instance (the root) with its savepoint chain, and runs at the root's commit when every chain record has a commit mark. A savepoint is decided by its commit mark, never by its rollback callback.
- The root's commit proves the commit only when the instance is at level 0, has the same PDO, and that PDO is not in a transaction. Else the items are counted.
- The stale rule: a level-0 begin on a watched instance drops and counts every tracked root of the name that can no longer commit.
- Two budgets, both checked before a root is tracked: the count (`MAX_HELD`) and the bytes (`byteBudget()`: min(`MAX_HELD_BYTES`, `memory_limit` / `MEMORY_SHARE`)). Each hook passes the byte estimate of its item (`estimate()`: the payload strings plus a fixed size per observation; a bulk insert all its payloads, so a whole `Bus::batch()` is one item). The held bytes follow every move of an item: `hold()`, `untrack()` and the walk transfer. An item over a budget, also one larger than the whole byte budget, is dropped with its reason (`DropCounter::overBudget()`, `count_cap` or `byte_budget`): `unverified`, and the reason in the window's errors (`Degradation::reason()`: the last error only while no other is set). A dropped push stays invisible until its reservation creates the row (R7).
- A hook frees the observation's decoded payload copy as soon as the observation is built, and again after its write (`Observation::forgetDecoded()`): a held item keeps only its payload string, and the write decodes the payload once more (no round trip).
- A rollback counts its items: `unverified` for ambiguous ones, `rollback_unverified` for the others (a lost COMMIT acknowledgement cannot be ruled out).
- Units of work: `beginUnit()` returns a token; `endUnit($token)` counts what that unit left. `ObservationModule` opens a unit per job (`JobProcessing` to `JobAttempted`), per command, and per request or process.
- Under Laravel's testing transactions manager (`RefreshDatabase`, `DatabaseTransactions`), a pop or release inside the wrapper transaction is counted `unverified` at once, and a push waits for a root that never commits.
- `WatchRegistry` keeps one entry per connection instance. Both are process singletons.
