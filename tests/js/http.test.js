// Tests of resources/js/support/http.js: the fetch client of the dashboard
// (the shared `$http` of app.js), with a fake fetch.

import {test} from 'node:test';
import assert from 'node:assert/strict';

import {createHttp, queryUrl} from '../../resources/js/support/http.js';
import {createPrefetchCache} from '../../resources/js/support/prefetch.js';
import {describeFailure, enterTerminal, failureKind, installErrorHandling, resetTerminalForTesting} from '../../resources/js/errors.js';

/** A fake fetch: records each call and answers with `answer(url, init)` (a Response or a promise). */
function fakeFetch(answer = () => new Response('{}', {status: 200})) {
    const calls = [];
    const fetch = (url, init) => {
        calls.push({url, init});

        return answer(url, init);
    };

    return {fetch, calls};
}

/** A fetch that never answers until its signal aborts, as a hung server. */
const hangs = (url, init) => new Promise((resolve, reject) => {
    init.signal.addEventListener('abort', () => reject(new DOMException('The operation was aborted.', 'AbortError')));
});

/** Rejection of a promise, or a failed assertion when it resolves. */
async function rejection(promise) {
    try {
        await promise;
    } catch (error) {
        return error;
    }

    assert.fail('the request resolved');
}

// Rejects: a client that drops X-Requested-With (every write needs it, else 403 header_missing), lets a request
// config replace it, drops the instance headers (the CSRF token) or the Accept header that axios sent.
// Still passes: a client that sends the header from a fetch that a browser extension rewrites (out of reach).
test('every request sends X-Requested-With, the Accept header and the instance headers', async () => {
    const {fetch, calls} = fakeFetch();
    const http = createHttp({headers: {'X-CSRF-TOKEN': 'token'}, fetch});

    await http.get('/orrery/api/workload');
    await http.post('/orrery/api/control', {action: 'pause'}, {headers: {'X-Requested-With': 'nothing'}});
    await http.request({method: 'delete', url: '/orrery/api/tags/monitored', params: {tag: 'a'}});

    assert.equal(calls.length, 3);

    for (const {init} of calls) {
        assert.equal(init.headers['X-Requested-With'], 'XMLHttpRequest');
        assert.equal(init.headers['X-CSRF-TOKEN'], 'token');
        assert.equal(init.headers.Accept, 'application/json, text/plain, */*');
        assert.equal(init.credentials, 'same-origin');
    }

    assert.deepEqual(calls.map(call => call.init.method), ['GET', 'POST', 'DELETE']);
});

// Rejects: a write whose body is not JSON or has no JSON content type, and a read that sends a body.
test('a body is sent as JSON with its content type, and a read sends none', async () => {
    const {fetch, calls} = fakeFetch();
    const http = createHttp({fetch});

    await http.post('/api/bulk/run', {token: 'abc', after: null});
    await http.get('/api/bulk');

    assert.equal(calls[0].init.body, '{"token":"abc","after":null}');
    assert.equal(calls[0].init.headers['Content-Type'], 'application/json');
    assert.equal(calls[1].init.body, undefined);
    assert.equal(calls[1].init.headers['Content-Type'], undefined);
});

// Rejects: a query that sends null or undefined values ("null" as a filter), drops arrays or encodes nothing,
// and one that adds a second "?" to a URL that already has a query.
test('queryUrl adds the params to the URL, as axios did', () => {
    assert.equal(queryUrl('/api/jobs', {status: 'failed', queue: 'a b/c', cursor: null, per_page: 50, skip: undefined}),
        '/api/jobs?status=failed&queue=a+b%2Fc&per_page=50');
    assert.equal(queryUrl('/api/jobs', {ids: [1, 2]}), '/api/jobs?ids%5B%5D=1&ids%5B%5D=2');
    assert.equal(queryUrl('/api/jobs?x=1', {y: true}), '/api/jobs?x=1&y=true');
    assert.equal(queryUrl('/api/jobs', {}), '/api/jobs');
    assert.equal(queryUrl('/api/jobs', undefined), '/api/jobs');
});

// Rejects: a client that ignores `params` on a request.
test('a request sends its params in the URL', async () => {
    const {fetch, calls} = fakeFetch();

    await createHttp({fetch}).get('/api/tags', {params: {tag: 'x'}});

    assert.equal(calls[0].url, '/api/tags?tag=x');
});

