/**
 * The pure rules of the Job detail screen:
 * which request to send, what the header and the inspector say, which
 * write is offered and what its confirmation freezes, how a write or read
 * answer reads, and how the data-only projection becomes a tree. JobDetail.vue
 * and PayloadTree.vue stay thin; Node tests these rules.
 *
 * The rules of the SPA hold:
 * - every time is epoch seconds through toSeconds() and is shown with its
 *   zone (formatLocal());
 * - unknown is never zero: a null value is "—" or "unknown", a part that
 *   could not be read is unknown, never empty;
 * - a write posts exactly the frozen selection of its confirmation: the
 *   occurrence id of the page read, never a value read later;
 * - every source, awaiting reason, result, state and code is worded through
 *   support/wording.js;
 * - every text is plain text filled from stored data, never markup.
 */

import {isUuid} from '../../support/lists.js';
import {queuePath} from '../../support/redirects.js';
import {formatCount, formatLocal, relativeTime, timeZoneLabel, toSeconds} from '../../support/time.js';
import {awaitingText, codeText, itemText, outcomeText, sourceText, stateText} from '../../support/wording.js';
import {enforcedRuleText, terminalText, timeoutValue, triesSourceText, triesValue} from './rules.js';
import {tagLocation} from '../tags/tag-links.js';

const OPERATION_ID = /^[0-9a-f]{32}$/;

function isNumber(value) {
    return typeof value === 'number' && Number.isFinite(value);
}

/** An integer beyond ±(2^53 − 1), which the server sends as its decimal digits: {"integer": "1234567890123456789"}. */
function isExactInteger(value) {
    return isObject(value) && keysAre(value, ['integer']) && typeof value.integer === 'string' && /^-?\d+$/.test(value.integer);
}

function isObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function plural(count, one, many) {
    return count === 1 ? `1 ${one}` : `${formatCount(count)} ${many}`;
}

// ---------------------------------------------------------------------------
// Requests
// ---------------------------------------------------------------------------

/** `GET /api/jobs/{uuid}`, or null for a value that is not a full uuid. */
export function detailUrl(uuid) {
    return isUuid(uuid) ? `/api/jobs/${uuid.toLowerCase()}` : null;
}

/** `GET /api/jobs/{uuid}/history`, or null. */
export function historyUrl(uuid) {
    return isUuid(uuid) ? `/api/jobs/${uuid.toLowerCase()}/history` : null;
}

/** `GET /api/jobs/{uuid}/history/{id}` for a whole entry id, or null. */
export function entryUrl(uuid, id) {
    return isUuid(uuid) && Number.isInteger(id) && id > 0 ? `/api/jobs/${uuid.toLowerCase()}/history/${id}` : null;
}

/** The query of a history page: the cycle filter and the cursor, when set. */
export function historyParams({cycle = null, cursor = null} = {}) {
    const params = {};

    if (Number.isInteger(cycle) && cycle >= 1) params.cycle = cycle;
    if (typeof cursor === 'string' && cursor !== '') params.cursor = cursor;

    return params;
}

// ---------------------------------------------------------------------------
// Header
// ---------------------------------------------------------------------------

const TAB_LABELS = {
    pending: 'Pending', running: 'Running', completed: 'Completed', silenced: 'Silenced',
    failed: 'Failed', retrying: 'Retrying', unknown: 'Unknown',
};

/** The Jobs list tab of a row's status, or null. */
export function listTab(status, silenced) {
    switch (status) {
        case 'pending': return 'pending';
        case 'reserved': return 'running';
        case 'completed': return silenced === true ? 'silenced' : 'completed';
        case 'failed': return 'failed';
        case 'retrying': return 'retrying';
        case 'unknown': return 'unknown';
        default: return null;
    }
}

/** The toolbar's back link: the Jobs list tab of the row, else the Jobs list. */
export function backLink(detail) {
    const tab = detail ? listTab(detail.status, detail.silenced) : null;

    return tab ? {label: TAB_LABELS[tab], to: {path: '/jobs', query: {status: tab}}} : {label: 'Jobs', to: {path: '/jobs'}};
}

const STATUS_CHIPS = {
    pending: {label: 'Pending', tone: 'neutral'},
    reserved: {label: 'Running', tone: 'accent'},
    completed: {label: 'Completed', tone: 'success'},
    failed: {label: 'Failed', tone: 'danger'},
    retrying: {label: 'Retrying', tone: 'warning'},
    unknown: {label: 'Unknown', tone: 'neutral'},
};

/** The status chip of the header. */
export function statusChip(status, silenced = false) {
    const chip = STATUS_CHIPS[status];

    if (!chip) return {label: typeof status === 'string' && status !== '' ? status : 'Status unknown', tone: 'neutral'};

    return status === 'completed' && silenced === true ? {label: 'Completed · silenced', tone: chip.tone} : {...chip};
}

/** The last segment of a class name: "App\Jobs\SendInvoice" → "SendInvoice". */
export function baseName(name) {
    if (typeof name !== 'string') return '';

    const parts = name.split('\\');

    return parts[parts.length - 1];
}

function currentCycle(detail) {
    return detail && Array.isArray(detail.cycles) ? detail.cycles.find(cycle => cycle && cycle.current === true) || null : null;
}

/**
 * The header line: the exception's short class of a failed or retrying row
 * ("ConnectException in cycle 2, after 5 of 5 attempts"), else the
 * job's short name and its progress.
 */
export function headline(detail) {
    if (!detail) return {title: '', context: ''};

    const cycle = isNumber(detail.cycle) ? detail.cycle : '—';
    const attempts = isNumber(detail.attempts) ? detail.attempts : null;
    const failing = (detail.status === 'failed' || detail.status === 'retrying') && typeof detail.exception_class === 'string' && detail.exception_class !== '';

    if (failing) {
        const current = currentCycle(detail);
        const tries = current && current.effective ? current.effective.tries : null;
        const of = isNumber(tries) && tries > 0 && attempts !== null ? ` of ${tries}` : '';

        return {title: baseName(detail.exception_class), context: `in cycle ${cycle}, after ${attempts === null ? '—' : attempts}${of} ${attempts === 1 && !of ? 'attempt' : 'attempts'}`};
    }

    let progress;

    if (attempts === null) {
        progress = 'attempts unknown';
    } else if (detail.status === 'reserved') {
        progress = `attempt ${attempts}`;
    } else if (attempts === 0) {
        progress = 'not attempted yet';
    } else {
        progress = `after ${plural(attempts, 'attempt', 'attempts')}`;
    }

    return {title: baseName(detail.name), context: `in cycle ${cycle}, ${progress}`};
}

