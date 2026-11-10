/**
 * The wording of the Job detail's facts:
 * the enforced rule, the terminal explanation of a failed cycle,
 * the stuck signals and the worker liveness. The server returns facts
 * (Lifecycle\EnforcedRule, Lifecycle\Diagnostics, Monitoring\StuckSignals,
 * Workers\WorkerLiveness); these pure helpers fill the templates, so Node
 * can test them.
 *
 * The rules of the SPA hold: an unknown value reads "unknown", never 0; 0
 * tries is "unlimited" and a 0 timeout is "no alarm", both different from
 * unknown. The terminal explanation of a failure that is not a timeout or a
 * MaxAttemptsExceededException states the limits at the failure time as
 * conditions, never as a cause, and the words "root cause" are never used.
 */

import {formatDayClock, formatLocal, relativeTime, toSeconds} from '../../support/time.js';

function isNumber(value) {
    return typeof value === 'number' && Number.isFinite(value);
}

/** A tries value: "unknown", "unlimited (0)" or the number. */
export function triesValue(tries) {
    if (!isNumber(tries)) return 'unknown';

    return tries === 0 ? 'unlimited (0)' : String(tries);
}

/** A timeout value: "unknown", "no alarm (0)" or "120 s". */
export function timeoutValue(timeout) {
    if (!isNumber(timeout)) return 'unknown';

    return timeout === 0 ? 'no alarm (0)' : `${timeout} s`;
}

/**
 * Where an effective value came from: the payload, the worker's option of
 * the same attempt (`--tries` or `--timeout`), or unknown.
 */
export function triesSourceText(source, option = 'tries') {
    if (source === 'payload') return "from the job's payload";
    if (source === 'worker') return `from the worker's --${option === 'timeout' ? 'timeout' : 'tries'}`;

    return 'source unknown';
}

function timeoutPart(timeout, source) {
    return isNumber(timeout) ? `timeout ${timeoutValue(timeout)} (${triesSourceText(source, 'timeout')})` : 'timeout unknown';
}

/**
 * The other stop conditions of a rule (`other_stops`), which apply in every
 * case: the effective timeout, failOnTimeout and maxExceptions.
 */
export function otherStopsText(stops) {
    if (!stops || typeof stops !== 'object') return null;

    const maxExceptions = isNumber(stops.max_exceptions) ? String(stops.max_exceptions) : 'not set';

    return `Also stops on: ${timeoutPart(stops.timeout, stops.timeout_source)}, fail on timeout ${stops.fail_on_timeout === true ? 'on' : 'off'} · maxExceptions ${maxExceptions}`;
}

/**
 * The enforced rule in words: `{headline, stops}`. `context`
 * is 'now' (a running cycle: a passed deadline fails the job at its next
 * check) or 'failure' (the rule at the failure time). A null rule is a
 * frozen cycle without a finish time: unknown.
 */
export function enforcedRuleText(rule, context = 'now') {
    if (!rule || typeof rule !== 'object') {
        return {headline: 'Enforced rule unknown: this cycle has no recorded finish time.', stops: null};
    }

    const deadline = formatLocal(rule.deadline);
    let headline;

    switch (rule.kind) {
        case 'deadline':
            headline = `Retry deadline ${deadline}. Laravel does not enforce the attempt count (tries: ${isNumber(rule.tries) ? rule.tries : 'unknown'}) until then.`;
            break;
        case 'deadline_passed':
            headline = context === 'failure'
                ? `Retry deadline passed at ${deadline}.`
                : `Retry deadline passed at ${deadline}. Laravel fails the job at its next check (the next reservation or the next exception).`;
            break;
        case 'attempt_limit':
            headline = `Attempt limit: ${triesValue(rule.tries)} (${triesSourceText(rule.tries_source)})`;
            break;
        case 'none':
            headline = `No attempt limit (tries 0, ${triesSourceText(rule.tries_source)})`;
            break;
        case 'unknown':
            headline = 'Attempt limit unknown';
            break;
        default:
            headline = `Enforced rule: ${String(rule.kind)}`;
    }

    return {headline, stops: otherStopsText(rule.other_stops)};
}

