// Tests of resources/js/errors.js: which failed requests reach the global alert.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {describeFailure, failureKind} from '../../resources/js/errors.js';

const timeout = (handles) => ({code: 'ECONNABORTED', message: 'timeout of 30000ms exceeded', config: {handles}});
const network = (handles) => ({code: 'ERR_NETWORK', message: 'Network Error', config: {handles}});
const status = (code, handles) => ({response: {status: code, data: {}}, config: {handles}});

// Rejects: a timeout read as a network failure, a status error read as either, and ETIMEDOUT missed.
test('failureKind tells a timeout, a network failure and an answered request apart', () => {
    assert.equal(failureKind(timeout()), 'timeout');
    assert.equal(failureKind({code: 'ETIMEDOUT', config: {}}), 'timeout');
    assert.equal(failureKind(network()), 'network');
    assert.equal(failureKind(status(500)), null);
    assert.equal(failureKind(null), 'network');
});

// Rejects: a background poll that pops the global alert on every hung read (CLAUDE.md, the SPA rules),
// and a request that handles timeouts and so also hides network failures, or the reverse.
test('a request that handles timeouts or network failures raises no global alert for them', () => {
    assert.equal(describeFailure(timeout(['timeout'])), null);
    assert.equal(describeFailure(network(['network'])), null);
    assert.equal(describeFailure(timeout([500, 'timeout', 'network'])), null);

    assert.equal(describeFailure(network(['timeout'])).type, 'error');
    assert.equal(describeFailure(timeout(['network'])).type, 'error');
});

// Rejects: user-started requests and writes that lose the global alert on a timeout or a network failure,
// and 401 or 419 hidden by a background declaration.
test('requests that do not declare them keep the global alert', () => {
    assert.match(describeFailure(timeout()).message, /could not be reached|did not answer/);
    assert.match(describeFailure(timeout([404])).message, /did not answer/);
    assert.match(describeFailure(network([404])).message, /could not be reached/);
    assert.equal(describeFailure(status(419, [500, 'timeout', 'network'])).type, 'reload');
    assert.equal(describeFailure(status(500, ['timeout', 'network'])).type, 'error');
    assert.equal(describeFailure(status(500, [500])), null);
});

// Rejects: a browser abort read as a timeout ("did not answer in time"), and an abort that alerts a poll.
test('an aborted request is not a timeout', () => {
    const aborted = handles => ({code: 'ECONNABORTED', message: 'Request aborted', config: {handles}});

    assert.equal(failureKind(aborted()), 'aborted');
    assert.equal(failureKind({code: 'ECONNABORTED', message: 'timeout of 30000ms exceeded'}), 'timeout');
    assert.doesNotMatch(describeFailure(aborted()).message, /in time/);
    assert.equal(describeFailure(aborted(['timeout', 'network', 'aborted'])), null);
});

// The 401/403 split: the terminal session-expired and access-lost states.

import {beforeEach} from 'node:test';
import {
    ACCESS_LOST,
    authFailure,
    FORBIDDEN_ACTION,
    installErrorHandling,
    isReadRequest,
    readSignal,
    resetTerminalForTesting,
    SESSION_EXPIRED,
    terminalState,
} from '../../resources/js/errors.js';

const answer = (code, method, data = {}, handles = []) => ({response: {status: code, data}, config: {method, handles}});

beforeEach(() => resetTerminalForTesting());

// Rejects: a 403 read as one state (the old single "not allowed" alert), a refused action that ends the
// session, a 419 on a read made terminal, and a 403 without a known code decided without its method.
test('authFailure tells the session-expired, access-lost and refused-action cases apart', () => {
    assert.equal(authFailure(answer(401, 'get')), SESSION_EXPIRED);
    assert.equal(authFailure(answer(401, 'post')), SESSION_EXPIRED);
    assert.equal(authFailure(answer(419, 'post')), SESSION_EXPIRED);
    assert.equal(authFailure(answer(419, 'delete')), SESSION_EXPIRED);
    assert.equal(authFailure(answer(419, 'get')), null);

    assert.equal(authFailure(answer(403, 'get', {error: 'forbidden'})), ACCESS_LOST);
    assert.equal(authFailure(answer(403, 'post', {error: 'forbidden'})), ACCESS_LOST);
    assert.equal(authFailure(answer(403, 'post', {error: 'forbidden_action'})), FORBIDDEN_ACTION);

    // A 403 without a known code (a host middleware answered it).
    assert.equal(authFailure(answer(403, 'get', '<html>Forbidden</html>')), ACCESS_LOST);
    assert.equal(authFailure(answer(403, 'head', {message: 'no'})), ACCESS_LOST);
    assert.equal(authFailure(answer(403, 'post', {message: 'This action is unauthorized.'})), FORBIDDEN_ACTION);
    assert.equal(authFailure(answer(403, 'patch', null)), FORBIDDEN_ACTION);
    assert.equal(authFailure(answer(403, undefined, {error: 'something_else'})), ACCESS_LOST, 'a request without a method is a GET');

    assert.equal(authFailure(answer(500, 'get')), null);
    assert.equal(authFailure({code: 'ERR_NETWORK', config: {}}), null);
});

