// Tests of resources/js/screens/jobs/detail.js: the pure rules of the Job
// detail screen: the requests, the
// header, the actions and their frozen confirmations, the write and read
// answers, the operation, the retries, the cycles, the history, the
// exception and the payload tree.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {
    actionsFor,
    backLink,
    baseName,
    countersLine,
    cycleHistoryAfter,
    cycleHistoryToDrop,
    earlierView,
    cycleTabs,
    cycleView,
    deleteSheet,
    detailUrl,
    earlierExceptions,
    entryUrl,
    exceptionView,
    forgetSheet,
    forgetRemovedRow,
    formatBytes,
    headerFacts,
    headline,
    historyCycleOptions,
    historyEntryText,
    historyEventLabel,
    historyFromDetail,
    historyNotices,
    historyPageOf,
    historyParams,
    historyUrl,
    inspectorLinks,
    keyText,
    layoutFor,
    listTab,
    markerText,
    messageBox,
    notFoundState,
    operationPanel,
    readFailure,
    readFailureState,
    resolveOutcome,
    resolveSheet,
    retriesBlock,
    retrySheet,
    statusChip,
    toolbarSubtitle,
    treeNode,
    treeSummary,
    utf8Bytes,
    visibleText,
    visibleChildren,
    writeFailure,
    writeOutcome,
    writeRequest,
} from '../../resources/js/screens/jobs/detail.js';

const UUID = '3f9b0c12-7d4e-4c1a-a6f0-2b8e91d5c047';
const OP = '0123456789abcdef0123456789abcdef';
const T = Date.UTC(2026, 10, 8, 9, 28, 44) / 1000;
const T_TEXT = '2026-11-08 09:28:44 UTC';

function failedDetail(over = {}) {
    return {
        id: UUID,
        cycle: 2,
        occurrence_id: 41,
        name: 'App\\Jobs\\SendInvoice',
        type: 'job',
        connection: 'database',
        queue: 'emails',
        batch_id: null,
        status: 'failed',
        silenced: false,
        attempts: 5,
        retried_count: 1,
        retried_count_inferred: false,
        tags: ['invoice:INV-20412', 'App\\Models\\Invoice:20412'],
        tags_truncated: false,
        pushed_at: T - 1600,
        available_at: T - 1600,
        reserved_at: T - 30,
        finished_at: T,
        failed_at: T,
        runtime_ms: 30100,
        exception_class: 'GuzzleHttp\\Exception\\ConnectException',
        exception_message: 'cURL error 28: Operation timed out',
        worker: null,
        lease_expires_at: null,
        operation: null,
        payload: {},
        command: {},
        exception: 'GuzzleHttp\\Exception\\ConnectException: cURL error 28',
        context: null,
        cycles: [
            {cycle: 2, current: true, outcome: 'failed', started_at: T - 800, started_source: 'dashboard', finished_at: T, attempts: 5, releases: 4, exceptions: 5, timeouts: 0, reclaims: 0, observed_gap: 0, late_outcomes: 0,
                exception_class: 'GuzzleHttp\\Exception\\ConnectException', exception_message: 'cURL error 28',
                limits: {max_tries: 5, retry_until: null, max_exceptions: null, timeout: null, fail_on_timeout: false},
                effective: {tries: 5, tries_source: 'payload', timeout: 120, timeout_source: 'worker'},
                enforced_rule: {kind: 'attempt_limit', deadline: null, tries: 5, tries_source: 'payload', other_stops: {max_exceptions: null, fail_on_timeout: false, timeout: 120, timeout_source: 'worker'}},
                terminal: {kind: 'attempt_limit', facts: {tries: 5, tries_source: 'payload', max_exceptions: null, attempt: 6}}},
            {cycle: 1, current: false, outcome: 'failed', started_at: T - 1600, started_source: 'push', finished_at: T - 900, attempts: 5, releases: 4, exceptions: 5, timeouts: 0, reclaims: 0, observed_gap: 0, late_outcomes: 0,
                exception_class: 'X', exception_message: 'y', limits: {max_tries: 5, retry_until: null, max_exceptions: null, timeout: null, fail_on_timeout: false},
                effective: {tries: null, tries_source: 'unknown', timeout: null, timeout_source: 'unknown'}, enforced_rule: null, terminal: null},
        ],
        retries: {entries: [{kind: 'retry', cycle: 2, started_at: T - 800, source: 'dashboard', outcome: 'failed'}], retries_retained: 1, retries_folded: 0, retries_unrecorded: 0, retried_count: 1, retried_count_inferred: false},
        history: {entries: [], next_cursor: null, history_truncated: false, history_discarded: 0},
        actions: {retry: true, forget: true, resolve: false, read_only_reason: null},
        batch: null,
        ...over,
    };
}

// Rejects: a request path built from a value that is not a uuid (a crafted route param reaching another API path), an upper-case uuid sent as is, and an entry id that is not a whole number.
test('detailUrl, historyUrl and entryUrl build the 4.5 paths from a uuid only', () => {
    assert.equal(detailUrl(UUID.toUpperCase()), `/api/jobs/${UUID}`);
    assert.equal(detailUrl('../counts'), null);
    assert.equal(detailUrl(null), null);
    assert.equal(historyUrl(UUID), `/api/jobs/${UUID}/history`);
    assert.equal(historyUrl('pending'), null);
    assert.equal(entryUrl(UUID, 17), `/api/jobs/${UUID}/history/17`);
    assert.equal(entryUrl(UUID, '17'), null);
    assert.equal(entryUrl(UUID, 1.5), null);
    assert.equal(entryUrl('x', 17), null);
});

// Rejects: history params that send an empty cycle or cursor, or a cycle that is not a whole number of 1 or more.
test('historyParams sends only the cycle filter and the cursor that are set', () => {
    assert.deepEqual(historyParams({}), {});
    assert.deepEqual(historyParams({cycle: 2, cursor: 'abc'}), {cycle: 2, cursor: 'abc'});
    assert.deepEqual(historyParams({cycle: 0, cursor: ''}), {});
    assert.deepEqual(historyParams({cycle: null, cursor: null}), {});
});

// Rejects: a reserved row sent to a "reserved" tab that does not exist, a silenced completed row sent to Completed, and an unknown status sent to a tab.
test('listTab and backLink name the Jobs list tab of the row', () => {
    assert.equal(listTab('pending', false), 'pending');
    assert.equal(listTab('reserved', false), 'running');
    assert.equal(listTab('completed', false), 'completed');
    assert.equal(listTab('completed', true), 'silenced');
    assert.equal(listTab('failed', false), 'failed');
    assert.equal(listTab('retrying', false), 'retrying');
    assert.equal(listTab('unknown', false), 'unknown');
    assert.equal(listTab('weird', false), null);

    assert.deepEqual(backLink(failedDetail()), {label: 'Failed', to: {path: '/jobs', query: {status: 'failed'}}});
    assert.deepEqual(backLink(failedDetail({status: 'reserved'})), {label: 'Running', to: {path: '/jobs', query: {status: 'running'}}});
    assert.deepEqual(backLink(null), {label: 'Jobs', to: {path: '/jobs'}});
});

// Rejects: a status chip that reads a missing status as pending, or a running row labelled with the raw code "reserved".
test('statusChip labels and tones each status', () => {
    assert.deepEqual(statusChip('failed'), {label: 'Failed', tone: 'danger'});
    assert.deepEqual(statusChip('reserved'), {label: 'Running', tone: 'accent'});
    assert.deepEqual(statusChip('completed'), {label: 'Completed', tone: 'success'});
    assert.deepEqual(statusChip('completed', true), {label: 'Completed · silenced', tone: 'success'});
    assert.deepEqual(statusChip('retrying'), {label: 'Retrying', tone: 'warning'});
    assert.deepEqual(statusChip('pending'), {label: 'Pending', tone: 'neutral'});
    assert.deepEqual(statusChip('unknown'), {label: 'Unknown', tone: 'neutral'});
    assert.deepEqual(statusChip(null), {label: 'Status unknown', tone: 'neutral'});
});

// Rejects: a base name that keeps the namespace, and one that fails on an empty or missing name.
test('baseName keeps the last segment of a class name', () => {
    assert.equal(baseName('App\\Jobs\\SendInvoice'), 'SendInvoice');
    assert.equal(baseName('Closure (web.php:12)'), 'Closure (web.php:12)');
    assert.equal(baseName(''), '');
    assert.equal(baseName(null), '');
});