const CONDITIONS_NOTE = 'These are the conditions at the failure, not its cause: a job can call fail() itself, and the exception handler can stop retries. They are the limits that Orrery knows, not a complete account of the failure policy.';

function deadlineCondition(facts) {
    if (!isNumber(facts.deadline)) return 'Retry deadline: not set';

    const passed = facts.failed_at !== undefined && isNumber(toSeconds(facts.failed_at))
        ? Math.floor(facts.failed_at) > facts.deadline
        : null;

    return `Retry deadline: ${formatLocal(facts.deadline)} (${passed === null ? 'whether it had passed is not known' : (passed ? 'passed' : 'not passed')} at the failure)`;
}

/**
 * The terminal explanation of a failed cycle (`cycle.terminal`):
 * `{headline, detail, conditions}`, or null without one. One template
 * per kind; for `max_attempts_other` and `other` the facts are listed as
 * conditions, never as a cause.
 */
export function terminalText(terminal) {
    if (!terminal || typeof terminal !== 'object') return null;

    const facts = terminal.facts && typeof terminal.facts === 'object' ? terminal.facts : {};
    const attempt = isNumber(facts.attempt) ? facts.attempt : '—';

    switch (terminal.kind) {
        case 'timeout':
            return {
                headline: `Timed out on attempt ${attempt}`,
                detail: `The effective timeout was ${timeoutValue(facts.timeout)} (${triesSourceText(facts.timeout_source, 'timeout')}), and failOnTimeout was ${facts.fail_on_timeout === true ? 'on' : 'off'}.`,
                conditions: [],
            };

        case 'deadline_passed':
            return {
                headline: `Failed on attempt ${attempt}: the retry deadline passed`,
                detail: `The deadline was ${formatLocal(facts.deadline)}; the job failed at ${formatLocal(facts.failed_at)}.`,
                conditions: [],
            };

        case 'attempt_limit': {
            if (!isNumber(facts.tries)) {
                return {headline: `Failed on attempt ${attempt}: the attempt limit was reached`, detail: 'The tries value is not known.', conditions: []};
            }

            const maxExceptions = isNumber(facts.max_exceptions) ? ` maxExceptions was ${facts.max_exceptions}.` : '';

            return {
                headline: `Failed on attempt ${attempt}: the attempt limit (${facts.tries}) was reached`,
                detail: `The limit of ${facts.tries} tries came ${triesSourceText(facts.tries_source)}.${maxExceptions}`,
                conditions: [],
            };
        }

        case 'max_attempts_other':
            return {
                headline: `Failed with MaxAttemptsExceededException on attempt ${attempt}`,
                detail: 'Laravel throws this exception when the attempts pass the tries value, when the retry deadline passes, and, for jobs that count crashes, when maxExceptions is reached; these values do not show which one applied.',
                conditions: [
                    `Tries: ${triesValue(facts.tries)} (${triesSourceText(facts.tries_source)})`,
                    deadlineCondition(facts),
                    `maxExceptions: ${isNumber(facts.max_exceptions) ? facts.max_exceptions : 'not set'}`,
                ],
            };

        case 'other': {
            const conditions = [];

            if (!isNumber(facts.tries)) {
                conditions.push(`At the failure time: attempt ${attempt}; the tries value is not known.`);
            } else if (facts.tries === 0) {
                conditions.push(`At the failure time: attempt ${attempt}; no attempt limit (tries 0).`);
            } else {
                conditions.push(`At the failure time: attempt ${attempt} of the tries value ${facts.tries}.`);
            }

            if (isNumber(facts.deadline)) {
                const state = facts.deadline_passed === true ? 'had passed' : (facts.deadline_passed === false ? 'had not passed' : 'may or may not have passed');

                conditions.push(`At the failure time: the retry deadline ${formatLocal(facts.deadline)} ${state}.`);
            }

            conditions.push(`Other limits: ${timeoutPart(facts.timeout, facts.timeout_source)}, fail on timeout ${facts.fail_on_timeout === true ? 'on' : 'off'} · maxExceptions ${isNumber(facts.max_exceptions) ? facts.max_exceptions : 'not set'}`);

            return {headline: `Failed on attempt ${attempt}`, detail: CONDITIONS_NOTE, conditions};
        }

        default:
            return {headline: 'Failed', detail: null, conditions: []};
    }
}

