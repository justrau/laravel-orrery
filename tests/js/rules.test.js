// Tests of resources/js/screens/jobs/rules.js: the wording of the enforced
// rule, the terminal explanation, the stuck signals and the worker liveness
// of the Job detail.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    enforcedRuleText,
    livenessText,
    otherStopsText,
    signalText,
    signalsEmptyText,
    terminalText,
    timeoutValue,
    triesSourceText,
    triesValue,
} from '../../resources/js/screens/jobs/rules.js';

const AT = Date.UTC(2026, 10, 8, 9, 28, 44) / 1000;
const AT_TEXT = '2026-11-08 09:28:44 UTC';
const DEADLINE = Date.UTC(2026, 10, 9, 12, 0, 0) / 1000;
const DEADLINE_TEXT = '2026-11-09 12:00:00 UTC';

const stops = (over = {}) => ({max_exceptions: null, fail_on_timeout: false, timeout: 120, timeout_source: 'worker', ...over});
const rule = (over = {}) => ({kind: 'attempt_limit', deadline: null, tries: 5, tries_source: 'payload', other_stops: stops(), ...over});

// Rejects: an unknown tries value shown as 0, an unlimited 0 shown as a limit of 0, and an unknown or 0 timeout shown as "0 s" (0 is "no alarm", null is unknown).
test('triesValue and timeoutValue keep unknown, unlimited and no alarm apart', () => {
    assert.equal(triesValue(null), 'unknown');
    assert.equal(triesValue(undefined), 'unknown');
    assert.equal(triesValue(0), 'unlimited (0)');
    assert.equal(triesValue(5), '5');

    assert.equal(timeoutValue(null), 'unknown');
    assert.equal(timeoutValue(0), 'no alarm (0)');
    assert.equal(timeoutValue(120), '120 s');
    assert.equal(timeoutValue('120'), 'unknown', 'only a number is a value');
});

// Rejects: a source shown as its raw code, and an unknown source shown as the payload.
test('triesSourceText names where a value came from', () => {
    assert.equal(triesSourceText('payload', 'tries'), "from the job's payload");
    assert.equal(triesSourceText('worker', 'tries'), "from the worker's --tries");
    assert.equal(triesSourceText('worker', 'timeout'), "from the worker's --timeout");
    assert.equal(triesSourceText('unknown', 'tries'), 'source unknown');
    assert.equal(triesSourceText(null, 'timeout'), 'source unknown');
});

// Rejects: a rule template per kind that drops the deadline, the tries value or its source, a running "next check" sentence at the failure time, an unknown limit shown as "No attempt limit", and a missing rule shown as a limit.
test('enforcedRuleText fills the Spec E template of each kind', () => {
    assert.equal(
        enforcedRuleText(rule({kind: 'deadline', deadline: DEADLINE, tries: 1})).headline,
        `Retry deadline ${DEADLINE_TEXT}. Laravel does not enforce the attempt count (tries: 1) until then.`,
    );
    assert.equal(
        enforcedRuleText(rule({kind: 'deadline', deadline: DEADLINE, tries: null, tries_source: 'unknown'})).headline,
        `Retry deadline ${DEADLINE_TEXT}. Laravel does not enforce the attempt count (tries: unknown) until then.`,
    );
    assert.equal(
        enforcedRuleText(rule({kind: 'deadline_passed', deadline: DEADLINE})).headline,
        `Retry deadline passed at ${DEADLINE_TEXT}. Laravel fails the job at its next check (the next reservation or the next exception).`,
    );
    assert.equal(enforcedRuleText(rule({kind: 'deadline_passed', deadline: DEADLINE}), 'failure').headline, `Retry deadline passed at ${DEADLINE_TEXT}.`);
    assert.equal(enforcedRuleText(rule()).headline, "Attempt limit: 5 (from the job's payload)");
    assert.equal(enforcedRuleText(rule({tries_source: 'worker'})).headline, "Attempt limit: 5 (from the worker's --tries)");
    assert.equal(enforcedRuleText(rule({kind: 'none', tries: 0, tries_source: 'worker'})).headline, "No attempt limit (tries 0, from the worker's --tries)");
    assert.equal(enforcedRuleText(rule({kind: 'unknown', tries: null, tries_source: 'unknown'})).headline, 'Attempt limit unknown');
    assert.equal(enforcedRuleText(rule({kind: 'surprise'})).headline, 'Enforced rule: surprise');

    const missing = enforcedRuleText(null);
    assert.equal(missing.headline, 'Enforced rule unknown: this cycle has no recorded finish time.');
    assert.equal(missing.stops, null);
});

