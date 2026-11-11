<script>
    import {ANY_ERROR_STATUS, BACKGROUND_FAILURES} from '../../errors.js';
    import BulkRun from '../../components/ui/BulkRun.vue';
    import Button from '../../components/ui/Button.vue';
    import ConfirmSheet from '../../components/ui/ConfirmSheet.vue';
    import Notice from '../../components/ui/Notice.vue';
    import Sparkline from '../../components/ui/Sparkline.vue';
    import InspectorLayout from '../../shell/InspectorLayout.vue';
    import {live, requestLiveRefresh, revealInspector, setToolbar} from '../../shell/store';
    import {formatCount, formatHourMinuteZoned, formatLocal, nowSeconds, relativeTime, timesInNote} from '../../support/time';
    import {queuePath} from '../../support/redirects.js';
    import {codeText} from '../../support/wording.js';
    import {GROUPS_BULK_STORAGE_KEY, countText, groupQueuesText, groupsSource, markerBefore} from '../failed/failures';
    import {nativeContinueCommands} from '../overview/overview';
    import BacklogChart from './BacklogChart.vue';
    import {BACKLOG_RANGES, BACKLOG_SCALES, backlogMarkersRequest, backlogPollSeconds, backlogRange, backlogScale, backlogSlots, cardTitle, historyRowsOf, rangeLabel, readBacklogChoice, slotSecondsFor, writeBacklogChoice} from './backlog.js';
    import MiniGauge from './MiniGauge.vue';
    import QueueInspector from './QueueInspector.vue';
    import {
        CARD_PAGE,
        FAILURE_ROWS,
        HISTORY_CONCURRENCY,
        HISTORY_DEBOUNCE_MS,
        POLL_SECONDS,
        RECENT_FAILURE_SECONDS,
        clearFailure,
        clearOutcome,
        clearSheet,
        connectionSections,
        controlBodies,
        controlNotice,
        controlConfirmLabel,
        controlMessage,
        controlTitle,
        failureScopes,
        failureWindow,
        failuresFooter,
        findRow,
        groupTitle,
        historyBuckets,
        historyWindow,
        inOutText,
        limitText,
        markerName,
        markersIn,
        mergeFailures,
        normalizeMarkers,
        normalizeWorkload,
        isPaused,
        queueRequest,
        num,
        pauseHint,
        pausedFrom,
        pausedTitle,
        queueState,
        retryLabel,
        runLimited,
        sectionPausedBroadly,
        sectionWorkersText,
        shortName,
        subtitleText,
        waitText,
    } from './queues';

    /**
     * The HTTP statuses that the screen's reads handle themselves: the
     * section shows its own error, and no global alert repeats on every
     * poll. 401 and 419 still ask for a reload.
     */
    const SECTION_STATUSES = [400, 403, 404, 405, 408, 409, 422, 429, 500, 502, 503, 504];

    /**
     * A background read (a poll, or a load that a poll's data started) also
     * handles a timeout and a network failure: the section shows its error
     * and the toolbar says "Stale". A read that the user started (a new
     * selection, "Show more") keeps the global alert for them.
     */
    const BACKGROUND_STATUSES = [...SECTION_STATUSES, ANY_ERROR_STATUS, ...BACKGROUND_FAILURES];

    /** A clear's answer is read inline: these statuses and failures never raise the global alert (a 403 does). */
    const CLEAR_HANDLES = [404, 409, 422, 500, 502, 503, 504, ...BACKGROUND_FAILURES];

    /**
     * The Queues screen.
     *
     * - /queues is the list; /queues/:connection/:queue is the list with that
     *   queue in the inspector. Both routes reuse this one instance: a route
     *   change cancels a preparation in progress, closes an open sheet, and
     *   loads the failures of the new selection with a new request sequence.
     * - The table rows and the workers come from the shell's `live` data
     *   (shell/store.js), and so do the failure groups of
     *   all queues (`groupsHour`): the screen does not fetch them again. The
     *   markers and the failures of a selected queue poll every 10 s. The
     *   backlog cards poll every 30 s (one history request per queue; less
     *   often for the longer ranges, backlog.js backlogPollSeconds()). Their
     *   range (15 min to 24 h) and their scale (shared, or per queue) apply
     *   to every card and are remembered per browser.
     * - Pause, Continue and Terminate open a ConfirmSheet with a FROZEN
     *   selection (the queue or the connection, and its aliases); a poll never
     *   changes it. They send one `POST /api/control` per alias.
     * - A group Retry starts a bulk run with a groups
     *   source: the group's queue scope, the live past hour and its key
     *   (groupsSource()), a snapshot of the click. The shared BulkRun freezes
     *   it, shows the frozen total and runs it. A route change of the
     *   selection closes an open bulk sheet (host rule 1); `started` closes
     *   `open`; BulkRun stays mounted while a run is shown, and leaving the
     *   screen during a run asks first. Its run is stored under the shared
     *   groups key, so after a reload the Failure groups screen recovers it.
     * - The inspector reads "Kept for" from `GET /api/settings/retention`,
     *   once per visit, and links to the queue's pending and failed
     *   jobs in the Jobs list. In the narrow layout a selected queue opens
     *   its inspector over the list (store.js revealInspector()).
     */
    export default {
        BULK_STORAGE_KEY: GROUPS_BULK_STORAGE_KEY,

        components: {
            BacklogChart,
            BulkRun,
            ConfirmSheet,
            InspectorLayout,
            MiniGauge,
            Notice,
            QueueInspector,
            Sparkline,
            'o-button': Button,
        },

        props: {
            connection: {type: String, default: null},
            queue: {type: String, default: null},
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
                backlog: readBacklogChoice(),
                markers: [],
                histories: {},
                historyKeys: null,
                cardLimit: CARD_PAGE,
                failures: null,
                failuresKey: null,
                failuresWindow: null,
                failuresLoading: true,
                failuresError: null,
                operation: null,
                sheetOpen: false,
                snapshotKey: 0,
                busy: false,
                controlResult: null,
                notice: null,
                bulk: {open: false, source: null, key: 0, runShown: false, running: false},
                leave: {open: false, to: null, confirmed: false, key: 0},
                retention: null,
                retentionError: false,
            };
        },

        computed: {
            leaveText() {
                return codeText('bulk_run_in_progress');
            },

            /** /api/health `queue_pause_cache_local`: a queue pause may not reach every worker, or null. */
            cacheNote() {
                const note = this.live.health ? this.live.health.queue_pause_cache_local : null;

                return typeof note === 'string' && note !== '' ? note : null;
            },

            /** The shell's workload rows, normalized; null before they loaded. */
            rows() {
                return Array.isArray(this.live.workload) ? normalizeWorkload(this.live.workload) : null;
            },

            /** The shell's last workload read failed: the table shows the last data it read. */
            rowsError() {
                return this.live.workloadError ? 'The last read failed' : null;
            },

            workers() {
                return Array.isArray(this.live.workers) ? this.live.workers : null;
            },

            workersError() {
                return this.live.workersError;
            },

            /** The keys of the rows, in order: a change loads the new backlog cards. */
            rowKeys() {
                return this.rows ? this.rows.map(row => row.key).join('\n') : null;
            },

            /** The failure groups of all queues in the past hour come from the shell. */
            liveFailures() {
                return !this.selectedRow;
            },

            routeSelected() {
                return !!(this.connection && this.queue);
            },

            selectedRow() {
                return this.rows ? findRow(this.rows, this.connection, this.queue) : null;
            },

            notFound() {
                return this.routeSelected && this.rows !== null && !this.selectedRow;
            },

            /** The key of the selection that the failures belong to. */
            selectionKey() {
                if (this.selectedRow) return this.selectedRow.key;

                return this.routeSelected ? `missing:${this.connection}:${this.queue}` : '*';
            },

            sections() {
                return this.rows ? connectionSections(this.rows) : [];
            },

            subtitle() {
                return this.rows ? subtitleText(this.rows) : null;
            },

            /** The backlog cards on screen: the first CARD_PAGE, more on "Show more". */
            cardRows() {
                return this.rows ? this.rows.slice(0, this.cardLimit) : [];
            },

            /** The queues whose history is read: the cards on screen, and the selected queue. */
            historyRows() {
                return historyRowsOf(this.rows || [], this.cardLimit, this.selectedRow);
            },

            backlogRanges() {
                return BACKLOG_RANGES;
            },

            backlogScales() {
                return BACKLOG_SCALES;
            },

            /** The history minutes of the chosen range. */
            historyMinutes() {
                return backlogRange(this.backlog.range).minutes;
            },

            historyPollSeconds() {
                return backlogPollSeconds(this.backlog.range);
            },

            rangeLabel() {
                return rangeLabel(this.backlog.range);
            },

            /** The slots of the cards on screen, by key: the y scale reads them (backlog.js backlogScale()). */
            cardSlots() {
                const slots = {};
                const seconds = slotSecondsFor(this.historyMinutes);

                this.cardRows.forEach(row => {
                    const history = this.histories[row.key];

                    if (history && history.window) slots[row.key] = backlogSlots(history.window, history.buckets, seconds);
                });

                return slots;
            },

            backlogSubtitle() {
                const parts = [`Ready jobs, ${this.rangeLabel}`, this.backlog.scale === 'queue' ? 'own scale per queue' : 'one scale for all queues', timesInNote(this.now)];
                const window = Object.values(this.histories).map(history => history.window).find(Boolean) || null;
                const inWindow = markersIn(this.markers, window);

                if (inWindow.length === 1) {
                    parts.push(`dashed line = ${markerName(inWindow[0])} ${formatHourMinuteZoned(inWindow[0].at)}`);
                } else if (inWindow.length > 1) {
                    parts.push(`dashed lines = ${inWindow.length} restarts and deploys`);
                }

                return parts.join(' · ');
            },

            failuresLabel() {
                return this.selectedRow ? this.selectedRow.name : 'all queues';
            },

            failureGroups() {
                return this.failures && this.failuresKey === this.selectionKey ? this.failures.groups : [];
            },

            shownGroups() {
                return this.failureGroups.slice(0, FAILURE_ROWS);
            },

            hiddenGroups() {
                return Math.max(0, this.failureGroups.length - FAILURE_ROWS);
            },

            failuresIncomplete() {
                return !!(this.failures && this.failures.incomplete);
            },

            footer() {
                if (!this.failures || this.failuresKey !== this.selectionKey) return null;

                // After a failed read the answer is unknown: no "No failures" line.
                if (this.failuresError) return null;

                return failuresFooter(this.failureGroups, this.failuresIncomplete, this.failuresLabel, this.failuresWindow, this.failures.retentionFrom);
            },

            selectedHistory() {
                return this.selectedRow ? (this.histories[this.selectedRow.key] || null) : null;
            },
        },

        watch: {
            subtitle: {
                immediate: true,
                handler(subtitle) {
                    setToolbar({title: 'Queues', subtitle});
                },
            },

            connection() {
                this.selectionChanged();
            },

            queue() {
                this.selectionChanged();
            },

            /** A change of the queue set: read the cards that have no history yet. */
            rowKeys(keys) {
                if (keys === null || keys === this.historyKeys) return;

                const first = this.historyKeys === null;

                this.historyKeys = keys;
                this.scheduleHistory(first);

                // The first rows: the selected queue is known now, so its failures can load.
                if (first) this.loadFailures(true);
            },

            'live.groupsHour'() {
                if (this.liveFailures) this.loadFailures(true);
            },

            'live.groupsHourError'() {
                if (this.liveFailures) this.loadFailures(true);
            },
        },

        mounted() {
            this.clock = setInterval(() => {
                this.now = nowSeconds();
            }, 1000);

            // An in-app navigation mounts the screen with the shell's rows already loaded: the
            // rowKeys watcher does not fire for them, so the cards load now. The first poll
            // (refresh) loads the failures, so they are not loaded twice.
            if (this.rowKeys !== null) {
                this.historyKeys = this.rowKeys;
                this.scheduleHistory(true);
            }

            this.loadRetention();

            // A queue named by the route (a sidebar link) opens its inspector, also over the list when narrow.
            if (this.routeSelected) revealInspector();
        },

        beforeUnmount() {
            // Responses that arrive later change nothing, and start nothing.
            this.unmounted = true;
            clearInterval(this.clock);
            clearTimeout(this.historyTimer);
            this.startRequest('screen');
            this.startRequest('history');
            this.startRequest('failures');
            this.startRequest('operation');
        },

        methods: {
            countText,
            nativeContinueCommands,
            pauseHint,
            pausedFrom,
            pausedTitle,
            formatCount,
            formatHourMinuteZoned,
            formatLocal,
            relativeTime,
            shortName,
            limitText,
            waitText,
            inOutText,
            retryLabel,
            groupTitle,
            markerName,
            queueState,
            sectionPausedBroadly,
            sectionWorkersText,

            /** A read; `background` for a poll (BACKGROUND_STATUSES), else a user-started read (SECTION_STATUSES). */
            get(path, params = {}, background = false) {
                return this.$http.get(Orrery.basePath + path, {params, handles: background ? BACKGROUND_STATUSES : SECTION_STATUSES})
                    .then(response => response.data);
            },

            errorText(error) {
                const status = error && error.response ? error.response.status : null;

                return status ? `HTTP ${status}` : 'no response';
            },

            /**
             * One poll of the markers and the failures of the current selection
             * (the shell polls the rows and the workers). Each response is
             * applied only while its poll is the newest (startRequest), so a
             * late response never overwrites newer data. The open operation is
             * never touched.
             */
            refresh() {
                const sequence = this.startRequest('screen');
                const markers = backlogMarkersRequest(nowSeconds(), this.backlog.range);
                const current = () => this.isCurrentRequest('screen', sequence);

                return Promise.allSettled([
                    this.rows !== null ? this.loadFailures(true) : null,
                    this.get(markers.url, markers.params, true).then(data => {
                        if (current()) this.markers = normalizeMarkers(data);
                    }).catch(() => {}),
                ]);
            },

            /**
             * A change of the queue set: read the cards that have no history
             * yet, after HISTORY_DEBOUNCE_MS (at once on the first workload).
             * A queue that drops out for one poll keeps its history, so its
             * return reads nothing; the 30 s poll refreshes every card.
             */
            scheduleHistory(now = false) {
                clearTimeout(this.historyTimer);

                if (now) {
                    this.loadHistory(true, true);

                    return;
                }

                this.historyTimer = setTimeout(() => this.loadHistory(true, true), HISTORY_DEBOUNCE_MS);
            },

            /**
             * The backlog of the cards on screen (and the selected queue): one
             * history request per queue on its primary connection (the samples
             * are keyed by it), at most HISTORY_CONCURRENCY in flight. With
             * `missingOnly`, only queues without a history are read; with
             * `background`, a timeout raises no global alert. A failed
             * read keeps the card's last series and marks it. Each queue keeps
             * its own request sequence, so an older response never replaces a
             * newer one.
             */
            loadHistory(missingOnly = false, background = false) {
                if (this.unmounted || !this.rows || !this.rows.length) return Promise.resolve();

                const rows = this.historyRows.filter(row => !missingOnly || !this.histories[row.key]);
                const tasks = rows.map(row => {
                    const sequence = this.startRequest(`history:${row.key}`);

                    const request = queueRequest('history', row.connection, row.name, {minutes: this.historyMinutes});

                    return () => this.get(request.url, request.params, background).then(
                        data => this.applyHistory(row.key, sequence, {window: historyWindow(data), buckets: historyBuckets(data), error: false}),
                        () => this.applyHistory(row.key, sequence, {...(this.histories[row.key] || {window: null, buckets: []}), error: true}),
                    );
                });

                return runLimited(tasks, HISTORY_CONCURRENCY, () => this.unmounted);
            },

            /** The 30 s poll of the backlog cards: a background read. */
            pollHistory() {
                return this.loadHistory(false, true);
            },

            applyHistory(key, sequence, history) {
                if (this.unmounted || !this.isCurrentRequest(`history:${key}`, sequence)) return;

                this.histories = {...this.histories, [key]: history};
            },

            /** The top of a card's y scale: shared by every card on screen, or its own. */
            scaleOf(row) {
                return backlogScale(this.backlog.scale, this.cardSlots, row.key);
            },

            cardTitle,

            /**
             * A new range for every card: the histories of the old range go
             * (their windows differ), and every card and the markers read the
             * new one. The choice is remembered per browser.
             */
            setRange(key) {
                if (key === this.backlog.range) return;

                this.backlog = {...this.backlog, range: backlogRange(key).key};
                writeBacklogChoice(this.backlog);
                this.histories = {};
                this.loadHistory(false);
                this.refresh();
            },

            setScale(key) {
                if (key === this.backlog.scale) return;

                this.backlog = {...this.backlog, scale: key};
                writeBacklogChoice(this.backlog);
            },

            showMoreCards() {
                this.cardLimit += CARD_PAGE;
                this.loadHistory(true);
            },

            /**
             * The failure groups of the selection in the live past hour: one
             * request per alias of the selected queue, or, for all queues, the
             * shell's groups of the past hour (`live.groupsHour`). A new
             * selection starts a new sequence, so an older response of another
             * selection is dropped.
             */
            loadFailures(background = false) {
                if (this.unmounted) return Promise.resolve();

                const sequence = this.startRequest('failures');
                const key = this.selectionKey;

                if (this.liveFailures) {
                    const data = this.live.groupsHour;
                    const scope = data && data.scope ? data.scope : null;
                    const from = num(scope && scope.from);
                    const to = num(scope && scope.to);

                    if (data) {
                        this.failures = mergeFailures([{scopeQueue: null, data}]);
                        this.failuresKey = key;
                        this.failuresWindow = from !== null && to !== null ? {from, to} : failureWindow(nowSeconds());
                    }

                    this.failuresError = this.live.groupsHourError ? 'The last read failed' : null;
                    this.failuresLoading = !data && !this.live.groupsHourError;

                    return Promise.resolve();
                }

                const scopes = failureScopes(this.selectedRow);
                const window = failureWindow(nowSeconds());

                return Promise.all(scopes.map(scopeQueue => this.get('/api/failures/groups', {
                    from: window.from,
                    to: window.to,
                    ...(scopeQueue ? {queue: scopeQueue} : {}),
                }, background).then(data => ({scopeQueue, data}))))
                    .then(responses => {
                        if (!this.isCurrentRequest('failures', sequence) || key !== this.selectionKey) return;

                        this.failures = mergeFailures(responses);
                        this.failuresKey = key;
                        this.failuresWindow = window;
                        this.failuresError = null;
                        this.failuresLoading = false;
                    })
                    .catch(error => {
                        if (!this.isCurrentRequest('failures', sequence)) return;

                        this.failuresError = this.errorText(error);
                        this.failuresLoading = false;
                    });
            },

            /**
             * The route selected another queue (or none). The same instance
             * stays: a preparation for the old queue is cancelled, an open
             * sheet closes, and the failures of the new selection load now.
             */
            selectionChanged() {
                this.cancelPreparation();

                if (this.sheetOpen && !this.busy) {
                    this.sheetOpen = false;
                }

                this.failures = null;
                this.failuresKey = null;
                this.failuresError = null;
                this.failuresLoading = true;

                if (this.rows !== null) {
                    this.loadFailures();

                    if (this.selectedRow && !this.histories[this.selectedRow.key]) this.loadHistory(true);
                } else {
                    this.startRequest('failures');
                }

                this.loadRetention();

                if (this.routeSelected) revealInspector();
            },

            select(row) {
                if (this.selectedRow && this.selectedRow.key === row.key) {
                    // The same queue again: its inspector shows (the narrow overlay may have been closed).
                    revealInspector();

                    return;
                }

                this.$router.push(queuePath(row.connection, row.name));
            },

            /** A change of the selection closes an open bulk sheet (BulkRun host rule 1); a run goes on. */
            cancelPreparation() {
                this.bulk.open = false;
            },

            /**
             * Open the confirmation of a control action with a frozen
             * selection: the queue and its aliases, or the connection section
             * and its aliases.
             */
            openControl(action, scope, target) {
                if (this.sheetOpen || this.busy || !target) return;

                this.cancelPreparation();

                const selection = scope === 'queue'
                    ? {kind: 'control', action, scope, queue: target.name, label: target.name, connections: target.connections.slice(), pause: target.pause, cacheNote: this.cacheNote}
                    : {kind: 'control', action, scope, queue: null, label: target.connection, connections: target.aliases.slice()};

                this.operation = {
                    selection,
                    title: controlTitle(selection),
                    message: controlMessage(selection),
                    confirmLabel: controlConfirmLabel(selection),
                    tone: action === 'terminate' ? 'danger' : 'primary',
                };
                this.snapshotKey++;
                this.sheetOpen = true;
            },

            /**
             * Retry a failure group: a bulk run over the group in its queue
             * scope and the live past hour (groupsSource()), a snapshot of
             * now. During a run nothing opens.
             */
            openRetry(group) {
                if (this.sheetOpen || this.busy) return;

                if (this.bulk.running) {
                    this.notice = {tone: 'info', title: codeText('bulk_run_busy'), detail: null};

                    return;
                }

                const source = groupsSource(group.scopeQueue, failureWindow(nowSeconds()), [group.key], [group]);

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

                if (!this.unmounted) {
                    this.refresh();
                    requestLiveRefresh();
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

            /** "Kept for": the retention settings, read once per visit; a failed read is retried on the next selection. */
            loadRetention() {
                if (this.unmounted || this.retentionLoading || (this.retention && !this.retentionError)) return Promise.resolve();

                this.retentionLoading = true;

                return this.get('/api/settings/retention', {}, true)
                    .then(data => {
                        if (this.unmounted) return;

                        this.retention = data && typeof data === 'object' ? data : null;
                        this.retentionError = this.retention === null;
                    })
                    .catch(() => {
                        if (!this.unmounted) this.retentionError = true;
                    })
                    .finally(() => {
                        this.retentionLoading = false;
                    });
            },

            cancelOperation() {
                this.sheetOpen = false;
            },

            /** The inspector's actions: Clear waiting jobs has its own sheet; the others are control signals. */
            onInspectorControl(action) {
                if (action === 'clear') return this.openClear(this.selectedRow);

                return this.openControl(action, 'queue', this.selectedRow);
            },

            /**
             * Open "Clear waiting jobs…": an operator read counts the waiting
             * jobs first (`POST …/waiting`), and the sheet freezes that count.
             * A count that fails or is refused shows as unknown, never 0. A
             * route change while the count runs opens nothing.
             */
            openClear(row) {
                if (this.sheetOpen || this.busy || !row) return Promise.resolve();

                this.cancelPreparation();

                const sequence = this.startRequest('operation');

                const request = queueRequest('waiting', row.connection, row.name);

                return this.$http.post(Orrery.basePath + request.url, request.body, {handles: [ANY_ERROR_STATUS, ...BACKGROUND_FAILURES]})
                    .then(response => (response && response.data && typeof response.data === 'object' ? response.data : null))
                    .catch(() => null)
                    .then(count => {
                        if (this.unmounted || !this.isCurrentRequest('operation', sequence) || this.sheetOpen || this.busy) return;

                        this.controlResult = null;
                        this.operation = clearSheet(row, count);
                        this.snapshotKey++;
                        this.sheetOpen = true;
                    });
            },

            /** Post the frozen clear; its answer, or why it failed, shows as the result notice. */
            postClear(selection) {
                if (this.busy) return Promise.resolve();

                this.busy = true;
                this.controlResult = null;

                // Exactly the frozen connection and queue that the sheet names.
                const request = queueRequest('clear', selection.connection, selection.queue);

                return this.$http.post(Orrery.basePath + request.url, request.body, {handles: CLEAR_HANDLES})
                    .then(response => {
                        this.controlResult = clearOutcome(selection, response ? response.data : null);
                    })
                    .catch(error => {
                        this.controlResult = clearFailure(selection, error);
                    })
                    .finally(() => {
                        this.busy = false;
                        this.sheetOpen = false;

                        if (!this.unmounted) {
                            this.refresh();
                            requestLiveRefresh();
                        }
                    });
            },

            /** Post exactly the frozen selection that the sheet emits. */
            confirmOperation(selection) {
                if (!selection) return Promise.resolve();

                if (selection.kind === 'clear') return this.postClear(selection);

                return this.postControl(selection);
            },

            /** One signal per alias, one after the other; the result names what was sent. */
            async postControl(selection) {
                if (this.busy) return;

                this.busy = true;
                this.controlResult = null;

                const outcomes = [];

                for (const body of controlBodies(selection)) {
                    try {
                        const response = await this.$http.post(`${Orrery.basePath}/api/control`, body);

                        outcomes.push({body, data: response ? response.data : null});
                    } catch (error) {
                        outcomes.push({body, failed: true, status: error && error.response ? error.response.status : null, data: error && error.response ? error.response.data : null});
                    }
                }

                this.controlResult = controlNotice(selection, outcomes);

                this.busy = false;
                this.sheetOpen = false;

                if (!this.unmounted) {
                    this.refresh();
                    // The table comes from the shell: ask it for fresh live data now.
                    requestLiveRefresh();
                }
            },

            state(row) {
                return queueState(row, this.now);
            },

            historyOf(row) {
                return this.histories[row.key] || null;
            },

            /** A definite cause pauses the queue (Orrery's, or Laravel's). */
            isPaused(row) {
                return isPaused(row);
            },

            cardTone(row) {
                if (this.isPaused(row)) return 'paused';

                return row.overLimit ? 'warning' : 'accent';
            },

            cardValue(row) {
                const ready = row.ready === null ? '—' : formatCount(row.ready);

                if (row.backlogUnknown) return 'backlog unknown';

                return this.isPaused(row) ? `${ready} · paused` : ready;
            },

            groupRecent(group) {
                return group.last_failed_at !== null && group.last_failed_at >= this.now - RECENT_FAILURE_SECONDS;
            },

            groupMarker(group) {
                return markerBefore(this.markers, group.first_failed_at, this.failuresWindow ? this.failuresWindow.from : null);
            },

            groupLine(group) {
                const parts = [group.message || 'No message', shortName(group.job)];

                if (!this.selectedRow || this.selectedRow.connections.length > 1) {
                    parts.push(group.scopeQueue || groupQueuesText(group));
                }

                return parts.filter(Boolean).join(' · ');
            },

            groupBuckets(group) {
                return (Array.isArray(group.buckets) ? group.buckets : [])
                    .map(bucket => ({at: num(bucket && bucket.at), count: num(bucket && bucket.count), partial: !!(bucket && bucket.partial)}))
                    .filter(bucket => bucket.at !== null);
            },
        },
    };
</script>

<template>
    <inspector-layout>
        <poll :request="refresh" :interval="pollSeconds" />
        <poll :key="historyPollSeconds" :request="pollHistory" :interval="historyPollSeconds" :immediate="false" />

        <div v-if="canOperate" class="o-queues__bulk" data-bulk-run>
            <bulk-run :open="bulk.open" action="retry" :source="bulk.source" :selection-key="bulk.key"
                      :storage-key="$options.BULK_STORAGE_KEY" source-kind="groups"
                      @started="onBulkStarted" @close="onBulkClose" @finished="onBulkFinished" />
        </div>

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

        <notice v-if="rowsError" tone="danger" title="Could not load the queues." :detail="rows === null ? `${rowsError}.` : `${rowsError}. The table shows the last data it read.`"
                data-rows-error />

        <div v-if="rows === null && !rowsError" class="o-queues__loading" data-loading>Loading…</div>

        <section v-else-if="rows !== null && !rows.length" class="o-card o-queues__empty" data-empty>No queues on this connection</section>

        <section v-for="section in sections" :key="section.connection" class="o-section" data-connection-section>
            <div class="o-section__head">
                <div class="o-section__title">{{ section.connection }}</div>
                <div class="o-section__subtitle" data-section-workers>{{ sectionWorkersText(workers, section.aliases) }}</div>
                <div class="o-queues__spacer"></div>
                <o-button v-if="sectionPausedBroadly(section)" variant="write" icon="play" operate data-continue-all
                          @click="openControl('continue', 'connection', section)">Continue all…</o-button>
                <o-button variant="write" icon="pause" operate data-pause-all @click="openControl('pause', 'connection', section)">Pause all…</o-button>
                <span v-if="canOperate" class="o-section__divider"></span>
                <o-button variant="danger" icon="power" operate data-terminate-all @click="openControl('terminate', 'connection', section)">Terminate all workers…</o-button>
            </div>

            <div class="o-card o-table">
                <div class="o-table__head">
                    <span class="o-col-queue">Queue</span>
                    <span class="o-col-wait">Oldest wait</span>
                    <span class="o-col-num">Ready</span>
                    <span class="o-col-running">Running</span>
                    <span class="o-col-rates">
                        <span>In / out per min</span>
                        <span class="o-col-rates__sub">last 5 min · observed</span>
                    </span>
                    <span class="o-col-workers">Workers</span>
                    <span class="o-col-state">State</span>
                </div>

                <div v-for="row in section.rows" :key="row.key" class="o-row"
                     :class="{'o-row--selected': selectedRow && selectedRow.key === row.key, 'o-row--paused': isPaused(row)}"
                     data-queue-row :data-key="row.key" role="button" tabindex="0"
                     @click="select(row)" @keydown.enter.self.prevent="select(row)">
                    <span class="o-col-queue">
                        <span class="o-row__name" :title="row.name">{{ row.name }}</span>
                        <span class="o-row__caption" data-limit>{{ limitText(row.threshold) }}</span>
                    </span>
                    <span class="o-col-wait">
                        <span v-if="row.backlogUnknown" class="o-row__gauge-unknown" title="The backlog could not be read" data-gauge-unknown>—</span>
                        <mini-gauge v-else :oldest-age="row.oldestAge" :threshold="row.threshold" :over-limit="row.overLimit" :paused="isPaused(row)" />
                        <span class="o-row__wait" :class="{'o-row__wait--over': row.overLimit, 'o-row__wait--none': row.oldestAge === null}" data-wait>{{ waitText(row) }}</span>
                    </span>
                    <span class="o-col-num" data-ready>{{ row.ready === null ? '—' : formatCount(row.ready) }}</span>
                    <span class="o-col-running" data-running>{{ row.running === null ? '—' : formatCount(row.running) }}</span>
                    <span class="o-col-rates o-col-rates--value" data-rates>{{ inOutText(row) }}</span>
                    <span class="o-col-workers" data-workers>{{ row.workers === null ? '—' : formatCount(row.workers) }}</span>
                    <span class="o-col-state" data-state :data-state-kind="state(row).kind">
                        <span class="o-state-icon" :class="`o-state-icon--${state(row).kind}`" aria-hidden="true"></span>
                        <span class="o-state">
                            <span class="o-state__label" :title="state(row).label">{{ state(row).label }}</span>
                            <span v-if="state(row).detail" class="o-state__detail" :title="state(row).detail">{{ state(row).detail }}</span>
                        </span>
                    </span>
                </div>
            </div>
        </section>

        <section v-if="rows && rows.length" class="o-section" data-backlog>
            <div class="o-section__head">
                <div class="o-section__title">Backlog</div>
                <div class="o-section__subtitle" data-backlog-subtitle>{{ backlogSubtitle }}</div>
                <div class="o-queues__spacer"></div>
                <div class="o-backlog-controls">
                    <div class="o-segmented" role="group" aria-label="Backlog range">
                        <button v-for="entry in backlogRanges" :key="entry.key" type="button" class="o-segmented__item"
                                :class="{'o-segmented__item--active': entry.key === backlog.range}" :aria-pressed="entry.key === backlog.range ? 'true' : 'false'"
                                :data-backlog-range="entry.key" @click="setRange(entry.key)">{{ entry.label }}</button>
                    </div>
                    <div class="o-segmented" role="group" aria-label="Backlog scale">
                        <button v-for="entry in backlogScales" :key="entry.key" type="button" class="o-segmented__item"
                                :class="{'o-segmented__item--active': entry.key === backlog.scale}" :aria-pressed="entry.key === backlog.scale ? 'true' : 'false'"
                                :data-backlog-scale="entry.key" @click="setScale(entry.key)">{{ entry.label }}</button>
                    </div>
                </div>
            </div>
            <div class="o-cards">
                <div v-for="row in cardRows" :key="row.key" class="o-backlog-card"
                     :class="{'o-backlog-card--over': row.overLimit && !isPaused(row), 'o-backlog-card--paused': isPaused(row), 'o-backlog-card--selected': selectedRow && selectedRow.key === row.key}"
                     data-backlog-card :data-key="row.key" @click="select(row)">
                    <div class="o-backlog-card__head">
                        <button type="button" class="o-backlog-card__name" :title="`${cardTitle(row).group} · ${row.name}`"
                                :aria-pressed="selectedRow && selectedRow.key === row.key ? 'true' : 'false'" data-card-name @click.stop="select(row)">
                            <span class="o-backlog-card__queue">{{ cardTitle(row).name }}</span>
                            <span class="o-backlog-card__group">{{ cardTitle(row).group }}</span>
                        </button>
                        <span class="o-backlog-card__value" data-card-value>{{ cardValue(row) }}</span>
                    </div>
                    <div v-if="!historyOf(row) || (!historyOf(row).window && !historyOf(row).error)" class="o-backlog-card__note">Loading…</div>
                    <div v-else-if="!historyOf(row).window" class="o-backlog-card__note" data-card-error>Could not load the backlog.</div>
                    <template v-else>
                        <backlog-chart :window="historyOf(row).window" :buckets="historyOf(row).buckets" :max="scaleOf(row)" :markers="markers"
                                       :paused-from="pausedFrom(row, historyOf(row).window)" :paused-title="pausedTitle(row, historyOf(row).window)"
                                       :tone="cardTone(row)" :label="`${cardTitle(row).name} on ${cardTitle(row).group}: ready jobs, ${rangeLabel}`" />
                        <div v-if="historyOf(row).error" class="o-backlog-card__note" data-card-stale>Last read failed; showing older data.</div>
                    </template>
                </div>
            </div>
            <div v-if="rows.length > cardLimit" class="o-cards__more">
                <o-button variant="write" size="sm" data-show-more @click="showMoreCards">Show more ({{ (rows.length - cardLimit).toLocaleString() }} more {{ rows.length - cardLimit === 1 ? 'queue' : 'queues' }})</o-button>
            </div>
        </section>

        <section v-if="rows !== null" class="o-section" data-failures>
            <div class="o-section__head">
                <div class="o-section__title" data-failures-title>Recent failures on {{ failuresLabel }}</div>
                <div class="o-section__subtitle">Failure groups on {{ selectedRow ? 'the selected queue' : 'all queues' }} · past hour</div>
                <div class="o-queues__spacer"></div>
                <o-button variant="link" to="/failed" data-show-failed>Show in Failed jobs</o-button>
            </div>

            <div class="o-failures">
                <notice v-if="failuresError" tone="danger" title="Could not load the failure groups." :detail="`${failuresError}.`" data-failures-error />
                <div v-if="failuresLoading && !failuresError" class="o-queues__loading" data-failures-loading>Loading…</div>

                <div v-for="group in shownGroups" :key="group.rowKey" class="o-failure" data-failure-row :data-scope="group.scopeQueue || ''">
                    <div class="o-failure__main">
                        <div class="o-failure__title-row">
                            <span class="o-failure__dot" :class="{'o-failure__dot--recent': groupRecent(group)}"></span>
                            <span class="o-failure__title" :title="group.exception_class" data-failure-title>{{ groupTitle(group) }}</span>
                            <span class="o-failure__last" :title="formatLocal(group.last_failed_at)">· last {{ relativeTime(group.last_failed_at, now) }}</span>
                            <span v-if="groupMarker(group)" class="o-chip" data-marker-chip>{{ markerName(groupMarker(group)) }} {{ formatHourMinuteZoned(groupMarker(group).at) }}</span>
                        </div>
                        <div class="o-failure__line" :title="group.message" data-failure-line>{{ groupLine(group) }}</div>
                    </div>
                    <span class="o-failure__spark">
                        <sparkline v-if="failuresWindow" :from="failuresWindow.from" :to="failuresWindow.to" :bucket-seconds="group.bucketSeconds"
                                   :buckets="groupBuckets(group)" value-key="count" :retention-from="group.retentionFrom" :width="88" :height="22"
                                   :label="`${shortName(group.exception_class)} failures per minute`" />
                    </span>
                    <o-button v-if="!failedReadOnly" variant="write" icon="retry" operate data-retry @click="openRetry(group)">{{ retryLabel(group) }}</o-button>
                    <router-link to="/failed" class="o-failure__chevron" :aria-label="`${shortName(group.exception_class)} in Failed jobs`">›</router-link>
                </div>

                <div v-if="hiddenGroups" class="o-failures__more">
                    <o-button variant="link" to="/failed">{{ failuresIncomplete ? 'at least ' : '' }}{{ hiddenGroups }} more {{ hiddenGroups === 1 ? 'group' : 'groups' }} in Failed jobs</o-button>
                </div>
                <div v-else-if="failuresIncomplete" class="o-failures__footer" data-failures-incomplete>More failure groups may exist; see Failed jobs.</div>
                <div v-if="footer" class="o-failures__footer" data-failures-footer>
                    <span class="o-ok" aria-hidden="true">✓</span>{{ footer }}
                </div>
            </div>
        </section>

        <template v-if="routeSelected" #inspector>
            <queue-inspector :row="selectedRow" :not-found="notFound" :loading="rows === null && !rowsError" :history="selectedHistory" :markers="markers" :range-label="rangeLabel"
                             :workers="workers" :workers-error="workersError" :now="now" :cache-note="cacheNote"
                             :retention="retention" :retention-error="retentionError"
                             @control="onInspectorControl" />
        </template>

        <confirm-sheet :open="sheetOpen" :title="operation ? operation.title : ''" :message="operation ? operation.message : null"
                       :confirm-label="operation ? operation.confirmLabel : 'Confirm'" :tone="operation ? operation.tone : 'primary'"
                       :selection="operation ? operation.selection : null" :snapshot-key="snapshotKey" :busy="busy"
                       @confirm="confirmOperation" @cancel="cancelOperation">
            <template #default="{selection}">
                <div v-if="selection && selection.kind === 'control'" class="o-sheet-summary" data-sheet-summary>
                    <div data-sheet-targets>
                        {{ selection.connections.length }} {{ selection.connections.length === 1 ? 'signal' : 'signals' }}:
                        {{ selection.connections.map(alias => selection.scope === 'queue' ? `${alias}:${selection.queue}` : alias).join(', ') }}
                    </div>
                    <code v-for="command in nativeContinueCommands(selection)" :key="command" class="o-sheet-summary__command" data-sheet-command>{{ command }}</code>
                </div>
            </template>
        </confirm-sheet>

        <confirm-sheet :open="leave.open" title="Leave this screen?" :message="leaveText" confirm-label="Leave"
                       tone="danger" :snapshot-key="leave.key" @confirm="confirmLeave" @cancel="cancelLeave" />
    </inspector-layout>
</template>

<style scoped>
    .o-row__gauge-unknown {
        color: var(--color-text-secondary);
        display: inline-block;
        text-align: center;
        width: 40px;
    }

    .o-sheet-summary__command {
        display: block;
        font-family: var(--font-mono);
        font-size: var(--text-caption);
        overflow-wrap: anywhere;
    }

    /* BulkRun's sheet is teleported: the wrapper takes room only for a run or recovery panel. */
    .o-queues__bulk:not(:has(section)) {
        display: none;
    }

    .o-queues__spacer {
        flex-grow: 1;
    }

    .o-queues__loading {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: 18px;
        padding: 16px 0;
    }

    .o-queues__empty {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        padding: 24px;
        text-align: center;
    }

    .o-section {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .o-section__head {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 8px 10px;
        min-height: 28px;
    }

    .o-section__title {
        color: var(--color-text);
        font-size: var(--text-section);
        font-weight: var(--font-weight-semibold);
        letter-spacing: -0.01em;
        line-height: 22px;
        white-space: nowrap;
    }

    .o-section__subtitle {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: 18px;
        padding-top: 2px;
    }

    .o-section__divider {
        background-color: var(--color-separator);
        flex-shrink: 0;
        height: 20px;
        width: 1px;
    }

    .o-card {
        background-color: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-card);
        box-sizing: border-box;
    }

    /* A wide table scrolls inside its own container, never the page. */
    .o-table {
        display: flex;
        flex-direction: column;
        overflow-x: auto;
    }

    .o-table > .o-table__head,
    .o-table > .o-row {
        min-width: 760px;
    }

    .o-table__head,
    .o-row {
        align-items: center;
        box-sizing: border-box;
        display: flex;
        padding-inline: 14px;
    }

    .o-table__head {
        background-color: var(--color-subtle);
        border-bottom: 1px solid var(--color-separator);
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        height: 32px;
        line-height: 16px;
    }

    .o-row {
        border-bottom: 1px solid var(--color-separator);
        color: var(--color-text);
        cursor: pointer;
        font-size: var(--text-body);
        height: 56px;
        line-height: 18px;
    }

    .o-row:last-of-type {
        border-bottom: none;
    }

    .o-row:hover {
        background-color: var(--color-subtle);
    }

    .o-row--paused {
        background-color: var(--color-subtle);
        color: var(--color-text-secondary);
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

    .o-col-queue { display: flex; flex-direction: column; flex-shrink: 0; gap: 1px; min-width: 0; width: 130px; }
    .o-col-wait { align-items: center; display: flex; flex-shrink: 0; gap: 10px; width: 132px; }
    .o-col-num { flex-shrink: 0; font-variant-numeric: tabular-nums; text-align: right; width: 80px; }
    .o-col-running { flex-shrink: 0; font-variant-numeric: tabular-nums; text-align: right; width: 70px; }
    .o-col-rates { align-items: flex-end; display: flex; flex-direction: column; flex-shrink: 0; line-height: 14px; white-space: nowrap; width: 140px; }
    .o-col-rates--value { font-variant-numeric: tabular-nums; line-height: 18px; }
    .o-col-rates__sub { color: var(--color-text-tertiary); font-weight: var(--font-weight-regular); }
    .o-col-workers { flex-shrink: 0; font-variant-numeric: tabular-nums; text-align: right; width: 66px; }
    .o-col-state { align-items: center; display: flex; flex: 1 1 0%; gap: 6px; min-width: 0; padding-left: 24px; }

    .o-row__name {
        font-weight: var(--font-weight-semibold);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-row__caption {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
    }

    .o-row__wait {
        font-weight: var(--font-weight-semibold);
        white-space: nowrap;
    }

    .o-row__wait--over { color: var(--color-warning-text); }
    .o-row__wait--none { color: var(--color-text-tertiary); font-weight: var(--font-weight-regular); }

    .o-state {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .o-state__label {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: 18px;
    }

    .o-col-state[data-state-kind='over'] .o-state__label,
    .o-col-state[data-state-kind='quiet'] .o-state__label {
        color: var(--color-warning-text);
        font-weight: var(--font-weight-semibold);
    }

    .o-col-state[data-state-kind='paused'] .o-state__label,
    .o-col-state[data-state-kind='unknown'] .o-state__label {
        font-weight: var(--font-weight-medium);
    }

    .o-state__detail {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-state-icon {
        flex-shrink: 0;
        height: 7px;
        width: 7px;
    }

    .o-state-icon--running { background-color: var(--color-success); border-radius: 50%; }
    .o-state-icon--quiet { background-color: var(--color-warning); border-radius: 50%; }
    .o-state-icon--idle { background-color: var(--color-paused); border-radius: 50%; }
    .o-state-icon--over { border-bottom: 10px solid var(--color-warning); border-left: 6px solid transparent; border-right: 6px solid transparent; height: 0; width: 0; }
    .o-state-icon--paused { border-left: 2.5px solid var(--color-paused); border-right: 2.5px solid var(--color-paused); height: 9px; width: 3px; }
    .o-state-icon--unknown { border: 1.5px dashed var(--color-text-tertiary); border-radius: 50%; box-sizing: border-box; height: 9px; width: 9px; }

    .o-cards {
        display: grid;
        gap: 12px;
        grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
    }

    .o-cards__more {
        display: flex;
        justify-content: center;
    }

    .o-backlog-card {
        background-color: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-card);
        box-sizing: border-box;
        cursor: pointer;
        display: flex;
        flex-direction: column;
        gap: 8px;
        min-width: 0;
        padding: 12px 11px 10px;
    }

    .o-backlog-card--over { border-color: color-mix(in srgb, var(--color-warning) 55%, transparent); }
    .o-backlog-card--paused { background-color: var(--color-subtle); }
    .o-backlog-card--selected { box-shadow: var(--color-accent) 0 0 0 1px; }

    .o-backlog-card:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: 1px;
    }

    .o-backlog-card__head {
        align-items: baseline;
        display: flex;
        font-size: var(--text-table);
        gap: 6px;
        line-height: 18px;
    }

    /* The card's title: a button that selects the queue (the chart below it takes its own focus). */
    .o-backlog-card__name {
        align-items: flex-start;
        background: transparent;
        border: 0;
        border-radius: 4px;
        color: var(--color-text);
        cursor: pointer;
        display: flex;
        flex: 1 1 0%;
        flex-direction: column;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        line-height: 18px;
        min-width: 0;
        padding: 0;
        text-align: left;
    }

    .o-backlog-card__name:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: 2px;
    }

    .o-backlog-card__queue {
        font-weight: var(--font-weight-semibold);
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-backlog-card__group {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-backlog-card--paused .o-backlog-card__name { color: var(--color-text-secondary); }

    .o-backlog-controls {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .o-segmented {
        align-items: center;
        background-color: color-mix(in srgb, var(--color-text) 7%, transparent);
        border-radius: 7px;
        display: flex;
        padding: 2px;
    }

    .o-segmented__item {
        background: transparent;
        border: 0;
        border-radius: 5px;
        color: var(--color-text);
        cursor: pointer;
        font-family: var(--font-sans);
        font-size: var(--text-caption);
        line-height: 16px;
        padding: 3px 9px;
        white-space: nowrap;
    }

    .o-segmented__item--active {
        background-color: var(--color-bg);
        box-shadow: var(--shadow-raised);
        font-weight: var(--font-weight-semibold);
    }

    .o-segmented__item:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: 1px;
    }

    .o-backlog-card__value {
        color: var(--color-text-secondary);
        white-space: nowrap;
    }

    .o-backlog-card--over .o-backlog-card__value {
        color: var(--color-warning-text);
        font-weight: var(--font-weight-semibold);
    }

    .o-backlog-card__note {
        color: var(--color-text-tertiary);
        font-size: var(--text-caption);
        line-height: 16px;
    }

    .o-failures {
        border-top: 1px solid var(--color-separator);
        display: flex;
        flex-direction: column;
    }

    .o-failure {
        align-items: center;
        border-bottom: 1px solid var(--color-separator);
        display: flex;
        gap: 16px;
        min-height: 60px;
    }

    .o-failure__main {
        display: flex;
        flex: 1 1 0%;
        flex-direction: column;
        gap: 2px;
        min-width: 0;
    }

    .o-failure__title-row {
        align-items: center;
        display: flex;
        gap: 7px;
        min-width: 0;
    }

    .o-failure__dot {
        background-color: var(--color-paused);
        border-radius: 50%;
        flex-shrink: 0;
        height: 7px;
        width: 7px;
    }

    .o-failure__dot--recent { background-color: var(--color-danger); }

    .o-failure__title {
        color: var(--color-text);
        font-size: var(--text-body);
        font-weight: var(--font-weight-semibold);
        line-height: 20px;
        white-space: nowrap;
    }

    .o-failure__last {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: 18px;
        white-space: nowrap;
    }

    .o-failure__line {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: 18px;
        overflow: hidden;
        padding-left: 14px;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-failure__spark {
        display: flex;
        flex-shrink: 0;
        width: 100px;
    }

    .o-failure__chevron {
        color: var(--color-text-tertiary);
        flex-shrink: 0;
        font-size: 18px;
        text-align: right;
        text-decoration: none;
        width: 16px;
    }

    .o-failures__footer,
    .o-failures__more {
        align-items: center;
        color: var(--color-text-secondary);
        display: flex;
        font-size: var(--text-table);
        gap: 7px;
        line-height: 18px;
        min-height: 44px;
    }

    .o-ok {
        color: var(--color-success);
        font-weight: var(--font-weight-semibold);
    }

    .o-chip {
        background-color: color-mix(in srgb, var(--color-text) 7%, transparent);
        border-radius: 5px;
        color: var(--color-text);
        font-size: var(--text-caption);
        line-height: 16px;
        padding: 1px 6px;
        white-space: nowrap;
    }

    .o-sheet-summary {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .o-sheet-summary__groups {
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .o-sheet-summary__note {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
    }
</style>
