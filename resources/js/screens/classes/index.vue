<script>
    import {ANY_ERROR_STATUS, BACKGROUND_FAILURES} from '../../errors.js';
    import BarChart from '../../components/ui/BarChart.vue';
    import Button from '../../components/ui/Button.vue';
    import Icon from '../../components/ui/Icon.vue';
    import Notice from '../../components/ui/Notice.vue';
    import Sparkline from '../../components/ui/Sparkline.vue';
    import InspectorLayout from '../../shell/InspectorLayout.vue';
    import ToolbarControls from '../../shell/ToolbarControls.vue';
    import {setToolbar} from '../../shell/store';
    import {
        POLL_SECONDS,
        SPANS,
        WINDOWS,
        chartSeries,
        classFailure,
        classesTitle,
        compactParts,
        countText,
        countTitle,
        emptyText,
        findClass,
        fullMinutes,
        inText,
        jobsLink,
        lastFullMinuteText,
        normalizeClasses,
        normalizeSeries,
        notListedText,
        peakText,
        queryFor,
        runtimeText,
        selectedFromQuery,
        seriesParams,
        unexpectedAnswer,
        unknownAnnouncement,
        unknownNotice,
        windowFromQuery,
        windowLine,
    } from './classes';

    /** The statuses that a read of this screen shows itself (no global alert on every poll). */
    const SECTION_STATUSES = [400, 403, 404, 405, 408, 409, 422, 429, 500, 502, 503, 504];

    /** A poll also handles a timeout and a network failure: its section keeps its last data and says "Stale". */
    const BACKGROUND_STATUSES = [...SECTION_STATUSES, ANY_ERROR_STATUS, ...BACKGROUND_FAILURES];

    /** Below this width a row opens its chart inside itself. */
    const NARROW_QUERY = '(max-width: 767px)';

    /**
     * The Job classes view.
     *
     * - The table over a window of 15 or 60 minutes (the URL's `minutes`)
     *   from GET /api/classes; the selected class (the URL's `class`, byte
     *   for byte) opens the inspector with its per-minute charts over 1 h or
     *   24 h from GET /api/classes/series.
     * - Below 768 px a row opens its 1 h chart inside itself; Escape closes
     *   the inspector or the row and returns focus to the row.
     * - One poll every 15 s reads the table and the selected class's series.
     *   A response applies only while its request is the newest of its kind;
     *   a failed poll keeps the last data and says "Stale".
     * - The rules (what each cell says, unknown and lower bounds, the link to
     *   the Jobs list) are in classes.js.
     */
    export default {
        components: {BarChart, Icon, InspectorLayout, Notice, Sparkline, ToolbarControls, 'o-button': Button},

        data() {
            return {
                pollSeconds: POLL_SECONDS,
                windows: WINDOWS,
                spans: Object.keys(SPANS),
                span: '1h',
                data: null,
                dataWindow: null,
                loadError: null,
                stale: null,
                series: null,
                seriesKey: null,
                seriesError: null,
                seriesStale: null,
                narrow: false,
            };
        },

        computed: {
            window() {
                return windowFromQuery(this.$route.query);
            },

            selected() {
                return selectedFromQuery(this.$route.query);
            },

            /** The span of the charts: 1 h only below 768 px. */
            effectiveSpan() {
                return this.narrow ? '1h' : this.span;
            },

            currentSeriesKey() {
                return this.selected === null ? null : JSON.stringify([this.selected, this.effectiveSpan]);
            },

            /** The series of the selection, never the series of another class or span. */
            shownSeries() {
                return this.series && this.seriesKey === this.currentSeriesKey ? this.series : null;
            },

            /** The table's data for the window on screen. */
            shown() {
                return this.data && this.dataWindow === this.window ? this.data : null;
            },

            selectedRow() {
                return this.shown && this.selected !== null ? findClass(this.shown.classes, this.selected) : null;
            },

            chart() {
                return chartSeries(this.shownSeries ? this.shownSeries.retentionFrom : null);
            },

            title() {
                return this.shown ? classesTitle(this.shown.classes.length, this.shown.truncated) : null;
            },

            /** The live text of the uncounted minutes: it changes only with their count. */
            announcement() {
                return this.shown ? unknownAnnouncement(this.shown.unknownMinutes) : '';
            },

            /** The whole minutes of the series for the sparklines (the partial minute left out), or null. */
            sparkMinutes() {
                return fullMinutes(this.shownSeries);
            },

            /** The uncounted minutes of the window, named once (P-5), or null. */
            notice() {
                return this.shown ? unknownNotice(this.shown.unknownMinutes) : null;
            },

            /** The Jobs list on the selected class over the window of its chart (else of the table). */
            jobsTarget() {
                if (this.selected === null) return null;

                return jobsLink(this.selected, this.shownSeries || this.shown);
            },

            shortSelected() {
                if (this.selected === null) return null;

                const parts = this.selected.split('\\');

                return parts[parts.length - 1] || this.selected;
            },
        },

        watch: {
            window() {
                this.loadClasses(false);
            },

            currentSeriesKey() {
                this.loadSeries(false);
            },
        },

        created() {
            setToolbar({title: 'Job classes', subtitle: 'Counted per minute across every queue'});
        },

        mounted() {
            if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
                this.media = window.matchMedia(NARROW_QUERY);
                this.narrow = this.media.matches;
                this.onMedia = event => {
                    this.narrow = event.matches;
                };
                this.media.addEventListener('change', this.onMedia);
            }

            document.addEventListener('keydown', this.onKeydown);
        },

        beforeUnmount() {
            this.unmounted = true;
            this.startRequest('classes');
            this.startRequest('series');

            if (this.media) this.media.removeEventListener('change', this.onMedia);

            document.removeEventListener('keydown', this.onKeydown);
        },

        methods: {
            countText,
            countTitle,
            compactParts,
            emptyText,
            inText,
            runtimeText,
            windowLine,
            peakText,
            lastFullMinuteText,
            notListedText,

            /** The row's name for a screen reader: the class and its sums in words. */
            rowLabel(row) {
                return `${row.name}: ${compactParts(row).map(part => part.text).join(', ')}`;
            },

            get(path, params, background) {
                return this.$http.get(Orrery.basePath + path, {params, handles: background ? BACKGROUND_STATUSES : SECTION_STATUSES})
                    .then(response => response.data);
            },

            /** The poll: the table and the selected class's series, both as background reads. */
            refresh() {
                return Promise.all([this.loadClasses(true), this.loadSeries(true)]);
            },

            /** Read the table; `background` for a poll. A failed poll keeps the last data. */
            loadClasses(background) {
                const sequence = this.startRequest('classes');
                const window = this.window;

                if (!background) {
                    this.stale = null;
                    this.loadError = null;
                }

                return this.get('/api/classes', {minutes: window}, background)
                    .then(body => {
                        if (this.unmounted || !this.isCurrentRequest('classes', sequence)) return;

                        const data = normalizeClasses(body);

                        if (data === null) throw unexpectedAnswer();

                        this.data = data;
                        this.dataWindow = window;
                        this.loadError = null;
                        this.stale = null;
                    })
                    .catch(error => {
                        if (this.unmounted || !this.isCurrentRequest('classes', sequence)) return;

                        if (this.shown) {
                            this.stale = classFailure(error);
                        } else {
                            this.loadError = classFailure(error);
                        }
                    });
            },

            /** Read the selected class's series; `background` for a poll. */
            loadSeries(background) {
                const sequence = this.startRequest('series');
                const key = this.currentSeriesKey;

                if (key === null) {
                    this.seriesError = null;
                    this.seriesStale = null;

                    return Promise.resolve();
                }

                if (!background) {
                    this.seriesError = null;
                    this.seriesStale = null;
                }

                return this.get('/api/classes/series', seriesParams(this.selected, this.effectiveSpan), background)
                    .then(body => {
                        if (this.unmounted || !this.isCurrentRequest('series', sequence) || key !== this.currentSeriesKey) return;

                        const series = normalizeSeries(body);

                        if (series === null) throw unexpectedAnswer();

                        this.series = series;
                        this.seriesKey = key;
                        this.seriesError = null;
                        this.seriesStale = null;
                    })
                    .catch(error => {
                        if (this.unmounted || !this.isCurrentRequest('series', sequence) || key !== this.currentSeriesKey) return;

                        if (this.shownSeries) {
                            this.seriesStale = classFailure(error);
                        } else {
                            this.seriesError = classFailure(error);
                        }
                    });
            },

            setWindow(window) {
                if (window === this.window) return;

                this.$router.replace({path: '/classes', query: queryFor(window, this.selected)});
            },

            /** Select a class, or close it when it is open (the row is its own toggle). */
            toggle(name) {
                this.$router.replace({path: '/classes', query: queryFor(this.window, this.selected === name ? null : name)});
            },

            close() {
                const name = this.selected;

                this.$router.replace({path: '/classes', query: queryFor(this.window, null)}).then(() => {
                    this.focusRow(name);
                });
            },

            focusRow(name) {
                if (!this.$el || typeof this.$el.querySelectorAll !== 'function') return;

                const row = Array.from(this.$el.querySelectorAll('[data-class-row]')).find(element => element.dataset.name === name);

                if (row) row.focus();
            },

            /** Escape closes the inspector (or the open row) and returns focus to the row. */
            onKeydown(event) {
                if (event.key !== 'Escape' || this.selected === null || event.defaultPrevented) return;

                const target = event.target;

                if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable)) return;

                event.preventDefault();
                this.close();
            },

            retryClasses() {
                this.loadClasses(false);
            },

            retrySeries() {
                this.loadSeries(false);
            },
        },
    };