/** The message box: the redacted first line of a failed or retrying row's exception. */
export function messageBox(detail) {
    if (!detail || (detail.status !== 'failed' && detail.status !== 'retrying')) return null;

    return typeof detail.exception_message === 'string' && detail.exception_message !== '' ? detail.exception_message : null;
}

function retriedText(count, inferred) {
    if (!isNumber(count)) return 'retries unknown';

    const text = count === 0 ? 'never retried' : `retried ${plural(count, 'time', 'times')}`;

    return inferred === true && count > 0 ? `${text} (inferred)` : text;
}

function runtimeText(ms) {
    if (!isNumber(ms) || ms < 0) return '—';

    return ms < 1000 ? `${ms} ms (last attempt)` : `${(ms / 1000).toFixed(1)} s (last attempt)`;
}

function timeWithAge(epoch, now) {
    const seconds = toSeconds(epoch);

    return seconds === null ? '—' : `${formatLocal(seconds)} · ${relativeTime(seconds, now)}`;
}

/**
 * The facts of the header, each `{label, value, …}`: the identity (uuid with
 * copy, queue with its link, cycle with its start and source, attempts and
 * retries) and the times (pushed, available for a pending row, last
 * reserved, failed or finished, runtime). Unknown is "—".
 */
export function headerFacts(detail, now) {
    if (!detail) return [];

    const current = currentCycle(detail);
    const facts = [
        {label: 'UUID', value: detail.id, mono: true, copy: true},
        {label: 'Queue', value: `${detail.connection}:${detail.queue}`, to: queuePath(detail.connection, detail.queue)},
        {
            label: 'Cycle',
            value: `${isNumber(detail.cycle) ? detail.cycle : '—'} · started ${formatLocal(current ? current.started_at : null)}`,
            note: current && current.started_source ? sourceText(current.started_source) : null,
        },
        {label: 'Attempts', value: `${isNumber(detail.attempts) ? detail.attempts : '—'} in this cycle · ${retriedText(detail.retried_count, detail.retried_count_inferred)}`},
        {label: 'Pushed', value: formatLocal(detail.pushed_at)},
    ];

    if (detail.status === 'pending') {
        facts.push({label: 'Available', value: formatLocal(detail.available_at)});
    }

    facts.push({label: 'Last reserved', value: formatLocal(detail.reserved_at)});

    if (detail.status === 'failed' || detail.status === 'retrying') {
        facts.push({label: 'Failed', value: timeWithAge(detail.failed_at, now)});
    } else if (detail.status === 'completed' || detail.status === 'unknown') {
        facts.push({label: 'Finished', value: timeWithAge(detail.finished_at, now)});
    }

    facts.push({label: 'Runtime', value: runtimeText(detail.runtime_ms)});

    return facts;
}

/** The toolbar subtitle: the screen and the zone of its times. */
export function toolbarSubtitle(now) {
    return `Job detail · times in ${timeZoneLabel(now)}`;
}

// ---------------------------------------------------------------------------
// Actions, confirmations and writes
// ---------------------------------------------------------------------------

function validOccurrence(value) {
    return Number.isInteger(value) && value >= 1;
}

/**
 * What the screen offers: Retry and Forget for
 * a failed row that no operation holds, with the occurrence id of the page
 * read, when the server allows them; Resolve for an operation that awaits an
 * operator. Without canOperate nothing is offered: the controls are removed,
 * not disabled. `reason` is the read-only reason in words.
 */
export function actionsFor(detail, canOperate) {
    if (!detail) return {retry: false, forget: false, resolve: false, held: false, reason: null};

    const actions = isObject(detail.actions) ? detail.actions : {};
    const operation = detail.operation;
    const held = operation !== null && operation !== undefined;
    const writable = canOperate === true && detail.status === 'failed' && !held && validOccurrence(detail.occurrence_id);
    const reason = detail.status === 'failed' && !held && actions.retry !== true && actions.forget !== true && typeof actions.read_only_reason === 'string'
        ? codeText(actions.read_only_reason)
        : null;

    return {
        retry: writable && actions.retry === true,
        forget: writable && actions.forget === true,
        resolve: canOperate === true && actions.resolve === true && held && isObject(operation) && typeof operation.id === 'string',
        held,
        reason,
    };
}

function shortId(uuid) {
    return typeof uuid === 'string' ? uuid.slice(0, 8) : '—';
}

const SQLITE_NOTE = 'The queue database is a SQLite file of the application. If a worker holds it, the retry waits for an operator; run php artisan orrery:resolve with the operation id.';

/**
 * The plain Retry sheet. Its
 * `selection` is what the sheet freezes and what the write posts: the uuid
 * and the occurrence id of the page read.
 */
export function retrySheet(detail, sqliteConnections) {
    const cycle = isNumber(detail.cycle) ? detail.cycle : null;
    const sqlite = Array.isArray(sqliteConnections) && sqliteConnections.includes(detail.connection);

    return {
        title: 'Retry this job?',
        message: `${detail.name} · ${shortId(detail.id)} · cycle ${cycle ?? '—'}, failed at ${formatLocal(detail.failed_at)}. The stored payload is pushed again to ${detail.connection}:${detail.queue} as cycle ${cycle === null ? '—' : cycle + 1}.`,
        note: sqlite ? SQLITE_NOTE : null,
        footnote: 'Only this failure is retried. If the job fails again first, nothing happens and you see why.',
        confirmLabel: 'Retry',
        tone: 'primary',
        selection: {action: 'retry', uuid: detail.id, occurrence_id: detail.occurrence_id, cycle},
    };
}

/** The plain Forget sheet: the same frozen selection, a destructive tone. */
export function forgetSheet(detail) {
    return {
        title: 'Forget this failed job?',
        message: `${detail.name} · ${shortId(detail.id)} · failed at ${formatLocal(detail.failed_at)}. Its failed-job record is deleted and Orrery stops keeping it. It is not retried. This cannot be undone.`,
        note: null,
        footnote: null,
        confirmLabel: 'Forget',
        tone: 'danger',
        selection: {action: 'forget', uuid: detail.id, occurrence_id: detail.occurrence_id, cycle: isNumber(detail.cycle) ? detail.cycle : null},
    };
}

