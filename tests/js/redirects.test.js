// Tests of resources/js/support/redirects.js: the redirect map of the old
// SPA routes. The router module wires no row itself; each screen task wires its
// own rows, and the browser check covers direct and back navigation.

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync, readdirSync} from 'node:fs';

import {REDIRECTS, matchPath, metricsClass, queuePath, redirectTarget, routeRedirect} from '../../resources/js/support/redirects.js';

const UUID = '0b6f1c9e-3b8a-4d2f-9a51-7c2e4b1d9f00';

// Rejects: a matcher that ignores a literal segment, a trailing segment or a constraint, and one that hands out a still-encoded param.
test('matchPath matches literal and parameter segments', () => {
    assert.deepEqual(matchPath('/jobs/:type', '/jobs/pending'), {type: 'pending'});
    assert.deepEqual(matchPath('/jobs/:type/:jobId', '/jobs/pending/abc'), {type: 'pending', jobId: 'abc'});
    assert.equal(matchPath('/jobs/:type', '/jobs/pending/abc'), null);
    assert.equal(matchPath('/jobs/:type', '/batches/pending'), null);
    assert.equal(matchPath('/jobs/:type(pending|completed)', '/jobs/failed'), null);
    assert.deepEqual(matchPath('/jobs/:type(pending|completed)', '/jobs/completed'), {type: 'completed'});
    assert.deepEqual(matchPath('/monitoring/:tag', '/monitoring/App%5CModels%5CUser%3A1'), {tag: 'App\\Models\\User:1'});
    assert.deepEqual(matchPath('/failed-legacy', '/failed-legacy/'), {});
    assert.equal(matchPath('/monitoring/:tag', '/monitoring/%E0%A4%A'), null, 'a malformed escape matches nothing');
});

// Rejects: a missing row of the 5.3 table, a row without its task, and a /batches row (those paths keep their place with new components).
test('the map has every row of section 5.3 with the task that wires it', () => {
    const rows = Object.fromEntries(REDIRECTS.map(row => [row.path, row.lands]));

    assert.deepEqual(rows, {
        '/jobs/:type(pending|completed|silenced)': 'D8-13',
        '/failed-legacy': 'D8-13',
        '/jobs/:type/:jobId': 'D8-14',
        '/failed/:jobId': 'D8-14',
        '/monitoring': 'D8-16',
        '/monitoring/:tag': 'D8-16',
        '/monitoring/:tag/jobs': 'D8-16',
        '/monitoring/:tag/failed': 'D8-16',
        '/metrics': 'D8-17',
        '/metrics/jobs': 'D8-17',
        '/metrics/queues': 'D8-17',
        '/metrics/:type/:slug': 'D8-17',
    });

    assert.ok(Object.isFrozen(REDIRECTS));
});

// Rejects: a redirect row that keeps the name of an old route after the UI rebuild deleted every screen that linked to
// it by name (a new route with that name would silently replace the redirect), and a name that differs from
// the one in routes.js.
test('no row keeps the name of an old route', () => {
    const routes = readFileSync(new URL('../../resources/js/routes.js', import.meta.url), 'utf8');
    const expected = {
        '/jobs/:type(pending|completed|silenced)': undefined,
        '/failed-legacy': undefined,
        '/jobs/:type/:jobId': undefined,
        '/failed/:jobId': undefined,
        '/monitoring': undefined,
        '/monitoring/:tag': undefined,
        '/monitoring/:tag/jobs': undefined,
        '/monitoring/:tag/failed': undefined,
        '/metrics': undefined,
        '/metrics/jobs': undefined,
        '/metrics/queues': undefined,
        '/metrics/:type/:slug': undefined,
    };

    for (const name of ['job-preview', 'failed-jobs-preview', 'monitoring-jobs', 'monitoring-failed', 'metrics-jobs', 'metrics-queues', 'metrics-preview']) {
        assert.doesNotMatch(routes, new RegExp(`name: '${name}'`), name);
    }

    for (const row of REDIRECTS) {
        assert.equal(row.name, expected[row.path], row.path);

        if (row.name !== undefined) {
            // The name exists in routes.js, on a route whose last segment is the row's last segment.
            const last = row.path.split('/').pop().replace(/\(.*\)$/, '');
            const declared = new RegExp(`path: '[^']*?${last.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}'[^}]*name: '${row.name}'`);
            assert.match(routes, declared, `${row.name} on ${row.path}`);
        }
    }
});

// Rejects: a redirect that drops the query string, one that lets the old query override the new tab, and the wrong target of each row.
test('redirectTarget sends each old route to its replacement and keeps the query', () => {
    assert.deepEqual(redirectTarget('/jobs/pending'), {path: '/jobs', query: {status: 'pending'}});
    assert.deepEqual(redirectTarget('/jobs/silenced', {queue: 'q', status: 'failed'}), {path: '/jobs', query: {queue: 'q', status: 'silenced'}});
    assert.deepEqual(redirectTarget('/failed-legacy', {tag: 'x'}), {path: '/jobs', query: {tag: 'x', status: 'failed'}});

    assert.deepEqual(redirectTarget(`/jobs/completed/${UUID}`, {a: '1'}), {path: `/jobs/${UUID}`, query: {a: '1'}});
    assert.deepEqual(redirectTarget(`/failed/${UUID.toUpperCase()}`), {path: `/jobs/${UUID.toUpperCase()}`, query: {}});

    assert.deepEqual(redirectTarget('/monitoring'), {path: '/tags', query: {}});
    assert.deepEqual(redirectTarget('/monitoring/user%3A1/jobs'), {path: '/tags/user%3A1', query: {}});
    assert.deepEqual(redirectTarget('/monitoring/user:1'), {path: '/tags/user%3A1', query: {}});
    assert.deepEqual(redirectTarget('/monitoring/a%2Fb/failed', {tab: 'all', x: 'y'}), {path: '/tags/a%2Fb', query: {x: 'y', tab: 'failed'}});

    for (const path of ['/metrics', '/metrics/jobs', '/metrics/jobs/Closure%20(x.php%3A1)']) {
        assert.deepEqual(redirectTarget(path), {path: '/classes', query: {}}, path);
    }

    // A full class name as the old slug selects that class (byte for byte).
    assert.deepEqual(redirectTarget('/metrics/jobs/App%5CJobs%5CSend', {x: '1'}), {path: '/classes', query: {x: '1', class: 'App\\Jobs\\Send'}});

    for (const path of ['/metrics/queues', '/metrics/queues/default', '/metrics/queues/database%3Adefault']) {
        assert.deepEqual(redirectTarget(path), {path: '/queues', query: {}}, path);
    }
});

