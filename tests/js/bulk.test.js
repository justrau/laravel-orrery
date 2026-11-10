// Tests of resources/js/support/bulk.js: the client rules of a bulk run
// (5.2 "The bulk run sheet").

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    BULK_OUTCOMES,
    FREEZE_NOTE,
    UNCERTAIN_NOTE,
    applyPage,
    basePathClient,
    latestRecovered,
    needsRefreeze,
    openAction,
    recoveryKeys,
    bulkBlocker,
    canFreeze,
    confirmationCount,
    confirmationNote,
    endPhase,
    isSheetPhase,
    sourceKey,
    storageKeyFor,
    confirmLabel,
    freezeFailure,
    frozenMessage,
    frozenTitle,
    recordLines,
    recoveredActions,
    recoveredHeadline,
    refusedLines,
    remainingText,
    resultPage,
    runFailure,
    runHeadline,
    runLabel,
    storedRun,
    storedRunIs,
    storedValue,
    totalsText,
    forgetBlocker,
    freezeBody,
    freezeInput,
    listSource,
    parseConfirmation,
    runBody,
    shouldRunNextPage,
    sourceSummary,
    startRun,
    recentRunsOf,
} from '../../resources/js/support/bulk.js';

/** A freeze answer as the server sends it: the token is opaque. */
function freezeAnswer(overrides = {}) {
    return {
        frozen: {
            token: 'eyJhY3Rpb24iOiJmb3JnZXQiLCJmcm96ZW5fYXQiOjE3NjI2MDAwMDAuMH0.c2lnbmF0dXJl',
            ref: '0123456789abcdef0123456789abcdef',
            action: 'forget',
            source: {kind: 'batch', batch_id: 'b-1'},
            source_hash: 'h',
            max_occurrence: 991,
            frozen_at: 1762600000.0,
            total: {count: 450, at_least: false},
            exact: true,
            ...overrides,
        },
        held: {count: 2, at_least: false},
        preview: [],
    };
}

// Rejects: a freeze body with an unknown action or source kind, a groups source without keys or with more than 200, and a list source that sends a status or an empty filter.
test('freezeBody builds the freeze request of each source kind', () => {
    assert.deepEqual(freezeBody('retry', {kind: 'groups', queue: 'db:emails', from: 1, to: 2, keys: ['k1', 'k2']}), {
        action: 'retry', source: {kind: 'groups', queue: 'db:emails', from: 1, to: 2, keys: ['k1', 'k2']},
    });
    assert.deepEqual(freezeBody('forget', {kind: 'batch', batch_id: 'b-1'}), {action: 'forget', source: {kind: 'batch', batch_id: 'b-1'}});
    assert.deepEqual(freezeBody('retry', {kind: 'groups', from: 1, to: 2, keys: ['k']}).source.queue, null);

    assert.throws(() => freezeBody('delete', {kind: 'batch', batch_id: 'b'}), /action/);
    assert.throws(() => freezeBody('retry', {kind: 'everything'}), /source/);
    assert.throws(() => freezeBody('retry', {kind: 'groups', from: 1, to: 2, keys: []}), /keys/);
    assert.throws(() => freezeBody('retry', {kind: 'groups', from: 1, to: 2, keys: Array.from({length: 201}, (_, i) => `k${i}`)}), /keys/);
    assert.throws(() => freezeBody('retry', {kind: 'batch', batch_id: ''}), /batch/);
    assert.throws(() => freezeBody('retry', null), /source/);
});

// Rejects: a list source that carries the tab or the order (the run acts on failed and held members only), or sends empty strings instead of null.
test('listSource takes the filters of the failed tab', () => {
    assert.deepEqual(listSource({status: 'failed', connection: 'db', queue: 'q', class: '', tag: 't', from: 5, to: null, q: '', order: 'asc'}), {
        kind: 'list', connection: 'db', queue: 'q', class: null, tag: 't', from: 5, to: null,
    });
    assert.deepEqual(listSource({}), {kind: 'list', connection: null, queue: null, class: null, tag: null, from: null, to: null});
});

// Rejects: a list source that drops the search text, so "Retry all matching" would act on every failed job of the filters instead of the ones the search shows.
test('listSource refuses a search text', () => {
    assert.throws(() => listSource({status: 'failed', q: 'abc'}), error => error.code === 'search_not_in_bulk');
    assert.doesNotThrow(() => listSource({status: 'failed', q: null}));
});

// Rejects: a freeze that is sent while the source cannot be a bulk run, and a blocker without the wording of its code.
test('bulkBlocker says why a source cannot start a run', () => {
    assert.deepEqual(bulkBlocker('retry', {kind: 'list', q: 'abc'}), {
        code: 'search_not_in_bulk', text: 'Clear the search to act on all matching jobs: the search is not part of a bulk run.',
    });
    assert.equal(bulkBlocker('retry', {kind: 'list', connection: 'db'}), null);
    assert.equal(bulkBlocker('retry', {kind: 'batch', batch_id: ''}).code, null);
    assert.match(bulkBlocker('retry', {kind: 'batch', batch_id: ''}).text, /batch/);
});

// Rejects: a list source that drops the batch filter, so a run would act on every failed job instead of the batch's members.
test('listSource refuses a batch filter', () => {
    assert.throws(() => listSource({status: 'failed', batch: 'b-1'}), /batch/);
    assert.doesNotThrow(() => listSource({status: 'failed', batch: null}));
});

