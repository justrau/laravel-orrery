# src/Retry/ (and src/Failed/)

## The ledger

- `orrery_operations` on the mirror (`Operations`) is the single authority of retry and forget, and the audit log of operator actions. Each operation has an `action` (`retry`, `forget`), a `mode` (`in_tx`, `before`, `none`), a `source` (`dashboard`, `batch`, `bulk`, `cli`), an optional `bulk_ref`, and a `state`: `claimed` → `published` → `done` (a retry), `claimed` → `done` (a forget, and a retry that a worker adopted), or `claimed` → `aborting` → `aborted`. Every transition is guarded, with one winner. A claimed occurrence's mirror row holds `operation_id`.
- Time alone never decides an outcome.

## The fence

- `orrery_fences` (`Fence`), keyed by (`operation_id`, `slot`), lives in every database where an operation has an effect outside the mirror: the queue database of a retry, the failed-record database of a delete and a restore. Every effect inserts its fence row in the same transaction as the effect, so a stale or concurrent actor loses on the primary key.
- Slots: `publish` (`published` or `cancelled`), `delete` (`deleted` or `delete_cancelled`), `restore` (`restored`). `insertBefore()` inserts only while that database's clock is at or before the operation's deadline.
- Clocks: `deadline` is in the queue database's clock (retry) or the mirror's (forget), `record_deadline` in the failed-record database's clock, `next_check_at` and `awaiting_since` in the mirror's.
- The main migration creates `orrery_fences` on the mirror. Every other database that needs one gets a migration from `orrery:install --fence` (`stubs/fence-migration.stub`). `FenceTargets::report()` lists the missing ones for `/api/health`.

## The supported failers (`Failed\FailedRecords::for()`, read at action time)

| Bound failer | Adapter | Retry | Forget |
| --- | --- | --- | --- |
| `CapturingUuidFailedJobProvider` on a table whose ids are never reused | `SqlFailedRecords` | mode `in_tx` or `before` | exact delete |
| `CapturingUuidFailedJobProvider` on another table | `ReadOnlyFailedRecords` | refused `ids_may_be_reused` | refused |
| `NullFailedJobProvider` | `NoFailedRecords` | mode `none` (the mirror payload) | the mirror only |
| anything else (file, DynamoDB, legacy `database`, wrappers, subclasses) | `ReadOnlyFailedRecords` | refused `provider_read_only` | refused |

- `Failed\ProviderRegistrar` replaces the failer with `CapturingUuidFailedJobProvider` only when `queue.failed.driver` is `database-uuids`, the failer is exactly Laravel's `DatabaseUuidFailedJobProvider`, and it equals a public construction from the config (`FailerProvenance`). Any other failer stays, and retry and forget are read-only for it.
- **The identity of a failed record.** `CapturingUuidFailedJobProvider::log()` runs Laravel's insert and binds the inserted id to the occurrence that this failure's `fail` write created (the scoped `OccurrenceSlot`, one guarded update). The id must belong to that statement: `INSERT … RETURNING id` on SQLite 3.35+ and Postgres; `insertGetId()` with a statement counter on MySQL and MariaDB; else nothing is bound. A row without a binding is refused (`occurrence_unprovable`) and counted in `/api/health`.

## Retry (`RetryService::retry()`)

An operator action. It refuses inside an open mirror transaction with a `LogicException`.

1. Refusals before any write (adapter, row, binding and target, fence tables, clocks).
2. The record by the identity rule, and the payload (`RetryPayload`).
3. The claim, in one mirror transaction: the guarded row update, the `claimed` operation, the row's failed tags deleted (and the bulk run's claim count, see `src/Http/CLAUDE.md`).
4. Mode `before`: the fenced exact delete on the failed-record session.
5. The publication (`Publication::publish()`) in one transaction on the queue's session: the conditional fence insert, in mode `in_tx` the exact record delete, `pushRaw()` through Laravel's own `DatabaseQueue`, COMMIT. No clock read and no PHP work inside.
6. After COMMIT: `published`, then `RetryFinalizer` starts the next cycle.

An error before COMMIT aborts; a COMMIT error gives `uncertain`, and `Publication::resolve()` decides through the fence. An abort restores a deleted record (`RetryHandler`, slot `restore`).

## Forget (`ForgetService::forget()`)

Refusals; the record; the claim (hold the row, `claimed`, deadlines = now + `WINDOW_SECONDS`, 60); mode `before`: the fenced `DELETE … WHERE id = ? AND uuid = ?`; the mirror delete with its dependents and `done`. A COMMIT error is uncertain, and the runner decides.

`orrery:forget --all` (`ForgetAllRecords`): a keyset walk over the failed table by `id`, bounded by the largest id at its start; each delete is its own autocommit statement; the scope is byte-exact.

## The runner and the resolver

- `OperationRunner::runOnce()` (tick phase `operations`): one fair pass over the due operations after a persisted cursor, at most `MAX_INSPECTED` (100) and `CAP_SECONDS` (5 s) per run. It stops at the first blocking failure and defers that connection. Backoff: 1, 5, 15, 60 minutes, then hourly. Handlers: `RetryHandler` and `ForgetService` (tag `OperationRunner::HANDLERS`).
- `OperationResolver`: `POST /api/operations/{id}/resolve`, `orrery:resolve`, and a later retry or forget of a held occurrence run the pending step as an operator action. A step whose fingerprint check fails changes nothing (`target_changed`).
- On a host SQLite file, a step that needs a write waits for an operator (`AwaitingOperator`).
