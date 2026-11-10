/**
 * The route location of a tag's view, in a module
 * without imports, so the Job detail (screens/jobs/detail.js) can link tags
 * without an import cycle through tags.js.
 *
 * - /tags/:tag when one path segment carries the tag; a tag of '.' or '..'
 *   (the browser resolves them) or with '/' (a server can refuse %2F) lives
 *   at /tags?tag= instead.
 * - installTagGuard() moves such a path to the query form on every
 *   navigation to the route `tag`, also a param-only change from one tag to
 *   another (a `beforeEnter` guard would not run then).
 */

/** Whether one path segment carries the tag: not '.' or '..', and no '/'. */
export function pathCarries(tag) {
    return typeof tag === 'string' && tag !== '' && tag !== '.' && tag !== '..' && !tag.includes('/');
}

/**
 * The route location of a tag's view: /tags/:tag (encoded), or /tags?tag=
 * for a tag that a path cannot carry; `tab` 'failed' adds `tab=failed`.
 */
export function tagLocation(tag, tab = 'all') {
    const own = tab === 'failed' ? {tab: 'failed'} : {};

    if (pathCarries(tag)) return {path: '/tags/' + encodeURIComponent(tag), query: own};

    return {path: '/tags', query: {tag, ...own}};
}

/**
 * The guard of the route `tag` (/tags/:tag): a tag that the path cannot
 * carry (a link such as /tags/.. or /tags/a%2Fb) moves to /tags?tag=, with
 * the rest of the query kept; any other tag stays.
 */
export function tagRouteGuard(to) {
    const tag = to && to.params ? to.params.tag : null;

    if (typeof tag !== 'string' || pathCarries(tag)) return true;

    return {path: '/tags', query: {...((to && to.query) || {}), tag}};
}

/** Installs tagRouteGuard() as a global guard of the route `tag` (app.js and the route tests). */
export function installTagGuard(router) {
    router.beforeEach(to => (to.name === 'tag' ? tagRouteGuard(to) : true));
}
