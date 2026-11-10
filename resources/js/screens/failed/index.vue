<script>
    import {ANY_ERROR_STATUS, BACKGROUND_FAILURES} from '../../errors.js';
    import BarChart from '../../components/ui/BarChart.vue';
    import BulkRun from '../../components/ui/BulkRun.vue';
    import Button from '../../components/ui/Button.vue';
    import ConfirmSheet from '../../components/ui/ConfirmSheet.vue';
    import Notice from '../../components/ui/Notice.vue';
    import Sparkline from '../../components/ui/Sparkline.vue';
    import InspectorLayout from '../../shell/InspectorLayout.vue';
    import {bodyWith, isShapeError, objectBody} from '../../shell/data.js';
    import {live, requestLiveRefresh, revealInspector, setToolbar} from '../../shell/store';
    import {formatDayMinute, formatLocal, nowSeconds, relativeTime, toSeconds} from '../../support/time';
    import {codeText} from '../../support/wording.js';
    import GroupInspector from './GroupInspector.vue';
    import {
        GROUPS_BULK_STORAGE_KEY,
        LATEST_PATH,
        MARKER_LEAD_SECONDS,
        MAX_GROUP_KEYS,
        GROUP_PAGE,
        RANGES,
        failedView,
        failedViewQuery,
        atLeastText,
        chartBuckets,
        countText,
        failedJobsLink,
        firstLine,
        groupCountAtLeast,
        groupQueuesText,
        groupsSource,
        latestParams,
        liveWindow,
        markerName,
        latestSubtitle,
        peakOf,
        peakText,
        perUnit,
        queueName,
        queueOptions,
        rangeOf,
        restartSource,
        rowInGroup,
        shortName,
        subtitleText,
    } from './failures';


    /** The screen polls every this many seconds. */
    const POLL_SECONDS = 10;

    /** The rows of "Latest failed jobs". */
    const LATEST_ROWS = 5;

    /**
     * The HTTP statuses that the screen's reads handle themselves: the
     * section shows its own error. 401 and 419 still ask for a reload.
     */
    const SECTION_STATUSES = [400, 403, 404, 405, 408, 409, 422, 429, 500, 502, 503, 504];

    /**
     * A poll also handles a timeout and a network failure: the section keeps
     * its last data and shows its error, with no global alert on every poll.
     * A read that the user started (a range, queue, page or group change)
     * keeps the global alert for them.
     */
    const BACKGROUND_STATUSES = [...SECTION_STATUSES, ANY_ERROR_STATUS, ...BACKGROUND_FAILURES];

    /**
     * The Failed jobs screen.
     *
     * - The list and the inspector show the LIVE display window: every poll
     *   reads `to = now`, `from = now − range`.
     * - The queue options (the workload), the past-hour groups of the
     *   subtitle and the failed count come from the shell's `live` data
     *   (shell/store.js).
     * - Retry and Forget start a bulk run with a groups
     *   source: the queue scope, the live window of the click and the keys
     *   (the selected group, or the checked ones; groupsSource()). The
     *   shared BulkRun freezes it on the server, shows the frozen total and
     *   runs every member page by page. Its host rules hold: the source is
     *   a snapshot of the click; a user change of the selection (range,
     *   queue, page, group, checks) closes an open sheet; `started` closes
     *   `open`; BulkRun stays mounted while a run is shown (also its
     *   recovery after a reload, `storageKey`), and leaving the screen
     *   during a run asks first.
     * - A group count is "at least" when it is capped or holds rows of a
     *   truncated fallback scan (groupCountAtLeast()). The list's `truncated`
     *   means only that older groups may be missing.
     */
    export default {
        BULK_STORAGE_KEY: GROUPS_BULK_STORAGE_KEY,

        components: {
            BarChart,
            BulkRun,
            ConfirmSheet,
            GroupInspector,
            InspectorLayout,
            Notice,
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
            const now = nowSeconds();
            // The view comes from the URL, so a reload or a shared link shows it again.
            const view = failedView(this.$route.query);

            return {
                ranges: RANGES,
                range: view.range,
                queue: view.queue,
                offset: view.offset,
                span: liveWindow(3600, now),
                now,
                live,
                list: null,
                listLoading: true,
                listError: null,
                failedList: null,
                latestError: null,
                markers: [],
                selectedKey: view.group,
                // A group of the URL is the user's choice: the first group is not selected over it.
                autoSelected: view.group !== null,
                detail: null,
                detailLoading: view.group !== null,
                detailError: null,
                checked: [],
                bulk: {open: false, action: 'retry', source: null, key: 0, runShown: false, running: false},
                leave: {open: false, to: null, confirmed: false, key: 0},
                notice: null,
                pollSeconds: POLL_SECONDS,
            };
        },

        computed: {
            rangeInfo() {
                return rangeOf(this.range);
            },

            groups() {
                return this.list && Array.isArray(this.list.groups) ? this.list.groups : [];
            },

            truncated() {
                return !!(this.list && this.list.truncated === true);
            },

            total() {
                return this.list && typeof this.list.total === 'number' ? this.list.total : null;
            },

            totalsText() {
                if (!this.list) return null;

                const groups = this.total === null ? 0 : this.total;
                const failures = typeof this.list.scanned === 'number' ? this.list.scanned : 0;

                return `${atLeastText(groups, this.truncated)} ${groups === 1 ? 'group' : 'groups'}`
                    + ` · ${atLeastText(failures, this.truncated)} ${failures === 1 ? 'failure' : 'failures'}`;
            },

            /**
             * The default view (past hour, all queues, first page) is the
             * shell's `live.groupsHour`: the screen does not read it again.
             */
            usesLiveList() {
                return this.range === '1h' && !this.queue && this.offset === 0;
            },

            /** The past-hour groups of all queues (the subtitle), from the shell. */
            hour() {
                return this.live.groupsHour;
            },

            queueOptions() {
                const options = queueOptions(this.live.workload);

                if (this.queue && !options.some(option => option.value === this.queue)) {
                    options.push({value: this.queue, label: this.queue});
                }

                return options;
            },

            selectedGroup() {
                return this.groups.find(group => group.key === this.selectedKey) || null;
            },

            bucketSeconds() {
                return this.list && this.list.bucket_seconds > 0 ? this.list.bucket_seconds : (this.range === '1h' ? 60 : 3600);
            },

            chartTitle() {
                return this.bucketSeconds === 3600 ? 'Failures per hour' : 'Failures per minute';
            },

            chartData() {
                return chartBuckets(this.list, this.selectedGroup ? this.selectedKey : null, this.span);
            },

            chartSeries() {
                const retentionFrom = this.list && typeof this.list.retention_from === 'number' ? this.list.retention_from : null;

                if (!this.selectedGroup) {
                    return [{key: 'all', label: 'failures', color: 'var(--color-danger)', retentionFrom}];
                }

                return [
                    {key: 'selected', label: shortName(this.selectedGroup.exception_class), color: 'var(--color-danger)', retentionFrom},
                    {key: 'other', label: 'other groups', color: 'color-mix(in srgb, var(--color-paused) 45%, transparent)', retentionFrom},
                ];
            },

            /**
             * Whether the chart and its peak may miss failures: the scan was
             * truncated, or the page of groups is full, so a next page may
             * exist (the spec's Frontend rule for counts built from a page).
             */
            chartAtLeast() {
                return this.truncated || this.groups.length >= 50;
            },

            chartPeak() {
                return peakOf(this.chartData, 'all');
            },

            chartSubtitle() {
                const parts = [this.rangeInfo.words];

                if (this.chartPeak) {
                    parts.push(peakText(this.chartPeak, this.bucketSeconds, this.chartAtLeast));
                }

                if (this.total !== null && this.total > this.groups.length) {
                    parts.push(`the ${this.groups.length} listed groups`);
                }

                return parts.join(' · ');
            },

            chartAnnotations() {
                return this.chartPeak
                    ? [{at: this.chartPeak.at, text: `Peak ${this.chartPeak.value.toLocaleString()}${perUnit(this.bucketSeconds)}`, target: 'main'}]
                    : [];
            },

            chartMarkers() {
                return this.markers.map(marker => ({at: marker.at, kind: marker.kind, label: marker.label}));
            },

            sparkColumn() {
                return {'1h': 'Past hour', '24h': 'Past 24 h', '7d': 'Past 7 d'}[this.range];
            },

            checkedGroups() {
                return this.checked.map(key => this.groups.find(group => group.key === key)).filter(Boolean);
            },

            checkedCount() {
                return this.checkedGroups.reduce((sum, group) => sum + (typeof group.count === 'number' ? group.count : 0), 0);
            },

            /** A checked group off this page, or a lower-bound group count, makes the sum "at least". */
            checkedCountAtLeast() {
                return this.checkedGroups.length < this.checked.length
                    || this.checkedGroups.some(group => typeof group.count !== 'number' || groupCountAtLeast(group));
            },

            allChecked() {
                return this.groups.length > 0 && this.groups.every(group => this.checked.includes(group.key));
            },

            latestRows() {
                return this.failedList && Array.isArray(this.failedList.jobs) ? this.failedList.jobs.slice(0, LATEST_ROWS) : [];
            },

            /** BulkRun stays mounted for the operator: the recovery of a run after a reload, and a run while it is shown. */
            bulkMounted() {
                return this.canOperate;
            },

            /** The Jobs list behind the selected group, or the Failed tab of the screen's queue. */
            jobsLink() {
                return failedJobsLink(this.selectedGroup, this.queue || null);
            },

            leaveText() {
                return codeText('bulk_run_in_progress');
            },

            /**
             * The failed count of `GET /api/counts` ({ count, at_least }):
             * undefined before the counts loaded, null when it is unknown.
             */
            failedTotal() {
                if (this.live.countsError) return null;

                if (!this.live.counts) return undefined;

                return this.live.counts.failed && typeof this.live.counts.failed === 'object' ? this.live.counts.failed : null;
            },

            subtitle() {
                return subtitleText(this.hour, this.failedTotal);
            },

            hasPrevious() {
                return this.offset > 0;
            },

            hasNext() {
                return this.total !== null && this.offset + this.groups.length < this.total;
            },

            empty() {
                return !this.listLoading && this.list && this.groups.length === 0 && this.offset === 0;
            },
        },

        watch: {
            subtitle: {
                immediate: true,
                handler(subtitle) {
                    setToolbar({title: 'Failure groups', subtitle});
                },
            },

            'live.groupsHour'() {
                if (this.usesLiveList) this.applyLiveList();
            },

            'live.groupsHourError'() {
                if (this.usesLiveList) this.applyLiveList();
            },

            /** A link to another view of this screen (the sidebar, back and forward): show that view. */
            '$route.query'(query) {
                if (this.$route.name !== 'failed-jobs') return;

                const view = failedView(query);

                if (JSON.stringify(failedViewQuery(view)) === JSON.stringify(failedViewQuery(this.currentView()))) return;

                this.cancelPreparation();
                this.checked = [];
                this.range = view.range;
                this.queue = view.queue;
                this.offset = view.offset;
                this.autoSelected = view.group !== null;
                this.selectedKey = view.group;
                this.reload();
            },
        },

        mounted() {
            this.clock = setInterval(() => {
                this.now = nowSeconds();
            }, 1000);
        },

        beforeUnmount() {
            clearInterval(this.clock);
        },

        methods: {
            shortName,
            queueName,
            countText,
            atLeastText,
            relativeTime,
            formatDayMinute,
            formatLocal,
            latestSubtitle,
            markerName,
            firstLine,
            groupCountAtLeast,
            groupQueuesText,

            /** A read; `background` for a poll (BACKGROUND_STATUSES), else a user-started read. */
            get(path, params = {}, background = false) {
                return this.$http.get(Orrery.basePath + path, {params, handles: background ? BACKGROUND_STATUSES : SECTION_STATUSES})
                    .then(response => response.data);
            },

            errorText(error) {
                if (isShapeError(error)) return 'unreadable answer (reload the page; you may need to sign in again)';

                const status = error && error.response ? error.response.status : null;

                return status ? `HTTP ${status}` : 'no response';
            },

            /** The 10 s poll: a background refresh. */
            poll() {
                return this.refresh(true);
            },

            groupParams(live, extra = {}) {
                const params = {from: live.from, to: live.to, ...extra};

                if (this.queue) params.queue = this.queue;

                return params;
            },

            /**
             * One poll: the live window moves to now, and every part of the
             * screen reads it. Each response is applied only while its poll is
             * the newest one, so a late response never overwrites newer data.
             * A bulk run in progress is never touched here.
             * Each part keeps its last data and shows its own error when its
             * read fails. `background` marks a poll (see get()).
             */
            refresh(background = false) {
                const sequence = this.startRequest('screen');
                const now = nowSeconds();
                const live = liveWindow(this.rangeInfo.seconds, now);
                const current = () => this.isCurrentRequest('screen', sequence);
                const apply = assign => data => {
                    if (current()) assign(data);
                };
                const requests = [];

                if (this.usesLiveList) {
                    this.applyLiveList();
                } else {
                    requests.push(this.get('/api/failures/groups', this.groupParams(live, this.offset ? {offset: this.offset} : {}), background)
                        // A body of another shape (a login page answered with 200) is a failed read, never "no groups".
                        .then(data => bodyWith(data, 'groups'))
                        .then(apply(data => {
                            if (this.offset > 0 && Array.isArray(data.groups) && !data.groups.length) {
                                // The page ran past the end (groups left the window): back to the first page.
                                this.offset = 0;
                                this.syncUrl();
                                this.reload();

                                return;
                            }

                            this.applyList(data, live);
                        })).catch(error => {
                            if (current()) {
                                this.listLoading = false;
                                this.listError = this.errorText(error);
                            }

                            throw error;
                        }));
                }

                requests.push(
                    this.get(LATEST_PATH, latestParams(LATEST_ROWS), background).then(data => bodyWith(data, 'jobs')).then(apply(data => {
                        this.failedList = data;
                        this.latestError = null;
                    })).catch(error => {
                        if (current()) this.latestError = this.errorText(error);
                    }),
                    this.get('/api/markers', {since: live.from - MARKER_LEAD_SECONDS}, background).then(apply(data => {
                        this.markers = data && Array.isArray(data.markers) ? data.markers : [];
                    })),
                );

                if (this.selectedKey) {
                    requests.push(this.loadDetail(live, background));
                }

                return Promise.allSettled(requests);
            },

            /** Show a group list read in its window, and select its first group once. */
            applyList(data, window) {
                this.span = window;
                this.list = data;
                this.listLoading = false;
                this.listError = null;

                if (!this.selectedKey && !this.autoSelected && Array.isArray(data.groups) && data.groups.length) {
                    this.autoSelected = true;
                    this.selectGroup(data.groups[0].key);
                }
            },

            /**
             * The default view from the shell's `live.groupsHour`, in the
             * window of that read. A failed shell read keeps the last list
             * with its error.
             */
            applyLiveList() {
                const data = this.live.groupsHour;
                const scope = data && data.scope ? data.scope : null;
                const from = toSeconds(scope && scope.from);
                const to = toSeconds(scope && scope.to);

                if (data) {
                    this.applyList(data, from !== null && to !== null ? {from, to} : liveWindow(3600, nowSeconds()));
                }

                this.listError = this.live.groupsHourError ? 'the last read failed' : null;
                this.listLoading = !data && !this.live.groupsHourError;
            },

            /** Read the detail of the selected group in the given window (display only). */
            loadDetail(live = this.span, background = false) {
                const key = this.selectedKey;

                if (!key) return Promise.resolve();

                const sequence = this.startRequest('detail');

                return this.get(`/api/failures/groups/${key}`, this.groupParams(live), background)
                    .then(objectBody)
                    .then(data => {
                        if (this.isCurrentRequest('detail', sequence) && this.selectedKey === key) {
                            this.detail = data;
                            this.detailLoading = false;
                            this.detailError = null;
                        }
                    })
                    .catch(error => {
                        if (this.isCurrentRequest('detail', sequence)) {
                            this.detailLoading = false;
                            this.detailError = this.errorText(error);
                        }
                    });
            },

            /** A change of the range, the queue or the page: a new foreground load. */
            reload() {
                this.listLoading = true;
                this.list = null;
                this.detail = null;
                this.detailLoading = !!this.selectedKey;
                this.markers = [];

                return this.refresh();
            },

            /**
             * A change of the group, the checks, the range, the queue or the
             * page changes what the user means: an open bulk sheet closes
             * (BulkRun host rule 1), so a frozen selection never outlives it.
             * A run that started goes on.
             */
            cancelPreparation() {
                this.bulk.open = false;
            },

            /** The view that the screen shows now (failures.js failedView()). */
            currentView() {
                return {range: this.range, queue: this.queue, offset: this.offset, group: this.selectedKey};
            },

            /** Keep the URL on the view, without a history entry per change. */
            syncUrl() {
                const query = failedViewQuery(this.currentView());

                if (JSON.stringify(query) === JSON.stringify(failedViewQuery(failedView(this.$route.query)))) return;

                this.$router.replace({query}).catch(() => {});
            },

            setRange(key) {
                if (this.range === key) return;

                this.cancelPreparation();
                this.range = key;
                this.offset = 0;
                this.checked = [];
                this.syncUrl();
                this.reload();
            },

            setQueue(value) {
                this.cancelPreparation();
                this.queue = value;
                this.offset = 0;
                this.checked = [];
                this.syncUrl();
                this.reload();
            },

            page(delta) {
                this.cancelPreparation();
                this.offset = Math.max(0, this.offset + delta * GROUP_PAGE);
                this.syncUrl();
                this.reload();
            },

            /** A group the user opened: in the narrow layout its inspector opens over the list. */
            openGroup(key) {
                this.selectGroup(key);
                revealInspector();
            },

            selectGroup(key) {
                if (this.selectedKey === key) return;

                this.cancelPreparation();
                this.selectedKey = key;
                this.syncUrl();
                this.detail = null;
                this.detailError = null;
                this.detailLoading = true;
                this.loadDetail();
            },

            toggleCheck(key) {
                this.cancelPreparation();
                this.checked = this.checked.includes(key)
                    ? this.checked.filter(entry => entry !== key)
                    : [...this.checked, key];
            },

            toggleAll() {
                this.cancelPreparation();

                if (this.allChecked) {
                    const keys = this.groups.map(group => group.key);

                    this.checked = this.checked.filter(key => !keys.includes(key));
                } else {
                    this.checked = [...new Set([...this.checked, ...this.groups.map(group => group.key)])];
                }
            },

            deselect() {
                this.cancelPreparation();
                this.checked = [];
            },

            /**
             * Start a bulk run over failure groups: the source is
             * a snapshot of now (the queue scope, the live window, the keys).
             * During a run, or for more than MAX_GROUP_KEYS groups, nothing
             * opens; the notice says why.
             */
            openBulk(action, keys) {
                // The names of the groups, for the sheet: the list rows, or the inspector's detail.
                const known = [...this.groups, ...(this.detail && this.detail.group ? [this.detail.group] : [])];
                const source = groupsSource(this.queue || null, liveWindow(this.rangeInfo.seconds, nowSeconds()), keys, known);

                if (!source && keys.length > MAX_GROUP_KEYS) {
                    this.notice = {tone: 'warning', title: `Select at most ${MAX_GROUP_KEYS} groups for one run.`, detail: null};

                    return;
                }

                this.startBulk(action, source);
            },

            /** Opens the bulk sheet with a frozen source; during a run nothing opens. */
            startBulk(action, source) {
                if (this.bulk.running) {
                    this.notice = {tone: 'info', title: codeText('bulk_run_busy'), detail: null};

                    return;
                }

                if (!source) return;

                this.notice = null;
                this.bulk = {...this.bulk, action, source, key: this.bulk.key + 1, open: true};
            },

            onBulkStarted() {
                // BulkRun host rule 3: the sheet handed over to the run panel.
                this.bulk = {...this.bulk, open: false, runShown: true, running: true};
                this.checked = [];
            },

            onBulkClose() {
                this.bulk.open = false;

                // A refused open during a run (BulkRun emits `close`) keeps the run panel.
                if (!this.bulk.running) this.bulk.runShown = false;
            },

            onBulkFinished() {
                this.bulk.running = false;
                this.refresh(true);
                requestLiveRefresh();
            },

            /**
             * "Start a new run…" of a recovered run: the same groups, queue and
             * window that its operator confirmed (failures.js restartSource()),
             * never the group selected on the screen now.
             */
            onBulkRestart(action, recovered) {
                const source = restartSource(recovered);

                if (source) {
                    this.startBulk(action === 'forget' ? 'forget' : 'retry', source);
                } else {
                    this.notice = {tone: 'info', title: 'Select a failure group, then use its Retry or Forget.', detail: null};
                }
            },

            confirmLeave() {
                const to = this.leave.to;

                this.leave = {...this.leave, open: false, confirmed: true};

                if (to) this.$router.push(to);
            },

            cancelLeave() {
                this.leave = {...this.leave, open: false, to: null};
            },

            latestInGroup(row) {
                return !!this.selectedGroup && rowInGroup(row, this.selectedGroup);
            },

            firstTag(row) {
                const tags = row && Array.isArray(row.tags) ? row.tags : [];

                return tags.length && typeof tags[0] === 'string' ? tags[0] : null;
            },

            attemptOf(row) {
                const attempts = toSeconds(row.attempts);

                return attempts === null ? '—' : `attempt ${attempts}`;
            },
        },
    };