// Rejects: other stops that drop maxExceptions, read a null timeout as 0, or name failOnTimeout wrongly; and a rule without its stops line.
test('otherStopsText lists every other stop condition', () => {
    assert.equal(otherStopsText(stops()), "Also stops on: timeout 120 s (from the worker's --timeout), fail on timeout off · maxExceptions not set");
    assert.equal(
        otherStopsText(stops({timeout: null, timeout_source: 'unknown', fail_on_timeout: true, max_exceptions: 3})),
        'Also stops on: timeout unknown, fail on timeout on · maxExceptions 3',
    );
    assert.equal(otherStopsText(stops({timeout: 0, timeout_source: 'payload'})), "Also stops on: timeout no alarm (0) (from the job's payload), fail on timeout off · maxExceptions not set");
    assert.equal(otherStopsText(null), null);
    assert.equal(enforcedRuleText(rule()).stops, otherStopsText(stops()));
});

// Rejects: a terminal explanation that claims a cause for "other" (or uses the words "root cause"), that words the server's timeout kind as another kind (the class precedence itself is Lifecycle\Diagnostics on the server), that drops the attempt, the limit or its source, or that shows an unknown tries value as a limit.
test('terminalText words each terminal kind as facts, and "other" as conditions only', () => {
    const timeout = terminalText({kind: 'timeout', facts: {timeout: 1, timeout_source: 'payload', fail_on_timeout: true, attempt: 1}});
    assert.equal(timeout.headline, 'Timed out on attempt 1');
    assert.equal(timeout.detail, "The effective timeout was 1 s (from the job's payload), and failOnTimeout was on.");

    const deadline = terminalText({kind: 'deadline_passed', facts: {deadline: DEADLINE, failed_at: AT + 86400 * 2, attempt: 4}});
    assert.equal(deadline.headline, 'Failed on attempt 4: the retry deadline passed');
    assert.equal(deadline.detail, `The deadline was ${DEADLINE_TEXT}; the job failed at 2026-11-10 09:28:44 UTC.`);

    const limit = terminalText({kind: 'attempt_limit', facts: {tries: 5, tries_source: 'payload', max_exceptions: null, attempt: 6}});
    assert.equal(limit.headline, 'Failed on attempt 6: the attempt limit (5) was reached');
    assert.equal(limit.detail, "The limit of 5 tries came from the job's payload.");

    const limitMax = terminalText({kind: 'attempt_limit', facts: {tries: 3, tries_source: 'worker', max_exceptions: 2, attempt: 4}});
    assert.equal(limitMax.detail, "The limit of 3 tries came from the worker's --tries. maxExceptions was 2.");

    const unknownLimit = terminalText({kind: 'attempt_limit', facts: {tries: null, tries_source: 'unknown', max_exceptions: null, attempt: 2}});
    assert.equal(unknownLimit.headline, 'Failed on attempt 2: the attempt limit was reached');
    assert.equal(unknownLimit.detail, 'The tries value is not known.');

    const other = terminalText({kind: 'max_attempts_other', facts: {deadline: DEADLINE, failed_at: AT, tries: 5, tries_source: 'payload', max_exceptions: 2, attempt: 3}});
    assert.equal(other.headline, 'Failed with MaxAttemptsExceededException on attempt 3');
    assert.deepEqual(other.conditions, [
        "Tries: 5 (from the job's payload)",
        `Retry deadline: ${DEADLINE_TEXT} (not passed at the failure)`,
        'maxExceptions: 2',
    ]);
    assert.match(other.detail, /these values do not show which one applied/);

    const manual = terminalText({kind: 'other', facts: {
        attempt: 2, tries: 5, tries_source: 'payload', deadline: DEADLINE, deadline_passed: false,
        max_exceptions: null, fail_on_timeout: false, timeout: 60, timeout_source: 'payload',
    }});
    assert.equal(manual.headline, 'Failed on attempt 2');
    assert.deepEqual(manual.conditions, [
        'At the failure time: attempt 2 of the tries value 5.',
        `At the failure time: the retry deadline ${DEADLINE_TEXT} had not passed.`,
        "Other limits: timeout 60 s (from the job's payload), fail on timeout off · maxExceptions not set",
    ]);
    assert.equal(manual.detail, 'These are the conditions at the failure, not its cause: a job can call fail() itself, and the exception handler can stop retries. They are the limits that Orrery knows, not a complete account of the failure policy.');

    const passed = terminalText({kind: 'other', facts: {attempt: 1, tries: null, tries_source: 'unknown', deadline: DEADLINE, deadline_passed: true, max_exceptions: null, fail_on_timeout: false, timeout: null, timeout_source: 'unknown'}});
    assert.deepEqual(passed.conditions.slice(0, 2), [
        'At the failure time: attempt 1; the tries value is not known.',
        `At the failure time: the retry deadline ${DEADLINE_TEXT} had passed.`,
    ]);

    const unlimited = terminalText({kind: 'other', facts: {attempt: 7, tries: 0, tries_source: 'worker', deadline: null, deadline_passed: null, max_exceptions: null, fail_on_timeout: false, timeout: null, timeout_source: 'unknown'}});
    assert.equal(unlimited.conditions[0], 'At the failure time: attempt 7; no attempt limit (tries 0).');
    assert.equal(unlimited.conditions.length, 2, 'no deadline line without a deadline');

    for (const result of [timeout, deadline, limit, other, manual, passed, unlimited]) {
        const text = [result.headline, result.detail, ...(result.conditions || [])].join(' ');
        assert.doesNotMatch(text, /root cause/i);
        assert.doesNotMatch(text, /\bcaused by\b|\bbecause\b/i);
    }

    assert.equal(terminalText(null), null);
    assert.equal(terminalText({kind: 'new_kind', facts: {}}).headline, 'Failed');
});

