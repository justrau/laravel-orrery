<script>
    import {ANY_ERROR_STATUS, BACKGROUND_FAILURES} from '../../errors.js';
    import BulkRun from '../../components/ui/BulkRun.vue';
    import Button from '../../components/ui/Button.vue';
    import ConfirmSheet from '../../components/ui/ConfirmSheet.vue';
    import {bodyWith, objectBody} from '../../shell/data.js';
    import {setToolbar} from '../../shell/store';
    import {nowSeconds, timeZoneLabel} from '../../support/time.js';
    import {codeText} from '../../support/wording.js';
    import {
        MEMBERS_CAPTION,
        QUERY_MAX,
        SEARCH_DEBOUNCE_MS,
        batchSource,
        batchState,
        batchTitle,
        bulkPanelMounted,
        bulkStorageKey,
        contentShown,
        countParts,
        countText,
        headerTimes,
        leaveDecision,
        listParams,
        listState,
        memberTabs,
        narrowParts,
        olderCursor,
        pagerShown,
        pollReads,
        optionsView,
        progressBar,
        progressText,
        queryFromRoute,
        readFailure,
        retryState,
        rowMeta,
        rowTime,
        staleText,
        stateChip,
    } from './batches.js';

    /** The list and the open batch are read again every this many seconds. */
    const POLL_SECONDS = 10;

    /** The errors that a read shows itself (the screen keeps its last data or shows its own state). */
    const SECTION_STATUSES = [400, 404, 405, 408, 409, 422, 429, 500, 502, 503, 504];

    /** A background poll also handles a timeout and a network failure, without a global alert. */
    const BACKGROUND_STATUSES = [...SECTION_STATUSES, ANY_ERROR_STATUS, ...BACKGROUND_FAILURES];

    /**
     * The Batches screen.
     * The rules are in
     * batches.js; this component renders them.
     *
     * - `/batches` and `/batches/:batchId`: from 768 px the list (400 px,
     *   480 at 1800 and wider) and the detail sit side by side; below 768
     *   they are two screens, the detail with a back link.
     * - The list: `GET /api/batches` with the search (`?query=` in the URL,
     *   300 ms debounce) and the `before_id` pager ("Older batches", and
     *   back through "Newer"); it is read again every 10 s, and a failed
     *   poll keeps the rows and says "Stale". `available: false` and
     *   `batches: null` have their own states.
     * - The detail: `GET /api/batches/{id}`, read again every 10 s until a
     *   404 says the batch is gone. Laravel's counts, the projected options,
     *   and one tab per member status with Orrery's capped count, each a
     *   link to the Jobs list of that status filtered by the batch (the
     *   members are listed there, not here).
     * - Operate ability only: "Retry failed members…" opens the shared
     *   BulkRun with a batch source. BulkRun host rules: the selection is
     *   the route's batch, so a change of batch closes the sheet (the key
     *   changes with it); `started` closes `open`; the stored run has a key
     *   per batch; the run panel stays mounted while it is shown, and
     *   leaving the batch during a run asks first.
     * - Escape in the detail closes it and returns the focus to its row.
     */
    export default {
        components: {BulkRun, ConfirmSheet, 'o-button': Button},

        props: {
            batchId: {type: String, default: null},
        },

        beforeRouteUpdate(to) {
            return this.guardLeave(to, to.params.batchId === this.batchId);
        },

        beforeRouteLeave(to) {
            return this.guardLeave(to, false);
        },

        data() {
            const query = queryFromRoute(this.$route.query);

            return {
                search: query,
                query,
                cursors: [],
                list: null,
                listLoading: true,
                listFailure: null,
                listStale: false,
                listUpdatedAt: null,
                detail: null,
                detailLoading: false,
                detailFailure: null,
                detailStale: false,
                detailUpdatedAt: null,
                bulk: {open: false, running: false, shown: false},
                leave: {open: false, to: null, confirmed: false, key: 0},
                copied: null,
                now: nowSeconds(),
                pollSeconds: POLL_SECONDS,
                queryMax: QUERY_MAX,
            };
        },

        computed: {
            listView() {
                if (this.listFailure && !this.list) return {kind: 'failed'};

                return listState(this.list, this.query, {paged: this.cursors.length > 0});
            },

            rows() {
                return this.list && Array.isArray(this.list.batches) ? this.list.batches : [];
            },

            older() {
                return olderCursor(this.list);
            },

            batch() {
                return this.detail ? this.detail.batch : null;
            },

            detailUnknown() {
                return this.detail && this.detail.batch === null ? listState({batches: null, unknown_reason: this.detail.unknown_reason}) : null;
            },

            title() {
                return this.batch ? batchTitle(this.batch) : null;
            },

            chip() {
                return this.batch ? stateChip(batchState(this.batch)) : null;
            },

            bar() {
                return this.batch ? progressBar(this.batch) : null;
            },

            options() {
                return this.batch ? optionsView(this.batch.options) : null;
            },

            tabs() {
                return this.batch ? memberTabs(this.detail.members, this.batch.id) : [];
            },

            retry() {
                return retryState(this.canOperate, this.detail ? this.detail.members : null, {readOnly: this.failedReadOnly});
            },

            source() {
                return this.batchId === null ? null : batchSource(this.batchId);
            },

            storageKey() {
                return this.batchId === null ? null : bulkStorageKey(this.batchId);
            },

            bulkMounted() {
                return bulkPanelMounted({canOperate: this.canOperate, batchId: this.batchId, batchKnown: this.batch !== null, running: this.bulk.running, shown: this.bulk.shown});
            },

            /** The batch's content is shown: a known batch, kept (stale) after a failed read that is not a 404. */
            contentShown() {
                return contentShown({batchId: this.batchId, batch: this.batch, failure: this.detailFailure});
            },

            pagerShown() {
                return pagerShown(this.listView.kind, this.cursors.length);
            },

            narrowSummary() {
                return this.batch ? narrowParts(this.batch, this.options) : [];
            },

            leaveText() {
                return codeText('bulk_run_in_progress');
            },
        },

        watch: {
            '$route.query'(query) {
                const next = queryFromRoute(query);

                if (next === this.query) return;

                this.query = next;
                if (this.search !== next) this.search = next;
                this.cursors = [];
                this.list = null;
                this.loadList();
            },

            search(value) {
                clearTimeout(this.searchTimer);

                if (value === this.query) return;

                this.searchTimer = setTimeout(() => {
                    const query = {...this.$route.query};

                    if (value === '') delete query.query;
                    else query.query = value;

                    this.$router.replace({query}).catch(() => {});
                }, SEARCH_DEBOUNCE_MS);
            },

            batchId: {
                handler(id, previous) {
                    // A change of the batch is a change of the selection: an open sheet closes (BulkRun host rule 1).
                    this.bulk = {open: false, running: false, shown: false};
                    this.copied = null;
                    this.detail = null;
                    this.detailFailure = null;
                    this.detailStale = false;

                    if (id !== null) this.loadDetail();
                    else this.startRequest('detail');

                    if (previous !== undefined && previous !== null && id === null) this.focusRow(previous);
                },
            },
        },

        created() {
            setToolbar({title: 'Batches', subtitle: `Laravel's batches, newest first · times in ${timeZoneLabel(nowSeconds())}`});
            this.loadList();

            if (this.batchId !== null) this.loadDetail();
        },

        mounted() {
            this.clock = setInterval(() => {
                this.now = nowSeconds();
            }, 30000);
        },

        beforeUnmount() {
            clearInterval(this.clock);
            clearTimeout(this.searchTimer);
            clearTimeout(this.copiedTimer);
            // A read that settles after unmount changes nothing.
            this.startRequest('list');
            this.startRequest('detail');
        },

        methods: {
            /** A run stops when its batch closes: ask first (BulkRun keeps nothing running elsewhere). */
            guardLeave(to, sameBatch) {
                if (leaveDecision({sameBatch, running: this.bulk.running, confirmed: this.leave.confirmed}) === 'allow') {
                    this.leave = {...this.leave, confirmed: false};

                    return true;
                }

                this.leave = {open: true, to: to.fullPath, confirmed: false, key: this.leave.key + 1};

                return false;
            },

            confirmLeave() {
                const to = this.leave.to;

                this.leave = {...this.leave, open: false, confirmed: true};

                if (to) this.$router.push(to).catch(() => {});
            },

            cancelLeave() {
                this.leave = {...this.leave, open: false, to: null};
            },

            /** Reads a page of the list. A poll keeps the rows when it fails ("Stale"). */
            async loadList({background = false} = {}) {
                const sequence = this.startRequest('list');
                const before = this.cursors.length ? this.cursors[this.cursors.length - 1] : null;

                if (!background) {
                    // "Try again" and a new page show the loading state, not the last failure.
                    this.listLoading = true;
                    this.listFailure = null;
                }

                try {
                    const response = await this.$http.get(Orrery.basePath + '/api/batches', {
                        params: listParams(this.query, before),
                        handles: background ? BACKGROUND_STATUSES : SECTION_STATUSES,
                    });

                    if (!this.isCurrentRequest('list', sequence)) return;

                    // A body of another shape (a login page answered with 200) is a failed read, never "none".
                    this.list = bodyWith(response.data, 'batches', {nullable: true});
                    this.listFailure = null;
                    this.listStale = false;
                    this.listUpdatedAt = nowSeconds();
                } catch (error) {
                    if (!this.isCurrentRequest('list', sequence)) return;

                    if (background && this.list) {
                        this.listStale = true;

                        return;
                    }

                    this.list = null;
                    this.listFailure = readFailure(error);
                } finally {
                    if (this.isCurrentRequest('list', sequence)) this.listLoading = false;
                }
            },

            /** Reads the open batch. A 404 stops the polls of this batch; a failed poll keeps the data ("Stale"). */
            async loadDetail({background = false} = {}) {
                const id = this.batchId;
                const sequence = this.startRequest('detail');

                if (!background) {
                    this.detailLoading = true;
                    if (!this.detail) this.detailFailure = null;
                }

                try {
                    const response = await this.$http.get(Orrery.basePath + '/api/batches/' + encodeURIComponent(id), {
                        handles: background ? BACKGROUND_STATUSES : SECTION_STATUSES,
                    });

                    if (!this.isCurrentRequest('detail', sequence)) return;

                    // A body of another shape (a login page answered with 200) is a failed read.
                    const detail = objectBody(response.data);

                    if (!('batch' in detail)) throw new TypeError('The answer has no batch.');

                    this.detail = detail;
                    this.detailFailure = null;
                    this.detailStale = false;
                    this.detailUpdatedAt = nowSeconds();
                } catch (error) {
                    if (!this.isCurrentRequest('detail', sequence)) return;

                    const failure = readFailure(error, {detail: true});

                    // Any read that is not a 404 keeps the last batch (and so the run panel and its run): it is marked stale.
                    if (this.detail && !failure.gone) {
                        this.detailStale = true;

                        return;
                    }

                    this.detail = null;
                    this.detailFailure = failure;
                } finally {
                    if (this.isCurrentRequest('detail', sequence)) this.detailLoading = false;
                }
            },

            /** The 10 s poll: the current list page and the open batch (never during a user read, never a gone batch). */
            poll() {
                const reads = [];
                const due = pollReads({
                    listLoading: this.listLoading,
                    batchId: this.batchId,
                    detailLoading: this.detailLoading,
                    detailGone: !!(this.detailFailure && this.detailFailure.gone),
                });

                if (due.list) reads.push(this.loadList({background: true}));
                if (due.detail) reads.push(this.loadDetail({background: true}));

                return Promise.allSettled(reads);
            },

            showOlder() {
                if (this.older === null) return;

                this.cursors = [...this.cursors, this.older];
                this.loadList();
                this.$nextTick(() => this.$refs.listTop && this.$refs.listTop.scrollIntoView({block: 'nearest'}));
            },

            showNewer() {
                this.cursors = this.cursors.slice(0, -1);
                this.loadList();
            },

            batchLink(batch) {
                return {name: 'batches-preview', params: {batchId: String(batch.id)}, query: this.$route.query};
            },

            closeLink() {
                return {name: 'batches', query: this.$route.query};
            },

            closeDetail() {
                if (this.batchId === null) return;

                this.$router.push(this.closeLink()).catch(() => {});
            },

            /** After the detail closes: the focus goes back to the row that opened it, else to the search. */
            focusRow(id) {
                this.$nextTick(() => {
                    const links = this.$el && this.$el.querySelectorAll ? this.$el.querySelectorAll('[data-batch-id]') : [];
                    const row = [...links].find(link => link.getAttribute('data-batch-id') === String(id));
                    const target = row || this.$refs.search;

                    if (target && typeof target.focus === 'function') target.focus();
                });
            },

            openBulk() {
                this.bulk = {...this.bulk, open: true};
            },

            onBulkStarted() {
                // BulkRun host rule 3: the sheet handed over to the run panel.
                this.bulk = {open: false, running: true, shown: true};
            },

            onBulkClose() {
                this.bulk = {...this.bulk, open: false, shown: this.bulk.running};
            },

            onBulkFinished() {
                this.bulk = {...this.bulk, running: false};
                this.loadDetail();
            },

            /** "Start a new run…" of a recovered run: the retry again, when this batch allows one. */
            onBulkRestart() {
                if (this.retry.visible) this.openBulk();
            },

            /** Copy the batch id; the button says whether it worked. */
            copyId() {
                const done = ok => {
                    this.copied = ok ? 'Copied' : 'Copy failed';
                    clearTimeout(this.copiedTimer);
                    this.copiedTimer = setTimeout(() => {
                        this.copied = null;
                    }, 2000);
                };

                if (typeof navigator !== 'undefined' && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                    navigator.clipboard.writeText(this.batch.id).then(() => done(true), () => done(false));
                } else {
                    done(false);
                }
            },

            rowTitle(batch) {
                return batchTitle(batch);
            },

            barStyle(segment) {
                return {width: `${segment.percent}%`};
            },

            countParts,
            rowMeta,
            staleText,
            countText,
            headerTimes,
            progressBar,
            progressText,
            rowTime,
        },

        MEMBERS_CAPTION,
    };