// Rejects: a failed headline that names the job instead of the exception, an "of N" built from an unknown or unlimited tries value, and a pending row "after 0 attempts".
test('headline names the failure, or the job and its progress', () => {
    assert.deepEqual(headline(failedDetail()), {title: 'ConnectException', context: 'in cycle 2, after 5 of 5 attempts'});

    const unknownTries = failedDetail();
    unknownTries.cycles[0].effective.tries = null;
    assert.equal(headline(unknownTries).context, 'in cycle 2, after 5 attempts');

    const unlimited = failedDetail();
    unlimited.cycles[0].effective.tries = 0;
    assert.equal(headline(unlimited).context, 'in cycle 2, after 5 attempts');

    assert.deepEqual(headline(failedDetail({status: 'pending', attempts: 0, exception_class: null, cycles: null})), {title: 'SendInvoice', context: 'in cycle 2, not attempted yet'});
    assert.deepEqual(headline(failedDetail({status: 'reserved', attempts: 1, exception_class: null})), {title: 'SendInvoice', context: 'in cycle 2, attempt 1'});
    assert.deepEqual(headline(failedDetail({status: 'completed', attempts: 1, exception_class: null})), {title: 'SendInvoice', context: 'in cycle 2, after 1 attempt'});
});

// Rejects: a message box on a completed row with an old exception, and one that shows an empty message.
test('messageBox shows the first line of the failure on failed and retrying rows only', () => {
    assert.equal(messageBox(failedDetail()), 'cURL error 28: Operation timed out');
    assert.equal(messageBox(failedDetail({status: 'retrying'})), 'cURL error 28: Operation timed out');
    assert.equal(messageBox(failedDetail({status: 'completed'})), null);
    assert.equal(messageBox(failedDetail({exception_message: null})), null);
});

// Rejects: an unknown time or runtime shown as 0 or as the epoch start, a cycle source shown as its raw code, and the retry count without "inferred".
test('headerFacts lists the identity and the times, unknown as "—"', () => {
    const facts = headerFacts(failedDetail(), T + 240);
    const byLabel = Object.fromEntries(facts.map(fact => [fact.label, fact]));

    assert.equal(byLabel.UUID.value, UUID);
    assert.equal(byLabel.UUID.copy, true);
    assert.equal(byLabel.Queue.value, 'database:emails');
    assert.deepEqual(byLabel.Queue.to, '/queues/database/emails');
    assert.equal(byLabel.Cycle.value, '2 · started 2026-11-08 09:15:24 UTC');
    assert.equal(byLabel.Cycle.note, 'Retried from the dashboard.');
    assert.equal(byLabel.Attempts.value, '5 in this cycle · retried 1 time');
    assert.equal(byLabel.Pushed.value, '2026-11-08 09:02:04 UTC');
    assert.equal(byLabel['Last reserved'].value, '2026-11-08 09:28:14 UTC');
    assert.equal(byLabel.Failed.value, `${T_TEXT} · 4 min ago`);
    assert.equal(byLabel.Runtime.value, '30.1 s (last attempt)');

    const unknown = Object.fromEntries(headerFacts(failedDetail({
        status: 'pending', pushed_at: null, reserved_at: null, runtime_ms: null, cycles: null, retried_count: 3, retried_count_inferred: true,
    }), T).map(fact => [fact.label, fact.value]));
    assert.equal(unknown.Pushed, '—');
    assert.equal(unknown['Last reserved'], '—');
    assert.equal(unknown.Runtime, '—');
    assert.equal(unknown.Cycle, '2 · started —');
    assert.equal(unknown.Attempts, '5 in this cycle · retried 3 times (inferred)');
    assert.equal(unknown.Available, '2026-11-08 09:02:04 UTC');
    assert.equal(unknown.Failed, undefined, 'a pending row has no failure time');

    const fast = Object.fromEntries(headerFacts(failedDetail({status: 'completed', runtime_ms: 0, retried_count: 0}), T).map(fact => [fact.label, fact.value]));
    assert.equal(fast.Runtime, '0 ms (last attempt)', '0 is a measured value, not unknown');
    assert.equal(fast.Finished, `${T_TEXT} · just now`);
    assert.equal(fast.Attempts, '5 in this cycle · never retried');
});

// Rejects: Retry or Forget offered without canOperate, on a held row, on a row that is not failed, without a usable occurrence id, or when the server says no; Resolve without an awaiting operation; and a read-only reason shown as its raw code.
test('actionsFor offers exactly what the server would accept now', () => {
    assert.deepEqual(actionsFor(failedDetail(), true), {retry: true, forget: true, resolve: false, delete: false, deleteBlocked: null, held: false, reason: null});
    assert.deepEqual(actionsFor(failedDetail(), false), {retry: false, forget: false, resolve: false, delete: false, deleteBlocked: null, held: false, reason: null});
    assert.equal(actionsFor(failedDetail({status: 'completed'}), true).retry, false);
    assert.equal(actionsFor(failedDetail({occurrence_id: null}), true).retry, false);
    assert.equal(actionsFor(failedDetail({occurrence_id: 0}), true).forget, false);
    assert.equal(actionsFor(failedDetail({occurrence_id: '41'}), true).retry, false);
    assert.equal(actionsFor(failedDetail({actions: {retry: false, forget: true, resolve: false, read_only_reason: null}}), true).retry, false);

    const held = failedDetail({status: 'retrying', operation: {id: OP, action: 'retry', state: 'claimed', awaiting_reason: 'sqlite_host_file'}, actions: {retry: true, forget: true, resolve: true, read_only_reason: null}});
    assert.deepEqual(actionsFor(held, true), {retry: false, forget: false, resolve: true, delete: false, deleteBlocked: null, held: true, reason: null});
    assert.equal(actionsFor(held, false).resolve, false);
    // A forget claim holds a row that is still failed: nothing may start beside it.
    assert.deepEqual(actionsFor(failedDetail({operation: {id: OP, action: 'forget', state: 'claimed', awaiting_reason: null}}), true), {retry: false, forget: false, resolve: false, delete: false, deleteBlocked: null, held: true, reason: null});
    assert.equal(actionsFor(failedDetail({actions: {retry: false, forget: false, resolve: true, read_only_reason: null}}), true).resolve, false, 'no operation, no resolve');

    const readOnly = actionsFor(failedDetail({actions: {retry: false, forget: false, resolve: false, read_only_reason: 'ids_may_be_reused'}}), true);
    assert.equal(readOnly.retry, false);
    assert.match(readOnly.reason, /^The failed-job table can hand out an id again/);
    assert.equal(actionsFor(failedDetail({actions: {retry: false, forget: false, resolve: false, read_only_reason: 'provider_read_only'}}), false).reason, actionsFor(failedDetail({actions: {retry: false, forget: false, resolve: false, read_only_reason: 'provider_read_only'}}), true).reason, 'a viewer reads the reason too');
    assert.equal(actionsFor(null, true).retry, false);
});

// Rejects: a retry confirmation whose selection is not the occurrence of the page read, a SQLite note on a connection that is not SQLite, a sheet text that misses the job, its cycle or its queue, and a forget sheet without "cannot be undone".
test('retrySheet and forgetSheet freeze the occurrence of the page read', () => {
    const retry = retrySheet(failedDetail(), ['sqlite-queue']);
    assert.equal(retry.title, 'Retry this job?');
    assert.equal(retry.message, `App\\Jobs\\SendInvoice · 3f9b0c12 · cycle 2, failed at ${T_TEXT}. The stored payload is pushed again to database:emails as cycle 3.`);
    assert.equal(retry.note, null);
    assert.equal(retry.footnote, 'Only this failure is retried. If the job fails again first, nothing happens and you see why.');
    assert.equal(retry.confirmLabel, 'Retry');
    assert.equal(retry.tone, 'primary');
    assert.deepEqual(retry.selection, {action: 'retry', uuid: UUID, occurrence_id: 41, cycle: 2});

    assert.match(retrySheet(failedDetail(), ['database']).note, /^The queue database is a SQLite file of the application/);
    assert.equal(retrySheet(failedDetail(), null).note, null);

    const forget = forgetSheet(failedDetail());
    assert.equal(forget.title, 'Forget this failed job?');
    assert.equal(forget.message, `App\\Jobs\\SendInvoice · 3f9b0c12 · failed at ${T_TEXT}. Its failed-job record is deleted and Orrery stops keeping it. It is not retried. This cannot be undone.`);
    assert.equal(forget.tone, 'danger');
    assert.deepEqual(forget.selection, {action: 'forget', uuid: UUID, occurrence_id: 41, cycle: 2});
});

