/**
 * The client rules of a bulk run:
 * the freeze request, the run request with the token echoed
 * verbatim, the forget confirmation, and the totals of the pages that this
 * client saw. Pure helpers, so Node can test them; the bulk run sheet
 * uses them.
 *
 * The server keeps the record of a run (`GET /api/bulk/{ref}`); the client
 * totals are what this client saw, and a missing total stays unknown (null),
 * never 0.
 */

import {formatCount, formatLocal} from './time.js';
import {codeText, outcomeLabel} from './wording.js';

/** The outcomes of a bulk item, in the order of the totals. */
export const BULK_OUTCOMES = Object.freeze(['accepted', 'refused', 'uncertain', 'not_member', 'already_done', 'not_confirmed']);

/** The text of the sheet before a run starts. */
export const FREEZE_NOTE = 'Failures that happen after this moment are not included.';

const ACTIONS = ['retry', 'forget'];

/** The most group keys of one groups source (4.9). */
const MAX_KEYS = 200;

function orNull(value) {
    return value === undefined || value === '' ? null : value;
}

/**
 * The body of `POST /api/bulk/freeze`. It throws on an unknown action or a
 * malformed source: the sheet never sends one.
 */
export function freezeBody(action, source) {
    if (!ACTIONS.includes(action)) throw new Error(`Unknown bulk action: ${action}`);
    if (!source || typeof source !== 'object') throw new Error('A bulk run needs a source.');

    if (source.kind === 'groups') {
        if (!Array.isArray(source.keys) || source.keys.length < 1 || source.keys.length > MAX_KEYS) {
            throw new Error(`A groups source needs 1 to ${MAX_KEYS} keys.`);
        }

        return {action, source: {kind: 'groups', queue: orNull(source.queue) ?? null, from: source.from, to: source.to, keys: [...source.keys]}};
    }

    if (source.kind === 'list') {
        return {action, source: listSource(source)};
    }

    if (source.kind === 'batch') {
        if (typeof source.batch_id !== 'string' || source.batch_id === '') throw new Error('A batch source needs a batch id.');

        return {action, source: {kind: 'batch', batch_id: source.batch_id}};
    }

    throw new Error(`Unknown bulk source: ${source.kind}`);
}

/**
 * A list source from the filters of the failed tab: the scope only. The tab,
 * the search text and the order are not part of it; the run acts on the
 * failed and held members.
 */
export function listSource(filters) {
    // A batch is its own source ({kind: 'batch', batch_id}); a list source cannot carry it.
    if (filters && filters.batch !== null && filters.batch !== undefined && filters.batch !== '') {
        throw new Error('A batch filter needs a batch source.');
    }

    // The server's list source has no search text: a run would act on more jobs than the list shows.
    if (filters && filters.q !== null && filters.q !== undefined && filters.q !== '') {
        const error = new Error(codeText('search_not_in_bulk'));

        error.code = 'search_not_in_bulk';
        throw error;
    }

    const value = key => (filters && filters[key] !== undefined && filters[key] !== '' ? filters[key] : null);

    return {kind: 'list', connection: value('connection'), queue: value('queue'), class: value('class'), tag: value('tag'), from: value('from'), to: value('to')};
}

/**
 * The body of `POST /api/bulk/run`. The token goes back exactly as the
 * freeze answer gave it: the client never rebuilds it from the parsed fields.
 * Only a forget sends `confirmed_total`.
 */
export function runBody(frozen, after, confirmedTotal) {
    if (!frozen || typeof frozen.token !== 'string' || frozen.token === '') throw new Error('A bulk run needs the token of its freeze.');

    return {
        token: frozen.token,
        after: after === undefined ? null : after,
        confirmed_total: frozen.action === 'forget' ? confirmedTotal : null,
    };
}

/**
 * The total that the operator typed, or null: digits without a leading zero,
 * with optional "," or space between thousands (one kind per number).
 */
