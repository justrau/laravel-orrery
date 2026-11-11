<script>
    import {ANY_ERROR_STATUS, BACKGROUND_FAILURES} from '../../errors.js';
    import Button from '../../components/ui/Button.vue';
    import ConfirmSheet from '../../components/ui/ConfirmSheet.vue';
    import CursorPager from '../../components/ui/CursorPager.vue';
    import Notice from '../../components/ui/Notice.vue';
    import {setToolbar} from '../../shell/store';
    import {pageRows, phpTrim} from '../../support/lists.js';
    import {nowSeconds} from '../../support/time.js';
    import {pageAfter, shownTotal} from '../jobs/jobs.js';
    import {
        afterMonitor,
        jobsLink,
        monitorChip,
        monitorRequest,
        monitoredNotes,
        monitoredRows,
        readFailure,
        routeTag,
        stopSheet,
        tabFromQuery,
        tabItems,
        tagInputProblem,
        tagListRequest,
        tagRow,
        tagRowsEmpty,
        tagsSubtitle,
        unmonitorRequest,
        viewLookup,
        WRITE_HANDLES,
        writeFailure,
        writeResult,
        TAG_PER_PAGE,
        monitoredTagsRead,
        tagPageRead,
    } from './tags.js';
    import {tagLocation} from './tag-links.js';

    /** Both panes poll every this many seconds. */
    const POLL_SECONDS = 10;

    /** The errors that a read shows itself (the pane keeps its last data or shows its own state). */
    const SECTION_STATUSES = [400, 404, 405, 408, 409, 422, 429, 500, 502, 503, 504];

    /** A background poll also handles a timeout and a network failure, without a global alert. */
    const BACKGROUND_STATUSES = [...SECTION_STATUSES, ANY_ERROR_STATUS, ...BACKGROUND_FAILURES];


    /** The tag view before its first read. */
    function emptyView() {
        return {response: null, firstTotal: null, otherTotal: null, page: {order: 'desc', cursor: null}, asked: null, loading: false, failure: null, stale: false};
    }

    /**
     * The Tags screen.
     * The rules are in tags.js; this component renders them.
     *
     * - Left: the exact-tag lookup ("Open"; "Monitor" with the operate
     *   ability) and the monitored tags (GET /api/tags, at most 100, counts
     *   capped at 1,000). Right: the tag view of /tags/:tag (or /tags?tag=
     *   for a tag that a path cannot carry): its monitor state (the lookup
     *   of GET /api/tags?tag=), the All and Failed tabs of the list engine
     *   with their list totals, and the cursor pager.
     * - Below 768 px the list and the tag view are two screens: /tags shows
     *   the list, a tag shows its view with a back link.
     * - Monitor and Stop monitoring need the operate ability; a viewer sees
     *   no write control. Stop monitoring opens a confirmation sheet with a
     *   plain sentence; the unmonitor uses the query form of the route.
     * - Both panes poll every 10 s; a failed poll keeps the last data and
     *   says "Stale"; a failed first read shows a retry.
     */
    export default {
        components: {ConfirmSheet, CursorPager, Notice, 'o-button': Button},

        data() {
            return {
                input: '',
                inputProblem: null,
                tags: {response: null, lookupFor: null, loading: true, failure: null, stale: false},
                view: emptyView(),
                perPage: TAG_PER_PAGE,
                now: nowSeconds(),
                notice: null,
                writing: false,
                sheet: {open: false, key: 0, title: '', message: '', confirmLabel: '', selection: null},
                menuTag: null,
                pollSeconds: POLL_SECONDS,
                unmounted: false,
            };
        },

        computed: {
            selected() {
                return routeTag(this.$route.params, this.$route.query);
            },

            tag() {
                return this.selected && this.selected.valid ? this.selected.tag : null;
            },

            tab() {
                return tabFromQuery(this.$route.query);
            },

            viewKey() {
                return JSON.stringify([this.tag, this.tab]);
            },

            rows() {
                return monitoredRows(this.tags.response);
            },

            notes() {
                return monitoredNotes(this.tags.response, this.canOperate);
            },

            chip() {
                // Only the lookup that was asked for this tag: never the previous tag's state.
                return monitorChip(viewLookup(this.tags.response, this.tags.lookupFor, this.tag), this.canOperate);
            },

            total() {
                return shownTotal(this.view.response, this.view.firstTotal);
            },

            tabs() {
                return this.tab === 'failed'
                    ? tabItems('failed', this.view.otherTotal, this.total)
                    : tabItems('all', this.total, this.view.otherTotal);
            },

            jobRows() {
                const response = this.view.response;

                return response ? pageRows(response.jobs, this.view.page.order, 'desc').map(row => tagRow(row, this.tab, this.now)) : [];
            },

            pagerPage() {
                return this.view.response ? {order: this.view.page.order, response: this.view.response} : null;
            },

            orderText() {
                return this.tab === 'failed' ? 'newest failure first' : 'newest push first';
            },
        },

        watch: {
            viewKey() {
                this.menuTag = null;
                // The pending reads of the previous tag or tab change nothing.
                this.startRequest('view');
                this.startRequest('other');
                this.view = emptyView();
                this.loadTags();
                this.loadView(this.view.page);
            },

            tag(tag) {
                // A notice belongs to its tag: one about another tag goes.
                if (this.notice && this.notice.tag !== tag) this.notice = null;
            },
        },

        created() {
            setToolbar({title: 'Tags', subtitle: tagsSubtitle(nowSeconds())});
            this.loadTags();
            this.loadView(this.view.page);
        },

        mounted() {
            this.clock = setInterval(() => {
                this.now = nowSeconds();
            }, 5000);
        },

        beforeUnmount() {
            this.unmounted = true;
            clearInterval(this.clock);
            this.closeMenu(false);
            // A read that settles after unmount changes nothing.
            this.startRequest('tags');
            this.startRequest('view');
            this.startRequest('other');
        },

        methods: {
            api(path) {
                return Orrery.basePath + path;
            },

            /** The monitored tags, and the lookup of the selected tag. */
            async loadTags({background = false} = {}) {
                const sequence = this.startRequest('tags');
                const lookupFor = this.tag;

                if (!background) this.tags.loading = true;

                try {
                    const read = monitoredTagsRead(lookupFor);
                    const response = await this.$http.get(this.api(read.url), {
                        params: read.params,
                        handles: background ? BACKGROUND_STATUSES : SECTION_STATUSES,
                    });

                    if (!this.isCurrentRequest('tags', sequence)) return;

                    this.tags = {response: response.data, lookupFor, loading: false, failure: null, stale: false};
                } catch (error) {
                    if (!this.isCurrentRequest('tags', sequence)) return;

                    if (this.tags.response) {
                        this.tags.stale = true;
                    } else {
                        this.tags.failure = readFailure(error);
                    }
                } finally {
                    if (this.isCurrentRequest('tags', sequence)) this.tags.loading = false;
                }
            },

            /** A page of the selected tag's tab, then the other tab's list total. */
            async loadView(page, {background = false} = {}) {
                if (this.tag === null) return;

                const sequence = this.startRequest('view');
                const request = tagPageRead(this.tag, this.tab, page, this.perPage);

                // The page that a retry asks again.
                this.view.asked = page;

                if (!background) this.view.loading = true;

                try {
                    const response = await this.$http.get(this.api(request.url), {
                        params: request.params,
                        handles: background ? BACKGROUND_STATUSES : SECTION_STATUSES,
                    });

                    if (!this.isCurrentRequest('view', sequence)) return;

                    const data = response.data;

                    this.view.page = pageAfter(page, data, null).page;
                    this.view.response = data;

                    // The tab total, kept for the later pages (they count only from their own start).
                    if (typeof data.prev_cursor !== 'string') this.view.firstTotal = data.total || null;

                    this.view.failure = null;
                    this.view.stale = false;
                    this.now = nowSeconds();
                } catch (error) {
                    if (!this.isCurrentRequest('view', sequence)) return;

                    if (background && this.view.response) {
                        this.view.stale = true;
                    } else {
                        this.view.failure = readFailure(error);
                        this.view.response = null;
                    }
                } finally {
                    if (this.isCurrentRequest('view', sequence)) this.view.loading = false;
                }

                // Inside the poll's single flight: the next poll waits for it.
                if (this.isCurrentRequest('view', sequence)) await this.loadOtherTotal();
            },

            /** The other tab's list total: one row of its list. Unknown ("—") when it fails. */
            async loadOtherTotal() {
                if (this.tag === null) return;

                const sequence = this.startRequest('other');
                const request = tagListRequest(this.tag, this.tab === 'failed' ? 'all' : 'failed', null, 1);

                try {
                    const response = await this.$http.get(this.api(request.endpoint), {params: request.params, handles: BACKGROUND_STATUSES});

                    if (this.isCurrentRequest('other', sequence)) this.view.otherTotal = response.data.total || null;
                } catch (error) {
                    if (this.isCurrentRequest('other', sequence)) this.view.otherTotal = null;
                }
            },

            poll() {
                const reads = [];

                if (!this.tags.loading) reads.push(this.loadTags({background: true}));
                if (this.tag !== null && !this.view.loading) reads.push(this.loadView(this.view.page, {background: true}));

                return Promise.all(reads);
            },

            retryTags() {
                this.tags.failure = null;
                this.loadTags();
            },

            retryView() {
                this.view.failure = null;
                this.loadView(this.view.asked || this.view.page);
            },

            navigate(request) {
                if (request) this.loadView(request);
            },

            setPerPage(value) {
                this.perPage = value;
                this.view.firstTotal = null;
                this.loadView({order: 'desc', cursor: null});
            },

            /** "Open": the tag view of the typed tag. */
            openTyped() {
                this.inputProblem = tagInputProblem(this.input);

                if (this.inputProblem !== null) return;

                this.$router.push(tagLocation(routeTag({tag: this.input}, {}).tag)).catch(() => {});
            },

            /** "Monitor" of the lookup: the typed tag, then its view. */
            async monitorTyped() {
                this.inputProblem = tagInputProblem(this.input);

                if (this.inputProblem !== null) return;

                const route = this.$route.fullPath;
                const answer = await this.write('monitor', monitorRequest(this.input), 'list', phpTrim(this.input));
                const next = afterMonitor(answer, {unmounted: this.unmounted, routeChanged: this.$route.fullPath !== route});

                if (next !== null) {
                    this.input = '';
                    this.$router.push(next).catch(() => {});
                }
            },

            monitorSelected() {
                this.write('monitor', monitorRequest(this.tag), 'view', this.tag);
            },

            /**
             * Opens the Stop monitoring sheet. From a row menu, the menu
             * closes first with the focus back on the row's "…" button, and
             * the sheet opens on the next tick, so that button is its opener
             * (the sheet gives the focus back to it).
             */
            openStop(tag, where) {
                const fromMenu = this.menuTag !== null;
                const open = () => {
                    this.sheet = {...stopSheet(tag), open: true, key: this.sheet.key + 1, where};
                };

                if (!fromMenu) {
                    open();

                    return;
                }

                this.closeMenu(true);
                this.$nextTick(() => this.$nextTick(open));
            },

            cancelStop() {
                if (!this.writing) this.sheet = {...this.sheet, open: false};
            },

            async confirmStop(selection) {
                await this.write('stop', unmonitorRequest(selection.tag), this.sheet.where, selection.tag);
                this.sheet = {...this.sheet, open: false};
            },

            /**
             * Sends a monitor or unmonitor, shows its answer in the pane that
             * started it, and reads both panes again (the write is
             * synchronous: committed when the answer returns, also after a
             * 503 the state is read again).
             */
            async write(action, request, where, tag) {
                if (this.writing) return null;

                this.writing = true;
                this.notice = null;

                let answer = null;

                try {
                    const response = await this.$http.request({
                        method: request.method,
                        url: this.api(request.url),
                        data: request.data,
                        params: request.params,
                        handles: WRITE_HANDLES,
                    });

                    answer = response.data;
                    this.notice = {...writeResult(action, answer), where, tag: typeof answer.tag === 'string' ? answer.tag : tag};
                } catch (error) {
                    // A failure that the write does not handle is the global alert's (errors.js): show nothing twice.
                    const failure = writeFailure(action, error);

                    this.notice = failure === null ? null : {...failure, where, tag};
                } finally {
                    this.writing = false;
                }

                // A screen that is gone reads nothing more.
                if (!this.unmounted) this.loadTags({background: true});

                return answer;
            },

            dismissNotice() {
                this.notice = null;
            },

            toggleMenu(tag) {
                if (this.menuTag === tag) {
                    this.closeMenu(true);

                    return;
                }

                this.closeMenu(false);
                this.menuTag = tag;
                document.addEventListener('keydown', this.onMenuKeydown);
                document.addEventListener('click', this.onDocumentClick, true);
                this.$nextTick(() => {
                    const item = this.$el.querySelector('[data-menu-item]');

                    if (item) item.focus();
                });
            },

            closeMenu(returnFocus) {
                const tag = this.menuTag;

                this.menuTag = null;
                document.removeEventListener('keydown', this.onMenuKeydown);
                document.removeEventListener('click', this.onDocumentClick, true);

                if (returnFocus && tag !== null) {
                    this.$nextTick(() => {
                        const button = [...this.$el.querySelectorAll('[data-menu-button]')].find(element => element.dataset.tag === tag);

                        if (button) button.focus();
                    });
                }
            },

            onMenuKeydown(event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    this.closeMenu(true);
                }
            },

            onDocumentClick(event) {
                if (event.target && event.target.closest && event.target.closest('.o-tags__menu, [data-menu-button]')) return;

                this.closeMenu(false);
            },

            isSelected(tag) {
                return this.tag !== null && tag === this.tag;
            },

            tagLocation,
            jobsLink,
            tagRowsEmpty,
        },
    };