// Rejects: an answer without the axios shape that the screens read (data, status, config), a JSON body left as
// text, and a body that is no JSON thrown away (axios keeps the text).
test('a 2xx answer resolves with data, status and the config', async () => {
    const answers = ['{"rows":[1]}', 'plain text', ''];
    const {fetch} = fakeFetch(() => new Response(answers.shift(), {status: 200}));
    const http = createHttp({fetch});

    const json = await http.get('/api/a', {handles: [404]});
    assert.deepEqual(json.data, {rows: [1]});
    assert.equal(json.status, 200);
    assert.deepEqual(json.config.handles, [404]);
    assert.equal(json.config.url, '/api/a');

    assert.equal((await http.get('/api/b')).data, 'plain text');
    assert.equal((await http.get('/api/c')).data, '');
});

// Rejects: a failed answer that resolves, or that rejects without error.response.status, the parsed body in
// error.response.data or the request's `handles` in error.config (errors.js and every screen read these).
test('an error status rejects with response.status, response.data and the config', async () => {
    const {fetch} = fakeFetch((url) => new Response(JSON.stringify({error: 'invalid', message: 'Bad field.'}), {status: url.endsWith('422') ? 422 : 500}));
    const http = createHttp({fetch});

    const handled = await rejection(http.get('/api/x/422', {handles: [422]}));
    assert.ok(handled instanceof Error);
    assert.equal(handled.response.status, 422);
    assert.deepEqual(handled.response.data, {error: 'invalid', message: 'Bad field.'});
    assert.deepEqual(handled.config.handles, [422]);
    assert.equal(handled.code, 'ERR_BAD_REQUEST');
    assert.equal(failureKind(handled), null);
    assert.equal(describeFailure(handled), null);

    const server = await rejection(http.post('/api/x/500', {}));
    assert.equal(server.code, 'ERR_BAD_RESPONSE');
    assert.equal(describeFailure(server).message, 'The request failed (HTTP 500). Bad field.');
});

// Rejects: a client without timeouts (a hung poll never settles), a timeout read as a network failure or a
// cancel, and a write that gets a timeout it did not ask for.
test('a request with a timeout fails as a timeout when the server does not answer', async () => {
    const http = createHttp({fetch: hangs});

    const error = await rejection(http.get('/api/workload', {timeout: 20, handles: ['timeout']}));

    assert.equal(failureKind(error), 'timeout');
    assert.equal(error.response, undefined);
    assert.equal(describeFailure(error), null, 'the read handles its timeout');
    assert.equal(describeFailure({...error, config: {}}).message, 'The server did not answer in time. Try again.');
});

// Rejects: a caller's abort that raises an alert (a cancel is no failure), one that is read as a timeout, and a
// request that goes out although its signal is already aborted.
test('an aborted signal cancels the request without an alert', async () => {
    const http = createHttp({fetch: hangs});
    const controller = new AbortController();
    const pending = http.post('/api/bulk/freeze', {}, {signal: controller.signal, timeout: 1000});

    controller.abort();
    const error = await rejection(pending);

    assert.equal(error.__CANCEL__, true);
    assert.equal(error.code, 'ERR_CANCELED');
    assert.equal(describeFailure(error), null);

    const {fetch, calls} = fakeFetch();
    const early = await rejection(createHttp({fetch}).get('/api/a', {signal: AbortSignal.abort()}));

    assert.equal(early.__CANCEL__, true);
    assert.equal(calls.length, 0);
});

// Rejects: a fetch failure without an answer read as anything but a network failure.
test('a request that gets no answer fails as a network failure', async () => {
    const http = createHttp({fetch: () => Promise.reject(new TypeError('Failed to fetch'))});

    const error = await rejection(http.get('/api/a'));

    assert.equal(error.code, 'ERR_NETWORK');
    assert.equal(failureKind(error), 'network');
    assert.equal(describeFailure(error).message, 'The server could not be reached. Check your connection and try again.');
});

// Rejects: request interceptors that cannot change the config (app.js sets the timeout there), that run in
// another order than axios (the last one added runs first), a request interceptor's rejection that skips the
// response interceptors (errors.js shows its alert there), and response interceptors that never run.
test('interceptors run as in axios', async () => {
    const {fetch, calls} = fakeFetch();
    const http = createHttp({fetch});
    const order = [];

    http.interceptors.request.use(config => {
        order.push('first added');

        return {...config, params: {from: 'first'}};
    });
    http.interceptors.request.use(config => {
        order.push('last added');

        return config;
    });
    http.interceptors.response.use(response => ({...response, data: 'changed'}));

    const response = await http.get('/api/a');

    assert.deepEqual(order, ['last added', 'first added']);
    assert.equal(calls[0].url, '/api/a?from=first');
    assert.equal(response.data, 'changed');

    const refusing = createHttp({fetch});
    const seen = [];

    refusing.interceptors.request.use(() => Promise.reject({__CANCEL__: true}));
    refusing.interceptors.response.use(undefined, error => {
        seen.push(error);

        return Promise.reject(error);
    });

    const refused = await rejection(refusing.get('/api/b'));

    assert.equal(refused.__CANCEL__, true);
    assert.deepEqual(seen, [refused]);
    assert.equal(calls.length, 1, 'the refused request never went out');
});

