/**
 * The operator wording: one sentence template per
 * code that an operator can see. Every screen and sheet renders a source, an
 * awaiting reason, a result, an outcome, an operation state or a code only
 * through this module.
 *
 * A template says what happened and what the operator can do. `{name}` is
 * filled from stored data with `fill()`; a value that is missing shows as
 * "—", never as an empty text. A code without a template still shows: as a
 * sentence that names the code (`codeText()`), never as nothing.
 *
 * tests/Scripts/OperatorWordingTest.php fails when a code of the PHP sources
 * has no entry here. Keep each entry as `key: '…',` on its own line: the test
 * reads the keys that way.
 */

/** The `source` of a history entry and of a cycle. */
export const SOURCES = Object.freeze({
    push: 'Pushed by the application.',
    native: "Retried by Laravel's own queue:retry.",
    dashboard: 'Retried from the dashboard.',
    batch: 'Retried with the failed members of its batch.',
    bulk: 'Retried in a bulk run.',
    cli: 'Retried from the command line.',
    unknown: 'The source of this cycle is not known.',
});

/** The `awaiting_reason` of an operation that waits for an operator. */
export const AWAITING_REASONS = Object.freeze({
    sqlite_host_file: "Waiting for an operator: this step writes the application's SQLite file. Run php artisan orrery:resolve {id}.",
    sqlite_reads_not_allowed: "Waiting for an operator: Orrery may not read the application's SQLite file on its own. Run php artisan orrery:resolve {id}.",
});

/** The `unknown_reason` of an unknown job: why its outcome is not known. */
export const UNKNOWN_REASONS = Object.freeze({
    absent: 'The job left the jobs table, and no outcome was observed.',
    blind_gap: 'Orrery was off or not observing when the job left the jobs table, so its outcome was not observed. No stuck-job alert is sent for it.',
    connection_removed: 'Its queue connection is no longer configured, and no configured queue table holds the job.',
    bulk_unresolved: 'Orrery could not find the job of this bulk push in the jobs table.',
});

/** The `result` of a retry or forget, and the `outcome` of a bulk or group item. */
export const OUTCOMES = Object.freeze({
    accepted: 'Accepted.',
    deleted: 'Forgotten.',
    mirror_pending: "Forgotten; Orrery's own row is removed by the runner.",
    done: 'Done.',
    refused: 'Refused before anything changed.',
    not_eligible: 'Not eligible: nothing changed.',
    failed: 'Failed: nothing was published or deleted.',
    uncertain: 'Not decided yet: the runner decides it, and it is never retried as new work.',
    not_member: 'No longer part of this selection: not acted on.',
    already_done: 'Already done earlier: nothing repeated.',
    not_confirmed: 'Not forgotten: the confirmed number of records was already reached.',
});

/** The `state` of an operation in the ledger. */
export const OPERATION_STATES = Object.freeze({
    claimed: 'Claimed: the operation is starting.',
    published: 'Published: the job is back on its queue.',
    aborting: 'Stopping: the operation is being undone.',
    done: 'Done.',
    aborted: 'Stopped: nothing was published or deleted.',
});

/**
 * Every code of a refusal, a non-eligible answer, a failure, an uncertain
 * answer, an operation `error_code`, a bulk or group item, and the HTTP
 * errors of the bulk and job routes.
 */
