<script>
    import {ANY_ERROR_STATUS, BACKGROUND_FAILURES} from '../../errors.js';
    import BulkRun from '../../components/ui/BulkRun.vue';
    import Button from '../../components/ui/Button.vue';
    import ConfirmSheet from '../../components/ui/ConfirmSheet.vue';
    import CursorPager from '../../components/ui/CursorPager.vue';
    import FilterBar from '../../components/ui/FilterBar.vue';
    import InspectorLayout from '../../shell/InspectorLayout.vue';
    import {bodyWith} from '../../shell/data.js';
    import {live, setToolbar} from '../../shell/store';
    import {listSource} from '../../support/bulk.js';
    import {mergeQuery} from '../../support/filters.js';
    import {filterKey, filtersFromQuery, pageRows, queryProblem, searchLimitedText} from '../../support/lists.js';
    import {formatClock, nowSeconds, timeZoneLabel} from '../../support/time.js';
    import {codeText} from '../../support/wording.js';
    import {
        autoLoadApplies,
        attemptsText,
        availableText,
        bulkSelectionKey,
        bulkState,
        restartPlan,
        clearedFiltersQuery,
        columnsOf,
        compactLine,
        emptyState,
        enforcedRuleText,
        filterFailure,
        filterFields,
        jobLink,
        lastVerifiedParts,
        leaseCell,
        listFailure,
        listOrder,
        markedText,
        mergeBarChange,
        mismatchAction,
        operationCell,
        pageAfter,
        pastTheEnd,
        pagerRequestFor,
        pagerShown,
        pollAction,
        requestFor,
        resultDetail,
        resultTitle,
        retriedText,
        runtimeText,
        scannedToText,
        shortId,
        shortName,
        shownTotal,
        signalChip,
        signalFromQuery,
        signalTabs,
        signalText,
        stuckBarFilters,
        stuckTime,
        tagChips,
        timeCell,
        timeColumn,
        timeHeader,
        workerCell,
    } from './jobs.js';

    /** The list polls every this many seconds. */
    const POLL_SECONDS = 10;

    /** The errors that a list read shows itself (the page keeps its last rows or shows its own state). */
    const SECTION_STATUSES = [400, 404, 405, 408, 409, 422, 429, 500, 502, 503, 504];

    /** A background poll also handles a timeout and a network failure, without a global alert. */
    const BACKGROUND_STATUSES = [...SECTION_STATUSES, ANY_ERROR_STATUS, ...BACKGROUND_FAILURES];

    /** The stored run of this screen's bulk sheet (BulkRun host rule 2: one key for both actions). */
    const BULK_STORAGE_KEY = 'orreryBulkRun:jobs:list';

    /**
     * The Jobs list.
     * The rules are in jobs.js; this component renders them.
     *
     * - The tab, the filters, the search and the stuck signal live in the
     *   URL; the cursor does not (a reload starts at the first page). The tab
     *   counts are the shell's global /api/counts; the result bar shows the
     *   list's filtered total, kept from the first page on later pages
     *   (shownTotal()). On the stuck tab the filter bar shows only the queue
     *   and the class (the filters its endpoint takes); the others stay in
     *   the URL for the other tabs.
     * - Every 10 s the current page is read again. On the first page in the
     *   order desc the poll sends `newer_than` with the page's head: while
     *   `has_newer` is true (or unknown) the rows stay and "New entries" (or
     *   "There may be new entries") shows, unless auto-load is on or the tab
     *   is a state tab (pending, running, retrying), whose rows a poll always
     *   replaces (pollAction()). Every first page, also one reached through a
     *   `before` cursor, is the new head (pageAfter()). A failed poll keeps
     *   the rows and says "Stale".
     * - The stuck list has one fixed order: no Newest or Oldest, no
     *   reversal (pagerRequestFor(), listOrder()).
     * - Failed tab, operate ability only: "Retry all N…" and "Forget all
     *   N…" open the shared BulkRun with a list source of the tab's filters;
     *   not for a read-only failed-record driver, whose message shows once.
     *   The host rules of BulkRun hold: a route change of the selection
     *   closes the sheet (the selection key also changes with it); `started`
     *   closes `open`; the stored run has its own key; the buttons are
     *   removed while a search is set. BulkRun is mounted on the Failed tab
     *   (so its recovery reads only there) and stays mounted while a run is
     *   shown, whatever the tab; leaving the screen during a run asks first.
     * - A row opens /jobs/:uuid (the Job detail).
     */
    export default {
        components: {BulkRun, ConfirmSheet, CursorPager, FilterBar, InspectorLayout, 'o-button': Button},

        beforeRouteLeave(to) {
            // A run stops when the screen unmounts: ask first (BulkRun keeps nothing running elsewhere).
            if (!this.bulk.running || this.leave.confirmed) return true;

            this.leave = {open: true, to: to.fullPath, confirmed: false, key: this.leave.key + 1};

            return false;
        },

        data() {
            const filters = filtersFromQuery(this.$route.query);

            return {
                live,
                filters,
                // A filter of the link that cannot be applied (queryProblem()): the list is not read and not shown.
                filterProblem: queryProblem(this.$route.query),
                signal: signalFromQuery(this.$route.query),
                page: {order: listOrder(filters), cursor: null},
                response: null,
                head: null,
                firstTotal: null,
                loading: true,
                failure: null,
                stale: false,
                updatedAt: null,
                banner: null,
                perPage: 50,
                now: nowSeconds(),
                bulk: {open: false, action: 'retry', runShown: false, running: false},
                restartNote: null,
                leave: {open: false, to: null, confirmed: false, key: 0},
                menuOpen: false,
                pollSeconds: POLL_SECONDS,
            };
        },

        computed: {
            status() {
                return this.filters.status;
            },

            isStuck() {
                return this.status === 'stuck';
            },

            order() {
                return listOrder(this.filters);
            },

            listKey() {
                const problem = this.filterProblem;

                return `${filterKey(this.filters)}|${this.isStuck ? this.signal : ''}|${problem ? `${problem.code}:${problem.field}` : ''}`;
            },


            fields() {
                return filterFields(this.status);
            },

            barFilters() {
                return this.isStuck ? stuckBarFilters(this.filters) : this.filters;
            },

            counts() {
                return this.live.countsError ? null : this.live.counts;
            },

            stuckTotal() {
                return this.isStuck ? this.total : null;
            },

            signalTabs() {
                return signalTabs(this.signal, this.stuckTotal);
            },

            columns() {
                return columnsOf(this.status);
            },

            rows() {
                return this.response ? pageRows(this.response.jobs, this.page.order, this.order) : [];
            },

            total() {
                return shownTotal(this.response, this.firstTotal);
            },

            title() {
                return resultTitle(this.status, this.total);
            },

            detail() {
                return resultDetail(this.filters, null, this.signal);
            },

            verified() {
                return ['pending', 'running'].includes(this.status) && this.response ? lastVerifiedParts(this.response.last_verified, this.now) : null;
            },

            limited() {
                return searchLimitedText(this.response);
            },

            scannedTo() {
                if (!this.response || this.response.search_limited !== true) return null;

                return scannedToText(this.response.scanned_to, {status: this.status, signal: this.signal, order: this.response.order});
            },

            bulkState() {
                const refused = this.filterProblem || (this.failure && this.failure.kind === 'filter' ? this.failure : null);

                return bulkState(this.status, this.canOperate, this.filters, this.total, {readOnly: this.failedReadOnly, filterProblem: refused});
            },

            bulkSource() {
                return this.bulkState.visible ? listSource(this.filters) : null;
            },

            bulkMounted() {
                return this.canOperate && (this.status === 'failed' || this.bulk.runShown || this.bulk.running);
            },

            selectionKey() {
                return bulkSelectionKey(this.filters, this.bulk.action);
            },

            empty() {
                return emptyState(this.filters, null, this.signal, this.response);
            },

            autoLoadShown() {
                return autoLoadApplies(this.status, this.order);
            },

            bannerText() {
                if (this.banner === 'new') return 'New entries · show';
                if (this.banner === 'maybe') return 'There may be new entries · show';

                return null;
            },

            pagerPage() {
                return this.response ? {order: this.page.order, response: this.response} : null;
            },

            leaveText() {
                return codeText('bulk_run_in_progress');
            },
        },

        watch: {
            '$route.query'(query) {
                const filters = filtersFromQuery(query);
                const signal = signalFromQuery(query);
                const before = this.listKey;

                this.filters = filters;
                this.filterProblem = queryProblem(query);
                this.signal = signal;

                if (this.listKey === before) return;

                // A user or route change of the selection: an open bulk sheet closes (BulkRun host rule 1).
                this.bulk.open = false;
                this.menuOpen = false;
                this.restartNote = null;
                this.reset();
            },

            '$root.autoLoadsNewEntries'(on) {
                if (on && this.banner) this.showNewEntries();
            },
        },

        created() {
            this.updateToolbar();
            this.load(this.page);
        },

        mounted() {
            this.clock = setInterval(() => {
                this.now = nowSeconds();
            }, 5000);
        },

        beforeUnmount() {
            clearInterval(this.clock);
            document.removeEventListener('keydown', this.onMenuKeydown);
            // A read that settles after unmount changes nothing.
            this.startRequest('list');
        },

        methods: {
            updateToolbar() {
                setToolbar({title: 'Jobs', subtitle: `Every retained job, by state · times in ${timeZoneLabel(nowSeconds())}`});
            },

            /** A new list: the first page in the list's own order, no head, no banner, no kept total. */
            reset() {
                this.response = null;
                this.head = null;
                this.firstTotal = null;
                this.banner = null;
                this.stale = false;
                this.failure = null;
                this.load({order: this.order, cursor: null});
            },

            /**
             * Reads a page. A user read replaces the page (and shows its error
             * in place of the rows); a background poll keeps the rows when it
             * fails (Stale) and keeps them behind the "New entries" banner.
             */
            async load(page, {background = false} = {}) {
                const sequence = this.startRequest('list');

                // A link filter that cannot be applied: never the unfiltered list in its place.
                if (this.filterProblem) {
                    this.failure = filterFailure(this.filterProblem);
                    this.response = null;
                    this.loading = false;

                    return;
                }

                const request = requestFor(this.filters, page, {perPage: this.perPage, head: background ? this.head : null, signal: this.signal});
                const sentNewerThan = typeof request.params.newer_than === 'string';

                if (!background) this.loading = true;

                try {
                    const response = await this.$http.get(Orrery.basePath + request.endpoint, {
                        params: request.params,
                        handles: background ? BACKGROUND_STATUSES : SECTION_STATUSES,
                    });

                    if (!this.isCurrentRequest('list', sequence)) return;

                    // A body of another shape (a login page answered with 200) is a failed read, never "none".
                    bodyWith(response.data, 'jobs');

                    const action = background ? pollAction(response.data, this.$root.autoLoadsNewEntries === true, {sentNewerThan, status: this.status}) : 'apply';

                    if (action !== 'apply') {
                        this.banner = action === 'banner' ? 'new' : 'maybe';
                        this.stale = false;

                        return;
                    }

                    this.apply(page, response.data);
                } catch (error) {
                    if (!this.isCurrentRequest('list', sequence)) return;

                    const failure = listFailure(error);

                    if (failure.kind === 'reset' && mismatchAction(request.params) === 'reload') {
                        // A cursor or head of another list: the first page again, without the head.
                        this.head = null;
                        this.banner = null;
                        this.load({order: this.order, cursor: null});

                        return;
                    }

                    if (background && this.response && failure.kind !== 'index_missing') {
                        this.stale = true;

                        return;
                    }

                    this.failure = failure.kind === 'reset' ? listFailure({response: {status: 422, data: {error: 'invalid'}}}) : failure;
                    this.response = null;
                } finally {
                    if (this.isCurrentRequest('list', sequence)) this.loading = false;
                }
            },

            apply(page, data) {
                if (pastTheEnd(page, data)) {
                    // The rows after the cursor left the list: the first page again, never "none kept".
                    this.load({order: this.order, cursor: null});

                    return;
                }

                const next = pageAfter(page, data, this.head);

                this.page = next.page;
                this.head = next.head;
                this.response = data;

                // The list total, kept for the later pages (they count only from their own start).
                if (typeof data.prev_cursor !== 'string') this.firstTotal = data.total || null;

                this.banner = null;
                this.stale = false;
                this.failure = null;
                this.updatedAt = nowSeconds();
                this.now = this.updatedAt;
            },

            /** The 10 s poll: the current page again (never during a user read). */
            poll() {
                if (this.loading) return Promise.resolve();

                return this.load(this.page, {background: true});
            },

            retryNow() {
                this.load(this.page);
            },

            navigate(request, action) {
                const next = pagerRequestFor(this.status, action, this.pagerPage);

                if (next) this.load(next);
            },

            setPerPage(value) {
                this.perPage = value;
                this.load({order: this.order, cursor: null});
            },

            showNewEntries() {
                this.load({order: 'desc', cursor: null});
            },

            selectSignal(signal) {
                if (signal === this.signal) return;

                this.$router.push({query: {...this.$route.query, signal}}).catch(() => {});
            },

            /** A change of the stuck filter bar: the filters it does not show stay in the URL. */
            onBarChange(next) {
                if (!this.isStuck) return;

                this.$router.push({query: mergeQuery(this.$route.query, mergeBarChange(this.filters, next))}).catch(() => {});
            },

            /** "Clear filters" of the empty state: the same rule as the filter bar's (clearedFiltersQuery()). */
            clearFilters() {
                this.$router.push({query: clearedFiltersQuery(this.$route.query, this.filters)}).catch(() => {});
            },

            openBulk(action) {
                this.menuOpen = false;
                document.removeEventListener('keydown', this.onMenuKeydown);
                this.restartNote = null;
                this.bulk.action = action;
                this.bulk.open = true;
            },

            onBulkStarted() {
                // BulkRun host rule 3: the sheet handed over to the run panel.
                this.bulk.open = false;
                this.bulk.runShown = true;
                this.bulk.running = true;
            },

            onBulkClose() {
                this.bulk.open = false;

                // A refused open during a run (BulkRun emits `close`) keeps the run panel.
                if (!this.bulk.running) this.bulk.runShown = false;
            },

            onBulkFinished() {
                this.bulk.running = false;
                this.load(this.page);
            },

            /**
             * "Start a new run…" of a recovered run: its action on its own source (jobs.js restartPlan()). The sheet
             * opens only while the tab shows exactly that source; else the run's filters are applied first, with a
             * note, and the operator starts the run from the list.
             */
            onBulkRestart(action, source) {
                const plan = restartPlan(source, this.filters);

                if (plan.query) {
                    // The route watcher clears the note when the selection changes: set it after the change.
                    this.$router.push({query: plan.query}).catch(() => {}).then(() => this.$nextTick()).then(() => {
                        this.restartNote = plan.note;
                    });

                    return;
                }

                if (!plan.open) {
                    this.restartNote = plan.note;

                    return;
                }

                if (this.bulkState.visible) {
                    this.openBulk(action === 'forget' ? 'forget' : 'retry');

                    return;
                }

                this.restartNote = this.bulkState.blocker || this.bulkState.filterText || this.bulkState.readOnlyText || codeText('no_bulk_members');
            },

            confirmLeave() {
                const to = this.leave.to;

                this.leave = {...this.leave, open: false, confirmed: true};

                if (to) this.$router.push(to).catch(() => {});
            },

            cancelLeave() {
                this.leave = {...this.leave, open: false, to: null};
            },

            toggleMenu() {
                this.menuOpen = !this.menuOpen;

                if (this.menuOpen) document.addEventListener('keydown', this.onMenuKeydown);
                else document.removeEventListener('keydown', this.onMenuKeydown);
            },

            onMenuKeydown(event) {
                if (event.key !== 'Escape' || !this.menuOpen) return;

                this.toggleMenu();
                this.$nextTick(() => this.$refs.menuButton && this.$refs.menuButton.focus());
            },

            openRow(row, event) {
                const link = jobLink(row);

                if (link === null || (event && event.target && event.target.closest && event.target.closest('a, button'))) return;

                this.$router.push(link).catch(() => {});
            },

            time(row) {
                return this.isStuck ? stuckTime(row, this.now) : timeCell(row[timeColumn(this.status)], this.now);
            },

            queueTitle(row) {
                return `${row.connection}:${row.queue}`;
            },

            jobSubline(row) {
                const parts = [];

                if (!this.columns.includes('queue')) parts.push(row.queue);
                if (this.status === 'retrying') parts.push(attemptsText(row.attempts));

                const retried = retriedText(row);

                if (retried) parts.push(retried);
                if (this.status === 'unknown') parts.push('status unknown: the package could not observe how it ended');

                return parts.join(' · ');
            },

            narrowDetail(row) {
                switch (this.status) {
                    case 'running': {
                        const worker = workerCell(row, this.now);

                        return [worker.id, worker.text].filter(Boolean).join(' · ');
                    }
                    case 'pending':
                        return `Available ${availableText(row.available_at, this.now)}`;
                    case 'retrying':
                        return operationCell(row.operation, this.now).label;
                    case 'stuck':
                        return (row.signals || []).map(entry => signalChip(entry.signal).label).join(' · ');
                    default:
                        return null;
                }
            },

            formatClock,
            attemptsText,
            availableText,
            compactLine,
            enforcedRuleText,
            jobLink,
            leaseCell,
            markedText,
            operationCell,
            pagerShown,
            runtimeText,
            shortId,
            shortName,
            signalChip,
            signalText,
            tagChips,
            timeHeader,
            workerCell,
        },

        BULK_STORAGE_KEY,

        HEADERS: {
            job: 'Job',
            exception: 'Exception',
            queue: 'Queue',
            attempts: 'Attempts',
            attempt: 'Attempt',
            runtime: 'Runtime',
            available: 'Available',
            worker: 'Worker',
            lease: 'Lease',
            operation: 'Operation',
            signals: 'Signals and facts',
            rule: 'Enforced rule',
        },
    };
