// Tests of resources/js/routes.js through vue-router's own matcher: the
// route records are built with stub components (a loader hook answers
// every .vue import with an empty component), so Node needs no compiler.

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {registerHooks} from 'node:module';

// Synchronous, in-thread module hooks (module.registerHooks, Node 22.15+ and
// 24): every .vue import is answered with an empty component.
registerHooks({
    resolve(specifier, context, nextResolve) {
        if (specifier.endsWith('.vue')) return {url: 'stub-vue:' + specifier, shortCircuit: true};

        return nextResolve(specifier, context);
    },
    load(url, context, nextLoad) {
        if (url.startsWith('stub-vue:')) return {format: 'module', source: `export default {name: ${JSON.stringify(url)}};`, shortCircuit: true};

        return nextLoad(url, context);
    },
});

const {createRouterMatcher} = await import('vue-router');
const {createRoutes} = await import('../../resources/js/routes.js');
const {jobLink} = await import('../../resources/js/screens/jobs/jobs.js');
const {queuePath} = await import('../../resources/js/support/redirects.js');

const UUID = '0b6f1c9e-3b8a-4d2f-9a51-7c2e4b1d9f00';

function resolve(path) {
    const matcher = createRouterMatcher(createRoutes({environment: 'production'}), {});

    return matcher.resolve({path}, {path: '/', name: undefined, params: {}, matched: [], meta: {}});
}

// Rejects: a row link that the router sends to the legacy /jobs/:type list (a uuid read as a list type) instead of the Job detail, in either case of the uuid.
test('a row link of the Jobs list opens the Job detail', () => {
    for (const id of [UUID, UUID.toUpperCase()]) {
        const link = jobLink({id});
        const resolved = resolve(link);

        assert.equal(resolved.name, 'job-detail', link);
        assert.notEqual(resolved.name, 'jobs', link);
    }
});

// Rejects: a Jobs list route that a legacy record shadows, and old list tabs that still reach the legacy list.
test('the Jobs list and its redirects resolve', () => {
    assert.equal(resolve('/jobs').name, 'jobs-list');

    for (const tab of ['pending', 'completed', 'silenced']) {
        const record = resolve(`/jobs/${tab}`).matched[0];

        assert.equal(typeof record.redirect, 'function', tab);
        assert.deepEqual(record.redirect({params: {type: tab}, query: {}}), {path: '/jobs', query: {status: tab}});
    }

    assert.equal(typeof resolve('/failed-legacy').matched[0].redirect, 'function');
});

// vue-router's matcher returns the params of a path still percent-encoded;
// the router decodes them (decodeURIComponent, the raw text when that throws)
// before it calls a redirect. The tests call the redirects the same way.
function decodeParams(params) {
    const decode = text => { try { return decodeURIComponent(String(text)); } catch { return String(text); } };

    return Object.fromEntries(Object.entries(params).map(([key, value]) => [key, Array.isArray(value) ? value.map(decode) : decode(value)]));
}

// Rejects: a /classes route that is missing or shadowed, legacy /metrics paths that still open the legacy screens instead of redirecting, the query dropped, and an old class slug that is lost (it reaches the redirect decoded, as the router decodes it) or a closure label taken as a class.
test('the Job classes view and the /metrics redirects resolve', () => {
    assert.equal(resolve('/classes').name, 'classes');

    const cases = {
        '/metrics': {path: '/classes', query: {minutes: '15'}},
        '/metrics/jobs': {path: '/classes', query: {minutes: '15'}},
        '/metrics/jobs/App%5CJobs%5CSend': {path: '/classes', query: {minutes: '15', class: 'App\\Jobs\\Send'}},
        '/metrics/jobs/Closure%20(routes%2Fweb.php%3A12)': {path: '/classes', query: {minutes: '15'}},
        '/metrics/queues': {path: '/queues', query: {minutes: '15'}},
        '/metrics/queues/default': {path: '/queues', query: {minutes: '15'}},
    };

    for (const [path, target] of Object.entries(cases)) {
        const resolved = resolve(path);
        const record = resolved.matched[resolved.matched.length - 1];

        assert.equal(typeof record.redirect, 'function', path);
        assert.deepEqual(record.redirect({params: decodeParams(resolved.params), query: {minutes: '15'}}), target, path);
    }
});