// Rejects: a resolve sheet without the operation id in its selection, an awaiting reason shown as its code, and
// a sheet that names neither the job nor what the step does, though a held forget step deletes a failed record.
// Still passes: a step text that is right for the action but does not say which of its effects will happen.
test('resolveSheet freezes the operation that waits', () => {
    const detail = failedDetail();
    const sheet = resolveSheet({id: OP, action: 'retry', state: 'claimed', awaiting_reason: 'sqlite_host_file'}, detail);
    assert.equal(sheet.title, 'Resolve this retry?');
    assert.equal(sheet.message, 'App\\Jobs\\SendInvoice · 3f9b0c12: Orrery runs the step that waits for an operator now, as your action. It finishes the retry that waits: it may delete the failed-job record and push the job again, or undo a retry that did not publish.');
    assert.equal(sheet.note, `Waiting for an operator: this step writes the application's SQLite file. Run php artisan orrery:resolve ${OP}.`);
    assert.equal(sheet.confirmLabel, 'Resolve');
    assert.equal(sheet.tone, 'primary');
    assert.deepEqual(sheet.selection, {action: 'resolve', operation_id: OP, uuid: UUID});

    const forget = resolveSheet({id: OP, action: 'forget', state: 'claimed', awaiting_reason: null}, detail);
    assert.equal(forget.title, 'Resolve this forget?');
    assert.match(forget.message, /may delete the failed-job record of this failure\. A deleted record cannot be restored\.$/);
    assert.equal(forget.tone, 'danger');

    assert.equal(resolveSheet({id: OP, action: null}, {id: UUID}).message, '3f9b0c12: Orrery runs the step that waits for an operator now, as your action.');
    assert.equal(resolveSheet(null, detail), null);
});

// Rejects: a request whose body takes anything but the selection's own occurrence id (a string, fractional or zero occurrence), a request for an unknown action, and a resolve path built from an id that is not an operation id. That the selection holds the occurrence of the page read is tested with retrySheet and forgetSheet; that the sheet posts its frozen copy is ConfirmSheet's contract and the browser check.
test('writeRequest posts exactly the frozen selection', () => {
    assert.deepEqual(writeRequest({action: 'retry', uuid: UUID.toUpperCase(), occurrence_id: 41}), {url: `/api/jobs/${UUID}/retry`, body: {occurrence_id: 41}});
    assert.deepEqual(writeRequest({action: 'forget', uuid: UUID, occurrence_id: 7}), {url: `/api/jobs/${UUID}/forget`, body: {occurrence_id: 7}});
    assert.deepEqual(writeRequest({action: 'resolve', operation_id: OP}), {url: `/api/operations/${OP}/resolve`, body: {}});
    assert.equal(writeRequest({action: 'retry', uuid: UUID, occurrence_id: '41'}), null);
    assert.equal(writeRequest({action: 'retry', uuid: UUID, occurrence_id: 1.5}), null);
    assert.equal(writeRequest({action: 'retry', uuid: UUID, occurrence_id: 0}), null);
    assert.equal(writeRequest({action: 'retry', uuid: 'abc', occurrence_id: 1}), null);
    assert.equal(writeRequest({action: 'purge', uuid: UUID, occurrence_id: 1}), null);
    assert.equal(writeRequest({action: 'resolve', operation_id: '../x'}), null);
    assert.equal(writeRequest(null), null);
});

// Rejects: a retry answer read from the HTTP status instead of `result`, an uncertain result shown as success, a refusal without its operator text, and a replayed forget shown as an error.
test('writeOutcome reads the result item of 4.8', () => {
    assert.deepEqual(writeOutcome('retry', {result: 'accepted', code: null, operation_id: OP}), {tone: 'success', title: 'Retry started', detail: 'Accepted.'});
    assert.deepEqual(writeOutcome('retry', {result: 'not_eligible', code: 'occurrence_mismatch'}), {tone: 'danger', title: 'Retry not started', detail: 'This failure was replaced by a newer one: reload the page and decide again.'});
    assert.deepEqual(writeOutcome('retry', {result: 'uncertain', code: 'publish_uncertain'}).tone, 'warning');
    assert.equal(writeOutcome('retry', {result: 'uncertain', code: 'publish_uncertain'}).title, 'Retry not decided yet');
    assert.deepEqual(writeOutcome('retry', {result: 'accepted', code: null, awaiting_reason: 'sqlite_host_file', operation_id: OP}), {
        tone: 'warning', title: 'Retry waits for an operator', detail: `Waiting for an operator: this step writes the application's SQLite file. Run php artisan orrery:resolve ${OP}.`,
    });
    assert.deepEqual(writeOutcome('forget', {result: 'deleted', code: null}), {tone: 'success', title: 'Forgotten', detail: 'Forgotten.'});
    // Nit: a forget whose mirror row stays is no green "Forgotten" beside an error detail.
    assert.deepEqual(writeOutcome('forget', {result: 'mirror_pending', code: null}),
        {tone: 'warning', title: "Forgotten; Orrery's row is removed later", detail: "Forgotten; Orrery's own row is removed by the runner."});
    assert.deepEqual(writeOutcome('forget', {result: 'already_done', code: null}), {tone: 'success', title: 'Already forgotten', detail: 'Already done earlier: nothing repeated.'});
    assert.equal(writeOutcome('forget', {result: 'refused', code: 'provider_read_only'}).title, 'Not forgotten');
    assert.deepEqual(writeOutcome('retry', null), {tone: 'warning', title: 'Outcome unknown', detail: 'The change may not have been saved. Reload to see the current state; trying again is safe.'});
});

// Rejects: a lost answer shown as a refusal (the outcome is unknown), a 404 or 422 shown as a lost answer, a 403 that adds a second message to the global alert, and a resolve 409 without the target-changed text.
test('writeFailure tells a refused request from a lost answer', () => {
    const status = (code, data = {}) => ({response: {status: code, data}});

    assert.deepEqual(writeFailure('retry', status(404, {error: 'not_found'})), {tone: 'danger', title: 'Retry not started', detail: 'Not found: it no longer exists or is no longer kept. Nothing changed.'});
    assert.equal(writeFailure('forget', status(422, {error: 'invalid', field: 'occurrence_id'})).detail, 'The request was not valid: nothing changed. Reload the page and try again.');
    assert.equal(writeFailure('retry', status(403, {error: 'forbidden_action'})), null);
    assert.deepEqual(writeFailure('retry', {code: 'ECONNABORTED', message: 'timeout of 30000ms exceeded'}), {tone: 'warning', title: 'Outcome unknown', detail: 'The change may not have been saved. Reload to see the current state; trying again is safe.'});
    assert.equal(writeFailure('forget', status(500)).title, 'Outcome unknown');
    assert.equal(writeFailure('resolve', status(409, {message: 'x', operation: {code: 'target_changed'}})).detail, 'The failed-job storage changed since this failure. Check the queue configuration.');
    assert.equal(writeFailure('resolve', status(404, {message: 'Not found.'})).title, 'Not resolved');

    const undecided = writeFailure('resolve', status(503, {operation: {id: OP, state: 'claimed', step: 'undecided', error_code: 'resolution_failed', awaiting_reason: null}}));
    assert.equal(undecided.tone, 'warning');
    assert.equal(undecided.title, 'The step did not finish');
    assert.equal(undecided.detail, 'The outcome of the publication could not be read yet. The runner tries again.');
    assert.equal(writeFailure('resolve', status(503, {message: 'The operation could not be resolved.'})).title, 'Outcome unknown');
});

// Rejects: a resolve answer whose state is shown as its code, an awaiting step shown as done, a step that stopped the operation shown as a success, and an operation that had already finished shown as a step that did not finish.
test('resolveOutcome words the state after the step', () => {
    assert.deepEqual(resolveOutcome({id: OP, state: 'done', step: 'decided', code: null, error_code: null, awaiting_reason: null}), {tone: 'success', title: 'Resolved', detail: 'Done.'});
    assert.deepEqual(resolveOutcome({id: OP, state: 'claimed', step: 'awaiting', code: null, error_code: null, awaiting_reason: 'sqlite_host_file'}), {
        tone: 'warning', title: 'Still waiting for an operator', detail: `Waiting for an operator: this step writes the application's SQLite file. Run php artisan orrery:resolve ${OP}.`,
    });
    assert.equal(resolveOutcome({id: OP, state: 'published', step: 'later', error_code: null}).detail, 'Published: the job is back on its queue.');
    assert.deepEqual(resolveOutcome({id: OP, state: 'aborted', step: 'decided', code: null, error_code: 'cancelled', awaiting_reason: null}), {
        tone: 'warning', title: 'The operation stopped', detail: 'The runner cancelled this operation before it took effect: nothing was published or deleted. Try again.',
    });
    assert.equal(resolveOutcome(null).title, 'Outcome unknown');

    // The operation finished before the resolve (the runner, or another operator): not "The step did not finish".
    assert.deepEqual(resolveOutcome({id: OP, state: 'done', step: 'terminal', code: null, error_code: null, awaiting_reason: null}), {tone: 'info', title: 'Already finished', detail: 'Done.'});
    assert.deepEqual(resolveOutcome({id: OP, state: 'aborted', step: 'terminal', code: null, error_code: 'cancelled', awaiting_reason: null}), {
        tone: 'warning', title: 'Already finished', detail: 'The runner cancelled this operation before it took effect: nothing was published or deleted. Try again.',
    });
});