/** What the waiting step of an operation can do, by its action: a held forget step deletes a record. */
const RESOLVE_STEPS = {
    retry: 'It finishes the retry that waits: it may delete the failed-job record and push the job again, or undo a retry that did not publish.',
    forget: 'It finishes the forget that waits: it may delete the failed-job record of this failure. A deleted record cannot be restored.',
};

/**
 * The Resolve sheet of an operation that awaits an operator, or null. It
 * names the job (`detail`: its name and id) and what the waiting step of the
 * operation's action does; a forget step is destructive (tone `danger`).
 */
export function resolveSheet(operation, detail) {
    if (!isObject(operation) || typeof operation.id !== 'string') return null;

    const uuid = isObject(detail) && typeof detail.id === 'string' ? detail.id : null;
    const job = isObject(detail) && typeof detail.name === 'string' && detail.name !== '' ? `${detail.name} · ${shortId(uuid)}` : (uuid ? shortId(uuid) : 'This job');
    const step = RESOLVE_STEPS[operation.action] || null;

    return {
        title: operation.action === 'forget' ? 'Resolve this forget?' : (operation.action === 'retry' ? 'Resolve this retry?' : 'Resolve this operation?'),
        message: `${job}: Orrery runs the step that waits for an operator now, as your action.${step ? ` ${step}` : ''}`,
        note: operation.awaiting_reason ? awaitingText(operation.awaiting_reason, {id: operation.id}) : null,
        footnote: null,
        confirmLabel: 'Resolve',
        tone: operation.action === 'forget' ? 'danger' : 'primary',
        selection: {action: 'resolve', operation_id: operation.id, uuid},
    };
}

/**
 * The request of a frozen selection: `{url, body}`, or null when the
 * selection cannot be posted. A retry or forget sends exactly the frozen
 * occurrence id (the server never substitutes the current one).
 */
export function writeRequest(selection) {
    if (!isObject(selection)) return null;

    if (selection.action === 'retry' || selection.action === 'forget') {
        if (!isUuid(selection.uuid) || !validOccurrence(selection.occurrence_id)) return null;

        return {url: `/api/jobs/${selection.uuid.toLowerCase()}/${selection.action}`, body: {occurrence_id: selection.occurrence_id}};
    }

    if (selection.action === 'resolve') {
        return typeof selection.operation_id === 'string' && OPERATION_ID.test(selection.operation_id)
            ? {url: `/api/operations/${selection.operation_id}/resolve`, body: {}}
            : null;
    }

    return null;
}

const UNKNOWN_OUTCOME = () => ({tone: 'warning', title: 'Outcome unknown', detail: codeText('write_failed')});

const FAILED_TITLES = {retry: 'Retry not started', forget: 'Not forgotten', resolve: 'Not resolved'};

/**
 * The answer of a per-job write: read from the
 * item's `result`, never from the HTTP status. `{tone, title, detail}`.
 */
export function writeOutcome(action, item) {
    if (!isObject(item) || typeof item.result !== 'string') return UNKNOWN_OUTCOME();

    const detail = itemText(item);
    const result = item.result;

    if (action === 'retry') {
        if (result === 'accepted') {
            return item.awaiting_reason
                ? {tone: 'warning', title: 'Retry waits for an operator', detail}
                : {tone: 'success', title: 'Retry started', detail};
        }

        if (result === 'uncertain') return {tone: 'warning', title: 'Retry not decided yet', detail};

        return {tone: 'danger', title: FAILED_TITLES.retry, detail};
    }

    if (result === 'deleted') return {tone: 'success', title: 'Forgotten', detail};
    // The record is deleted, but Orrery's own row could not be removed yet (its detail names why): not all green.
    if (result === 'mirror_pending') return {tone: 'warning', title: "Forgotten; Orrery's row is removed later", detail};
    if (result === 'already_done') return {tone: 'success', title: 'Already forgotten', detail};
    if (result === 'uncertain') return {tone: 'warning', title: 'Forget not decided yet', detail};

    return {tone: 'danger', title: FAILED_TITLES.forget, detail};
}

/**
 * Whether a forget answer removed the job's row, so the not-found panel can
 * say "Forgotten": `deleted` without `row_moved` (with it, the row moved on
 * and stays), or `already_done` (a replay). `mirror_pending` leaves the row
 * to the runner.
 */
export function forgetRemovedRow(item) {
    if (!isObject(item)) return false;

    return (item.result === 'deleted' && item.code !== 'row_moved') || item.result === 'already_done';
}

function statusOf(error) {
    return error && error.response && typeof error.response.status === 'number' ? error.response.status : null;
}

function bodyOf(error) {
    return error && error.response && isObject(error.response.data) ? error.response.data : {};
}

/**
 * A write that did not answer with a result item. A 404 or 422 is a refused
 * request (nothing changed); a 403 is left to the global alert (null); no
 * answer, a timeout or a 5xx is an unknown outcome. Resolve adds its own
 * 409 (target changed) and 503 (the step did not finish) answers.
 */
export function writeFailure(action, error) {
    const status = statusOf(error);
    const body = bodyOf(error);
    const title = FAILED_TITLES[action] || 'Not done';

    if (status === 403) return null;

    if (action === 'resolve') {
        if (status === 409) return {tone: 'danger', title, detail: codeText('target_changed')};

        if (status === 503 && isObject(body.operation)) {
            const operation = body.operation;

            return {
                tone: 'warning',
                title: 'The step did not finish',
                detail: codeText(operation.error_code, {id: operation.id}) || stateText(operation.state),
            };
        }
    }

    if (status === 404) return {tone: 'danger', title, detail: codeText('not_found')};
    if (status === 422) return {tone: 'danger', title, detail: codeText(typeof body.error === 'string' ? body.error : 'invalid')};

    return UNKNOWN_OUTCOME();
}