export const CODES = Object.freeze({
    // Refused before a claim.
    provider_read_only: "The failed-job storage is read-only for Orrery. Use Laravel's queue:retry or queue:forget.",
    ids_may_be_reused: "The failed-job table can hand out an id again, so Orrery cannot prove which record is this one. Use Laravel's queue:retry or queue:forget.",
    occurrence_unprovable: "Orrery cannot prove which failed record belongs to this failure. Use Laravel's own queue commands for it.",
    fence_missing: 'The fence table is missing. Run php artisan orrery:install --fence and php artisan migrate.',
    queue_unreachable: 'The queue database cannot be reached. Try again when it is back.',
    mirror_unreachable: "Orrery's own storage cannot be reached. Try again when it is back.",
    record_unreachable: 'The failed-job storage cannot be reached. Try again when it is back.',
    target_changed: 'The failed-job storage changed since this failure. Check the queue configuration.',
    scope_changed: 'The failed-job storage changed between the count and the run: nothing was forgotten. Run the command again.',
    schema_outdated: "Orrery's tables are older than this version. Follow the upgrade steps in docs/upgrading.md; the health footer shows the command.",
    app_key_missing: 'The application has no APP_KEY, so a bulk forget cannot be signed. Set APP_KEY first.',
    unknown_source: 'The request named an unknown source: nothing changed.',

    // Not eligible: nothing changed.
    not_failed: 'This job is no longer failed: nothing changed.',
    occurrence_mismatch: 'This failure was replaced by a newer one: reload the page and decide again.',
    operation_in_progress: 'Another operation on this job is still running: wait for it to finish.',
    record_missing: 'The failed-job record is gone: nothing changed.',

    // Failed, and the operation error codes.
    prepare_failed: 'The retry could not be prepared: nothing was published.',
    claim_failed: 'The operation could not start: nothing changed. Try again.',
    record_gone: 'The failed-job record was deleted by something else: nothing was published.',
    record_delete_failed: 'The failed-job record could not be deleted: nothing was published.',
    cancelled: 'The runner cancelled this operation before it took effect: nothing was published or deleted. Try again.',
    deadline_passed: 'The operation did not finish before its deadline: nothing changed. Try again.',
    publish_failed: 'The job could not be put back on its queue: nothing was published.',
    provider_error: 'The failed-job storage answered with an error: the record was not deleted.',
    row_moved: 'The job changed while the operation finished; the operation is done.',
    restore_conflict: 'The failed-job record could not be put back, because another record took its place. Check the failed-job table.',
    restore_failed: 'The failed-job record could not be put back yet. The runner tries again.',
    resolution_failed: 'The outcome of the publication could not be read yet. The runner tries again.',
    finalize_failed: 'The job was published, but its record here was not updated yet. The runner finishes it.',
    delete_decision_failed: 'Whether the failed-job record was deleted could not be read yet. The runner tries again.',
    awaiting_operator: 'The operation stopped, and its next step needs an operator. Resolve it on the job page, or run php artisan orrery:resolve {id}.',
    mirror_error: "Orrery's own storage answered with an error. The runner tries again.",
    unexpected_state: 'The operation is in a state that this step does not expect. The runner checks it again.',
    decide_failed: 'Whether the record was deleted could not be decided yet. The runner tries again.',
    handler_missing: 'No handler is registered for this operation. Check the installed version.',
    check_failed: 'The check of this operation failed. The runner tries again.',

    // Uncertain: the runner decides.
    claim_uncertain: 'It is not known yet whether the operation started. The runner decides it; it is never retried as new work.',
    publish_uncertain: 'It is not known yet whether the job was published. The runner decides it; it is never retried as new work.',
    record_delete_uncertain: 'It is not known yet whether the failed-job record was deleted. The runner decides it.',

    // Bulk and group items.
    missing: 'This job is no longer kept.',
    occurrence_changed: 'This job failed again after the selection: not acted on.',
    other_group: 'This failure now belongs to another group: not acted on.',
    out_of_scope: 'This failure is outside the selected queue or time range: not acted on.',
    retry_in_progress: 'A retry of this job is still running.',
    retried: 'This failure was already retried.',
    gone: 'This failure was already forgotten.',
    mirror_pending: "The record is deleted; Orrery's own row is removed by the runner.",
    retry_uncertain: 'It is not known yet whether the retry was published. The runner decides it.',

    // HTTP errors of the bulk and job routes.
    frozen_invalid: 'The selection was changed or damaged in transit: start a new one.',
    frozen_expired: 'This run was idle for more than an hour; start a new one.',
    confirmation_mismatch: 'The typed number does not match the total: type the total that is shown.',
    frozen_mismatch: 'Another person runs this selection with another confirmation: start a new one.',
    total_not_exact: 'The total is not exact, so a forget cannot start. Narrow the selection.',
    too_many: 'More than {maximum} records match, so a forget cannot start. Narrow the selection.',
    config_invalid: "The bulk configuration is invalid (orrery.bulk.forget_max). Check config/orrery.php.",
    index_missing: 'An index is missing. Run php artisan orrery:install --indexes.',

    // A list source with a search text (the SPA refuses it before a freeze).
    search_not_in_bulk: 'Clear the search to act on all matching jobs: the search is not part of a bulk run.',
    // A link filter that the screen cannot apply (support/lists.js queryProblem(), or the list's 422 on a filter).
    queue_needs_connection: 'The link names the queue “{queue}” without its connection, so the list is not filtered and not shown. Choose the queue in the filter, or clear the filters.',
    filter_invalid: 'The filter “{field}” of this link cannot be applied, so the list is not shown. Choose the filter again, or clear the filters.',
    redaction_config_invalid: 'The redaction configuration is invalid, so job data is not shown. Check orrery.redact in config/orrery.php.',

    // HTTP errors of the job detail, its history, the writes, the tags API and the other routes.
    invalid: 'The request was not valid: nothing changed. Reload the page and try again.',
    not_found: 'Not found: it no longer exists or is no longer kept. Nothing changed.',
    read_timeout: 'A read took too long. Narrow the selection and try again.',
    read_failed: "Orrery's storage could not be read. Try again.",
    write_failed: 'The change may not have been saved. Reload to see the current state; trying again is safe.',
    record_failed: 'Orrery could not save its record of this change, so the dashboard may not show who made it. Reload to see the current state.',
    header_missing: 'The server refused the change because the request came without its X-Requested-With header. Reload the page; if it happens again, check that no proxy removes the header.',
    query_too_long: 'The address has too many parameters to be read in full, so nothing was read. Use fewer filters and try again.',

    // The writes on waiting jobs.
    in_transaction: 'The queue connection was inside a transaction, so nothing was cleared. Try again.',
    job_id_unknown: "Orrery does not know this job's row in the queue table yet: it came in a bulk push that is still being matched. Try again in a minute.",
    not_database_queue: 'This queue connection is not a database queue (any more), so nothing changed. Check the queue configuration.',

    // The bulk run of a screen.
    bulk_run_in_progress: 'A bulk run is in progress here. If you leave this screen, it stops after its current page; nothing is undone.',
    bulk_run_busy: 'A bulk run is already in progress here. Wait until it ends, or stop it, before you start another.',
    no_bulk_members: 'No failed job matches these filters: there is nothing to act on.',

    // The batches.
    batching_unavailable: 'Batching is not set up: the batch table is missing.',
    unsupported_repository: 'Batches are unknown: the application stores them in a way that Orrery does not read (only Laravel\'s database batch repository is read).',
    batches_sqlite_reads_not_allowed: "Batches are unknown: Orrery may not read the application's SQLite file on its own. It reads it only in WAL mode, with a busy_timeout above 0, without EXCLUSIVE locking, and with orrery.sqlite_automatic_access on.",
    batches_unknown: 'Batches are unknown: the server did not say why.',
    already_cancelled: 'This batch was already cancelled: nothing changed.',
    already_finished: 'This batch has already finished: nothing changed.',
    batch_state_unknown: 'The state of this batch could not be read, so it was not cancelled. Try again.',
});