export function parseConfirmation(text) {
    if (typeof text !== 'string') return null;

    const value = text.trim();

    if (!/^(0|[1-9]\d*|[1-9]\d{0,2}(,\d{3})+|[1-9]\d{0,2}( \d{3})+)$/.test(value)) return null;

    return Number(value.replace(/[, ]/g, ''));
}

/**
 * Why a forget cannot start, as a wording code, or null: `total_not_exact`
 * when the frozen total is capped, unknown or not exact;
 * `confirmation_mismatch` when the typed total differs from it. A retry
 * needs no typed total.
 */
export function forgetBlocker(frozen, typed) {
    if (!frozen || frozen.action !== 'forget') return null;

    const total = frozen.total;

    if (frozen.exact !== true || !total || total.at_least === true || typeof total.count !== 'number') return 'total_not_exact';

    return parseConfirmation(typed) === total.count ? null : 'confirmation_mismatch';
}

/** The client state of a new run. */
export function startRun(frozen) {
    return {
        ref: frozen ? frozen.ref : null,
        action: frozen ? frozen.action : null,
        pages: 0,
        after: null,
        done: false,
        remaining: frozen ? frozen.total : null,
        totals: Object.fromEntries(BULK_OUTCOMES.map(outcome => [outcome, 0])),
        refusedReasons: {},
        results: [],
    };
}

/**
 * The run after one page answer. A total that the page does not give makes
 * that total unknown (null) for the rest of the run.
 */
export function applyPage(run, page) {
    const totals = {};

    for (const outcome of BULK_OUTCOMES) {
        const added = page && page.totals ? page.totals[outcome] : undefined;

        totals[outcome] = run.totals[outcome] === null || typeof added !== 'number' ? null : run.totals[outcome] + added;
    }

    const refusedReasons = {...run.refusedReasons};

    for (const [code, count] of Object.entries((page && page.refused_reasons) || {})) {
        // Only a count: a value of another type is ignored, never added as text.
        if (typeof count === 'number' && Number.isFinite(count)) {
            refusedReasons[code] = (refusedReasons[code] || 0) + count;
        }
    }

    return {
        ...run,
        pages: run.pages + 1,
        after: page && page.next_after !== undefined ? page.next_after : null,
        done: !!page && page.done === true,
        remaining: page && page.remaining !== undefined ? page.remaining : null,
        totals,
        refusedReasons,
        results: [...run.results, ...((page && Array.isArray(page.results)) ? page.results : [])],
    };
}

/**
 * Whether the runner asks for the next page: not after `done`, not after a
 * Stop, and never again from the start when a later page came back without a
 * cursor.
 */
export function shouldRunNextPage(run, stopRequested) {
    if (run.done || stopRequested) return false;

    return run.pages === 0 || (typeof run.after === 'string' && run.after !== '');
}

/** A stored value in quotes, so a leading or trailing space stays visible. */
function quoted(value) {
    return `“${value}”`;
}

/** The time range of a source in words, as a list of at most one part. */
function rangeWords(source) {
    const from = typeof source.from === 'number' ? source.from : null;
    const to = typeof source.to === 'number' ? source.to : null;

    if (from !== null && to !== null) return [`from ${formatLocal(from)} to ${formatLocal(to)}`];
    if (from !== null) return [`since ${formatLocal(from)}`];
    if (to !== null) return [`until ${formatLocal(to)}`];

    return [];
}

/** The most characters of a group's message in its name. */
const NAME_MESSAGE_CHARACTERS = 80;

/** "TransportException: Connection timed out": the short class and the first line of a group, or null. */
function groupName(names) {
    if (!names || typeof names.exception_class !== 'string' || names.exception_class === '') return null;

    const parts = names.exception_class.split('\\');
    const short = parts[parts.length - 1] || names.exception_class;
    const line = typeof names.message === 'string' ? names.message.split(/\r\n|\n|\r/)[0] : '';

    if (line === '') return short;

    const cut = line.length > NAME_MESSAGE_CHARACTERS ? `${line.slice(0, NAME_MESSAGE_CHARACTERS - 1)}…` : line;

    return `${short}: ${cut}`;
}

/**
 * The source of a run in words. A groups source that the host built with
 * `names` (screens/failed/failures.js groupsSource()) names its first group.
 */
