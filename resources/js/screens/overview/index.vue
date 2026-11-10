<script>
    import {ANY_ERROR_STATUS, BACKGROUND_FAILURES} from '../../errors.js';
    import BarChart from '../../components/ui/BarChart.vue';
    import BulkRun from '../../components/ui/BulkRun.vue';
    import Button from '../../components/ui/Button.vue';
    import ConfirmSheet from '../../components/ui/ConfirmSheet.vue';
    import Icon from '../../components/ui/Icon.vue';
    import Notice from '../../components/ui/Notice.vue';
    import Sparkline from '../../components/ui/Sparkline.vue';
    import InspectorLayout from '../../shell/InspectorLayout.vue';
    import {failureGroups, groupsIncomplete, sidebarQueues} from '../../shell/data';
    import {live, liveWatcherHealth, requestLiveRefresh, setToolbar} from '../../shell/store';
    import {formatHourMinuteZoned, formatLocal, nowSeconds, relativeTime} from '../../support/time';
    import {codeText} from '../../support/wording.js';
    import {
        GROUPS_BULK_STORAGE_KEY,
        MARKER_LEAD_SECONDS,
        countText,
        groupCountAtLeast,
        groupsSource,
        liveWindow,
        markerName,
        queueName,
        shortName,
    } from '../failed/failures';
    import QueueCard from './QueueCard.vue';
    import {
        GROUP_RANGE_SECONDS,
        GROUP_ROWS,
        MAX_GROUP_NOTICES,
        attentionItems,
        attentionSubtitle,
        controlErrorText,
        controlOutcome,
        controlRequests,
        controlSheet,
        globalControlSelection,
        groupNeedsAttention,
        groupQueues,
        groupsSubtitle,
        hasGlobalPause,
        nativeContinueCommands,
        num,
        overviewAttention,
        queueControlSelection,
        queuesSubtitle,
        retentionOf,
        throughputAnnotations,
        throughputBuckets,
        throughputSubtitle,
    } from './overview';

    /** The screen polls every this many seconds. */
    const POLL_SECONDS = 10;

    /**
     * The statuses that the screen's reads handle themselves: the section
     * shows its own error, and no global alert repeats on every poll. Every
     * read of get() is a poll (refresh()), so it also handles a timeout and
     * a network failure. 401 and 419 still ask for a reload. The selection
     * read of a Retry is user-started and keeps the global alert.
     */
    const SECTION_STATUSES = [400, 403, 404, 405, 408, 409, 422, 429, 500, 502, 503, 504, ANY_ERROR_STATUS, ...BACKGROUND_FAILURES];

    /** The status and the error body of a failed write (a `record_failed` answer is no failure). */
    function errorAnswer(error) {
        const response = error && error.response;

        return {status: response ? response.status : null, data: response ? response.data : null};
    }

    /** "HTTP 500", or "no response" for a failed read. */
    function failureText(error) {
        const status = error && error.response ? error.response.status : null;

        return status ? `HTTP ${status}` : 'no response';
    }

    /**
     * The Overview.
     *
     * - The workload, the workers, the watcher health, the failure groups of
     *   the past hour and the failed count come from the shell's `live` data
     *   (shell/store.js): the screen does not fetch
     *   them again. A part whose last read failed keeps its last data and
     *   shows its own error.
     * - Every poll reads the rest: the control signals, the throughput (and
     *   from it the rate of the last five minutes) and the markers. A
     *   response is applied only while its poll is the newest one.
     * - The attention notices follow the spec's rule (attentionItems()), and
     *   the subtitle also names the watcher health.
     * - A pause, continue or terminate opens a ConfirmSheet with a FROZEN
     *   selection (`snapshotKey`): it names its queue and sends one signal
     *   per alias. The polls never touch the open sheet.
     * - A group notice's Retry starts a bulk run with a
     *   groups source of that group in the live past hour (groupsSource()),
     *   a snapshot of the click. The shared BulkRun freezes it, shows the
     *   frozen total and runs it; its host rules hold (`started` closes
     *   `open`, it stays mounted while a run is shown, leaving the screen
     *   during a run asks first). Its run is stored under the shared groups
     *   key, so after a reload the Failure groups screen recovers it.
     */
    export default {
        BULK_STORAGE_KEY: GROUPS_BULK_STORAGE_KEY,

        components: {
            BarChart,
            BulkRun,
            ConfirmSheet,
            Icon,
            InspectorLayout,
            Notice,
            QueueCard,
            Sparkline,
            'o-button': Button,
        },

        beforeRouteLeave(to) {
            // A run stops when the screen unmounts: ask first (BulkRun keeps nothing running elsewhere).
            if (!this.bulk.running || this.leave.confirmed) return true;

            this.leave = {open: true, to: to.fullPath, confirmed: false, key: this.leave.key + 1};

            return false;
        },

        data() {
            return {
                live,
                now: nowSeconds(),
                pollSeconds: POLL_SECONDS,
                control: null,
                throughput: null,
                markers: [],
                errors: {throughput: null, control: null},
                loaded: {throughput: false, control: false},
                sheet: null,
                sheetOpen: false,
                snapshotKey: 0,
                busy: false,
                controlResult: null,
                bulk: {open: false, source: null, key: 0, runShown: false, running: false},
                leave: {open: false, to: null, confirmed: false, key: 0},
                notice: null,
            };
        },

        computed: {
            leaveText() {
                return codeText('bulk_run_in_progress');
            },

            /** The shell's workload rows, or null before they loaded. */
            workload() {
                return Array.isArray(this.live.workload) ? this.live.workload : null;
            },

            workers() {
                return Array.isArray(this.live.workers) ? this.live.workers : null;
            },

            /** The shell's failure groups of the past hour (`groupsHour`), or null before they loaded. */
            groups() {
                return this.live.groupsHour;
            },

            /**
             * The failed count of `GET /api/counts` ({ count, at_least }):
             * undefined before the counts loaded, null when it is unknown (the
             * read failed), which shows "—".
             */
            failedTotal() {
                if (this.live.countsError) return null;

                if (!this.live.counts) return undefined;

                return this.live.counts.failed && typeof this.live.counts.failed === 'object' ? this.live.counts.failed : null;
            },

            /** The section errors of the shell's parts: the last read failed. */
            sectionErrors() {
                return {queues: this.live.workloadError, groups: this.live.groupsHourError};
            },

            /** A shell part is loaded when it has data or its read failed. */
            sectionLoaded() {
                return {
                    queues: this.live.workload !== null || this.live.workloadError,
                    groups: this.live.groupsHour !== null || this.live.groupsHourError,
                };
            },

            health() {
                return liveWatcherHealth(this.now);
            },

            queueEntries() {
                const labels = new Map(sidebarQueues(this.workload).map(entry => [entry.key, entry.label]));

                return (Array.isArray(this.workload) ? this.workload : [])
                    .filter(row => row && typeof row.name === 'string' && typeof row.connection === 'string')
                    .map(row => {
                        const key = `${row.connection}:${row.name}`;

                        return {key, row, label: labels.get(key) || row.name};
                    });
            },

            /**
             * The attention count of the sidebar (shellAttention(), through
             * overviewAttention()): the definite count, the possible items and
             * the unknown parts, so the subtitle and the sidebar agree.
             */
            attention() {
                return overviewAttention(this.live, this.now);
            },

            subtitle() {
                return attentionSubtitle(this.attention, this.health);
            },

            allItems() {
                if (this.workload === null && this.groups === null) return [];

                return attentionItems(this.workload, this.groups, this.markers, this.now);
            },

            /** The notices: every queue, and the first MAX_GROUP_NOTICES groups. */
            items() {
                let groups = 0;

                return this.allItems.filter(item => item.type === 'queue' || ++groups <= MAX_GROUP_NOTICES);
            },

            /** The recent groups without a notice of their own: { count, atLeast }, or null. */
            moreGroups() {
                const hidden = this.allItems.length - this.items.length;
                // The groups may be incomplete (a truncated scan or a full page), or stale.
                const atLeast = groupsIncomplete(this.groups) || this.live.groupsHourError;

                return hidden > 0 ? {count: hidden, atLeast} : null;
            },

            queuesSubtitle() {
                return queuesSubtitle(this.workers);
            },

            globallyPaused() {
                return hasGlobalPause(this.control);
            },

            buckets() {
                return throughputBuckets(this.throughput);
            },

            chart() {
                const from = num(this.throughput && this.throughput.from);
                const to = num(this.throughput && this.throughput.to);

                if (from === null || to === null) return null;

                return {
                    from,
                    to,
                    bucketSeconds: num(this.throughput.bucket_seconds) || 60,
                    series: [{key: 'completed', label: 'completed', color: 'var(--color-accent)', retentionFrom: retentionOf(this.throughput, 'completed')}],
                    // A retried failure keeps its failed_at, so the band counts the latest failure of every retained job.
                    band: {key: 'failed', label: 'Failed (latest failure of each job)', axisLabel: 'Failed', color: 'var(--color-danger)', retentionFrom: retentionOf(this.throughput, 'failed')},
                    markers: (Array.isArray(this.throughput.markers) ? this.throughput.markers : [])
                        .map(marker => ({at: num(marker && marker.at), kind: marker && marker.kind, label: marker && marker.label}))
                        .filter(marker => marker.at !== null),
                };
            },

            annotations() {
                return this.chart ? throughputAnnotations(this.buckets, this.chart.bucketSeconds) : [];
            },

            throughputSubtitle() {
                return this.throughput ? throughputSubtitle(this.throughput) : null;
            },

            groupList() {
                return failureGroups(this.groups) || [];
            },

            groupRows() {
                return this.groupList.slice(0, GROUP_ROWS);
            },

            groupsTruncated() {
                return !!(this.groups && this.groups.truncated === true);
            },

            /** The error line of the groups section: the shell's last groups read failed. */
            groupsErrorText() {
                return this.groups ? 'The failure groups could not be refreshed.' : 'The failure groups could not be loaded.';
            },

            queuesErrorText() {
                return this.workload ? 'The queues could not be refreshed. The cards show the last data read.' : 'The queues could not be loaded.';
            },

            groupsWindow() {
                const scope = this.groups && this.groups.scope;
                const from = num(scope && scope.from);
                const to = num(scope && scope.to);

                return from !== null && to !== null ? {from, to} : null;
            },

            groupsSubtitle() {
                return groupsSubtitle(this.groups, this.failedTotal);
            },
        },

        watch: {
            subtitle: {
                immediate: true,
                handler(subtitle) {
                    setToolbar({title: 'Overview', subtitle});
                },
            },
        },

        mounted() {
            this.clock = setInterval(() => {
                this.now = nowSeconds();
            }, 1000);
        },

        beforeUnmount() {
            this.unmounted = true;
            clearInterval(this.clock);
        },

        methods: {
            controlErrorText,
            shortName,
            queueName,
            countText,
            relativeTime,
            formatLocal,
            formatHourMinuteZoned,
            markerName,
            groupQueues,
            groupCountAtLeast,
            nativeContinueCommands,
            num,

            get(path, params = {}) {
                return this.$http.get(Orrery.basePath + path, {params, handles: SECTION_STATUSES}).then(response => response.data);
            },

            /**
             * One poll of the screen's own reads: the control signals, the
             * throughput and the markers (the shell polls the rest). Each
             * response is applied only while its poll is the newest one; a
             * failed read marks its section and keeps the last good data. The
             * open sheet is never touched here.
             */
            refresh() {
                const sequence = this.startRequest('overview');
                const now = nowSeconds();
                const live = liveWindow(GROUP_RANGE_SECONDS, now);
                const current = () => this.isCurrentRequest('overview', sequence);
                const read = (path, params, assign, section) => this.get(path, params)
                    .then(data => {
                        if (!current()) return;

                        assign(data);

                        if (section) {
                            this.errors[section] = null;
                            this.loaded[section] = true;
                        }
                    })
                    .catch(error => {
                        if (current() && section) {
                            this.errors[section] = failureText(error);
                            this.loaded[section] = true;
                        }

                        throw error;
                    });

                return Promise.allSettled([
                    read('/api/control', {}, data => {
                        if (!Array.isArray(data)) throw new TypeError('The control answer is not a list.');

                        this.control = data;
                    }, 'control'),
                    read('/api/throughput', {minutes: 60}, data => { this.throughput = data; }, 'throughput'),
                    read('/api/markers', {since: live.from - MARKER_LEAD_SECONDS}, data => {
                        this.markers = (data && Array.isArray(data.markers) ? data.markers : [])
                            .map(marker => ({...marker, at: num(marker && marker.at)}))
                            .filter(marker => marker.at !== null);
                    }),
                ]);
            },

            /** Open a sheet with a new, frozen snapshot. */
            openSheet(sheet) {
                this.sheet = sheet;
                this.snapshotKey++;
                this.sheetOpen = true;
            },

            /**
             * Pause or continue one queue (one signal per alias), or pause,
             * continue or terminate globally. The selection is taken from the
             * row NOW; later polls do not change it.
             */
            openControl(action, entry = null) {
                if (this.sheetOpen || this.busy) return;

                // A control sheet replaces an open bulk sheet (BulkRun host rule 1).
                this.bulk.open = false;

                const selection = entry ? queueControlSelection(action, entry.row, this.live.health ? this.live.health.queue_pause_cache_local : null) : globalControlSelection(action);

                if (entry && !selection.connections.length) return;

                this.openSheet({...controlSheet(selection, entry ? entry.label : null), selection: {...selection, label: entry ? entry.label : null}});
            },

            /**
             * Retry a failure group: a bulk run over that group in the live
             * past hour, on every queue (groupsSource()). The source is a
             * snapshot of now; during a run nothing opens.
             */
            openRetry(group) {
                if (this.sheetOpen || this.busy || !group || typeof group.key !== 'string') return;

                if (this.bulk.running) {
                    this.notice = {tone: 'info', title: codeText('bulk_run_busy'), detail: null};

                    return;
                }

                const source = groupsSource(null, liveWindow(GROUP_RANGE_SECONDS, nowSeconds()), [group.key], [group]);

                if (!source) return;

                this.notice = null;
                this.bulk = {...this.bulk, source, key: this.bulk.key + 1, open: true};
            },

            onBulkStarted() {
                // BulkRun host rule 3: the sheet handed over to the run panel.
                this.bulk = {...this.bulk, open: false, runShown: true, running: true};
            },

            onBulkClose() {
                this.bulk.open = false;

                if (!this.bulk.running) this.bulk.runShown = false;
            },

            onBulkFinished() {
                this.bulk.running = false;

                if (!this.unmounted) requestLiveRefresh();
            },

            confirmLeave() {
                const to = this.leave.to;

                this.leave = {...this.leave, open: false, confirmed: true};

                if (to) this.$router.push(to);
            },

            cancelLeave() {
                this.leave = {...this.leave, open: false, to: null};
            },

            cancelSheet() {
                this.sheetOpen = false;
            },

            /** Post exactly the frozen selection that the sheet emits. */
            confirmSheet(selection) {
                if (!selection) return Promise.resolve();

                return this.postControl(selection);
            },

            /** Send the control signals of the selection, one by one, and report each failure. */
            postControl(selection) {
                if (this.busy) return Promise.resolve();

                this.busy = true;

                const outcomes = [];

                const send = controlRequests(selection).reduce((chain, body) => chain.then(() => this.$http.post(`${Orrery.basePath}/api/control`, body)
                    .then(response => {
                        outcomes.push({body, data: response ? response.data : null});
                    }, error => {
                        outcomes.push({body, failed: true, text: failureText(error), ...errorAnswer(error)});
                    })), Promise.resolve());

                return send
                    .then(() => {
                        this.controlResult = controlOutcome(selection, outcomes);
                    })
                    .finally(() => {
                        this.busy = false;
                        this.sheetOpen = false;

                        // A write that ends after the screen is gone starts no poll.
                        if (!this.unmounted) {
                            this.refresh();
                            // The queue cards come from the shell: ask it for fresh live data now.
                            requestLiveRefresh();
                        }
                    });
            },

            groupAttention(group) {
                return groupNeedsAttention(group, this.now);
            },
        },
    };