const MISSING = '—';

/**
 * Fills `{name}` in a template from `values`. A value that is null,
 * undefined or empty shows as "—"; a name that the template does not use is
 * ignored. The values are text, never markup: the screens render the result
 * as text.
 */
export function fill(template, values = {}) {
    return String(template).replace(/\{([a-z_]+)\}/g, (match, name) => {
        const value = values ? values[name] : undefined;

        return value === null || value === undefined || value === '' ? MISSING : String(value);
    });
}

function lookup(table, key, values, fallback) {
    if (typeof key === 'string' && Object.prototype.hasOwnProperty.call(table, key)) {
        return fill(table[key], values);
    }

    return fallback;
}

/** The sentence of a history or cycle source. */
export function sourceText(source) {
    return lookup(SOURCES, source, {}, SOURCES.unknown);
}

/** The short label of each unknown reason, for a table cell: never the raw code. */
export const UNKNOWN_REASON_LABELS = Object.freeze({
    absent: 'left the queue unobserved',
    blind_gap: 'Orrery was off or not observing',
    connection_removed: 'connection removed',
    bulk_unresolved: 'bulk job not found',
});

/** The short label of an unknown job's reason; a missing or new one is "reason unknown". */
export function unknownReasonLabel(reason) {
    return lookup(UNKNOWN_REASON_LABELS, reason, {}, 'reason unknown');
}