// Rejects: a client that rebuilds the token from parsed fields (a JSON round trip turns 1762600000.0 into 1762600000 and breaks the signature), a retry that sends a confirmation, and a missing token.
test('runBody echoes the token verbatim after a JSON round trip of the answer', () => {
    const answer = JSON.parse(JSON.stringify(freezeAnswer()));
    const token = freezeAnswer().frozen.token;

    assert.deepEqual(runBody(answer.frozen, null, 450), {token, after: null, confirmed_total: 450});
    assert.deepEqual(runBody(answer.frozen, 'cursor-2', 450), {token, after: 'cursor-2', confirmed_total: 450});
    assert.deepEqual(runBody({...answer.frozen, action: 'retry'}, undefined, 450), {token, after: null, confirmed_total: null});
    assert.throws(() => runBody({action: 'retry'}, null, null), /token/);
});

// Rejects: a confirmation that accepts a partial number, a negative or a fraction, and one that refuses thousands separators.
test('parseConfirmation reads the typed total', () => {
    assert.equal(parseConfirmation('450'), 450);
    assert.equal(parseConfirmation(' 10,000 '), 10000);
    assert.equal(parseConfirmation('10 000'), 10000);
    assert.equal(parseConfirmation('0'), 0);
    assert.equal(parseConfirmation('1 000 000'), 1000000);
    for (const bad of ['', '4a50', '-3', '4.5', '1,00', 'abc', null, undefined, 450, '0450', '00', '10,000 000', '1 000,000', ',450']) {
        assert.equal(parseConfirmation(bad), null, String(bad));
    }
});

// Rejects: a forget that starts on a capped or inexact total, or with a typed number that differs from the frozen total; and a retry that needs a typed total.
test('forgetBlocker names why a forget cannot start', () => {
    const exact = freezeAnswer().frozen;

    assert.equal(forgetBlocker(exact, '450'), null);
    assert.equal(forgetBlocker(exact, '449'), 'confirmation_mismatch');
    assert.equal(forgetBlocker(exact, ''), 'confirmation_mismatch');
    assert.equal(forgetBlocker({...exact, exact: false}, '450'), 'total_not_exact');
    assert.equal(forgetBlocker({...exact, total: {count: 450, at_least: true}}, '450'), 'total_not_exact');
    assert.equal(forgetBlocker({...exact, total: {count: null, at_least: false}}, ''), 'total_not_exact');
    assert.equal(forgetBlocker({...exact, action: 'retry'}, ''), null);
});

// Rejects: totals that start from anything but zero, page totals that are added wrongly, a missing page total counted as zero (unknown is never zero), and results of earlier pages that are lost.
test('applyPage adds each page to the run', () => {
    let run = startRun(freezeAnswer().frozen);

    assert.deepEqual(BULK_OUTCOMES, ['accepted', 'refused', 'uncertain', 'not_member', 'already_done', 'not_confirmed']);
    assert.deepEqual(run.totals, {accepted: 0, refused: 0, uncertain: 0, not_member: 0, already_done: 0, not_confirmed: 0});
    assert.equal(run.pages, 0);
    assert.equal(run.after, null);
    assert.equal(run.done, false);

    run = applyPage(run, {
        results: [{id: 'a', outcome: 'accepted'}, {id: 'b', outcome: 'refused', code: 'provider_read_only'}],
        totals: {accepted: 1, refused: 1, uncertain: 0, not_member: 0, already_done: 0, not_confirmed: 0},
        refused_reasons: {provider_read_only: 1},
        next_after: 'c1', done: false, remaining: {count: 448, at_least: false},
    });
    run = applyPage(run, {
        results: [{id: 'c', outcome: 'refused', code: 'provider_read_only'}],
        totals: {accepted: 0, refused: 1, uncertain: 0, not_member: 0, already_done: 0},
        refused_reasons: {provider_read_only: 1, fence_missing: 2, broken: '3', odd: null, worse: Infinity},
        next_after: null, done: true, remaining: {count: 0, at_least: false},
    });

    assert.equal(run.pages, 2);
    assert.deepEqual(run.totals, {accepted: 1, refused: 2, uncertain: 0, not_member: 0, already_done: 0, not_confirmed: null});
    assert.deepEqual(run.refusedReasons, {provider_read_only: 2, fence_missing: 2});
    assert.deepEqual(run.results.map(item => item.id), ['a', 'b', 'c']);
    assert.equal(run.after, null);
    assert.equal(run.done, true);
    assert.deepEqual(run.remaining, {count: 0, at_least: false});
    assert.equal(run.ref, '0123456789abcdef0123456789abcdef');
});

// Rejects: a runner that goes on after done or a Stop, and one that starts again from the beginning when a page ends without a cursor and without done.
test('shouldRunNextPage stops on done, on Stop and without a cursor', () => {
    const run = startRun(freezeAnswer().frozen);

    assert.equal(shouldRunNextPage(run, false), true, 'the first page');
    assert.equal(shouldRunNextPage(run, true), false);
    assert.equal(shouldRunNextPage({...run, pages: 1, after: 'c'}, false), true);
    assert.equal(shouldRunNextPage({...run, pages: 1, after: null}, false), false);
    assert.equal(shouldRunNextPage({...run, pages: 1, after: 'c', done: true}, false), false);
});

