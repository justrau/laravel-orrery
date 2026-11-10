<script>
    import Button from '../../components/ui/Button.vue';
    import WaitGauge from '../../components/ui/WaitGauge.vue';
    import {gaugePause, queueContinueApplies} from '../../support/pause.js';
    import {formatCount, formatHourMinuteZoned, formatLimit} from '../../support/time';
    import BacklogChart from './BacklogChart.vue';
    import {
        TERMINATE_TEXT,
        backlogStatus,
        currentJob,
        diffText,
        growthSince,
        growthText,
        inOutBars,
        markerName,
        markersIn,
        isPaused,
        pauseHint,
        pausedFrom,
        pausedTitle,
        keptFor,
        queueJobsLinks,
        queueState,
        rateText,
        workersFor,
        workersHeader,
    } from './queues';

    /**
     * The inspector of the selected queue:
     * the state and Pause / Continue, the big wait gauge, the read-only wait
     * limit, the observed in and out rates, the backlog of the past hour with
     * the markers, the workers that serve the queue, and "Terminate <queue>
     * workers…". The write actions emit `control` with the action; the screen
     * opens the confirmation with a frozen selection.
     */
    export default {
        components: {BacklogChart, WaitGauge, 'o-button': Button},

        props: {
            row: {type: Object, default: null},
            notFound: {type: Boolean, default: false},
            loading: {type: Boolean, default: false},
            history: {type: Object, default: null},
            markers: {type: Array, default: () => []},
            workers: {type: Array, default: null},
            workersError: {type: Boolean, default: false},
            now: {type: Number, required: true},
            /** /api/health `queue_pause_cache_local`: the hint that a queue pause may not reach every worker, or null. */
            cacheNote: {type: String, default: null},
            /** GET /api/settings/retention, or null before it loaded. */
            retention: {type: Object, default: null},
            retentionError: {type: Boolean, default: false},
        },

        emits: ['control'],

        computed: {
            state() {
                return this.row ? queueState(this.row, this.now) : null;
            },

            summary() {
                const count = value => (value === null ? '—' : formatCount(value));

                return `${count(this.row.ready)} ready · ${count(this.row.running)} running · ${count(this.row.workers)} ${this.row.workers === 1 ? 'worker' : 'workers'}`;
            },

            /** The pause that the gauge draws (a cause from the command line has no actor or time). */
            gaugePaused() {
                return this.row ? gaugePause(this.row.pause) : null;
            },

            /** What Orrery's actions cannot lift, what is unknown, and a per-server cache store. */
            hint() {
                return pauseHint(this.row, this.cacheNote);
            },

            /** Orrery's Pause: not while a pause holds. */
            showPause() {
                return !isPaused(this.row);
            },

            /** A queue Continue: while a queue cause holds (also a possible one), or while the pause state is unknown. */
            showContinue() {
                return queueContinueApplies(this.row.pause);
            },

            pausedFrom() {
                return pausedFrom(this.row, this.window);
            },

            pausedTitle() {
                return pausedTitle(this.row, this.window);
            },

            /** "Kept for" of this queue (queues.js keptFor()): null while unknown. */
            kept() {
                return keptFor(this.retention, this.row);
            },

            keptNote() {
                if (this.kept) return null;
                if (this.retentionError) return 'The retention settings could not be read.';
                if (!this.retention) return 'Loading…';

                return 'Unknown for this queue: it is not among the queues the settings list.';
            },

            /** One link per alias of the queue (queues.js queueJobsLinks()). */
            jobLinks() {
                return [...queueJobsLinks(this.row, 'pending'), ...queueJobsLinks(this.row, 'failed')]
                    .map(link => ({...link, key: `${link.to.query.status}:${link.key}`}));
            },

            limit() {
                return this.row.threshold > 0 ? formatLimit(this.row.threshold) : 'No limit';
            },

            bars() {
                return inOutBars(this.row.inPerMin, this.row.outPerMin);
            },

            diff() {
                return diffText(this.bars.diff);
            },

            window() {
                return this.history ? this.history.window : null;
            },

            backlog() {
                return backlogStatus(this.history);
            },

            buckets() {
                return this.history ? this.history.buckets : [];
            },

            scale() {
                return Math.max(1, ...this.buckets.map(bucket => (bucket.ready === null ? 0 : bucket.ready)));
            },

            windowMarkers() {
                return markersIn(this.markers, this.window);
            },

            growth() {
                return growthSince(this.buckets, this.markers, this.window);
            },

            growthLine() {
                if (!this.growth) return null;

                return `${growthText(this.growth)} · ${formatCount(this.growth.then)} at ${markerName(this.growth.marker)}`;
            },

            backlogSubtitle() {
                return `past hour · ${this.row.ready === null ? '—' : formatCount(this.row.ready)} now`;
            },

            /** The newest marker of the window with its zoned time ("Deploy v2 09:40 GMT+3"). */
            axisMarker() {
                const marker = this.windowMarkers[this.windowMarkers.length - 1];

                return marker ? `${markerName(marker)} ${formatHourMinuteZoned(marker.at)}` : '';
            },

            axisStart() {
                return this.window ? formatHourMinuteZoned(Math.floor(this.window.from / 60) * 60) : '';
            },

            tone() {
                if (isPaused(this.row)) return 'paused';

                return this.row.overLimit ? 'warning' : 'accent';
            },

            serving() {
                return Array.isArray(this.workers) ? workersFor(this.workers, this.row) : [];
            },

            workerRows() {
                return this.serving.map(worker => ({
                    key: worker.worker_id || worker.name,
                    name: worker.name,
                    fresh: worker.heartbeat_fresh === true,
                    job: currentJob(worker, this.now),
                }));
            },

            workersTitle() {
                if (this.workersError && !Array.isArray(this.workers)) return 'Could not load the workers';
                if (!Array.isArray(this.workers)) return 'Loading…';

                return workersHeader(this.serving, this.row.name, this.now);
            },
        },

        methods: {
            formatHourMinuteZoned,
            markerName,
            rateText,
            /** The mandated terminate text, then the scope in a separate sentence (no string replacement of the text). */
            terminateText() {
                return `${TERMINATE_TEXT} It applies to every worker that serves ${this.row.name}.`;
            },
        },
    };