</script>

<template>
    <inspector-layout>
        <poll :request="refresh" :interval="pollSeconds" />

        <!-- The window: in the toolbar, and at the top of the page below 768 px, where the toolbar has no room. -->
        <toolbar-controls v-if="!narrow">
            <div class="o-classes__segmented" role="group" aria-label="Window">
                <button v-for="entry in windows" :key="entry" type="button" class="o-classes__segment"
                        :class="{'o-classes__segment--active': entry === window}" :aria-pressed="entry === window ? 'true' : 'false'"
                        :data-window="entry" @click="setWindow(entry)">{{ entry }} min</button>
            </div>
        </toolbar-controls>

        <div class="o-classes">
            <div v-if="narrow" class="o-classes__segmented o-classes__segmented--page" role="group" aria-label="Window">
                <button v-for="entry in windows" :key="entry" type="button" class="o-classes__segment"
                        :class="{'o-classes__segment--active': entry === window}" :aria-pressed="entry === window ? 'true' : 'false'"
                        :data-window="entry" @click="setWindow(entry)">{{ entry }} min</button>
            </div>

            <!-- The live region of the uncounted minutes stays mounted through every state, so a screen reader hears its first text too. -->
            <span class="o-classes__sr" role="status" aria-live="polite" data-unknown-status>{{ announcement }}</span>

            <notice v-if="loadError && !shown" tone="danger" title="The job classes could not be loaded" :detail="loadError" data-load-error>
                <template #actions>
                    <o-button variant="write" size="sm" @click="retryClasses">Try again</o-button>
                </template>
            </notice>

            <div v-else-if="!shown" class="o-classes__loading" data-loading>Loading…</div>

            <template v-else>
                <div class="o-classes__head">
                    <div class="o-classes__title" data-title>{{ title }}</div>
                    <div class="o-classes__window" data-window-line>{{ windowLine(shown) }}</div>
                    <span v-if="stale" class="o-classes__stale" :title="stale" data-stale>Stale</span>
                </div>

                <!-- Not a Notice: its alert role would announce the moving times on every poll. The live region above names the count once per change. -->
                <div v-if="notice" class="o-classes__unknown" data-unknown-notice>
                    <icon name="warning" :size="16" class="o-classes__unknown-icon" />
                    <span>{{ notice }}</span>
                </div>

                <section v-if="!shown.classes.length" class="o-classes__empty" data-empty>{{ emptyText(shown, window) }}</section>

                <div v-else class="o-classes__table" aria-label="Job classes" role="group">
                    <div class="o-classes__row o-classes__row--head" aria-hidden="true">
                        <span class="o-classes__name">Class <span class="o-classes__sort">· busiest first by completed + failed ▾</span></span>
                        <span class="o-classes__num o-classes__num--completed">Completed</span>
                        <span class="o-classes__num o-classes__num--failed">Failed</span>
                        <span class="o-classes__num o-classes__num--in">In</span>
                        <span class="o-classes__num o-classes__num--runtime">Avg runtime</span>
                    </div>

                    <div v-for="row in shown.classes" :key="row.name" class="o-classes__item">
                        <div class="o-classes__row" :class="{'o-classes__row--selected': row.name === selected}" role="button"
                             tabindex="0" :aria-pressed="row.name === selected ? 'true' : 'false'" :aria-label="rowLabel(row)"
                             data-class-row :data-name="row.name"
                             @click="toggle(row.name)" @keydown.enter.self.prevent="toggle(row.name)" @keydown.space.self.prevent="toggle(row.name)">
                            <span class="o-classes__name" :title="row.name" data-name-cell>{{ row.name }}</span>
                            <span class="o-classes__num o-classes__num--completed" :title="countTitle(row.completed, row.complete, 'completed')"
                                  data-completed>{{ countText(row.completed, row.complete) }}</span>
                            <span class="o-classes__num o-classes__num--failed" :class="{'o-classes__num--danger': row.failed > 0}"
                                  :title="countTitle(row.failed, row.complete, 'failed')" data-failed>{{ countText(row.failed, row.complete) }}</span>
                            <span class="o-classes__num o-classes__num--in" :class="{'o-classes__num--unknown': row.pushed === null}"
                                  :title="row.pushed === null ? 'Arrivals unknown for a minute of the window' : null" data-in>{{ inText(row.pushed) }}</span>
                            <span class="o-classes__num o-classes__num--runtime" :class="{'o-classes__num--unknown': row.runtimeAvgMs === null}"
                                  data-runtime>{{ runtimeText(row.runtimeAvgMs) }}</span>
                            <span class="o-classes__compact" data-compact>
                                <span v-for="part in compactParts(row)" :key="part.key" :class="{'o-classes__compact--danger': part.tone === 'danger'}">{{ part.text }}</span>
                            </span>
                        </div>

                        <div v-if="narrow && row.name === selected" class="o-classes__inline" data-inline>
                            <notice v-if="seriesError && !shownSeries" tone="danger" title="The chart could not be loaded" :detail="seriesError">
                                <template #actions>
                                    <o-button variant="write" size="sm" @click="retrySeries">Try again</o-button>
                                </template>
                            </notice>
                            <div v-else-if="!shownSeries" class="o-classes__loading">Loading…</div>
                            <bar-chart v-else :from="shownSeries.from" :to="shownSeries.to" :bucket-seconds="shownSeries.bucketSeconds"
                                       :buckets="shownSeries.buckets" :series="chart.series" :band="chart.band" :y-ticks="2" />
                            <div class="o-classes__inline-foot">
                                <span>Hatched: not counted<template v-if="seriesStale"> · <span class="o-classes__stale" :title="seriesStale">Stale</span></template></span>
                                <router-link :to="jobsTarget" class="o-classes__link" data-jobs-link>Open in Jobs ›</router-link>
                            </div>
                        </div>
                    </div>
                </div>

                <p class="o-classes__footnote">"In" counts pushes. Avg runtime is "—" when no run finished in the window. Silenced jobs count as completed.</p>
            </template>
        </div>

        <template v-if="selected !== null && !narrow" #inspector>
            <div class="o-inspector" data-inspector>
                <div class="o-inspector__head">
                    <div class="o-inspector__caption">Job class</div>
                    <div class="o-inspector__name" :title="selected" data-inspector-name>{{ shortSelected }}</div>
                    <div class="o-inspector__full" :title="selected" data-inspector-full>{{ selected }}</div>
                </div>

                <div class="o-inspector__controls">
                    <div class="o-classes__segmented" role="group" aria-label="Chart span">
                        <button v-for="entry in spans" :key="entry" type="button" class="o-classes__segment"
                                :class="{'o-classes__segment--active': entry === span}" :aria-pressed="entry === span ? 'true' : 'false'"
                                :data-span="entry" @click="span = entry">{{ entry === '1h' ? '1 h' : '24 h' }}</button>
                    </div>
                    <router-link :to="jobsTarget" class="o-classes__link" data-jobs-link>Open in Jobs ›</router-link>
                    <button type="button" class="o-inspector__close" aria-label="Close the inspector" data-close @click="close">×</button>
                </div>

                <notice v-if="selectedRow === null && shown" tone="info" title="Not in the table" :detail="notListedText(shown, window)" data-not-listed />

                <notice v-if="seriesError && !shownSeries" tone="danger" title="The charts could not be loaded" :detail="seriesError" data-series-error>
                    <template #actions>
                        <o-button variant="write" size="sm" @click="retrySeries">Try again</o-button>
                    </template>
                </notice>

                <div v-else-if="!shownSeries" class="o-classes__loading" data-series-loading>Loading…</div>

                <template v-else>
                    <span v-if="seriesStale" class="o-classes__stale" :title="seriesStale" data-series-stale>Stale</span>

                    <div class="o-inspector__section">
                        <div class="o-inspector__section-head">
                            <span class="o-inspector__section-title">Completed and failed per minute</span>
                            <span class="o-inspector__section-note" data-peak>{{ peakText(shownSeries) }}</span>
                        </div>
                        <bar-chart :from="shownSeries.from" :to="shownSeries.to" :bucket-seconds="shownSeries.bucketSeconds"
                                   :buckets="shownSeries.buckets" :series="chart.series" :band="chart.band" :y-ticks="2" />
                        <div class="o-inspector__section-note">Hatched: not counted</div>
                    </div>

                    <div class="o-inspector__section o-inspector__section--ruled">
                        <div class="o-inspector__section-head">
                            <span class="o-inspector__section-title">In per minute</span>
                            <span class="o-inspector__section-note" data-last-in>{{ lastFullMinuteText(shownSeries, 'pushed', inText) }}</span>
                        </div>
                        <sparkline v-if="sparkMinutes" :from="sparkMinutes.from" :to="sparkMinutes.to" :bucket-seconds="shownSeries.bucketSeconds" :buckets="sparkMinutes.buckets"
                                   value-key="pushed" :retention-from="shownSeries.retentionFrom.pushed" variant="line" color="var(--color-text-secondary)"
                                   :width="300" :height="36" label="Pushes per minute" />
                    </div>

                    <div class="o-inspector__section o-inspector__section--ruled">
                        <div class="o-inspector__section-head">
                            <span class="o-inspector__section-title">Average runtime</span>
                            <span class="o-inspector__section-note" data-window-runtime>{{ selectedRow ? `${runtimeText(selectedRow.runtimeAvgMs)} · last ${window} min` : lastFullMinuteText(shownSeries, 'runtime_avg_ms', runtimeText) }}</span>
                        </div>
                        <sparkline v-if="sparkMinutes" :from="sparkMinutes.from" :to="sparkMinutes.to" :bucket-seconds="shownSeries.bucketSeconds" :buckets="sparkMinutes.buckets"
                                   value-key="runtime_avg_ms" :retention-from="shownSeries.retentionFrom.completed" variant="line" color="var(--color-warning-text)"
                                   :width="300" :height="36" label="Average runtime per minute" />
                    </div>
                </template>
            </div>
        </template>
    </inspector-layout>