// Rejects: a source shown without its scope, and an unknown source shown as nothing.
test('sourceSummary words the source of a run', () => {
    assert.equal(sourceSummary({kind: 'groups', queue: 'db:emails', keys: ['a', 'b', 'c']}), '3 failure groups on “db:emails”');
    assert.equal(sourceSummary({kind: 'groups', queue: null, keys: ['a']}), '1 failure group on every queue');
    assert.equal(sourceSummary({kind: 'list'}), 'the failed jobs on every queue');
    assert.equal(sourceSummary({kind: 'list', connection: 'db', queue: 'emails'}), 'the failed jobs on “db:emails”');
    assert.equal(sourceSummary({kind: 'list', connection: 'db', queue: null}), 'the failed jobs on every queue of “db”');
    assert.equal(sourceSummary({kind: 'list', class: 'App\\Jobs\\A'}), 'the failed jobs on every queue, class “App\\Jobs\\A”');
    assert.equal(sourceSummary({kind: 'list', tag: 'order:1'}), 'the failed jobs on every queue, tag “order:1”');
    assert.equal(sourceSummary({kind: 'list', from: 1762592400, to: 1762678800}), 'the failed jobs on every queue, from 2025-11-08 09:00:00 UTC to 2025-11-09 09:00:00 UTC');
    assert.equal(sourceSummary({kind: 'list', from: 1762592400}), 'the failed jobs on every queue, since 2025-11-08 09:00:00 UTC');
    assert.equal(sourceSummary({kind: 'list', to: 1762678800}), 'the failed jobs on every queue, until 2025-11-09 09:00:00 UTC');
    assert.equal(sourceSummary({kind: 'groups', queue: 'db:emails', keys: ['a'], from: 1762592400, to: 1762678800}), '1 failure group on “db:emails”, from 2025-11-08 09:00:00 UTC to 2025-11-09 09:00:00 UTC');
    assert.equal(sourceSummary({kind: 'batch', batch_id: 'b-1'}), 'the failed members of batch “b-1”');
    assert.equal(sourceSummary({kind: 'list', connection: 'db', queue: 'emails '}), 'the failed jobs on “db:emails ”');
    assert.equal(sourceSummary(null), 'an unknown selection');
    assert.equal(FREEZE_NOTE, 'Failures that happen after this moment are not included.');
});

/** A client error (support/http.js) with an HTTP answer. */
function httpError(status, data) {
    return {response: {status, data}, config: {method: 'post'}};
}

// Rejects: a title without "at least" for a capped total, "1 jobs", and a forget title that reads like a retry.
test('frozenTitle and confirmLabel name the action and the frozen total', () => {
    assert.equal(frozenTitle(freezeAnswer().frozen), 'Forget 450 failed jobs?');
    assert.equal(frozenTitle(freezeAnswer({action: 'retry', total: {count: 1, at_least: false}}).frozen), 'Retry 1 failed job?');
    assert.equal(frozenTitle(freezeAnswer({action: 'retry', total: {count: 10000, at_least: true}}).frozen), 'Retry at least 10,000 failed jobs?');
    assert.equal(confirmLabel(freezeAnswer().frozen), 'Forget 450 jobs');
    assert.equal(confirmLabel(freezeAnswer({action: 'retry', total: {count: 1, at_least: false}}).frozen), 'Retry 1 job');
    assert.equal(confirmLabel(freezeAnswer({action: 'retry', total: {count: null, at_least: false}}).frozen), 'Retry — jobs');
});

// Rejects: a title that calls every member "failed" when some are being retried now (60 = 59 failed + 1 held),
// a title without "failed" when the split is not exact, and a typed count other than the frozen total.
test('frozenTitle drops "failed" when the total includes held members', () => {
    const answer = {...freezeAnswer({total: {count: 60, at_least: false}}), held: {count: 1, at_least: false}};

    assert.equal(frozenTitle(answer.frozen, answer.held), 'Forget 60 jobs?');
    assert.equal(frozenTitle(answer.frozen, {count: 0, at_least: false}), 'Forget 60 failed jobs?');
    assert.equal(frozenTitle(answer.frozen, {count: 1, at_least: true}), 'Forget 60 failed jobs?');
    assert.equal(frozenTitle(answer.frozen, null), 'Forget 60 failed jobs?');
    assert.equal(confirmLabel(answer.frozen), 'Forget 60 jobs');
    assert.equal(confirmationCount(answer.frozen), '60');
});