// Rejects: a 404 read as a failure (it is "no longer kept"), the redaction error read as a plain failure (the whole panel must say so), and a timeout without its own text.
test('readFailure classifies a failed detail read', () => {
    const status = (code, data = {}) => ({response: {status: code, data}});

    assert.equal(readFailure(status(404, {error: 'not_found'})).kind, 'not_found');
    const redaction = readFailure(status(500, {error: 'redaction_config_invalid', field: 'orrery.redact.patterns.2'}));
    assert.equal(redaction.kind, 'redaction');
    assert.equal(redaction.title, 'Job data is hidden: the redaction settings are invalid');
    assert.equal(redaction.detail, 'orrery.redact.patterns.2 is not a valid rule. Fix the configuration; no job data is shown until then.');
    assert.equal(readFailure(status(503, {error: 'read_timeout'})).detail, 'A read took too long. Narrow the selection and try again.');
    assert.equal(readFailure(status(503, {error: 'read_failed'})).kind, 'failed');
    assert.equal(readFailure(status(500)).kind, 'failed');
    assert.equal(readFailure({code: 'ERR_NETWORK'}).kind, 'failed');
});

// Rejects: a legacy id that is not a uuid shown as a job that was removed, a 404 state that does not say polling stopped, and a forget that the not-found panel no longer names.
test('notFoundState words a removed job and a link that names no uuid', () => {
    assert.deepEqual(notFoundState(), {title: 'This job is no longer kept', detail: 'Retention or a forget removed it. The page stopped refreshing.'});
    assert.deepEqual(notFoundState('12345'), {title: 'No job with this id', detail: 'The link names "12345", which is not a job uuid. Only a job\'s uuid opens its page.'});
    assert.deepEqual(notFoundState(null, true), {title: 'Forgotten: this job is no longer kept', detail: 'Its failed-job record and its row in Orrery were deleted. The page stopped refreshing.'});
});

// Rejects: a held row whose ledger row is gone shown as "no operation", an awaiting reason or an error code shown as its code, an unknown actor shown as blank, and a forget's source worded as a retry.
test('operationPanel describes the holding operation from the ledger', () => {
    assert.deepEqual(operationPanel(null), {kind: 'none', text: 'No operation holds this job.'});

    const gone = operationPanel({id: OP, action: null, state: null});
    assert.equal(gone.kind, 'unknown');
    assert.equal(gone.text, 'Held by an operation whose record is gone; state unknown.');

    const waiting = operationPanel({id: OP, action: 'retry', state: 'claimed', mode: 'in_tx', source: 'dashboard', actor: 'operator@example.com', error_code: null, awaiting_reason: 'sqlite_host_file', bulk_ref: null, created_at: T, updated_at: T, next_check_at: null});
    assert.equal(waiting.kind, 'awaiting');
    assert.equal(waiting.label, 'Retry · awaiting an operator');
    assert.equal(waiting.tone, 'warning');
    assert.equal(waiting.text, `Waiting for an operator: this step writes the application's SQLite file. Run php artisan orrery:resolve ${OP}.`);
    assert.deepEqual(waiting.facts, [
        {label: 'Started', value: `${T_TEXT} by operator@example.com`},
        {label: 'Source', value: 'Retried from the dashboard.'},
        {label: 'Next check', value: 'none (waits for an operator)'},
    ]);

    const failing = operationPanel({id: OP, action: 'forget', state: 'aborting', source: 'dashboard', actor: null, error_code: 'restore_failed', awaiting_reason: null, bulk_ref: 'abc', created_at: T, next_check_at: T + 60});
    assert.equal(failing.kind, 'active');
    assert.equal(failing.label, 'Forget · stopping');
    assert.equal(failing.text, 'Stopping: the operation is being undone.');
    assert.deepEqual(failing.facts, [
        {label: 'Started', value: `${T_TEXT} by an unknown actor`},
        {label: 'Error', value: 'The failed-job record could not be put back yet. The runner tries again.'},
        {label: 'Next check', value: '2026-11-08 09:29:44 UTC'},
    ]);
    assert.equal(failing.bulkRef, 'abc');
});

// Rejects: a retry time without its date and zone, an invented entry for an unrecorded retry, a folded range without its count, missing "not recorded" and "inferred" notices, and unread retries shown as "never retried".
test('retriesBlock lists the retained retries and the notices', () => {
    const block = retriesBlock({
        entries: [
            {kind: 'retry', cycle: 9, started_at: T, source: 'cli', outcome: 'in_progress'},
            {kind: 'retry', cycle: 8, started_at: null, source: 'bulk', outcome: 'completed'},
            {kind: 'earlier', first_cycle: 2, last_cycle: 6, count: 5},
        ],
        retries_retained: 2, retries_folded: 5, retries_unrecorded: 3, retried_count: 10, retried_count_inferred: true,
    });

    assert.equal(block.unknown, false);
    assert.equal(block.retained, '2 retained');
    assert.deepEqual(block.rows, [
        {key: 'retry-9', label: 'Cycle 9', when: T_TEXT, source: 'Retried from the command line.', outcome: {label: 'in progress', tone: 'accent'}},
        {key: 'retry-8', label: 'Cycle 8', when: '—', source: 'Retried in a bulk run.', outcome: {label: 'completed', tone: 'success'}},
        {key: 'earlier', label: 'Cycles 2–6', when: null, source: '5 retries folded together', outcome: null},
    ]);
    assert.deepEqual(block.notes, ['3 earlier retries were not recorded.', 'Retry count inferred from the retry cycle.', 'Retried 10 times in all.']);

    assert.deepEqual(retriesBlock({entries: [], retries_retained: 0, retries_folded: 0, retries_unrecorded: 1, retried_count: 1, retried_count_inferred: false}).notes, ['1 earlier retry was not recorded.', 'Retried 1 time in all.']);
    assert.deepEqual(retriesBlock({entries: [], retries_retained: 0, retries_folded: 0, retries_unrecorded: 0, retried_count: 0, retried_count_inferred: false}).notes, ['Never retried.']);
    assert.deepEqual(retriesBlock(null), {unknown: true, text: 'Retries unknown: they could not be read.', retained: null, rows: [], notes: []});
});

// Rejects: a merged tab that names one cycle, a current tab without "current", and unread cycles shown as none.
test('cycleTabs lists the cycles newest first, the merged entry last', () => {
    assert.deepEqual(cycleTabs([
        {cycle: 52, current: true, outcome: 'failed'},
        {cycle: 51, current: false, outcome: 'completed'},
        {cycle: 7, current: false, outcome: 'unknown'},
        {cycle: 6, merged: true, first_cycle: 1, merged_cycles: 6, merged_retries: 5},
    ]), [
        {key: 'c52', cycle: 52, label: 'Cycle 52 · current', tone: 'danger', merged: false},
        {key: 'c51', cycle: 51, label: 'Cycle 51', tone: 'success', merged: false},
        {key: 'c7', cycle: 7, label: 'Cycle 7', tone: 'neutral', merged: false},
        {key: 'merged', cycle: 6, label: 'Cycles 1–6', tone: 'neutral', merged: true},
    ]);
    assert.deepEqual(cycleTabs(null), []);
    assert.equal(cycleTabs([{cycle: 1, current: true, outcome: 'in_progress'}])[0].tone, 'accent');
});

