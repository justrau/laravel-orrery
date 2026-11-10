<script>
    import Button from './Button.vue';
    import ConfirmSheet from './ConfirmSheet.vue';
    import {
        FREEZE_NOTE,
        UNCERTAIN_NOTE,
        applyPage,
        basePathClient,
        bulkBlocker,
        canFreeze,
        confirmLabel,
        confirmationCount,
        confirmationNote,
        endPhase,
        isSheetPhase,
        latestRecovered,
        needsRefreeze,
        openAction,
        recoveryKeys,
        storageKeyFor,
        forgetBlocker,
        freezeBody,
        freezeFailure,
        freezeInput,
        frozenMessage,
        frozenTitle,
        recordLines,
        recentRunsOf,
        recoveredActions,
        recoveredHeadline,
        refusedLines,
        remainingText,
        resultPage,
        runBody,
        runFailure,
        runHeadline,
        runLabel,
        shouldRunNextPage,
        startRun,
        storedRun,
        storedRunIs,
        storedValue,
    } from '../../support/bulk.js';
    import {totalText} from '../../support/lists.js';
    import {readStored, removeStored, writeStored} from '../../support/storage.js';
    import {formatCount, formatLocal} from '../../support/time.js';
    import {codeText, itemText, outcomeLabel} from '../../support/wording.js';

    /** The failures of a freeze or a page that this component shows itself. */
    const HANDLES = [400, 404, 409, 422, 429, 500, 502, 503, 504, 'timeout', 'network', 'aborted'];

    const PANEL_PHASES = ['running', 'stopped', 'done', 'error'];

    /**
     * The bulk run,
     * shared by the Jobs
     * list, the failure groups, the Overview notices and the batch detail.
     *
     * - Before: when `open` becomes true, `POST /api/bulk/freeze` with
     *   `action` and `source`; the ConfirmSheet shows the frozen total ("at
     *   least" when capped), the held count, the preview of up to 20 members
     *   and FREEZE_NOTE. A forget asks for the exact total to be typed and
     *   sends it as `confirmed_total`. A refused freeze (too many, not exact,
     *   no APP_KEY, the configuration, the schema) offers only a way back.
     * - During: `POST /api/bulk/run` page after page with the token of the
     *   freeze, verbatim, and the `after` cursor of the last page, until
     *   `done`. Stop (and closing, and unmounting) ends the run before the
     *   next page; nothing is undone.
     * - After: the totals per outcome, the refused reasons and an
     *   explanation of `uncertain` through the wording table, the per-member
     *   results of the pages that this client saw (paged here), and the
     *   server record of the run (`GET /api/bulk/{ref}`: the counters and the
     *   exact operation states).
     * - With `recover`, a run kept in localStorage (its ref) is found again
     *   after a reload and shown from the server record, with the recent
     *   runs (`GET /api/bulk`).
     *
     * Every write needs the operate ability; without it nothing is frozen
     * and the Stop and Start buttons are not rendered. The sheet deep-copies
     * the freeze answer, so a refresh of the screen never changes it. No
     * answer changes anything after unmount.
     *
     * `action` and `source` are a SNAPSHOT of the user's action: they are
     * read when `open` becomes true, and again only when `selectionKey`
     * changes while the sheet is open (the host changes it when its user
     * changes the selection). A polled change of `source` never freezes
     * again. A superseded freeze request is aborted.
     *
     * Events: `started` when the sheet hands over to a run (the parent sets
     * `open` to false; that never stops the run: Stop and unmounting do);
     * `close` when the sheet or the finished run panel closes, and when an
     * open request is refused (during a run, or without the operate
     * ability); `finished` with the run, after its last page or a failed
     * page; `restart` with the action and a copy of the source of the
     * recovered run (the recovered panel's "Start a new run…"). Without `client`, requests go to the
     * dashboard's base path (window.Orrery.basePath). Keep BulkRun mounted while a run is shown: a
     * remount loses the client's totals (the server record stays).
     *
     * A list source with a search text is refused before any request
     * (`search_not_in_bulk`): the server's list source has no search. The
     * stored ref of a run is kept under the key of its own action and source
     * kind (storageKeyFor()), unless `storageKey` names another key; the
     * recovery reads both actions of the host's source kind, and lists only
     * the recent runs of that kind (`sourceKind`, else `source.kind`;
     * recentRunsOf()).
     */
    export default {
        components: {'o-button': Button, ConfirmSheet},

        props: {
            action: {type: String, default: 'retry', validator: value => ['retry', 'forget'].includes(value)},
            source: {type: Object, default: null},
            open: {type: Boolean, default: false},
            selectionKey: {type: [String, Number], default: null},
            recover: {type: Boolean, default: false},
            storageKey: {type: String, default: null},
            /** The host's source kind (`groups`, `list`, `batch`) while `source` is not built yet; else `source.kind`. */
            sourceKind: {type: String, default: null},
            client: {type: Object, default: null},
        },

        emits: ['started', 'close', 'finished', 'restart'],

        data() {
            return {
                phase: 'idle',
                sequence: 0,
                answer: null,
                failure: null,
                typed: '',
                run: null,
                confirmedTotal: null,
                stopRequested: false,
                memberPage: 0,
                record: null,
                recordError: false,
                recovered: null,
                recentRuns: null,
                runToken: 0,
                runKey: null,
                frozenAction: null,
                frozenSource: null,
                frozenFor: null,
                freezeAbort: null,
                unmounted: false,
            };
        },

        computed: {
            /** The `client` prop, else the shared HTTP client with the dashboard's base path. */
            http() {
                return this.client || basePathClient(this.$http, typeof window !== 'undefined' && window.Orrery ? window.Orrery.basePath : '');
            },

            sheetOpen() {
                return isSheetPhase(this.phase);
            },

            recoverKeys() {
                return this.storageKey ? [this.storageKey] : recoveryKeys(this.hostKind);
            },

            hostKind() {
                return this.sourceKind || (this.source && typeof this.source.kind === 'string' ? this.source.kind : null);
            },

            /** The recent runs of the host's own source kind (recentRunsOf()). */
            hostRuns() {
                return this.recentRuns === null ? null : recentRunsOf(this.recentRuns, this.hostKind, this.source);
            },

            sheetKey() {
                return `${this.phase}:${this.sequence}`;
            },

            sheet() {
                if (this.phase === 'freezing') {
                    return {title: 'Counting the jobs…', message: 'Nothing is changed while the selection is counted.', label: 'Continue', tone: 'primary'};
                }

                if (this.phase === 'blocked') {
                    return {
                        title: this.frozenAction === 'forget' ? 'This forget cannot start' : 'This run cannot start',
                        message: this.failure ? this.failure.text : null,
                        label: 'Change the selection',
                        tone: 'primary',
                    };
                }

                if (this.phase === 'failed') {
                    return {title: 'The selection could not be counted', message: this.failure ? this.failure.text : null, label: 'Try again', tone: 'primary'};
                }

                const frozen = this.answer ? this.answer.frozen : null;

                return {
                    title: frozenTitle(frozen, this.answer ? this.answer.held : null),
                    message: frozenMessage(this.answer, this.frozenSource),
                    label: confirmLabel(frozen),
                    tone: frozen && frozen.action === 'forget' ? 'danger' : 'primary',
                };
            },

            blocker() {
                return this.phase === 'confirm' && this.answer ? forgetBlocker(this.answer.frozen, this.typed) : null;
            },

            confirmDisabled() {
                return this.phase === 'freezing' || (this.phase === 'confirm' && (this.blocker !== null || !this.canOperate));
            },

            panelVisible() {
                return PANEL_PHASES.includes(this.phase) && this.run !== null;
            },

            headline() {
                if (!this.run) return null;

                return runHeadline(this.run, {stopped: this.phase === 'stopped', failed: this.phase === 'error'});
            },

            cells() {
                const totals = this.run ? this.run.totals : null;
                const value = key => (totals && typeof totals[key] === 'number' ? formatCount(totals[key]) : '—');
                const cells = [
                    {key: 'accepted', label: 'Accepted', value: value('accepted')},
                    {key: 'refused', label: 'Refused', value: value('refused'), warn: totals && totals.refused > 0},
                    {key: 'uncertain', label: 'Uncertain', value: value('uncertain')},
                    {key: 'not_member', label: 'Not members / done', value: `${value('not_member')} / ${value('already_done')}`},
                ];

                if (this.run && this.run.action === 'forget') {
                    cells.push({key: 'not_confirmed', label: 'Not confirmed', value: value('not_confirmed')});
                }

                return cells;
            },

            refused() {
                return this.run ? refusedLines(this.run.refusedReasons) : [];
            },

            showUncertain() {
                const uncertain = this.run ? this.run.totals.uncertain : 0;

                return uncertain === null || uncertain > 0;
            },

            members() {
                return resultPage(this.run ? this.run.results : [], this.memberPage);
            },

            recordText() {
                return this.record ? recordLines(this.record) : null;
            },

            recoveredText() {
                return this.recovered && this.recovered.record ? recordLines(this.recovered.record) : null;
            },

            recoveredHeadline() {
                return this.recovered && this.recovered.record ? recoveredHeadline(this.recovered.stored, this.recovered.record) : null;
            },

            recoveredActions() {
                return recoveredActions(this.recovered);
            },
        },

        watch: {
            open: {
                immediate: true,
                handler(open) {
                    if (!open) {
                        this.cancelPreparation();

                        return;
                    }

                    if (openAction(this.phase, this.canOperate) === 'refuse') {
                        this.$emit('close');

                        return;
                    }

                    this.freeze();
                },
            },

            selectionKey(key) {
                // The host's user changed the selection under the open sheet: freeze it again, once.
                if (needsRefreeze({open: this.open, phase: this.phase, key, frozenFor: this.frozenFor})) this.freeze();
            },
        },

        mounted() {
            if (this.recover) this.loadRecovered();
        },

        beforeUnmount() {
            this.unmounted = true;
            this.stopRequested = true;
            this.abortFreeze();
        },

        methods: {
            /** A new sequence: an answer of an older one is dropped (cancelled preparations). */
            nextSequence() {
                this.sequence++;

                return this.sequence;
            },

            current(sequence) {
                return !this.unmounted && this.sequence === sequence;
            },

            abortFreeze() {
                if (this.freezeAbort) this.freezeAbort.abort();

                this.freezeAbort = null;
            },

            /** Drops a preparation (a pending freeze, an open sheet); a run goes on. */
            cancelPreparation() {
                this.nextSequence();
                this.abortFreeze();

                if (isSheetPhase(this.phase)) {
                    this.phase = 'idle';
                    this.answer = null;
                }
            },

            /**
             * Freezes the selection. `reuse` ("Try again" after a failed
             * freeze) freezes the snapshot of the failed freeze again, never
             * the live props (freezeInput()).
             */
            async freeze({reuse = false} = {}) {
                if (!canFreeze(this.phase, this.canOperate)) return;

                const sequence = this.nextSequence();
                const input = freezeInput({
                    reuse,
                    action: this.action,
                    source: this.source,
                    selectionKey: this.selectionKey,
                    frozenAction: this.frozenAction,
                    frozenSource: this.frozenSource,
                    frozenFor: this.frozenFor,
                });

                this.abortFreeze();
                // The snapshot of the user's action: a later poll of the host does not reach it.
                this.frozenAction = input.action;
                this.frozenSource = input.source;
                this.frozenFor = input.key;
                this.answer = null;
                this.failure = null;
                this.typed = '';

                const blocker = bulkBlocker(this.frozenAction, this.frozenSource);

                if (blocker) {
                    this.failure = {blocked: true, code: blocker.code, text: blocker.text};
                    this.phase = 'blocked';
                    this.sequence++;

                    return;
                }

                this.phase = 'freezing';

                const body = freezeBody(this.frozenAction, this.frozenSource);
                const controller = typeof AbortController === 'function' ? new AbortController() : null;

                this.freezeAbort = controller;

                try {
                    const response = await this.http.post('/api/bulk/freeze', body, {handles: HANDLES, signal: controller ? controller.signal : undefined});

                    if (!this.current(sequence)) return;

                    // A deep copy: a later change of the screen never reaches the frozen answer.
                    this.answer = JSON.parse(JSON.stringify(response.data));
                    this.phase = 'confirm';
                    this.sequence++;
                } catch (error) {
                    if (!this.current(sequence)) return;

                    this.failure = freezeFailure(error);
                    this.phase = this.failure.blocked ? 'blocked' : 'failed';
                    this.sequence++;
                }
            },

            onConfirm() {
                if (this.phase === 'blocked') {
                    this.close();

                    return;
                }

                if (this.phase === 'failed') {
                    // The same selection again: never the live props of the host.
                    this.freeze({reuse: true});

                    return;
                }

                if (this.phase !== 'confirm' || this.blocker !== null || !this.canOperate) return;

                const frozen = this.answer.frozen;

                this.confirmedTotal = frozen.action === 'forget' ? frozen.total.count : null;
                this.run = startRun(frozen);
                this.stopRequested = false;
                this.memberPage = 0;
                this.record = null;
                this.recordError = false;
                this.failure = null;
                this.phase = 'running';
                // The key of the run's own action and source, whatever the props are later.
                this.runKey = this.storageKey || storageKeyFor(frozen.action, frozen.source);
                this.remember('running');
                this.loop(frozen);
                // The sheet hands over to the run panel: the parent closes `open`.
                this.$emit('started', this.run);
            },

            async loop(frozen) {
                this.runToken++;

                const token = this.runToken;
                const live = () => !this.unmounted && this.runToken === token;
                // The client of the run's start: a later change of the props does not reach a running run.
                const http = this.http;

                while (live() && shouldRunNextPage(this.run, this.stopRequested)) {
                    try {
                        const response = await http.post('/api/bulk/run', runBody(frozen, this.run.after, this.confirmedTotal), {handles: HANDLES});

                        if (!live()) return;

                        this.run = applyPage(this.run, response.data);
                    } catch (error) {
                        if (!live()) return;

                        this.failure = runFailure(error);
                        this.phase = 'error';
                        this.remember('error');
                        this.loadRecord();
                        this.reloadRecovered();
                        // The run is over: the host learns it as after the last page.
                        this.$emit('finished', this.run);

                        return;
                    }
                }

                if (!live()) return;

                this.phase = endPhase(this.run);
                // 'done' when the server said so, else the user stopped it.
                this.remember(this.phase);
                this.loadRecord();
                this.reloadRecovered();
                this.$emit('finished', this.run);
            },

            stop() {
                this.stopRequested = true;
            },

            /** A run ended: the recovered panel shows the stored runs as they are now, never an older run. */
            reloadRecovered() {
                if (!this.recover) return;

                this.recovered = null;
                this.loadRecovered();
            },

            async loadRecord() {
                const ref = this.run ? this.run.ref : null;

                if (!ref) return;

                const current = () => !this.unmounted && this.run !== null && this.run.ref === ref;

                try {
                    const response = await this.http.get(`/api/bulk/${ref}`, {handles: HANDLES});

                    if (!current()) return;

                    this.record = response.data;
                    this.recordError = false;
                } catch (error) {
                    if (current()) this.recordError = true;
                }
            },

            /** Stores the run's ref with how it ended (bulk.js storedValue()). */
            remember(end) {
                // A convenience only.
                if (this.run && this.run.ref && this.runKey) writeStored(this.runKey, storedValue(this.run.ref, end));
            },

            /** Hides the recovered run and forgets its stored ref, unless a newer run replaced it under the same key. */
            forget() {
                const key = this.recovered ? this.recovered.key : null;
                const ref = this.recovered ? this.recovered.stored.ref : null;

                if (key && storedRunIs(readStored(key), ref)) removeStored(key);

                this.recovered = null;
            },

            async loadRecovered() {
                const entries = [];

                for (const key of this.recoverKeys) {
                    const stored = storedRun(readStored(key));

                    if (stored) entries.push({key, stored, record: null});
                }

                try {
                    const response = await this.http.get('/api/bulk', {handles: HANDLES});

                    if (!this.unmounted) this.recentRuns = Array.isArray(response.data && response.data.runs) ? response.data.runs : [];
                } catch (error) {
                    if (!this.unmounted) this.recentRuns = null;
                }

                const found = [];

                for (const entry of entries) {
                    try {
                        const response = await this.http.get(`/api/bulk/${entry.stored.ref}`, {handles: HANDLES});

                        found.push({...entry, record: response.data});
                    } catch (error) {
                        // The run aged out of the server: its ref is of no use.
                        if (error && error.response && error.response.status === 404) removeStored(entry.key);
                        else found.push(entry);
                    }

                    if (this.unmounted) return;
                }

                this.recovered = latestRecovered(found);
            },

            /** Closes the sheet or the finished run panel: back to idle, nothing kept. */
            close() {
                // A run that the panel's close ends was stopped by the user, not by a reload.
                if (this.phase === 'running') this.remember('stopped');

                this.nextSequence();
                this.runToken++;
                this.stopRequested = true;
                this.phase = 'idle';
                this.answer = null;
                this.run = null;
                this.record = null;
                this.recordError = false;
                this.failure = null;
                this.$emit('close');
            },

            restart() {
                const record = this.recovered ? this.recovered.record : null;
                const action = record && record.action === 'forget' ? 'forget' : 'retry';
                const source = record && record.source && typeof record.source === 'object' ? JSON.parse(JSON.stringify(record.source)) : null;

                this.forget();
                // The host starts the action of the recovered run with that run's own source, if it allows a run.
                this.$emit('restart', action, source);
            },

            clock(epoch) {
                return formatLocal(epoch);
            },

            shortName(name) {
                if (typeof name !== 'string' || name === '') return '—';

                const parts = name.split('\\');

                return parts[parts.length - 1];
            },

            shortId(id) {
                return typeof id === 'string' ? id.slice(0, 8) : '—';
            },

            totalText,
            codeText,
            itemText,
            remainingText,
            confirmationCount,
            confirmationNote,
            outcomeLabel,
            runLabel,
            formatCount,
        },

        FREEZE_NOTE,
        UNCERTAIN_NOTE,
    };