// Rejects: a background read whose `handles` hides an expired session or lost access (the poll would
// keep failing in silence), and one text for both terminal states.
test('the terminal states reach the alert whatever the request handles', () => {
    const all = [401, 403, 419, 500, 'timeout', 'network', 'aborted'];
    const expired = describeFailure(answer(401, 'get', {}, all));
    const lost = describeFailure(answer(403, 'get', {error: 'forbidden'}, all));
    const action = describeFailure(answer(403, 'post', {error: 'forbidden_action'}, [403]));

    assert.deepEqual([expired.type, expired.terminal], ['reload', SESSION_EXPIRED]);
    assert.deepEqual([lost.type, lost.terminal], ['reload', ACCESS_LOST]);
    assert.notEqual(expired.message, lost.message);
    assert.equal(action.type, 'error');
    assert.equal(action.terminal, undefined);
    assert.match(action.message, /not allowed/);

    // 419 on a read keeps the old reload alert, and it is not terminal.
    const stale = describeFailure(answer(419, 'get'));
    assert.equal(stale.type, 'reload');
    assert.equal(stale.terminal, undefined);
});

// Rejects: treating a write as a read (it would be aborted with an unknown outcome), and missing a read POST.
test('isReadRequest names the safe methods and the read-only POSTs', () => {
    assert.ok(isReadRequest({method: 'get', url: '/x'}));
    assert.ok(isReadRequest({url: '/x'}));
    assert.ok(isReadRequest({method: 'HEAD', url: '/x'}));
    // The bulk freeze only reads: it gets the read timeout and is aborted in a terminal state.
    assert.ok(isReadRequest({method: 'post', url: '/orrery/api/bulk/freeze'}));
    assert.ok(isReadRequest({method: 'post', url: '/orrery/api/bulk/freeze?x=1'}));
    assert.ok(!isReadRequest({method: 'post', url: '/orrery/api/bulk/run'}), 'a bulk run page writes');
    assert.ok(!isReadRequest({method: 'post', url: '/orrery/api/jobs/00000000-0000-4000-8000-000000000001/retry'}));
    assert.ok(!isReadRequest({method: 'delete', url: '/orrery/api/tags/monitored/x'}));
    assert.ok(isReadRequest(null), 'a request without a method is a GET');
});

/** A fake client (support/http.js): the interceptors that installErrorHandling registers. */
function fakeHttp() {
    const http = {request: null, rejected: null};

    http.interceptors = {
        request: {use: fulfilled => { http.request = fulfilled; }},
        response: {use: (fulfilled, rejected) => { http.rejected = rejected; }},
    };

    return http;
}

function fakeRoot() {
    return {alert: {type: null, message: ''}, lastDismissedAlert: null};
}

// Rejects: a session-expired state that lets pending reads finish or new polls start, that aborts a
// write (its outcome would be unknown), that shows a second alert over the first, or that a dismissed
// copy of the same text hides.
test('a 401 aborts every pending read, refuses new reads and shows one reload alert', async () => {
    const http = fakeHttp();
    const root = fakeRoot();

    installErrorHandling(http, () => root);

    const pending = http.request({method: 'get', url: '/orrery/api/workload'});
    const write = http.request({method: 'post', url: '/orrery/api/control'});

    assert.equal(pending.signal.aborted, false);
    assert.equal(write.signal, undefined, 'a write gets no abort signal');

    root.lastDismissedAlert = {message: describeFailure(answer(401, 'get')).message, at: Date.now()};
    root.alert.type = 'error';
    root.alert.message = 'An older alert.';

    await assert.rejects(http.rejected(answer(401, 'get')));

    assert.equal(terminalState(), SESSION_EXPIRED);
    assert.equal(pending.signal.aborted, true);
    assert.equal(root.alert.type, 'reload');
    assert.match(root.alert.message, /session has expired/);
    assert.equal(typeof root.alert.confirmationProceed, 'function');

    // A later read never goes out, and its refusal raises no alert.
    const refusal = await http.request({method: 'get', url: '/orrery/api/workers'}).then(() => null, error => error);
    assert.ok(refusal && refusal.__CANCEL__, 'a read after the terminal state is refused');
    assert.equal(describeFailure(refusal), null);

    // A write still goes out.
    assert.equal(http.request({method: 'post', url: '/orrery/api/control'}).method, 'post');

    // A later 403 does not replace the session-expired alert.
    const message = root.alert.message;
    await assert.rejects(http.rejected(answer(403, 'get', {error: 'forbidden'})));
    assert.equal(root.alert.message, message);
    assert.equal(terminalState(), SESSION_EXPIRED);
});