// Rejects: a client on which errors.js cannot refuse reads in a terminal state, or does not hand each read the
// abort signal (a pending read survives the terminal state).
test('errors.js installs its interceptors on the client', async (t) => {
    t.after(resetTerminalForTesting);
    resetTerminalForTesting();

    const {fetch, calls} = fakeFetch((url, init) => (url === '/api/hang' ? hangs(url, init) : new Response('{}', {status: 200})));
    const http = createHttp({fetch});

    installErrorHandling(http, () => null);

    await http.get('/api/a');
    assert.ok(calls[0].init.signal instanceof AbortSignal);

    const pending = rejection(http.get('/api/hang'));
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(calls.length, 2, 'the read is pending at the server');
    enterTerminal('session_expired');
    const aborted = await pending;

    assert.equal(aborted.__CANCEL__, true, 'the terminal state aborts the pending read');

    const refused = await rejection(http.get('/api/b'));

    assert.equal(refused.__CANCEL__, true);
    assert.equal(calls.length, 2, 'the read in the terminal state never went out');
});

// Rejects: a screen's first GET that goes to the network again after the navigation prefetched it (fetched twice),
// a second GET that reuses the answer (a poll would show old data), a write that takes a prefetched answer, or a
// prefetch that is not abortable (a cancelled navigation keeps its request running).
// Still passes: a client that also takes a prefetched answer for a GET with the params in another order.
test('prefetch: the first GET of the same read takes the prefetched answer, once', async () => {
    const {fetch, calls} = fakeFetch((url, init) => new Response(JSON.stringify({url}), {status: 200}));
    const cache = createPrefetchCache();
    const http = createHttp({fetch, prefetchCache: cache});

    const entry = http.prefetch('/orrery/api/jobs', {status: 'failed', per_page: 5});

    assert.equal(typeof entry.abort, 'function');
    await entry.promise;
    cache.arm([entry]);
    assert.equal(calls.length, 1);

    const first = await http.get('/orrery/api/jobs', {params: {per_page: 5, status: 'failed'}});

    assert.equal(calls.length, 1, 'the screen took the prefetched answer');
    assert.deepEqual(first.data, {url: '/orrery/api/jobs?status=failed&per_page=5'});

    await http.get('/orrery/api/jobs', {params: {per_page: 5, status: 'failed'}});
    assert.equal(calls.length, 2, 'a poll goes to the network');

    cache.arm([http.prefetch('/orrery/api/control', {})]);
    await http.post('/orrery/api/control', {});
    assert.equal(calls.at(-1).init.method, 'POST', 'a write never takes a prefetched answer');
    assert.equal(calls.length, 4);
});

// Rejects: a prefetch whose abort does not reach fetch().
test('prefetch: abort cancels the request', async () => {
    const {fetch} = fakeFetch(hangs);
    const http = createHttp({fetch, prefetchCache: createPrefetchCache()});
    const entry = http.prefetch('/orrery/api/workload', {});

    entry.abort();
    assert.equal((await rejection(entry.promise)).code, 'ERR_CANCELED');
});

// Rejects: a taken prefetch whose failure reaches the error handling only with the prefetch's own handles (a screen
// read that raises an alert on a timeout would then stay silent), or one that runs it only once.
// Still passes: a client that also runs the success handlers again (they return the answer unchanged).
test('prefetch: a taken prefetch that fails runs the error handling again with the screen\'s handles', async () => {
    const {fetch} = fakeFetch(() => new Response('{}', {status: 503}));
    const cache = createPrefetchCache();
    const http = createHttp({fetch, prefetchCache: cache});
    const seen = [];

    http.interceptors.response.use(response => response, error => {
        seen.push(error.config.handles);

        return Promise.reject(error);
    });

    const entry = http.prefetch('/orrery/api/jobs', {status: 'failed'}, [], {handles: 'prefetch'});

    cache.arm([entry]);

    const error = await rejection(http.get('/orrery/api/jobs', {params: {status: 'failed'}, handles: 'screen'}));

    assert.equal(error.response.status, 503);
    assert.deepEqual(seen, ['prefetch', 'screen']);
    assert.equal(error.config.url, '/orrery/api/jobs');
});