// Rejects: a merged cycle shown with current values (it must say "Details not retained"), the rule of a failed cycle worded as "now", an in-progress cycle worded at the failure, a counter of 0 shown as unknown, and effective values without their source.
test('cycleView builds the diagnostics of one cycle', () => {
    const merged = cycleView({cycle: 6, merged: true, first_cycle: 1, merged_cycles: 6, merged_retries: 5});
    assert.deepEqual(merged, {merged: true, title: 'Details not retained', detail: 'Cycles 1–6: 6 cycles merged, 5 retries folded together.'});

    const failed = cycleView(failedDetail().cycles[0]);
    assert.equal(failed.merged, false);
    assert.equal(failed.terminal.headline, 'Failed on attempt 6: the attempt limit (5) was reached');
    assert.equal(failed.ruleHeading, 'Enforced rule at the failure');
    assert.equal(failed.rule.headline, "Attempt limit: 5 (from the job's payload)");
    assert.deepEqual(failed.effective, [
        {label: 'Tries', value: '5 · payload'},
        {label: 'Timeout', value: '120 s · worker --timeout'},
        {label: 'Retry until', value: 'not set'},
    ]);
    assert.deepEqual(failed.counters.map(counter => [counter.label, counter.value]), [
        ['Attempts', '5'], ['Releases', '4'], ['Exceptions', '5'], ['Timeouts', '0'], ['Reclaims', '0'], ['Not observed', '0'], ['Late outcomes', '0'],
    ]);
    assert.equal(failed.countersNote, 'The counters count observed events only. Releases are counted, not recorded one by one.');

    const frozen = cycleView(failedDetail().cycles[1]);
    assert.equal(frozen.rule.headline, 'Enforced rule unknown: this cycle has no recorded finish time.');
    assert.deepEqual(frozen.effective.slice(0, 2), [{label: 'Tries', value: 'unknown'}, {label: 'Timeout', value: 'unknown'}]);
    assert.equal(frozen.terminal, null);

    const running = cycleView({...failedDetail().cycles[0], outcome: 'in_progress', terminal: null, limits: {...failedDetail().cycles[0].limits, retry_until: T},
        effective: {tries: 0, tries_source: 'worker', timeout: 0, timeout_source: 'payload'}});
    assert.equal(running.ruleHeading, 'Enforced rule now');
    assert.equal(running.terminal, null);
    assert.deepEqual(running.effective, [
        {label: 'Tries', value: 'unlimited (0) · worker --tries'},
        {label: 'Timeout', value: 'no alarm (0) · payload'},
        {label: 'Retry until', value: T_TEXT},
    ]);
    assert.equal(cycleView({...failedDetail().cycles[0], outcome: 'completed', terminal: null}).ruleHeading, 'Enforced rule at the finish');
    assert.equal(cycleView(null), null);
});

// Rejects: a phone fact line that drops the gap or shows unknown tries as a number.
test('countersLine puts the cycle facts on one line', () => {
    assert.equal(countersLine(failedDetail().cycles[0]), "5 tries from the job's payload · timeout 120 s from the worker's --timeout · releases 4 · exceptions 5 · timeouts 0 · not observed 0");
    assert.equal(countersLine(failedDetail().cycles[1]), 'tries unknown · timeout unknown · releases 4 · exceptions 5 · timeouts 0 · not observed 0');
    assert.equal(countersLine({merged: true}), null);
});

// Rejects: an event shown as its raw code, a failure without its danger tone, and an entry text that drops the exception or reads a source as its code.
test('historyEventLabel and historyEntryText word a history entry', () => {
    assert.deepEqual(historyEventLabel('failed'), {label: 'failed', tone: 'danger'});
    assert.deepEqual(historyEventLabel('timeout'), {label: 'timed out', tone: 'warning'});
    assert.deepEqual(historyEventLabel('retry_aborted'), {label: 'retry stopped', tone: 'neutral'});
    assert.deepEqual(historyEventLabel('something_else'), {label: 'something else', tone: 'neutral'});

    assert.equal(historyEntryText({event: 'failed', exception_class: 'A\\B\\ConnectException', exception_message: 'cURL error 28'}), 'ConnectException: cURL error 28');
    assert.equal(historyEntryText({event: 'exception', exception_class: 'A\\B\\E', exception_message: null}), 'E');
    assert.equal(historyEntryText({event: 'retried', source: 'dashboard'}), 'Retried from the dashboard.');
    assert.equal(historyEntryText({event: 'retry_aborted', reason: 'publish_failed'}), 'The job could not be put back on its queue: nothing was published.');
    assert.equal(historyEntryText({event: 'retry_aborted', reason: null}), 'The retry was stopped.');
    assert.equal(historyEntryText({event: 'late_outcome'}), 'The outcome of an older attempt arrived after a newer one.');
    assert.equal(historyEntryText({event: 'completed'}), 'Completed.');
    assert.equal(historyEntryText({event: 'odd', reason: null}), '—');
});

// Rejects: a truncated or discarded history without its notice, and a notice on a complete history.
test('historyNotices says when entries were not kept', () => {
    assert.deepEqual(historyNotices({history_truncated: false, history_discarded: 0}), []);
    assert.deepEqual(historyNotices({history_truncated: true, history_discarded: 12}), [
        'Some entries were not kept: Orrery keeps the first 5 and the newest 45 of each cycle, and at most 200 of a job.',
        '12 entries were discarded.',
    ]);
    assert.deepEqual(historyNotices({history_truncated: false, history_discarded: 1}), ['1 entry was discarded.']);
    assert.deepEqual(historyNotices(null), []);
});

// Rejects: a cycle filter that offers the merged cycles (they have no history of their own), and one without "All cycles".
test('historyCycleOptions offers all cycles and each retained cycle', () => {
    assert.deepEqual(historyCycleOptions([{cycle: 3, current: true}, {cycle: 2}, {cycle: 1, merged: true, first_cycle: 1}]), [
        {value: null, label: 'All cycles'}, {value: 3, label: 'Cycle 3'}, {value: 2, label: 'Cycle 2'},
    ]);
    assert.deepEqual(historyCycleOptions(null), [{value: null, label: 'All cycles'}]);
});

// Rejects: earlier exceptions that include the final failure or another cycle, or that lose the newest-first order.
test('earlierExceptions keeps the exceptions and timeouts of one cycle', () => {
    const entries = [
        {id: 9, cycle: 2, event: 'failed'},
        {id: 8, cycle: 2, event: 'exception'},
        {id: 7, cycle: 2, event: 'timeout'},
        {id: 6, cycle: 1, event: 'exception'},
        {id: 5, cycle: 2, event: 'retried'},
    ];

    assert.deepEqual(earlierExceptions(entries, 2).map(entry => entry.id), [8, 7]);
    assert.deepEqual(earlierExceptions(null, 2), []);
});

// Rejects: a size label in characters instead of UTF-8 bytes, and bytes shown without a unit.
test('utf8Bytes and formatBytes size a text as stored', () => {
    assert.equal(utf8Bytes('abc'), 3);
    assert.equal(utf8Bytes('ą'), 2);
    assert.equal(utf8Bytes('€'), 3);
    assert.equal(formatBytes(512), '512 B');
    assert.equal(formatBytes(18637), '18.2 KB');
    assert.equal(formatBytes(1468006), '1.4 MB');
    assert.equal(formatBytes(null), '—');
});

// Rejects: a stack trace that hides its first line, shows vendor frames as the application's, folds without saying how many frames there are, or reads an omitted exception as text.
test('exceptionView splits the text and emphasises the application frames', () => {
    const text = [
        'GuzzleHttp\\Exception\\ConnectException: cURL error 28',
        'Authorization: [redacted]',
        '#0 vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php(210): x()',
        '#1 /var/www/vendor/laravel/framework/src/Q.php(1): y()',
        '#2 app/Services/Xero/InvoiceClient.php(88): push()',
        '#3 app/Jobs/SendInvoice.php(54): send()',
        '#4 vendor/a.php(1): z()',
        '#5 {main}',
    ].join('\n');

    const folded = exceptionView(text, false);
    assert.equal(folded.kind, 'text');
    assert.equal(folded.size, `${utf8Bytes(text)} B`);
    assert.equal(folded.lines.length, 6);
    assert.deepEqual(folded.lines.map(line => line.app), [false, false, false, false, true, true]);
    assert.equal(folded.hidden, 2);
    assert.equal(folded.toggle, 'Show all 6 frames');

    const open = exceptionView(text, true);
    assert.equal(open.lines.length, 8);
    assert.equal(open.toggle, 'Show fewer lines');
    assert.equal(open.lines[7].app, false, '{main} is not an application frame');

    const short = exceptionView('One line', false);
    assert.equal(short.toggle, null);

    assert.deepEqual(exceptionView({omitted: 'too_large', bytes: 70000}, false), {kind: 'marker', text: 'Too large to show · 68.4 KB'});
    assert.deepEqual(exceptionView('[redaction failed]', false), {kind: 'redaction_failed', text: '[redaction failed]'});
    assert.deepEqual(exceptionView(null, false), {kind: 'none', text: 'No exception recorded.'});
});