</template>

<style scoped>
    .o-classes {
        display: flex;
        flex-direction: column;
        font-family: var(--font-sans);
        gap: 14px;
        min-width: 0;
    }

    .o-classes__loading,
    .o-classes__empty {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: var(--leading-table);
    }

    .o-classes__empty {
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-card);
        padding: 16px 14px;
    }

    .o-classes__head {
        align-items: baseline;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .o-classes__title {
        color: var(--color-text);
        font-size: var(--text-body);
        font-weight: var(--font-weight-semibold);
        line-height: var(--leading-body);
    }

    .o-classes__window {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: var(--leading-table);
    }

    .o-classes__stale {
        background-color: var(--color-warning-tint);
        border-radius: 4px;
        color: var(--color-warning-text);
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        line-height: 16px;
        padding: 0 6px;
    }

    .o-classes__table {
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-card);
        display: flex;
        flex-direction: column;
        overflow-x: auto;
    }

    .o-classes__item + .o-classes__item {
        border-top: 1px solid var(--color-separator);
    }

    .o-classes__row {
        align-items: center;
        box-sizing: border-box;
        cursor: pointer;
        display: flex;
        min-height: 40px;
        /* The name keeps 160 px; below that the table scrolls inside its own container. */
        min-width: 598px;
        padding: 0 14px;
    }

    .o-classes__row:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: -2px;
    }

    .o-classes__row--head {
        background-color: var(--color-subtle);
        border-bottom: 1px solid var(--color-separator);
        color: var(--color-text-secondary);
        cursor: default;
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        line-height: 16px;
        min-height: 30px;
    }

    .o-classes__row--selected {
        background-color: var(--color-accent-tint);
        box-shadow: inset 3px 0 0 var(--color-accent);
    }

    .o-classes__name {
        flex: 1 1 0%;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-classes__row:not(.o-classes__row--head) .o-classes__name {
        color: var(--color-text);
        font-size: var(--text-table);
        font-weight: var(--font-weight-semibold);
        line-height: var(--leading-table);
    }

    .o-classes__num {
        flex-shrink: 0;
        font-variant-numeric: tabular-nums;
        text-align: right;
    }

    .o-classes__row:not(.o-classes__row--head) .o-classes__num {
        color: var(--color-text);
        font-size: var(--text-table);
        line-height: var(--leading-table);
    }

    .o-classes__sort {
        font-weight: var(--font-weight-regular);
    }

    .o-classes__unknown {
        align-items: flex-start;
        background-color: var(--color-warning-tint);
        border: 1px solid color-mix(in srgb, var(--color-warning) 35%, transparent);
        border-radius: var(--radius-card);
        color: var(--color-text);
        display: flex;
        font-size: var(--text-table);
        gap: 10px;
        line-height: var(--leading-table);
        padding: 9px 12px;
    }

    .o-classes__unknown-icon {
        color: var(--color-warning);
        flex-shrink: 0;
        margin-top: 1px;
    }

    .o-classes__sr {
        clip: rect(0 0 0 0);
        clip-path: inset(50%);
        height: 1px;
        overflow: hidden;
        position: absolute;
        white-space: nowrap;
        width: 1px;
    }

    .o-classes__num--completed,
    .o-classes__num--runtime {
        width: 110px;
    }

    .o-classes__num--failed {
        width: 90px;
    }

    .o-classes__num--in {
        width: 100px;
    }

    .o-classes__row:not(.o-classes__row--head) .o-classes__num--danger {
        color: var(--color-danger-text);
        font-weight: var(--font-weight-semibold);
    }

    .o-classes__row:not(.o-classes__row--head) .o-classes__num--unknown {
        color: var(--color-text-tertiary);
    }

    .o-classes__compact {
        display: none;
    }

    .o-classes__footnote {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
        margin: 0;
    }

    .o-classes__segmented {
        align-items: center;
        background-color: color-mix(in srgb, var(--color-text) 7%, transparent);
        border-radius: 7px;
        display: flex;
        padding: 2px;
    }

    .o-classes__segmented--page {
        align-self: flex-start;
    }

    .o-classes__segment {
        background: transparent;
        border: 0;
        border-radius: 5px;
        color: var(--color-text);
        cursor: pointer;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        line-height: var(--leading-table);
        padding: 3px 12px;
        white-space: nowrap;
    }

    .o-classes__segment--active {
        background-color: var(--color-bg);
        box-shadow: var(--shadow-raised);
        font-weight: var(--font-weight-semibold);
    }

    .o-classes__segment:focus-visible,
    .o-classes__link:focus-visible,
    .o-inspector__close:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: 1px;
    }

    .o-classes__link {
        color: var(--color-accent);
        font-size: var(--text-table);
        line-height: var(--leading-table);
        text-decoration: none;
        white-space: nowrap;
    }

    .o-classes__link:hover {
        text-decoration: underline;
    }

    .o-classes__inline {
        display: flex;
        flex-direction: column;
        gap: 8px;
        padding: 0 14px 12px;
    }

    .o-classes__inline-foot {
        color: var(--color-text-secondary);
        display: flex;
        font-size: var(--text-caption);
        gap: 8px;
        justify-content: space-between;
        line-height: 16px;
    }

    .o-inspector {
        display: flex;
        flex-direction: column;
        font-family: var(--font-sans);
        gap: 16px;
    }

    .o-inspector__head {
        display: flex;
        flex-direction: column;
        gap: 3px;
        min-width: 0;
    }

    .o-inspector__caption {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        letter-spacing: var(--tracking-caps);
        line-height: 16px;
        text-transform: uppercase;
    }

    .o-inspector__name {
        color: var(--color-text);
        font-size: var(--text-section);
        font-weight: var(--font-weight-semibold);
        letter-spacing: -0.01em;
        line-height: 22px;
        overflow-wrap: anywhere;
        -webkit-box-orient: vertical;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        line-clamp: 2;
        overflow: hidden;
    }

    .o-inspector__full {
        color: var(--color-text-secondary);
        font-family: var(--font-mono);
        font-size: var(--text-caption);
        line-height: 16px;
        overflow-wrap: anywhere;
        -webkit-box-orient: vertical;
        display: -webkit-box;
        -webkit-line-clamp: 4;
        line-clamp: 4;
        overflow: hidden;
    }

    .o-inspector__controls {
        align-items: center;
        display: flex;
        gap: 10px;
        justify-content: space-between;
    }

    .o-inspector__close {
        background: transparent;
        border: 0;
        border-radius: var(--radius-control);
        color: var(--color-text-secondary);
        cursor: pointer;
        font-size: var(--text-section);
        line-height: 1;
        padding: 2px 6px;
    }

    .o-inspector__section {
        display: flex;
        flex-direction: column;
        gap: 6px;
        min-width: 0;
    }

    .o-inspector__section--ruled {
        border-top: 1px solid var(--color-separator);
        padding-top: 12px;
    }

    .o-inspector__section-head {
        align-items: baseline;
        display: flex;
        gap: 8px;
        justify-content: space-between;
    }

    .o-inspector__section-title {
        color: var(--color-text);
        font-size: var(--text-table);
        font-weight: var(--font-weight-semibold);
        line-height: var(--leading-table);
    }

    .o-inspector__section-note {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
    }

    @media (max-width: 767px) {
        .o-classes__row--head {
            display: none;
        }

        .o-classes__row:not(.o-classes__row--head) {
            align-items: flex-start;
            min-width: 0;
            flex-direction: column;
            gap: 4px;
            padding: 10px 14px;
        }

        .o-classes__row:not(.o-classes__row--head) .o-classes__num {
            display: none;
        }

        .o-classes__name {
            width: 100%;
        }

        .o-classes__compact {
            color: var(--color-text-secondary);
            column-gap: 14px;
            display: flex;
            flex-wrap: wrap;
            font-size: var(--text-caption);
            line-height: 16px;
        }

        .o-classes__compact--danger {
            color: var(--color-danger-text);
            font-weight: var(--font-weight-semibold);
        }
    }
</style>