</script>

<template>
    <inspector-layout>
        <poll :request="poll" :interval="pollSeconds" />

        <toolbar-controls>
            <div class="o-segmented o-failed__views" role="tablist" aria-label="View">
                <span class="o-segmented__item o-segmented__item--active" role="tab" aria-selected="true">Groups</span>
                <router-link :to="{path: '/jobs', query: {status: 'failed'}}" class="o-segmented__item" role="tab" aria-selected="false" data-jobs-view>Jobs</router-link>
            </div>
        </toolbar-controls>

        <div class="o-failed__filters">
            <label class="o-select">
                <span class="o-select__label">Queue</span>
                <select class="o-select__input" data-queue-filter :value="queue" @change="setQueue($event.target.value)">
                    <option value="">All queues</option>
                    <option v-for="option in queueOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
            </label>

            <div class="o-segmented" role="group" aria-label="Range">
                <button v-for="entry in ranges" :key="entry.key" type="button" class="o-segmented__item"
                        :class="{'o-segmented__item--active': entry.key === range}" :aria-pressed="entry.key === range ? 'true' : 'false'"
                        :data-range="entry.key" @click="setRange(entry.key)">{{ entry.label }}</button>
            </div>

            <div class="o-failed__spacer"></div>

            <div v-if="totalsText" class="o-failed__totals" data-totals>{{ totalsText }}</div>
        </div>

        <notice v-if="failedReadOnly" tone="info" title="These failures are read-only." :detail="failedReadOnly.message" data-read-only>
            <template v-if="failedReadOnly.reason" #badge>
                <code class="o-code-label" data-read-only-code>{{ failedReadOnly.reason }}</code>
            </template>
        </notice>

        <div v-if="bulkMounted" class="o-failed__bulk" data-bulk-run>
            <bulk-run :open="bulk.open" :action="bulk.action" :source="bulk.source" :selection-key="bulk.key"
                      recover :storage-key="$options.BULK_STORAGE_KEY" source-kind="groups"
                      @started="onBulkStarted" @close="onBulkClose" @finished="onBulkFinished" @restart="onBulkRestart" />
        </div>

        <notice v-if="notice" :tone="notice.tone" :title="notice.title" :detail="notice.detail" data-notice>
            <template #actions>
                <o-button variant="write" size="sm" @click="notice = null">Dismiss</o-button>
            </template>
        </notice>

        <notice v-if="listError" tone="danger" data-list-error
                :title="list ? 'The failure groups could not be refreshed.' : 'The failure groups could not be loaded.'"
                :detail="list ? `${listError}. The list shows the last data it read.` : `${listError}.`" />

        <notice v-if="truncated" tone="info" data-truncated
                title="Only the newest failures were scanned, so older groups may be missing."
                :detail="`${list.scanned.toLocaleString()} failures scanned. A count marked ≥ is a lower bound.`" />

        <section class="o-card o-failed__chart" data-chart>
            <div class="o-card__head">
                <div class="o-card__title">{{ chartTitle }}</div>
                <div class="o-card__subtitle" data-chart-subtitle>{{ chartSubtitle }}</div>
                <div class="o-failed__spacer"></div>
                <div class="o-legend">
                    <span v-for="entry in chartSeries" :key="entry.key" class="o-legend__item">
                        <span class="o-legend__swatch" :style="{backgroundColor: entry.color}"></span><span class="o-legend__label" :title="entry.label">{{ entry.label }}</span>
                    </span>
                </div>
            </div>
            <div v-if="listLoading" class="o-failed__loading">Loading…</div>
            <bar-chart v-else :from="span.from" :to="span.to" :bucket-seconds="bucketSeconds" :buckets="chartData"
                       :series="chartSeries" :markers="chartMarkers" :annotations="chartAnnotations" unit="failures" />
        </section>

        <section class="o-card o-failed__groups" data-groups>
            <div v-if="checked.length && canOperateFailures" class="o-groupbar" data-bulk-bar>
                <span class="o-check o-check--on o-check--mixed" aria-hidden="true"></span>
                <span class="o-groupbar__title">{{ checked.length }} {{ checked.length === 1 ? 'group' : 'groups' }} selected</span>
                <span class="o-groupbar__count" data-bulk-count>{{ checkedCountAtLeast ? 'at least ' : '' }}{{ checkedCount.toLocaleString() }} failed {{ checkedCount === 1 ? 'job' : 'jobs' }}</span>
                <span class="o-failed__spacer"></span>
                <o-button variant="write" icon="retry" operate data-bulk-retry
                          @click="openBulk('retry', checked)">Retry {{ checkedCountAtLeast ? '≥ ' : '' }}{{ checkedCount.toLocaleString() }} jobs…</o-button>
                <o-button variant="danger" operate data-bulk-forget
                          @click="openBulk('forget', checked)">Forget…</o-button>
                <button type="button" class="o-groupbar__deselect" data-bulk-deselect @click="deselect">Deselect</button>
            </div>

            <div class="o-failed__scroller" data-groups-scroller>
            <div class="o-table__head">
                <span class="o-col-check">
                    <button v-if="canOperateFailures && groups.length" type="button" class="o-check" :class="{'o-check--on': allChecked}"
                            :aria-pressed="allChecked ? 'true' : 'false'" title="Select every listed group" data-check-all @click="toggleAll"></button>
                </span>
                <span class="o-col-exception o-head-cell">Exception</span>
                <span class="o-col-job o-head-cell">Job</span>
                <span class="o-col-queue o-head-cell">Queue</span>
                <span class="o-col-count o-head-cell">Count</span>
                <span class="o-col-last o-head-cell">Last seen</span>
                <span class="o-col-spark o-head-cell">{{ sparkColumn }}</span>
            </div>

            <div v-if="listLoading" class="o-failed__loading" data-loading>Loading…</div>

            <div v-else-if="empty" class="o-failed__empty" data-empty>No failures in this range</div>

            <div v-for="group in groups" :key="group.key" class="o-row" :class="{'o-row--selected': group.key === selectedKey}"
                 data-group-row :data-key="group.key" role="button" tabindex="0"
                 @click="openGroup(group.key)" @keydown.enter.self.prevent="openGroup(group.key)">
                <span class="o-col-check">
                    <button v-if="canOperateFailures" type="button" class="o-check" :class="{'o-check--on': checked.includes(group.key)}"
                            :aria-pressed="checked.includes(group.key) ? 'true' : 'false'" :aria-label="`Select ${shortName(group.exception_class)}`"
                            data-group-check @click.stop="toggleCheck(group.key)"></button>
                </span>
                <span class="o-col-exception">
                    <span class="o-row__class" :title="group.exception_class">{{ shortName(group.exception_class) }}</span>
                    <span class="o-row__message" :title="group.message">{{ group.message || 'No message' }}</span>
                </span>
                <span class="o-col-job" :title="group.job">{{ shortName(group.job) }}</span>
                <span class="o-col-queue" :title="groupQueuesText(group)" data-queues>{{ groupQueuesText(group, queueName) }}</span>
                <span class="o-col-count" data-count>{{ countText(group.count, groupCountAtLeast(group)) }}</span>
                <span class="o-col-last" :title="formatLocal(group.last_failed_at)">{{ relativeTime(group.last_failed_at, now) }}</span>
                <span class="o-col-spark">
                    <sparkline :from="span.from" :to="span.to" :bucket-seconds="bucketSeconds" :buckets="group.buckets"
                               value-key="count" :retention-from="list.retention_from" :width="88" :height="22"
                               :label="`${shortName(group.exception_class)} failures ${bucketSeconds === 3600 ? 'per hour' : 'per minute'}`" />
                </span>
            </div>

            </div>

            <div v-if="hasPrevious || hasNext" class="o-pager">
                <o-button variant="write" size="sm" :disabled="!hasPrevious" data-page-previous @click="page(-1)">Previous 50</o-button>
                <span class="o-pager__text">Groups {{ offset + 1 }}–{{ offset + groups.length }} of {{ atLeastText(total, truncated) }}</span>
                <o-button variant="write" size="sm" :disabled="!hasNext" data-page-next @click="page(1)">Next 50</o-button>
            </div>
        </section>

        <section class="o-latest" data-latest>
            <div class="o-card__head">
                <div class="o-card__title">Latest failed jobs</div>
                <div class="o-card__subtitle" data-latest-subtitle>{{ latestSubtitle(now) }}</div>
                <div class="o-failed__spacer"></div>
                <o-button variant="link" :to="{path: '/jobs', query: {status: 'failed'}}" data-show-all-jobs>Show all in Jobs</o-button>
            </div>
            <div class="o-latest__rows o-failed__scroller">
                <div v-if="latestError" class="o-failed__empty" data-latest-error>
                    {{ failedList ? 'The latest failed jobs could not be refreshed' : 'The latest failed jobs could not be loaded' }} ({{ latestError }}).
                </div>
                <div v-if="failedList && !latestRows.length" class="o-failed__empty">No failed jobs are kept.</div>
                <router-link v-for="row in latestRows" :key="row.id" class="o-latest__row" data-latest-row
                             :to="{name: 'job-detail', params: {uuid: row.id}}">
                    <span class="o-latest__time" :title="formatLocal(row.failed_at)">{{ formatDayMinute(row.failed_at, now) }}</span>
                    <span class="o-latest__job">
                        <span :title="row.name">{{ shortName(row.name) }}</span>
                        <span v-if="firstTag(row)" class="o-chip">{{ firstTag(row) }}</span>
                    </span>
                    <span class="o-latest__exception" :title="row.exception_class">
                        <span class="o-dot" :class="{'o-dot--group': latestInGroup(row)}" data-latest-dot></span>{{ shortName(row.exception_class) }}
                    </span>
                    <span class="o-latest__queue" :title="`${row.connection}:${row.queue}`">{{ row.queue }}</span>
                    <span class="o-latest__attempt">{{ attemptOf(row) }}</span>
                    <span class="o-latest__ago">{{ relativeTime(row.failed_at, now) }}</span>
                </router-link>
            </div>
        </section>

        <template v-if="selectedKey" #inspector>
            <group-inspector :group-key="selectedKey" :detail="detail" :loading="detailLoading" :error="detailError" :markers="markers" :window="span"
                             :range-words="rangeInfo.words" :now="now" :jobs-link="jobsLink"
                             @retry="openBulk('retry', [selectedKey])" @forget="openBulk('forget', [selectedKey])" />
        </template>

        <confirm-sheet :open="leave.open" title="Leave this screen?" :message="leaveText" confirm-label="Leave"
                       tone="danger" :snapshot-key="leave.key" @confirm="confirmLeave" @cancel="cancelLeave" />
    </inspector-layout>