// Rejects: a forget sheet that does not say the records are deleted for good, a held count that is not mentioned (they are skipped), and an unknown held count shown as 0.
test('frozenMessage says what the run does, in words', () => {
    assert.equal(
        frozenMessage(freezeAnswer()),
        'All the failed members of batch “b-1”. Their failed-job records are deleted. This cannot be undone. 450 jobs match: 448 failed and 2 being retried now. The 2 being retried are skipped while their retries run and end “uncertain”; if a retry stops before the run reaches its job, that job is forgotten like the others.',
    );
    assert.equal(
        frozenMessage({...freezeAnswer({action: 'retry'}), held: {count: 0, at_least: false}}),
        'All the failed members of batch “b-1”. Each one goes back on its queue as a new attempt.',
    );
    assert.match(frozenMessage({...freezeAnswer({action: 'retry'}), held: {count: 3, at_least: true}}), /At least 3 of them are being retried now and are skipped\.$/);
    assert.match(frozenMessage({...freezeAnswer({total: {count: 60, at_least: false}}), held: {count: 1, at_least: false}}),
        / 60 jobs match: 59 failed and 1 being retried now\. The 1 being retried is skipped while its retry runs and ends “uncertain”; if that retry stops before the run reaches it, it is forgotten like the others\.$/);
    assert.match(frozenMessage({...freezeAnswer({action: 'retry', total: {count: 60, at_least: false}}), held: {count: 1, at_least: false}}),
        /if that retry stops before the run reaches it, it is retried like the others\.$/);
    assert.match(frozenMessage({...freezeAnswer({action: 'retry', total: {count: 1000, at_least: true}}), held: {count: 1, at_least: false}}),
        / 1 of them is being retried now and is skipped\.$/);
    assert.match(frozenMessage({...freezeAnswer({action: 'retry'}), held: null}), /Whether some of them are being retried now is not known\./);
});

// Rejects: a refusal of the freeze that is shown as a failure to retry (nothing was frozen; the sheet offers only a way back), a maximum taken from a constant instead of the response, and a refusal text that is not the wording table's.
test('freezeFailure tells a refused freeze from a failed one', () => {
    assert.deepEqual(freezeFailure(httpError(422, {error: 'too_many', maximum: 2500})), {
        blocked: true, code: 'too_many', text: 'More than 2,500 records match, so a forget cannot start. Narrow the selection.',
    });
    assert.equal(freezeFailure(httpError(422, {error: 'total_not_exact'})).blocked, true);
    assert.equal(freezeFailure(httpError(422, {error: 'app_key_missing'})).blocked, true);
    assert.equal(freezeFailure(httpError(422, {error: 'config_invalid'})).blocked, true);
    assert.equal(freezeFailure(httpError(422, {error: 'invalid', field: 'source'})).blocked, true);
    assert.equal(freezeFailure(httpError(500, {error: 'redaction_config_invalid'})).blocked, true);
    assert.equal(freezeFailure(httpError(503, {error: 'schema_outdated'})).blocked, true);
    assert.deepEqual(freezeFailure(httpError(503, {error: 'read_timeout'})), {blocked: false, code: 'read_timeout', text: 'A read took too long. Narrow the selection and try again.'});
    assert.deepEqual(freezeFailure(httpError(500, 'oops')), {blocked: false, code: null, text: 'The request failed (HTTP 500). Nothing was frozen.'});
    assert.deepEqual(freezeFailure({code: 'ERR_NETWORK'}), {blocked: false, code: null, text: 'The server did not answer. Nothing was frozen; try again.'});
});

// Rejects: a run error shown without its operator text, and a lost answer reported as "nothing happened" (the page may have run).
test('runFailure gives the operator text of a stopped run', () => {
    assert.deepEqual(runFailure(httpError(422, {error: 'frozen_expired'})), {code: 'frozen_expired', unknown: false, text: 'This run was idle for more than an hour; start a new one.'});
    assert.equal(runFailure(httpError(422, {error: 'frozen_mismatch'})).code, 'frozen_mismatch');
    assert.deepEqual(runFailure({code: 'ECONNABORTED', message: 'aborted'}), {
        code: null, unknown: true, text: 'The server did not answer. This page may have run; the counts cover the pages that answered. Check the run record before you start a new run.',
    });
    assert.deepEqual(runFailure(httpError(500, {})), {
        code: null, unknown: true, text: 'The server answered with an error (HTTP 500). This page may have run; the counts cover the pages that answered. Check the run record before you start a new run.',
    });
    assert.deepEqual(runFailure(httpError(503, {error: 'read_failed'})), {
        code: 'read_failed', unknown: true, text: "Orrery's storage could not be read. This page may have run; the counts cover the pages that answered. Check the run record before you start a new run.",
    });
});

// Rejects: an unknown total shown as 0, and totals without their outcome names.
test('totalsText lists every outcome, unknown as —', () => {
    const run = startRun(freezeAnswer().frozen);

    assert.equal(totalsText(run.totals), '0 accepted · 0 refused · 0 uncertain · 0 outside the selection · 0 already done · 0 not confirmed');
    assert.equal(totalsText({...run.totals, accepted: 16, refused: null}), '16 accepted · — refused · 0 uncertain · 0 outside the selection · 0 already done · 0 not confirmed');
    assert.equal(totalsText(null), '— accepted · — refused · — uncertain · — outside the selection · — already done · — not confirmed');
});

// Rejects: a capped remaining count without "at least", and an unknown remaining count shown as 0.
test('remainingText keeps at least and unknown', () => {
    assert.equal(remainingText({count: 5, at_least: false}), '5 remaining');
    assert.equal(remainingText({count: 10000, at_least: true}), 'at least 10,000 remaining');
    assert.equal(remainingText(null), 'remaining unknown');
    assert.equal(remainingText({count: null, at_least: false}), 'remaining unknown');
});

