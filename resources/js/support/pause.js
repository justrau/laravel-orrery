/**
 * The pause model of a queue, shared by every screen, so one
 * state reads the same on the sidebar, the Overview and the Queues screen.
 *
 * A workload row lists every cause that pauses the queue in `pauses`, each
 * `{scope, source, issued_at, issued_by, possible}`. A queue is paused when
 * any definite cause holds (an OR of the scopes: no cause overrides another).
 * Sources: `orrery` (an Orrery pause; for a queue, Laravel's pause that
 * Orrery issued), `queue:pause` (Laravel's queue pause from the command
 * line) and `queue:pause --all` (Laravel's global pause). A `possible` cause
 * is an Orrery queue pause whose Laravel flag is unknown: it never counts
 * as a pause, so it never hides an over-limit queue.
 *
 * Unknown is never "running": `paused_unknown` (a read failed),
 * `queue_cause_unknown` (Laravel's global pause masks the queue's own flag)
 * and `native_unknown` (Laravel's state was not read) keep the state from
 * being known off. A row without a `pauses` list is unknown too.
 */
import {toSeconds} from './time.js';
import {codeText} from './wording.js';

/** The note while Laravel's global pause masks a queue's own pause. */
export const MASKED_NOTE = "Own pause state unknown while Laravel's global pause is on";

/** The note while Laravel's pause state is not read (the cache store is the app's SQLite database). */
export const NATIVE_UNKNOWN_NOTE = "Laravel's pause state unknown; a queue:continue run from the command line is not seen";

/** The command that lifts Laravel's global pause. */
export const CONTINUE_ALL_COMMAND = 'php artisan queue:continue --all';

const SCOPES = ['global', 'connection', 'queue'];

/** The words of a definite cause in a label; "queue" for an Orrery queue pause (alone it is "Paused"). */
function causeWords(cause) {
    if (cause.scope === 'global') return cause.source === 'queue:pause --all' ? 'queue:pause --all' : 'all queues';
    if (cause.scope === 'connection') return 'connection';

    return cause.source === 'queue:pause' ? 'queue:pause' : 'queue';
}

/**
 * The pause state of a workload row: { causes, definite, possible, paused,
 * pausedUnknown, queueCauseUnknown, nativeUnknown, knownOff }. Each cause is
 * { scope, source, issuedAt, issuedBy, possible }.
 */
export function pauseState(row) {
    const list = row && Array.isArray(row.pauses) ? row.pauses : null;
    const causes = (list || [])
        .filter(cause => cause && SCOPES.includes(cause.scope) && typeof cause.source === 'string')
        .map(cause => ({
            scope: cause.scope,
            source: cause.source,
            issuedAt: toSeconds(cause.issued_at),
            issuedBy: typeof cause.issued_by === 'string' && cause.issued_by !== '' ? cause.issued_by : null,
            possible: cause.possible === true,
        }));
    const definite = causes.filter(cause => !cause.possible);
    const possible = causes.filter(cause => cause.possible);
    const pausedUnknown = list === null || row.paused_unknown === true;
    const queueCauseUnknown = !!row && row.queue_cause_unknown === true;
    const nativeUnknown = !!row && row.native_unknown === true;

    return {
        causes,
        definite,
        possible,
        paused: definite.length > 0,
        pausedUnknown,
        queueCauseUnknown,
        nativeUnknown,
        knownOff: definite.length === 0 && possible.length === 0 && !pausedUnknown && !queueCauseUnknown && !nativeUnknown,
    };
}

/**
 * The label of a pause state: "Paused" (an Orrery queue pause alone), "Paused
 * (connection)", "Paused (all queues)", "Paused (queue:pause)", "Paused
 * (queue:pause --all)", or several words for several causes ("Paused (all
 * queues, connection)"; the queue's own Orrery pause beside another cause is
 * "queue": "Paused (connection, queue)"). Only possible causes: "Possibly paused", or
 * "Possibly paused (queue:pause)" while Laravel's global pause masks the
 * queue. Null when no cause is known.
 */
export function pauseLabel(state) {
    if (!state) return null;

    if (state.paused) {
        const words = [...new Set(state.definite.map(causeWords))];

        return words.length === 1 && words[0] === 'queue' ? 'Paused' : `Paused (${words.join(', ')})`;
    }

    if (state.possible.length) return state.queueCauseUnknown ? 'Possibly paused (queue:pause)' : 'Possibly paused';

    return null;
}

/** The Orrery cause with an actor or a time that a detail names: the first definite one, else the first possible one. */
export function issuedCause(state) {
    if (!state) return null;

    return state.definite.find(cause => cause.source === 'orrery') || state.possible[0] || null;
}

/**
 * The notes of a pause state, in a fixed order: the masked queue cause
 * (with "possibly paused (queue:pause)" for an Orrery queue pause under
 * it), the unread Laravel state, and a failed read.
 */
export function pauseNotes(state) {
    if (!state) return [];

    return [
        state.queueCauseUnknown && state.paused && state.possible.length ? 'possibly paused (queue:pause)' : null,
        state.queueCauseUnknown ? MASKED_NOTE : null,
        state.nativeUnknown ? NATIVE_UNKNOWN_NOTE : null,
        state.pausedUnknown ? 'pause state unknown' : null,
    ].filter(Boolean);
}

/**
 * The commands that lift a pause that Orrery's actions cannot lift:
 * Laravel's global pause needs `php artisan queue:continue --all`.
 */