// Rejects: a legacy job id that is not a uuid sent to the new detail (which matches only a uuid), and a not-found answer without the id.
test('a legacy job id that is not a uuid gives the not-found state', () => {
    assert.deepEqual(redirectTarget('/jobs/pending/12345'), {notFound: true, id: '12345'});
    assert.deepEqual(redirectTarget('/failed/not-a-uuid'), {notFound: true, id: 'not-a-uuid'});
});

// Rejects: catching a path that no row names (/failed stays the failure-groups screen, /batches keeps its path, /jobs/<other> is not a list tab).
test('redirectTarget answers null for a path that no row names', () => {
    for (const path of ['/failed', '/batches', `/batches/${UUID}`, '/jobs', '/jobs/failed', '/overview', '/queues/db/q']) {
        assert.equal(redirectTarget(path), null, path);
    }
});

/** The .vue files under resources/js. */
function vueFiles(directory = new URL('../../resources/js/', import.meta.url)) {
    return readdirSync(directory, {withFileTypes: true}).flatMap(entry => {
        const url = new URL(entry.name + (entry.isDirectory() ? '/' : ''), directory);

        return entry.isDirectory() ? vueFiles(url) : (entry.name.endsWith('.vue') ? [readFileSync(url, 'utf8')] : []);
    });
}

// Rejects: a row that keeps a name no screen links to (a new route with that name would silently replace the redirect, or be replaced by it).
test('every row name is linked by name from a screen', () => {
    const screens = vueFiles().join('\n');

    for (const row of REDIRECTS.filter(candidate => candidate.name !== undefined)) {
        assert.match(screens, new RegExp(`name: ?'${row.name}'`), row.name);
    }
});

// Rejects: a job-detail row wired without a not-found location (a non-uuid id would land on a made-up path at navigation time instead of failing when the route is built).
test('routeRedirect needs a not-found location for the job-detail rows', () => {
    for (const path of ['/jobs/:type/:jobId', '/failed/:jobId']) {
        assert.throws(() => routeRedirect(REDIRECTS.find(row => row.path === path)), /not-found/, path);
    }

    assert.doesNotThrow(() => routeRedirect(REDIRECTS.find(row => row.path === '/monitoring')));
});

// Rejects: a router redirect that loses the query or the params, and one that cannot send a non-uuid id to the caller's not-found location.
test('routeRedirect gives a vue-router redirect function for a row', () => {
    const detail = REDIRECTS.find(row => row.path === '/jobs/:type/:jobId');
    const notFound = target => ({name: 'job-not-found', query: {id: target.id}});
    const redirect = routeRedirect(detail, notFound);

    assert.deepEqual(redirect({params: {type: 'pending', jobId: UUID}, query: {x: '1'}}), {path: `/jobs/${UUID}`, query: {x: '1'}});
    assert.deepEqual(redirect({params: {type: 'pending', jobId: '7'}, query: {}}), {name: 'job-not-found', query: {id: '7'}});

    const tabs = routeRedirect(REDIRECTS.find(row => row.path === '/jobs/:type(pending|completed|silenced)'));
    assert.deepEqual(tabs({params: {type: 'completed'}, query: {}}), {path: '/jobs', query: {status: 'completed'}});

    const monitored = routeRedirect(REDIRECTS.find(row => row.path === '/monitoring/:tag/failed'));
    assert.deepEqual(monitored({params: {tag: 'a/b'}}), {path: '/tags/a%2Fb', query: {tab: 'failed'}});
});

// Rejects: a slug that is not a class name (a closure label, a path) sent to /classes as a selection.
test('metricsClass keeps only a full class name', () => {
    assert.equal(metricsClass('App\\Jobs\\Send'), 'App\\Jobs\\Send');
    assert.equal(metricsClass('Send'), 'Send');
    assert.equal(metricsClass('Closure (DatabaseSeeder.php:291)'), null);
    assert.equal(metricsClass('App\\'), null);
    assert.equal(metricsClass(''), null);
    assert.equal(metricsClass('A'.repeat(513)), null);
});

// Rejects: encodeURIComponent of the whole queue name (a "/" becomes %2F, which Apache's default refuses on a
// reload), and an unencoded segment (a "?" or "#" in a name would end the path).
test('queuePath keeps the slashes of a queue name as path segments', () => {
    assert.equal(queuePath('database', 'emails/high'), '/queues/database/emails/high');
    assert.equal(queuePath('database', 'a?b#c'), '/queues/database/a%3Fb%23c');
    assert.equal(queuePath('my db', 'default'), '/queues/my%20db/default');
    assert.equal(queuePath('db/x', 'default'), '/queues/db%2Fx/default');
});