</script>

<template>
    <inspector-layout>
        <poll :request="poll" :interval="pollSeconds" :immediate="false" />

        <section class="o-jobs" aria-label="Jobs">
            <filter-bar :filters="barFilters" tabs :counts="counts" :fields="fields" :sync-url="!isStuck" @change="onBarChange" />

            <div v-if="isStuck" class="o-jobs__signals">
                <div class="o-jobs__segmented" role="group" aria-label="Stuck signal">
                    <button v-for="tab in signalTabs" :key="tab.key" type="button" class="o-jobs__segment"
                            :class="{'o-jobs__segment--active': tab.active}" :aria-pressed="tab.active ? 'true' : 'false'"
                            @click="selectSignal(tab.key)">
                        <span>{{ tab.label }}</span>
                        <span v-if="tab.count !== null" class="o-jobs__segment-count">{{ tab.count }}</span>
                    </button>
                </div>
                <span class="o-jobs__muted">Evidence only: a signal never says that a process crashed or that the job will be picked up.</span>
            </div>

            <div v-if="verified" class="o-jobs__verified">
                <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true">
                    <circle cx="7" cy="7" r="5.6" />
                    <path d="M4.6 7.2l1.6 1.6 3.2-3.4" />
                </svg>
                <span>
                    Checked against the jobs table:
                    <template v-for="(part, index) in verified" :key="part.connection"><template v-if="index"> · </template><span class="o-jobs__strong">{{ part.connection }}</span> {{ part.text }}</template>
                </span>
            </div>

            <div class="o-jobs__result">
                <div class="o-jobs__result-text">
                    <h2 class="o-jobs__total">{{ response || !loading ? title : 'Counting…' }}</h2>
                    <span class="o-jobs__detail">{{ detail }}</span>
                </div>

                <button v-if="bannerText" type="button" class="o-jobs__new" @click="showNewEntries">
                    <svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><path d="M5 8.5V1.5M2 4.5l3-3 3 3" /></svg>
                    {{ bannerText }}
                </button>

                <span class="o-jobs__spacer"></span>

                <button v-if="autoLoadShown" type="button" class="o-jobs__switch" role="switch"
                        :aria-checked="$root.autoLoadsNewEntries ? 'true' : 'false'" @click="$root.autoLoadNewEntries()">
                    <span class="o-jobs__switch-track" :class="{'o-jobs__switch-track--on': $root.autoLoadsNewEntries}"><span class="o-jobs__switch-knob"></span></span>
                    Auto-load new
                </button>

                <template v-if="bulkState.visible">
                    <span class="o-jobs__divider" aria-hidden="true"></span>
                    <span class="o-jobs__bulk-wide">
                        <o-button operate variant="write" icon="retry" @click="openBulk('retry')">{{ bulkState.retryLabel }}</o-button>
                        <o-button operate variant="danger" @click="openBulk('forget')">{{ bulkState.forgetLabel }}</o-button>
                    </span>
                    <span class="o-jobs__bulk-narrow">
                        <button ref="menuButton" type="button" class="o-jobs__menu-button" :aria-expanded="menuOpen ? 'true' : 'false'"
                                aria-controls="o-jobs-bulk-menu" @click="toggleMenu">
                            {{ bulkState.menuLabel }}
                            <svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><path d="M2 3.5l3 3 3-3" /></svg>
                        </button>
                        <span v-if="menuOpen" id="o-jobs-bulk-menu" class="o-jobs__menu">
                            <button type="button" class="o-jobs__menu-item" @click="openBulk('retry')">{{ bulkState.retryLabel }}</button>
                            <button type="button" class="o-jobs__menu-item o-jobs__menu-item--danger" @click="openBulk('forget')">{{ bulkState.forgetLabel }}</button>
                        </span>
                    </span>
                </template>
            </div>

            <p v-if="bulkState.blocker && canOperate" class="o-jobs__muted o-jobs__small">{{ bulkState.blocker }}</p>
            <p v-if="bulkState.readOnlyText" class="o-jobs__muted o-jobs__small" role="note">{{ bulkState.readOnlyText }}</p>
            <p v-if="restartNote" class="o-jobs__muted o-jobs__small" role="status">{{ restartNote }}</p>

            <div v-if="limited" class="o-jobs__limited" role="status">
                <svg width="16" height="16" viewBox="0 0 14 14" aria-hidden="true"><circle cx="6" cy="6" r="4.2" /><path d="M9.2 9.2l3 3" /></svg>
                <div>
                    <div>{{ limited }}</div>
                    <div v-if="scannedTo" class="o-jobs__muted o-jobs__small">{{ scannedTo }}</div>
                </div>
            </div>

            <div v-if="bulkMounted" class="o-jobs__bulk">
                <bulk-run :open="bulk.open" :action="bulk.action" :source="bulkSource" :selection-key="selectionKey"
                          recover :storage-key="$options.BULK_STORAGE_KEY" source-kind="list"
                          @started="onBulkStarted" @close="onBulkClose" @finished="onBulkFinished" @restart="onBulkRestart" />
            </div>

            <confirm-sheet :open="leave.open" title="Leave this screen?" :message="leaveText" confirm-label="Leave"
                           tone="danger" :snapshot-key="leave.key" @confirm="confirmLeave" @cancel="cancelLeave" />

            <div v-if="stale && response" class="o-jobs__stale" role="status">
                <span class="o-jobs__stale-dot" aria-hidden="true"></span>
                <span class="o-jobs__stale-text">The last refresh of this list failed. Showing rows from {{ formatClock(updatedAt) }}.</span>
                <button type="button" class="o-jobs__link" @click="retryNow">Retry now</button>
            </div>

            <div v-if="failure && failure.kind === 'index_missing'" class="o-jobs__state">
                <div class="o-jobs__state-title">{{ failure.title }}</div>
                <code v-if="failure.hint" class="o-jobs__hint">{{ failure.hint }}</code>
                <div class="o-jobs__muted">{{ failure.text }}</div>
            </div>

            <div v-else-if="failure" class="o-jobs__state" role="alert">
                <svg width="26" height="26" viewBox="0 0 20 20" aria-hidden="true" class="o-jobs__state-icon">
                    <circle cx="10" cy="10" r="8" /><path d="M10 6v5" /><circle cx="10" cy="14" r="1" class="o-jobs__state-dot" />
                </svg>
                <div class="o-jobs__state-title">{{ failure.title }}</div>
                <div class="o-jobs__muted">{{ failure.text }}</div>
                <o-button v-if="failure.kind === 'filter'" variant="write" data-clear-invalid-filter @click="clearFilters">Clear filters</o-button>
                <o-button v-else variant="write" @click="retryNow">Try again</o-button>
            </div>

            <div v-else-if="loading && !response" class="o-jobs__table-box" aria-busy="true" aria-label="Loading">
                <div class="o-jobs__skeleton-head"></div>
                <div v-for="width in [180, 220, 160]" :key="width" class="o-jobs__skeleton-row">
                    <div class="o-jobs__skeleton-lines">
                        <div class="o-jobs__skeleton-bar" :style="{width: width + 'px'}"></div>
                        <div class="o-jobs__skeleton-bar o-jobs__skeleton-bar--light" :style="{width: (width / 2) + 'px'}"></div>
                    </div>
                    <div class="o-jobs__skeleton-bar o-jobs__skeleton-bar--short"></div>
                </div>
            </div>

            <div v-else-if="response && !rows.length" class="o-jobs__state">
                <svg v-if="!empty.filtered && !empty.partial" width="28" height="28" viewBox="0 0 16 16" aria-hidden="true" class="o-jobs__state-ok">
                    <circle cx="8" cy="8" r="5.8" /><path d="M5.4 8.2l1.8 1.8 3.4-3.6" />
                </svg>
                <div class="o-jobs__state-title">{{ empty.title }}</div>
                <div class="o-jobs__muted o-jobs__center">{{ empty.text }}</div>
                <button v-if="empty.filtered" type="button" class="o-jobs__link" @click="clearFilters">Clear filters</button>
            </div>

            <template v-else-if="response">
                <div class="o-jobs__table-box o-jobs__wide" :class="{'o-jobs__table-box--stale': stale}">
                    <div class="o-jobs__scroll">
                        <table class="o-jobs__table" :class="`o-jobs__table--${status}`">
                            <thead>
                                <tr>
                                    <th v-for="column in columns" :key="column" scope="col" :class="`o-jobs__col o-jobs__col--${column}`">
                                        <template v-if="column === 'time'">{{ timeHeader(status, order) }}</template>
                                        <template v-else>{{ $options.HEADERS[column] }}</template>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in rows" :key="`${row.id}:${row.cycle}`" class="o-jobs__row" @click="openRow(row, $event)">
                                    <td v-for="column in columns" :key="column" :class="`o-jobs__col o-jobs__col--${column}`">
                                        <div v-if="column === 'job'" class="o-jobs__job">
                                            <div class="o-jobs__job-line">
                                                <router-link v-if="jobLink(row)" :to="jobLink(row)" class="o-jobs__name" :title="row.name">{{ row.name }}</router-link>
                                                <span v-else class="o-jobs__name" :title="row.name">{{ row.name }}</span>
                                                <span class="o-jobs__id" :title="row.id">{{ status === 'stuck' ? row.id : shortId(row.id) }}</span>
                                            </div>
                                            <div v-if="jobSubline(row)" class="o-jobs__sub" :title="jobSubline(row)">{{ jobSubline(row) }}</div>
                                            <div v-if="tagChips(row.tags, row.tags_truncated).shown.length || row.tags_truncated" class="o-jobs__tags"
                                                 :title="tagChips(row.tags, row.tags_truncated).title">
                                                <span v-for="tag in tagChips(row.tags, row.tags_truncated).shown" :key="tag" class="o-jobs__tag">{{ tag }}</span>
                                                <span v-if="tagChips(row.tags, row.tags_truncated).more" class="o-jobs__tag-more">{{ tagChips(row.tags, row.tags_truncated).more }}</span>
                                            </div>
                                        </div>

                                        <div v-else-if="column === 'exception'" class="o-jobs__exception">
                                            <div class="o-jobs__exception-class" :title="row.exception_class || ''">{{ row.exception_class ? shortName(row.exception_class) : '—' }}</div>
                                            <div class="o-jobs__message" :title="row.exception_message || ''"><template v-for="(part, index) in markedText(row.exception_message)" :key="index"><span :class="{'o-jobs__marker': part.marker}">{{ part.text }}</span></template></div>
                                        </div>

                                        <span v-else-if="column === 'queue'" class="o-jobs__clip" :title="queueTitle(row)">{{ row.queue }}</span>

                                        <span v-else-if="column === 'attempts' || column === 'attempt'">{{ row.attempts }}</span>

                                        <span v-else-if="column === 'runtime'" :class="{'o-jobs__unknown': row.runtime_ms === null}">{{ runtimeText(row.runtime_ms) }}</span>

                                        <span v-else-if="column === 'available'" :class="{'o-jobs__muted': availableText(row.available_at, now) === 'now'}">{{ availableText(row.available_at, now) }}</span>

                                        <div v-else-if="column === 'worker'" class="o-jobs__worker">
                                            <div class="o-jobs__mono o-jobs__clip" :title="workerCell(row, now).id">{{ workerCell(row, now).id }}</div>
                                            <div v-if="workerCell(row, now).text" :class="`o-jobs__liveness o-jobs__liveness--${workerCell(row, now).tone}`">{{ workerCell(row, now).text }}</div>
                                        </div>

                                        <div v-else-if="column === 'lease'" class="o-jobs__lease">
                                            <span v-if="leaseCell(row, now).expired" class="o-jobs__chip o-jobs__chip--warning">
                                                <svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><path d="M5 1L9.3 8.6H.7z" /></svg>
                                                {{ leaseCell(row, now).text }}
                                            </span>
                                            <span v-else>{{ leaseCell(row, now).text }}</span>
                                            <span v-if="leaseCell(row, now).detail" class="o-jobs__small" :class="leaseCell(row, now).expired ? 'o-jobs__warning' : 'o-jobs__muted'">{{ leaseCell(row, now).detail }}</span>
                                        </div>

                                        <div v-else-if="column === 'operation'" class="o-jobs__operation">
                                            <span class="o-jobs__chip" :class="`o-jobs__chip--${operationCell(row.operation, now).tone}`">{{ operationCell(row.operation, now).label }}</span>
                                            <span v-if="operationCell(row.operation, now).text" class="o-jobs__muted o-jobs__small">{{ operationCell(row.operation, now).text }}</span>
                                            <span v-if="operationCell(row.operation, now).started" class="o-jobs__small">{{ operationCell(row.operation, now).started }}</span>
                                        </div>

                                        <div v-else-if="column === 'signals'" class="o-jobs__signal-list">
                                            <div v-for="entry in row.signals || []" :key="entry.signal" class="o-jobs__signal">
                                                <span class="o-jobs__chip o-jobs__chip--fixed" :class="`o-jobs__chip--${signalChip(entry.signal).tone}`">{{ signalChip(entry.signal).label }}</span>
                                                <span>{{ signalText(entry.signal, entry.facts, row, now) }}</span>
                                            </div>
                                        </div>

                                        <span v-else-if="column === 'rule'">{{ enforcedRuleText(row.enforced_rule, now) }}</span>

                                        <div v-else-if="column === 'time'" class="o-jobs__time" :title="time(row).title">
                                            <span :class="{'o-jobs__unknown': time(row).relative === null}">{{ time(row).primary }}</span>
                                            <span v-if="time(row).relative" class="o-jobs__muted o-jobs__small">{{ time(row).relative }}</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <ul class="o-jobs__cards o-jobs__narrow" :class="{'o-jobs__table-box--stale': stale}">
                    <li v-for="row in rows" :key="`${row.id}:${row.cycle}`" class="o-jobs__card">
                        <div class="o-jobs__card-line">
                            <router-link v-if="jobLink(row)" :to="jobLink(row)" class="o-jobs__card-name" :title="row.name">{{ row.name }}</router-link>
                            <span v-else class="o-jobs__card-name" :title="row.name">{{ row.name }}</span>
                            <span class="o-jobs__muted o-jobs__small" :title="time(row).title">{{ time(row).primary }}</span>
                        </div>
                        <template v-if="status === 'failed'">
                            <div class="o-jobs__exception-class" :title="row.exception_class || ''">{{ row.exception_class ? shortName(row.exception_class) : '—' }}</div>
                            <div class="o-jobs__message o-jobs__message--wide" :title="row.exception_message || ''"><template v-for="(part, index) in markedText(row.exception_message)" :key="index"><span :class="{'o-jobs__marker': part.marker}">{{ part.text }}</span></template></div>
                        </template>
                        <div v-else-if="narrowDetail(row)" class="o-jobs__card-detail">{{ narrowDetail(row) }}</div>
                        <div class="o-jobs__card-meta" :title="`${queueTitle(row)} · ${compactLine(row, status)}`">{{ compactLine(row, status) }}</div>
                    </li>
                </ul>
            </template>

            <cursor-pager v-if="pagerShown(response, rows.length)" :page="pagerPage" :rows="rows.length" :total="total"
                          :display-order="order" :edges="!isStuck" :per-page="perPage" :loading="loading"
                          @navigate="navigate" @update:per-page="setPerPage" />
        </section>
    </inspector-layout>