// Rejects: a finished run that claims all members accepted while a count is unknown, a stopped run that says it finished, and a running run without its page.
test('runHeadline says how the run stands', () => {
    const run = startRun(freezeAnswer({action: 'retry'}).frozen);
    const done = {...run, pages: 2, done: true, totals: {...run.totals, accepted: 21, refused: 1, uncertain: 1}};

    assert.equal(runHeadline(done), 'Retry finished: 21 of 23 accepted');
    assert.equal(runHeadline({...done, totals: {...done.totals, refused: null}}), 'Retry finished: 21 accepted; some counts are unknown');
    assert.equal(runHeadline({...run, pages: 1}), 'Retry running: page 2…');
    assert.equal(runHeadline({...run, pages: 1}, {stopped: true}), 'Retry stopped after page 1: nothing is undone.');
    assert.equal(runHeadline({...run, pages: 1}, {failed: true}), 'Retry stopped by an error after page 1: nothing is undone.');
    assert.equal(runHeadline({...startRun(freezeAnswer().frozen), pages: 1, done: true, totals: {...run.totals, accepted: 1}}), 'Forget finished: 1 of 1 accepted');
});

// Rejects: refused reasons without their wording, unsorted reasons, and a reason with a non-number count.
test('refusedLines gives each refused reason with its count and text', () => {
    assert.deepEqual(refusedLines({fence_missing: 1, occurrence_mismatch: 3}), [
        {code: 'occurrence_mismatch', count: 3, text: '3 × This failure was replaced by a newer one: reload the page and decide again.'},
        {code: 'fence_missing', count: 1, text: '1 × The fence table is missing. Run php artisan orrery:install --fence and php artisan migrate.'},
    ]);
    assert.deepEqual(refusedLines({x: 'many'}), []);
    assert.deepEqual(refusedLines(null), []);
    assert.match(UNCERTAIN_NOTE, /never retried as new work/);
});

// Rejects: a per-member table that shows every member at once, a page past the end, and a page count of 0 for an empty run.
test('resultPage pages the member results in the client', () => {
    const results = Array.from({length: 45}, (_, index) => ({id: `id-${index}`}));

    assert.deepEqual(resultPage(results, 0).rows.map(row => row.id).slice(0, 2), ['id-0', 'id-1']);
    assert.equal(resultPage(results, 0).rows.length, 20);
    assert.equal(resultPage(results, 2).rows.length, 5);
    assert.equal(resultPage(results, 9).page, 2);
    assert.equal(resultPage(results, -3).page, 0);
    assert.equal(resultPage(results, 0).pages, 3);
    assert.deepEqual(resultPage([], 0), {rows: [], page: 0, pages: 1});
});

// Rejects: a stored ref that is not a ref (a tampered localStorage value would reach a URL), a broken JSON that throws, and a stored state without its done flag.
test('storedRun reads only a valid stored run', () => {
    const ref = '0123456789abcdef0123456789abcdef';

    assert.deepEqual(storedRun(storedValue(ref, 'running')), {ref, done: false, end: 'running'});
    assert.deepEqual(storedRun(storedValue(ref, 'done')), {ref, done: true, end: 'done'});
    assert.deepEqual(storedRun(storedValue(ref, 'stopped')), {ref, done: false, end: 'stopped'});
    assert.deepEqual(storedRun(storedValue(ref, 'error')), {ref, done: false, end: 'error'});
    assert.deepEqual(storedRun(storedValue(ref, 'other')), {ref, done: false, end: 'running'});
    // A value stored before `end` existed.
    assert.deepEqual(storedRun(JSON.stringify({ref, done: true})), {ref, done: true, end: 'done'});
    assert.deepEqual(storedRun(JSON.stringify({ref, done: false})), {ref, done: false, end: 'running'});
    assert.equal(storedRun('{"ref":"../../etc","done":false}'), null);
    assert.equal(storedRun('not json'), null);
    assert.equal(storedRun(null), null);
    assert.equal(storedRun(undefined), null);
});

// Rejects: server counters shown as exact when they only sum the answered pages, ledger states that drop one state, and an absent value shown as 0.
test('recordLines gives the counted pages and the exact operation states', () => {
    const record = {
        counters: {accepted: 16, refused: 2, uncertain: 0, not_member: 0, already_done: 0, not_confirmed: 0},
        operations: {done: 15, published: 1, claimed: 0, aborting: 0, aborted: null},
        operations_complete: true,
    };

    assert.deepEqual(recordLines(record), {
        counters: '16 accepted · 2 refused · 0 uncertain · 0 outside the selection · 0 already done · 0 not confirmed',
        operations: '15 done · 1 published · 0 claimed · 0 aborting · — aborted',
        operationsTitle: 'Operations, exact',
    });
    assert.deepEqual(recordLines(null).operations, '— done · — published · — claimed · — aborting · — aborted');
});

