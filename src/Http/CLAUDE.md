# src/Http/

The controllers' docblocks hold the contract of each endpoint, and this file holds the shared conventions. (Maintainers also keep the full API spec privately, section 4 of `.internal/specs/2026-11-08-d8-ui-api-end-state.md`.)

## Routes and authorization

- Routes in `routes/web.php` mount under `orrery.path` (default `orrery`), behind the `orrery` middleware group and `Middleware\Authenticate`. Register each new API route before the catch-all (`ApiNotFoundController`), which answers an unknown `/api/...` path with a JSON 404.
- Two abilities, checked by the shipped gates for the user of `orrery.guard` (`Authenticate::guard()`); a guard that cannot give a user denies. `viewOrrery` guards every route; when it fails, no user on `orrery.guard` gives 401 `unauthenticated`, a user gives 403 `forbidden`. `operateOrrery` also guards every write route (every method except GET, HEAD, OPTIONS, TRACE), else 403 `forbidden_action`. The check is by method, so a new write route cannot forget it; `OperateAbilityTest` counts the write routes. The `local` environment always has access; without an `Orrery::operateUsing()` callback only `local` can operate. The page route answers plain HTTP errors.
- `orrery:install` publishes `stubs/OrreryServiceProvider.stub`, where the user defines the gates.
- `Orrery::scriptVariables()` puts `canOperate`, `appName`, `environment` and more in `window.Orrery`.

## Conventions

- Times are numeric epoch seconds (fractional allowed), sent with `JSON_PRESERVE_ZERO_FRACTION`. Every decimal column is cast to a float before encoding; no response sends a decimal as a string.
- Windows are half-open `[from, to)`. A bucket starts at `floor(epoch / bucket_seconds) * bucket_seconds`; SQL never groups by a date function in a time zone.
- A series has `as_of`, `bucket_seconds`, `retention_from` and `buckets` (`{at, …values, partial}`). `retention_from` is a horizon, not a completeness proof. An unknown bucket is absent or null, never zero-filled.
- Every JSON error of the package has `error` (a stable code); most also have `message`. The SPA decides on `error`, never `message`. An invalid parameter gives 422; the 422 of `POST /api/control` is Laravel's validation body (`{message, errors}`), without `error`.
- Every write (a method that is not safe) needs `X-Requested-With: XMLHttpRequest`, else 403 `header_missing` (`Authenticate`). The SPA's axios sends it; tests get it from `TestCase::setUp()`.
- A capped count answers `{count, at_least}`; a failed count answers `{count: null, at_least: false}` and is reported.
- Reads run on `PackageSession::bounded()`. A timeout gives 503 `read_timeout` for a list, or a null part for a section of a composite response. Each extra part of a composite response runs in `Watcher::run()`, so a failure gives null, not a 500. Every read of the package storage runs in the watcher (`Controller::watchedRead()`): a failure of the whole read gives a JSON 503 (`read_timeout` or `read_failed`), never a raw 500.
- No job value in an error (`SafeError`). Write responses carry no job text.
- A queue name is never a path segment: a browser resolves `.` and `..` segments (also encoded ones), so `x/../default` would reach another queue. Queue routes take the connection and the queue as query values or JSON body values.

## Lists, detail and projection

- `GET /api/jobs` (`Lists\JobList`): each tab reads one access path in index order, never sorted in memory. Filters are byte-exact (MySQL `CAST(… AS BINARY)`, and PHP `===`). Index-only lists count with `CappedCount` (`dashboard.list_count_cap`); residual filters scan within `dashboard.search_candidates` and report `search_limited`. Cursors (`next_cursor`, `prev_cursor`, `head`, `has_newer`), not offsets. List reads never select `payload`, `exception` or `context`.
- `GET /api/jobs/stuck` (`StuckList`) lists the stuck signals; a missing deferred index answers `index_missing`.
- `GET /api/jobs/{uuid}` and its history: every job value goes through `Projection\JobProjection` and `Redactor` (`orrery.redact`). The projection never returns raw serialized PHP (`SerializedValueParser`; in free text, `Redactor` replaces each serialized fragment), and hidden Context is never shown. No viewer read calls `unserialize()`; the batch reader is data-only. A natural JSON object with only the keys of a projection shape (a marker, `integer`, `array`, …) is sent wrapped as `{"map": {...}}` (`JobProjection::natural()`), so the SPA never draws data as a marker.

## Writes

- `POST /api/jobs/{uuid}/retry` and `/forget` answer per occurrence with the `RetryService` or `ForgetService` result.
- **Operator actions on the queue tables** (`Actions\*`, each inside `asOperator()`): `POST /api/jobs/{uuid}/delete` and `/run-now` (200 `{id, result}`; `DelayedJobsController` for the run-now), `POST /api/queues/waiting` (a read-only POST: the clear's count) and `POST /api/queues/clear` (`WaitingJobsController`; `{connection, queue}` in the JSON body), `POST /api/batches/{id}/cancel` (`BatchesController::cancel()`, one guarded update, byte-exact id; no `BatchCanceled` event). Each write is recorded by `Actions\OperatorAudit`: the event `OperatorActionPerformed` and one `info` log line, outside the watcher (an open breaker never skips them; a failure is reported), with `Orrery::actor()`. There is no stored activity log. Refusals answer 409 with a code; an error of the queue connection 503 `write_failed`.
- **Bulk runs** (`Bulk\*`, `BulkController`): `POST /api/bulk/freeze` (a read-only POST: it writes nothing) freezes a source (failure groups, the failed list's filters, or a batch) into a signed token with `max_occurrence` and a total. `POST /api/bulk/run` processes the next page (at most 200 members, `BulkRun::PAGE_SECONDS`), re-checks each member, and calls the services one by one. The first request inserts the audit row (`orrery_bulk_runs`); each claim adds to its `claimed` count, and a forget claims only while `claimed < confirmed_total`, so a forget run deletes at most the confirmed total. A forget needs an exact total within `bulk.forget_max`. The client holds the cursor. `GET /api/bulk` and `GET /api/bulk/{ref}` show recent runs and their ledger states.
- `POST /api/control` (a failed write gives 503 `write_failed`; a queue pause or continue whose Laravel part happened before the package record failed gives 503 `record_failed` with `paused`, which the SPA shows as a warning, not a failure), tag monitoring (`POST`/`DELETE /api/tags/monitored…`), `POST /api/operations/{id}/resolve` (200, 404, 409 `target_changed`, 503).

## Other endpoints

`/api/workload` (`DatabaseWorkloadRepository` documents each field), `/api/workers`, `/api/health`, `/api/counts`, `/api/control`, `/api/tags`, `/api/batches`, `/api/throughput`, `/api/markers`, `/api/queues/history?connection=&queue=`, `/api/settings/retention`, `/api/classes` and `/api/classes/series`, `/api/failures/groups` and `/api/failures/groups/{key}` (byte-exact, through `orrery_jobs.failure_key`, capped by `dashboard.group_count_cap`).

## The page

`Orrery::css()` and `Orrery::js()` inline `dist/` into `resources/views/layout.blade.php` and add `nonce="…"` when `Vite::cspNonce()` returns one. `docs/security.md` documents the Content Security Policy.