</template>

<style scoped>
    .o-jobs {
        display: flex;
        flex-direction: column;
        font-family: var(--font-sans);
        gap: 12px;
        min-width: 0;
    }

    .o-jobs__muted {
        color: var(--color-text-secondary);
    }

    .o-jobs__small {
        font-size: var(--text-caption);
        line-height: 16px;
    }

    .o-jobs__strong {
        color: var(--color-text);
    }

    .o-jobs__warning {
        color: var(--color-warning-text);
    }

    .o-jobs__unknown {
        color: var(--color-text-tertiary);
    }

    .o-jobs__center {
        text-align: center;
    }

    .o-jobs__mono {
        font-family: var(--font-mono);
        font-size: var(--text-caption);
        line-height: 16px;
    }

    .o-jobs__clip {
        display: block;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* The stuck signal sub-tabs. */
    .o-jobs__signals {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        font-size: var(--text-table);
        gap: 12px;
        line-height: var(--leading-table);
    }

    .o-jobs__segmented {
        align-items: center;
        background-color: var(--color-separator);
        border-radius: 7px;
        display: flex;
        flex-wrap: wrap;
        padding: 2px;
    }

    .o-jobs__segment {
        align-items: baseline;
        background: transparent;
        border: 0;
        border-radius: 5px;
        color: var(--color-text);
        cursor: pointer;
        display: flex;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        gap: 6px;
        line-height: var(--leading-table);
        padding: 3px 11px;
    }

    .o-jobs__segment--active {
        background-color: var(--color-bg);
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.12);
        font-weight: var(--font-weight-semibold);
    }

    .o-jobs__segment-count {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        font-weight: var(--font-weight-regular);
    }

    .o-jobs__segment:focus-visible,
    .o-jobs__link:focus-visible,
    .o-jobs__new:focus-visible,
    .o-jobs__switch:focus-visible,
    .o-jobs__menu-button:focus-visible,
    .o-jobs__menu-item:focus-visible,
    .o-jobs__name:focus-visible,
    .o-jobs__card-name:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: 1px;
    }

    /* The last verified line. */
    .o-jobs__verified {
        align-items: center;
        color: var(--color-text-secondary);
        display: flex;
        font-size: var(--text-table);
        gap: 8px;
        line-height: var(--leading-table);
    }

    .o-jobs__verified svg {
        flex-shrink: 0;
    }

    .o-jobs__verified svg circle,
    .o-jobs__verified svg path,
    .o-jobs__limited svg circle,
    .o-jobs__limited svg path {
        fill: none;
        stroke: var(--color-text-secondary);
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-width: 1.3;
    }

    /* The result bar. */
    .o-jobs__result {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        min-height: 30px;
    }

    .o-jobs__result-text {
        align-items: baseline;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        min-width: 0;
    }

    .o-jobs__total {
        color: var(--color-text);
        font-size: var(--text-body);
        font-weight: var(--font-weight-semibold);
        line-height: var(--leading-body);
        margin: 0;
    }

    .o-jobs__detail {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: var(--leading-table);
        overflow-wrap: anywhere;
    }

    .o-jobs__new {
        align-items: center;
        background-color: var(--color-accent-tint);
        border: 0;
        border-radius: 13px;
        color: var(--color-accent);
        cursor: pointer;
        display: flex;
        font-family: var(--font-sans);
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        gap: 6px;
        height: 24px;
        line-height: 16px;
        padding-inline: 10px;
    }

    .o-jobs__new svg path {
        fill: none;
        stroke: var(--color-accent);
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-width: 1.4;
    }

    .o-jobs__spacer {
        flex-grow: 1;
    }

    .o-jobs__switch {
        align-items: center;
        background: transparent;
        border: 0;
        color: var(--color-text-secondary);
        cursor: pointer;
        display: flex;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        gap: 6px;
        line-height: var(--leading-table);
        padding: 2px;
    }

    .o-jobs__switch-track {
        align-items: center;
        background-color: var(--color-separator);
        border-radius: 8px;
        box-sizing: border-box;
        display: flex;
        flex-shrink: 0;
        height: 16px;
        padding: 2px;
        transition: background-color 0.12s ease;
        width: 26px;
    }

    .o-jobs__switch-track--on {
        background-color: var(--color-accent);
        justify-content: flex-end;
    }

    .o-jobs__switch-knob {
        background-color: var(--color-bg);
        border-radius: 50%;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
        height: 12px;
        width: 12px;
    }

    .o-jobs__divider {
        background-color: var(--color-separator);
        flex-shrink: 0;
        height: 18px;
        width: 1px;
    }

    .o-jobs__bulk-wide {
        align-items: center;
        display: flex;
        gap: 10px;
    }

    .o-jobs__bulk-narrow {
        display: none;
        position: relative;
    }

    .o-jobs__menu-button {
        align-items: center;
        background-color: var(--color-bg);
        border: 1px solid color-mix(in srgb, var(--color-text) 18%, transparent);
        border-radius: var(--radius-control);
        color: var(--color-text);
        cursor: pointer;
        display: flex;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        font-weight: var(--font-weight-medium);
        gap: 4px;
        height: 30px;
        padding-inline: 10px;
    }

    .o-jobs__menu-button svg path {
        fill: none;
        stroke: var(--color-text);
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-width: 1.4;
    }

    .o-jobs__menu {
        background-color: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: 8px;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.12);
        display: flex;
        flex-direction: column;
        min-width: 180px;
        padding: 4px;
        position: absolute;
        right: 0;
        top: 34px;
        z-index: 5;
    }

    .o-jobs__menu-item {
        background: transparent;
        border: 0;
        border-radius: 5px;
        color: var(--color-text);
        cursor: pointer;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        line-height: var(--leading-table);
        padding: 7px 10px;
        text-align: left;
    }

    .o-jobs__menu-item:hover {
        background-color: var(--color-subtle);
    }

    .o-jobs__menu-item--danger {
        color: var(--color-danger-text);
    }

    .o-jobs__limited {
        align-items: flex-start;
        background-color: var(--color-subtle);
        border: 1px solid var(--color-separator);
        border-radius: 8px;
        color: var(--color-text);
        display: flex;
        font-size: var(--text-table);
        gap: 10px;
        line-height: var(--leading-table);
        padding: 10px 12px;
    }

    .o-jobs__limited svg {
        flex-shrink: 0;
    }

    .o-jobs__stale {
        align-items: center;
        background-color: var(--color-warning-tint);
        border: 1px solid var(--color-separator);
        border-radius: 8px;
        display: flex;
        font-size: var(--text-table);
        gap: 10px;
        line-height: var(--leading-table);
        padding: 10px 14px;
    }

    .o-jobs__stale-dot {
        background-color: var(--color-warning);
        border-radius: 50%;
        flex-shrink: 0;
        height: 7px;
        width: 7px;
    }

    .o-jobs__stale-text {
        color: var(--color-text);
        flex-grow: 1;
    }

    .o-jobs__link {
        background: transparent;
        border: 0;
        color: var(--color-accent);
        cursor: pointer;
        flex-shrink: 0;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        font-weight: var(--font-weight-medium);
        padding: 2px;
        white-space: nowrap;
    }

    /* The states: index missing, error, empty. */
    .o-jobs__state {
        align-items: center;
        background-color: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-card);
        display: flex;
        flex-direction: column;
        font-size: var(--text-table);
        gap: 8px;
        line-height: var(--leading-table);
        padding: 28px 20px;
    }

    .o-jobs__state-title {
        color: var(--color-text);
        font-size: var(--text-body);
        font-weight: var(--font-weight-semibold);
        line-height: var(--leading-body);
        text-align: center;
    }

    .o-jobs__state-icon circle:first-child {
        fill: var(--color-danger-tint);
        stroke: var(--color-danger);
        stroke-width: 1.4;
    }

    .o-jobs__state-icon path {
        stroke: var(--color-danger);
        stroke-linecap: round;
        stroke-width: 1.6;
    }

    .o-jobs__state-icon .o-jobs__state-dot {
        fill: var(--color-danger);
    }

    .o-jobs__state-ok circle,
    .o-jobs__state-ok path {
        fill: none;
        stroke: var(--color-success);
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-width: 1.2;
    }

    .o-jobs__hint {
        background-color: var(--color-subtle);
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-control);
        color: var(--color-text);
        font-family: var(--font-mono);
        font-size: var(--text-caption);
        overflow-wrap: anywhere;
        padding: 4px 8px;
    }

    /* The table. */
    .o-jobs__table-box {
        background-color: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-card);
        min-width: 0;
        overflow: hidden;
    }

    .o-jobs__table-box--stale {
        opacity: 0.7;
    }

    .o-jobs__scroll {
        overflow-x: auto;
    }

    .o-jobs__table {
        border-collapse: collapse;
        font-size: var(--text-table);
        line-height: var(--leading-table);
        min-width: 760px;
        table-layout: fixed;
        width: 100%;
    }

    .o-jobs__table--failed {
        min-width: 880px;
    }

    .o-jobs__table--running,
    .o-jobs__table--stuck {
        min-width: 900px;
    }

    .o-jobs__table--retrying,
    .o-jobs__table--completed,
    .o-jobs__table--silenced,
    .o-jobs__table--unknown {
        min-width: 600px;
    }

    .o-jobs__table th {
        background-color: var(--color-subtle);
        border-bottom: 1px solid var(--color-separator);
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        height: 30px;
        line-height: 16px;
        padding: 0 14px 0 0;
        text-align: left;
        white-space: nowrap;
    }

    .o-jobs__table th.o-jobs__col--time {
        color: var(--color-text);
    }

    .o-jobs__table .o-jobs__col--attempts,
    .o-jobs__table .o-jobs__col--attempt,
    .o-jobs__table .o-jobs__col--runtime,
    .o-jobs__table .o-jobs__col--available,
    .o-jobs__table .o-jobs__col--time {
        text-align: right;
    }

    .o-jobs__table td {
        border-bottom: 1px solid var(--color-separator);
        color: var(--color-text);
        height: 48px;
        padding: 8px 14px 8px 0;
        vertical-align: middle;
    }

    .o-jobs__table th:first-child,
    .o-jobs__table td:first-child {
        padding-left: 14px;
    }

    .o-jobs__table tbody tr:last-child td {
        border-bottom: 0;
    }

    .o-jobs__row {
        cursor: pointer;
    }

    .o-jobs__row:hover td {
        background-color: var(--color-subtle);
    }

    .o-jobs__col--queue { width: 90px; }
    .o-jobs__col--attempts { text-align: right; width: 72px; }
    .o-jobs__col--attempt { text-align: right; width: 70px; }
    .o-jobs__col--runtime { text-align: right; width: 80px; }
    .o-jobs__col--available { text-align: right; width: 180px; }
    .o-jobs__col--exception { width: 360px; }
    .o-jobs__col--worker { width: 220px; }
    .o-jobs__col--lease { width: 180px; }
    .o-jobs__col--operation { width: 250px; }
    .o-jobs__col--rule { width: 200px; }
    .o-jobs__col--time { text-align: right; width: 116px; }

    .o-jobs__table--pending .o-jobs__col--queue { width: 140px; }
    .o-jobs__table--pending .o-jobs__col--attempts { width: 80px; }
    .o-jobs__table--pending .o-jobs__col--time,
    .o-jobs__table--running .o-jobs__col--time { width: 140px; }
    .o-jobs__table--running .o-jobs__col--queue { width: 100px; }
    .o-jobs__table--stuck .o-jobs__col--job { width: 260px; }
    .o-jobs__table--stuck .o-jobs__col--time { width: 120px; }
    .o-jobs__table--retrying .o-jobs__col--time { width: 100px; }
    .o-jobs__table--completed .o-jobs__col--attempts,
    .o-jobs__table--silenced .o-jobs__col--attempts,
    .o-jobs__table--unknown .o-jobs__col--attempts { width: 70px; }
    .o-jobs__table--completed .o-jobs__col--time,
    .o-jobs__table--silenced .o-jobs__col--time,
    .o-jobs__table--unknown .o-jobs__col--time { width: 120px; }

    .o-jobs__job {
        display: flex;
        flex-direction: column;
        gap: 2px;
        min-width: 0;
    }

    .o-jobs__job-line {
        align-items: baseline;
        display: flex;
        gap: 8px;
        min-width: 0;
    }

    .o-jobs__name {
        color: var(--color-text);
        font-weight: var(--font-weight-semibold);
        min-width: 0;
        overflow: hidden;
        text-decoration: none;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-jobs__name:hover {
        text-decoration: underline;
    }

    .o-jobs__id {
        color: var(--color-text-tertiary);
        flex-shrink: 0;
        font-family: var(--font-mono);
        font-size: 11px;
        line-height: 16px;
    }

    .o-jobs__table--stuck .o-jobs__id {
        flex-shrink: 1;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .o-jobs__table--stuck .o-jobs__job-line {
        flex-direction: column;
        gap: 0;
    }

    .o-jobs__sub {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-jobs__tags {
        display: flex;
        gap: 4px;
        min-width: 0;
        overflow: hidden;
    }

    .o-jobs__tag {
        background-color: var(--color-sidebar);
        border-radius: 4px;
        color: var(--color-text-secondary);
        flex-shrink: 1;
        font-size: var(--text-caption);
        line-height: 16px;
        max-width: 220px;
        min-width: 0;
        overflow: hidden;
        padding-inline: 6px;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-jobs__tag-more {
        color: var(--color-text-tertiary);
        flex-shrink: 0;
        font-size: var(--text-caption);
        line-height: 16px;
    }

    .o-jobs__exception {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .o-jobs__exception-class {
        color: var(--color-danger-text);
        font-size: var(--text-table);
        font-weight: var(--font-weight-medium);
        line-height: var(--leading-table);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-jobs__message {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-jobs__message--wide {
        font-size: var(--text-table);
        line-height: var(--leading-table);
    }

    .o-jobs__marker {
        color: var(--color-text-tertiary);
        font-family: var(--font-mono);
    }

    .o-jobs__worker,
    .o-jobs__lease,
    .o-jobs__operation {
        align-items: flex-start;
        display: flex;
        flex-direction: column;
        gap: 2px;
        min-width: 0;
    }

    .o-jobs__worker > * {
        max-width: 100%;
    }

    .o-jobs__liveness {
        font-size: var(--text-caption);
        line-height: 16px;
    }

    .o-jobs__liveness--live {
        color: var(--color-success-text);
    }

    .o-jobs__liveness--silent {
        color: var(--color-warning-text);
    }

    .o-jobs__liveness--unknown {
        color: var(--color-text-tertiary);
    }

    .o-jobs__chip {
        align-items: center;
        border-radius: 4px;
        display: inline-flex;
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        gap: 4px;
        line-height: 16px;
        padding: 1px 6px;
    }

    .o-jobs__chip--warning {
        background-color: var(--color-warning-tint);
        color: var(--color-warning-text);
    }

    .o-jobs__chip--warning svg path {
        fill: var(--color-warning);
    }

    .o-jobs__chip--accent {
        background-color: var(--color-accent-tint);
        color: var(--color-accent);
    }

    .o-jobs__chip--neutral {
        background-color: var(--color-sidebar);
        color: var(--color-text-secondary);
    }

    .o-jobs__chip--fixed {
        flex-shrink: 0;
        justify-content: flex-start;
        white-space: nowrap;
        width: 148px;
    }

    .o-jobs__signal-list {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .o-jobs__signal {
        align-items: baseline;
        display: flex;
        flex-wrap: wrap;
        gap: 2px 6px;
    }

    .o-jobs__signal > span:last-child {
        flex: 1 1 160px;
        min-width: 0;
    }

    .o-jobs__time {
        align-items: flex-end;
        display: flex;
        flex-direction: column;
    }

    /* The loading rows. */
    .o-jobs__skeleton-head {
        background-color: var(--color-subtle);
        border-bottom: 1px solid var(--color-separator);
        height: 30px;
    }

    .o-jobs__skeleton-row {
        align-items: center;
        border-bottom: 1px solid var(--color-separator);
        display: flex;
        gap: 16px;
        height: 48px;
        padding-inline: 14px;
    }

    .o-jobs__skeleton-row:last-child {
        border-bottom: 0;
    }

    .o-jobs__skeleton-lines {
        display: flex;
        flex-direction: column;
        flex-grow: 1;
        gap: 6px;
    }

    .o-jobs__skeleton-bar {
        background-color: var(--color-separator);
        border-radius: 5px;
        height: 10px;
    }

    .o-jobs__skeleton-bar--light {
        background-color: var(--color-sidebar);
        height: 8px;
    }

    .o-jobs__skeleton-bar--short {
        width: 60px;
    }

    /* The rows below 768 px. */
    .o-jobs__cards {
        border-top: 1px solid var(--color-separator);
        display: none;
        flex-direction: column;
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .o-jobs__card {
        border-bottom: 1px solid var(--color-separator);
        display: flex;
        flex-direction: column;
        gap: 3px;
        min-width: 0;
        padding: 12px 0;
    }

    .o-jobs__card-line {
        align-items: baseline;
        display: flex;
        gap: 8px;
        min-width: 0;
    }

    .o-jobs__card-name {
        color: var(--color-text);
        flex-grow: 1;
        font-size: var(--text-body);
        font-weight: var(--font-weight-semibold);
        line-height: var(--leading-body);
        min-width: 0;
        overflow: hidden;
        text-decoration: none;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-jobs__card-detail {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: var(--leading-table);
        overflow-wrap: anywhere;
    }

    .o-jobs__card-meta {
        color: var(--color-text-tertiary);
        font-size: var(--text-caption);
        line-height: 16px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-jobs__bulk:empty {
        display: none;
    }

    @media (max-width: 1365px) {
        .o-jobs__col--exception { width: 300px; }
    }

    @media (min-width: 1800px) {
        .o-jobs__col--exception { width: 520px; }
        .o-jobs__col--worker { width: 240px; }
        .o-jobs__col--lease { width: 200px; }
        .o-jobs__col--rule { width: 220px; }
        .o-jobs__table--stuck .o-jobs__col--job { width: 330px; }
    }

    @media (max-width: 767px) {
        .o-jobs__wide {
            display: none;
        }

        .o-jobs__cards {
            display: flex;
        }

        .o-jobs__bulk-wide,
        .o-jobs__divider,
        .o-jobs__switch,
        .o-jobs__detail {
            display: none;
        }

        .o-jobs__bulk-narrow {
            display: block;
        }

        .o-jobs__signals .o-jobs__muted {
            display: none;
        }
    }
</style>
