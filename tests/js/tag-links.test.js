// Tests of resources/js/screens/tags/tag-links.js and of the Tags routes of
// resources/js/routes.js
// through a real vue-router on a memory history: the guard of /tags/:tag,
// the redirects of the old /monitoring screens, and the tag links of the
// Job detail (screens/jobs/detail.js inspectorLinks()). Every .vue import is
// answered with an empty component, so Node needs no compiler.

import {test} from 'node:test';
import assert from 'node:assert/strict';
import {registerHooks} from 'node:module';

// No .vue import below this module: a static import is safe before the hooks.
import {installTagGuard, pathCarries, tagLocation, tagRouteGuard} from '../../resources/js/screens/tags/tag-links.js';

registerHooks({
    resolve(specifier, context, nextResolve) {
        if (specifier.endsWith('.vue')) return {url: 'stub-vue:' + specifier, shortCircuit: true};

        return nextResolve(specifier, context);
    },
    load(url, context, nextLoad) {
        if (url.startsWith('stub-vue:')) return {format: 'module', source: `export default {name: ${JSON.stringify(url)}, render: () => null};`, shortCircuit: true};

        return nextLoad(url, context);
    },
});

const {createMemoryHistory, createRouter} = await import('vue-router');
const {createRoutes} = await import('../../resources/js/routes.js');
const {inspectorLinks} = await import('../../resources/js/screens/jobs/detail.js');
const {routeTag, tabFromQuery} = await import('../../resources/js/screens/tags/tags.js');

const NBSP = ' ';
const TAGS = ['tenant:acme-eu', 'App\\Models\\Invoice:20412', 'campaign:spring', 'a b', 'x' + NBSP, '.', '..', 'a/b', '/', '?x=1&y#z', '100%', 'a%2Fb', '100%25', 'x%20y', 'a+b/c', 'a b/c'];

function makeRouter() {
    const router = createRouter({history: createMemoryHistory(), routes: createRoutes({environment: 'production'})});

    installTagGuard(router);

    return router;
}

async function land(to) {
    const router = makeRouter();

    await router.push(to);

    return router.currentRoute.value;
}

function shown(route) {
    return {tag: (routeTag(route.params, route.query) || {}).tag, tab: tabFromQuery(route.query)};
}

// Rejects: a tag view that loses, trims or re-encodes a tag on the way
// through the router (a '/' or a no-break space), and a '.' or '..' left in
// the path, where the browser would resolve it away.
test('tagLocation() lands on the tag view with the exact tag', async () => {
    for (const tag of TAGS) {
        for (const tab of ['all', 'failed']) {
            const route = await land(tagLocation(tag, tab));

            assert.ok(['tag', 'tags'].includes(route.name), JSON.stringify(tag));
            assert.deepEqual(shown(route), {tag, tab}, JSON.stringify(tag));
            assert.ok(!/\/\.\.?(\/|\?|$)/.test(route.fullPath), route.fullPath);
        }
    }
});

// Rejects: a /tags/:tag route that keeps '..' or '%2F' in the address bar
// (a reload would lose the tag), and one that drops the tab.
test('a path that cannot carry its tag moves to the query form', async () => {
    const dots = await land('/tags/..?tab=failed');

    assert.equal(dots.name, 'tags');
    assert.deepEqual(shown(dots), {tag: '..', tab: 'failed'});

    const slash = await land('/tags/a%2Fb');

    assert.equal(slash.name, 'tags');
    assert.deepEqual(shown(slash), {tag: 'a/b', tab: 'all'});
    assert.equal(slash.fullPath, '/tags?tag=a/b');

    const plain = await land('/tags/tenant%3Aacme-eu');

    assert.equal(plain.name, 'tag');
    assert.equal(plain.params.tag, 'tenant:acme-eu');
});

// Rejects: a guard that runs only on entering the route (beforeEnter), so a
// param-only change from one tag view to another keeps '..' or '/' in the path.
test('a param-only change to a tag that the path cannot carry also moves to the query form', async () => {
    const router = makeRouter();

    await router.push('/tags/foo');
    assert.equal(router.currentRoute.value.name, 'tag');

    await router.push('/tags/..');
    assert.equal(router.currentRoute.value.name, 'tags');
    assert.equal(router.currentRoute.value.fullPath, '/tags?tag=..');

    await router.push('/tags/foo');
    await router.push('/tags/a%2Fb');
    assert.equal(router.currentRoute.value.name, 'tags');
    assert.equal(router.currentRoute.value.fullPath, '/tags?tag=a/b');
});