// Rejects: a marker shown as raw JSON, a size in characters, and a reason without its words.
test('markerText words every omitted and unavailable marker', () => {
    assert.equal(markerText({omitted: 'too_large', bytes: 1677722}), 'Too large to show · 1.6 MB');
    assert.equal(markerText({omitted: 'string_too_large', bytes: 1468006}), 'Omitted · string too large · 1.4 MB');
    assert.equal(markerText({omitted: 'nested_serialized', bytes: 300}), 'Omitted · a nested serialized value that could not be read · 300 B');
    assert.equal(markerText({omitted: 'hidden_context'}), 'Hidden context · not shown');
    assert.equal(markerText({omitted: 'response_budget', bytes: 3000000}), 'Omitted · over the 2 MB response budget · 2.9 MB');
    assert.equal(markerText({omitted: 'new_reason'}), 'Omitted · new_reason');
    assert.equal(markerText({unavailable: 'limit', limit: 'work'}), 'Not shown · the work limit was reached');
    assert.equal(markerText({unavailable: 'unsupported'}), 'Not shown · a value Orrery does not read');
    assert.equal(markerText({unavailable: 'unknown_format'}), 'Not shown · an unknown format');
    assert.equal(markerText({unavailable: 'invalid_json'}), 'Not shown · not valid JSON');
    assert.equal(markerText({unavailable: 'error'}), 'Not shown · it could not be read');
    assert.equal(markerText({closure: true}), 'Closure · not shown');
    assert.equal(markerText({encrypted: true}), 'Encrypted · not shown');
    assert.equal(markerText({ref: 3}), 'Reference to value #3 · not expanded');
    assert.equal(markerText({binary: 2048}), 'Binary data · 2.0 KB');
    assert.equal(markerText({class: 'ArrayObject', custom: true, bytes: 212}), 'ArrayObject · custom serialization · 212 B, not parsed');
    assert.equal(markerText({class: 'Array\u202EObject', custom: true, bytes: 212}), 'Array\\u202EObject · custom serialization · 212 B, not parsed', 'a class name is job data');
    assert.equal(markerText({a: 1}), null);
});

// Rejects: a parser output node read as a plain map (the tree would show "array"/"properties" keys), a natural object that only looks partly like a marker read as one, a redacted value shown as a string, and a nested serialized value without its flag.
test('treeNode classifies the data-only projection', () => {
    assert.deepEqual(treeNode(null), {kind: 'scalar', text: 'null', type: 'null'});
    assert.deepEqual(treeNode(5), {kind: 'scalar', text: '5', type: 'number'});
    assert.deepEqual(treeNode(false), {kind: 'scalar', text: 'false', type: 'boolean'});
    assert.deepEqual(treeNode('INF'), {kind: 'string', text: 'INF'});
    assert.deepEqual(treeNode('[redacted]'), {kind: 'redacted', text: '[redacted]'});
    assert.deepEqual(treeNode('[redaction failed]'), {kind: 'redaction_failed', text: '[redaction failed]'});
    assert.deepEqual(treeNode({enum: 'App\\Enums\\Currency::EUR'}), {kind: 'enum', text: 'App\\Enums\\Currency::EUR'});
    assert.deepEqual(treeNode({closure: true}), {kind: 'marker', text: 'Closure · not shown'});
    assert.deepEqual(treeNode({ref: 2}), {kind: 'marker', text: 'Reference to value #2 · not expanded'});

    const array = treeNode({array: [{key: 0, value: 'a'}, {key: 'b', value: {array: []}}]});
    assert.equal(array.kind, 'container');
    assert.equal(array.shape, 'array');
    assert.deepEqual(array.children.map(child => child.key), ['0', 'b']);
    assert.equal(array.children[1].value.array.length, 0);

    const object = treeNode({class: 'App\\Jobs\\SendInvoice', properties: [
        {name: 'apiToken', visibility: 'private', declared_in: 'App\\Jobs\\SendInvoice', value: '[redacted]'},
        {name: 'tries', visibility: 'public', value: 5},
    ]});
    assert.equal(object.kind, 'container');
    assert.equal(object.shape, 'object');
    assert.equal(object.className, 'App\\Jobs\\SendInvoice');
    assert.deepEqual(object.children[0], {key: 'apiToken', visibility: 'private', declaredIn: 'App\\Jobs\\SendInvoice', value: '[redacted]'});
    assert.deepEqual(object.children[1], {key: 'tries', visibility: 'public', declaredIn: null, value: 5});

    const serialized = treeNode({serialized: {array: [{key: 0, value: 1}]}});
    assert.equal(serialized.kind, 'container');
    assert.equal(serialized.serialized, true);

    const list = treeNode([1, 2]);
    assert.equal(list.shape, 'list');
    assert.deepEqual(list.children.map(child => child.key), ['0', '1']);

    const map = treeNode({job: 'X', data: {command: 1}});
    assert.equal(map.shape, 'map');
    assert.deepEqual(map.children.map(child => child.key), ['job', 'data']);

    assert.equal(treeNode({closure: true, extra: 1}).shape, 'map', 'a marker needs its exact keys');
    assert.equal(treeNode({array: 'not a list'}).shape, 'map');
    assert.equal(treeNode({class: 'X', properties: [], more: 1}).shape, 'map');
});

// Rejects: an array key marker shown as JSON, and a numeric key shown as a number type.
test('keyText shows a key as text, a key marker in words', () => {
    assert.equal(keyText('name'), 'name');
    assert.equal(keyText(3), '3');
    assert.equal(keyText({binary: 4}), '(binary key · 4 B)');
    assert.equal(keyText({omitted: 'string_too_large', bytes: 70000}), '(key not shown)');
    assert.equal(keyText(null), '(key)');
});

// Rejects: a collapsed container without its size, a ModelIdentifier shown without its model and id, and an object summary without the class.
test('treeSummary sums up a container in one line', () => {
    assert.equal(treeSummary(treeNode({class: 'App\\X', properties: [{name: 'a', visibility: 'public', value: 1}]})), 'object · 1 property');
    assert.equal(treeSummary(treeNode({array: [{key: 0, value: 1}, {key: 1, value: 2}]})), 'array · 2 items');
    assert.equal(treeSummary(treeNode([])), 'list · 0 items');
    assert.equal(treeSummary(treeNode({a: 1, b: 2, c: 3})), '3 keys');
    assert.equal(treeSummary(treeNode({class: 'Illuminate\\Contracts\\Database\\ModelIdentifier', properties: [
        {name: 'class', visibility: 'public', value: 'App\\Models\\Invoice'},
        {name: 'id', visibility: 'public', value: 20412},
        {name: 'relations', visibility: 'public', value: {array: []}},
    ]})), 'App\\Models\\Invoice #20412');
    assert.equal(treeSummary(treeNode(1)), null);
});

// Rejects: an integer beyond 2^53 that the server sends as text ({integer}) drawn as a map or a string instead of the
// exact number, the same marker lost in an array key and in the ModelIdentifier summary, and a marker with other
// keys or a non-numeric text read as a number.
test('an exact integer marker draws as its digits', () => {
    assert.deepEqual(treeNode({integer: '1234567890123456789'}), {kind: 'scalar', text: '1234567890123456789', type: 'number'});
    assert.deepEqual(treeNode({integer: '-9223372036854775808'}), {kind: 'scalar', text: '-9223372036854775808', type: 'number'});
    assert.equal(treeNode({integer: '12a'}).kind, 'container');
    assert.equal(treeNode({integer: '1', extra: 1}).kind, 'container');
    assert.equal(keyText({integer: '9007199254740993'}), '9007199254740993');
    assert.equal(treeSummary(treeNode({class: 'Illuminate\\Contracts\\Database\\ModelIdentifier', properties: [
        {name: 'class', visibility: 'public', value: 'App\\Models\\Invoice'},
        {name: 'id', visibility: 'public', value: {integer: '1234567890123456789'}},
    ]})), 'App\\Models\\Invoice #1234567890123456789');
});

// Rejects: a long array rendered whole (thousands of rows at once), and a "show more" that skips or repeats children.
test('visibleChildren pages a long container', () => {
    assert.deepEqual(visibleChildren(10, 200), {count: 10, more: 0});
    assert.deepEqual(visibleChildren(1000, 200), {count: 200, more: 800});
    assert.deepEqual(visibleChildren(1000, 1200), {count: 1000, more: 0});
    assert.deepEqual(visibleChildren(-1, 200), {count: 0, more: 0});
});