export function sourceSummary(source) {
    if (!source || typeof source !== 'object') return 'an unknown selection';

    if (source.kind === 'groups') {
        const count = Array.isArray(source.keys) ? source.keys.length : 0;
        const where = `on ${source.queue ? quoted(source.queue) : 'every queue'}`;
        const name = groupName(Array.isArray(source.names) ? source.names[0] : null);
        let what = `${count} failure ${count === 1 ? 'group' : 'groups'} ${where}`;

        if (name !== null && count === 1) what = `the failure group ${quoted(name)} ${where}`;
        if (name !== null && count > 1) what = `${count} failure groups, ${quoted(name)} and ${count - 1} more, ${where}`;

        return [what, ...rangeWords(source)].join(', ');
    }

    if (source.kind === 'list') {
        let where = 'every queue';

        if (source.connection && source.queue) where = quoted(`${source.connection}:${source.queue}`);
        else if (source.connection) where = `every queue of ${quoted(source.connection)}`;

        const parts = [`the failed jobs on ${where}`];

        if (source.class) parts.push(`class ${quoted(source.class)}`);
        if (source.tag) parts.push(`tag ${quoted(source.tag)}`);

        return [...parts, ...rangeWords(source)].join(', ');
    }

    if (source.kind === 'batch') return `the failed members of batch ${quoted(source.batch_id)}`;

    return 'an unknown selection';
}

/** The explanation of an uncertain answer. */
export const UNCERTAIN_NOTE = 'Not decided yet: the runner decides it; it is never retried as new work.';

/** The localStorage key of the current run's ref (a convenience). */
export const BULK_REF_KEY = 'orreryBulkRun';

/** The freeze refusals that leave nothing to confirm: the sheet offers only a way back. */
const BLOCKING_CODES = ['total_not_exact', 'too_many', 'app_key_missing', 'config_invalid', 'schema_outdated', 'invalid', 'redaction_config_invalid'];

/** The text after a page whose outcome is not known. */
const PAGE_UNKNOWN = 'This page may have run; the counts cover the pages that answered. Check the run record before you start a new run.';

const OPERATION_STATES = ['done', 'published', 'claimed', 'aborting', 'aborted'];

const REF = /^[0-9a-f]{32}$/;