/** The answer of a resolve (`{operation}` of 200): the state after the step. */
export function resolveOutcome(operation) {
    if (!isObject(operation)) return UNKNOWN_OUTCOME();

    const detail = codeText(operation.error_code, {id: operation.id}) || stateText(operation.state);

    // The operation had finished before this resolve (the runner, or another operator): nothing ran now.
    if (operation.step === 'terminal') {
        return {tone: operation.state === 'done' ? 'info' : 'warning', title: 'Already finished', detail};
    }

    if (operation.awaiting_reason || operation.step === 'awaiting') {
        return {tone: 'warning', title: 'Still waiting for an operator', detail: awaitingText(operation.awaiting_reason, {id: operation.id})};
    }

    if (operation.step === 'decided') {
        // A step can decide that the operation stops (for example its deadline passed): that is not a success.
        return operation.state === 'aborted' || operation.state === 'aborting'
            ? {tone: 'warning', title: 'The operation stopped', detail}
            : {tone: 'success', title: 'Resolved', detail};
    }
    if (operation.step === 'later') return {tone: 'info', title: 'Not due yet', detail};

    return {tone: 'warning', title: 'The step did not finish', detail};
}

/**
 * A failed detail read: `{kind, title, detail}`. `not_found` (404: the job
 * is no longer kept, polling stops), `redaction` (the whole panel says that
 * job data is hidden), else `failed` (a first load offers Try again; a later
 * poll keeps the last data as stale).
 */
export function readFailure(error) {
    const status = statusOf(error);
    const body = bodyOf(error);

    if (status === 404) {
        return {kind: 'not_found', title: 'This job is no longer kept', detail: 'Retention or a forget removed it. The page stopped refreshing.'};
    }

    if (body.error === 'redaction_config_invalid') {
        const field = typeof body.field === 'string' ? body.field : 'orrery.redact';

        return {
            kind: 'redaction',
            title: 'Job data is hidden: the redaction settings are invalid',
            detail: `${field} is not a valid rule. Fix the configuration; no job data is shown until then.`,
        };
    }

    const code = typeof body.error === 'string' && body.error !== '' ? body.error : 'read_failed';

    return {kind: 'failed', title: 'The job could not be loaded', detail: codeText(code)};
}

/**
 * The not-found state: a job that is no longer kept (404), or a legacy link
 * whose id is not a uuid (the new detail route matches only a
 * uuid). The id is shown as text. After the screen's own successful forget
 * (`forgotten`) it says so.
 */
export function notFoundState(legacyId = null, forgotten = false) {
    if (forgotten === true) {
        return {title: 'Forgotten: this job is no longer kept', detail: 'Its failed-job record and its row in Orrery were deleted. The page stopped refreshing.'};
    }

    if (typeof legacyId === 'string') {
        return {title: 'No job with this id', detail: `The link names "${legacyId}", which is not a job uuid. Only a job's uuid opens its page.`};
    }

    const {title, detail} = readFailure({response: {status: 404}});

    return {title, detail};
}

/**
 * What a failed read does to the screen. `kind` is readFailure()'s kind,
 * `source` the read (`detail`, `history`, `entry`, `cycle`), `hasDetail`
 * whether a good detail is on screen.
 * - A 404 of the detail, the history or a cycle's history means the job is
 *   no longer kept: `gone`, the detail is dropped, an open sheet closes,
 *   polling stops, and the reads in flight (`invalidate`) change nothing.
 * - A 404 of one history entry is that entry's own error: History prunes
 *   the entries of a job that lives (5 + 45 per cycle, 200 per job).
 * - A redaction error of any read hides all job data: `redaction`.
 * - A failed detail poll keeps the last good detail as `stale`; a failed
 *   first read is a `failure` with Try again.
 * - A failed read of another section is that section's own error.
 */
export function readFailureState(kind, source, hasDetail = false) {
    if (kind === 'not_found' && source !== 'entry') {
        return {next: 'gone', keepDetail: false, closeSheet: true, invalidate: ['detail', 'history', 'entry']};
    }

    if (kind === 'redaction') return {next: 'redaction', keepDetail: false, closeSheet: true};

    if (source === 'detail') {
        return hasDetail ? {next: 'stale', keepDetail: true, closeSheet: false} : {next: 'failure', keepDetail: false, closeSheet: false};
    }

    return {next: 'section_error', keepDetail: true, closeSheet: false};
}

// ---------------------------------------------------------------------------
// Inspector: operation, retries, links
// ---------------------------------------------------------------------------

const ACTION_LABELS = {retry: 'Retry', forget: 'Forget'};

const STATE_LABELS = {claimed: 'claimed', published: 'published', aborting: 'stopping', done: 'done', aborted: 'stopped'};

/**
 * The operation panel (`operation`): none; held by an operation
 * whose ledger row is gone (state unknown); or the operation with its state,
 * its awaiting reason, the actor (text), the source of a retry, its error
 * code and its next check, all worded through wording.js.
 */
export function operationPanel(operation) {
    if (!isObject(operation)) return {kind: 'none', text: 'No operation holds this job.'};

    if (typeof operation.action !== 'string' || operation.action === '') {
        return {kind: 'unknown', label: 'Held · state unknown', tone: 'neutral', id: operation.id || null, text: 'Held by an operation whose record is gone; state unknown.', facts: [], bulkRef: null};
    }

    const awaiting = typeof operation.awaiting_reason === 'string' && operation.awaiting_reason !== '';
    const action = ACTION_LABELS[operation.action] || operation.action;
    const state = STATE_LABELS[operation.state] || String(operation.state || 'unknown');
    const facts = [{label: 'Started', value: `${formatLocal(operation.created_at)} by ${operation.actor ? operation.actor : 'an unknown actor'}`}];

    if (operation.action === 'retry' && operation.source) {
        facts.push({label: 'Source', value: sourceText(operation.source)});
    }

    if (operation.error_code) {
        facts.push({label: 'Error', value: codeText(operation.error_code, {id: operation.id})});
    }

    const next = toSeconds(operation.next_check_at);

    facts.push({label: 'Next check', value: next !== null ? formatLocal(next) : (awaiting ? 'none (waits for an operator)' : '—')});

    return {
        kind: awaiting ? 'awaiting' : 'active',
        label: `${action} · ${awaiting ? 'awaiting an operator' : state}`,
        tone: awaiting ? 'warning' : 'accent',
        id: operation.id,
        text: awaiting ? awaitingText(operation.awaiting_reason, {id: operation.id}) : stateText(operation.state),
        facts,
        bulkRef: typeof operation.bulk_ref === 'string' && operation.bulk_ref !== '' ? operation.bulk_ref : null,
    };
}

