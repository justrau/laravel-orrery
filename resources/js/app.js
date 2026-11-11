import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import '../css/tokens.css';
import '../css/base.css';
import Base from './base';
import { createRoutes } from './routes';
import App from './App.vue';
import Poll from './components/Poll.vue';
import ToolbarControls from './shell/ToolbarControls.vue';
import { live, navigation, resetToolbar } from './shell/store';
import { BACKGROUND_HANDLES, requestTimeout } from './shell/poll.js';
import { installErrorHandling } from './errors';
import { createHttp } from './support/http.js';
import { createNavigationGuard, createNavigationHold } from './support/navigation.js';
import { createPrefetchCache } from './support/prefetch.js';
import { nowSeconds } from './support/time.js';
import { installTagGuard } from './screens/tags/tag-links.js';

let token = document.head.querySelector("meta[name='csrf-token']");

// The runtime-only build of Vue: App.vue is compiled at build
// time, so the browser never compiles a template and the page needs no
// 'unsafe-eval'. Its root data holds the global alert and the auto-load
// toggle, which errors.js, AlertHost and the list screens reach as $root.
const app = createApp(App);

// Every request sends X-Requested-With: XMLHttpRequest (support/http.js), and
// the CSRF token of the page when it has one.
const prefetchCache = createPrefetchCache();
const http = createHttp({ headers: token ? { 'X-CSRF-TOKEN': token.content } : {}, prefetchCache });

// Reads time out after 30 s (shell/poll.js), so a poll never waits forever and
// the next one can start. Writes keep no timeout: their outcome would be unknown.
http.interceptors.request.use(config => {
    config.timeout = requestTimeout(config);

    return config;
});

app.config.globalProperties.$http = http;

let root = null;

installErrorHandling(http, () => root);

let proxyPath = window.Orrery.proxy_path;
window.Orrery.basePath = proxyPath + '/' + window.Orrery.path;

let routerBasePath = window.Orrery.basePath + '/';

if (window.Orrery.path === '' || window.Orrery.path === '/') {
    routerBasePath = proxyPath + '/';
    window.Orrery.basePath = proxyPath;
}

const router = createRouter({
    history: createWebHistory(routerBasePath),
    routes: createRoutes({ environment: window.Orrery.environment }),
});

// A tag that a path cannot carry ('.', '..', '/') moves to /tags?tag=.
installTagGuard(router);

// The held navigation (support/navigation.js): a change of screen waits,
// with the URL and the sidebar, until the next screen's first reads have
// answered (at most HOLD_TIMEOUT_MS). The reads are prefetched through
// $http, and the screen's own first GET takes each answer once. A newer
// navigation supersedes a waiting one. The prefetched answers can be taken
// only after the navigation commits. The shell's progress bar and the inert
// content read the store's `navigation`.
const hold = createNavigationHold({
    onChange: state => {
        navigation.held = state.held;
        navigation.progress = state.progress;
    },
});
const navigationGuard = createNavigationGuard({
    hold,
    load: to => to.meta.load(to, { now: nowSeconds(), live }),
    prefetch: requests => requests.map(request => http.prefetch(window.Orrery.basePath + request.url, request.params, request.timeKeys || [], { handles: BACKGROUND_HANDLES })),
    discard: entries => prefetchCache.discard(entries),
    arm: entries => prefetchCache.arm(entries),
});

router.beforeEach(to => {
    navigationGuard.supersede();
    navigation.title = to.meta && typeof to.meta.title === 'string' ? to.meta.title : null;
});
router.beforeResolve(navigationGuard);
// The committed navigation's prefetched answers become takeable by its screen; a duplicated
// navigation (the current screen's link during a hold) gives the hold up.
router.afterEach((to, from, failure) => navigationGuard.committed(to, failure));

// A screen sets its toolbar text when it mounts. A new screen starts from the
// route's title. Routes that render the same component (/queues and
// /queues/:connection/:queue) keep one instance, and so keep its text.
router.afterEach((to, from, failure) => {
    // An aborted or blocked navigation (a guard that asks first) stays on the same screen.
    if (failure) return;

    const screen = route => {
        const record = route.matched.length ? route.matched[route.matched.length - 1] : null;

        return record && record.components ? record.components.default : null;
    };

    if (screen(to) !== screen(from)) {
        resetToolbar();
    }
});

app.use(router);

app.component('poll', Poll);
app.component('toolbar-controls', ToolbarControls);

app.mixin(Base);

root = app.mount('#orrery');