export function pauseCommands(state) {
    return state && state.definite.some(cause => cause.source === 'queue:pause --all') ? [CONTINUE_ALL_COMMAND] : [];
}

/** Whether a queue-scoped Continue can change something: a queue cause, or an unknown pause state. */
export function queueContinueApplies(state) {
    if (!state) return true;

    return state.causes.some(cause => cause.scope === 'queue') || state.pausedUnknown || state.queueCauseUnknown || state.nativeUnknown;
}

/** The broader causes (global, connection) that a queue Continue leaves in force. */
export function broaderCauses(state) {
    return state ? state.definite.filter(cause => cause.scope !== 'queue') : [];
}

/**
 * The words of a list of broader causes: "the pause of all queues",
 * "the pause of the connection", "Laravel's global pause (queue:continue
 * --all)", joined with "and".
 */
export function causesText(causes) {
    const words = [...new Set(causes.map(cause => {
        if (cause.scope === 'global') return cause.source === 'queue:pause --all' ? "Laravel's global pause (queue:continue --all)" : 'the pause of all queues';

        return 'the pause of the connection';
    }))];

    return words.length > 1 ? `${words.slice(0, -1).join(', ')} and ${words[words.length - 1]}` : (words[0] || '');
}

/** The pause that a gauge draws ({ scope, issued_at, issued_by } or null): the first definite cause. */
export function gaugePause(state) {
    if (!state || !state.paused) return null;

    const cause = issuedCause(state) && !issuedCause(state).possible ? issuedCause(state) : state.definite[0];

    return {scope: cause.scope, issued_at: cause.issuedAt, issued_by: cause.issuedBy || (cause.source === 'orrery' ? null : cause.source)};
}

/** The words of one `still_paused_by` cause of a continue answer ({scope, value, source}). */
function stillPausedWords(cause) {
    if (cause.scope === 'global') return cause.source === 'queue:pause --all' ? "Laravel's global pause (queue:continue --all)" : 'the pause of all queues';
    if (cause.scope === 'connection') return `the pause of connection ${cause.value}`;

    return `Laravel's queue pause (queue:continue ${cause.value})`;
}

function joinWords(words) {
    return words.length > 1 ? `${words.slice(0, -1).join(', ')} and ${words[words.length - 1]}` : (words[0] || '');
}

/**
 * The warning after a continue whose signals all answered, from the
 * answers ({body, data}; `target(body)` names a signal's target): a
 * continue is a success only when every answer says that nothing else
 * pauses its target (`still_paused_by` empty, `still_paused_unknown`
 * false). A cause that still pauses it gives a warning that names the
 * cause; an answer without that list is unknown, never a success. Null
 * when every target is known to run again. Shared by the Overview and the
 * Queues screen.
 */
export function continueWarning(title, outcomes, target) {
    const still = [];
    const unknown = [];

    for (const outcome of outcomes) {
        const data = outcome && outcome.data && typeof outcome.data === 'object' ? outcome.data : {};
        const causes = Array.isArray(data.still_paused_by) ? data.still_paused_by.filter(cause => cause && typeof cause === 'object') : null;

        if (causes && causes.length) {
            still.push(`${target(outcome.body)} stays paused by ${joinWords([...new Set(causes.map(stillPausedWords))])}.`);
        } else if (causes === null || data.still_paused_unknown === true) {
            unknown.push(target(outcome.body));
        }
    }

    if (still.length) {
        const unknownNote = unknown.length ? ` The pause state of ${unknown.join(', ')} is unknown.` : '';

        return {tone: 'warning', title: `${title}: still paused.`, detail: `${still.join(' ')}${unknownNote}`};
    }

    if (unknown.length) {
        return {
            tone: 'warning',
            title: `${title}: sent; the pause state is unknown.`,
            detail: `Laravel's pause state of ${unknown.join(', ')} could not be read: it may still be paused.`,
        };
    }

    return null;
}

/**
 * Whether a control answer is a 503 `record_failed` (ControlController): a
 * queue pause or continue whose Laravel part happened, and only Orrery's
 * record of it failed. It is not a failed signal.
 */
export function isRecordFailed(outcome) {
    const data = outcome && outcome.failed && outcome.data && typeof outcome.data === 'object' ? outcome.data : null;

    return !!data && data.error === 'record_failed';
}

/** The state sentences of the `record_failed` answers; `paused` that is not a boolean is unknown. */
function recordFailedStates(outcomes, target) {
    return outcomes.filter(isRecordFailed).map(outcome => {
        const name = target(outcome.body);

        if (outcome.data.paused === true) return `${name} is paused.`;
        if (outcome.data.paused === false) return `Laravel's queue pause of ${name} is lifted; whether another pause still holds it is unknown.`;

        return `The pause state of ${name} is unknown.`;
    });
}

/**
 * The note of the `record_failed` answers for a notice detail (the state of
 * each target, then the wording of the code), or null without one.
 */
export function recordFailedNote(outcomes, target) {
    const states = recordFailedStates(outcomes, target);

    return states.length ? `${states.join(' ')} ${codeText('record_failed')}` : null;
}

/**
 * The warning after control writes whose every failed answer is a
 * `record_failed` (isRecordFailed()): the change happened, so the notice is
 * a warning that says so and names each target's state, never a failure.
 * Null without such an answer. Shared by the Overview and the Queues screen.
 */
export function recordFailedWarning(title, outcomes, target) {
    const note = recordFailedNote(outcomes, target);

    return note === null ? null : {tone: 'warning', title: `${title}: done; Orrery's record of it was not saved.`, detail: note};
}