</script>

<template>
    <div class="o-bulk">
        <confirm-sheet :open="open && sheetOpen"
                       :title="sheet.title"
                       :message="sheet.message"
                       :confirm-label="sheet.label"
                       :tone="sheet.tone"
                       :selection="answer"
                       :snapshot-key="sheetKey"
                       :confirm-disabled="confirmDisabled"
                       @confirm="onConfirm"
                       @cancel="close">
            <template #default="{selection}">
                <div v-if="phase === 'freezing'" class="o-bulk__note" role="status">Counting…</div>

                <template v-else-if="phase === 'confirm' && selection">
                    <div class="o-bulk__note">
                        <svg width="16" height="16" viewBox="0 0 18 18" aria-hidden="true">
                            <circle cx="9" cy="9" r="7.3" />
                            <path d="M9 5v4.2l2.6 1.6" />
                        </svg>
                        <span>Frozen at {{ clock(selection.frozen.frozen_at) }}. {{ $options.FREEZE_NOTE }}</span>
                    </div>

                    <div v-if="selection.preview && selection.preview.length" class="o-bulk__preview">
                        <div class="o-bulk__preview-head">Preview · newest {{ selection.preview.length }} of {{ totalText(selection.frozen.total) }}</div>
                        <div v-for="row in selection.preview" :key="row.id" class="o-bulk__preview-row">
                            <span class="o-bulk__preview-name" :title="row.exception_class ? `${row.name} · ${row.exception_class}` : row.name">{{ shortName(row.name) }}<template v-if="row.exception_class"> · {{ shortName(row.exception_class) }}</template></span>
                            <span class="o-bulk__muted o-bulk__preview-time">{{ clock(row.failed_at) }}</span>
                        </div>
                    </div>

                    <label v-if="selection.frozen.action === 'forget'" class="o-bulk__typed">
                        <span>Type <strong>{{ confirmationCount(selection.frozen) }}</strong> to confirm the exact count</span>
                        <span v-if="confirmationNote(selection)" class="o-bulk__typed-note" data-typed-note>{{ confirmationNote(selection) }}</span>
                        <input v-model="typed" type="text" inputmode="numeric" autocomplete="off" spellcheck="false"
                               @keydown.enter.prevent="onConfirm">
                        <span v-if="blocker === 'total_not_exact'" class="o-bulk__problem">{{ codeText('total_not_exact') }}</span>
                    </label>
                </template>
            </template>
        </confirm-sheet>

        <section v-if="panelVisible" class="o-bulk__panel" aria-live="polite">
            <div class="o-bulk__headline">{{ headline }}</div>

            <div class="o-bulk__cells">
                <div v-for="cell in cells" :key="cell.key" class="o-bulk__cell">
                    <div class="o-bulk__cell-label">{{ cell.label }}</div>
                    <div class="o-bulk__cell-value" :class="{'o-bulk__cell-value--warn': cell.warn}">{{ cell.value }}</div>
                </div>
            </div>

            <div v-if="phase === 'running'" class="o-bulk__running">
                <span class="o-bulk__muted">{{ remainingText(run.remaining) }} · {{ run.pages }} {{ run.pages === 1 ? 'page' : 'pages' }} done</span>
                <o-button v-if="canOperate" variant="write" size="md" :disabled="stopRequested" @click="stop">
                    {{ stopRequested ? 'Stopping after this page…' : 'Stop after this page' }}
                </o-button>
            </div>

            <p v-if="failure && phase === 'error'" class="o-bulk__problem" role="alert">{{ failure.text }}</p>

            <div v-if="refused.length" class="o-bulk__block">
                <div class="o-bulk__caps">Refused</div>
                <div v-for="line in refused" :key="line.code" class="o-bulk__text">{{ line.text }}</div>
            </div>

            <div v-if="showUncertain" class="o-bulk__block">
                <div class="o-bulk__caps">Uncertain</div>
                <div class="o-bulk__text">{{ $options.UNCERTAIN_NOTE }}</div>
            </div>

            <div v-if="phase !== 'running' && recordText" class="o-bulk__block">
                <div class="o-bulk__caps">Counted pages, from the server</div>
                <div class="o-bulk__text">{{ recordText.counters }}</div>
            </div>

            <div v-if="phase !== 'running'" class="o-bulk__block">
                <div class="o-bulk__caps">{{ recordText ? recordText.operationsTitle : 'Operations' }}</div>
                <div v-if="recordText" class="o-bulk__text">{{ recordText.operations }}</div>
                <div v-else-if="recordError" class="o-bulk__text o-bulk__muted">The run record could not be read: the operation states are unknown.</div>
                <div v-else class="o-bulk__text o-bulk__muted">Reading the run record…</div>
            </div>

            <div v-if="run.results.length" class="o-bulk__members">
                <div class="o-bulk__members-head">
                    <span>Results of this tab · {{ formatCount(run.results.length) }}</span>
                    <span v-if="members.pages > 1" class="o-bulk__members-pager">
                        <button type="button" class="o-bulk__link" :disabled="members.page === 0" @click="memberPage = members.page - 1">‹ Previous</button>
                        <span class="o-bulk__muted">{{ members.page + 1 }} / {{ members.pages }}</span>
                        <button type="button" class="o-bulk__link" :disabled="members.page + 1 >= members.pages" @click="memberPage = members.page + 1">Next ›</button>
                    </span>
                </div>
                <div v-for="item in members.rows" :key="`${item.id}:${item.occurrence_id}`" class="o-bulk__member">
                    <span class="o-bulk__member-id" :title="item.id">{{ shortId(item.id) }}</span>
                    <span class="o-bulk__member-text">{{ itemText(item) }}</span>
                    <span class="o-bulk__member-outcome" :class="`o-bulk__member-outcome--${item.outcome}`">{{ outcomeLabel(item.outcome) }}</span>
                </div>
            </div>

            <div v-if="phase !== 'running'" class="o-bulk__actions">
                <o-button variant="write" @click="close">Close</o-button>
            </div>
        </section>

        <section v-if="recover && phase === 'idle' && (recovered || (hostRuns && hostRuns.length))" class="o-bulk__panel">
            <div v-if="recovered" class="o-bulk__recovered">
                <div>
                    <div class="o-bulk__recovered-title">{{ recoveredHeadline || 'The last run could not be read' }}</div>
                    <div v-if="recoveredActions.restart" class="o-bulk__muted o-bulk__small">
                        These counts come from the server. The per-member table of this tab was lost. To go on, start a new run: it freezes a new total.
                    </div>
                    <div v-else class="o-bulk__muted o-bulk__small">
                        Its record on the server could not be read, so what it acted on is unknown. Read it again before you start a new run.
                    </div>
                </div>
                <o-button v-if="recoveredActions.restart" operate variant="write" @click="restart">Start a new run…</o-button>
                <o-button v-else-if="recoveredActions.readAgain" variant="write" @click="reloadRecovered">Read again</o-button>
            </div>
            <div v-if="recoveredText" class="o-bulk__columns">
                <div class="o-bulk__block">
                    <div class="o-bulk__caps">Counted pages</div>
                    <div class="o-bulk__text">{{ recoveredText.counters }}</div>
                </div>
                <div class="o-bulk__block">
                    <div class="o-bulk__caps">{{ recoveredText.operationsTitle }}</div>
                    <div class="o-bulk__text">{{ recoveredText.operations }}</div>
                </div>
            </div>
            <div v-if="hostRuns && hostRuns.length" class="o-bulk__recent">
                <div class="o-bulk__caps">Recent runs</div>
                <div v-for="entry in hostRuns" :key="entry.ref" class="o-bulk__recent-row"
                     :class="{'o-bulk__recent-row--current': recovered && entry.ref === recovered.stored.ref}">
                    <span class="o-bulk__recent-label">{{ runLabel(entry) }}</span>
                    <span class="o-bulk__muted o-bulk__recent-actor">{{ entry.actor === null ? 'unknown actor' : entry.actor }}</span>
                    <span class="o-bulk__muted o-bulk__recent-time">{{ clock(entry.last_run_at) }}</span>
                </div>
            </div>
            <div v-if="recovered" class="o-bulk__actions">
                <button type="button" class="o-bulk__link" @click="forget">Hide</button>
            </div>
        </section>
    </div>
