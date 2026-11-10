# Lifecycle rules

`Lifecycle\Rules::decide()` applies one observation (a push, a reservation, a release or an outcome) to the job's row in `orrery_jobs`. `Lifecycle\JobStore::observe()` writes the decision. Each rule is a method of `Rules`, and `Decision::$rule` holds the name of the method that decided. A combination that no rule accepts gives `Decision::REJECTED`.

The rules also have short ids: P1, R1 and so on. The table maps each id to its method.

## The rules

| Id | Method | Rule |
| --- | --- | --- |
| P1 | `push` | A push or a republish of a job without a row creates the row as `pending`. |
| P2 | `bulkPush` | A job of a bulk insert without a row creates the row as `pending`, with an unresolved job id and the bulk's floor id. |
| R1 | `reserve` | The first reservation of a `pending` row (also of its unresolved bulk row) makes it `reserved`. |
| R2 | `reclaim` | A `reserved` row that is reserved again at a higher attempt (its lease expired) stays `reserved` and counts a reclaim. |
| R3 | `reserveReplacement` | An active row that is reserved under another job id (the row of a release), at the same or a higher attempt, becomes `reserved`; the old id becomes `prev_job_id`. |
| R4 | `adoptRetryReservation` | The reservation of a package retry's publication finalises the retry, then applies the reservation in the same transaction. |
| R5 | `nativeRetry` | The first reservation of an unobserved native retry of a `failed` row (attempt 1, a job id higher than the row's job id and its cycle's first job id, and a known generation on an instrumented connection) freezes the cycle summary and starts the next cycle. |
| R6 | `reserveUnknown` | An `unknown` row that is reserved again under the same job id becomes `reserved` and leaves `unknown`. |
| R7 | `reserveWithoutRow` | A reservation of a job without a row (its push was not observed) creates the row as `reserved`. |
| R8 | `fillWorker` | A verified worker fills the worker columns of the current reservation, which only a committed read confirmed. |
| L1 | `release` | A release of the current reservation makes the row `pending` under the new job id; the old id becomes `prev_job_id`. A release at a higher attempt of the reserved job id (a reclaim that was not observed), or of a `pending` row's own job id (a reservation that was not observed), is accepted too; a `reserved` row's `attempts` rise to the release's attempt. |
| E1 | `exception` | An exception or a timeout of the current or the previous job id counts once per higher attempt and adds a history row; the status does not change. |
| C1 | `complete` | A completion of the current cycle makes the row `completed`. |
| C2 | `adoptRetryCompletion` | The completion of a package retry's publication, whose reservation was not observed, finalises the retry, then applies the completion. |
| C3 | `completeWithoutRow` | A completion of a job without a row creates the row as `completed`. |
| F1 | `fail` | A failure of the current cycle makes the row `failed` and starts a new occurrence. |
| F1 | `failWithoutRow` | A failure of a job without a row creates the row as `failed`, with a new occurrence. |
| F1 | `adoptRetryFailure` | The failure of a package retry's publication, whose reservation was not observed, finalises the retry, then applies the failure. |
| U1 | `markUnknown` | Reconciliation found the job of a `pending` or `reserved` row absent from `jobs`: the row becomes `unknown`. |
| O | `olderCycle` | An outcome of an older generation that has a retained cycle summary goes to that summary; the row does not change. |

`F1` had three cases under one id. Each case now has its own method.

## Decision values that are not rules

- `rejected` (`Decision::REJECTED`): no rule accepts the observation. With the `unmatched` effect it also counts `Degradation::unmatched()`.
- `accounted` (`Decision::ACCOUNTED`): the transition is rejected, but the outcome accounting changes the counters.
- `held` (`Decision::HELD`): the store found that the row already holds the observation's result (a duplicate, or a statement that Laravel ran again after a lost acknowledgement).

## Conventions of every rule

These cases are part of the rules.

- **S, the same cycle.** `Rules::sameGeneration()` compares the generations when the connection is instrumented and both generations are known. Else it compares the job ids: the row's job id, its previous job id, or a higher id at attempt 2 or more.
- **Adoption before S.** A package retry's generation is its operation id, so it differs from the row's `cycle_gen`. The adoption rules are decided before S, on a `retrying` row whose `operation_id` is the observation's generation.
- **`activity_at`.** Every status transition writes it. `fillWorker` and `exception` are bookkeeping and keep it.
- **Duplicates of the current reservation.** A reservation with the same generation, job id and attempt as the current one is rejected, in `reserved` and in `unknown`. So a read-confirmed duplicate never erases a verified worker.
- **The cycle of a created row.** A row that `reserveWithoutRow`, `completeWithoutRow` or `failWithoutRow` creates takes its cycle from `orreryRetry.cycle` when `orreryRetry.operation` is the observation's own generation, with an inferred retry count. Its `cycle_source` is `unknown`, and it has no `cycle_started_at`, because its cycle start was not observed.
- **Leaving `unknown`.** A transition away from `unknown` clears `unknown_since` and `unknown_reason`. A reservation also clears `finished_at`.
- **A native retry at the outcome.** A queue object that is not the package's observes only outcomes. A completion or a failure of attempt 1, with a higher job id, on a `failed` row whose native retry was requested after its failure, starts the next cycle as `nativeRetry` does. Its decision is named `complete` or `fail`, with the `cycle_start` effect.
- **A higher attempt of the reserved job id.** A completion, a failure or a release of the `reserved` row's job id at a higher attempt than the row's is accepted (its reclaim was not observed), and it raises `attempts` to that attempt. On a `pending` row `attempts` counts the observed reservations only. Its runtime has no `reserved_at` fallback: only a `reserved` row at the completed attempt gives one.
- **A reservation of an older generation.** A reservation whose generation is older than the row's, when an older cycle is retained, is rejected: a reservation is no outcome evidence, and the row keeps its cycle (`olderCycle` applies only to outcomes).
- **A reservation of a `failed` row by another job id.** When the generations are not compared (the swap off, or a generation is unknown), a reservation of a `failed` row under a job id that is neither the row's job id nor its previous job id is unmatched: it may be a native retry whose first reservation was missed (an ambiguity of the protocol). Such a reservation reaches this check only through the id fallback of `sameGeneration()` (a higher id at attempt 2 or more).
- **The predicates of `complete` and `fail`** (`outcomeAllowed()`): the same cycle, a job id and an attempt, and one of: the `reserved` row's job id at the row's attempt or a higher one; the job id of a `pending` or `unknown` row; or another job id of an active row at the row's attempt or a higher one (the row of a release).
- **`attempts` from an outcome.** On an instrumented connection only the `reserved` row's own job id at a higher attempt raises `attempts`; with the swap off, an outcome raises it to the larger of the two.
- **The guard.** Every revision of an existing row re-checks the columns that it read (status, cycle, generation, job ids, attempts, worker and the three accounting columns) in its `WHERE` clause.