// Rejects: a signal shown by its code, a gap without "at least", an "attempts not capped" text that ignores the deadline rule, and an expired reservation without its time.
test('signalText fills the Spec E wording of each stuck signal', () => {
    assert.equal(
        signalText({signal: 'reservation_expired', facts: {lease_expires_at: AT, retry_after: 90}}),
        `Reservation expired at ${AT_TEXT}: Laravel can give this job to another worker, so it can run twice.`,
    );
    assert.equal(
        signalText({signal: 'outcome_not_observed', facts: {gap: 2}}),
        'At least 2 attempts have no observed outcome so far (for example a timeout without PCNTL, a killed worker, or a monitoring outage).',
    );
    assert.match(signalText({signal: 'outcome_not_observed', facts: {gap: 1}}), /^At least 1 attempt has no observed outcome/);
    assert.equal(signalText({signal: 'repeated_releases', facts: {releases: 21}}), 'Released 21 times in this cycle.');
    assert.equal(signalText({signal: 'attempts_not_capped', facts: {attempts: 21, rule: 'none'}}), '21 attempts and no attempt limit.');
    assert.equal(
        signalText({signal: 'attempts_not_capped', facts: {attempts: 21, rule: 'deadline', deadline: DEADLINE}}),
        `21 attempts. Retry deadline ${DEADLINE_TEXT}: Laravel does not enforce the attempt count until then.`,
    );
    assert.equal(signalText({signal: 'something_new', facts: {}}), 'Signal: something_new');
    assert.equal(signalText(null), null);
});

// Rejects: a worker without a heartbeat read as live, a null worker read as idle, a silent worker without its time, and an expired lease read as live.
test('livenessText states the worker and its lease as facts', () => {
    const now = AT + 240;

    assert.deepEqual(livenessText({id: 'host:9120:1', heartbeat_at: AT, liveness: 'live', silent_since: null}, AT + 300, now), {
        worker: 'host:9120:1', label: 'live', tone: 'success', lease: 'lease until 09:33:44',
    });
    assert.deepEqual(livenessText({id: 'host:9120:1', heartbeat_at: AT - 600, liveness: 'silent', silent_since: AT}, AT, now), {
        worker: 'host:9120:1', label: 'silent since 09:28:44', tone: 'warning', lease: 'lease expired 4 min ago',
    });
    assert.deepEqual(livenessText({id: 'host:1:1', heartbeat_at: null, liveness: 'unknown', silent_since: null}, null, now), {
        worker: 'host:1:1', label: 'liveness unknown', tone: 'unknown', lease: 'lease unknown',
    });
    assert.deepEqual(livenessText({id: null, heartbeat_at: null, liveness: 'unknown', silent_since: null}, AT, now), {
        worker: null, label: 'a worker that Orrery could not verify', tone: 'unknown', lease: 'lease expired 4 min ago',
    });
    assert.deepEqual(livenessText(null, null, now), {worker: null, label: 'worker unknown', tone: 'unknown', lease: 'lease unknown'});
});

// Rejects: a bare time of day for a silent worker or a lease of an earlier day ("silent since 09:28:44" two
// days later reads as today's), and a date on a time of today.
test('livenessText dates a silent time and a lease of another day', () => {
    const now = AT + 2 * 86400;

    assert.equal(livenessText({id: 'h:1:1', liveness: 'silent', silent_since: AT}, AT + 3 * 86400, now).label, 'silent since 2026-11-08 09:28:44');
    assert.equal(livenessText({id: 'h:1:1', liveness: 'live'}, AT + 3 * 86400, now).lease, 'lease until 2026-11-11 09:28:44');
    assert.equal(livenessText({id: 'h:1:1', liveness: 'silent', silent_since: now - 60}, now + 60, now).label, 'silent since 09:27:44');
});

// Rejects: "No stuck signal holds now." beside "lease unknown" (whether the reservation expired cannot be
// evaluated without the lease), and the same claim when the signals were not read at all.
test('signalsEmptyText says when the signals could not be evaluated', () => {
    assert.equal(signalsEmptyText('pending', null, []), 'No stuck signal holds now.');
    assert.equal(signalsEmptyText('reserved', AT, []), 'No stuck signal holds now.');
    assert.equal(signalsEmptyText('reserved', null, []),
        'No other stuck signal holds now. Whether the reservation expired cannot be evaluated: the lease is unknown.');
    assert.equal(signalsEmptyText('reserved', AT, null), 'The stuck signals could not be evaluated.');
});