</template>

<style scoped>
    .o-bulk {
        font-family: var(--font-sans);
        min-width: 0;
    }

    .o-bulk__note {
        align-items: flex-start;
        background-color: var(--color-subtle);
        border: 1px solid var(--color-separator);
        border-radius: 8px;
        color: var(--color-text);
        display: flex;
        font-size: var(--text-table);
        gap: 10px;
        line-height: var(--leading-table);
        margin-bottom: 12px;
        padding: 10px 12px;
    }

    .o-bulk__note svg {
        flex-shrink: 0;
    }

    .o-bulk__note svg circle,
    .o-bulk__note svg path {
        fill: none;
        stroke: var(--color-text-secondary);
        stroke-linecap: round;
        stroke-width: 1.4;
    }

    .o-bulk__preview,
    .o-bulk__members {
        border: 1px solid var(--color-separator);
        border-radius: 8px;
        display: flex;
        flex-direction: column;
        margin-bottom: 12px;
        max-height: 260px;
        overflow: auto;
    }

    .o-bulk__members {
        max-height: none;
    }

    .o-bulk__preview-head,
    .o-bulk__members-head {
        align-items: center;
        background-color: var(--color-subtle);
        border-bottom: 1px solid var(--color-separator);
        color: var(--color-text-secondary);
        display: flex;
        flex-wrap: wrap;
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        gap: 8px;
        justify-content: space-between;
        line-height: 16px;
        min-height: 28px;
        padding: 4px 10px;
        position: sticky;
        top: 0;
    }

    .o-bulk__preview-row,
    .o-bulk__member {
        align-items: center;
        border-bottom: 1px solid var(--color-separator);
        display: flex;
        font-size: var(--text-caption);
        gap: 10px;
        line-height: 16px;
        min-height: 28px;
        padding: 4px 10px;
        /* The preview box has a maximum height: a row keeps its height and the box scrolls. */
        flex-shrink: 0;
    }

    .o-bulk__preview-row:last-child,
    .o-bulk__member:last-child {
        border-bottom: 0;
    }

    .o-bulk__preview-name,
    .o-bulk__member-text {
        color: var(--color-text);
        flex: 1 1 auto;
        min-width: 0;
        overflow-wrap: anywhere;
    }

    /* One line per preview row: the name truncates (its title has the full name and exception). */
    .o-bulk__preview-name {
        overflow: hidden;
        overflow-wrap: normal;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-bulk__preview-time {
        flex-shrink: 0;
        white-space: nowrap;
    }

    .o-bulk__member-id {
        color: var(--color-text-secondary);
        flex-shrink: 0;
        font-family: var(--font-mono);
        width: 64px;
    }

    .o-bulk__member-outcome {
        color: var(--color-text-secondary);
        flex-shrink: 0;
        font-weight: var(--font-weight-semibold);
    }

    .o-bulk__member-outcome--refused,
    .o-bulk__member-outcome--not_confirmed {
        color: var(--color-warning-text);
    }

    .o-bulk__member-outcome--uncertain {
        color: var(--color-text);
    }

    .o-bulk__typed {
        display: flex;
        flex-direction: column;
        font-size: var(--text-table);
        gap: 6px;
        line-height: var(--leading-table);
    }

    .o-bulk__typed-note {
        color: var(--color-text-secondary);
    }

    .o-bulk__typed input {
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-control);
        box-sizing: border-box;
        color: var(--color-text);
        font-family: var(--font-sans);
        font-size: var(--text-body);
        height: 30px;
        padding-inline: 10px;
        width: 160px;
    }

    .o-bulk__typed input:focus-visible {
        border-color: var(--color-accent);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-accent) 20%, transparent);
        outline: none;
    }

    .o-bulk__panel {
        background-color: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-card);
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
        gap: 12px;
        padding: 14px;
    }

    .o-bulk__headline {
        color: var(--color-text);
        font-size: var(--text-body);
        font-weight: var(--font-weight-semibold);
        line-height: var(--leading-body);
    }

    .o-bulk__cells {
        border: 1px solid var(--color-separator);
        border-radius: 8px;
        display: flex;
        flex-wrap: wrap;
        overflow: hidden;
    }

    .o-bulk__cell {
        border-right: 1px solid var(--color-separator);
        flex: 1 1 110px;
        padding: 8px 10px;
    }

    .o-bulk__cell:last-child {
        border-right: 0;
    }

    .o-bulk__cell-label {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
    }

    .o-bulk__cell-value {
        color: var(--color-text);
        font-size: var(--text-section);
        font-variant-numeric: tabular-nums;
        font-weight: var(--font-weight-semibold);
        line-height: 22px;
    }

    .o-bulk__cell-value--warn {
        color: var(--color-warning-text);
    }

    .o-bulk__running,
    .o-bulk__actions,
    .o-bulk__recovered {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        justify-content: space-between;
    }

    .o-bulk__actions {
        justify-content: flex-end;
    }

    .o-bulk__recovered {
        align-items: flex-start;
        background-color: var(--color-accent-tint);
        border-radius: 8px;
        flex-wrap: nowrap;
        padding: 10px 12px;
    }

    .o-bulk__recovered-title {
        color: var(--color-text);
        font-size: var(--text-table);
        font-weight: var(--font-weight-semibold);
        line-height: var(--leading-table);
    }

    .o-bulk__columns {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
    }

    .o-bulk__columns > .o-bulk__block {
        flex: 1 1 220px;
    }

    .o-bulk__block {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .o-bulk__caps {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        letter-spacing: var(--tracking-caps);
        line-height: 16px;
        text-transform: uppercase;
    }

    .o-bulk__text {
        color: var(--color-text);
        font-size: var(--text-table);
        line-height: var(--leading-table);
        overflow-wrap: anywhere;
    }

    .o-bulk__muted {
        color: var(--color-text-secondary);
    }

    .o-bulk__small {
        font-size: var(--text-caption);
        line-height: 16px;
    }

    .o-bulk__problem {
        color: var(--color-danger-text);
        font-size: var(--text-table);
        line-height: var(--leading-table);
        margin: 0;
    }

    .o-bulk__recent {
        border-top: 1px solid var(--color-separator);
        display: flex;
        flex-direction: column;
    }

    .o-bulk__recent .o-bulk__caps {
        padding: 5px 0;
    }

    .o-bulk__recent-row {
        align-items: center;
        border-top: 1px solid var(--color-separator);
        display: flex;
        font-size: var(--text-table);
        gap: 8px;
        line-height: var(--leading-table);
        min-height: 34px;
        padding-inline: 8px;
    }

    .o-bulk__recent-row--current {
        background-color: var(--color-accent-tint);
        font-weight: var(--font-weight-semibold);
    }

    .o-bulk__recent-label {
        flex: 1 1 auto;
        min-width: 0;
        overflow-wrap: anywhere;
    }

    .o-bulk__recent-actor {
        flex-shrink: 0;
        font-size: var(--text-caption);
        max-width: 150px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-bulk__recent-time {
        flex-shrink: 0;
        font-size: var(--text-caption);
        text-align: right;
    }

    .o-bulk__link {
        background: transparent;
        border: 0;
        color: var(--color-accent);
        cursor: pointer;
        font-family: var(--font-sans);
        font-size: var(--text-caption);
        padding: 2px;
    }

    .o-bulk__link:disabled {
        color: var(--color-text-tertiary);
        cursor: default;
    }

    .o-bulk__link:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: 1px;
    }

    .o-bulk__members-pager {
        align-items: center;
        display: flex;
        gap: 6px;
    }

    @media (max-width: 767px) {
        .o-bulk__recovered {
            flex-direction: column;
        }

        .o-bulk__recent-actor {
            display: none;
        }
    }
</style>