</script>

<template>
    <div class="o-qi" data-inspector>
        <template v-if="notFound">
            <div class="o-qi__eyebrow">Queue</div>
            <div class="o-qi__title" data-inspector-title>Queue not found</div>
            <p class="o-qi__muted" data-not-found>No database queue has this name on this connection.</p>
            <o-button variant="link" to="/queues" data-back>All queues</o-button>
        </template>

        <div v-else-if="!row" class="o-qi__muted" data-inspector-loading>{{ loading ? 'Loading…' : 'Could not load the queue.' }}</div>

        <template v-else>
            <header class="o-qi__header">
                <div class="o-qi__eyebrow">Queue · {{ row.connection }}</div>
                <div class="o-qi__title-row">
                    <div class="o-qi__title" data-inspector-title :title="row.name">{{ row.name }}</div>
                    <span class="o-pill" :class="`o-pill--${state.kind}`" :title="state.label" data-state-pill>{{ state.label }}</span>
                </div>
                <div class="o-qi__muted" data-inspector-summary>{{ summary }}</div>
                <div class="o-qi__actions">
                    <o-button v-if="showPause" variant="write" icon="pause" operate data-pause @click="$emit('control', 'pause')">Pause {{ row.name }}</o-button>
                    <o-button v-if="showContinue" variant="write" icon="play" operate data-continue @click="$emit('control', 'continue')">Continue {{ row.name }}</o-button>
                </div>
            </header>

            <div v-if="hint" class="o-qi__hint" data-native-hint>{{ hint }}</div>

            <div v-if="row.backlogUnknown" class="o-qi__gauge o-qi__muted" data-backlog-unknown>The backlog could not be read: the oldest wait is unknown.</div>
            <div v-else class="o-qi__gauge">
                <wait-gauge size="large" :oldest-age="row.oldestAge" :threshold="row.threshold" :over-limit="row.overLimit"
                            :paused="gaugePaused" :now="now" data-big-gauge />
            </div>

            <div class="o-qi__limit" data-wait-limit>
                <div>
                    <div class="o-qi__heading">Wait limit</div>
                    <div class="o-qi__caption">Set in config/orrery.php · waits</div>
                </div>
                <div class="o-qi__heading" data-wait-limit-value>{{ limit }}</div>
            </div>

            <div class="o-qi__links" data-jobs-links>
                <o-button v-for="link in jobLinks" :key="link.key" variant="link" :to="link.to" :data-jobs-link="link.key">{{ link.label }}</o-button>
            </div>

            <section class="o-qi__block" data-kept-for>
                <div class="o-qi__block-head">
                    <div class="o-qi__heading">Kept for</div>
                    <div class="o-qi__caption">orrery.trim</div>
                </div>
                <div v-if="kept" class="o-qi__kept">
                    <div v-for="entry in kept" :key="entry.key" class="o-qi__kept-row" :data-kept="entry.key" :title="entry.source">
                        <span class="o-qi__kept-label">{{ entry.label }}</span>
                        <span class="o-qi__kept-value">{{ entry.text }}</span>
                        <span class="o-qi__kept-source">{{ entry.source }}</span>
                    </div>
                </div>
                <div v-else class="o-qi__muted" data-kept-unknown>{{ keptNote }}</div>
            </section>

            <section class="o-qi__block" data-in-out>
                <div class="o-qi__block-head">
                    <div class="o-qi__heading">In and out</div>
                    <div class="o-qi__caption">last 5 min · observed</div>
                </div>
                <div class="o-bar-row">
                    <span class="o-bar-row__label">In</span>
                    <span class="o-bar-row__track">
                        <span v-if="bars.in !== null" class="o-bar-row__bar o-bar-row__bar--in" :style="{width: (bars.in * 100).toFixed(1) + '%'}"></span>
                    </span>
                    <span class="o-bar-row__value" data-in>{{ row.inPerMin === null ? '—' : `${rateText(row.inPerMin)}/min` }}</span>
                </div>
                <div class="o-bar-row">
                    <span class="o-bar-row__label">Out</span>
                    <span class="o-bar-row__track">
                        <span v-if="bars.out !== null && bars.excess > 0" class="o-bar-row__excess"
                              :style="{left: (bars.out * 100).toFixed(1) + '%', width: (bars.excess * 100).toFixed(1) + '%'}"></span>
                        <span v-if="bars.out !== null" class="o-bar-row__bar o-bar-row__bar--out" :style="{width: (bars.out * 100).toFixed(1) + '%'}"></span>
                    </span>
                    <span class="o-bar-row__value" data-out>{{ row.outPerMin === null ? '—' : `${rateText(row.outPerMin)}/min` }}</span>
                </div>
                <div v-if="diff" class="o-qi__diff" data-diff>{{ diff }}</div>
            </section>

            <section class="o-qi__block" data-inspector-backlog>
                <div class="o-qi__block-head">
                    <div class="o-qi__heading">Backlog</div>
                    <div class="o-qi__caption">{{ backlogSubtitle }}</div>
                </div>
                <div v-if="growthLine" class="o-qi__growth" data-growth>{{ growthLine }}</div>
                <div v-if="backlog === 'failed'" class="o-qi__muted" data-backlog-error>Could not load the backlog.</div>
                <div v-else-if="backlog === 'loading'" class="o-qi__muted">Loading…</div>
                <template v-else>
                    <div v-if="backlog === 'stale'" class="o-qi__muted" data-backlog-stale>Stale: the backlog could not be refreshed. The chart shows the last data read.</div>
                    <backlog-chart :window="window" :buckets="buckets" :max="scale" :markers="markers" :height="84"
                                   :paused-from="pausedFrom" :paused-title="pausedTitle" :tone="tone"
                                   :label="`${row.name}: ready jobs, past hour`" />
                    <div class="o-qi__axis">
                        <span>{{ axisStart }}</span>
                        <span v-if="windowMarkers.length" class="o-qi__axis-marker" :title="axisMarker">{{ axisMarker }}</span>
                        <span>now</span>
                    </div>
                </template>
            </section>

            <section class="o-qi__block" data-inspector-workers>
                <div class="o-qi__block-head">
                    <div class="o-qi__heading">Workers</div>
                    <div class="o-qi__caption" data-workers-title>{{ workersTitle }}</div>
                </div>
                <div v-for="worker in workerRows" :key="worker.key" class="o-worker" data-worker-row>
                    <span class="o-worker__dot" :class="{'o-worker__dot--stale': !worker.fresh}"
                          :title="worker.fresh ? 'Heartbeat in the last 60 s' : 'No recent heartbeat; a live lease keeps it listed'"></span>
                    <span class="o-worker__name" :title="worker.name">{{ worker.name }}</span>
                    <span class="o-worker__job" :title="worker.job ? worker.job.fullName : 'The current job is unknown'" data-worker-job>{{ worker.job ? worker.job.name : '—' }}</span>
                    <span class="o-worker__runtime" data-worker-runtime>{{ worker.job ? worker.job.runtime : '—' }}</span>
                </div>
            </section>

            <section v-if="canOperate" class="o-qi__terminate">
                <o-button variant="danger" icon="power" operate data-terminate-queue @click="$emit('control', 'terminate')">Terminate {{ row.name }} workers…</o-button>
                <div class="o-qi__caption">{{ terminateText() }}</div>
            </section>
        </template>
    </div>