/** The plural of "attempt" with its verb: "1 attempt has", "2 attempts have". */
function attemptsHave(count) {
    return count === 1 ? '1 attempt has' : `${count} attempts have`;
}

/**
 * One stuck signal `{signal, facts}` in words (without the
 * swap-off text). No signal claims a process death, a crash, a rate or an
 * eventual pickup.
 */
export function signalText(signal) {
    if (!signal || typeof signal !== 'object') return null;

    const facts = signal.facts && typeof signal.facts === 'object' ? signal.facts : {};

    switch (signal.signal) {
        case 'reservation_expired':
            return `Reservation expired at ${formatLocal(facts.lease_expires_at)}: Laravel can give this job to another worker, so it can run twice.`;
        case 'outcome_not_observed':
            return `At least ${attemptsHave(isNumber(facts.gap) ? facts.gap : '—')} no observed outcome so far (for example a timeout without PCNTL, a killed worker, or a monitoring outage).`;
        case 'repeated_releases':
            return `Released ${isNumber(facts.releases) ? facts.releases : '—'} times in this cycle.`;
        case 'attempts_not_capped': {
            const attempts = isNumber(facts.attempts) ? facts.attempts : '—';

            return facts.rule === 'deadline'
                ? `${attempts} attempts. Retry deadline ${formatLocal(facts.deadline)}: Laravel does not enforce the attempt count until then.`
                : `${attempts} attempts and no attempt limit.`;
        }
        default:
            return `Signal: ${String(signal.signal)}`;
    }
}

const LIVENESS = {
    live: {label: 'live', tone: 'success'},
    unknown: {label: 'liveness unknown', tone: 'unknown'},
};

/**
 * The worker of a running row and its lease, as facts:
 * `{worker, label, tone, lease}`. Liveness is not a stuck signal: a long
 * job inside its lease is live. A worker without an id is one that Orrery
 * could not verify; a missing worker is unknown, never idle.
 */
export function livenessText(worker, leaseExpiresAt, now) {
    const leaseAt = toSeconds(leaseExpiresAt);
    const lease = leaseAt === null
        ? 'lease unknown'
        : (leaseAt > now ? `lease until ${formatDayClock(leaseAt, now)}` : `lease expired ${relativeTime(leaseAt, now)}`);

    if (!worker || typeof worker !== 'object') {
        return {worker: null, label: 'worker unknown', tone: 'unknown', lease};
    }

    const id = typeof worker.id === 'string' && worker.id !== '' ? worker.id : null;

    if (id === null) {
        return {worker: null, label: 'a worker that Orrery could not verify', tone: 'unknown', lease};
    }

    if (worker.liveness === 'silent') {
        return {worker: id, label: `silent since ${formatDayClock(worker.silent_since, now)}`, tone: 'warning', lease};
    }

    const known = LIVENESS[worker.liveness] || LIVENESS.unknown;

    return {worker: id, label: known.label, tone: known.tone, lease};
}

/**
 * The line of a pending or reserved row without a stuck signal. The
 * reservation_expired signal needs the lease, so a reserved row with an
 * unknown lease never claims that no signal holds; signals that were not
 * read are not evaluated.
 */
export function signalsEmptyText(status, leaseExpiresAt, signals) {
    if (!Array.isArray(signals)) return 'The stuck signals could not be evaluated.';

    if (status === 'reserved' && toSeconds(leaseExpiresAt) === null) {
        return 'No other stuck signal holds now. Whether the reservation expired cannot be evaluated: the lease is unknown.';
    }

    return 'No stuck signal holds now.';
}