const CYCLE_OUTCOMES = {
    in_progress: {label: 'in progress', tone: 'accent'},
    completed: {label: 'completed', tone: 'success'},
    failed: {label: 'failed', tone: 'danger'},
    unknown: {label: 'unknown', tone: 'neutral'},
};

function cycleOutcome(outcome) {
    return CYCLE_OUTCOMES[outcome] ? {...CYCLE_OUTCOMES[outcome]} : {label: 'unknown', tone: 'neutral'};
}

/**
 * The Retries block (`retries`): one row per retained retry,
 * the folded range of the merged row, and the notices. No row is invented
 * for an unrecorded retry. Retries that could not be read are unknown.
 */
export function retriesBlock(retries) {
    if (!isObject(retries) || !Array.isArray(retries.entries)) {
        return {unknown: true, text: 'Retries unknown: they could not be read.', retained: null, rows: [], notes: []};
    }

    const rows = retries.entries.map(entry => {
        if (entry && entry.kind === 'earlier') {
            return {
                key: 'earlier',
                label: `Cycles ${entry.first_cycle ?? '—'}–${entry.last_cycle ?? '—'}`,
                when: null,
                source: `${plural(entry.count, 'retry', 'retries')} folded together`,
                outcome: null,
            };
        }

        return {
            key: `retry-${entry.cycle}`,
            label: `Cycle ${entry.cycle}`,
            when: formatLocal(entry.started_at),
            source: sourceText(entry.source),
            outcome: cycleOutcome(entry.outcome),
        };
    });

    const notes = [];
    const unrecorded = isNumber(retries.retries_unrecorded) ? retries.retries_unrecorded : 0;

    if (unrecorded > 0) {
        notes.push(unrecorded === 1 ? '1 earlier retry was not recorded.' : `${formatCount(unrecorded)} earlier retries were not recorded.`);
    }

    if (retries.retried_count_inferred === true) notes.push('Retry count inferred from the retry cycle.');

    if (isNumber(retries.retried_count)) {
        notes.push(retries.retried_count === 0 ? 'Never retried.' : `Retried ${plural(retries.retried_count, 'time', 'times')} in all.`);
    }

    return {unknown: false, text: null, retained: `${formatCount(retries.retries_retained)} retained`, rows, notes};
}

/**
 * The links of the inspector: the tags (tagLocation(): `/tags/:tag`, or
 * `/tags?tag=` for '.', '..' and '/'), the batch, the queue
 * and the other jobs of the class in the Jobs list. Tags that could not be
 * read are null (unknown), not an empty list.
 */
export function inspectorLinks(detail) {
    const tags = Array.isArray(detail.tags)
        ? detail.tags.map(tag => ({label: tag, to: tagLocation(tag)}))
        : null;
    const batchId = typeof detail.batch_id === 'string' && detail.batch_id !== '' ? detail.batch_id : null;
    const batchName = isObject(detail.batch) && typeof detail.batch.name === 'string' && detail.batch.name !== '' ? detail.batch.name : null;
    const tab = listTab(detail.status, detail.silenced);

    return {
        tags,
        batch: batchId ? {label: batchName || batchId, to: `/batches/${encodeURIComponent(batchId)}`} : null,
        queue: {label: `${detail.connection}:${detail.queue}`, to: queuePath(detail.connection, detail.queue)},
        klass: {label: `Other ${baseName(detail.name)} jobs`, to: {path: '/jobs', query: {...(tab ? {status: tab} : {}), class: detail.name}}},
    };
}

// ---------------------------------------------------------------------------
// Cycles
// ---------------------------------------------------------------------------

function mergedLabel(cycle) {
    return `Cycles ${cycle.first_cycle ?? '—'}–${cycle.cycle ?? '—'}`;
}

/** The cycle tabs, newest first, the merged entry last; [] when the cycles could not be read. */
export function cycleTabs(cycles) {
    if (!Array.isArray(cycles)) return [];

    return cycles.map(cycle => (cycle.merged === true
        ? {key: 'merged', cycle: cycle.cycle, label: mergedLabel(cycle), tone: 'neutral', merged: true}
        : {key: `c${cycle.cycle}`, cycle: cycle.cycle, label: cycle.current ? `Cycle ${cycle.cycle} · current` : `Cycle ${cycle.cycle}`, tone: cycleOutcome(cycle.outcome).tone, merged: false}));
}

function shortSource(source, option) {
    if (source === 'payload') return 'payload';
    if (source === 'worker') return `worker --${option}`;

    return null;
}

function effectiveValue(text, value, source, option) {
    if (!isNumber(value)) return 'unknown';

    const from = shortSource(source, option);

    return from ? `${text} · ${from}` : text;
}

const COUNTERS = [
    ['attempts', 'Attempts'], ['releases', 'Releases'], ['exceptions', 'Exceptions'], ['timeouts', 'Timeouts'],
    ['reclaims', 'Reclaims'], ['observed_gap', 'Not observed'], ['late_outcomes', 'Late outcomes'],
];

/**
 * The diagnostics of one cycle: the terminal
 * explanation, the enforced rule (now for a cycle in progress, else at its
 * failure or finish), the effective tries and timeout with their sources,
 * and the counters. A merged entry says only "Details not retained".
 */
