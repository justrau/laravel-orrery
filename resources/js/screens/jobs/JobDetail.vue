<script>
    import Button from '../../components/ui/Button.vue';
    import ConfirmSheet from '../../components/ui/ConfirmSheet.vue';
    import Notice from '../../components/ui/Notice.vue';
    import {BACKGROUND_HANDLES} from '../../shell/poll.js';
    import {requestLiveRefresh, setToolbar} from '../../shell/store.js';
    import {formatDayClock, formatLocal, nowSeconds} from '../../support/time.js';
    import {
        actionsFor,
        backLink,
        countersLine,
        cycleHistoryAfter,
        cycleHistoryToDrop,
        cycleTabs,
        cycleView,
        deleteSheet,
        detailUrl,
        earlierView,
        entryUrl,
        exceptionView,
        forgetRemovedRow,
        forgetSheet,
        headerFacts,
        headline,
        historyCycleOptions,
        historyEntryText,
        historyEventLabel,
        historyFromDetail,
        historyNotices,
        historyPageOf,
        historyParams,
        historyUrl,
        inspectorLinks,
        layoutFor,
        messageBox,
        notFoundState,
        operationPanel,
        readFailure,
        readFailureState,
        resolveOutcome,
        resolveSheet,
        retriesBlock,
        retrySheet,
        runNowSheet,
        statusChip,
        toolbarSubtitle,
        writeFailure,
        writeOutcome,
        writeRequest,
    } from './detail.js';
    import {enforcedRuleText, livenessText, signalText, signalsEmptyText} from './rules.js';
    import PayloadTree from './PayloadTree.vue';

    /** The detail polls every 10 s, as the shell does. */
    const POLL_SECONDS = 10;

    /** A write's answer is read inline: these statuses and failures never raise the global alert (a 403 does). */
    const WRITE_HANDLES = [404, 409, 422, 500, 502, 503, 504, 'timeout', 'network', 'aborted'];

    function emptyHistory() {
        return {page: null, cycle: null, cursors: [], loading: false, error: false};
    }

    /**
     * The Job detail screen (`/jobs/:uuid`).
     *
     * - It reads `GET /api/jobs/{uuid}` and polls it every 10 s (single
     *   flight, the newest answer only). A 404 shows "This job is no longer
     *   kept" and stops the polling; `redaction_config_invalid` hides the
     *   whole panel; a failed first read offers Try again, a failed poll keeps
     *   the last data and says "Stale".
     * - Retry and Forget open a ConfirmSheet whose frozen
     *   selection carries the occurrence id of the page read; the write posts
     *   exactly that selection, and its answer is read from `result`.
     *   Resolve posts the frozen operation id. Without canOperate no write
     *   control exists.
     * - The history pages by cursor; an entry opens with its full exception
     *   and context. The data (command, envelope, context) is the server's
     *   data-only projection, drawn by PayloadTree: the browser unserializes
     *   nothing.
     * - At 1024 px and wider the inspector is a column on the right; below,
     *   the operation sits above the cycles, History, Data and Exception
     *   start collapsed, and below 768 Retry and Forget sit in a bottom bar.
     *
     * The rules are in detail.js and rules.js; this file only draws them.
     */
    export default {
        components: {'o-button': Button, ConfirmSheet, Notice, PayloadTree},

        props: {
            uuid: {type: String, default: null},
            legacyId: {type: String, default: null},
        },

        data() {
            return {
                pollSeconds: POLL_SECONDS,
                detail: null,
                lastKnown: null,
                loading: true,
                failure: null,
                stale: false,
                gone: false,
                redaction: null,
                now: nowSeconds(),
                layout: layoutFor(typeof window !== 'undefined' ? window.innerWidth : 1440),
                sections: {history: false, data: false, exception: false},
                selectedCycle: null,
                dataTab: 'command',
                exceptionExpanded: false,
                history: emptyHistory(),
                cycleHistory: {},
                entry: null,
                sheet: {open: false, content: null, key: 0},
                busy: false,
                outcome: null,
                copied: null,
            };
        },

        computed: {
            sqliteConnections() {
                return typeof Orrery !== 'undefined' && Array.isArray(Orrery.sqliteQueueConnections) ? Orrery.sqliteQueueConnections : [];
            },

            pollActive() {
                return !this.gone && this.legacyId === null && detailUrl(this.uuid) !== null;
            },

            /** The screen's own forget removed the row: the not-found panel says so. */
            forgotten() {
                return !!this.outcome && this.outcome.action === 'forget' && this.outcome.removedRow === true;
            },

            /** The screen's own delete removed the job: the not-found panel says so. */
            deleted() {
                return !!this.outcome && this.outcome.action === 'delete' && this.outcome.removedRow === true;
            },

            notFound() {
                return notFoundState(this.legacyId, this.forgotten, this.deleted);
            },

            /** The list tab of the row; after a 404 the tab of the last read, so "Back to Failed" stays. */
            back() {
                return backLink(this.detail || this.lastKnown);
            },

            chip() {
                return this.detail ? statusChip(this.detail.status, this.detail.silenced) : null;
            },

            head() {
                return headline(this.detail);
            },

            message() {
                return messageBox(this.detail);
            },

            facts() {
                return headerFacts(this.detail, this.now);
            },

            factColumns() {
                return [this.facts.slice(0, 4), this.facts.slice(4)];
            },

            actions() {
                return actionsFor(this.detail, this.canOperate);
            },

            operation() {
                return operationPanel(this.detail ? this.detail.operation : null);
            },

            active() {
                return !!this.detail && (this.detail.status === 'pending' || this.detail.status === 'reserved');
            },

            signals() {
                return this.active && Array.isArray(this.detail.signals) ? this.detail.signals.map(signal => signalText(signal)) : [];
            },

            signalsEmpty() {
                return this.detail ? signalsEmptyText(this.detail.status, this.detail.lease_expires_at, this.detail.signals) : null;
            },

            activeRule() {
                return this.active ? enforcedRuleText(this.detail.enforced_rule, 'now') : null;
            },

            worker() {
                return this.detail && this.detail.status === 'reserved' ? livenessText(this.detail.worker, this.detail.lease_expires_at, this.now) : null;
            },

            tabs() {
                return cycleTabs(this.detail ? this.detail.cycles : null);
            },

            currentTab() {
                return this.tabs.find(tab => tab.key === this.selectedCycle) || this.tabs[0] || null;
            },

            selected() {
                if (!this.detail || !Array.isArray(this.detail.cycles) || !this.currentTab) return null;

                return this.detail.cycles.find(cycle => (this.currentTab.merged ? cycle.merged === true : cycle.merged !== true && cycle.cycle === this.currentTab.cycle)) || null;
            },

            view() {
                return cycleView(this.selected);
            },

            factLine() {
                return countersLine(this.selected);
            },

            earlier() {
                const cycle = this.selected && !this.selected.merged ? this.selected.cycle : null;

                return earlierView(cycle === null ? undefined : this.cycleHistory[cycle], cycle);
            },

            retries() {
                return this.detail ? retriesBlock(this.detail.retries) : null;
            },

            links() {
                return this.detail ? inspectorLinks(this.detail) : null;
            },

            historyOptions() {
                return historyCycleOptions(this.detail ? this.detail.cycles : null);
            },

            historyPage() {
                return this.history.page;
            },

            historyNotes() {
                return historyNotices(this.history.page);
            },

            exception() {
                return this.detail ? exceptionView(this.detail.exception, this.exceptionExpanded) : null;
            },

            dataValue() {
                if (!this.detail) return null;

                return {command: this.detail.command, envelope: this.detail.payload, context: this.detail.context}[this.dataTab];
            },

            entryException() {
                return this.entry && this.entry.data ? exceptionView(this.entry.data.exception, true) : null;
            },
        },

        watch: {
            uuid: {
                immediate: true,
                handler() {
                    this.reset();
                    this.load();
                },
            },

            detail(detail) {
                setToolbar({title: detail ? detail.name : 'Job', subtitle: toolbarSubtitle(this.now)});
            },
        },

        mounted() {
            setToolbar({title: 'Job', subtitle: toolbarSubtitle(this.now)});
            this.clock = setInterval(() => {
                this.now = nowSeconds();
            }, 15000);
            this.onResize = () => {
                this.layout = layoutFor(window.innerWidth);
            };
            window.addEventListener('resize', this.onResize);
        },

        beforeUnmount() {
            clearInterval(this.clock);
            window.removeEventListener('resize', this.onResize);
            // Answers that arrive after unmount change nothing.
            ['detail', 'history', 'entry', 'write'].forEach(kind => this.startRequest(kind));
            this.cycleGeneration = (this.cycleGeneration || 0) + 1;
        },

        methods: {
            reset() {
                ['detail', 'history', 'entry', 'write'].forEach(kind => this.startRequest(kind));
                this.cycleGeneration = (this.cycleGeneration || 0) + 1;
                Object.assign(this, {
                    detail: null, lastKnown: null, loading: true, failure: null, stale: false, gone: false, redaction: null,
                    selectedCycle: null, dataTab: 'command', exceptionExpanded: false, history: emptyHistory(),
                    cycleHistory: {}, entry: null, outcome: null, busy: false,
                });
                this.sheet.open = false;
            },

            api(path) {
                return Orrery.basePath + path;
            },

            /** The 10 s poll: a background read of the detail. */
            poll() {
                return this.load();
            },

            /**
             * Read the detail. Only the newest answer changes the screen. A
             * 404 is final (polling stops); a failure after a good read keeps
             * the last data and marks it stale.
             */
            load() {
                const url = detailUrl(this.uuid);

                if (url === null || this.legacyId !== null) {
                    this.loading = false;
                    this.gone = true;

                    return Promise.resolve();
                }

                const sequence = this.startRequest('detail');

                return this.$http.get(this.api(url), {handles: BACKGROUND_HANDLES})
                    .then(response => {
                        if (!this.isCurrentRequest('detail', sequence)) return;

                        this.apply(response.data);
                    })
                    .catch(error => {
                        if (this.isCurrentRequest('detail', sequence)) this.readFailed(error, 'detail');
                    })
                    .finally(() => {
                        if (this.isCurrentRequest('detail', sequence)) this.loading = false;
                    });
            },

            /**
             * A failed read of any part (detail.js readFailureState()): a
             * 404 marks the job gone, a redaction error hides all job data,
             * a failed poll keeps the last detail as stale. Returns the
             * state, so a section can show its own error.
             */
            readFailed(error, source) {
                const failure = readFailure(error);
                const state = readFailureState(failure.kind, source, !!this.detail);

                if (state.next === 'gone') {
                    this.markGone(state.invalidate);
                } else if (state.next === 'redaction') {
                    // No job data is shown while the rules are invalid, not even the last read.
                    this.redaction = failure;
                    this.detail = null;
                    this.sheet.open = false;
                } else if (state.next === 'stale') {
                    this.stale = true;
                } else if (state.next === 'failure') {
                    this.failure = failure;
                }

                return {failure, state};
            },

            /**
             * The job is no longer kept: the detail goes, an open sheet
             * closes, polling stops, and the reads in flight change nothing
             * (a late answer would paint the gone job again).
             */
            markGone(invalidate = ['detail', 'history', 'entry']) {
                invalidate.forEach(kind => this.startRequest(kind));
                this.cycleGeneration = (this.cycleGeneration || 0) + 1;
                this.gone = true;
                this.loading = false;
                this.detail = null;
                this.entry = null;
                this.history.loading = false;
                this.sheet.open = false;
            },

            apply(data) {
                // A job that is gone stays gone: a late answer never brings it back.
                if (this.gone) return;

                if (!data || typeof data !== 'object' || typeof data.id !== 'string') {
                    this.failure = readFailure({response: {status: 500, data: {}}});

                    return;
                }

                const drop = cycleHistoryToDrop(this.detail, data);

                // Read that cycle's history again; its last entries stay on screen until the answer.
                if (drop !== null && this.cycleHistory[drop]) this.cycleHistory[drop] = {...this.cycleHistory[drop], reload: true};

                this.detail = data;
                this.lastKnown = {status: data.status, silenced: data.silenced};
                this.failure = null;
                this.stale = false;
                this.redaction = null;

                // The first history page comes with the detail; a page the user moved to stays.
                if (this.history.cycle === null && this.history.cursors.length === 0 && !this.history.loading) {
                    // A history section that could not be read keeps the last good page, marked stale.
                    Object.assign(this.history, historyFromDetail(this.history.page, data.history));
                }

                if (this.selectedCycle !== null && !this.tabs.some(tab => tab.key === this.selectedCycle)) {
                    this.selectedCycle = null;
                }

                this.loadCycleHistory();
            },

            retryFirstLoad() {
                this.loading = true;
                this.failure = null;
                this.load();
            },

            sectionOpen(name) {
                return this.layout === 'wide' || this.sections[name];
            },

            toggleSection(name) {
                this.sections[name] = !this.sections[name];
            },

            selectCycle(key) {
                this.selectedCycle = key;
                this.loadCycleHistory();
            },

            /**
             * "Earlier exceptions in this cycle": the newest history page of
             * the selected cycle, read once per cycle and again when a
             * detail read marks it for a reload (detail.js
             * cycleHistoryToDrop()). The last entries stay while it reloads
             * (cycleHistoryAfter()).
             */
            loadCycleHistory() {
                const cycle = this.selected && !this.selected.merged ? this.selected.cycle : null;
                const url = historyUrl(this.uuid);
                const state = cycle === null ? undefined : this.cycleHistory[cycle];

                if (cycle === null || url === null || (state && (state.loading || !state.reload))) return;

                // One generation per job and per write: an answer of an earlier generation changes nothing.
                const generation = this.cycleGeneration;
                const current = () => generation === this.cycleGeneration && !!this.cycleHistory[cycle] && this.cycleHistory[cycle].loading;

                this.cycleHistory[cycle] = cycleHistoryAfter(state, 'loading');
                this.$http.get(this.api(url), {params: historyParams({cycle}), handles: BACKGROUND_HANDLES})
                    .then(response => {
                        if (current()) this.cycleHistory[cycle] = cycleHistoryAfter(this.cycleHistory[cycle], {page: response.data});
                    })
                    .catch(error => {
                        if (!current()) return;

                        this.cycleHistory[cycle] = cycleHistoryAfter(this.cycleHistory[cycle], {failed: true});
                        this.readFailed(error, 'cycle');
                    });
            },

            loadHistory() {
                const url = historyUrl(this.uuid);

                if (url === null) return Promise.resolve();

                const cursor = this.history.cursors.length ? this.history.cursors[this.history.cursors.length - 1] : null;
                const sequence = this.startRequest('history');

                this.history.loading = true;
                this.entry = null;

                return this.$http.get(this.api(url), {params: historyParams({cycle: this.history.cycle, cursor}), handles: BACKGROUND_HANDLES})
                    .then(response => {
                        if (!this.isCurrentRequest('history', sequence)) return;

                        const page = historyPageOf(response.data);

                        // An answer of another shape is a failed read: the last good page stays, marked stale.
                        if (page) this.history.page = page;
                        this.history.error = page === null;
                    })
                    .catch(error => {
                        if (!this.isCurrentRequest('history', sequence)) return;

                        if (this.readFailed(error, 'history').state.next === 'section_error') this.history.error = true;
                    })
                    .finally(() => {
                        if (this.isCurrentRequest('history', sequence)) this.history.loading = false;
                    });
            },

            setHistoryCycle(event) {
                const value = event.target.value === '' ? null : Number(event.target.value);

                this.history.cycle = Number.isInteger(value) ? value : null;
                this.history.cursors = [];
                this.loadHistory();
            },

            olderHistory() {
                if (!this.history.page || !this.history.page.next_cursor) return;

                this.history.cursors.push(this.history.page.next_cursor);
                this.loadHistory();
            },

            newerHistory() {
                this.history.cursors.pop();
                this.loadHistory();
            },

            newestHistory() {
                this.history.cursors = [];
                this.loadHistory();
            },

            eventLabel(entry) {
                return historyEventLabel(entry.event);
            },

            entryText(entry) {
                return historyEntryText(entry);
            },

            entryTime(entry) {
                return formatLocal(entry.occurred_at);
            },

            entryClock(entry) {
                return formatDayClock(entry.occurred_at);
            },

            /** Open one history entry with its full exception and context (GET /history/{id}). */
            openEntry(entry, event) {
                if (this.entry && this.entry.id === entry.id) {
                    this.closeEntry();

                    return;
                }

                const url = entryUrl(this.uuid, entry.id);

                if (url === null) return;

                const sequence = this.startRequest('entry');

                this.entry = {id: entry.id, loading: true, data: null, error: null, opener: event ? event.currentTarget : null};
                this.$http.get(this.api(url), {handles: BACKGROUND_HANDLES})
                    .then(response => {
                        if (this.isCurrentRequest('entry', sequence) && this.entry) this.entry = {...this.entry, loading: false, data: response.data};
                    })
                    .catch(error => {
                        if (!this.isCurrentRequest('entry', sequence) || !this.entry) return;

                        const {failure, state} = this.readFailed(error, 'entry');

                        if (state.next === 'section_error' && this.entry) this.entry = {...this.entry, loading: false, error: failure};

                        // A pruned entry 404s while the job lives; the detail read decides whether the job is gone.
                        if (failure.kind === 'not_found') this.load();
                    });
            },

            closeEntry() {
                const opener = this.entry ? this.entry.opener : null;

                this.startRequest('entry');
                this.entry = null;

                if (opener && typeof opener.focus === 'function') this.$nextTick(() => opener.focus());
            },

            /** Copy a text; the button says whether it worked. */
            copy(text, what) {
                const done = ok => {
                    this.copied = {what, ok};
                    clearTimeout(this.copiedTimer);
                    this.copiedTimer = setTimeout(() => {
                        this.copied = null;
                    }, 2000);
                };

                if (typeof navigator !== 'undefined' && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                    navigator.clipboard.writeText(text).then(() => done(true), () => done(false));
                } else {
                    done(false);
                }
            },

            copyLabel(what, label) {
                if (!this.copied || this.copied.what !== what) return label;

                return this.copied.ok ? 'Copied' : 'Copy failed';
            },

            /**
             * Open a confirmation. The sheet freezes its content and the
             * selection (the occurrence id or the operation id of the page
             * read): a poll that changes the detail changes nothing on the
             * open sheet.
             */
            openSheet(kind) {
                if (!this.detail || this.busy || this.sheet.open) return;

                let content = null;

                if (kind === 'retry' && this.actions.retry) content = retrySheet(this.detail, this.sqliteConnections);
                if (kind === 'forget' && this.actions.forget) content = forgetSheet(this.detail);
                if (kind === 'resolve' && this.actions.resolve) content = resolveSheet(this.detail.operation, this.detail);
                if (kind === 'delete' && this.actions.delete && !this.actions.deleteBlocked) content = deleteSheet(this.detail);
                if (kind === 'run_now' && this.actions.runNow && !this.actions.deleteBlocked) content = runNowSheet(this.detail);

                if (!content) return;

                this.outcome = null;
                this.sheet.content = {...content, selection: {...content.selection, note: content.note, footnote: content.footnote}};
                this.sheet.key++;
                this.sheet.open = true;
            },

            closeSheet() {
                if (!this.busy) this.sheet.open = false;
            },

            /** Post exactly the frozen selection that the sheet emits. */
            confirm(selection) {
                const request = writeRequest(selection);

                if (request === null || this.busy) return Promise.resolve();

                const action = selection.action;
                const sequence = this.startRequest('write');

                this.busy = true;

                return this.$http.post(this.api(request.url), request.body, {handles: WRITE_HANDLES})
                    .then(response => {
                        if (!this.isCurrentRequest('write', sequence)) return;

                        this.outcome = {
                            action,
                            removedRow: (action === 'forget' && forgetRemovedRow(response.data))
                                || (action === 'delete' && !!response.data && ['deleted', 'mirror_failed'].includes(response.data.result)),
                            ...(action === 'resolve' ? resolveOutcome(response.data && response.data.operation) : writeOutcome(action, response.data)),
                        };
                    })
                    .catch(error => {
                        if (!this.isCurrentRequest('write', sequence)) return;

                        const failure = writeFailure(action, error);

                        this.outcome = failure ? {action, ...failure} : null;
                    })
                    .finally(() => {
                        // Another job's page (or none) owns busy and the sheet now: reset() already reset them.
                        if (!this.isCurrentRequest('write', sequence)) return;

                        this.busy = false;
                        this.sheet.open = false;

                        if (!this.gone) {
                            this.cycleGeneration++;
                            // Every cycle's history is read again; the cached entries stay until the answers.
                            Object.keys(this.cycleHistory).forEach(cycle => {
                                this.cycleHistory[cycle] = {...this.cycleHistory[cycle], loading: false, reload: true};
                            });
                            this.load();
                            requestLiveRefresh();
                        }
                    });
            },
        },
    };