// Rejects: a reloaded run that is said to have finished while it stopped, a total without "at least", and a run
// that the user stopped or that an error ended called "stopped when the page reloaded".
// Still passes: a run that ends because the user left the screen, which reads "stopped when the page reloaded".
test('recoveredHeadline tells how a run ended', () => {
    const record = {action: 'retry', total: {count: 23, at_least: false}};
    const ref = '0123456789abcdef0123456789abcdef';
    const stored = end => storedRun(storedValue(ref, end));

    assert.equal(recoveredHeadline(stored('running'), {...record, actor: 'ops@acme.test'}), 'The last run in this browser: a retry of 23 failed members by ops@acme.test, stopped when the page reloaded');
    assert.equal(recoveredHeadline(stored('done'), {...record, actor: 'cli'}), 'The last run in this browser: a retry of 23 failed members by cli');
    assert.equal(recoveredHeadline(stored('stopped'), {...record, actor: 'cli'}), 'The last run in this browser: a retry of 23 failed members by cli, stopped before its last page');
    assert.equal(recoveredHeadline(stored('error'), {...record, actor: 'cli'}), 'The last run in this browser: a retry of 23 failed members by cli, stopped by an error');
    assert.equal(recoveredHeadline({done: false}, {action: 'forget', total: {count: 10000, at_least: true}, actor: null}), 'The last run in this browser: a forget of at least 10,000 failed members by an unknown actor, stopped when the page reloaded');
    assert.equal(recoveredHeadline({done: true}, record), 'The last run in this browser: a retry of 23 failed members by an unknown actor');
});

// Rejects: a recent run listed without its action or its source in words.
test('runLabel names a recent run', () => {
    assert.equal(runLabel({action: 'retry', source: {kind: 'batch', batch_id: 'b-1'}}), 'Retry · the failed members of batch “b-1”');
    assert.equal(runLabel({action: 'forget', source: null}), 'Forget · an unknown selection');
});

// Rejects: a typed-count hint that shows "null" or "undefined" when the frozen total is unknown.
test('confirmationCount gives the number to type, or —', () => {
    assert.equal(confirmationCount(freezeAnswer().frozen), '450');
    assert.equal(confirmationCount(freezeAnswer({total: {count: null, at_least: false}}).frozen), '—');
    assert.equal(confirmationCount(null), '—');
});

// Rejects: one stored run shared by every bulk host (a batch screen would recover a run of the failure groups), and a key without the action.
test('storageKeyFor keeps one stored run per action and source kind', () => {
    assert.equal(storageKeyFor('retry', {kind: 'batch', batch_id: 'b'}), 'orreryBulkRun:retry:batch');
    assert.equal(storageKeyFor('forget', {kind: 'list'}), 'orreryBulkRun:forget:list');
    assert.equal(storageKeyFor('retry', null), 'orreryBulkRun:retry:unknown');
});

// Rejects: a sheet that keeps a freeze of an older source (the screen's filters changed under the open sheet), and a key that sees no change when only the search text changes.
test('sourceKey changes with every part of the freeze', () => {
    const base = sourceKey('retry', {kind: 'list', connection: 'db', queue: 'q'});

    assert.equal(base, sourceKey('retry', {kind: 'list', connection: 'db', queue: 'q'}));
    assert.notEqual(base, sourceKey('retry', {kind: 'list', connection: 'db', queue: 'q2'}));
    assert.notEqual(base, sourceKey('forget', {kind: 'list', connection: 'db', queue: 'q'}));
    assert.notEqual(base, sourceKey('retry', {kind: 'list', connection: 'db', queue: 'q', q: 'abc'}));
    assert.match(sourceKey('retry', {kind: 'list', q: 'abc'}), /^blocked:search_not_in_bulk/);
});

// Rejects: a freeze that starts during a run (two runs on one host), a freeze for a viewer, and a run that ends as "done" after a Stop.
test('the phase rules of a run', () => {
    assert.equal(canFreeze('idle', true), true);
    assert.equal(canFreeze('done', true), true);
    assert.equal(canFreeze('running', true), false);
    assert.equal(canFreeze('idle', false), false);

    assert.equal(isSheetPhase('confirm'), true);
    assert.equal(isSheetPhase('freezing'), true);
    assert.equal(isSheetPhase('running'), false);

    assert.equal(endPhase({done: true}), 'done');
    assert.equal(endPhase({done: false}), 'stopped');
});

// Rejects: a recovery that reads only the key of the live action (a forget run is lost on a retry host), and keys of another source kind.
test('recoveryKeys reads the stored runs of both actions of one source kind', () => {
    assert.deepEqual(recoveryKeys('batch'), ['orreryBulkRun:retry:batch', 'orreryBulkRun:forget:batch']);
    assert.deepEqual(recoveryKeys(null), ['orreryBulkRun:retry:unknown', 'orreryBulkRun:forget:unknown']);
});

// Rejects: a recovered run that is not the newest of the stored ones, and an entry without a record that wins.
test('latestRecovered picks the run that ran last', () => {
    const older = {key: 'a', stored: {ref: 'a'}, record: {last_run_at: 100}};
    const newer = {key: 'b', stored: {ref: 'b'}, record: {last_run_at: 200}};
    const unread = {key: 'c', stored: {ref: 'c'}, record: null};

    assert.equal(latestRecovered([older, newer]).key, 'b');
    assert.equal(latestRecovered([newer, older]).key, 'b');
    assert.equal(latestRecovered([unread, older]).key, 'a');
    assert.equal(latestRecovered([unread]).key, 'c');
    assert.equal(latestRecovered([]), null);
});

