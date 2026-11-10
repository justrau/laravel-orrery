import overview from './screens/overview/index.vue';
import queues from './screens/queues/index.vue';
import failed from './screens/failed/index.vue';
import workers from './screens/workers/index.vue';
import gallery from './screens/gallery/index.vue';
import batchesScreen from './screens/batches/BatchesScreen.vue';
import jobsList from './screens/jobs/index.vue';
import jobDetail from './screens/jobs/JobDetail.vue';
import jobClasses from './screens/classes/index.vue';
import {REDIRECTS, routeRedirect} from './support/redirects.js';
import tagsScreen from './screens/tags/index.vue';

/** The redirect rows of the Jobs list: the old list tabs and the old failed list. */
const JOBS_LIST_REDIRECTS = REDIRECTS.filter(row => row.lands === 'D8-13');

/** The redirect of an old monitoring path (support/redirects.js, rows that land on the Tags screen). */
function tagsRedirect(path) {
    return routeRedirect(REDIRECTS.find(row => row.lands === 'D8-16' && row.path === path));
}

/**
 * The routes of the SPA.
 *
 * - Every screen fills the shell's content area with its own layout.
 * - /jobs is the Jobs list. The old list tabs /jobs/pending,
 *   /jobs/completed and /jobs/silenced and the old failed list
 *   /failed-legacy redirect to its tabs (support/redirects.js).
 * - /jobs/:uuid is the Job detail; the legacy detail paths
 *   /jobs/:type/:jobId and /failed/:jobId redirect to it.
 * - /classes is the Job classes view; the legacy /metrics paths
 *   redirect to it, and /metrics/queues… to /queues (support/redirects.js).
 * - /batches and /batches/:batchId are the Batches screen.
 * - /tags and /tags/:tag are the Tags screen; the old /monitoring
 *   screens redirect to it.
 * - /failed is the Failure groups screen.
 * - The old paths of the screens that the rebuild replaced only redirect
 *   (support/redirects.js); the screens themselves are gone.
 * - /gallery is a temporary, development-only component gallery: it exists
 *   only in the local environment.
 * - Any other path redirects to /overview.
 *
 * @param {{ environment?: string }} options
 */
export function createRoutes({environment = null} = {}) {
    const routes = [
        {path: '/', redirect: '/overview'},
        {path: '/dashboard', redirect: '/overview'},

        {path: '/overview', name: 'overview', component: overview, meta: {title: 'Overview'}},

        {path: '/queues', name: 'queues', component: queues, meta: {title: 'Queues'}, props: {connection: null, queue: null}},
        {path: '/queues/:connection/:queue(.+)', name: 'queue', component: queues, meta: {title: 'Queues'}, props: true},

        {path: '/workers', name: 'workers', component: workers, meta: {title: 'Workers'}},

        // The Jobs list, and the redirects of the lists it replaced.
        {path: '/jobs', name: 'jobs-list', component: jobsList, meta: {title: 'Jobs'}},
        ...JOBS_LIST_REDIRECTS.map(row => ({path: row.path, redirect: routeRedirect(row)})),

        // The Tags screen. /tags/:tag is the tag view; a tag that a path
        // cannot carry ('.', '..', '/') moves to /tags?tag= through the global
        // guard of screens/tags/tag-links.js installTagGuard() (app.js), which
        // also runs on a param-only change. The old /monitoring screens
        // redirect here.
        {path: '/tags', name: 'tags', component: tagsScreen, meta: {title: 'Tags'}},
        {path: '/tags/:tag', name: 'tag', component: tagsScreen, meta: {title: 'Tags'}},
        {path: '/monitoring', redirect: tagsRedirect('/monitoring')},
        {path: '/monitoring/:tag', redirect: tagsRedirect('/monitoring/:tag')},
        {path: '/monitoring/:tag/jobs', redirect: tagsRedirect('/monitoring/:tag/jobs')},
        {path: '/monitoring/:tag/failed', redirect: tagsRedirect('/monitoring/:tag/failed')},

        // The Job classes view, and the redirects of the Metrics screens it replaces.
        {path: '/classes', name: 'classes', component: jobClasses, meta: {title: 'Job classes'}},
        // The old Metrics paths redirect.
        {path: '/metrics', redirect: metricsRedirect('/metrics')},
        {path: '/metrics/jobs', redirect: metricsRedirect('/metrics/jobs')},
        {path: '/metrics/queues', redirect: metricsRedirect('/metrics/queues')},
        {path: '/metrics/:type/:slug', redirect: metricsRedirect('/metrics/:type/:slug')},

        {
            path: '/failed',
            children: [
                {path: '', name: 'failed-jobs', component: failed, meta: {title: 'Failed jobs'}},
            ],
        },

        // The Job detail. /jobs/:uuid matches only a
        // uuid. The old detail paths redirect to it; an old id that is not a
        // uuid lands on the not-found state.
        {path: `/jobs/:uuid(${JOB_UUID})`, name: 'job-detail', component: jobDetail, props: route => ({uuid: route.params.uuid}), meta: {title: 'Job'}},
        {path: '/jobs/not-a-uuid/:legacyId', name: 'job-detail-not-found', component: jobDetail, props: route => ({uuid: null, legacyId: route.params.legacyId}), meta: {title: 'Job'}},
        {path: '/jobs/:type/:jobId', redirect: detailRedirect('/jobs/:type/:jobId')},
        {path: '/failed/:jobId', redirect: detailRedirect('/failed/:jobId')},

        // The Batches screen on the old paths and names.
        {path: '/batches', name: 'batches', component: batchesScreen, props: {batchId: null}, meta: {title: 'Batches'}},
        {path: '/batches/:batchId', name: 'batches-preview', component: batchesScreen, props: route => ({batchId: String(route.params.batchId)}), meta: {title: 'Batch'}},
    ];

    if (environment === 'local') {
        routes.push({path: '/gallery', name: 'gallery', component: gallery, meta: {title: 'Component gallery'}});
    }

    // Any other path (a typo, a removed screen) opens the Overview, never an empty content area.
    routes.push({path: '/:pathMatch(.*)*', name: 'not-found', redirect: '/overview'});

    return routes;
}

/** A full uuid in any case: the route pattern of the Job detail. */
const JOB_UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

/**
 * The redirect of a legacy job detail path (support/redirects.js, rows that
 * land on the Job detail): a uuid opens /jobs/:uuid with the query kept, any other
 * id the not-found state.
 */
function detailRedirect(path) {
    const row = REDIRECTS.find(entry => entry.path === path);

    return routeRedirect(row, target => ({name: 'job-detail-not-found', params: {legacyId: String(target.id)}}));
}

/**
 * The redirect of a legacy Metrics path (support/redirects.js, rows that land
 * on the Job classes view): /classes, or /queues for the queue metrics, with the query kept.
 */
function metricsRedirect(path) {
    return routeRedirect(REDIRECTS.find(entry => entry.path === path && entry.lands === 'D8-17'));
}