export function cycleView(cycle) {
    if (!isObject(cycle)) return null;

    if (cycle.merged === true) {
        return {
            merged: true,
            title: 'Details not retained',
            detail: `${mergedLabel(cycle)}: ${plural(cycle.merged_cycles, 'cycle', 'cycles')} merged, ${plural(cycle.merged_retries, 'retry', 'retries')} folded together.`,
        };
    }

    const running = cycle.outcome === 'in_progress';
    const effective = isObject(cycle.effective) ? cycle.effective : {};
    const limits = isObject(cycle.limits) ? cycle.limits : {};
    const retryUntil = isNumber(limits.retry_until) && limits.retry_until !== 0 ? formatLocal(limits.retry_until) : 'not set';

    return {
        merged: false,
        terminal: terminalText(cycle.terminal),
        ruleHeading: running ? 'Enforced rule now' : (cycle.outcome === 'failed' ? 'Enforced rule at the failure' : 'Enforced rule at the finish'),
        rule: enforcedRuleText(cycle.enforced_rule, running ? 'now' : 'failure'),
        effective: [
            {label: 'Tries', value: effectiveValue(triesValue(effective.tries), effective.tries, effective.tries_source, 'tries')},
            {label: 'Timeout', value: effectiveValue(timeoutValue(effective.timeout), effective.timeout, effective.timeout_source, 'timeout')},
            {label: 'Retry until', value: retryUntil},
        ],
        counters: COUNTERS.map(([key, label]) => ({key, label, value: isNumber(cycle[key]) ? formatCount(cycle[key]) : '—'})),
        countersNote: 'The counters count observed events only. Releases are counted, not recorded one by one.',
    };
}

/** The phone's one fact line of a cycle. */
export function countersLine(cycle) {
    if (!isObject(cycle) || cycle.merged === true) return null;

    const effective = isObject(cycle.effective) ? cycle.effective : {};
    const tries = isNumber(effective.tries) ? `${triesValue(effective.tries)} tries ${triesSourceText(effective.tries_source)}` : 'tries unknown';
    const timeout = isNumber(effective.timeout) ? `timeout ${timeoutValue(effective.timeout)} ${triesSourceText(effective.timeout_source, 'timeout')}` : 'timeout unknown';
    const count = key => (isNumber(cycle[key]) ? formatCount(cycle[key]) : '—');

    return `${tries} · ${timeout} · releases ${count('releases')} · exceptions ${count('exceptions')} · timeouts ${count('timeouts')} · not observed ${count('observed_gap')}`;
}

// ---------------------------------------------------------------------------
// History
// ---------------------------------------------------------------------------

const EVENTS = {
    failed: {label: 'failed', tone: 'danger'},
    exception: {label: 'exception', tone: 'warning'},
    timeout: {label: 'timed out', tone: 'warning'},
    completed: {label: 'completed', tone: 'success'},
    retried: {label: 'retried', tone: 'accent'},
    retry_aborted: {label: 'retry stopped', tone: 'neutral'},
    late_outcome: {label: 'late outcome', tone: 'neutral'},
};

/** The label and tone of a history event. */
export function historyEventLabel(event) {
    if (EVENTS[event]) return {...EVENTS[event]};

    return {label: String(event || 'event').replace(/_/g, ' '), tone: 'neutral'};
}

/** The text of a history entry: its exception, its source, its reason. */
export function historyEntryText(entry) {
    if (!isObject(entry)) return '—';

    if (typeof entry.exception_class === 'string' && entry.exception_class !== '') {
        const message = typeof entry.exception_message === 'string' && entry.exception_message !== '' ? `: ${entry.exception_message}` : '';

        return `${baseName(entry.exception_class)}${message}`;
    }

    switch (entry.event) {
        case 'retried': return sourceText(entry.source);
        case 'retry_aborted': return entry.reason ? codeText(entry.reason) : 'The retry was stopped.';
        case 'late_outcome': return 'The outcome of an older attempt arrived after a newer one.';
        case 'completed': return 'Completed.';
        default: return entry.reason ? codeText(entry.reason) : '—';
    }
}

/** The notices of a history page: entries that were not kept. */
export function historyNotices(page) {
    if (!isObject(page)) return [];

    const notices = [];

    if (page.history_truncated === true) {
        notices.push('Some entries were not kept: Orrery keeps the first 5 and the newest 45 of each cycle, and at most 200 of a job.');
    }

    if (isNumber(page.history_discarded) && page.history_discarded > 0) {
        notices.push(page.history_discarded === 1 ? '1 entry was discarded.' : `${formatCount(page.history_discarded)} entries were discarded.`);
    }

    return notices;
}

/** A history answer when it has the shape of a page (`entries` a list, `next_cursor` a string or null), else null. */
export function historyPageOf(data) {
    if (!isObject(data) || !Array.isArray(data.entries)) return null;
    if (data.next_cursor !== undefined && data.next_cursor !== null && typeof data.next_cursor !== 'string') return null;

    return data;
}

/**
 * The first history page after a detail read: the detail's `history`
 * section when it is a page; else the section could not be read, so the
 * last good page stays and is marked stale (never an empty history).
 */
export function historyFromDetail(lastPage, section) {
    const page = historyPageOf(section);

    return page ? {page, error: false} : {page: lastPage || null, error: true};
}

function newestHistoryId(detail) {
    const page = detail ? historyPageOf(detail.history) : null;

    return page && page.entries.length && isObject(page.entries[0]) ? page.entries[0].id : (page ? null : undefined);
}

/**
 * The cycle whose cached "earlier exceptions" a new detail read makes
 * stale, or null: the current cycle while it is in progress (it can still
 * gain exceptions), or when the newest entry of the first history page
 * changed (or the history could not be read, so nothing proves it did not).
 */
export function cycleHistoryToDrop(previous, next) {
    if (!isObject(previous) || !isObject(next) || !Number.isInteger(next.cycle)) return null;

    const current = Array.isArray(next.cycles) ? next.cycles.find(cycle => isObject(cycle) && cycle.current === true) : null;

    if (current && current.outcome === 'in_progress') return next.cycle;

    const before = newestHistoryId(previous);
    const after = newestHistoryId(next);

    return after === undefined || before !== after ? next.cycle : null;
}

/** The cycle filter of the history: all cycles, or one retained cycle (a merged row has no history of its own). */
export function historyCycleOptions(cycles) {
    const options = [{value: null, label: 'All cycles'}];

    if (Array.isArray(cycles)) {
        for (const cycle of cycles) {
            if (cycle && cycle.merged !== true && Number.isInteger(cycle.cycle)) options.push({value: cycle.cycle, label: `Cycle ${cycle.cycle}`});
        }
    }

    return options;
}

/** "Earlier exceptions in this cycle": the exceptions and timeouts of one cycle, newest first. */
export function earlierExceptions(entries, cycle) {
    if (!Array.isArray(entries)) return [];

    return entries.filter(entry => isObject(entry) && entry.cycle === cycle && (entry.event === 'exception' || entry.event === 'timeout'));
}