// Rejects: a freeze on a polled change of the source (only a new selection key counts), a second freeze for a key already frozen (open and the source changed in one render), and a freeze outside the sheet.
test('needsRefreeze freezes again only for a new selection while the sheet is open', () => {
    assert.equal(needsRefreeze({open: true, phase: 'confirm', key: 2, frozenFor: 1}), true);
    assert.equal(needsRefreeze({open: true, phase: 'freezing', key: 2, frozenFor: 1}), true);
    assert.equal(needsRefreeze({open: true, phase: 'confirm', key: 1, frozenFor: 1}), false);
    assert.equal(needsRefreeze({open: false, phase: 'confirm', key: 2, frozenFor: 1}), false);
    assert.equal(needsRefreeze({open: true, phase: 'running', key: 2, frozenFor: 1}), false);
    assert.equal(needsRefreeze({open: true, phase: 'idle', key: 2, frozenFor: 1}), false);
});

// Rejects: an open request during a run that starts a second freeze, or that is left open (the host's button would stay pressed with nothing shown).
test('openAction refuses an open request during a run or without the operate ability', () => {
    assert.equal(openAction('idle', true), 'freeze');
    assert.equal(openAction('done', true), 'freeze');
    assert.equal(openAction('running', true), 'refuse');
    assert.equal(openAction('idle', false), 'refuse');
});

// Rejects: a "Try again" after a failed freeze that reads the live props (a poll or a route change of the host
// would then freeze another selection than the one the operator asked for), a first freeze that reuses an
// older snapshot, and a snapshot that shares its object with the host's prop.
test('freezeInput reuses the frozen snapshot for a retry and copies the live props otherwise', () => {
    const live = {action: 'forget', source: {kind: 'list', connection: 'db', queue: 'b'}, selectionKey: 'k2'};
    const frozen = {frozenAction: 'retry', frozenSource: {kind: 'list', connection: 'db', queue: 'a'}, frozenFor: 'k1'};

    const again = freezeInput({reuse: true, ...live, ...frozen});

    assert.deepEqual(again, {action: 'retry', source: {kind: 'list', connection: 'db', queue: 'a'}, key: 'k1'});
    assert.notEqual(again.source, frozen.frozenSource);

    const first = freezeInput({reuse: false, ...live, ...frozen});

    assert.deepEqual(first, {action: 'forget', source: {kind: 'list', connection: 'db', queue: 'b'}, key: 'k2'});
    assert.notEqual(first.source, live.source);

    // Without a snapshot yet, a retry falls back to the live props.
    assert.deepEqual(freezeInput({reuse: true, ...live, frozenAction: null, frozenSource: null, frozenFor: null}).action, 'forget');
    assert.equal(freezeInput({reuse: false, action: 'retry', source: null, selectionKey: null}).source, null);
});

// Rejects: a BulkRun client that posts to the host's root (outside the dashboard path), and one that drops the request config.
test('basePathClient adds the dashboard path to every request', async () => {
    const calls = [];
    const http = {
        get: (url, config) => { calls.push(['get', url, config]); return Promise.resolve({data: 1}); },
        post: (url, body, config) => { calls.push(['post', url, body, config]); return Promise.resolve({data: 2}); },
    };
    const client = basePathClient(http, '/orrery');

    assert.deepEqual(await client.get('/api/bulk', {handles: [1]}), {data: 1});
    assert.deepEqual(await client.post('/api/bulk/freeze', {a: 1}, {handles: [2]}), {data: 2});
    assert.deepEqual(calls, [['get', '/orrery/api/bulk', {handles: [1]}], ['post', '/orrery/api/bulk/freeze', {a: 1}, {handles: [2]}]]);
    assert.equal(basePathClient(http, undefined).get('/api/bulk') instanceof Promise, true);
    assert.equal(calls[2][1], '/api/bulk');
});

// The recovery panel listed the recent runs of every source. Rejects: a batch run in the failure
// groups' list, a run without a source shown everywhere, and a list for a host whose kind is unknown.
test('recentRunsOf keeps the recent runs of the host source kind', () => {
    const runs = [
        {ref: 'a', source: {kind: 'groups', keys: ['k']}},
        {ref: 'b', source: {kind: 'batch', batch_id: 'x'}},
        {ref: 'c', source: null},
        null,
        {ref: 'd', source: {kind: 'list'}},
    ];

    assert.deepEqual(recentRunsOf(runs, 'groups').map(run => run.ref), ['a']);
    assert.deepEqual(recentRunsOf(runs, 'batch').map(run => run.ref), ['b']);
    assert.deepEqual(recentRunsOf(runs, 'list').map(run => run.ref), ['d']);
    assert.deepEqual(recentRunsOf(runs, null), []);
    assert.deepEqual(recentRunsOf(null, 'groups'), []);
});