</script>

<template>
    <div class="o-batches" :class="{'o-batches--open': batchId !== null}">
        <poll :request="poll" :interval="pollSeconds" :immediate="false" />

        <section class="o-batches__list" aria-label="Batches">
            <div ref="listTop" class="o-batches__search-bar">
                <label class="o-batches__search">
                    <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><circle cx="6" cy="6" r="4.2" /><path d="M9.2 9.2l3 3" /></svg>
                    <span class="o-batches__sr">Search batches by name or id</span>
                    <input ref="search" v-model="search" type="search" class="o-batches__search-input" placeholder="Search by name or id"
                           :maxlength="queryMax" autocomplete="off" spellcheck="false">
                </label>
            </div>

            <div v-if="listStale && list" class="o-batches__stale" role="status">
                <span class="o-batches__stale-dot" aria-hidden="true"></span>
                <span class="o-batches__grow">{{ staleText('batches', listUpdatedAt) }}</span>
            </div>

            <div v-if="listView.kind === 'failed'" class="o-batches__state" role="alert">
                <div class="o-batches__state-title">{{ listFailure.title }}</div>
                <div class="o-batches__muted">{{ listFailure.text }}</div>
                <o-button variant="write" @click="loadList()">Try again</o-button>
            </div>

            <div v-else-if="listView.kind === 'loading'" class="o-batches__rows" aria-busy="true" aria-label="Loading">
                <div v-for="width in [180, 220, 150]" :key="width" class="o-batches__row o-batches__row--skeleton">
                    <div class="o-batches__skeleton" :style="{width: width + 'px'}"></div>
                    <div class="o-batches__skeleton o-batches__skeleton--bar"></div>
                    <div class="o-batches__skeleton o-batches__skeleton--light" :style="{width: (width - 40) + 'px'}"></div>
                </div>
            </div>

            <div v-else-if="listView.kind !== 'rows'" class="o-batches__state" :role="listView.kind === 'unknown' ? 'status' : null">
                <div class="o-batches__state-title">{{ listView.title }}</div>
                <div class="o-batches__muted o-batches__center">{{ listView.text }}</div>
            </div>

            <template v-else>
                <ul class="o-batches__rows" :class="{'o-batches__rows--stale': listStale}">
                    <li v-for="row in rows" :key="row.id">
                        <router-link :to="batchLink(row)" class="o-batches__row" :class="{'o-batches__row--selected': String(row.id) === batchId}"
                                     :data-batch-id="row.id" :aria-current="String(row.id) === batchId ? 'page' : null" :title="rowTitle(row).text">
                            <span class="o-batches__row-line">
                                <span class="o-batches__row-name" :class="{'o-batches__unnamed': rowTitle(row).unnamed}">{{ rowTitle(row).text }}</span>
                                <span class="o-batches__row-time" :title="rowTime(row, now).title">{{ rowTime(row, now).text }}</span>
                            </span>
                            <span class="o-batches__row-meta" :title="rowMeta(row, now).title">
                                <span class="o-batches__mono">{{ rowMeta(row, now).id }}</span><template v-if="rowMeta(row, now).created"> · {{ rowMeta(row, now).created }}</template>
                            </span>
                            <span class="o-batches__bar" aria-hidden="true">
                                <span v-for="(segment, index) in progressBar(row) || []" :key="index" :class="`o-batches__bar-part o-batches__bar-part--${segment.tone}`" :style="barStyle(segment)"></span>
                            </span>
                            <span class="o-batches__row-counts">
                                <template v-for="(part, index) in countParts(row)" :key="index"><template v-if="index"> · </template><span :class="{'o-batches__danger': part.danger}">{{ part.text }}</span></template>
                            </span>
                        </router-link>
                    </li>
                </ul>
            </template>

            <div v-if="pagerShown" class="o-batches__pager">
                <span class="o-batches__muted o-batches__small">50 per page</span>
                <span class="o-batches__grow"></span>
                <button v-if="cursors.length" type="button" class="o-batches__link" :disabled="listLoading" @click="showNewer">‹ Newer</button>
                <button v-if="older !== null" type="button" class="o-batches__link" :disabled="listLoading" @click="showOlder">Older batches ›</button>
            </div>
        </section>

        <section class="o-batches__detail" aria-label="Batch" @keydown.esc="closeDetail">
            <router-link v-if="batchId !== null" :to="closeLink()" class="o-batches__back">
                <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><path d="M8.5 3L4.5 7l4 4" /></svg>
                Batches
            </router-link>

            <div v-if="batchId === null" class="o-batches__placeholder">
                <div class="o-batches__state-title">Choose a batch</div>
                <div class="o-batches__muted o-batches__center">Its counts, options and members show here.</div>
            </div>

            <div v-else-if="detailFailure" class="o-batches__state" :role="detailFailure.gone ? 'status' : 'alert'">
                <div class="o-batches__state-title">{{ detailFailure.title }}</div>
                <div class="o-batches__muted o-batches__center">{{ detailFailure.text }}</div>
                <o-button v-if="!detailFailure.gone" variant="write" @click="loadDetail()">Try again</o-button>
            </div>

            <div v-else-if="detailUnknown" class="o-batches__state" role="status">
                <div class="o-batches__state-title">{{ detailUnknown.title }}</div>
                <div class="o-batches__muted o-batches__center">{{ detailUnknown.text }}</div>
                <!-- A later read that failed keeps the unknown answer: it says Stale, as a known batch does. -->
                <div v-if="detailStale" class="o-batches__stale">
                    <span class="o-batches__stale-dot" aria-hidden="true"></span>
                    <span class="o-batches__grow">{{ staleText('batch', detailUpdatedAt) }}</span>
                    <button type="button" class="o-batches__link" @click="loadDetail()">Retry now</button>
                </div>
            </div>

            <div v-else-if="!batch" class="o-batches__detail-skeleton" aria-busy="true" aria-label="Loading">
                <div class="o-batches__skeleton"></div>
                <div class="o-batches__skeleton o-batches__skeleton--card"></div>
            </div>

            <template v-else>
                <header class="o-batches__header">
                    <div class="o-batches__heading">
                        <div class="o-batches__title-line">
                            <h2 class="o-batches__title" :class="{'o-batches__unnamed': title.unnamed}" :title="title.text">{{ title.text }}</h2>
                            <span class="o-batches__chip" :class="`o-batches__chip--${chip.tone}`">
                                <span class="o-batches__chip-dot" aria-hidden="true"></span>{{ chip.label }}
                            </span>
                        </div>
                        <div class="o-batches__meta">
                            <span class="o-batches__mono o-batches__id" :title="batch.id">{{ batch.id }}</span>
                            <button type="button" class="o-batches__copy" :title="copied || 'Copy the batch id'" @click="copyId">
                                <svg width="13" height="13" viewBox="0 0 14 14" aria-hidden="true"><rect x="4.5" y="4.5" width="7" height="7" rx="1.5" /><path d="M9.5 4.5V3.5A1 1 0 0 0 8.5 2.5h-5a1 1 0 0 0-1 1v5a1 1 0 0 0 1 1h1" /></svg>
                                <span class="o-batches__sr">{{ copied || 'Copy the batch id' }}</span>
                            </button>
                            <span v-if="copied" class="o-batches__muted o-batches__small" role="status">{{ copied }}</span>
                        </div>
                        <div class="o-batches__muted o-batches__small">{{ headerTimes(batch) }}</div>
                    </div>
                    <o-button v-if="retry.visible" operate variant="primary" icon="retry" :disabled="bulk.running" @click="openBulk">Retry failed members…</o-button>
                </header>

                <p v-if="retry.readOnlyText" class="o-batches__muted o-batches__small" role="note">{{ retry.readOnlyText }}</p>

                <div v-if="detailStale" class="o-batches__stale" role="status">
                    <span class="o-batches__stale-dot" aria-hidden="true"></span>
                    <span class="o-batches__grow">{{ staleText('batch', detailUpdatedAt) }}</span>
                    <button type="button" class="o-batches__link" @click="loadDetail()">Retry now</button>
                </div>

                <div class="o-batches__counts o-batches__wide-only" :class="{'o-batches__counts--stale': detailStale}">
                    <div class="o-batches__count o-batches__count--progress">
                        <span class="o-batches__count-label">Progress</span>
                        <span class="o-batches__progress">
                            <span class="o-batches__count-value">{{ progressText(batch) }}</span>
                            <span class="o-batches__bar o-batches__bar--grow" aria-hidden="true">
                                <span v-for="(segment, index) in bar || []" :key="index" :class="`o-batches__bar-part o-batches__bar-part--${segment.tone}`" :style="barStyle(segment)"></span>
                            </span>
                        </span>
                    </div>
                    <div class="o-batches__count">
                        <span class="o-batches__count-label">Total</span>
                        <span class="o-batches__count-value">{{ countText(batch.total_jobs) }}</span>
                    </div>
                    <div class="o-batches__count">
                        <span class="o-batches__count-label">Processed</span>
                        <span class="o-batches__count-value">{{ countText(batch.processed_jobs) }}</span>
                    </div>
                    <div class="o-batches__count">
                        <span class="o-batches__count-label">Pending</span>
                        <span class="o-batches__count-value">{{ countText(batch.pending_jobs) }}</span>
                    </div>
                    <div class="o-batches__count">
                        <span class="o-batches__count-label">Failed</span>
                        <span class="o-batches__count-value" :class="{'o-batches__danger': batch.failed_jobs > 0}">{{ countText(batch.failed_jobs) }}</span>
                    </div>
                </div>

                <div class="o-batches__card o-batches__narrow-only">
                    <div class="o-batches__card-line">
                        <span class="o-batches__count-value">{{ progressText(batch) }}</span>
                        <span class="o-batches__muted">{{ countParts(batch)[0].text }}</span>
                    </div>
                    <span class="o-batches__bar" aria-hidden="true">
                        <span v-for="(segment, index) in bar || []" :key="index" :class="`o-batches__bar-part o-batches__bar-part--${segment.tone}`" :style="barStyle(segment)"></span>
                    </span>
                    <span class="o-batches__muted o-batches__small">
                        <template v-for="(part, index) in narrowSummary" :key="index"><template v-if="index"> · </template><span :class="{'o-batches__danger': part.danger}">{{ part.text }}</span></template>
                    </span>
                </div>

                <div class="o-batches__options o-batches__wide-only">
                    <p v-if="options.omitted" class="o-batches__muted">{{ options.omitted }}</p>
                    <p v-else-if="options.unreadable" class="o-batches__muted">The options could not be shown.</p>
                    <template v-else>
                        <div class="o-batches__option"><span class="o-batches__option-label">Connection</span><span class="o-batches__option-value" :title="options.connection">{{ options.connection }}</span></div>
                        <div class="o-batches__option"><span class="o-batches__option-label">Queue</span><span class="o-batches__option-value" :title="options.queue">{{ options.queue }}</span></div>
                        <div class="o-batches__option"><span class="o-batches__option-label">Allows failures</span><span class="o-batches__option-value">{{ options.allowFailures }}</span></div>
                        <div v-if="options.callbacks.length" class="o-batches__option">
                            <span class="o-batches__option-label">Callbacks</span>
                            <span class="o-batches__option-value">{{ options.callbacks.join(', ') }}</span>
                            <span class="o-batches__marker">closures · not shown</span>
                        </div>
                        <div v-if="options.others.length" class="o-batches__option">
                            <span class="o-batches__option-label">Other options</span>
                            <span class="o-batches__option-value" :title="options.others.join(', ')">{{ options.others.join(', ') }}</span>
                        </div>
                    </template>
                </div>
            </template>

            <!-- A sibling of the state chain: a failed or 404 batch read never unmounts a running bulk run (bulkPanelMounted). -->
            <div v-if="bulkMounted" class="o-batches__bulk">
                <bulk-run :key="batchId" :open="bulk.open" action="retry" :source="source" :selection-key="`retry:${batchId}`"
                          recover :storage-key="storageKey" source-kind="batch"
                          @started="onBulkStarted" @close="onBulkClose" @finished="onBulkFinished" @restart="onBulkRestart" />
            </div>

            <template v-if="contentShown">
                <nav class="o-batches__tabs" aria-label="Members of this batch in the Jobs list">
                    <ul class="o-batches__tab-list">
                        <li v-for="tab in tabs" :key="tab.key">
                            <router-link :to="tab.to" class="o-batches__tab" :title="`Open the ${tab.label.toLowerCase()} members in the Jobs list`">
                                <span class="o-batches__tab-label">{{ tab.label }}</span>
                                <span class="o-batches__tab-count" :class="{'o-batches__danger': tab.danger, 'o-batches__unknown': tab.unknown}">{{ tab.count }}</span>
                            </router-link>
                        </li>
                    </ul>
                    <span class="o-batches__caption">{{ $options.MEMBERS_CAPTION }}</span>
                </nav>
                <p class="o-batches__muted o-batches__small">Each tab opens its members in the Jobs list, filtered by this batch.</p>
            </template>
        </section>

        <confirm-sheet :open="leave.open" title="Leave this batch?" :message="leaveText" confirm-label="Leave"
                       tone="danger" :snapshot-key="leave.key" @confirm="confirmLeave" @cancel="cancelLeave" />
    </div>
