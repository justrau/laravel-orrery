<script>
    import Notice from '../components/ui/Notice.vue';
    import {modalOpen} from '../support/focus.js';
    import {browserSeconds, noteServerTime, nowSeconds} from '../support/time.js';
    import Sidebar from './Sidebar.vue';
    import Toolbar from './Toolbar.vue';
    import {SIDEBAR_BREAKPOINT, failuresPastHour, listBody, objectBody, mirroredCount, runningBatches, shellAttention, sidebarIsDrawer, sidebarQueues} from './data.js';
    import {BACKGROUND_HANDLES, LIVE_HANDLES, createLiveRefresher} from './poll.js';
    import {closeNav, live, liveWatcherHealth, setLiveRefresh, setNarrow, setPhone, shell} from './store.js';

    /** The slower reads (the counts and the batches) refresh on every nth poll. */
    const SLOW_EVERY = 3;

    /**
     * The application shell: the sidebar, the toolbar and the
     * content area. It polls every 10
     * seconds and publishes what it reads in the store's `live` (store.js),
     * which the screens read instead of fetching the same data again. Each
     * part keeps its last good value when its request fails, and its `…Error`
     * flag says that the value is stale. The screens render in the content
     * area, each with its own layout.
     *
     * Below 1024 px the layout is narrow (store.js `shell`):
     * the sidebar is a drawer that the toolbar's toggle opens over a scrim.
     * Following a link, a route change, a click on the scrim and Escape
     * close it; the scrim and Escape return the focus to the toggle. While it
     * is open it is a modal dialog ("Navigation") and the rest of the shell
     * is inert.
     */
    export default {
        components: {Notice, Sidebar, Toolbar},

        props: {
            downForMaintenance: {type: Boolean, default: false},
        },

        data() {
            return {
                live,
                shell,
                now: nowSeconds(),
                polls: 0,
            };
        },

        computed: {
            appName() {
                return typeof Orrery !== 'undefined' && typeof Orrery.appName === 'string' ? Orrery.appName : null;
            },

            environment() {
                return typeof Orrery !== 'undefined' && typeof Orrery.environment === 'string' ? Orrery.environment : null;
            },

            queues() {
                return sidebarQueues(this.live.workload);
            },

            /** The workload never loaded: the sidebar says "Queues unknown", not "No database queues". */
            queuesUnknown() {
                return this.live.workload === null && this.live.workloadError;
            },

            /** The attention count (data.js shellAttention()), or null before the first workload read. */
            attention() {
                return shellAttention(this.live, this.now);
            },

            sidebarCounts() {
                const counts = this.live.countsError ? null : this.live.counts;
                let failuresHour;

                // Undefined before the first read, null when the last read failed (unknown).
                if (this.live.groupsHourError) failuresHour = null;
                else if (this.live.groupsHour !== null) failuresHour = failuresPastHour(this.live.groupsHour);

                return {
                    // Each unknown or "at least" while a connection's pushes are not mirrored.
                    pending: counts ? mirroredCount(counts.pending, this.live.health) : null,
                    running: counts ? mirroredCount(counts.running, this.live.health) : null,
                    completed: counts ? counts.completed : null,
                    failed: counts ? counts.failed : null,
                    failuresHour,
                    tags: counts && typeof counts.monitored_tags === 'number' ? counts.monitored_tags : null,
                    tagsKnown: this.live.counts !== null || this.live.countsError,
                    batches: this.live.batches,
                    batchesFailed: this.live.batchesError,
                    workers: Array.isArray(this.live.workers) && !this.live.workersError ? this.live.workers.length : null,
                };
            },

            healthSummary() {
                return liveWatcherHealth(this.now);
            },

            /** The drawer is open over the content: a modal dialog, the rest inert. */
            drawerOpen() {
                return this.shell.narrow && this.shell.navOpen;
            },
        },

        created() {
            // Every live read runs through one refresher: the 10 s poll and the
            // screens' requests (requestLiveRefresh) never overlap.
            this.liveRefresher = createLiveRefresher(() => this.refresh());
            setLiveRefresh(() => this.liveRefresher.request());
        },

        watch: {
            // A route change closes the drawer (the inspector overlay closes with its screen, InspectorLayout).
            '$route.fullPath'() {
                closeNav();
            },
        },

        mounted() {
            this.clock = setInterval(() => {
                this.now = nowSeconds();
            }, 1000);

            this.onKeydown = event => this.keydown(event);
            document.addEventListener('keydown', this.onKeydown);

            if (typeof window.matchMedia === 'function') {
                this.media = window.matchMedia(`(max-width: ${SIDEBAR_BREAKPOINT - 1}px)`);
                this.onMedia = () => setNarrow(this.media.matches);
                this.media.addEventListener('change', this.onMedia);
                setNarrow(this.media.matches);
                this.phoneMedia = window.matchMedia('(max-width: 767px)');
                this.onPhone = () => setPhone(this.phoneMedia.matches);
                this.phoneMedia.addEventListener('change', this.onPhone);
                setPhone(this.phoneMedia.matches);
            } else {
                setNarrow(sidebarIsDrawer(window.innerWidth));
                setPhone(window.innerWidth < 768);
            }
        },

        beforeUnmount() {
            setLiveRefresh(null);
            clearInterval(this.clock);
            document.removeEventListener('keydown', this.onKeydown);

            if (this.media) this.media.removeEventListener('change', this.onMedia);
            if (this.phoneMedia) this.phoneMedia.removeEventListener('change', this.onPhone);

            // A poll that settles after unmount changes nothing.
            this.startRequest('shell');
        },

        methods: {
            closeNav,

            /** Escape closes the open drawer and gives the focus back to its toggle. */
            keydown(event) {
                // An Escape that a sheet handled, or one while a confirmation sheet or the alert is open, is not the drawer's.
                if (event.key !== 'Escape' || !this.shell.navOpen || event.defaultPrevented || modalOpen(document, document.getElementById('orrery-nav'))) return;

                event.preventDefault();
                this.closeDrawer();
            },

            closeDrawer() {
                closeNav();
                this.$nextTick(() => {
                    const toggle = document.getElementById('orrery-nav-toggle');

                    if (toggle) toggle.focus();
                });
            },

            /** The drawer opened: the focus moves to its first link. */
            focusDrawer() {
                this.$nextTick(() => {
                    const link = this.$el.querySelector('.o-shell__nav a');

                    if (link) link.focus();
                });
            },

            /** The 10 s poll: starts a live read, or joins the one in flight. */
            pollLive() {
                return this.liveRefresher.poll();
            },

            get(path, params = {}, config = {}) {
                return this.$http.get(Orrery.basePath + path, {params, ...config}).then(response => response.data);
            },

            /**
             * One poll of the shell data. It resolves after every request has
             * settled (each GET times out after 30 s), so polls never overlap
             * (Poll.vue). Every result is applied only while its poll is the
             * newest (startRequest), so a late response of an older poll never
             * overwrites newer data.
             *
             * A part whose request fails keeps its last data and sets its
             * `…Error` flag. The background reads (the failure groups, the
             * counts and the batches) handle their own error statuses: they
             * raise no alert on every screen.
             */
            refresh() {
                const sequence = this.startRequest('shell');
                const now = nowSeconds();
                const slow = this.polls % SLOW_EVERY === 0;
                const current = () => this.isCurrentRequest('shell', sequence);
                const part = (key, request, read) => request
                    .then(data => {
                        if (!current()) return;

                        this.live[key] = read(data);
                        this.live[`${key}Error`] = false;
                    })
                    .catch(error => {
                        if (current()) this.live[`${key}Error`] = true;

                        throw error;
                    });
                // The part keeps its last data with its error flag, and the toolbar says "Stale" (poll.js handles).
                const background = (path, params) => this.get(path, params, {handles: BACKGROUND_HANDLES});
                const liveRead = (path, params) => this.get(path, params, {handles: LIVE_HANDLES});
                // Every freshness rule runs on the server's clock: the `as_of` of an answer sets it (time.js noteServerTime()).
                // Health and the failure groups both carry it, so one failed read keeps the clock.
                const serverTimed = request => {
                    const sentAt = browserSeconds();

                    return request.then(data => {
                        if (current()) noteServerTime(data && data.as_of, sentAt, browserSeconds());

                        return data;
                    });
                };

                this.polls++;

                const requests = [
                    part('workload', liveRead('/api/workload'), listBody),
                    part('workers', liveRead('/api/workers'), listBody),
                    part('health', serverTimed(liveRead('/api/health', {from: Math.floor(now - 900), to: Math.floor(now)})), objectBody),
                    part('groupsHour', serverTimed(background('/api/failures/groups', {from: Math.floor(now - 3600), to: Math.floor(now)})), objectBody),
                ];

                if (slow) {
                    requests.push(
                        part('counts', background('/api/counts'), objectBody),
                        // An unreadable list (runningBatches() null) is a read with an unknown count, not "no read yet".
                        part('batches', background('/api/batches'), data => runningBatches(objectBody(data)) ?? {count: null, atLeast: false}),
                    );
                }

                return Promise.allSettled(requests).then(results => {
                    if (!current()) return;

                    if (results.slice(0, 3).every(result => result.status === 'fulfilled')) {
                        this.live.at = nowSeconds();
                    }
                });
            },
        },
    };