// Review fix 1. Rejects: "All 1 failure group" (the singular reads wrong), a sheet that names no group when the
// host knows its class and message, and a long message shown whole.
test('the groups sheet names the group and reads in the singular', () => {
    const one = {kind: 'groups', queue: null, keys: ['a'], names: [{exception_class: 'App\\Exceptions\\TransportException', message: 'Connection timed out\nat line 2'}]};
    const three = {...one, queue: 'db:emails', keys: ['a', 'b', 'c']};

    assert.equal(sourceSummary(one), 'the failure group “TransportException: Connection timed out” on every queue');
    assert.equal(sourceSummary(three), '3 failure groups, “TransportException: Connection timed out” and 2 more, on “db:emails”');
    assert.equal(sourceSummary({kind: 'groups', queue: null, keys: ['a'], names: [{exception_class: 'E', message: 'x'.repeat(120)}]}),
        `the failure group “E: ${'x'.repeat(79)}…” on every queue`);
    assert.equal(sourceSummary({kind: 'groups', queue: null, keys: ['a', 'b']}), '2 failure groups on every queue');

    const answer = {frozen: {action: 'retry', source: {kind: 'groups', queue: null, keys: ['a']}, total: {count: 2, at_least: false}}, held: {count: 0, at_least: false}};

    assert.equal(frozenMessage(answer), 'Every failed job of 1 failure group on every queue. Each one goes back on its queue as a new attempt.');
    assert.equal(frozenMessage(answer, one), 'Every failed job of the failure group “TransportException: Connection timed out” on every queue. Each one goes back on its queue as a new attempt.');
    assert.doesNotMatch(frozenMessage(answer), /All 1/);
    assert.deepEqual(freezeBody('retry', one).source, {kind: 'groups', queue: null, from: undefined, to: undefined, keys: ['a']});
});

// Review nit. Rejects: a batch screen that lists the runs of another batch.
test('recentRunsOf keeps only the runs of the host batch', () => {
    const runs = [{ref: 'a', source: {kind: 'batch', batch_id: 'b-1'}}, {ref: 'b', source: {kind: 'batch', batch_id: 'b-2'}}];

    assert.deepEqual(recentRunsOf(runs, 'batch', {kind: 'batch', batch_id: 'b-2'}).map(run => run.ref), ['b']);
    assert.deepEqual(recentRunsOf(runs, 'batch').map(run => run.ref), ['a', 'b']);
});

// Rejects: a typed count that differs from the list's count without a word why (the list counts the failed
// jobs, the run also counts the held ones: spec 4.9), "1 failed jobs" or "1 being retried are", a note for
// a run without held members, and a split of a total that is not exact.
test('confirmationNote splits the count to type into failed and held members', () => {
    assert.equal(confirmationNote({...freezeAnswer({total: {count: 60, at_least: false}}), held: {count: 1, at_least: false}}),
        '60 is 59 failed jobs and 1 job being retried now (skipped while its retry runs).');
    assert.equal(confirmationNote({...freezeAnswer({total: {count: 2, at_least: false}}), held: {count: 1, at_least: false}}),
        '2 is 1 failed job and 1 job being retried now (skipped while its retry runs).');
    assert.equal(confirmationNote(freezeAnswer()), '450 is 448 failed jobs and 2 jobs being retried now (skipped while their retries run).');
    assert.equal(confirmationNote({...freezeAnswer(), held: {count: 0, at_least: false}}), null);
    assert.equal(confirmationNote({...freezeAnswer(), held: null}), null);
    assert.equal(confirmationNote({...freezeAnswer(), held: {count: 2, at_least: true}}), null);
    assert.equal(confirmationNote({...freezeAnswer({total: {count: 450, at_least: true}})}), null);
    assert.equal(confirmationNote(null), null);
});

// Rejects: operation states labelled "exact" after the ledger's retention may have deleted finished
// operations of the run (`operations_complete: false`), and "exact" for a record that does not say.
test('recordLines labels the operation states exact only while the ledger holds every operation', () => {
    const operations = {done: 15, published: 1, claimed: 0, aborting: 0, aborted: 2};
    const old = recordLines({operations, operations_complete: false});

    assert.equal(old.operationsTitle, 'Operations, at least');
    assert.equal(old.operations, '≥ 15 done · ≥ 1 published · ≥ 0 claimed · ≥ 0 aborting · ≥ 2 aborted');
    assert.equal(recordLines({operations}).operationsTitle, 'Operations');
    assert.equal(recordLines({operations}).operations, '15 done · 1 published · 0 claimed · 0 aborting · 2 aborted');
});

// Rejects: "Hide" on a recovered run that removes the stored ref of a newer run under the same key (a new run
// overwrote it), and a match on a broken stored value.
test('storedRunIs tells whether the stored ref is still the recovered run', () => {
    const ref = '0123456789abcdef0123456789abcdef';
    const other = 'fedcba9876543210fedcba9876543210';

    assert.equal(storedRunIs(storedValue(ref, 'done'), ref), true);
    assert.equal(storedRunIs(storedValue(other, 'running'), ref), false);
    assert.equal(storedRunIs('not json', ref), false);
    assert.equal(storedRunIs(null, ref), false);
});

// Rejects: "Start a new run…" offered for a recovered run whose record could not be read (the host then says the
// run "acted on failure groups or a batch" and the stored ref is dropped), and no way to read it again.
// Still passes: a record of the wrong shape inside an object (the headline reads it defensively).
test('recoveredActions offers a restart only for a read record', () => {
    const stored = {ref: '0123456789abcdef0123456789abcdef', done: false, end: 'running'};

    assert.deepEqual(recoveredActions({key: 'k', stored, record: {action: 'retry', source: {kind: 'list'}}}), {restart: true, readAgain: false});
    assert.deepEqual(recoveredActions({key: 'k', stored, record: null}), {restart: false, readAgain: true});
    assert.deepEqual(recoveredActions(null), {restart: false, readAgain: false});
});