</template>

<style scoped>
    .o-failed__filters {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    /* BulkRun's sheet is teleported: the wrapper takes room only for a run or recovery panel. */
    .o-failed__bulk:not(:has(section)) {
        display: none;
    }

    /* A wide table scrolls inside its own container, never the page. */
    .o-failed__scroller {
        overflow-x: auto;
    }

    .o-failed__scroller > .o-table__head,
    .o-failed__scroller > .o-row {
        min-width: 680px;
    }

    .o-latest__rows.o-failed__scroller > .o-latest__row {
        min-width: 600px;
    }

    /* A header cell never runs into the next one (the header overlapped "Exception" and "Job"). */
    .o-head-cell {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-failed__spacer {
        flex-grow: 1;
    }

    .o-failed__totals {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: 18px;
    }

    .o-select {
        align-items: center;
        background-color: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-control);
        box-sizing: border-box;
        display: flex;
        gap: 6px;
        height: 28px;
        margin: 0;
        padding-left: 10px;
        padding-right: 4px;
    }

    /* The select inside draws no outline: the box shows the focus ring. */
    .o-select:focus-within {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: 1px;
    }

    .o-select__label {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: 18px;
    }

    .o-select__input {
        background: transparent;
        border: none;
        color: var(--color-text);
        cursor: pointer;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        font-weight: var(--font-weight-medium);
        line-height: 18px;
        outline: none;
        padding: 0 2px;
    }

    .o-segmented {
        align-items: center;
        background-color: var(--color-separator);
        border-radius: 7px;
        display: flex;
        padding: 2px;
    }

    .o-segmented__item {
        background: none;
        border: none;
        border-radius: 5px;
        color: var(--color-text);
        cursor: pointer;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        line-height: 18px;
        padding: 3px 11px;
        text-decoration: none;
    }

    .o-segmented__item:hover {
        color: var(--color-text);
        text-decoration: none;
    }

    .o-segmented__item--active {
        background-color: var(--color-bg);
        box-shadow: var(--shadow-raised);
        cursor: default;
        font-weight: var(--font-weight-semibold);
    }

    .o-card {
        background-color: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-card);
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
    }

    .o-failed__chart {
        gap: 10px;
        padding: 16px 20px 14px;
    }

    .o-card__head {
        align-items: baseline;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .o-card__title {
        color: var(--color-text);
        font-size: var(--text-body);
        font-weight: var(--font-weight-semibold);
        line-height: 20px;
    }

    .o-card__subtitle {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: 18px;
    }

    /* A long group name truncates inside the card, with its full text as a title. */
    .o-legend {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 4px 14px;
        min-width: 0;
    }

    .o-legend__item {
        align-items: center;
        color: var(--color-text-secondary);
        display: flex;
        font-size: var(--text-caption);
        gap: 6px;
        line-height: 16px;
        max-width: 100%;
        min-width: 0;
    }

    .o-legend__label {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-legend__swatch {
        border-radius: 2px;
        display: inline-block;
        flex-shrink: 0;
        height: 10px;
        width: 10px;
    }

    .o-failed__loading,
    .o-failed__empty {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: 18px;
        padding: 20px 14px;
        text-align: center;
    }

    .o-failed__groups {
        overflow: clip;
    }

    .o-groupbar {
        align-items: center;
        background-color: var(--color-subtle);
        border-bottom: 1px solid var(--color-separator);
        display: flex;
        flex-wrap: wrap;
        gap: 6px 10px;
        min-height: 42px;
        padding: 6px 12px 6px 14px;
        box-sizing: border-box;
    }

    .o-groupbar__title {
        color: var(--color-text);
        font-size: var(--text-table);
        font-weight: var(--font-weight-semibold);
        line-height: 18px;
    }

    .o-groupbar__count {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: 18px;
    }

    .o-groupbar__deselect {
        background: none;
        border: none;
        color: var(--color-accent);
        cursor: pointer;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        line-height: 18px;
        padding: 0 0 0 4px;
    }

    .o-table__head,
    .o-row {
        align-items: center;
        box-sizing: border-box;
        display: flex;
        padding-inline: 14px;
    }

    .o-table__head {
        border-bottom: 1px solid var(--color-separator);
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        height: 30px;
        line-height: 16px;
    }

    .o-row {
        border-bottom: 1px solid var(--color-separator);
        color: var(--color-text);
        cursor: pointer;
        font-size: var(--text-table);
        height: 52px;
        line-height: 18px;
    }

    .o-row:last-of-type {
        border-bottom: none;
    }

    .o-row:hover {
        background-color: var(--color-subtle);
    }

    .o-row--selected,
    .o-row--selected:hover {
        background-color: var(--color-accent-tint);
        box-shadow: var(--color-accent) 3px 0 0 inset;
    }

    .o-row:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: -2px;
    }

    .o-col-check { align-items: center; display: flex; flex-shrink: 0; width: 30px; }
    .o-col-exception { display: flex; flex: 1 1 0%; flex-direction: column; min-width: 0; padding-right: 12px; }
    .o-table__head .o-col-exception { display: block; }
    .o-col-job { flex-shrink: 0; overflow: hidden; padding-right: 8px; text-overflow: ellipsis; white-space: nowrap; width: 168px; }
    .o-col-queue { flex-shrink: 0; overflow: hidden; padding-right: 8px; text-overflow: ellipsis; white-space: nowrap; width: 90px; }
    .o-col-count { flex-shrink: 0; font-variant-numeric: tabular-nums; text-align: right; width: 64px; }
    .o-col-last { color: var(--color-text-secondary); flex-shrink: 0; text-align: right; width: 96px; }
    .o-col-spark { align-items: center; display: flex; flex-shrink: 0; padding-left: 16px; width: 104px; }

    .o-row .o-col-count {
        font-size: var(--text-body);
        font-weight: var(--font-weight-semibold);
    }

    .o-row__class {
        font-weight: var(--font-weight-semibold);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-row__message {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-check {
        background-color: var(--color-bg);
        border: 1px solid color-mix(in srgb, var(--color-text) 28%, transparent);
        border-radius: 4px;
        box-sizing: border-box;
        cursor: pointer;
        display: inline-block;
        height: 16px;
        padding: 0;
        position: relative;
        width: 16px;
    }

    .o-check--on {
        background-color: var(--color-accent);
        border-color: var(--color-accent);
    }

    .o-check--on::after {
        border: solid var(--color-on-accent);
        border-width: 0 1.6px 1.6px 0;
        content: '';
        height: 7px;
        left: 4.5px;
        position: absolute;
        top: 1.5px;
        transform: rotate(45deg);
        width: 3.5px;
    }

    .o-check--mixed::after {
        border-width: 0 0 1.6px 0;
        height: 0;
        left: 3px;
        top: 6.5px;
        transform: none;
        width: 8px;
    }

    .o-pager {
        align-items: center;
        border-top: 1px solid var(--color-separator);
        display: flex;
        gap: 12px;
        justify-content: space-between;
        padding: 8px 14px;
    }

    .o-pager__text {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
    }

    .o-latest {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .o-latest__rows {
        border-top: 1px solid var(--color-separator);
        display: flex;
        flex-direction: column;
    }

    .o-latest__row {
        align-items: center;
        border-bottom: 1px solid var(--color-separator);
        color: var(--color-text);
        display: flex;
        font-size: var(--text-table);
        height: 36px;
        line-height: 18px;
        padding-inline: 4px;
        text-decoration: none;
    }

    .o-latest__row:hover {
        background-color: var(--color-subtle);
        color: var(--color-text);
        text-decoration: none;
    }

    /* "09:17" keeps its 56 px column; an earlier day adds its date, and a gap keeps it off the job name. */
    .o-latest__time { box-sizing: border-box; color: var(--color-text-secondary); flex-shrink: 0; min-width: 56px; padding-right: 12px; white-space: nowrap; }
    .o-latest__job { align-items: center; display: flex; flex: 1 1 0%; gap: 8px; min-width: 0; overflow: hidden; white-space: nowrap; }
    .o-latest__exception { align-items: center; display: flex; flex-shrink: 0; gap: 7px; overflow: hidden; white-space: nowrap; width: 190px; }
    .o-latest__queue { flex-shrink: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; width: 70px; }
    .o-latest__attempt { color: var(--color-text-secondary); flex-shrink: 0; text-align: right; width: 80px; }
    .o-latest__ago { color: var(--color-text-secondary); flex-shrink: 0; text-align: right; width: 96px; }

    /* The code of a refusal or a read-only reason, for operators: small, beside the text (gate fixes 1 and 5). */
    .o-code-label {
        background-color: color-mix(in srgb, var(--color-text) 7%, transparent);
        border-radius: 5px;
        color: var(--color-text-secondary);
        font-family: var(--font-mono);
        font-size: var(--text-caption);
        line-height: 16px;
        padding: 1px 6px;
        white-space: nowrap;
    }

    .o-chip {
        background-color: color-mix(in srgb, var(--color-text) 7%, transparent);
        border-radius: 5px;
        font-size: var(--text-caption);
        line-height: 16px;
        overflow: hidden;
        padding: 1px 6px;
        text-overflow: ellipsis;
    }

    .o-dot {
        background-color: var(--color-paused);
        border-radius: 50%;
        display: inline-block;
        flex-shrink: 0;
        height: 6px;
        width: 6px;
    }

    .o-dot--group {
        background-color: var(--color-danger);
    }

    /* Below 768 px the toolbar has no room for the view switch; "Show all in Jobs" stays on the page. */
    @media (max-width: 767px) {
        .o-failed__views {
            display: none;
        }
    }
</style>