/**
 * The state of one cycle's history read (`{entries, loading, error}`) after
 * an event: 'loading' (a read starts), `{page}` (an answer) or `{failed:
 * true}`. A reload keeps the last entries; a failed or malformed answer
 * keeps them and marks them stale.
 */
export function cycleHistoryAfter(previous, answer) {
    const entries = isObject(previous) && Array.isArray(previous.entries) ? previous.entries : null;

    if (answer === 'loading') return {entries, loading: true, error: isObject(previous) ? previous.error === true : false};

    const page = isObject(answer) && 'page' in answer ? historyPageOf(answer.page) : null;

    return page ? {entries: page.entries, loading: false, error: false} : {entries, loading: false, error: true};
}

/**
 * "Earlier exceptions in this cycle" from a cycle's history state:
 * `{status, entries, stale}` with status `loading` (nothing cached yet),
 * `error` (the first read failed), `none` or `list`. A cached list stays on
 * screen while it reloads, and says when its last reload failed.
 */
export function earlierView(state, cycle) {
    if (!isObject(state) || !Array.isArray(state.entries)) {
        return {status: isObject(state) && state.error === true && state.loading !== true ? 'error' : 'loading', entries: [], stale: false};
    }

    const entries = earlierExceptions(state.entries, cycle);

    return {status: entries.length ? 'list' : 'none', entries, stale: state.error === true};
}

// ---------------------------------------------------------------------------
// Exception
// ---------------------------------------------------------------------------

/** The UTF-8 byte length of a text, as the server stores and limits it. */
export function utf8Bytes(text) {
    return typeof text === 'string' ? new TextEncoder().encode(text).length : 0;
}

/** A byte count: "512 B", "18.2 KB", "1.4 MB"; unknown is "—". */
export function formatBytes(bytes) {
    if (!isNumber(bytes) || bytes < 0) return '—';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1048576) return `${(bytes / 1024).toFixed(1)} KB`;

    return `${(bytes / 1048576).toFixed(1)} MB`;
}

const FOLDED_LINES = 6;
const FRAME = /^#\d+ /;

/**
 * The exception text (redacted as a whole by the server): its lines, the
 * application frames (not vendor/) emphasised, the first 6 lines shown and
 * the rest folded. An omitted exception is a marker, never text.
 */