</script>

<template>
    <div class="o-tags" :class="{'o-tags--selected': selected !== null}">
        <poll :request="poll" :interval="pollSeconds" :immediate="false" />

        <section class="o-tags__list" aria-label="Monitored tags">
            <form class="o-tags__lookup" @submit.prevent="openTyped">
                <label class="o-tags__caps" for="o-tags-input">{{ canOperate ? 'Look up or monitor a tag' : 'Look up a tag' }}</label>
                <div class="o-tags__lookup-row">
                    <input id="o-tags-input" v-model="input" class="o-tags__input" type="text" autocomplete="off" spellcheck="false"
                           :aria-invalid="inputProblem ? 'true' : 'false'" aria-describedby="o-tags-input-hint" placeholder="customer:88" @input="inputProblem = null">
                    <o-button size="sm" @click="openTyped">Open</o-button>
                    <o-button variant="primary" size="sm" operate :busy="writing" @click="monitorTyped">Monitor</o-button>
                </div>
                <div id="o-tags-input-hint" class="o-tags__hint" :class="{'o-tags__hint--problem': inputProblem}">
                    {{ inputProblem || 'Exact tag, as the job stored it. Tags are not redacted.' }}
                </div>
            </form>

            <notice v-if="notice && notice.where === 'list'" :tone="notice.tone === 'success' ? 'success' : 'danger'" :title="notice.text">
                <template #actions><o-button size="sm" @click="dismissNotice">Dismiss</o-button></template>
            </notice>

            <div v-if="tags.loading && !tags.response && !tags.failure" class="o-tags__state">Loading the monitored tags…</div>

            <div v-else-if="tags.failure && !tags.response" class="o-tags__state o-tags__state--error" role="alert">
                <div class="o-tags__state-title">The monitored tags could not be loaded</div>
                <div>{{ tags.failure }}</div>
                <o-button size="sm" @click="retryTags">Try again</o-button>
            </div>

            <template v-else-if="tags.response">
                <div v-if="tags.stale" class="o-tags__stale" role="status">Stale: the last read failed; showing the data from before.</div>

                <div v-if="notes.empty" class="o-tags__state">{{ notes.empty }}</div>

                <div v-else class="o-tags__table" role="table" aria-label="Monitored tags">
                    <div class="o-tags__head" role="row">
                        <span class="o-tags__cell-tag" role="columnheader">Monitored tag</span>
                        <span class="o-tags__cell-num" role="columnheader">Jobs</span>
                        <span class="o-tags__cell-failed" role="columnheader">Failed</span>
                        <span class="o-tags__cell-menu" role="columnheader"><span class="o-tags__sr">Actions</span></span>
                    </div>
                    <div v-for="row in rows" :key="row.tag" class="o-tags__row" :class="{'o-tags__row--selected': isSelected(row.tag)}" role="row">
                        <router-link class="o-tags__cell-tag o-tags__tag-link" :to="tagLocation(row.tag)" :title="row.tag" role="cell"
                                     :aria-current="isSelected(row.tag) ? 'page' : null">{{ row.tag }}</router-link>
                        <span class="o-tags__cell-num" role="cell">{{ row.jobs }}</span>
                        <span class="o-tags__cell-failed" :class="`o-tags__count--${row.failedTone}`" role="cell">{{ row.failed }}</span>
                        <span class="o-tags__cell-menu" role="cell">
                            <template v-if="canOperate">
                                <button type="button" class="o-tags__menu-button" data-menu-button :data-tag="row.tag" aria-haspopup="menu"
                                        :aria-expanded="menuTag === row.tag ? 'true' : 'false'" :aria-label="`Actions for ${row.tag}`" @click="toggleMenu(row.tag)">
                                    <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><circle cx="3" cy="7" r="1.1" /><circle cx="7" cy="7" r="1.1" /><circle cx="11" cy="7" r="1.1" /></svg>
                                </button>
                                <div v-if="menuTag === row.tag" class="o-tags__menu" role="menu">
                                    <button type="button" class="o-tags__menu-item" role="menuitem" data-menu-item @click="openStop(row.tag, 'list')">Stop monitoring…</button>
                                </div>
                            </template>
                        </span>
                    </div>
                </div>

                <div v-if="notes.truncated" class="o-tags__hint">{{ notes.truncated }}</div>
                <div v-if="!notes.empty" class="o-tags__hint">Counts stop at 1,000 (“1,000+”); the tag view shows the full list total. “—” means the count could not be read, not zero. Up to 100 monitored tags are listed.</div>
            </template>
        </section>

        <section class="o-tags__view" aria-label="Tag view">
            <router-link v-if="selected !== null" to="/tags" class="o-tags__back">
                <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><path d="M8.5 3L4.5 7l4 4" /></svg>
                Tags
            </router-link>

            <div v-if="selected === null" class="o-tags__placeholder">
                <div class="o-tags__state-title">Choose a tag</div>
                <div>Open a monitored tag, or look up any tag: every tag of every retained job can be viewed.</div>
            </div>

            <div v-else-if="!selected.valid" class="o-tags__state o-tags__state--error" role="alert">
                <div class="o-tags__state-title">This is not a tag</div>
                <div>A tag is not blank and has no NUL character. Look up the tag again.</div>
            </div>

            <template v-else>
                <div class="o-tags__header">
                    <svg class="o-tags__icon" width="20" height="20" viewBox="0 0 16 16" aria-hidden="true">
                        <path d="M2.5 3.5v4.2l6.3 6.3 5-5-6.3-6.3H3.5z" />
                        <circle cx="5.4" cy="5.4" r="1" />
                    </svg>
                    <h2 class="o-tags__title">{{ tag }}</h2>
                    <span class="o-tags__chip" :class="`o-tags__chip--${chip.state}`">{{ chip.label }}</span>
                    <span class="o-tags__grow"></span>
                    <o-button v-if="chip.action === 'stop'" class="o-tags__stop" size="sm" operate :busy="writing" @click="openStop(tag, 'view')">Stop monitoring…</o-button>
                    <o-button v-if="chip.action === 'monitor'" variant="primary" size="sm" operate :busy="writing" @click="monitorSelected">Monitor</o-button>
                </div>

                <notice v-if="notice && notice.where === 'view'" :tone="notice.tone === 'success' ? 'success' : 'danger'" :title="notice.text">
                    <template #actions><o-button size="sm" @click="dismissNotice">Dismiss</o-button></template>
                </notice>

                <div class="o-tags__tabs">
                    <nav class="o-tags__tablist" aria-label="Tag view tabs">
                        <router-link v-for="item in tabs" :key="item.key" class="o-tags__tab" :class="{'o-tags__tab--active': item.active}"
                                     :to="tagLocation(tag, item.key)" :aria-current="item.active ? 'page' : null">
                            <span>{{ item.label }}</span>
                            <span class="o-tags__tab-count" :class="{'o-tags__count--danger': item.tone === 'danger'}">{{ item.count }}</span>
                        </router-link>
                    </nav>
                    <span class="o-tags__grow"></span>
                    <span class="o-tags__meta">
                        {{ orderText }} ·
                        <router-link :to="jobsLink(tag, tab)" class="o-tags__jobs-link">Open in Jobs ›</router-link>
                        <span v-if="view.stale" class="o-tags__stale-inline"> · Stale</span>
                    </span>
                </div>

                <div v-if="view.loading && !view.response && !view.failure" class="o-tags__state">Loading the jobs of this tag…</div>

                <div v-else-if="view.failure && !view.response" class="o-tags__state o-tags__state--error" role="alert">
                    <div class="o-tags__state-title">The jobs of this tag could not be loaded</div>
                    <div>{{ view.failure }}</div>
                    <o-button size="sm" @click="retryView">Try again</o-button>
                </div>

                <template v-else-if="view.response">
                    <div v-if="!jobRows.length" class="o-tags__state">{{ tagRowsEmpty(tab) }}</div>

                    <div v-else class="o-tags__jobs" role="table" :aria-label="tab === 'failed' ? 'Failed jobs with this tag' : 'Jobs with this tag'">
                        <div class="o-tags__jobs-head" role="row">
                            <span class="o-tags__col-status" role="columnheader">Status</span>
                            <span class="o-tags__col-job" role="columnheader">Job</span>
                            <span class="o-tags__col-queue" role="columnheader">Queue</span>
                            <span class="o-tags__col-time" role="columnheader">{{ tab === 'failed' ? 'Failed at ▾' : 'Pushed at ▾' }}</span>
                        </div>
                        <component :is="row.link ? 'router-link' : 'div'" v-for="(row, index) in jobRows" :key="index" :to="row.link || undefined"
                                   class="o-tags__job" role="row">
                            <span class="o-tags__col-status" role="cell"><span class="o-tags__status" :class="`o-tags__status--${row.chip.tone}`">{{ row.chip.label }}</span></span>
                            <span class="o-tags__col-job" role="cell" :title="row.name">{{ row.name }}</span>
                            <span class="o-tags__col-queue" role="cell" :title="row.queueTitle">{{ row.queue }}</span>
                            <span class="o-tags__col-time" role="cell" :title="row.time.title">{{ row.time.primary }}</span>
                        </component>
                    </div>

                    <cursor-pager v-if="jobRows.length || pagerPage.response.prev_cursor || pagerPage.response.next_cursor" :page="pagerPage" :rows="jobRows.length" :total="total" :per-page="perPage" :loading="view.loading"
                                  @navigate="navigate" @update:per-page="setPerPage" />
                </template>
            </template>
        </section>

        <confirm-sheet :open="sheet.open" :title="sheet.title" :message="sheet.message" :confirm-label="sheet.confirmLabel"
                       :selection="sheet.selection" :busy="writing" :snapshot-key="sheet.key" @confirm="confirmStop" @cancel="cancelStop" />
    </div>