/** The sentence of an unknown job's reason; a missing or new reason says that the reason is not known. */
export function unknownReasonText(reason) {
    return lookup(UNKNOWN_REASONS, reason, {}, 'The reason is not known.');
}

/** The sentence of an awaiting reason; `values.id` is the operation id. */
export function awaitingText(reason, values = {}) {
    return lookup(AWAITING_REASONS, reason, values, fill('Waiting for an operator ({reason}). Run php artisan orrery:resolve {id}.', {...values, reason}));
}

/** The sentence of a result or an outcome. */
export function outcomeText(outcome) {
    return lookup(OUTCOMES, outcome, {}, fill('The server answered {outcome}.', {outcome}));
}

/** The short label of each outcome, for a table cell and after a count: never the raw code. */
export const OUTCOME_LABELS = Object.freeze({
    accepted: 'accepted',
    refused: 'refused',
    uncertain: 'uncertain',
    not_member: 'outside the selection',
    already_done: 'already done',
    not_confirmed: 'not confirmed',
});

/** The short label of an outcome; an unknown one is "unknown outcome". */
export function outcomeLabel(outcome) {
    return lookup(OUTCOME_LABELS, outcome, {}, 'unknown outcome');
}

/** The short label of each operation state, for a chip: never the raw code. */
export const OPERATION_STATE_LABELS = Object.freeze({
    claimed: 'starting',
    published: 'published',
    aborting: 'stopping',
    done: 'done',
    aborted: 'stopped',
});

/** The short label of an operation state; an unknown one is "state unknown". */
export function stateLabel(state) {
    return lookup(OPERATION_STATE_LABELS, state, {}, 'state unknown');
}

/** The sentence of an operation state. */
export function stateText(state) {
    return lookup(OPERATION_STATES, state, {}, fill('The operation is {state}.', {state}));
}

/**
 * The sentence of a code. A code without a template names itself, so an
 * unknown code is never shown as nothing; a null code gives null.
 */
export function codeText(code, values = {}) {
    if (code === null || code === undefined || code === '') return null;

    return lookup(CODES, code, values, fill('The server answered with the code {code}.', {code}));
}

/**
 * The sentence of a write item (a per-job write, a bulk or group item): an
 * awaiting reason first, then the code, else the outcome. `result` or
 * `outcome` names the outcome; `operation_id` fills `{id}`.
 */
export function itemText(item) {
    if (!item || typeof item !== 'object') return null;

    const values = {id: item.operation_id};

    if (item.awaiting_reason) return awaitingText(item.awaiting_reason, values);

    const code = codeText(item.code, values);

    if (code !== null) return code;

    return outcomeText(item.outcome !== undefined ? item.outcome : item.result);
}