</script>

<template>
    <div class="o-shell" :class="{'o-shell--narrow': shell.narrow}">
        <poll :request="pollLive" :interval="10" />

        <div v-if="drawerOpen" class="o-shell__scrim" aria-hidden="true" @click="closeDrawer"></div>

        <div v-show="!shell.narrow || shell.navOpen" id="orrery-nav" class="o-shell__nav"
             :role="drawerOpen ? 'dialog' : null" :aria-modal="drawerOpen ? 'true' : null" :aria-label="drawerOpen ? 'Navigation' : null">
            <sidebar :app-name="appName" :environment="environment" :attention="attention" :counts="sidebarCounts"
                     :queues="queues" :queues-unknown="queuesUnknown" :health="healthSummary" :drawer="shell.narrow"
                     @navigate="closeNav" />
        </div>

        <div class="o-shell__main" :inert="drawerOpen">
            <toolbar :now="now" @nav-opened="focusDrawer" />

            <div v-if="downForMaintenance" class="o-shell__maintenance">
                <notice tone="warning" title="This application is in maintenance mode."
                        detail="Queued jobs may not be processed unless your worker uses the --force option." />
            </div>

            <router-view />
        </div>
    </div>
</template>

<style scoped>
    .o-shell {
        background-color: var(--color-bg);
        display: flex;
        font-family: var(--font-sans);
        height: 100vh;
        overflow: hidden;
    }

    .o-shell__nav {
        display: flex;
        flex-shrink: 0;
    }

    /* Below 1024 px the sidebar is a drawer over the content. */
    .o-shell--narrow .o-shell__nav {
        box-shadow: 0 0 24px color-mix(in srgb, var(--color-text) 18%, transparent);
        height: 100vh;
        left: 0;
        max-width: calc(100vw - 48px);
        position: fixed;
        top: 0;
        z-index: 40;
    }

    .o-shell__scrim {
        background-color: color-mix(in srgb, var(--color-text) 24%, transparent);
        inset: 0;
        position: fixed;
        z-index: 39;
    }

    .o-shell__main {
        display: flex;
        flex: 1 1 0%;
        flex-direction: column;
        min-width: 0;
    }

    .o-shell__maintenance {
        padding: 16px 24px 0;
    }
</style>