</template>

<style scoped>
    .o-qi {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .o-qi__header {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .o-qi__eyebrow {
        color: var(--color-text-tertiary);
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        letter-spacing: var(--tracking-caps);
        line-height: 16px;
        text-transform: uppercase;
    }

    .o-qi__title-row {
        align-items: center;
        display: flex;
        gap: 8px;
    }

    .o-qi__title {
        color: var(--color-text);
        flex: 1 1 0%;
        font-size: var(--text-title);
        font-weight: var(--font-weight-semibold);
        letter-spacing: -0.01em;
        line-height: 28px;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-qi__actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        padding-top: 8px;
    }

    .o-qi__actions:empty {
        display: none;
    }

    .o-qi__links {
        display: flex;
        flex-wrap: wrap;
        gap: 4px 16px;
    }

    .o-qi__kept {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .o-qi__kept-row {
        align-items: baseline;
        display: flex;
        font-size: var(--text-table);
        gap: 8px;
        line-height: 18px;
    }

    .o-qi__kept-label {
        color: var(--color-text-secondary);
        flex-shrink: 0;
        width: 84px;
    }

    .o-qi__kept-value {
        color: var(--color-text);
        flex-shrink: 0;
        font-variant-numeric: tabular-nums;
        font-weight: var(--font-weight-medium);
    }

    .o-qi__kept-source {
        color: var(--color-text-tertiary);
        font-size: var(--text-caption);
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* The hint is prose with an occasional command, so it keeps the body font. */
    .o-qi__hint {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
        overflow-wrap: anywhere;
    }

    .o-qi__muted {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: 18px;
        margin: 0;
    }

    .o-qi__gauge {
        display: flex;
        justify-content: center;
    }

    .o-qi__limit {
        align-items: flex-start;
        border-top: 1px solid var(--color-separator);
        display: flex;
        gap: 12px;
        justify-content: space-between;
        padding-top: 10px;
    }

    .o-qi__heading {
        color: var(--color-text);
        font-size: var(--text-body);
        font-weight: var(--font-weight-semibold);
        line-height: 20px;
    }

    .o-qi__caption {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
    }

    .o-qi__block {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .o-qi__block-head {
        align-items: baseline;
        display: flex;
        gap: 8px;
        justify-content: space-between;
    }

    .o-qi__diff {
        color: var(--color-warning-text);
        font-size: var(--text-table);
        font-weight: var(--font-weight-medium);
        line-height: 18px;
    }

    .o-qi__growth {
        color: var(--color-text);
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        line-height: 16px;
    }

    .o-qi__axis {
        color: var(--color-text-tertiary);
        display: flex;
        font-size: var(--text-caption);
        justify-content: space-between;
        line-height: 14px;
    }

    .o-qi__axis-marker {
        color: var(--color-text-secondary);
        font-weight: var(--font-weight-medium);
        min-width: 0;
        overflow: hidden;
        padding: 0 6px;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-qi__axis > span:not(.o-qi__axis-marker) {
        flex-shrink: 0;
    }

    .o-bar-row {
        align-items: center;
        display: flex;
        gap: 8px;
        height: 18px;
    }

    .o-bar-row__label {
        color: var(--color-text-secondary);
        flex-shrink: 0;
        font-size: var(--text-table);
        width: 30px;
    }

    .o-bar-row__track {
        background-color: var(--color-subtle);
        border-radius: 4px;
        flex: 1 1 0%;
        height: 8px;
        position: relative;
    }

    .o-bar-row__bar {
        border-radius: 4px;
        height: 8px;
        left: 0;
        position: absolute;
        top: 0;
    }

    .o-bar-row__bar--in { background-color: var(--color-text-tertiary); }
    .o-bar-row__bar--out { background-color: var(--color-accent); }

    .o-bar-row__excess {
        background-color: var(--color-warning-tint);
        border: 1px dashed var(--color-warning);
        border-radius: 0 4px 4px 0;
        box-sizing: border-box;
        height: 8px;
        position: absolute;
        top: 0;
    }

    .o-bar-row__value {
        color: var(--color-text);
        flex-shrink: 0;
        font-size: var(--text-table);
        font-variant-numeric: tabular-nums;
        font-weight: var(--font-weight-semibold);
        text-align: right;
        width: 72px;
    }

    .o-worker {
        align-items: center;
        border-top: 1px solid var(--color-separator);
        display: flex;
        gap: 8px;
        height: 32px;
    }

    .o-worker__dot {
        background-color: var(--color-success);
        border-radius: 50%;
        flex-shrink: 0;
        height: 6px;
        width: 6px;
    }

    .o-worker__dot--stale {
        background-color: var(--color-paused);
    }

    .o-worker__name {
        color: var(--color-text);
        flex-shrink: 0;
        font-family: var(--font-mono);
        font-size: var(--text-caption);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        width: 110px;
    }

    .o-worker__job {
        color: var(--color-text-secondary);
        flex: 1 1 0%;
        font-size: var(--text-table);
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .o-worker__runtime {
        color: var(--color-text);
        flex-shrink: 0;
        font-size: var(--text-table);
        font-variant-numeric: tabular-nums;
        text-align: right;
        width: 60px;
    }

    .o-qi__terminate {
        align-items: flex-start;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .o-pill {
        align-items: center;
        border-radius: 11px;
        display: inline-flex;
        flex-shrink: 0;
        font-size: var(--text-caption);
        font-weight: var(--font-weight-semibold);
        height: 22px;
        line-height: 16px;
        padding-inline: 8px;
        white-space: nowrap;
    }

    .o-pill--over { background-color: var(--color-warning-tint); color: var(--color-warning-text); }
    .o-pill--paused,
    .o-pill--unknown,
    .o-pill--idle { background-color: var(--color-subtle); color: var(--color-text-secondary); }
    .o-pill--running { background-color: color-mix(in srgb, var(--color-success) 14%, transparent); color: var(--color-text); }
</style>