// Rejects: a Job detail tag link whose href keeps a dot segment or a %2F
// (a middle-click or a copied link would lose the tag).
test('the Job detail tag links have hrefs that keep the tag', () => {
    const router = makeRouter();
    const links = inspectorLinks({tags: TAGS, status: 'failed', connection: 'database', queue: 'default', name: 'App\\Jobs\\X'}).tags;

    for (const link of links) {
        const href = router.resolve(link.to).href;

        assert.ok(!/\/\.\.?(\/|\?|#|$)/.test(href), href);
        assert.ok(!/%2F/i.test(href.split('?')[0]), href);
    }
});

// Rejects: a Job detail tag link that does not reach the tag view, or that
// reaches it with another tag (the Job detail links /tags/<encodeURIComponent(tag)>).
test('the Job detail tag links open the tag view', async () => {
    const links = inspectorLinks({tags: TAGS, status: 'failed', connection: 'database', queue: 'default', name: 'App\\Jobs\\X'}).tags;

    for (const [index, link] of links.entries()) {
        const route = await land(link.to);

        assert.deepEqual(shown(route), {tag: TAGS[index], tab: 'all'}, link.to);
    }
});

// Rejects: old /monitoring links that do not reach the Tags screen, and a
// failed tab that lands on All.
test('the old monitoring routes redirect to the Tags screen', async () => {
    assert.equal((await land('/monitoring')).name, 'tags');
    assert.equal((await land('/tags')).name, 'tags');
    assert.deepEqual(shown(await land('/monitoring')), {tag: undefined, tab: 'all'});
    assert.deepEqual(shown(await land('/monitoring/campaign%3Aspring')), {tag: 'campaign:spring', tab: 'all'});
    assert.deepEqual(shown(await land('/monitoring/campaign%3Aspring/jobs')), {tag: 'campaign:spring', tab: 'all'});
    assert.deepEqual(shown(await land('/monitoring/campaign%3Aspring/failed')), {tag: 'campaign:spring', tab: 'failed'});
    assert.deepEqual(shown(await land('/monitoring/..%2F/failed')), {tag: '../', tab: 'failed'});
});

// Rejects: a path link for a tag that a path cannot carry ('.' and '..' are
// resolved by the browser, '/' splits the segment and %2F is refused by
// Apache), and a query link for an ordinary tag.
test('pathCarries() accepts only tags that one path segment carries', () => {
    for (const tag of ['tenant:acme-eu', 'App\\Models\\Invoice:20412', 'a b', 'x' + NBSP, '...', '.x', '%2e%2e', '?#&']) {
        assert.equal(pathCarries(tag), true, JSON.stringify(tag));
    }

    for (const tag of ['.', '..', 'a/b', '/', '', null, 42]) {
        assert.equal(pathCarries(tag), false, JSON.stringify(tag));
    }
});

// Rejects: a link that trims, re-cases or drops a character of the tag, a
// failed tab that is not in the location, and '..' sent as a path.
test('tagLocation() keeps the tag byte for byte, in the path or in the query', () => {
    assert.deepEqual(tagLocation('tenant:acme-eu'), {path: '/tags/tenant%3Aacme-eu', query: {}});
    assert.deepEqual(tagLocation('a b' + NBSP), {path: '/tags/a%20b%C2%A0', query: {}});
    assert.deepEqual(tagLocation('a b', 'failed'), {path: '/tags/a%20b', query: {tab: 'failed'}});
    assert.deepEqual(tagLocation('..'), {path: '/tags', query: {tag: '..'}});
    assert.deepEqual(tagLocation('.', 'failed'), {path: '/tags', query: {tag: '.', tab: 'failed'}});
    assert.deepEqual(tagLocation('a/b'), {path: '/tags', query: {tag: 'a/b'}});
});

// Rejects: a guard that lets '..' or 'a/b' stay in the path (the address bar
// would lose the tag), one that drops the failed tab, and one that redirects
// an ordinary tag (an endless redirect).
test('tagRouteGuard() moves a tag that the path cannot carry to the query', () => {
    assert.equal(tagRouteGuard({params: {tag: 'tenant:acme-eu'}, query: {}}), true);
    assert.equal(tagRouteGuard({params: {tag: 'x' + NBSP}, query: {tab: 'failed'}}), true);
    assert.deepEqual(tagRouteGuard({params: {tag: '..'}, query: {tab: 'failed'}}), {path: '/tags', query: {tab: 'failed', tag: '..'}});
    assert.deepEqual(tagRouteGuard({params: {tag: 'a/b'}, query: {}}), {path: '/tags', query: {tag: 'a/b'}});
});
