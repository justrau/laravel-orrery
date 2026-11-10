/**
 * The redirect map of the old SPA routes. Each screen task
 * wired its own rows when its screen existed (`lands`); the rebuild deleted the
 * old screens, so every old path now only redirects. The /batches paths
 * keep their place with new components and have no row; /failed
 * stays the failure-groups screen.
 *
 * A row is `{path, name?, lands, detail?, target(params, query)}`: `path` is
 * the vue-router path of the old route. `name` is the old route's name in
 * routes.js, kept only while a screen links to it by name: a new route with
 * the same name would replace the row without a warning. Since the rebuild no
 * screen links to an old route, so no row has a name. `target` returns the
 * new location, or
 * `{notFound: true, id}` for a legacy job id that is not a uuid (the new
 * detail route matches only a uuid). A redirect keeps the old query string;
 * the new route's own query values win. vue-router replaces the redirected
 * entry, so Back skips the old URL.
 */

import {isUuid} from './lists.js';

function withQuery(path, query, own = {}) {
    const kept = query && typeof query === 'object' ? query : {};

    return {path, query: {...kept, ...own}};
}

function jobDetail(id, query) {
    return isUuid(id) ? withQuery('/jobs/' + id, query) : {notFound: true, id};
}

/**
 * The SPA path of a queue page: the connection as one encoded segment, the
 * queue name with its "/" kept as path separators, each part encoded. A
 * "/" encoded as %2F is answered with 404 by Apache's default
 * (AllowEncodedSlashes Off) on a reload; the queue route reads the rest of
 * the path back as the name (routes.js). A connection name has no "/" in
 * practice; one that has it stays a single %2F segment.
 */
export function queuePath(connection, queue) {
    return `/queues/${encodeURIComponent(connection)}/${String(queue).split('/').map(encodeURIComponent).join('/')}`;
}

function tagPath(tag) {
    return '/tags/' + encodeURIComponent(tag);
}

/** A PHP class name: identifier segments separated by backslashes, as the samples key a job class. */
const CLASS_NAME = /^[A-Za-z_\x80-\uFFFF][\w\x80-\uFFFF]*(?:\\[A-Za-z_\x80-\uFFFF][\w\x80-\uFFFF]*)*$/;

/** The longest class name that GET /api/classes/series accepts. */
const CLASS_MAX = 512;

/**
 * The class of an old /metrics/jobs/:slug, or null when the slug is not a
 * full class name (a closure label, for example): the Job classes view then
 * opens without a selection.
 */
export function metricsClass(slug) {
    return typeof slug === 'string' && [...slug].length <= CLASS_MAX && CLASS_NAME.test(slug) ? slug : null;
}

function classesTarget(slug, query) {
    const name = metricsClass(slug);

    return withQuery('/classes', query, name === null ? {} : {class: name});
}

export const REDIRECTS = Object.freeze([
    {path: '/jobs/:type(pending|completed|silenced)', lands: 'D8-13', target: (params, query) => withQuery('/jobs', query, {status: params.type})},
    {path: '/failed-legacy', lands: 'D8-13', target: (params, query) => withQuery('/jobs', query, {status: 'failed'})},
    {path: '/jobs/:type/:jobId', lands: 'D8-14', detail: true, target: (params, query) => jobDetail(params.jobId, query)},
    {path: '/failed/:jobId', lands: 'D8-14', detail: true, target: (params, query) => jobDetail(params.jobId, query)},
    {path: '/monitoring', lands: 'D8-16', target: (params, query) => withQuery('/tags', query)},
    {path: '/monitoring/:tag', lands: 'D8-16', target: (params, query) => withQuery(tagPath(params.tag), query)},
    {path: '/monitoring/:tag/jobs', lands: 'D8-16', target: (params, query) => withQuery(tagPath(params.tag), query)},
    {path: '/monitoring/:tag/failed', lands: 'D8-16', target: (params, query) => withQuery(tagPath(params.tag), query, {tab: 'failed'})},
    // The old /metrics redirected to /metrics/jobs; it goes to /classes directly.
    {path: '/metrics', lands: 'D8-17', target: (params, query) => withQuery('/classes', query)},
    {path: '/metrics/jobs', lands: 'D8-17', target: (params, query) => withQuery('/classes', query)},
    {path: '/metrics/queues', lands: 'D8-17', target: (params, query) => withQuery('/queues', query)},
    // One row, as the old route /metrics/:type/:slug.
    {path: '/metrics/:type/:slug', lands: 'D8-17', target: (params, query) => (params.type === 'queues' ? withQuery('/queues', query) : classesTarget(params.slug, query))},
].map(row => Object.freeze(row)));

function segments(path) {
    return String(path).split('?')[0].split('/').filter(segment => segment !== '');
}

/**
 * The decoded params of a concrete path for a vue-router path with `:name`
 * and `:name(a|b)` segments, or null when it does not match.
 */
export function matchPath(pattern, path) {
    const expected = segments(pattern);
    const actual = segments(path);

    if (expected.length !== actual.length) return null;

    const params = {};

    for (let i = 0; i < expected.length; i++) {
        const param = /^:([A-Za-z_]\w*)(?:\(([^)]*)\))?$/.exec(expected[i]);

        if (!param) {
            if (expected[i] !== actual[i]) return null;

            continue;
        }

        let value;

        try {
            value = decodeURIComponent(actual[i]);
        } catch (error) {
            return null;
        }

        if (param[2] !== undefined && !new RegExp('^(?:' + param[2] + ')$').test(value)) return null;

        params[param[1]] = value;
    }

    return params;
}

/**
 * The new location of an old path, `{notFound: true, id}`, or null when no
 * row names the path. Used by the tests and by a screen that resolves an old
 * link itself.
 */
export function redirectTarget(path, query = {}) {
    for (const row of REDIRECTS) {
        const params = matchPath(row.path, path);

        if (params !== null) return row.target(params, query);
    }

    return null;
}

/**
 * The vue-router `redirect` function of a row. `notFound(target)` gives the
 * location of the not-found state for a legacy job id that is not a uuid; a
 * job-detail row (`detail`) needs it, and building its redirect without one
 * throws, so a wiring mistake shows when the routes are built, not at a
 * user's navigation.
 */
export function routeRedirect(row, notFound = null) {
    if (row.detail && typeof notFound !== 'function') {
        throw new Error(`The redirect of ${row.path} needs a not-found location for a job id that is not a uuid.`);
    }

    return to => {
        const target = row.target((to && to.params) || {}, (to && to.query) || {});

        return target.notFound ? notFound(target) : target;
    };
}