</script>

<template>
    <inspector-layout>
        <poll :request="refresh" :interval="pollSeconds" />

        <div v-if="canOperate" class="o-overview__bulk" data-bulk-run>
            <bulk-run :open="bulk.open" action="retry" :source="bulk.source" :selection-key="bulk.key"
                      :storage-key="$options.BULK_STORAGE_KEY" source-kind="groups"
                      @started="onBulkStarted" @close="onBulkClose" @finished="onBulkFinished" />
        </div>

        <div v-if="items.length || moreGroups || controlResult || notice" class="o-overview__notices" data-attention>
            <notice v-if="controlResult" :tone="controlResult.tone" :title="controlResult.title" :detail="controlResult.detail" data-control-result>
                <template #actions>
                    <o-button variant="write" size="sm" @click="controlResult = null">Dismiss</o-button>
                </template>
            </notice>

            <notice v-if="notice" :tone="notice.tone" :title="notice.title" :detail="notice.detail" data-notice>
                <template #actions>
                    <o-button variant="write" size="sm" @click="notice = null">Dismiss</o-button>
                </template>
            </notice>

            <template v-for="item in items" :key="item.key">
                <notice v-if="item.type === 'queue'" tone="warning" :title="item.title" :detail="item.detail"
                        data-attention-item="queue" :data-key="item.key">
                    <template #link>
                        <o-button variant="link" :to="item.to" data-show-queue>Show queue</o-button>
                    </template>
                    <template #actions>
                        <o-button variant="write" icon="pause" operate class="o-overview__action" data-notice-pause
                                  @click="openControl('pause', {row: item.row, label: item.label})">Pause {{ item.label }}…</o-button>
                    </template>
                </notice>

                <notice v-else tone="danger" :title="item.title" :detail="item.detail" data-attention-item="group" :data-key="item.key">
                    <template v-if="item.marker" #badge>
                        <span class="o-chip" data-marker-chip><icon name="marker" :size="12" />{{ markerName(item.marker) }} {{ formatHourMinuteZoned(item.marker.at) }}</span>
                    </template>
                    <template #link>
                        <o-button variant="link" to="/failed" data-show-group>Show group</o-button>
                    </template>
                    <template v-if="!failedReadOnly" #actions>
                        <o-button variant="write" icon="retry" operate class="o-overview__action"
                                  data-notice-retry @click="openRetry(item.group)">{{ item.retryLabel }}</o-button>
                    </template>
                </notice>
            </template>

            <notice v-if="moreGroups" tone="danger" data-more-groups
                    :title="`${moreGroups.atLeast ? 'At least ' : ''}${moreGroups.count.toLocaleString()} more failure ${moreGroups.count === 1 ? 'group' : 'groups'} failed in the last 15 minutes`">
                <template #link>
                    <o-button variant="link" to="/failed">Show all</o-button>
                </template>
            </notice>
        </div>

        <section class="o-overview__section" data-queues>
            <div class="o-section-head">
                <div class="o-section-head__title">Queues</div>
                <div class="o-section-head__subtitle" data-queues-subtitle>{{ queuesSubtitle }}</div>
                <div class="o-overview__spacer"></div>
                <o-button v-if="globallyPaused" variant="write" icon="play" accent operate data-continue-all
                          @click="openControl('continue')">Continue all…</o-button>
                <o-button v-else variant="write" icon="pause" operate data-pause-all @click="openControl('pause')">Pause all…</o-button>
                <span v-if="canOperate" class="o-section-head__divider"></span>
                <o-button variant="danger" icon="power" operate data-terminate-all @click="openControl('terminate')">Terminate all workers…</o-button>
            </div>

            <div v-if="sectionErrors.queues" class="o-overview__error" data-section-error="queues">{{ queuesErrorText }}</div>
            <div v-if="errors.control" class="o-overview__error" data-section-error="control">{{ controlErrorText(control) }}</div>

            <div v-if="!sectionLoaded.queues" class="o-overview__loading" data-loading="queues">Loading…</div>
            <div v-else-if="workload && !queueEntries.length" class="o-overview__empty" data-empty="queues">No queues yet</div>
            <div v-else-if="queueEntries.length" class="o-overview__cards">
                <queue-card v-for="entry in queueEntries" :key="entry.key" :row="entry.row" :label="entry.label" :now="now"
                            :busy="busy" @control="openControl($event, entry)" />
            </div>
        </section>

        <div class="o-overview__bottom">
            <section class="o-overview__section o-overview__half" data-throughput>
                <div class="o-section-head">
                    <div class="o-section-head__title">Throughput</div>
                    <div class="o-section-head__subtitle" data-throughput-subtitle>{{ throughputSubtitle }}</div>
                </div>
                <div class="o-card o-overview__chart">
                    <div v-if="errors.throughput" class="o-overview__error" data-section-error="throughput">
                        {{ throughput ? 'The throughput could not be refreshed' : 'The throughput could not be loaded' }} ({{ errors.throughput }}).
                    </div>
                    <div v-if="!loaded.throughput" class="o-overview__loading" data-loading="throughput">Loading…</div>
                    <bar-chart v-else-if="chart" :from="chart.from" :to="chart.to" :bucket-seconds="chart.bucketSeconds" :buckets="buckets"
                               :series="chart.series" :band="chart.band" :markers="chart.markers" :annotations="annotations" unit="jobs" />
                </div>
            </section>

            <section class="o-overview__section o-overview__half" data-groups>
                <div class="o-section-head">
                    <div class="o-section-head__title">Failure groups</div>
                    <div class="o-section-head__subtitle o-overview__grow" data-groups-subtitle>{{ groupsSubtitle }}</div>
                    <o-button variant="link" to="/failed" data-show-all>Show all</o-button>
                </div>
                <div class="o-card o-groups">
                    <div class="o-groups__head">
                        <span class="o-groups__name">Exception · job · queue</span>
                        <span class="o-groups__spark">Past hour</span>
                        <span class="o-groups__count">Count</span>
                        <span class="o-groups__last">Last seen</span>
                        <span class="o-groups__chevron"></span>
                    </div>
                    <div v-if="sectionErrors.groups" class="o-overview__error o-groups__message" data-section-error="groups">{{ groupsErrorText }}</div>
                    <div v-if="!sectionLoaded.groups" class="o-overview__loading o-groups__message" data-loading="groups">Loading…</div>
                    <div v-else-if="groups && !groupRows.length" class="o-overview__empty o-groups__message" data-empty="groups">
                        {{ groupsTruncated ? 'No failure groups could be read' : 'No failures in the past hour' }}
                    </div>
                    <router-link v-for="group in groupRows" :key="group.key" to="/failed" class="o-groups__row" data-group-row :data-key="group.key">
                        <span class="o-groups__name">
                            <span class="o-groups__line">
                                <span class="o-groups__dot" :class="{'o-groups__dot--on': groupAttention(group)}" data-group-dot></span>
                                <span class="o-groups__class" :title="group.exception_class">{{ shortName(group.exception_class) }}</span>
                                <span class="o-groups__message-text" :title="group.message">{{ group.message || 'No message' }}</span>
                            </span>
                            <span class="o-groups__sub" :title="group.job">{{ shortName(group.job) }} · {{ groupQueues(group) }}</span>
                        </span>
                        <span class="o-groups__spark">
                            <sparkline v-if="groupsWindow" :from="groupsWindow.from" :to="groupsWindow.to" :bucket-seconds="num(groups.bucket_seconds) || 60"
                                       :buckets="group.buckets" value-key="count" :retention-from="num(groups.retention_from)" :width="88" :height="22"
                                       :label="`${shortName(group.exception_class)} failures per minute`" />
                        </span>
                        <span class="o-groups__count" :class="{'o-groups__count--attention': groupAttention(group)}" data-count>{{ countText(num(group.count), groupCountAtLeast(group)) }}</span>
                        <span class="o-groups__last" :title="formatLocal(group.last_failed_at)">{{ relativeTime(group.last_failed_at, now) }}</span>
                        <span class="o-groups__chevron"><icon name="chevron" :size="12" /></span>
                    </router-link>
                </div>
            </section>
        </div>

        <confirm-sheet :open="sheetOpen" :title="sheet ? sheet.title : ''" :message="sheet ? sheet.message : null"
                       :confirm-label="sheet ? sheet.confirmLabel : 'Confirm'" :tone="sheet ? sheet.tone : 'primary'"
                       :selection="sheet ? sheet.selection : null" :snapshot-key="snapshotKey" :busy="busy"
                       @confirm="confirmSheet" @cancel="cancelSheet">
            <template #default="{selection}">
                <div v-if="selection && selection.type === 'control' && selection.scope === 'queue'" class="o-sheet-summary" data-sheet-summary>
                    <div data-sheet-target>Queue {{ selection.queue }} on {{ selection.connections.join(', ') }}</div>
                    <code v-for="command in nativeContinueCommands(selection)" :key="command" class="o-sheet-summary__command" data-sheet-command>{{ command }}</code>
                </div>
            </template>
        </confirm-sheet>

        <confirm-sheet :open="leave.open" title="Leave this screen?" :message="leaveText" confirm-label="Leave"
                       tone="danger" :snapshot-key="leave.key" @confirm="confirmLeave" @cancel="cancelLeave" />
    </inspector-layout>