function number(value) {
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

/** "450", "at least 10,000" or "—". */
function countText(total) {
    const count = total ? number(total.count) : null;

    if (count === null) return '—';

    return (total.at_least === true ? 'at least ' : '') + formatCount(count);
}

function actionWord(action) {
    return action === 'forget' ? 'Forget' : 'Retry';
}

function errorBody(error) {
    const data = error && error.response ? error.response.data : null;

    return data && typeof data === 'object' ? data : {};
}

/**
 * The sheet title: "Forget 1,284 failed jobs?", or "Forget 60 jobs?" when
 * the exact total includes members that are being retried now (`held`), so
 * the title never calls them failed.
 */
export function frozenTitle(frozen, held = null) {
    const total = frozen ? frozen.total : null;
    const one = total && total.count === 1 && total.at_least !== true;
    const mixed = heldSplit({frozen, held}) !== null;

    return `${actionWord(frozen && frozen.action)} ${countText(total)} ${mixed ? '' : 'failed '}${one ? 'job' : 'jobs'}?`;
}

/** The confirm button: "Forget 1,284 jobs". */
export function confirmLabel(frozen) {
    const total = frozen ? frozen.total : null;
    const one = total && total.count === 1 && total.at_least !== true;

    return `${actionWord(frozen && frozen.action)} ${countText(total)} ${one ? 'job' : 'jobs'}`;
}

/**
 * The sheet text of a freeze answer: the source in words, what the action
 * does, and the held members (skipped; unknown is never 0).
 */
export function frozenMessage(answer, hostSource = null) {
    const frozen = answer ? answer.frozen : null;
    const source = frozen ? frozen.source : null;
    // The host's snapshot may carry the group names; the frozen source is the server's and has none.
    const named = source && hostSource && source.kind === 'groups' && hostSource.kind === 'groups' && Array.isArray(hostSource.names)
        ? {...source, names: hostSource.names}
        : source;
    const words = sourceSummary(named);
    const parts = [source && source.kind === 'groups' ? `Every failed job of ${words}.` : `All ${words}.`];

    parts.push(frozen && frozen.action === 'forget'
        ? 'Their failed-job records are deleted. This cannot be undone.'
        : 'Each one goes back on its queue as a new attempt.');

    const held = answer ? answer.held : null;
    const heldCount = held ? number(held.count) : null;
    const split = heldSplit(answer);

    if (heldCount === null) {
        parts.push('Whether some of them are being retried now is not known.');
    } else if (split !== null) {
        // The list counts only the failed jobs; the run also counts the held ones, so name both (4.9).
        // A held member is skipped while its retry runs (it ends "uncertain"); a retry that stops first leaves it failed, so the run acts on it.
        const done = frozen && frozen.action === 'forget' ? 'forgotten' : 'retried';

        parts.push(`${formatCount(split.total)} jobs match: ${formatCount(split.failed)} failed and ${formatCount(split.held)} being retried now.`);
        parts.push(split.held === 1
            ? `The 1 being retried is skipped while its retry runs and ends “uncertain”; if that retry stops before the run reaches it, it is ${done} like the others.`
            : `The ${formatCount(split.held)} being retried are skipped while their retries run and end “uncertain”; if a retry stops before the run reaches its job, that job is ${done} like the others.`);
    } else if (heldCount > 0 || held.at_least === true) {
        const text = countText(held);
        const one = heldCount === 1 && held.at_least !== true;

        parts.push(`${text.charAt(0).toUpperCase()}${text.slice(1)} of them ${one ? 'is' : 'are'} being retried now and ${one ? 'is' : 'are'} skipped.`);
    }

    return parts.join(' ');
}

/**
 * The exact split of a freeze into failed and held members, or null when
 * there is no held member or a count is not exact.
 */
function heldSplit(answer) {
    const total = answer && answer.frozen ? answer.frozen.total : null;
    const held = answer ? answer.held : null;
    const totalCount = total && total.at_least !== true ? number(total.count) : null;
    const heldCount = held && held.at_least !== true ? number(held.count) : null;

    if (totalCount === null || heldCount === null || heldCount <= 0 || heldCount > totalCount) return null;

    return {total: totalCount, failed: totalCount - heldCount, held: heldCount};
}

/**
 * The words beside a forget's typed count when it includes held members:
 * "60 is 59 failed jobs and 1 job being retried now (skipped while its
 * retry runs).", so
 * the number to type is not taken for the list's failed count. Null
 * without held members or without exact counts.
 */
export function confirmationNote(answer) {
    const split = heldSplit(answer);

    if (split === null) return null;

    const failed = `${formatCount(split.failed)} failed ${split.failed === 1 ? 'job' : 'jobs'}`;
    const held = `${formatCount(split.held)} ${split.held === 1 ? 'job' : 'jobs'} being retried now (skipped while ${split.held === 1 ? 'its retry runs' : 'their retries run'})`;

    return `${formatCount(split.total)} is ${failed} and ${held}.`;
}

/**
 * A failed freeze: `blocked` when the server refused it (nothing was
 * frozen and nothing can be confirmed: too many, not exact, no APP_KEY, the
 * configuration or the schema), with the operator text of its code; the
 * maximum comes from the response.
 */
export function freezeFailure(error) {
    if (!error || !error.response) {
        return {blocked: false, code: null, text: 'The server did not answer. Nothing was frozen; try again.'};
    }

    const body = errorBody(error);
    const code = typeof body.error === 'string' && body.error !== '' ? body.error : null;

    if (code === null) {
        return {blocked: false, code: null, text: `The request failed (HTTP ${error.response.status}). Nothing was frozen.`};
    }

    const maximum = number(body.maximum);

    return {blocked: BLOCKING_CODES.includes(code), code, text: codeText(code, {maximum: maximum === null ? null : formatCount(maximum)})};
}

/**
 * A failed page of a run. Without an answer (or with a server error) the
 * page may have run: `unknown` is true and the text sends the operator to
 * the run record.
 */
export function runFailure(error) {
    const body = errorBody(error);
    const code = typeof body.error === 'string' && body.error !== '' ? body.error : null;
    const status = error && error.response ? error.response.status : null;

    if (status === null) return {code: null, unknown: true, text: `The server did not answer. ${PAGE_UNKNOWN}`};

    if (status >= 500 || code === null) {
        // No "Try again" before a page whose outcome is unknown.
        const said = code !== null ? codeText(code).replace(/\s*Try again\.$/, '') : `The server answered with an error (HTTP ${status}).`;

        return {code, unknown: true, text: `${said} ${PAGE_UNKNOWN}`};
    }

    return {code, unknown: false, text: codeText(code)};
}

/** The totals per outcome: "16 accepted · 2 refused · …"; an unknown total is "—". */
export function totalsText(totals) {
    return BULK_OUTCOMES
        .map(outcome => {
            const value = totals ? number(totals[outcome]) : null;

            return `${value === null ? '—' : formatCount(value)} ${outcomeLabel(outcome)}`;
        })
        .join(' · ');
}

/** "5 remaining", "at least 10,000 remaining" or "remaining unknown". */
export function remainingText(remaining) {
    if (!remaining || number(remaining.count) === null) return 'remaining unknown';

    return `${countText(remaining)} remaining`;
}

/** The headline of a run: running, stopped, failed or finished. */
export function runHeadline(run, {stopped = false, failed = false} = {}) {
    const action = actionWord(run.action);

    if (failed) return `${action} stopped by an error after page ${run.pages}: nothing is undone.`;
    if (stopped && !run.done) return `${action} stopped after page ${run.pages}: nothing is undone.`;
    if (!run.done) return `${action} running: page ${run.pages + 1}…`;

    const values = BULK_OUTCOMES.map(outcome => (run.totals ? number(run.totals[outcome]) : null));
    const accepted = run.totals ? number(run.totals.accepted) : null;

    if (values.includes(null)) {
        return `${action} finished: ${accepted === null ? '—' : formatCount(accepted)} accepted; some counts are unknown`;
    }

    const seen = values.reduce((sum, value) => sum + value, 0);

    return `${action} finished: ${formatCount(accepted)} of ${formatCount(seen)} accepted`;
}

/** The refused reasons, most frequent first: "3 × <operator text>". */
export function refusedLines(reasons) {
    return Object.entries(reasons && typeof reasons === 'object' ? reasons : {})
        .filter(([, count]) => number(count) !== null)
        .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
        .map(([code, count]) => ({code, count, text: `${formatCount(count)} × ${codeText(code)}`}));
}

/** One client page of the per-member results (`size` rows; the page is clamped). */
export function resultPage(results, page, size = 20) {
    const list = Array.isArray(results) ? results : [];
    const pages = Math.max(1, Math.ceil(list.length / size));
    const current = Math.min(Math.max(0, Math.floor(Number(page) || 0)), pages - 1);

    return {rows: list.slice(current * size, current * size + size), page: current, pages};
}

/**
 * How a stored run ended: `running` (stored at its start; still so after a
 * reload), `done` (the server said so), `stopped` (the user stopped it) or
 * `error` (a page failed).
 */
const RUN_ENDS = ['running', 'done', 'stopped', 'error'];

/** The localStorage value of the current run, with how it ended (RUN_ENDS; anything else is `running`). */
export function storedValue(ref, end) {
    const state = RUN_ENDS.includes(end) ? end : 'running';

    return JSON.stringify({ref, done: state === 'done', end: state});
}

/**
 * The stored run ({ref, done, end}) of a localStorage value, or null when it
 * is not one. A value without `end` (an older one) ended `done` or `running`.
 */
export function storedRun(raw) {
    if (typeof raw !== 'string') return null;

    try {
        const value = JSON.parse(raw);

        if (!value || typeof value.ref !== 'string' || !REF.test(value.ref)) return null;

        const end = RUN_ENDS.includes(value.end) ? value.end : (value.done === true ? 'done' : 'running');

        return {ref: value.ref, done: end === 'done', end};
    } catch (error) {
        return null;
    }
}

/** Whether the stored value (localStorage) still names the run `ref`: a newer run may have replaced it. */
export function storedRunIs(raw, ref) {
    const stored = storedRun(raw);

    return stored !== null && stored.ref === ref;
}

/**
 * The server record of a run (`GET /api/bulk/{ref}`): the counters, which
 * sum the pages that answered, and the operation states of the ledger.
 * The states are exact while `operations_complete` is true; after the
 * ledger's retention they are lower bounds ("at least", "≥").
 */
export function recordLines(record) {
    const operations = record ? record.operations : null;
    const complete = record ? record.operations_complete : null;
    const prefix = complete === false ? '≥ ' : '';

    return {
        counters: totalsText(record ? record.counters : null),
        operations: OPERATION_STATES
            .map(state => {
                const value = operations ? number(operations[state]) : null;

                return `${value === null ? '—' : `${prefix}${formatCount(value)}`} ${state}`;
            })
            .join(' · '),
        operationsTitle: complete === true ? 'Operations, exact' : (complete === false ? 'Operations, at least' : 'Operations'),
    };
}

/** How a stored run that did not finish ended, in words (storedRun() `end`). */
const END_WORDS = {
    stopped: 'stopped before its last page',
    error: 'stopped by an error',
    running: 'stopped when the page reloaded',
};

/** The headline of a run found again after a reload: it names how the run ended (storedRun() `end`). */
export function recoveredHeadline(stored, record) {
    const action = record && record.action === 'forget' ? 'forget' : 'retry';
    const actor = record && typeof record.actor === 'string' && record.actor !== '' ? record.actor : 'an unknown actor';
    const text = `The last run in this browser: a ${action} of ${countText(record ? record.total : null)} failed members by ${actor}`;
    const end = stored ? (stored.end || (stored.done ? 'done' : 'running')) : 'running';

    return end === 'done' ? text : `${text}, ${END_WORDS[end] || END_WORDS.running}`;
}

/**
 * What the recovered panel offers for a run found again after a reload
 * (`{stored, record}`): "Start a new run…" only when the run's record was
 * read, because only the record names the run's action and source (an
 * unread one would start another selection, and forget the stored ref);
 * else "Read again", which keeps the stored ref.
 */
export function recoveredActions(recovered) {
    const read = !!recovered && !!recovered.record && typeof recovered.record === 'object';

    return {restart: read, readAgain: !!recovered && !read};
}

/** A recent run in words: "Retry · the failed members of batch b-1". */
export function runLabel(record) {
    return `${actionWord(record && record.action)} · ${sourceSummary(record ? record.source : null)}`;
}

/**
 * Why a source cannot start a bulk run ({code, text}; `code` null for a
 * malformed source), or null. The freeze is never sent then.
 */
export function bulkBlocker(action, source) {
    try {
        freezeBody(action, source);

        return null;
    } catch (error) {
        return {code: error.code || null, text: error.message};
    }
}

/** The number that a forget asks to type, or "—" while the total is unknown. */
export function confirmationCount(frozen) {
    const count = frozen && frozen.total ? number(frozen.total.count) : null;

    return count === null ? '—' : String(count);
}

/**
 * The localStorage key of a bulk host: one stored run per action and
 * source kind, so a batch screen never recovers a run of the failure groups.
 */
export function storageKeyFor(action, source) {
    const kind = source && typeof source.kind === 'string' ? source.kind : 'unknown';

    return `${BULK_REF_KEY}:${action}:${kind}`;
}

/**
 * A key of the freeze that the host asks for: an open sheet freezes again
 * when it changes (the screen's filters changed under it).
 */
export function sourceKey(action, source) {
    const blocker = bulkBlocker(action, source);

    if (blocker) return `blocked:${blocker.code || 'source'}:${JSON.stringify(source)}`;

    return JSON.stringify(freezeBody(action, source));
}

/** The phases of the sheet (before a run). */
const SHEET_PHASES = ['freezing', 'confirm', 'blocked', 'failed'];

/** Whether a freeze may start: never during a run, never without the operate ability. */
export function canFreeze(phase, canOperate) {
    return canOperate === true && phase !== 'running';
}

/** Whether the phase shows the sheet. */
export function isSheetPhase(phase) {
    return SHEET_PHASES.includes(phase);
}

/** The phase after the last page: done only when the server said so, else stopped. */
export function endPhase(run) {
    return run && run.done === true ? 'done' : 'stopped';
}

/**
 * The recent runs of `GET /api/bulk` that a host of one source kind lists
 * after a reload: only the runs of its own kind (`source.kind`), so the
 * failure groups never list a batch's run; with the host's batch source,
 * only the runs of that batch. Without a known kind, none.
 */
export function recentRunsOf(runs, kind, hostSource = null) {
    if (typeof kind !== 'string' || kind === '' || !Array.isArray(runs)) return [];

    // A batch screen lists the runs of its own batch only.
    const batch = kind === 'batch' && hostSource && typeof hostSource.batch_id === 'string' && hostSource.batch_id !== '' ? hostSource.batch_id : null;

    return runs.filter(run => run && run.source && typeof run.source === 'object' && run.source.kind === kind
        && (batch === null || run.source.batch_id === batch));
}

/** The stored-run keys that a host of one source kind recovers: both actions. */
export function recoveryKeys(kind) {
    return [storageKeyFor('retry', {kind: kind || 'unknown'}), storageKeyFor('forget', {kind: kind || 'unknown'})];
}

/**
 * The stored run to show after a reload: of the entries ({key, stored,
 * record}), the one whose record ran last; an entry without a record only
 * when no other has one.
 */
export function latestRecovered(entries) {
    let best = null;

    for (const entry of Array.isArray(entries) ? entries : []) {
        const at = entry.record ? number(entry.record.last_run_at) : null;
        const bestAt = best && best.record ? number(best.record.last_run_at) : null;

        if (best === null || (at !== null && (bestAt === null || at > bestAt))) best = entry;
    }

    return best;
}

/**
 * Whether an open sheet freezes again: only for a new selection key (the
 * host's user changed the selection), never for a key already frozen, and
 * never outside the sheet.
 */
export function needsRefreeze({open, phase, key, frozenFor}) {
    return open === true && isSheetPhase(phase) && phase !== 'idle' && key !== frozenFor;
}

/**
 * What an open request does: 'freeze', or 'refuse' during a run and
 * without the operate ability (the host is told `close`, so its button
 * does not stay pressed).
 */
export function openAction(phase, canOperate) {
    return canFreeze(phase, canOperate) ? 'freeze' : 'refuse';
}

/**
 * The action, the source and the selection key of a freeze. A first freeze
 * (`reuse` false) takes a deep copy of the host's live props. "Try again"
 * after a failed freeze (`reuse` true) takes the snapshot of the failed
 * freeze, so a poll or a route change of the host never turns a retry into
 * a freeze of another selection; without a snapshot it falls back to the
 * live props.
 */
export function freezeInput({reuse = false, action, source, selectionKey = null, frozenAction = null, frozenSource = null, frozenFor = null}) {
    if (reuse && frozenAction !== null) {
        return {action: frozenAction, source: frozenSource === null ? null : JSON.parse(JSON.stringify(frozenSource)), key: frozenFor};
    }

    return {action, source: source === null || source === undefined ? null : JSON.parse(JSON.stringify(source)), key: selectionKey};
}

/**
 * The API client of a bulk run: the shared HTTP client with the
 * dashboard's base path (window.Orrery.basePath) before every URL, because
 * the sheet posts '/api/bulk/…'.
 */
export function basePathClient(http, basePath) {
    const prefix = typeof basePath === 'string' ? basePath : '';

    return {
        get: (url, config) => http.get(prefix + url, config),
        post: (url, body, config) => http.post(prefix + url, body, config),
    };
}