// Rejects: an access-lost state that keeps polling, and one with the session-expired text.
test('a 403 forbidden enters the access-lost state', async () => {
    const http = fakeHttp();
    const root = fakeRoot();

    installErrorHandling(http, () => root);

    const pending = http.request({method: 'post', url: '/orrery/api/bulk/freeze'});

    await assert.rejects(http.rejected(answer(403, 'get', {error: 'forbidden'}, [403])));

    assert.equal(terminalState(), ACCESS_LOST);
    assert.equal(pending.signal.aborted, true, 'a read POST is aborted too');
    assert.equal(root.alert.type, 'reload');
    assert.match(root.alert.message, /no longer have access/);
});

// Rejects: a refused action that stops the polls or aborts the reads (only that action is refused).
test('a 403 forbidden_action shows one alert and the reads continue', async () => {
    const http = fakeHttp();
    const root = fakeRoot();

    installErrorHandling(http, () => root);

    const pending = http.request({method: 'get', url: '/orrery/api/workload'});

    await assert.rejects(http.rejected(answer(403, 'post', {error: 'forbidden_action'})));

    assert.equal(terminalState(), null);
    assert.equal(pending.signal.aborted, false);
    assert.equal(root.alert.type, 'error');
    assert.equal(http.request({method: 'get', url: '/orrery/api/workers'}).url, '/orrery/api/workers');
    assert.equal(readSignal().aborted, false);
});

// Rejects: a read that brings its own signal and then escapes the terminal abort, and one whose own
// signal no longer cancels it (the caller's cancel lost).
test('a read with its own signal is aborted by its caller and by a terminal state', async () => {
    const http = fakeHttp();
    const root = fakeRoot();
    const own = new AbortController();
    const other = new AbortController();

    installErrorHandling(http, () => root);

    const first = http.request({method: 'get', url: '/x', signal: own.signal});
    const second = http.request({method: 'get', url: '/y', signal: other.signal});

    own.abort();
    assert.equal(first.signal.aborted, true, 'the caller still cancels its read');
    assert.equal(second.signal.aborted, false);

    await assert.rejects(http.rejected(answer(401, 'get')));
    assert.equal(second.signal.aborted, true, 'the terminal state aborts a read with its own signal');
});

// Rejects: a terminal state whose alert, once dismissed, never comes back when the user tries a write
// (the write fails in silence), and one that shows it again for a refused read.
test('a write that fails in a terminal state shows the terminal alert again', async () => {
    const http = fakeHttp();
    const root = fakeRoot();

    installErrorHandling(http, () => root);

    await assert.rejects(http.rejected(answer(403, 'get', {error: 'forbidden'})));
    const message = root.alert.message;

    // The user closes the alert (AlertHost).
    root.lastDismissedAlert = {message, at: Date.now()};
    root.alert.type = null;
    root.alert.message = '';

    const refusal = await http.request({method: 'get', url: '/orrery/api/workers'}).then(() => null, error => error);
    await assert.rejects(http.rejected(refusal));
    assert.equal(root.alert.type, null, 'a refused read shows nothing');

    await assert.rejects(http.rejected(answer(500, 'post')));
    assert.deepEqual([root.alert.type, root.alert.message], ['reload', message]);

    // Over an open alert, nothing changes.
    root.alert.message = 'Another alert.';
    await assert.rejects(http.rejected({code: 'ERR_NETWORK', message: 'Network Error', config: {method: 'post'}}));
    assert.equal(root.alert.message, 'Another alert.');
});

// Rejects: a refused action hidden because the same text was dismissed less than 30 s ago (each
// refused action is the user's own click, not a repeating poll).
test('every refused action shows its alert', async () => {
    const http = fakeHttp();
    const root = fakeRoot();

    installErrorHandling(http, () => root);

    await assert.rejects(http.rejected(answer(403, 'post', {error: 'forbidden_action'})));
    const message = root.alert.message;

    root.lastDismissedAlert = {message, at: Date.now()};
    root.alert.type = null;
    root.alert.message = '';

    await assert.rejects(http.rejected(answer(403, 'delete', {error: 'forbidden_action'})));
    assert.deepEqual([root.alert.type, root.alert.message], ['error', message]);
    assert.equal(terminalState(), null);
});

// Rejects: a 403 `header_missing` (the request came without X-Requested-With) worded as a permission problem
// ("You are not allowed to do this."), and a 503 `record_failed` that raises the global "request failed" alert
// beside the screen's own warning (the change happened).
// Still passes: a `header_missing` alert in any wording that names the header.
test('header_missing and record_failed have their own handling', () => {
    assert.equal(authFailure(answer(403, 'post', {error: 'header_missing'})), null);

    const header = describeFailure(answer(403, 'post', {error: 'header_missing'}));
    assert.equal(header.type, 'error');
    assert.equal(header.action, true);
    assert.match(header.message, /X-Requested-With/);
    assert.doesNotMatch(header.message, /not allowed/);

    assert.equal(describeFailure(answer(503, 'post', {error: 'record_failed', paused: true, message: 'The queue is paused.'})), null);
    assert.match(describeFailure(answer(503, 'post', {error: 'write_failed', message: 'The change may not have been saved.'})).message, /HTTP 503/);
});