// Rejects: links that send the job name to another place than the list's class filter, a batch link labelled blank when its name is unknown, and a tag path that a slash splits or a dot segment resolves away.
test('inspectorLinks builds the tag, batch, queue and class links', () => {
    const links = inspectorLinks(failedDetail({batch_id: 'b-1', batch: {id: 'b-1', name: null}, tags: ['a/b', 'x']}));
    // A tag that a path cannot carry ('/', '.', '..') links to the query form (screens/tags/tag-links.js).
    assert.deepEqual(links.tags, [{label: 'a/b', to: {path: '/tags', query: {tag: 'a/b'}}}, {label: 'x', to: {path: '/tags/x', query: {}}}]);
    assert.deepEqual(links.batch, {label: 'b-1', to: '/batches/b-1'});
    assert.deepEqual(links.queue, {label: 'database:emails', to: '/queues/database/emails'});
    assert.deepEqual(links.klass, {label: 'Other SendInvoice jobs', to: {path: '/jobs', query: {status: 'failed', class: 'App\\Jobs\\SendInvoice'}}});
    assert.equal(inspectorLinks(failedDetail({batch: {id: 'b-1', name: 'November invoices'}, batch_id: 'b-1'})).batch.label, 'November invoices');
    assert.equal(inspectorLinks(failedDetail()).batch, null);
    assert.equal(inspectorLinks(failedDetail({tags: null})).tags, null, 'tags that could not be read are unknown, not none');
});

// Rejects: the breakpoints of 5.5 moved (the inspector folds below 1024, the action bar below 768).
test('layoutFor follows the D8-0b breakpoints', () => {
    assert.equal(layoutFor(1920), 'wide');
    assert.equal(layoutFor(1024), 'wide');
    assert.equal(layoutFor(1023), 'narrow');
    assert.equal(layoutFor(768), 'narrow');
    assert.equal(layoutFor(767), 'phone');
    assert.equal(layoutFor(375), 'phone');
});

// Rejects: a toolbar subtitle without the zone of the times.
test('toolbarSubtitle names the time zone of the times', () => {
    assert.equal(toolbarSubtitle(T), 'Job detail · times in UTC');
});

// Rejects: a 404 of the detail or the history that keeps the detail on screen (the job is gone), a gone state that lets an in-flight read of the detail, the history or an entry paint the gone job again, a 404 of one history entry read as a gone job (History prunes entries of a job that lives: 5 + 45 per cycle, 200 per job), a redaction error of any read that keeps job data, a failed poll that drops the last good detail, and a failed history read that closes an open sheet.
test('readFailureState decides what a failed read does to the screen', () => {
    for (const source of ['detail', 'history', 'cycle']) {
        assert.deepEqual(readFailureState('not_found', source, true), {next: 'gone', keepDetail: false, closeSheet: true, invalidate: ['detail', 'history', 'entry']}, source);
    }

    for (const source of ['detail', 'history', 'entry', 'cycle']) {
        assert.deepEqual(readFailureState('redaction', source, true), {next: 'redaction', keepDetail: false, closeSheet: true}, source);
    }

    assert.deepEqual(readFailureState('not_found', 'entry', true), {next: 'section_error', keepDetail: true, closeSheet: false});

    assert.deepEqual(readFailureState('failed', 'detail', true), {next: 'stale', keepDetail: true, closeSheet: false});
    assert.deepEqual(readFailureState('failed', 'detail', false), {next: 'failure', keepDetail: false, closeSheet: false});
    assert.deepEqual(readFailureState('failed', 'history', true), {next: 'section_error', keepDetail: true, closeSheet: false});
    assert.deepEqual(readFailureState('failed', 'entry', true), {next: 'section_error', keepDetail: true, closeSheet: false});
});

// Rejects: a detail read whose history section is null (it could not be read) that drops the last good page or reads as an empty history, and a good section that stays stale.
test('historyFromDetail keeps the last good page when the section could not be read', () => {
    const last = {entries: [{id: 3}], next_cursor: null, history_truncated: false, history_discarded: 0};
    const fresh = {entries: [{id: 4}, {id: 3}], next_cursor: null, history_truncated: false, history_discarded: 0};

    assert.deepEqual(historyFromDetail(last, fresh), {page: fresh, error: false});
    assert.deepEqual(historyFromDetail(last, null), {page: last, error: true});
    assert.deepEqual(historyFromDetail(null, null), {page: null, error: true});
    assert.deepEqual(historyFromDetail(last, {entries: 'x'}), {page: last, error: true}, 'a section of another shape is not a page');
});

// Rejects: a history answer of another shape taken as a page (the screen would read entries of nothing).
test('historyPageOf accepts only the shape of a history page', () => {
    const page = {entries: [], next_cursor: null, history_truncated: false, history_discarded: 0};

    assert.equal(historyPageOf(page), page);
    assert.equal(historyPageOf({entries: null}), null);
    assert.equal(historyPageOf('<html>'), null);
    assert.equal(historyPageOf(null), null);
    assert.equal(historyPageOf({entries: [], next_cursor: 5}), null, 'a cursor is a string or null');
});

// Rejects: a cached "earlier exceptions" list of the current cycle kept while the cycle still runs, or after a newer history entry arrived; and a cache dropped on every poll of a finished cycle.
test('cycleHistoryToDrop names the cached cycle that a new read makes stale', () => {
    const detail = (outcome, newest) => ({cycle: 3, cycles: [{cycle: 3, current: true, outcome}, {cycle: 2}], history: newest === undefined ? null : {entries: newest === null ? [] : [{id: newest}]}});

    assert.equal(cycleHistoryToDrop(detail('in_progress', 9), detail('in_progress', 9)), 3);
    assert.equal(cycleHistoryToDrop(detail('failed', 9), detail('failed', 10)), 3);
    assert.equal(cycleHistoryToDrop(detail('failed', 9), detail('failed', 9)), null);
    assert.equal(cycleHistoryToDrop(detail('completed', null), detail('completed', null)), null);
    assert.equal(cycleHistoryToDrop(detail('failed', 9), detail('failed', undefined)), 3, 'an unread history cannot prove that nothing changed');
    assert.equal(cycleHistoryToDrop(null, detail('failed', 9)), null);
    assert.equal(cycleHistoryToDrop(detail('failed', 9), {cycle: 4, cycles: null, history: null}), 4);
});

// Rejects: a bidi override or another invisible format character drawn as it is (it can reorder or hide the text around it), and a text without one changed.
test('visibleText escapes bidi and format control characters', () => {
    assert.equal(visibleText('invoice\u202Efdp.exe'), 'invoice\\u202Efdp.exe');
    assert.equal(visibleText('a\u2066b\u2069c\u200E\u200F'), 'a\\u2066b\\u2069c\\u200E\\u200F');
    assert.equal(visibleText('\u202A\u202B\u202C\u202D'), '\\u202A\\u202B\\u202C\\u202D');
    assert.equal(visibleText('plain ąčę → ok'), 'plain ąčę → ok');
    assert.equal(treeNode('x\u202Ey').text, 'x\\u202Ey');
    assert.equal(treeNode({k: 1}).children[0].key, 'k');
    assert.equal(treeNode({['a\u202E']: 1}).children[0].key, 'a\\u202E');
    assert.equal(treeNode({array: [{key: 'b\u2067', value: 1}]}).children[0].key, 'b\\u2067');
    assert.equal(treeNode({class: 'X\u202E', properties: [{name: 'p\u202D', visibility: 'public', value: 1}]}).className, 'X\\u202E');
    assert.equal(treeNode({class: 'X', properties: [{name: 'p\u202D', visibility: 'public', value: 1}]}).children[0].key, 'p\\u202D');
    assert.equal(treeNode({enum: 'E::\u202EA'}).text, 'E::\\u202EA');
    assert.equal(visibleText('a\u061Cb'), 'a\\u061Cb', 'the Arabic letter mark is a bidi control too');
    assert.equal(treeNode({class: 'X', properties: [{name: 'p', visibility: 'private', declared_in: 'Y\u202E', value: 1}]}).children[0].declaredIn, 'Y\\u202E');
    assert.equal(treeSummary(treeNode({class: 'Illuminate\\Contracts\\Database\\ModelIdentifier', properties: [
        {name: 'class', visibility: 'public', value: 'App\\M\u202Eodel'},
        {name: 'id', visibility: 'public', value: 'a\u2066b'},
    ]})), 'App\\M\\u202Eodel #a\\u2066b');
    assert.equal(visibleText(null), null);
});

