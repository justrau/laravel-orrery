import axios from 'axios';
import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import '../css/tokens.css';
import '../css/base.css';
import Base from './base';
import { createRoutes } from './routes';
import App from './App.vue';
import Poll from './components/Poll.vue';
import ToolbarControls from './shell/ToolbarControls.vue';
import { resetToolbar } from './shell/store';
import { requestTimeout } from './shell/poll.js';
import { installErrorHandling } from './errors';
import { installTagGuard } from './screens/tags/tag-links.js';

let token = document.head.querySelector("meta[name='csrf-token']");

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

if (token) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}

// The runtime-only build of Vue: App.vue is compiled at build
// time, so the browser never compiles a template and the page needs no
// 'unsafe-eval'. Its root data holds the global alert and the auto-load
// toggle, which errors.js, AlertHost and the list screens reach as $root.
const app = createApp(App);

const http = axios.create();

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