</template>

<style scoped>
    .o-tags {
        display: flex;
        flex: 1 1 0%;
        font-family: var(--font-sans);
        font-size: var(--text-caption);
        line-height: 16px;
        min-height: 0;
        overflow-wrap: anywhere;
    }

    .o-tags__list {
        border-right: 1px solid var(--color-separator);
        box-sizing: border-box;
        display: flex;
        flex: 0 0 420px;
        flex-direction: column;
        gap: 14px;
        min-width: 0;
        overflow-y: auto;
        padding: 16px;
    }

    .o-tags__view {
        box-sizing: border-box;
        container: tagview / inline-size;
        display: flex;
        flex: 1 1 0%;
        flex-direction: column;
        gap: 14px;
        min-width: 0;
        overflow-y: auto;
        padding: 18px 24px;
    }

    .o-tags__lookup {
        display: flex;
        flex-direction: column;
        gap: 6px;
        margin: 0;
    }

    .o-tags__caps {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        letter-spacing: var(--tracking-caps);
        text-transform: uppercase;
    }

    .o-tags__lookup-row {
        display: flex;
        gap: 8px;
    }

    .o-tags__input {
        background: var(--color-bg);
        border: 1px solid color-mix(in srgb, var(--color-text) 18%, transparent);
        border-radius: var(--radius-control);
        box-sizing: border-box;
        color: var(--color-text);
        flex: 1 1 0%;
        font-family: var(--font-mono);
        font-size: var(--text-caption);
        height: 28px;
        min-width: 0;
        padding: 0 8px;
    }

    .o-tags__input:focus {
        border-color: var(--color-accent);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-accent) 20%, transparent);
        outline: none;
    }

    .o-tags__input[aria-invalid='true'] {
        border-color: var(--color-danger);
    }

    .o-tags__hint {
        color: var(--color-text-secondary);
    }

    .o-tags__hint--problem {
        color: var(--color-danger-text);
    }

    .o-tags__table,
    .o-tags__jobs {
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-card);
        display: flex;
        flex-direction: column;
        flex-shrink: 0;
        overflow: clip;
    }

    .o-tags__head,
    .o-tags__jobs-head {
        align-items: center;
        background: var(--color-subtle);
        border-bottom: 1px solid var(--color-separator);
        color: var(--color-text-secondary);
        display: flex;
        font-weight: var(--font-weight-semibold);
        height: 30px;
        padding: 0 14px;
    }

    .o-tags__row {
        align-items: center;
        border-bottom: 1px solid var(--color-separator);
        display: flex;
        min-height: 40px;
        padding: 0 14px;
        position: relative;
    }

    .o-tags__row:last-child,
    .o-tags__job:last-child {
        border-bottom: 0;
    }

    .o-tags__row--selected {
        background: var(--color-accent-tint);
        box-shadow: inset 3px 0 0 var(--color-accent);
    }

    .o-tags__cell-tag {
        flex: 1 1 0%;
        min-width: 0;
    }

    .o-tags__tag-link {
        color: var(--color-text);
        font-family: var(--font-mono);
        overflow: hidden;
        padding: 12px 0;
        text-decoration: none;
        text-overflow: ellipsis;
        white-space: pre;
    }

    .o-tags__tag-link:hover {
        color: var(--color-accent);
    }

    .o-tags__row--selected .o-tags__tag-link {
        font-weight: var(--font-weight-semibold);
    }

    .o-tags__cell-num,
    .o-tags__cell-failed {
        flex: 0 0 auto;
        font-size: var(--text-table);
        line-height: 18px;
        text-align: right;
    }

    .o-tags__cell-num { width: 70px; color: var(--color-text); }
    .o-tags__cell-failed { width: 60px; }
    .o-tags__head .o-tags__cell-num,
    .o-tags__head .o-tags__cell-failed { font-size: var(--text-caption); line-height: 16px; color: var(--color-text-secondary); }

    .o-tags__cell-menu {
        display: flex;
        flex: 0 0 28px;
        justify-content: flex-end;
        position: relative;
    }

    .o-tags__count--danger { color: var(--color-danger-text); font-weight: var(--font-weight-semibold); }
    .o-tags__count--muted { color: var(--color-text-tertiary); }

    .o-tags__menu-button {
        align-items: center;
        background: none;
        border: 0;
        border-radius: var(--radius-control);
        color: var(--color-text-secondary);
        cursor: pointer;
        display: flex;
        height: 24px;
        justify-content: center;
        padding: 0;
        width: 24px;
    }

    .o-tags__menu-button svg { fill: currentColor; }
    .o-tags__menu-button:hover { background: var(--color-sidebar); }

    .o-tags__menu {
        background: var(--color-bg);
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-control);
        box-shadow: var(--shadow-popover);
        padding: 4px;
        position: absolute;
        right: 0;
        top: 28px;
        z-index: 5;
    }

    .o-tags__menu-item {
        background: none;
        border: 0;
        border-radius: 4px;
        color: var(--color-danger-text);
        cursor: pointer;
        font: inherit;
        font-size: var(--text-table);
        padding: 6px 10px;
        text-align: left;
        white-space: nowrap;
    }

    .o-tags__menu-item:hover,
    .o-tags__menu-item:focus-visible { background: var(--color-danger-tint); }

    .o-tags__state {
        color: var(--color-text-secondary);
        display: flex;
        flex-direction: column;
        font-size: var(--text-table);
        gap: 8px;
        line-height: 18px;
        padding: 12px 0;
    }

    .o-tags__state--error { color: var(--color-text); }
    .o-tags__state--error > :deep(.o-button) { align-self: flex-start; }
    .o-tags__state-title { color: var(--color-text); font-size: var(--text-body); font-weight: var(--font-weight-semibold); }

    .o-tags__stale,
    .o-tags__stale-inline { color: var(--color-warning-text); }

    .o-tags__placeholder {
        color: var(--color-text-secondary);
        display: flex;
        flex-direction: column;
        font-size: var(--text-table);
        gap: 6px;
        line-height: 18px;
        margin: auto;
        max-width: 360px;
        text-align: center;
    }

    .o-tags__back {
        align-items: center;
        color: var(--color-accent);
        display: none;
        font-size: var(--text-body);
        gap: 2px;
        line-height: 18px;
        text-decoration: none;
    }

    .o-tags__back svg { fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: 1.6; }

    .o-tags__header {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .o-tags__icon { flex-shrink: 0; }
    .o-tags__icon path { fill: none; stroke: var(--color-accent); stroke-linejoin: round; stroke-width: 1.3; }
    .o-tags__icon circle { fill: var(--color-accent); }

    .o-tags__title {
        color: var(--color-text);
        font-family: var(--font-mono);
        font-size: var(--text-section);
        font-weight: var(--font-weight-semibold);
        line-height: 24px;
        margin: 0;
        min-width: 0;
        white-space: pre-wrap;
    }

    .o-tags__chip {
        align-items: center;
        border-radius: 10px;
        display: inline-flex;
        flex-shrink: 0;
        font-size: var(--text-table);
        font-weight: var(--font-weight-semibold);
        height: 22px;
        line-height: 18px;
        padding: 0 9px;
    }

    .o-tags__chip--monitored { background: var(--color-accent-tint); color: var(--color-accent); }
    .o-tags__chip--not_monitored { background: var(--color-sidebar); color: var(--color-text-secondary); }
    .o-tags__chip--unknown { background: var(--color-warning-tint); color: var(--color-warning-text); }

    .o-tags__grow { flex: 1 1 0%; }

    .o-tags__tabs {
        align-items: flex-end;
        border-bottom: 1px solid var(--color-separator);
        display: flex;
        flex-wrap: wrap;
        gap: 22px;
    }

    .o-tags__tablist {
        display: flex;
        gap: 22px;
    }

    .o-tags__tab {
        align-items: baseline;
        border-bottom: 2px solid transparent;
        color: var(--color-text);
        display: flex;
        font-size: var(--text-body);
        gap: 6px;
        line-height: 18px;
        padding-bottom: 7px;
        text-decoration: none;
    }

    .o-tags__tab--active {
        border-bottom-color: var(--color-accent);
        font-weight: var(--font-weight-semibold);
    }

    .o-tags__tab-count {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        font-weight: var(--font-weight-regular);
        line-height: 16px;
    }

    .o-tags__tab-count.o-tags__count--danger { color: var(--color-danger-text); font-weight: var(--font-weight-semibold); }

    .o-tags__meta {
        color: var(--color-text-tertiary);
        padding-bottom: 10px;
    }

    .o-tags__jobs-link { color: var(--color-accent); text-decoration: none; }

    .o-tags__job {
        align-items: center;
        border-bottom: 1px solid var(--color-separator);
        color: var(--color-text);
        display: flex;
        font-size: var(--text-table);
        line-height: 18px;
        min-height: 40px;
        padding: 0 14px;
        text-decoration: none;
    }

    a.o-tags__job:hover { background: var(--color-subtle); }

    .o-tags__col-status { flex: 0 0 96px; display: flex; }
    .o-tags__col-job { flex: 1 1 0%; font-weight: var(--font-weight-semibold); min-width: 0; overflow: hidden; padding-right: 12px; text-overflow: ellipsis; white-space: pre; }
    .o-tags__col-queue { flex: 0 0 90px; overflow: hidden; padding-right: 8px; text-overflow: ellipsis; white-space: pre; }
    .o-tags__col-time { flex: 0 0 110px; text-align: right; }
    .o-tags__jobs-head .o-tags__col-job { font-weight: var(--font-weight-semibold); }
    .o-tags__jobs-head .o-tags__col-time { color: var(--color-text); }

    .o-tags__status {
        border-radius: 4px;
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        line-height: 16px;
        padding: 0 6px;
    }

    .o-tags__status--neutral { background: var(--color-sidebar); color: var(--color-text-secondary); }
    .o-tags__status--accent { background: var(--color-accent-tint); color: var(--color-accent); }
    .o-tags__status--success { background: var(--color-success-tint); color: var(--color-success-text); }
    .o-tags__status--danger { background: var(--color-danger-tint); color: var(--color-danger-text); }
    .o-tags__status--warning { background: var(--color-warning-tint); color: var(--color-warning-text); }

    .o-tags__sr {
        clip: rect(0 0 0 0);
        height: 1px;
        overflow: hidden;
        position: absolute;
        white-space: nowrap;
        width: 1px;
    }

    .o-tags a:focus-visible,
    .o-tags button:focus-visible {
        outline: 2px solid var(--color-accent);
        outline-offset: 1px;
    }

    @media (min-width: 1800px) {
        .o-tags__list { flex-basis: 480px; }
    }

    /* Below 768 px the list and the tag view are two screens. */
    @media (max-width: 767px) {
        .o-tags__list { border-right: 0; flex: 1 1 0%; }
        .o-tags--selected .o-tags__list { display: none; }
        .o-tags:not(.o-tags--selected) .o-tags__view { display: none; }
        .o-tags__view { padding: 14px 16px; gap: 12px; }
        .o-tags__back { display: inline-flex; }

        .o-tags__stop { background: none; border-color: transparent; color: var(--color-danger-text); padding-left: 0; padding-right: 0; }
        .o-tags__header { gap: 6px 10px; }

        .o-tags__tabs { border-bottom: 0; gap: 8px; }
        .o-tags__tablist { background: var(--color-separator); border-radius: 7px; flex: 1 1 100%; gap: 0; padding: 2px; }
        .o-tags__tab { border: 0; border-radius: 5px; flex: 1 1 0%; font-size: var(--text-table); justify-content: center; padding: 5px 0; }
        .o-tags__tab--active { background: var(--color-bg); box-shadow: var(--shadow-raised); }
        .o-tags__meta { padding-bottom: 0; }

    }

    /*
     * A narrow tag view (below 768 px, and beside the list at 768–1023 px):
     * each row as status chip and time, then the name, then the queue
     * on a phone, so no column is squeezed.
     */
    @container tagview (max-width: 600px) {
        .o-tags__jobs { border: 0; border-radius: 0; border-top: 1px solid var(--color-separator); }
        .o-tags__jobs-head { display: none; }
        .o-tags__job { display: grid; gap: 4px; grid-template-columns: 1fr auto; padding: 10px 0; }
        .o-tags__job:last-child { border-bottom: 1px solid var(--color-separator); }
        .o-tags__job .o-tags__col-status { grid-column: 1; grid-row: 1; }
        .o-tags__job .o-tags__col-time { grid-column: 2; grid-row: 1; color: var(--color-text-secondary); font-size: var(--text-caption); }
        .o-tags__job .o-tags__col-job { grid-column: 1 / 3; grid-row: 2; padding-right: 0; }
        .o-tags__job .o-tags__col-queue { grid-column: 1 / 3; grid-row: 3; color: var(--color-text-secondary); font-size: var(--text-caption); padding-right: 0; }
    }
</style>