// Rejects: a reload of a running cycle's earlier exceptions that blanks the list until it answers, "Loading…" over a list that is already there, a failed reload that drops the list (or hides that it is stale), and an answer of another shape taken as entries.
test('cycleHistoryAfter keeps the last entries while a cycle history reloads', () => {
    const page = entries => ({entries, next_cursor: null, history_truncated: false, history_discarded: 0});
    const first = cycleHistoryAfter(undefined, 'loading');
    assert.deepEqual(first, {entries: null, loading: true, error: false});

    const loaded = cycleHistoryAfter(first, {page: page([{id: 1}])});
    assert.deepEqual(loaded, {entries: [{id: 1}], loading: false, error: false});

    const reloading = cycleHistoryAfter(loaded, 'loading');
    assert.deepEqual(reloading, {entries: [{id: 1}], loading: true, error: false});

    assert.deepEqual(cycleHistoryAfter(reloading, {failed: true}), {entries: [{id: 1}], loading: false, error: true});
    assert.deepEqual(cycleHistoryAfter(reloading, {page: '<html>'}), {entries: [{id: 1}], loading: false, error: true});
    assert.deepEqual(cycleHistoryAfter(first, {failed: true}), {entries: null, loading: false, error: true});
    assert.deepEqual(cycleHistoryAfter({entries: [{id: 1}], loading: false, error: true}, {page: page([{id: 2}, {id: 1}])}), {entries: [{id: 2}, {id: 1}], loading: false, error: false});
});

// Rejects: "Loading…" while a cached list exists, a failed first read shown as "none observed", and a stale list without its notice.
test('earlierView words the earlier exceptions of a cycle', () => {
    const entries = [{id: 9, cycle: 2, event: 'failed'}, {id: 8, cycle: 2, event: 'exception'}];

    assert.deepEqual(earlierView(undefined, 2), {status: 'loading', entries: [], stale: false});
    assert.deepEqual(earlierView({entries: null, loading: true, error: false}, 2), {status: 'loading', entries: [], stale: false});
    assert.deepEqual(earlierView({entries: null, loading: false, error: true}, 2), {status: 'error', entries: [], stale: false});
    assert.deepEqual(earlierView({entries, loading: true, error: false}, 2), {status: 'list', entries: [entries[1]], stale: false});
    assert.deepEqual(earlierView({entries, loading: false, error: true}, 2), {status: 'list', entries: [entries[1]], stale: true});
    assert.deepEqual(earlierView({entries: [entries[0]], loading: false, error: false}, 2), {status: 'none', entries: [], stale: false});
});

// Rejects: a forget answer whose row survived (row_moved: the row moved on; mirror_pending: the runner removes it) read as "Forgotten: this job is no longer kept".
test('forgetRemovedRow is true only when the forget removed the row', () => {
    assert.equal(forgetRemovedRow({result: 'deleted', code: null}), true);
    assert.equal(forgetRemovedRow({result: 'already_done', code: null}), true);
    assert.equal(forgetRemovedRow({result: 'deleted', code: 'row_moved'}), false);
    assert.equal(forgetRemovedRow({result: 'mirror_pending', code: null}), false);
    assert.equal(forgetRemovedRow({result: 'refused', code: 'provider_read_only'}), false);
    assert.equal(forgetRemovedRow(null), false);
});

// Rejects: the server's wrapper of a natural object (JobProjection::natural()) drawn as a map with one key
// "map" instead of the object it wraps, real data {"ref": 48213} inside it drawn as a marker, and an
// object that has a "map" key next to another key read as a wrapper.
test('treeNode unwraps a natural object that the server wrapped', () => {
    const node = treeNode({map: {ref: 48213}});

    assert.equal(node.kind, 'container');
    assert.equal(node.shape, 'map');
    assert.deepEqual(node.children.map(child => [child.key, child.value]), [['ref', 48213]]);
    assert.equal(treeNode(node.children[0].value).kind, 'scalar');

    // The server's form of the natural {"map": {"ref": 1}}: both objects have only shape keys, so both are wrapped.
    const own = treeNode({map: {map: {map: {ref: 1}}}});

    assert.deepEqual(own.children.map(child => child.key), ['map']);
    assert.deepEqual(treeNode(own.children[0].value).children.map(child => [child.key, child.value]), [['ref', 1]]);
    assert.deepEqual(treeNode({map: 1, other: 2}).children.map(child => child.key), ['map', 'other']);
    assert.equal(treeNode({ref: 48213}).kind, 'marker');
});

function pendingDetail(over = {}) {
    return failedDetail({status: 'pending', reserved_at: null, finished_at: null, failed_at: null, exception_class: null, exception_message: null, chained: false,
        actions: {retry: false, forget: false, resolve: false, read_only_reason: null, delete: true, pending_reason: null}, ...over});
}

// Rejects: a Delete offered to a viewer, on a row that is not pending, beside an operation, or without the server's
// consent; and an unresolved bulk row whose Delete is enabled (it must show disabled, with its reason).
test('actionsFor offers Delete on a waiting job only', () => {
    assert.deepEqual(actionsFor(pendingDetail(), true), {retry: false, forget: false, resolve: false, delete: true, deleteBlocked: null, held: false, reason: null});
    assert.equal(actionsFor(pendingDetail(), false).delete, false);
    assert.equal(actionsFor(pendingDetail({status: 'reserved'}), true).delete, false);
    assert.equal(actionsFor(pendingDetail({operation: {id: OP, action: 'retry', state: 'claimed', awaiting_reason: null}}), true).delete, false);
    assert.equal(actionsFor(pendingDetail({actions: {delete: false}}), true).delete, false);

    const blocked = actionsFor(pendingDetail({actions: {delete: true, pending_reason: 'job_id_unknown'}}), true);
    assert.equal(blocked.delete, true);
    assert.match(blocked.deleteBlocked, /Try again in a minute/);
});

// Rejects: a delete sheet that misses the job, the batch that never finishes (with its Cancel instead), the dropped
// chain, or that claims "no chain" when the chain is unknown; and a selection that is not the page's uuid.
test('deleteSheet names the job, its batch and its chain', () => {
    const plain = deleteSheet(pendingDetail());
    assert.equal(plain.title, 'Delete this waiting job?');
    assert.match(plain.message, /^App\\Jobs\\SendInvoice · 3f9b0c12 on database:emails\. .*This cannot be undone\.$/);
    assert.equal(plain.note, null);
    assert.equal(plain.tone, 'danger');
    assert.deepEqual(plain.selection, {action: 'delete', uuid: UUID, batchLink: null});

    const batched = deleteSheet(pendingDetail({batch_id: 'b-1', batch: {id: 'b-1', name: 'Invoices'}, chained: true}));
    assert.match(batched.note, /belongs to the batch Invoices\. After the delete, that batch never finishes\. To stop the whole batch, cancel the batch instead\./);
    assert.match(batched.note, /the rest of the chain is dropped and never runs/);
    assert.equal(batched.selection.batchLink, '/batches/b-1');

    assert.match(deleteSheet(pendingDetail({chained: null})).note, /^Whether this job carries a chain is unknown/);
});

// Rejects: a delete or run-now posted to another path, or with a uuid that is not one.
test('writeRequest posts a delete and a run-now of the frozen uuid', () => {
    assert.deepEqual(writeRequest({action: 'delete', uuid: UUID.toUpperCase()}), {url: `/api/jobs/${UUID}/delete`, body: {}});
    assert.deepEqual(writeRequest({action: 'run_now', uuid: UUID}), {url: `/api/jobs/${UUID}/run-now`, body: {}});
    assert.equal(writeRequest({action: 'delete', uuid: 'abc'}), null);
});

// Rejects: a delete result that falls to the generic "outcome unknown", a refused delete shown as success, and a
// mirror_failed shown as plain success.
test('writeOutcome words every delete result', () => {
    assert.equal(writeOutcome('delete', {result: 'deleted'}).tone, 'success');
    assert.equal(writeOutcome('delete', {result: 'not_pending'}).tone, 'danger');
    assert.equal(writeOutcome('delete', {result: 'gone'}).tone, 'danger');
    assert.match(writeOutcome('delete', {result: 'job_id_unknown'}).detail, /Try again in a minute/);
    assert.equal(writeOutcome('delete', {result: 'mirror_failed'}).tone, 'warning');
    assert.equal(writeOutcome('delete', {result: 'other'}).title, 'Outcome unknown');
    assert.equal(writeFailure('delete', {response: {status: 409, data: {error: 'not_database_queue'}}}).title, 'Not deleted');
    assert.match(notFoundState(null, false, true).title, /^Deleted/);
});