</template>

<style scoped>
    .o-batches {
        display: flex;
        flex: 1 1 0%;
        font-family: var(--font-sans);
        min-height: 0;
        overflow-wrap: anywhere;
    }

    .o-batches__sr {
        clip: rect(0 0 0 0);
        height: 1px;
        overflow: hidden;
        position: absolute;
        white-space: nowrap;
        width: 1px;
    }

    .o-batches__muted { color: var(--color-text-secondary); }
    .o-batches__unknown { color: var(--color-text-tertiary); }
    .o-batches__danger { color: var(--color-danger-text); font-weight: var(--font-weight-semibold); }
    .o-batches__grow { flex-grow: 1; }
    .o-batches__center { text-align: center; }

    .o-batches__small {
        font-size: var(--text-caption);
        line-height: 16px;
    }

    .o-batches__mono {
        font-family: var(--font-mono);
        font-size: var(--text-caption);
        line-height: 16px;
    }

    /* The list pane. */
    .o-batches__list {
        border-right: 1px solid var(--color-separator);
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
        flex-shrink: 0;
        overflow-y: auto;
        width: 400px;
    }

    .o-batches__search-bar {
        border-bottom: 1px solid var(--color-separator);
        padding: 14px 16px 10px;
    }

    .o-batches__search {
        align-items: center;
        background-color: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-control);
        box-sizing: border-box;
        display: flex;
        gap: 6px;
        height: 28px;
        padding-inline: 8px;
    }

    .o-batches__search:focus-within {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: 1px;
    }

    .o-batches__search svg,
    .o-batches__back svg,
    .o-batches__copy svg {
        flex-shrink: 0;
    }

    .o-batches__search svg circle,
    .o-batches__search svg path {
        fill: none;
        stroke: var(--color-text-secondary);
        stroke-linecap: round;
        stroke-width: 1.4;
    }

    .o-batches__search-input {
        background: transparent;
        border: 0;
        color: var(--color-text);
        flex-grow: 1;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        line-height: 18px;
        min-width: 0;
        outline: none;
        padding: 0;
    }

    .o-batches__search-input::placeholder {
        color: var(--color-text-tertiary);
    }

    .o-batches__rows {
        display: flex;
        flex-direction: column;
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .o-batches__rows--stale {
        opacity: 0.7;
    }

    .o-batches__row {
        border-bottom: 1px solid var(--color-separator);
        color: inherit;
        display: flex;
        flex-direction: column;
        gap: 6px;
        padding: 12px 16px;
        text-decoration: none;
    }

    .o-batches__row:hover {
        background-color: var(--color-subtle);
    }

    .o-batches__row:focus-visible,
    .o-batches__link:focus-visible,
    .o-batches__back:focus-visible,
    .o-batches__copy:focus-visible,
    .o-batches__tab:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: -2px;
    }

    .o-batches__row--selected,
    .o-batches__row--selected:hover {
        background-color: var(--color-accent-tint);
        box-shadow: inset 3px 0 0 var(--color-accent);
    }

    .o-batches__row-line {
        align-items: baseline;
        display: flex;
        gap: 8px;
        min-width: 0;
    }

    .o-batches__row-name {
        color: var(--color-text);
        flex-grow: 1;
        font-size: var(--text-table);
        font-weight: var(--font-weight-semibold);
        line-height: 18px;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-batches__unnamed {
        color: var(--color-text-secondary);
        font-style: italic;
        font-weight: var(--font-weight-regular);
    }

    .o-batches__row-time {
        color: var(--color-text-secondary);
        flex-shrink: 0;
        font-size: var(--text-caption);
        line-height: 16px;
        white-space: nowrap;
    }

    .o-batches__row-meta {
        color: var(--color-text-tertiary);
        font-size: var(--text-caption);
        line-height: 16px;
        margin-top: -4px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-batches__row-counts {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
    }

    .o-batches__bar {
        background-color: var(--color-separator);
        border-radius: 3px;
        display: flex;
        flex-shrink: 0;
        height: 6px;
        overflow: hidden;
    }

    .o-batches__bar--grow {
        flex-grow: 1;
    }

    .o-batches__bar-part { display: block; height: 100%; }
    .o-batches__bar-part--accent { background-color: var(--color-accent); }
    .o-batches__bar-part--success { background-color: var(--color-success); }
    .o-batches__bar-part--paused { background-color: var(--color-paused); }
    .o-batches__bar-part--danger { background-color: var(--color-danger); }

    .o-batches__row--skeleton {
        cursor: default;
    }

    .o-batches__skeleton {
        background-color: var(--color-separator);
        border-radius: 5px;
        height: 10px;
    }

    .o-batches__skeleton--bar {
        height: 6px;
        width: 100%;
    }

    .o-batches__skeleton--light {
        background-color: var(--color-sidebar);
        height: 8px;
    }

    .o-batches__skeleton--card {
        border-radius: var(--radius-card);
        height: 80px;
    }

    .o-batches__pager {
        align-items: center;
        display: flex;
        gap: 12px;
        padding: 12px 16px;
    }

    .o-batches__link {
        background: transparent;
        border: 0;
        color: var(--color-accent);
        cursor: pointer;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        font-weight: var(--font-weight-medium);
        line-height: 18px;
        padding: 2px;
        white-space: nowrap;
    }

    .o-batches__link:disabled {
        color: var(--color-text-tertiary);
        cursor: default;
    }

    .o-batches__state,
    .o-batches__placeholder {
        align-items: center;
        display: flex;
        flex-direction: column;
        font-size: var(--text-table);
        gap: 8px;
        line-height: var(--leading-table);
        padding: 28px 20px;
    }

    .o-batches__state-title {
        color: var(--color-text);
        font-size: var(--text-body);
        font-weight: var(--font-weight-semibold);
        line-height: var(--leading-body);
        text-align: center;
    }

    .o-batches__stale {
        align-items: center;
        background-color: var(--color-warning-tint);
        border: 1px solid var(--color-separator);
        border-radius: 8px;
        display: flex;
        font-size: var(--text-table);
        gap: 10px;
        line-height: var(--leading-table);
        margin: 10px 16px 0;
        padding: 8px 12px;
    }

    .o-batches__detail .o-batches__stale {
        margin: 0;
    }

    .o-batches__stale-dot {
        background-color: var(--color-warning);
        border-radius: 50%;
        flex-shrink: 0;
        height: 7px;
        width: 7px;
    }

    /* The detail pane. */
    .o-batches__detail {
        box-sizing: border-box;
        display: flex;
        flex: 1 1 0%;
        flex-direction: column;
        gap: 16px;
        min-width: 0;
        overflow-y: auto;
        padding: 18px 24px 24px;
    }

    .o-batches__back {
        align-items: center;
        color: var(--color-accent);
        display: none;
        font-size: var(--text-body);
        gap: 2px;
        line-height: 18px;
        text-decoration: none;
    }

    .o-batches__back svg path {
        fill: none;
        stroke: var(--color-accent);
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-width: 1.6;
    }

    .o-batches__detail-skeleton {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .o-batches__detail-skeleton .o-batches__skeleton:first-child {
        height: 22px;
        width: 220px;
    }

    .o-batches__header {
        align-items: flex-start;
        display: flex;
        gap: 12px;
    }

    .o-batches__heading {
        display: flex;
        flex-direction: column;
        flex-grow: 1;
        gap: 4px;
        min-width: 0;
    }

    .o-batches__title-line {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 6px 10px;
        min-width: 0;
    }

    .o-batches__title {
        color: var(--color-text);
        font-size: var(--text-title);
        font-weight: var(--font-weight-semibold);
        letter-spacing: var(--tracking-tight);
        line-height: 28px;
        margin: 0;
        max-width: 100%;
        overflow-wrap: anywhere;
    }

    .o-batches__chip {
        align-items: center;
        border-radius: 10px;
        display: inline-flex;
        flex-shrink: 0;
        font-size: var(--text-table);
        font-weight: var(--font-weight-semibold);
        gap: 5px;
        height: 22px;
        line-height: 18px;
        padding-inline: 9px;
    }

    .o-batches__chip-dot {
        border-radius: 50%;
        height: 7px;
        width: 7px;
    }

    .o-batches__chip--accent { background-color: var(--color-accent-tint); color: var(--color-accent); }
    .o-batches__chip--accent .o-batches__chip-dot { background-color: var(--color-accent); }
    .o-batches__chip--success { background-color: var(--color-success-tint); color: var(--color-success-text); }
    .o-batches__chip--success .o-batches__chip-dot { background-color: var(--color-success); }
    .o-batches__chip--neutral { background-color: var(--color-sidebar); color: var(--color-text-secondary); }
    .o-batches__chip--neutral .o-batches__chip-dot { background-color: var(--color-paused); }
    .o-batches__chip--warning { background-color: var(--color-warning-tint); color: var(--color-warning-text); }
    .o-batches__chip--warning .o-batches__chip-dot { background-color: var(--color-warning); }

    .o-batches__meta {
        align-items: center;
        color: var(--color-text-secondary);
        display: flex;
        flex-wrap: wrap;
        gap: 4px 6px;
        min-width: 0;
    }

    .o-batches__id {
        color: var(--color-text-secondary);
        min-width: 0;
    }

    .o-batches__copy {
        align-items: center;
        background: transparent;
        border: 0;
        border-radius: 4px;
        cursor: pointer;
        display: inline-flex;
        padding: 2px;
    }

    .o-batches__copy svg rect,
    .o-batches__copy svg path {
        fill: none;
        stroke: var(--color-accent);
        stroke-width: 1.2;
    }

    .o-batches__counts {
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-card);
        display: flex;
        overflow: hidden;
    }

    .o-batches__counts--stale {
        opacity: 0.7;
    }

    .o-batches__count {
        border-right: 1px solid var(--color-separator);
        display: flex;
        flex: 1 1 0%;
        flex-direction: column;
        gap: 6px;
        min-width: 0;
        padding: 12px 14px;
    }

    .o-batches__count:last-child {
        border-right: 0;
    }

    .o-batches__count--progress {
        flex-grow: 1.4;
    }

    .o-batches__count-label {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
    }

    .o-batches__count-value {
        color: var(--color-text);
        font-size: var(--text-stat);
        font-weight: var(--font-weight-semibold);
        letter-spacing: var(--tracking-tight);
        line-height: 32px;
    }

    .o-batches__progress {
        align-items: center;
        display: flex;
        gap: 10px;
    }

    .o-batches__card {
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-card);
        flex-direction: column;
        gap: 6px;
        padding: 12px;
    }

    .o-batches__card-line {
        align-items: baseline;
        display: flex;
        font-size: var(--text-table);
        gap: 8px;
        line-height: 18px;
    }

    .o-batches__options {
        display: flex;
        flex-wrap: wrap;
        font-size: var(--text-table);
        gap: 8px 20px;
        line-height: 18px;
        margin: 0;
    }

    .o-batches__options p {
        margin: 0;
    }

    .o-batches__option {
        align-items: baseline;
        display: flex;
        gap: 6px;
        min-width: 0;
    }

    .o-batches__option-label {
        color: var(--color-text-secondary);
        flex-shrink: 0;
        font-weight: var(--font-weight-regular);
    }

    .o-batches__option-value {
        color: var(--color-text);
        margin: 0;
        max-width: 420px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-batches__marker {
        border: 1px dashed color-mix(in srgb, var(--color-text) 25%, transparent);
        border-radius: 4px;
        color: var(--color-text-secondary);
        flex-shrink: 0;
        font-size: var(--text-caption);
        line-height: 16px;
        padding-inline: 6px;
    }

    .o-batches__tabs {
        align-items: flex-end;
        border-bottom: 1px solid var(--color-separator);
        display: flex;
        flex-wrap: wrap;
        gap: 4px 20px;
    }

    .o-batches__tab-list {
        display: flex;
        flex-wrap: wrap;
        gap: 0 20px;
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .o-batches__tab {
        align-items: baseline;
        border-bottom: 2px solid transparent;
        color: var(--color-text);
        display: flex;
        gap: 6px;
        padding-bottom: 7px;
        text-decoration: none;
        white-space: nowrap;
    }

    .o-batches__tab:hover {
        border-bottom-color: var(--color-accent);
    }

    .o-batches__tab-label {
        font-size: var(--text-body);
        line-height: 18px;
    }

    .o-batches__tab-count {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
    }

    .o-batches__caption {
        color: var(--color-text-tertiary);
        flex: 1 1 200px;
        font-size: var(--text-caption);
        line-height: 16px;
        padding-bottom: 10px;
        text-align: right;
    }

    .o-batches__narrow-only {
        display: none;
    }

    @media (min-width: 1800px) {
        .o-batches__list {
            width: 480px;
        }
    }

    @media (max-width: 1023px) {
        .o-batches__counts {
            flex-wrap: wrap;
        }

        .o-batches__count--progress {
            flex-basis: 100%;
            border-bottom: 1px solid var(--color-separator);
            border-right: 0;
        }
    }

    /* Below 768 px the list and the detail are two screens. */
    @media (max-width: 767px) {
        .o-batches__list {
            border-right: 0;
            width: 100%;
        }

        .o-batches--open .o-batches__list,
        .o-batches:not(.o-batches--open) .o-batches__detail {
            display: none;
        }

        .o-batches__detail {
            gap: 12px;
            padding: 12px 16px 20px;
        }

        .o-batches__back {
            display: flex;
        }

        .o-batches__header {
            flex-direction: column;
        }

        .o-batches__wide-only {
            display: none;
        }

        .o-batches__narrow-only {
            display: flex;
        }

        .o-batches__tabs {
            border-bottom: 0;
            flex-direction: column;
            align-items: stretch;
        }

        .o-batches__tab-list {
            flex-wrap: nowrap;
            gap: 6px;
            overflow-x: auto;
        }

        .o-batches__tab {
            background-color: var(--color-sidebar);
            border-bottom: 0;
            border-radius: 14px;
            padding: 4px 10px;
        }

        .o-batches__tab-label {
            font-size: var(--text-table);
        }

        .o-batches__caption {
            flex-basis: auto;
            padding-bottom: 0;
            text-align: left;
        }
    }
</style>