// Rejects: /batches on another screen, and a link by name `batches-preview` to a missing route.
test('the Batches screen owns /batches', () => {
    const list = resolve('/batches');
    const detail = resolve('/batches/b-1');

    assert.equal(list.name, 'batches');
    assert.equal(detail.name, 'batches-preview');
    assert.equal(list.matched[0].components.default, detail.matched[0].components.default, 'one screen serves the list and the detail');
    assert.match(String(detail.matched[0].components.default.name), /BatchesScreen\.vue/);
    assert.deepEqual(detail.matched[0].props.default(detail), {batchId: 'b-1'});
    assert.deepEqual(list.matched[0].props.default, {batchId: null});

    const matcher = createRouterMatcher(createRoutes({environment: 'production'}), {});
    const named = matcher.resolve({name: 'batches-preview', params: {batchId: 'b-1'}}, {path: '/', name: undefined, params: {}, matched: [], meta: {}});

    assert.equal(named.path, '/batches/b-1');
});

// Rejects: a route that still renders a deleted screen (the
// legacy /jobs/:type list, the /batches-legacy screens), a route left with the
// legacy marker or the toolbar's auto-load flag, and an old path that neither
// redirects nor stays gone.
test('no route serves a deleted screen', () => {
    const routes = createRoutes({environment: 'local'});
    const records = [];
    const walk = list => list.forEach(route => { records.push(route); walk(route.children || []); });

    walk(routes);

    for (const route of records) {
        assert.equal(route.meta && route.meta.legacy, undefined, route.path);
        assert.equal(route.meta && route.meta.autoLoad, undefined, route.path);

        const component = route.component ? String(route.component.name) : '';

        assert.doesNotMatch(component, /screens\/(recentJobs|failedJobs|monitoring|metrics)\/|screens\/batches\/(index|preview)\.vue/, route.path);
    }

    // No screen of their own: only the catch-all, which opens the Overview.
    for (const path of ['/batches-legacy', '/batches-legacy/b-1', '/jobs/failed', '/jobs/anything']) {
        assert.equal(resolve(path).name, 'not-found', path);
    }

    // The old paths redirect.
    for (const path of ['/jobs/pending', '/jobs/completed', '/jobs/silenced', '/failed-legacy', '/jobs/pending/' + UUID, '/failed/' + UUID, '/monitoring', '/monitoring/a/jobs', '/monitoring/a/failed', '/metrics', '/metrics/jobs', '/metrics/queues', '/metrics/jobs/A']) {
        const matched = resolve(path).matched;

        assert.equal(typeof matched[matched.length - 1].redirect, 'function', path);
    }
});

// Rejects: a queue page whose name holds "/" linked as one %2F segment (Apache's default answers a reload of
// such a URL with 404), and a route that no longer reads a name with "/" back whole.
test('a queue name with a slash gets a path of real segments that the queue route reads back whole', () => {
    const path = queuePath('database', 'emails/high/eu');

    assert.equal(path, '/queues/database/emails/high/eu');

    const resolved = resolve(path);

    // The matcher sees the raw path; the router decodes each param after it (createRouter's decode).
    assert.equal(resolved.name, 'queue');
    assert.equal(resolved.params.connection, 'database');
    assert.equal(resolved.params.queue, 'emails/high/eu');
    assert.equal(resolve('/queues/database/').name, 'not-found', 'a queue page needs a queue name: only the catch-all matches');
});

// Rejects (nit): an unknown path that matches no route (the content area stays empty), and a catch-all that shadows a
// screen or the gallery of the local environment.
// Still passes: a catch-all that redirects to another screen than the Overview.
test('an unknown path redirects to the Overview', () => {
    for (const path of ['/no-such-screen', '/jobs/x/y/z', '/gallery']) {
        const resolved = resolve(path);

        assert.equal(resolved.name, 'not-found', path);
        assert.equal(resolved.matched[0].redirect, '/overview', path);
    }

    assert.equal(resolve('/overview').name, 'overview');
    assert.equal(resolve('/failed').name, 'failed-jobs');

    const local = createRouterMatcher(createRoutes({environment: 'local'}), {});
    assert.equal(local.resolve({path: '/gallery'}, {path: '/', name: undefined, params: {}, matched: [], meta: {}}).name, 'gallery');
});