</script>

<template>
    <div class="o-jd" :class="`o-jd--${layout}`">
        <poll v-if="pollActive" :request="poll" :interval="pollSeconds" :immediate="false" />

        <toolbar-controls>
            <router-link class="o-jd__back" :to="back.to">
                <svg width="9" height="12" viewBox="0 0 9 12" aria-hidden="true" focusable="false"><path d="M6.5 1.5L2 6l4.5 4.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" /></svg>
                <span :class="{'o-jd__sr': layout === 'phone'}">{{ back.label }}</span>
            </router-link>
            <span class="o-jd__spacer"></span>
            <button v-if="detail && layout === 'phone'" type="button" class="o-jd__icon-button" :title="copyLabel('toolbar', 'Copy the uuid')" @click="copy(detail.id, 'toolbar')">
                <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true" focusable="false"><rect x="4.5" y="4.5" width="7" height="7" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.2" /><path d="M9.5 4.5V3.5A1 1 0 0 0 8.5 2.5h-5a1 1 0 0 0-1 1v5a1 1 0 0 0 1 1h1" fill="none" stroke="currentColor" stroke-width="1.2" /></svg>
                <span class="o-jd__sr">{{ copyLabel('toolbar', 'Copy the uuid') }}</span>
            </button>
            <template v-if="layout !== 'phone'">
                <o-button v-if="actions.retry" operate variant="primary" icon="retry" :disabled="busy" @click="openSheet('retry')">Retry…</o-button>
                <o-button v-if="actions.forget" operate variant="danger" :disabled="busy" @click="openSheet('forget')">Forget…</o-button>
                <o-button v-if="actions.runNow" operate variant="primary" :disabled="busy || !!actions.deleteBlocked" :title="actions.deleteBlocked || null" data-run-now @click="openSheet('run_now')">Run now…</o-button>
                <o-button v-if="actions.delete" operate variant="danger" :disabled="busy || !!actions.deleteBlocked" :title="actions.deleteBlocked || null" data-delete @click="openSheet('delete')">Delete…</o-button>
            </template>
        </toolbar-controls>

        <div class="o-jd__pane">
            <div class="o-jd__column">
                <!-- States that replace the whole panel. -->
                <div v-if="loading && !detail" class="o-jd__loading" aria-busy="true" aria-label="Loading the job">
                    <div class="o-jd__bar o-jd__bar--title"></div>
                    <div class="o-jd__bar"></div>
                    <div class="o-jd__bar o-jd__bar--short"></div>
                    <div class="o-jd__bar"></div>
                </div>

                <template v-else-if="gone">
                    <!-- A write answered before the job went: its outcome stays (a forget is in the panel's title). -->
                    <notice v-if="outcome && !forgotten" :tone="outcome.tone" :title="outcome.title" :detail="outcome.detail" />
                    <div class="o-jd__gone" role="status">
                        <div class="o-jd__gone-title">{{ notFound.title }}</div>
                        <div class="o-jd__gone-text">{{ notFound.detail }}</div>
                        <router-link class="o-jd__link" :to="back.to">Back to {{ back.label }}</router-link>
                    </div>
                </template>

                <div v-else-if="redaction" class="o-jd__redaction" role="alert">
                    <div class="o-jd__redaction-title">{{ redaction.title }}</div>
                    <div class="o-jd__redaction-text">{{ redaction.detail }}</div>
                </div>

                <notice v-else-if="failure && !detail" tone="danger" :title="failure.title" :detail="failure.detail">
                    <template #actions>
                        <o-button variant="write" @click="retryFirstLoad">Try again</o-button>
                    </template>
                </notice>

                <template v-else-if="detail">
                    <notice v-if="outcome" :tone="outcome.tone" :title="outcome.title" :detail="outcome.detail">
                        <template #actions>
                            <o-button variant="write" @click="outcome = null">Dismiss</o-button>
                        </template>
                    </notice>

                    <notice v-if="stale" tone="warning" title="Stale" detail="The last refresh failed; this is the data of the last good read. The page keeps trying." />

                    <!-- Header and facts -->
                    <section class="o-jd__header" aria-label="Job">
                        <div class="o-jd__headline">
                            <span class="o-jd__chip" :class="`o-jd__chip--${chip.tone}`"><span class="o-jd__chip-dot"></span>{{ chip.label }}</span>
                            <h2 class="o-jd__title" :title="head.title">{{ head.title }}</h2>
                            <span class="o-jd__context">{{ head.context }}</span>
                        </div>
                        <div v-if="layout === 'phone'" class="o-jd__identity" :title="detail.name">{{ detail.name }} · {{ detail.id }}</div>
                        <div v-if="message" class="o-jd__message">{{ message }}</div>

                        <div class="o-jd__facts">
                            <dl v-for="(column, index) in factColumns" :key="index" class="o-jd__fact-column">
                                <div v-for="fact in column" :key="fact.label" class="o-jd__fact">
                                    <dt>{{ fact.label }}</dt>
                                    <dd :class="{'o-jd__mono': fact.mono}">
                                        <router-link v-if="fact.to" class="o-jd__link" :to="fact.to">{{ fact.value }}</router-link>
                                        <template v-else>{{ fact.value }}</template>
                                        <button v-if="fact.copy" type="button" class="o-jd__copy" :title="copyLabel('uuid', 'Copy the uuid')" @click="copy(fact.value, 'uuid')">
                                            <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true" focusable="false"><rect x="4.5" y="4.5" width="7" height="7" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.2" /><path d="M9.5 4.5V3.5A1 1 0 0 0 8.5 2.5h-5a1 1 0 0 0-1 1v5a1 1 0 0 0 1 1h1" fill="none" stroke="currentColor" stroke-width="1.2" /></svg>
                                            <span class="o-jd__sr">{{ copyLabel('uuid', 'Copy the uuid') }}</span>
                                        </button>
                                        <span v-if="fact.copy && copied && copied.what === 'uuid'" class="o-jd__copied" role="status">{{ copyLabel('uuid', '') }}</span>
                                        <span v-if="fact.note" class="o-jd__fact-note">{{ fact.note }}</span>
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <p v-if="actions.reason" class="o-jd__read-only">Retry and Forget are not offered. {{ actions.reason }}</p>
                    </section>

                    <!-- The operation, inline below 1024 -->
                    <section v-if="layout !== 'wide'" class="o-jd__operation-inline" aria-label="Operation">
                        <div class="o-jd__caps">Operation</div>
                        <template v-if="operation.kind === 'none'">
                            <div class="o-jd__text">{{ operation.text }}</div>
                        </template>
                        <template v-else>
                            <div class="o-jd__op-head">
                                <span class="o-jd__op-label" :class="`o-jd__op-label--${operation.tone}`">{{ operation.label }}</span>
                                <span v-if="operation.id" class="o-jd__op-id">{{ operation.id }}</span>
                            </div>
                            <div class="o-jd__text">{{ operation.text }}</div>
                            <dl class="o-jd__op-facts">
                                <div v-for="fact in operation.facts" :key="fact.label" class="o-jd__op-fact"><dt>{{ fact.label }}</dt><dd>{{ fact.value }}</dd></div>
                            </dl>
                            <div v-if="operation.bulkRef" class="o-jd__text o-jd__muted">Part of a bulk run ({{ operation.bulkRef }}).</div>
                            <div class="o-jd__op-actions">
                                <o-button v-if="actions.resolve" operate variant="primary" size="lg" :disabled="busy" @click="openSheet('resolve')">Resolve…</o-button>
                                <span class="o-jd__muted">Retry and Forget are hidden while an operation holds this job.</span>
                            </div>
                        </template>
                    </section>

                    <!-- Worker, stuck signals and the enforced rule now: pending and reserved rows -->
                    <section v-if="active" class="o-jd__active" aria-label="Running state">
                        <div v-if="worker" class="o-jd__worker">
                            <span class="o-jd__caps">Worker</span>
                            <span class="o-jd__mono">{{ worker.worker || '—' }}</span>
                            <span class="o-jd__liveness" :class="`o-jd__liveness--${worker.tone}`"><span class="o-jd__chip-dot"></span>{{ worker.label }}</span>
                            <span class="o-jd__muted">{{ worker.lease }}</span>
                        </div>
                        <div class="o-jd__signals">
                            <div class="o-jd__caps">Stuck signals</div>
                            <ul v-if="signals.length" class="o-jd__signal-list">
                                <li v-for="(text, index) in signals" :key="index">{{ text }}</li>
                            </ul>
                            <div v-else class="o-jd__muted">{{ signalsEmpty }}</div>
                        </div>
                        <div v-if="activeRule" class="o-jd__rule-now">
                            <div class="o-jd__caps">Enforced rule now</div>
                            <div class="o-jd__text">{{ activeRule.headline }}</div>
                            <div v-if="activeRule.stops" class="o-jd__muted">{{ activeRule.stops }}</div>
                        </div>
                    </section>

                    <!-- Cycles and diagnostics -->
                    <section class="o-jd__section" aria-label="Cycles">
                        <div class="o-jd__section-head">
                            <h3 class="o-jd__section-title">Cycles</h3>
                            <div v-if="tabs.length" class="o-jd__segmented" role="tablist" aria-label="Cycles">
                                <button v-for="tab in tabs" :key="tab.key" type="button" role="tab" class="o-jd__segment"
                                        :class="{'o-jd__segment--on': currentTab && tab.key === currentTab.key}"
                                        :aria-selected="currentTab && tab.key === currentTab.key ? 'true' : 'false'" @click="selectCycle(tab.key)">
                                    {{ tab.label }}<span class="o-jd__dot" :class="`o-jd__dot--${tab.tone}`"></span>
                                </button>
                            </div>
                        </div>

                        <div v-if="!tabs.length" class="o-jd__muted">The cycles could not be read.</div>

                        <div v-else-if="view && view.merged" class="o-jd__card o-jd__merged">
                            <div class="o-jd__text o-jd__strong">{{ view.title }}</div>
                            <div class="o-jd__muted">{{ view.detail }}</div>
                        </div>

                        <template v-else-if="view">
                            <div v-if="view.terminal" class="o-jd__card o-jd__terminal">
                                <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" focusable="false" class="o-jd__terminal-icon"><circle cx="9" cy="9" r="7.3" fill="none" stroke="currentColor" stroke-width="1.4" /><path d="M9 8v4.4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" /><circle cx="9" cy="5.6" r="0.9" fill="currentColor" /></svg>
                                <div class="o-jd__terminal-text">
                                    <div class="o-jd__strong o-jd__body">{{ view.terminal.headline }}</div>
                                    <ul v-if="view.terminal.conditions.length" class="o-jd__conditions">
                                        <li v-for="(condition, index) in view.terminal.conditions" :key="index">{{ condition }}</li>
                                    </ul>
                                    <div v-if="view.terminal.detail" class="o-jd__muted">{{ view.terminal.detail }}</div>
                                </div>
                            </div>

                            <div v-if="layout === 'phone'" class="o-jd__muted">{{ factLine }}</div>

                            <div v-else class="o-jd__rule-grid">
                                <div class="o-jd__rule">
                                    <div class="o-jd__caps">{{ view.ruleHeading }}</div>
                                    <div class="o-jd__text">{{ view.rule.headline }}</div>
                                    <div v-if="view.rule.stops" class="o-jd__muted">{{ view.rule.stops }}</div>
                                </div>
                                <dl class="o-jd__effective">
                                    <div class="o-jd__caps">Effective values</div>
                                    <div v-for="row in view.effective" :key="row.label" class="o-jd__effective-row">
                                        <dt>{{ row.label }}</dt>
                                        <dd :class="{'o-jd__muted': row.value === 'unknown' || row.value === 'not set'}">{{ row.value }}</dd>
                                    </div>
                                </dl>
                            </div>

                            <template v-if="layout !== 'phone'">
                                <div class="o-jd__counters-box">
                                    <dl class="o-jd__counters" data-counters
                                        :style="{'--jd-counters': view.counters.length, '--jd-counters-half': Math.ceil(view.counters.length / 2)}">
                                        <div v-for="counter in view.counters" :key="counter.key" class="o-jd__counter">
                                            <dt>{{ counter.label }}</dt>
                                            <dd>{{ counter.value }}</dd>
                                        </div>
                                    </dl>
                                </div>
                                <div class="o-jd__muted o-jd__caption">{{ view.countersNote }}</div>
                            </template>

                            <div class="o-jd__earlier">
                                <div class="o-jd__caps">Earlier exceptions in this cycle</div>
                                <div v-if="earlier.status === 'loading'" class="o-jd__muted">Loading…</div>
                                <div v-else-if="earlier.status === 'error'" class="o-jd__muted">The history of this cycle could not be read.</div>
                                <div v-else-if="earlier.status === 'none'" class="o-jd__muted">None observed in the newest 50 history entries of this cycle.</div>
                                <div v-if="earlier.stale" class="o-jd__muted">Stale: the last read of this cycle's history failed.</div>
                                <ul v-if="earlier.status === 'list'" class="o-jd__earlier-list">
                                    <li v-for="item in earlier.entries" :key="item.id">
                                        <span class="o-jd__muted">{{ entryTime(item) }} · attempt {{ item.attempt ?? '—' }} ·</span> {{ entryText(item) }}
                                    </li>
                                </ul>
                            </div>
                        </template>
                    </section>

                    <!-- History -->
                    <section class="o-jd__section" aria-label="History">
                        <div class="o-jd__section-head">
                            <button v-if="layout !== 'wide'" type="button" class="o-jd__fold" :aria-expanded="sectionOpen('history') ? 'true' : 'false'" @click="toggleSection('history')">
                                <h3 class="o-jd__section-title">History</h3><span class="o-jd__fold-chevron" :class="{'o-jd__fold-chevron--open': sectionOpen('history')}">›</span>
                            </button>
                            <h3 v-else class="o-jd__section-title">History</h3>
                            <span class="o-jd__section-sub">newest first · 50 per page</span>
                            <span class="o-jd__spacer"></span>
                            <label v-if="sectionOpen('history')" class="o-jd__select">
                                <span class="o-jd__sr">Cycle</span>
                                <select :value="history.cycle === null ? '' : String(history.cycle)" @change="setHistoryCycle">
                                    <option v-for="option in historyOptions" :key="option.label" :value="option.value === null ? '' : String(option.value)">{{ option.label }}</option>
                                </select>
                            </label>
                        </div>

                        <template v-if="sectionOpen('history')">
                            <div v-for="(note, index) in historyNotes" :key="index" class="o-jd__muted">{{ note }}</div>
                            <div v-if="history.error && !historyPage" class="o-jd__muted">The history could not be read.</div>
                            <div v-else-if="!historyPage" class="o-jd__muted">Loading…</div>
                            <div v-else class="o-jd__card o-jd__history" :class="{'o-jd__history--loading': history.loading}">
                                <div v-if="history.error" class="o-jd__history-row o-jd__muted">Stale: the last history read failed.</div>
                                <!-- An opened entry that History pruned meanwhile: its row left the page, so its answer shows here. -->
                                <div v-if="entry && entry.error && !historyPage.entries.some(item => item.id === entry.id)" class="o-jd__history-row o-jd__muted" role="status">
                                    {{ entry.error.kind === 'not_found' ? 'The history entry that you opened is no longer kept.' : entry.error.detail }}
                                </div>
                                <div v-if="!historyPage.entries.length" class="o-jd__history-row o-jd__muted">No history entries{{ history.cycle !== null ? ' in this cycle' : '' }}.</div>
                                <template v-for="item in historyPage.entries" :key="item.id">
                                    <div class="o-jd__history-row" :class="{'o-jd__history-row--open': entry && entry.id === item.id}">
                                        <span class="o-jd__history-time" :title="entryTime(item)">{{ entryClock(item) }}</span>
                                        <span class="o-jd__history-event" :class="`o-jd__tone--${eventLabel(item).tone}`">{{ eventLabel(item).label }}</span>
                                        <span class="o-jd__history-attempt">{{ item.attempt === null ? `cycle ${item.cycle}` : `cycle ${item.cycle} · attempt ${item.attempt}` }}</span>
                                        <span class="o-jd__history-text" :title="entryText(item)">{{ entryText(item) }}</span>
                                        <button v-if="item.has_exception" type="button" class="o-jd__history-open" :aria-expanded="entry && entry.id === item.id ? 'true' : 'false'" @click="openEntry(item, $event)">
                                            {{ entry && entry.id === item.id ? 'Close' : 'Open' }}
                                        </button>
                                    </div>
                                    <div v-if="entry && entry.id === item.id" class="o-jd__entry" tabindex="-1" @keydown.esc.stop="closeEntry">
                                        <div v-if="entry.loading" class="o-jd__muted">Loading…</div>
                                        <div v-else-if="entry.error" class="o-jd__muted">{{ entry.error.kind === 'not_found' ? 'This entry is no longer kept.' : entry.error.detail }}</div>
                                        <template v-else-if="entry.data">
                                            <div class="o-jd__caps">Exception</div>
                                            <pre v-if="entryException.kind === 'text'" class="o-jd__pre"><template v-for="(line, index) in entryException.lines" :key="index"><span :class="{'o-jd__frame--app': line.app}">{{ line.text }}</span>{{ '\n' }}</template></pre>
                                            <div v-else class="o-jd__marker">{{ entryException.text }}</div>
                                            <div class="o-jd__caps">Context</div>
                                            <div v-if="entry.data.context === null" class="o-jd__muted">No context recorded.</div>
                                            <div v-else class="o-jd__tree-box"><payload-tree :value="entry.data.context" /></div>
                                        </template>
                                    </div>
                                </template>
                                <div class="o-jd__history-row o-jd__history-pager">
                                    <span class="o-jd__muted">Page {{ history.cursors.length + 1 }}</span>
                                    <span class="o-jd__spacer"></span>
                                    <button v-if="history.cursors.length > 1" type="button" class="o-jd__link-button" @click="newestHistory">Newest</button>
                                    <button v-if="history.cursors.length > 0" type="button" class="o-jd__link-button" @click="newerHistory">‹ Newer entries</button>
                                    <button v-if="historyPage.next_cursor" type="button" class="o-jd__link-button" @click="olderHistory">Older entries ›</button>
                                </div>
                            </div>
                        </template>
                    </section>

                    <!-- Data: command, envelope, context -->
                    <section class="o-jd__section" aria-label="Data">
                        <div class="o-jd__section-head o-jd__section-head--wrap">
                            <button v-if="layout !== 'wide'" type="button" class="o-jd__fold" :aria-expanded="sectionOpen('data') ? 'true' : 'false'" @click="toggleSection('data')">
                                <h3 class="o-jd__section-title">Data</h3><span class="o-jd__fold-chevron" :class="{'o-jd__fold-chevron--open': sectionOpen('data')}">›</span>
                            </button>
                            <h3 v-else class="o-jd__section-title">Data</h3>
                            <div v-if="sectionOpen('data')" class="o-jd__segmented" role="tablist" aria-label="Data">
                                <button v-for="tab in [['command', 'Command'], ['envelope', 'Envelope'], ['context', 'Context']]" :key="tab[0]" type="button" role="tab"
                                        class="o-jd__segment" :class="{'o-jd__segment--on': dataTab === tab[0]}" :aria-selected="dataTab === tab[0] ? 'true' : 'false'" @click="dataTab = tab[0]">{{ tab[1] }}</button>
                            </div>
                            <span class="o-jd__data-note">Built on the server from the stored payload. Dashed boxes are values that are not shown.</span>
                        </div>
                        <div v-if="sectionOpen('data')" class="o-jd__card o-jd__tree-box">
                            <div v-if="dataTab === 'context' && detail.context === null" class="o-jd__tree-empty o-jd__muted">No exception context recorded.</div>
                            <payload-tree v-else :key="`${detail.id}-${dataTab}`" :value="dataValue" />
                        </div>
                    </section>

                    <!-- Exception -->
                    <section class="o-jd__section" aria-label="Exception">
                        <div class="o-jd__section-head">
                            <button v-if="layout !== 'wide'" type="button" class="o-jd__fold" :aria-expanded="sectionOpen('exception') ? 'true' : 'false'" @click="toggleSection('exception')">
                                <h3 class="o-jd__section-title">Exception</h3><span class="o-jd__fold-chevron" :class="{'o-jd__fold-chevron--open': sectionOpen('exception')}">›</span>
                            </button>
                            <h3 v-else class="o-jd__section-title">Exception</h3>
                            <span v-if="exception.kind === 'text'" class="o-jd__section-sub">full text, redacted · {{ exception.size }}</span>
                            <span class="o-jd__spacer"></span>
                            <button v-if="exception.kind === 'text' && sectionOpen('exception')" type="button" class="o-jd__link-button" @click="copy(detail.exception, 'exception')">{{ copyLabel('exception', 'Copy') }}</button>
                        </div>
                        <template v-if="sectionOpen('exception')">
                            <div v-if="exception.kind === 'text'" class="o-jd__exception">
                                <pre class="o-jd__pre"><template v-for="(line, index) in exception.lines" :key="index"><span :class="{'o-jd__frame--app': line.app}">{{ line.text }}</span>{{ '\n' }}</template></pre>
                                <button v-if="exception.toggle" type="button" class="o-jd__link-button" :aria-expanded="exceptionExpanded ? 'true' : 'false'" @click="exceptionExpanded = !exceptionExpanded">{{ exception.toggle }}</button>
                            </div>
                            <div v-else-if="exception.kind === 'redaction_failed'" class="o-jd__chip-failed">{{ exception.text }}</div>
                            <div v-else-if="exception.kind === 'marker'" class="o-jd__marker">{{ exception.text }}</div>
                            <div v-else class="o-jd__muted">{{ exception.text }}</div>
                        </template>
                    </section>
                </template>

                <!-- The inspector folded into the column below 1024 -->
                <template v-if="detail && layout !== 'wide'">
                    <section class="o-jd__section" aria-label="Retries">
                        <div class="o-jd__section-head"><h3 class="o-jd__section-title">Retries</h3><span v-if="retries.retained" class="o-jd__section-sub">{{ retries.retained }}</span></div>
                        <div v-if="retries.unknown" class="o-jd__muted">{{ retries.text }}</div>
                        <template v-else>
                            <div v-for="row in retries.rows" :key="row.key" class="o-jd__retry-row">
                                <span class="o-jd__retry-label">{{ row.label }}</span>
                                <span class="o-jd__retry-source">{{ row.when ? `${row.when} · ` : '' }}{{ row.source }}</span>
                                <span v-if="row.outcome" class="o-jd__retry-outcome" :class="`o-jd__tone--${row.outcome.tone}`">{{ row.outcome.label }}</span>
                            </div>
                            <div v-for="(note, index) in retries.notes" :key="index" class="o-jd__muted o-jd__caption">{{ note }}</div>
                        </template>
                    </section>
                    <section class="o-jd__section" aria-label="Tags and links">
                        <h3 class="o-jd__section-title">Tags and links</h3>
                        <div v-if="links.tags === null" class="o-jd__muted">Tags unknown: they could not be read.</div>
                        <div v-else-if="!links.tags.length" class="o-jd__muted">No tags.</div>
                        <div v-else class="o-jd__tags">
                            <router-link v-for="tag in links.tags" :key="tag.label" class="o-jd__tag" :to="tag.to" :title="tag.label">{{ tag.label }}</router-link>
                        </div>
                        <div v-if="detail.tags_truncated" class="o-jd__muted o-jd__caption">More tags were not kept (at most 50 per job).</div>
                        <dl class="o-jd__links">
                            <div v-if="links.batch" class="o-jd__link-row"><dt>Batch</dt><dd><router-link class="o-jd__link" :to="links.batch.to" :title="links.batch.label">{{ links.batch.label }}</router-link></dd></div>
                            <div class="o-jd__link-row"><dt>Queue</dt><dd><router-link class="o-jd__link" :to="links.queue.to" :title="links.queue.label">{{ links.queue.label }}</router-link></dd></div>
                            <div class="o-jd__link-row"><dt>Class</dt><dd><router-link class="o-jd__link" :to="links.klass.to" :title="detail.name">{{ links.klass.label }}</router-link></dd></div>
                        </dl>
                    </section>
                </template>
            </div>

            <!-- Phone: Retry and Forget in a bottom bar -->
            <div v-if="detail && layout === 'phone' && (actions.retry || actions.forget || actions.delete)" class="o-jd__bottom-bar">
                <o-button v-if="actions.retry" operate variant="primary" size="lg" block :disabled="busy" @click="openSheet('retry')">Retry…</o-button>
                <o-button v-if="actions.forget" operate variant="danger" size="lg" block :disabled="busy" @click="openSheet('forget')">Forget…</o-button>
                <o-button v-if="actions.runNow" operate variant="primary" size="lg" block :disabled="busy || !!actions.deleteBlocked" data-run-now @click="openSheet('run_now')">Run now…</o-button>
                <o-button v-if="actions.delete" operate variant="danger" size="lg" block :disabled="busy || !!actions.deleteBlocked" data-delete @click="openSheet('delete')">Delete…</o-button>
            </div>
            <div v-if="detail && actions.deleteBlocked" class="o-jd__sheet-footnote" data-delete-blocked>{{ actions.deleteBlocked }}</div>
        </div>

        <!-- The inspector at 1024 and wider -->
        <aside v-if="detail && layout === 'wide'" class="o-jd__inspector" aria-label="Inspector">
            <section class="o-jd__inspector-section" aria-label="Operation">
                <div class="o-jd__caps">Operation</div>
                <template v-if="operation.kind === 'none'">
                    <div class="o-jd__text">{{ operation.text }}<template v-if="actions.retry || actions.forget"> Retry and Forget act on cycle {{ detail.cycle }} only; a newer failure is a new occurrence.</template></div>
                </template>
                <template v-else>
                    <div class="o-jd__op-head">
                        <span class="o-jd__op-label" :class="`o-jd__op-label--${operation.tone}`">{{ operation.label }}</span>
                        <span v-if="operation.id" class="o-jd__op-id" :title="operation.id">{{ operation.id }}</span>
                    </div>
                    <div class="o-jd__text">{{ operation.text }}</div>
                    <dl class="o-jd__op-facts">
                        <div v-for="fact in operation.facts" :key="fact.label" class="o-jd__op-fact"><dt>{{ fact.label }}</dt><dd>{{ fact.value }}</dd></div>
                    </dl>
                    <div v-if="operation.bulkRef" class="o-jd__muted o-jd__caption">Part of a bulk run ({{ operation.bulkRef }}).</div>
                    <div class="o-jd__op-actions">
                        <o-button v-if="actions.resolve" operate variant="primary" size="lg" :disabled="busy" @click="openSheet('resolve')">Resolve…</o-button>
                        <span class="o-jd__muted o-jd__caption">Retry and Forget are hidden while an operation holds this job.</span>
                    </div>
                </template>
            </section>

            <section class="o-jd__inspector-section" aria-label="Retries">
                <div class="o-jd__inspector-head"><span class="o-jd__caps">Retries</span><span v-if="retries.retained" class="o-jd__muted o-jd__caption">{{ retries.retained }}</span></div>
                <div v-if="retries.unknown" class="o-jd__muted">{{ retries.text }}</div>
                <template v-else>
                    <div v-for="row in retries.rows" :key="row.key" class="o-jd__retry-row">
                        <span class="o-jd__retry-label">{{ row.label }}</span>
                        <span class="o-jd__retry-source" :title="row.source">{{ row.when ? `${row.when} · ` : '' }}{{ row.source }}</span>
                        <span v-if="row.outcome" class="o-jd__retry-outcome" :class="`o-jd__tone--${row.outcome.tone}`">{{ row.outcome.label }}</span>
                    </div>
                    <div v-for="(note, index) in retries.notes" :key="index" class="o-jd__muted o-jd__caption">{{ note }}</div>
                </template>
            </section>

            <section class="o-jd__inspector-section" aria-label="Tags">
                <div class="o-jd__caps">Tags</div>
                <div v-if="links.tags === null" class="o-jd__muted">Tags unknown: they could not be read.</div>
                <div v-else-if="!links.tags.length" class="o-jd__muted">No tags.</div>
                <div v-else class="o-jd__tags">
                    <router-link v-for="tag in links.tags" :key="tag.label" class="o-jd__tag" :to="tag.to" :title="tag.label">{{ tag.label }}</router-link>
                </div>
                <div v-if="detail.tags_truncated" class="o-jd__muted o-jd__caption">More tags were not kept (at most 50 per job).</div>
            </section>

            <dl class="o-jd__inspector-section o-jd__links" aria-label="Links">
                <div v-if="links.batch" class="o-jd__link-row"><dt>Batch</dt><dd><router-link class="o-jd__link" :to="links.batch.to" :title="links.batch.label">{{ links.batch.label }}</router-link></dd></div>
                <div class="o-jd__link-row"><dt>Queue</dt><dd><router-link class="o-jd__link" :to="links.queue.to" :title="links.queue.label">{{ links.queue.label }}</router-link></dd></div>
                <div class="o-jd__link-row"><dt>Class</dt><dd><router-link class="o-jd__link" :to="links.klass.to" :title="detail.name">{{ links.klass.label }}</router-link></dd></div>
            </dl>
        </aside>

        <confirm-sheet :open="sheet.open" :title="sheet.content ? sheet.content.title : ''" :message="sheet.content ? sheet.content.message : null"
                       :confirm-label="sheet.content ? sheet.content.confirmLabel : 'Confirm'" :tone="sheet.content ? sheet.content.tone : 'primary'"
                       :selection="sheet.content ? sheet.content.selection : null" :snapshot-key="sheet.key" :busy="busy"
                       @confirm="confirm" @cancel="closeSheet">
            <template #default="{selection}">
                <div v-if="selection && selection.note" class="o-jd__sheet-note">
                    <svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true" focusable="false" class="o-jd__sheet-note-icon"><path d="M10 2.5l8 14H2z" fill="currentColor" /><path d="M10 8v4" fill="none" stroke="var(--color-bg)" stroke-width="1.8" stroke-linecap="round" /><circle cx="10" cy="14.2" r="1" fill="var(--color-bg)" /></svg>
                    <span>{{ selection.note }}</span>
                </div>
                <div v-if="selection && selection.batchLink" class="o-jd__sheet-footnote" data-sheet-batch>
                    <router-link class="o-jd__link" :to="selection.batchLink" @click="closeSheet">Cancel the batch instead…</router-link>
                </div>
                <div v-if="selection && selection.footnote" class="o-jd__sheet-footnote">{{ selection.footnote }}</div>
            </template>
        </confirm-sheet>
    </div>
</template>

<style scoped>
    .o-jd {
        color: var(--color-text);
        display: flex;
        flex: 1 1 0%;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        line-height: 18px;
        min-height: 0;
        min-width: 0;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }

    .o-jd__pane {
        box-sizing: border-box;
        display: flex;
        flex: 1 1 0%;
        flex-direction: column;
        min-width: 0;
        overflow-y: auto;
        padding: 22px 24px 24px;
    }

    .o-jd--phone .o-jd__pane {
        padding: 16px 16px 0;
    }

    .o-jd__column {
        display: flex;
        flex-direction: column;
        gap: 28px;
        max-width: 1200px;
        min-width: 0;
        width: 100%;
    }

    .o-jd--phone .o-jd__column {
        gap: 20px;
        padding-bottom: 16px;
    }

    .o-jd__spacer { flex: 1 1 0%; }

    /* The element defaults of resources/css/base.css give these elements margins and a weight. */
    .o-jd dl, .o-jd dd, .o-jd ul, .o-jd p, .o-jd pre { margin-bottom: 0; }
    .o-jd dt { font-weight: var(--font-weight-regular); }

    .o-jd__sr {
        clip: rect(0 0 0 0);
        clip-path: inset(50%);
        height: 1px;
        overflow: hidden;
        position: absolute;
        white-space: nowrap;
        width: 1px;
    }

    .o-jd__muted { color: var(--color-text-secondary); }
    .o-jd__strong { font-weight: var(--font-weight-semibold); }
    .o-jd__body { font-size: var(--text-body); line-height: 20px; }
    .o-jd__caption { font-size: var(--text-caption); line-height: 16px; }
    .o-jd__text { overflow-wrap: anywhere; }
    .o-jd__mono { font-family: var(--font-mono), ui-monospace, monospace; font-size: var(--text-caption); }

    .o-jd__caps {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        letter-spacing: var(--tracking-caps);
        line-height: 16px;
        text-transform: uppercase;
    }

    .o-jd__link,
    .o-jd__back {
        color: var(--color-accent);
        overflow-wrap: anywhere;
        text-decoration: none;
    }

    .o-jd__link:hover { text-decoration: underline; }

    .o-jd__back {
        align-items: center;
        display: inline-flex;
        flex-shrink: 0;
        gap: 6px;
        height: 28px;
    }

    .o-jd__link:focus-visible,
    .o-jd__back:focus-visible,
    .o-jd__link-button:focus-visible,
    .o-jd__copy:focus-visible,
    .o-jd__icon-button:focus-visible,
    .o-jd__segment:focus-visible,
    .o-jd__fold:focus-visible,
    .o-jd__history-open:focus-visible,
    .o-jd__tag:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: 1px;
    }

    .o-jd__link-button,
    .o-jd__history-open {
        background: none;
        border: none;
        color: var(--color-accent);
        cursor: pointer;
        flex-shrink: 0;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        font-weight: var(--font-weight-medium);
        line-height: 18px;
        padding: 0;
    }

    .o-jd__icon-button,
    .o-jd__copy {
        align-items: center;
        background: none;
        border: none;
        border-radius: var(--radius-control);
        color: var(--color-accent);
        cursor: pointer;
        display: inline-flex;
        flex-shrink: 0;
        height: 24px;
        justify-content: center;
        padding: 0;
        width: 24px;
    }

    .o-jd__copy { margin-left: 4px; vertical-align: middle; }
    .o-jd__copied { color: var(--color-text-secondary); font-size: var(--text-caption); margin-left: 4px; }

    /* Loading and whole-panel states */
    .o-jd__loading { display: flex; flex-direction: column; gap: 12px; }

    .o-jd__bar {
        background-color: var(--color-subtle);
        border-radius: 6px;
        height: 16px;
        width: 100%;
    }

    .o-jd__bar--title { height: 28px; width: 50%; }
    .o-jd__bar--short { width: 30%; }

    .o-jd__gone {
        align-items: center;
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-card);
        display: flex;
        flex-direction: column;
        gap: 4px;
        padding: 16px;
        text-align: center;
    }

    .o-jd__gone-title { font-size: var(--text-body); font-weight: var(--font-weight-semibold); line-height: 20px; }
    .o-jd__gone-text { color: var(--color-text-secondary); overflow-wrap: anywhere; }

    .o-jd__redaction {
        background-color: var(--color-danger-tint);
        border: 1px solid color-mix(in srgb, var(--color-danger) 25%, transparent);
        border-radius: var(--radius-card);
        display: flex;
        flex-direction: column;
        gap: 4px;
        padding: 12px 14px;
    }

    .o-jd__redaction-title { font-weight: var(--font-weight-semibold); }
    .o-jd__redaction-text { color: var(--color-text-secondary); font-size: var(--text-caption); line-height: 16px; overflow-wrap: anywhere; }

    /* Header */
    .o-jd__header { display: flex; flex-direction: column; gap: 12px; }

    .o-jd__headline {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        min-width: 0;
    }

    .o-jd__chip,
    .o-jd__liveness {
        align-items: center;
        border-radius: 10px;
        display: inline-flex;
        flex-shrink: 0;
        font-weight: var(--font-weight-semibold);
        gap: 5px;
        height: 22px;
        padding-inline: 9px;
    }

    .o-jd__chip-dot {
        background-color: currentColor;
        border-radius: 50%;
        flex-shrink: 0;
        height: 7px;
        width: 7px;
    }

    .o-jd__chip--danger { background-color: var(--color-danger-tint); color: var(--color-danger-text); }
    .o-jd__chip--warning { background-color: var(--color-warning-tint); color: var(--color-warning-text); }
    .o-jd__chip--success { background-color: var(--color-success-tint); color: var(--color-success-text); }
    .o-jd__chip--accent { background-color: var(--color-accent-tint); color: var(--color-accent); }
    .o-jd__chip--neutral { background-color: var(--color-sidebar); color: var(--color-text-secondary); }

    .o-jd__title {
        font-size: var(--text-title);
        font-weight: var(--font-weight-semibold);
        letter-spacing: var(--tracking-tight);
        line-height: 28px;
        margin: 0;
        min-width: 0;
        overflow-wrap: anywhere;
    }

    .o-jd__context { color: var(--color-text-secondary); font-size: var(--text-body); line-height: 20px; }

    .o-jd__identity {
        color: var(--color-text-secondary);
        font-family: var(--font-mono), ui-monospace, monospace;
        font-size: var(--text-caption);
        line-height: 16px;
        overflow-wrap: anywhere;
    }

    .o-jd__message {
        background-color: var(--color-danger-tint);
        border: 1px solid color-mix(in srgb, var(--color-danger) 20%, transparent);
        border-radius: 8px;
        font-size: var(--text-body);
        line-height: 20px;
        overflow-wrap: anywhere;
        padding: 10px 12px;
        white-space: pre-wrap;
    }

    .o-jd__facts {
        border-bottom: 1px solid var(--color-separator);
        border-top: 1px solid var(--color-separator);
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }

    .o-jd__fact-column { margin: 0; min-width: 0; }
    .o-jd__fact-column + .o-jd__fact-column { border-left: 1px solid var(--color-separator); padding-left: 16px; }

    .o-jd--phone .o-jd__facts { grid-template-columns: minmax(0, 1fr); }
    .o-jd--phone .o-jd__fact-column + .o-jd__fact-column { border-left: none; border-top: 1px solid var(--color-separator); padding-left: 0; }

    .o-jd__fact {
        align-items: center;
        border-bottom: 1px solid var(--color-separator);
        display: flex;
        min-height: 30px;
        padding-block: 6px;
    }

    .o-jd__fact:last-child { border-bottom: none; }

    .o-jd__fact dt {
        color: var(--color-text-secondary);
        flex-shrink: 0;
        font-size: var(--text-caption);
        line-height: 16px;
        width: 96px;
    }

    .o-jd__fact dd { margin: 0; min-width: 0; overflow-wrap: anywhere; }
    .o-jd__fact-note { color: var(--color-text-secondary); display: block; font-size: var(--text-caption); line-height: 16px; }

    .o-jd__read-only { color: var(--color-text-secondary); margin: 0; overflow-wrap: anywhere; }

    /* Operation */
    .o-jd__operation-inline {
        background-color: var(--color-subtle);
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-card);
        display: flex;
        flex-direction: column;
        gap: 10px;
        padding: 14px;
    }

    .o-jd__op-head { align-items: center; display: flex; flex-wrap: wrap; gap: 8px; min-width: 0; }

    .o-jd__op-label {
        border-radius: 4px;
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        line-height: 16px;
        padding: 1px 7px;
    }

    .o-jd__op-label--warning { background-color: var(--color-warning-tint); color: var(--color-warning-text); }
    .o-jd__op-label--accent { background-color: var(--color-accent-tint); color: var(--color-accent); }
    .o-jd__op-label--neutral { background-color: var(--color-sidebar); color: var(--color-text-secondary); }

    .o-jd__op-id {
        color: var(--color-text-secondary);
        font-family: var(--font-mono), ui-monospace, monospace;
        font-size: var(--text-caption);
        min-width: 0;
        overflow-wrap: anywhere;
    }

    .o-jd__op-facts { display: flex; flex-direction: column; gap: 4px; margin: 0; }
    .o-jd__op-fact { display: flex; gap: 12px; font-size: var(--text-caption); justify-content: space-between; line-height: 16px; }
    .o-jd__op-fact dt { color: var(--color-text-secondary); flex-shrink: 0; }
    .o-jd__op-fact dd { margin: 0; min-width: 0; overflow-wrap: anywhere; text-align: right; }
    .o-jd__op-actions { align-items: center; display: flex; flex-wrap: wrap; gap: 8px; }

    /* Active rows */
    .o-jd__active {
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-card);
        display: flex;
        flex-direction: column;
        gap: 12px;
        padding: 12px 14px;
    }

    .o-jd__worker { align-items: center; display: flex; flex-wrap: wrap; gap: 8px; min-width: 0; }
    .o-jd__worker .o-jd__mono { overflow-wrap: anywhere; }
    .o-jd__liveness { font-size: var(--text-caption); height: 20px; padding-inline: 8px; }
    .o-jd__liveness--success { background-color: var(--color-success-tint); color: var(--color-success-text); }
    .o-jd__liveness--warning { background-color: var(--color-warning-tint); color: var(--color-warning-text); }
    .o-jd__liveness--unknown { background-color: var(--color-sidebar); color: var(--color-text-secondary); }
    .o-jd__signals, .o-jd__rule-now { display: flex; flex-direction: column; gap: 6px; }
    .o-jd__signal-list { margin: 0; padding-left: 18px; }
    .o-jd__signal-list li { color: var(--color-warning-text); overflow-wrap: anywhere; }

    /* Sections */
    .o-jd__section { display: flex; flex-direction: column; gap: 12px; min-width: 0; }

    .o-jd__section-head {
        align-items: center;
        display: flex;
        gap: 12px;
        min-width: 0;
    }

    .o-jd__section-head--wrap { flex-wrap: wrap; }

    .o-jd__section-title {
        font-size: var(--text-section);
        font-weight: var(--font-weight-semibold);
        letter-spacing: -0.01em;
        line-height: 22px;
        margin: 0;
    }

    .o-jd__section-sub { color: var(--color-text-secondary); }

    .o-jd__fold {
        align-items: center;
        background: none;
        border: none;
        color: inherit;
        cursor: pointer;
        display: inline-flex;
        font: inherit;
        gap: 8px;
        padding: 0;
    }

    .o-jd__fold-chevron { color: var(--color-text-tertiary); font-size: 18px; transition: transform 0.12s ease; }
    .o-jd__fold-chevron--open { transform: rotate(90deg); }

    .o-jd__data-note {
        color: var(--color-text-secondary);
        flex: 1 1 200px;
        font-size: var(--text-caption);
        line-height: 16px;
        text-align: right;
    }

    .o-jd--phone .o-jd__data-note { text-align: left; }

    /* Up to 52 cycle tabs: the control scrolls inside itself, never the page. */
    .o-jd__segmented {
        align-items: center;
        background-color: var(--color-separator);
        border-radius: 7px;
        display: flex;
        flex-shrink: 1;
        flex-wrap: nowrap;
        gap: 0;
        max-width: 100%;
        min-width: 0;
        overflow-x: auto;
        padding: 2px;
        scrollbar-width: thin;
    }

    .o-jd__segment {
        align-items: center;
        flex-shrink: 0;
        background: none;
        border: none;
        border-radius: 5px;
        color: var(--color-text);
        cursor: pointer;
        display: inline-flex;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        gap: 6px;
        line-height: 18px;
        padding: 3px 11px;
        white-space: nowrap;
    }

    .o-jd__segment--on {
        background-color: var(--color-bg);
        box-shadow: var(--shadow-raised);
        font-weight: var(--font-weight-semibold);
    }

    .o-jd__dot { border-radius: 50%; flex-shrink: 0; height: 6px; width: 6px; }
    .o-jd__dot--danger { background-color: var(--color-danger); }
    .o-jd__dot--success { background-color: var(--color-success); }
    .o-jd__dot--accent { background-color: var(--color-accent); }
    .o-jd__dot--neutral { background-color: var(--color-text-tertiary); }

    .o-jd__card {
        background-color: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-card);
        box-sizing: border-box;
        min-width: 0;
    }

    .o-jd__merged { display: flex; flex-direction: column; gap: 4px; padding: 12px 14px; }

    .o-jd__terminal { align-items: flex-start; display: flex; gap: 10px; padding: 12px 14px; }
    .o-jd__terminal-icon { color: var(--color-text-secondary); flex-shrink: 0; }
    .o-jd__terminal-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; overflow-wrap: anywhere; }
    .o-jd__conditions { margin: 2px 0; padding-left: 18px; }

    .o-jd__rule-grid { display: flex; gap: 24px; }
    .o-jd--narrow .o-jd__rule-grid { flex-direction: column; gap: 16px; }
    .o-jd__rule { display: flex; flex: 1 1 0%; flex-direction: column; gap: 6px; min-width: 0; overflow-wrap: anywhere; }
    .o-jd__effective { display: flex; flex-direction: column; flex-shrink: 0; gap: 6px; margin: 0; width: 300px; }
    .o-jd--narrow .o-jd__effective { width: auto; }
    .o-jd__effective-row { display: flex; gap: 12px; justify-content: space-between; }
    .o-jd__effective-row dt { color: var(--color-text-secondary); }
    .o-jd__effective-row dd { margin: 0; text-align: right; }

    /*
     * The counters in balanced rows: all on one row when the
     * column has room for each (about 120 px), else two rows of half each
     * (4 + 3), never a last row with one card. The column's width decides,
     * not the window's, so the inspector and the 1200 px cap count.
     */
    .o-jd__counters-box { container-type: inline-size; }

    .o-jd__counters {
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-card);
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        margin: 0;
        overflow: hidden;
    }

    @container (min-width: 360px) {
        .o-jd__counters { grid-template-columns: repeat(var(--jd-counters-half, 4), minmax(0, 1fr)); }
    }

    @container (min-width: 840px) {
        .o-jd__counters { grid-template-columns: repeat(var(--jd-counters, 7), minmax(0, 1fr)); }
    }

    /* The last column's right border and the last row's bottom border fall outside the box. */
    .o-jd__counter {
        border-bottom: 1px solid var(--color-separator);
        border-right: 1px solid var(--color-separator);
        display: flex;
        flex-direction: column;
        margin-bottom: -1px;
        margin-right: -1px;
        min-width: 0;
        padding: 10px 14px;
    }

    .o-jd__counter dt { color: var(--color-text-secondary); font-size: var(--text-caption); line-height: 16px; }
    .o-jd__counter dd { font-size: var(--text-section); font-weight: var(--font-weight-semibold); line-height: 24px; margin: 0; }

    .o-jd__earlier { display: flex; flex-direction: column; gap: 6px; }
    .o-jd__earlier-list { margin: 0; padding-left: 18px; }
    .o-jd__earlier-list li { overflow-wrap: anywhere; }

    /* History */
    .o-jd__select select {
        background-color: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-control);
        color: var(--color-text);
        font-family: var(--font-sans);
        font-size: var(--text-table);
        height: 28px;
        max-width: 160px;
        padding-inline: 8px;
    }

    .o-jd__history { display: flex; flex-direction: column; overflow: hidden; }
    .o-jd__history--loading { opacity: 0.6; }

    .o-jd__history-row {
        align-items: center;
        border-bottom: 1px solid var(--color-separator);
        box-sizing: border-box;
        display: flex;
        gap: 12px;
        min-height: 36px;
        padding: 8px 14px;
    }

    .o-jd__history-row:last-child { border-bottom: none; }
    .o-jd__history-row--open { background-color: var(--color-accent-tint); box-shadow: var(--color-accent) 3px 0 0 inset; }
    .o-jd__history-time { color: var(--color-text-secondary); flex-shrink: 0; font-variant-numeric: tabular-nums; width: 72px; }
    .o-jd__history-event { flex-shrink: 0; font-weight: var(--font-weight-semibold); width: 96px; }
    .o-jd__history-attempt { color: var(--color-text-secondary); flex-shrink: 0; width: 128px; }

    .o-jd__history-text {
        flex: 1 1 0%;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-jd--narrow .o-jd__history-row,
    .o-jd--phone .o-jd__history-row { flex-wrap: wrap; row-gap: 2px; }
    .o-jd--phone .o-jd__history-time,
    .o-jd--phone .o-jd__history-attempt,
    .o-jd--phone .o-jd__history-event { width: auto; }
    .o-jd--phone .o-jd__history-text { flex-basis: 100%; white-space: normal; overflow-wrap: anywhere; }

    .o-jd__history-pager { gap: 16px; }

    .o-jd__tone--danger { color: var(--color-danger-text); }
    .o-jd__tone--warning { color: var(--color-warning-text); }
    .o-jd__tone--success { color: var(--color-success-text); }
    .o-jd__tone--accent { color: var(--color-accent); }
    .o-jd__tone--neutral { color: var(--color-text); }

    .o-jd__entry {
        background-color: var(--color-subtle);
        border-bottom: 1px solid var(--color-separator);
        display: flex;
        flex-direction: column;
        gap: 8px;
        outline: none;
        padding: 12px 14px;
    }

    /* Data and exception */
    .o-jd__tree-box { overflow-x: auto; padding-block: 8px; }
    .o-jd__tree-empty { padding: 4px 14px; }

    .o-jd__exception {
        background-color: var(--color-subtle);
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-card);
        display: flex;
        flex-direction: column;
        gap: 4px;
        padding: 12px 14px;
    }

    .o-jd__exception > .o-jd__link-button { align-self: flex-start; }

    .o-jd__pre {
        color: var(--color-text-secondary);
        font-family: var(--font-mono), ui-monospace, monospace;
        font-size: var(--text-caption);
        line-height: 18px;
        margin: 0;
        overflow-wrap: anywhere;
        white-space: pre-wrap;
    }

    .o-jd__pre > span:first-child { color: var(--color-text); }
    .o-jd__frame--app { color: var(--color-text); font-weight: var(--font-weight-semibold); }

    .o-jd__marker {
        align-self: flex-start;
        border: 1px dashed color-mix(in srgb, var(--color-text) 25%, transparent);
        border-radius: 4px;
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        padding-inline: 6px;
    }

    .o-jd__chip-failed {
        align-self: flex-start;
        background-color: var(--color-danger-tint);
        border-radius: 4px;
        color: var(--color-danger-text);
        font-family: var(--font-mono), ui-monospace, monospace;
        font-size: var(--text-caption);
        padding-inline: 6px;
    }

    /* Inspector */
    .o-jd__inspector {
        background-color: var(--color-subtle);
        border-left: 1px solid var(--color-separator);
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
        flex-shrink: 0;
        gap: 18px;
        overflow-y: auto;
        padding: 18px 20px 16px;
        width: var(--container-inspector);
    }

    .o-jd__inspector-section { display: flex; flex-direction: column; gap: 8px; margin: 0; min-width: 0; }
    .o-jd__inspector-section + .o-jd__inspector-section { border-top: 1px solid var(--color-separator); padding-top: 14px; }
    .o-jd__inspector-head { align-items: baseline; display: flex; justify-content: space-between; }

    .o-jd__retry-row {
        align-items: center;
        border-bottom: 1px solid var(--color-separator);
        display: flex;
        gap: 8px;
        min-height: 28px;
    }

    .o-jd__retry-label { flex-shrink: 0; width: 72px; }
    .o-jd__retry-source { color: var(--color-text-secondary); flex: 1 1 0%; font-size: var(--text-caption); line-height: 16px; min-width: 0; overflow-wrap: anywhere; }
    .o-jd__retry-outcome { flex-shrink: 0; font-size: var(--text-caption); font-weight: var(--font-weight-semibold); }

    .o-jd__tags { display: flex; flex-wrap: wrap; gap: 6px; }

    .o-jd__tag {
        background-color: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: 5px;
        color: var(--color-accent);
        font-size: var(--text-caption);
        line-height: 16px;
        max-width: 100%;
        overflow-wrap: anywhere;
        padding: 1px 7px;
        text-decoration: none;
    }

    .o-jd__links { display: flex; flex-direction: column; margin: 0; }
    .o-jd__link-row { align-items: center; border-bottom: 1px solid var(--color-separator); display: flex; min-height: 30px; padding-block: 6px; }
    .o-jd__link-row:last-child { border-bottom: none; }
    .o-jd__link-row dt { color: var(--color-text-secondary); flex-shrink: 0; font-size: var(--text-caption); width: 70px; }
    .o-jd__link-row dd { margin: 0; min-width: 0; }

    /* Phone bottom bar */
    .o-jd__bottom-bar {
        background-color: var(--color-bg);
        border-top: 1px solid var(--color-separator);
        bottom: 0;
        display: flex;
        gap: 10px;
        margin-inline: -16px;
        margin-top: auto;
        padding: 12px 16px;
        position: sticky;
    }

    /* Sheet */
    .o-jd__sheet-note {
        align-items: flex-start;
        background-color: var(--color-warning-tint);
        border: 1px solid color-mix(in srgb, var(--color-warning) 35%, transparent);
        border-radius: 8px;
        display: flex;
        gap: 10px;
        margin-bottom: 8px;
        padding: 10px 12px;
    }

    .o-jd__sheet-note-icon { color: var(--color-warning); flex-shrink: 0; }

    .o-jd__sheet-footnote {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
    }
</style>