export function exceptionView(exception, expanded) {
    if (exception === null || exception === undefined) return {kind: 'none', text: 'No exception recorded.'};

    if (exception === '[redaction failed]') return {kind: 'redaction_failed', text: exception};

    if (typeof exception !== 'string') return {kind: 'marker', text: markerText(exception) || 'Not shown'};

    const all = exception.split('\n').map(text => {
        const frame = FRAME.test(text);

        return {text, frame, app: frame && !text.includes('vendor/') && !/^#\d+ \{main\}/.test(text)};
    });
    const frames = all.filter(line => line.frame).length;
    const foldable = all.length > FOLDED_LINES;

    return {
        kind: 'text',
        size: formatBytes(utf8Bytes(exception)),
        lines: (expanded || !foldable ? all : all.slice(0, FOLDED_LINES)).map(({text, app}) => ({text, app})),
        hidden: expanded || !foldable ? 0 : all.length - FOLDED_LINES,
        toggle: !foldable ? null : (expanded ? 'Show fewer lines' : `Show all ${plural(frames, 'frame', 'frames')}`),
    };
}

// ---------------------------------------------------------------------------
// The payload tree (the data-only projection)
// ---------------------------------------------------------------------------

function keysAre(value, keys) {
    const own = Object.keys(value).sort();

    return own.length === keys.length && keys.slice().sort().every((key, index) => own[index] === key);
}

function keysWithin(value, required, optional) {
    const own = Object.keys(value);

    return required.every(key => own.includes(key)) && own.every(key => required.includes(key) || optional.includes(key));
}

const OMITTED = {
    too_large: 'Too large to show',
    string_too_large: 'Omitted · string too large',
    nested_serialized: 'Omitted · a nested serialized value that could not be read',
    hidden_context: 'Hidden context · not shown',
    response_budget: 'Omitted · over the 2 MB response budget',
};

const UNAVAILABLE = {
    unsupported: 'Not shown · a value Orrery does not read',
    unknown_format: 'Not shown · an unknown format',
    invalid_json: 'Not shown · not valid JSON',
    error: 'Not shown · it could not be read',
};

/**
 * The words of a marker that stands for a value that is not shown: omitted
 * (with its reason and size), unavailable (with its reason or limit), a
 * closure, an encrypted command, a reference, binary data, or a custom
 * serialization. Null when the value is not a marker. A marker has exactly
 * its keys, so a natural object that only looks partly like one stays data.
 */
export function markerText(value) {
    if (!isObject(value)) return null;

    if (keysWithin(value, ['omitted'], ['bytes']) && typeof value.omitted === 'string') {
        const text = OMITTED[value.omitted] || `Omitted · ${value.omitted}`;

        return isNumber(value.bytes) ? `${text} · ${formatBytes(value.bytes)}` : text;
    }

    if (keysWithin(value, ['unavailable'], ['limit']) && typeof value.unavailable === 'string') {
        if (value.unavailable === 'limit') return `Not shown · the ${typeof value.limit === 'string' ? value.limit : 'size'} limit was reached`;

        return UNAVAILABLE[value.unavailable] || `Not shown · ${value.unavailable}`;
    }

    if (keysAre(value, ['closure']) && value.closure === true) return 'Closure · not shown';
    if (keysAre(value, ['encrypted']) && value.encrypted === true) return 'Encrypted · not shown';
    if (keysAre(value, ['ref']) && isNumber(value.ref)) return `Reference to value #${value.ref} · not expanded`;
    if (keysAre(value, ['binary']) && isNumber(value.binary)) return `Binary data · ${formatBytes(value.binary)}`;

    if (keysAre(value, ['bytes', 'class', 'custom']) && value.custom === true && typeof value.class === 'string') {
        return `${visibleText(value.class)} · custom serialization · ${formatBytes(value.bytes)}, not parsed`;
    }

    return null;
}

function isEntryList(list, keyName) {
    return Array.isArray(list) && list.every(entry => isObject(entry) && keyName in entry && 'value' in entry);
}

/**
 * One value of the projection as a tree node:
 * - `scalar` (null, a bool, a number; also `{"integer": "<digits>"}`, an
 *   integer beyond ±(2^53 − 1) that the server sends as text so it stays
 *   exact), `string`, `redacted`
 *   ("[redacted]"), `redaction_failed` ("[redaction failed]"), `enum`,
 *   `marker` (markerText());
 * - `container` with `shape`: `array` (the parser's {"array": [{key,
 *   value}]}), `object` ({"class", "properties": [{name, visibility,
 *   declared_in?, value}]}), `list` (a JSON array) or `map` (a JSON object);
 *   each child `{key, visibility?, declaredIn?, value}`; a natural object
 *   with only the keys of these shapes comes wrapped as `{"map": {...}}` and
 *   is drawn as the map it wraps;
 * - `serialized: true` on the node of a string that held a serialized value.
 */
export function treeNode(value) {
    if (value === null || value === undefined) return {kind: 'scalar', text: 'null', type: 'null'};
    if (typeof value === 'boolean') return {kind: 'scalar', text: String(value), type: 'boolean'};
    if (typeof value === 'number') return {kind: 'scalar', text: String(value), type: 'number'};

    if (typeof value === 'string') {
        if (value === '[redacted]') return {kind: 'redacted', text: value};
        if (value === '[redaction failed]') return {kind: 'redaction_failed', text: value};

        return {kind: 'string', text: visibleText(value)};
    }

    if (Array.isArray(value)) {
        return {kind: 'container', shape: 'list', className: null, serialized: false, children: value.map((item, index) => ({key: String(index), visibility: null, declaredIn: null, value: item}))};
    }

    if (!isObject(value)) return {kind: 'string', text: String(value)};

    // A natural JSON object with only the keys of a projection shape, wrapped by the server (JobProjection::natural()).
    if (keysAre(value, ['map']) && isObject(value.map)) {
        return {
            kind: 'container', shape: 'map', className: null, serialized: false,
            children: Object.keys(value.map).map(key => ({key: visibleText(key), visibility: null, declaredIn: null, value: value.map[key]})),
        };
    }

    const marker = markerText(value);

    if (marker !== null) return {kind: 'marker', text: marker};

    if (isExactInteger(value)) return {kind: 'scalar', text: value.integer, type: 'number'};

    if (keysAre(value, ['enum']) && typeof value.enum === 'string') return {kind: 'enum', text: visibleText(value.enum)};

    if (keysAre(value, ['serialized'])) return {...treeNode(value.serialized), serialized: true};

    if (keysAre(value, ['array']) && isEntryList(value.array, 'key')) {
        return {
            kind: 'container', shape: 'array', className: null, serialized: false,
            children: value.array.map(entry => ({key: keyText(entry.key), visibility: null, declaredIn: null, value: entry.value})),
        };
    }

    if (keysAre(value, ['class', 'properties']) && typeof value.class === 'string' && isEntryList(value.properties, 'name')) {
        return {
            kind: 'container', shape: 'object', className: visibleText(value.class), serialized: false,
            children: value.properties.map(property => ({
                key: keyText(property.name),
                visibility: typeof property.visibility === 'string' ? property.visibility : null,
                declaredIn: typeof property.declared_in === 'string' ? visibleText(property.declared_in) : null,
                value: property.value,
            })),
        };
    }

    return {
        kind: 'container', shape: 'map', className: null, serialized: false,
        children: Object.keys(value).map(key => ({key: visibleText(key), visibility: null, declaredIn: null, value: value[key]})),
    };
}

const FORMAT_CONTROLS = /[\u061C\u200E\u200F\u202A-\u202E\u2066-\u2069]/g;

/**
 * A text of job data with its bidi and format control characters (U+061C,
 * U+200E, U+200F, U+202A–U+202E, U+2066–U+2069) shown escaped as `\u202E`: drawn
 * as they are, they can reorder or hide the text around them.
 */
export function visibleText(text) {
    if (typeof text !== 'string') return text;

    return text.replace(FORMAT_CONTROLS, character => `\\u${character.charCodeAt(0).toString(16).toUpperCase().padStart(4, '0')}`);
}

/** An array key or a property name as text; a key marker in words. */
export function keyText(key) {
    if (typeof key === 'string') return visibleText(key);
    if (isNumber(key)) return String(key);
    if (isExactInteger(key)) return key.integer;
    if (isObject(key) && keysAre(key, ['binary']) && isNumber(key.binary)) return `(binary key · ${formatBytes(key.binary)})`;
    if (isObject(key) && 'omitted' in key) return '(key not shown)';

    return '(key)';
}

/** The one-line summary of a container; a ModelIdentifier names its model and id. */
export function treeSummary(node) {
    if (!node || node.kind !== 'container') return null;

    const count = node.children.length;

    if (node.shape === 'object') {
        if (/(^|\\)ModelIdentifier$/.test(node.className)) {
            const field = name => {
                const child = node.children.find(entry => entry.key === name);

                return child ? child.value : undefined;
            };
            const model = field('class');
            const rawId = field('id');
            const id = isExactInteger(rawId) ? rawId.integer : rawId;

            if (typeof model === 'string' && (typeof id === 'string' || isNumber(id))) return `${visibleText(model)} #${visibleText(String(id))}`;
        }

        return `object · ${plural(count, 'property', 'properties')}`;
    }

    if (node.shape === 'array') return `array · ${plural(count, 'item', 'items')}`;
    if (node.shape === 'list') return `list · ${plural(count, 'item', 'items')}`;

    return plural(count, 'key', 'keys');
}

/** How many children of a long container are drawn now, and how many wait behind "Show more". */
export function visibleChildren(total, shown) {
    const all = Math.max(0, isNumber(total) ? total : 0);
    const count = Math.min(all, Math.max(0, shown));

    return {count, more: all - count};
}

// ---------------------------------------------------------------------------
// Layout
// ---------------------------------------------------------------------------

/**
 * The layout of a viewport width: `wide` (1024 and
 * more: the detail pane and the 340 px inspector), `narrow` (768–1023: one
 * column, the inspector folded in), `phone` (below 768: the actions in a
 * bottom bar, the counters on one line).
 */
export function layoutFor(width) {
    if (width >= 1024) return 'wide';
    if (width >= 768) return 'narrow';

    return 'phone';
}