</template>

<style scoped>
    .o-sheet-summary__command {
        display: block;
        font-family: var(--font-mono);
        font-size: var(--text-caption);
        overflow-wrap: anywhere;
    }

    /* BulkRun's sheet is teleported: the wrapper takes room only for a run or recovery panel. */
    .o-overview__bulk:not(:has(section)) {
        display: none;
    }

    .o-overview__notices {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .o-overview__action {
        min-width: 140px;
    }

    .o-overview__section {
        display: flex;
        flex-direction: column;
        gap: 12px;
        min-width: 0;
    }

    .o-section-head {
        align-items: center;
        display: flex;
        flex-shrink: 0;
        flex-wrap: wrap;
        gap: 8px 10px;
        min-height: 28px;
    }

    .o-section-head__title {
        color: var(--color-text);
        font-size: var(--text-section);
        font-weight: var(--font-weight-semibold);
        letter-spacing: -0.01em;
        line-height: 22px;
        white-space: nowrap;
    }

    .o-section-head__subtitle {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: 18px;
        min-width: 0;
        padding-top: 2px;
    }

    .o-section-head__divider {
        background-color: var(--color-separator);
        flex-shrink: 0;
        height: 20px;
        width: 1px;
    }

    .o-overview__spacer,
    .o-overview__grow {
        flex: 1 1 0%;
    }

    .o-overview__cards {
        display: grid;
        gap: 16px;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    }

    .o-overview__bottom {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
    }

    .o-overview__half {
        flex: 1 1 460px;
    }

    .o-card {
        background-color: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-card);
        box-sizing: border-box;
    }

    .o-overview__chart {
        display: flex;
        flex: 1 1 auto;
        flex-direction: column;
        gap: 8px;
        padding: 14px 16px 12px;
    }

    .o-overview__loading,
    .o-overview__empty {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: 18px;
    }

    .o-overview__error {
        color: var(--color-danger-text);
        font-size: var(--text-table);
        line-height: 18px;
    }

    .o-chip {
        align-items: center;
        background-color: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: 5px;
        box-sizing: border-box;
        color: var(--color-text-secondary);
        display: inline-flex;
        font-size: var(--text-caption);
        font-weight: var(--font-weight-medium);
        gap: 5px;
        height: 20px;
        line-height: 16px;
        padding-inline: 7px;
        white-space: nowrap;
    }

    .o-groups {
        display: flex;
        flex: 1 1 auto;
        flex-direction: column;
        overflow: hidden;
    }

    .o-groups__head,
    .o-groups__row {
        align-items: center;
        box-sizing: border-box;
        display: flex;
        flex-shrink: 0;
        padding-inline: 14px;
    }

    .o-groups__head {
        background-color: var(--color-subtle);
        border-bottom: 1px solid var(--color-separator);
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        height: 32px;
        line-height: 16px;
    }

    .o-groups__row {
        border-bottom: 1px solid var(--color-separator);
        color: inherit;
        height: 56px;
        text-decoration: none;
    }

    .o-groups__row:last-child {
        border-bottom: none;
    }

    .o-groups__row:hover {
        background-color: var(--color-subtle);
    }

    .o-groups__message {
        padding: 14px;
    }

    .o-groups__name {
        display: flex;
        flex: 1 1 0%;
        flex-direction: column;
        gap: 1px;
        min-width: 0;
        padding-right: 12px;
    }

    .o-groups__line {
        align-items: center;
        display: flex;
        gap: 7px;
        min-width: 0;
    }

    .o-groups__dot {
        border-radius: 50%;
        flex-shrink: 0;
        height: 7px;
        width: 7px;
    }

    .o-groups__dot--on {
        background-color: var(--color-danger);
    }

    .o-groups__class {
        color: var(--color-text);
        flex-shrink: 0;
        font-size: var(--text-body);
        font-weight: var(--font-weight-semibold);
        line-height: 18px;
        max-width: 60%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-groups__message-text {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: 18px;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-groups__sub {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: 18px;
        overflow: hidden;
        padding-left: 14px;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-groups__spark {
        display: flex;
        flex-shrink: 0;
        width: 100px;
    }

    .o-groups__count {
        color: var(--color-text);
        flex-shrink: 0;
        font-size: var(--text-body);
        font-variant-numeric: tabular-nums;
        font-weight: var(--font-weight-semibold);
        line-height: 18px;
        text-align: right;
        width: 60px;
    }

    .o-groups__head .o-groups__count {
        font-size: var(--text-caption);
    }

    .o-groups__count--attention {
        color: var(--color-danger-text);
    }

    .o-groups__last {
        color: var(--color-text-secondary);
        flex-shrink: 0;
        font-size: var(--text-table);
        line-height: 18px;
        text-align: right;
        width: 92px;
    }

    .o-groups__head .o-groups__last {
        font-size: var(--text-caption);
    }

    .o-groups__chevron {
        color: var(--color-text-tertiary);
        display: flex;
        flex-shrink: 0;
        justify-content: flex-end;
        width: 22px;
    }

    .o-sheet-summary {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .o-sheet-summary__groups {
        margin: 0;
        padding-left: 18px;
    }

    .o-sheet-summary__class {
        font-weight: var(--font-weight-semibold);
    }

    .o-sheet-summary__note {
        color: var(--color-text-secondary);
    }
</style>
