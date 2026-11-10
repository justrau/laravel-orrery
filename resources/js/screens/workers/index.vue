<script>
    import InspectorLayout from '../../shell/InspectorLayout.vue';
    import {heartbeatAge} from '../../shell/data.js';
    import {live, setToolbar} from '../../shell/store';
    import {formatDuration, formatLocal, nowSeconds, relativeTime} from '../../support/time';

    /**
     * The live workers (`GET /api/workers`): the workers table that the old
     * dashboard held, on its own screen.
     *
     * `current_job` ({ name, started_at } or null) comes later.
     * The column shows only when the response has the field. Null means that
     * the current job is unknown (the connector swap is off, or no lease was
     * observed), never that the worker is idle.
     *
     * The rows come from the shell's `live.workers` (shell/store.js),
     * which the shell polls every 10 s: the screen does not fetch
     * them again, and a failed read keeps the last rows with an error line,
     * with no global alert on every poll.
     */
    export default {
        components: {InspectorLayout},

        data() {
            return {
                live,
                now: nowSeconds(),
            };
        },

        computed: {
            workers() {
                return Array.isArray(this.live.workers) ? this.live.workers : [];
            },

            /** The workers loaded once; a later failed read keeps them. */
            ready() {
                return Array.isArray(this.live.workers);
            },

            error() {
                return this.live.workersError;
            },

            hasCurrentJob() {
                return this.workers.some(worker => worker && Object.prototype.hasOwnProperty.call(worker, 'current_job'));
            },

            hosts() {
                return new Set(this.workers.map(worker => worker.host)).size;
            },

            subtitle() {
                if (!this.ready) return this.error ? 'Workers unknown' : null;

                const workers = `${this.workers.length} ${this.workers.length === 1 ? 'worker' : 'workers'}`;

                return this.workers.length
                    ? `${workers} on ${this.hosts} ${this.hosts === 1 ? 'host' : 'hosts'} · a heartbeat in the last 60 s or a live lease`
                    : 'No live workers';
            },
        },

        watch: {
            subtitle: {
                immediate: true,
                handler(subtitle) {
                    setToolbar({title: 'Workers', subtitle});
                },
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
            queues(worker) {
                return (worker.queue || '').split(',').filter(Boolean).join(', ');
            },

            currentJob(worker) {
                const job = worker.current_job;

                if (!job || typeof job.name !== 'string') return null;

                return {
                    name: this.jobBaseName(job.name),
                    fullName: job.name,
                    running: typeof job.started_at === 'number' ? formatDuration(Math.max(0, this.now - job.started_at)) : null,
                };
            },

            started(worker) {
                return typeof worker.started_at === 'number' ? relativeTime(worker.started_at, this.now).replace(' ago', '') : '—';
            },

            startedTitle(worker) {
                return typeof worker.started_at === 'number' ? `Started ${formatLocal(worker.started_at)}` : null;
            },

            lastSeen(worker) {
                const age = heartbeatAge(worker, this.now);

                return age === null ? '—' : `${formatDuration(age)} ago`;
            },
        },
    };
</script>

<template>
    <inspector-layout>
        <div v-if="error" class="o-error" data-workers-error>
            {{ ready ? 'The workers could not be refreshed. The table shows the last data read.' : 'The workers could not be loaded.' }}
        </div>

        <div v-if="!ready && !error" class="o-muted" data-loading>Loading…</div>

        <div v-else-if="ready && !workers.length" class="o-empty">
            <div class="o-empty__title">No live workers</div>
            <div class="o-empty__text">
                No worker heartbeat in the last 60 seconds, and no live lease. Start one with
                <code>php artisan queue:work</code>, or <code>queue:listen</code> for development.
            </div>
        </div>

        <div v-else-if="ready" class="o-table" role="table" aria-label="Live workers">
            <div class="o-table__head" role="row">
                <div class="o-col o-col--worker" role="columnheader">Worker</div>
                <div class="o-col o-col--connection" role="columnheader">Connection</div>
                <div class="o-col o-col--queues" role="columnheader">Queues</div>
                <div v-if="hasCurrentJob" class="o-col o-col--job" role="columnheader">Current job</div>
                <div class="o-col o-col--started" role="columnheader">Running for</div>
                <div class="o-col o-col--seen" role="columnheader">Last heartbeat</div>
            </div>

            <div v-for="worker in workers" :key="worker.worker_id || worker.name" class="o-table__row" role="row">
                <div class="o-col o-col--worker" role="cell">
                    <span class="o-dot" :class="worker.heartbeat_fresh === false ? 'o-dot--lease' : 'o-dot--live'"
                          :title="worker.heartbeat_fresh === false ? 'No fresh heartbeat: live through an active lease' : 'Fresh heartbeat'"></span>
                    <span class="o-mono" :title="worker.worker_id">{{ worker.name }}</span>
                    <span v-if="worker.children && worker.children.length > 1" class="o-muted">{{ worker.children.length }} subprocesses seen</span>
                </div>
                <div class="o-col o-col--connection o-muted" role="cell">{{ worker.connection }}</div>
                <div class="o-col o-col--queues" role="cell">{{ queues(worker) }}</div>
                <div v-if="hasCurrentJob" class="o-col o-col--job" role="cell">
                    <template v-if="currentJob(worker)">
                        <span :title="currentJob(worker).fullName">{{ currentJob(worker).name }}</span>
                        <span v-if="currentJob(worker).running" class="o-muted"> · {{ currentJob(worker).running }}</span>
                    </template>
                    <span v-else class="o-muted" title="Unknown: the current job is known only with the connector swap on">—</span>
                </div>
                <div class="o-col o-col--started o-muted" role="cell" :title="startedTitle(worker)">{{ started(worker) }}</div>
                <div class="o-col o-col--seen o-muted" role="cell">{{ lastSeen(worker) }}</div>
            </div>
        </div>
    </inspector-layout>
</template>

<style scoped>
    .o-table {
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-card);
        font-family: var(--font-sans);
        overflow: hidden;
    }

    .o-table__head,
    .o-table__row {
        align-items: center;
        display: flex;
        gap: 16px;
        padding-inline: 14px;
    }

    .o-table__head {
        background-color: var(--color-subtle);
        border-bottom: 1px solid var(--color-separator);
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        font-weight: var(--font-weight-medium);
        height: 32px;
        line-height: 16px;
    }

    .o-table__row {
        color: var(--color-text);
        font-size: var(--text-table);
        line-height: 18px;
        min-height: 44px;
        padding-block: 10px;
    }

    .o-table__row + .o-table__row {
        border-top: 1px solid var(--color-separator);
    }

    .o-col { min-width: 0; overflow-wrap: anywhere; }
    .o-col--worker { align-items: center; display: flex; flex: 2 1 0%; gap: 8px; flex-wrap: wrap; }
    .o-col--connection { flex: 1 1 0%; }
    .o-col--queues { flex: 1.5 1 0%; }
    .o-col--job { flex: 2 1 0%; }
    .o-col--started { flex: 0 0 96px; text-align: right; }
    .o-col--seen { flex: 0 0 112px; text-align: right; }

    .o-mono {
        font-family: var(--font-mono);
        font-size: var(--text-caption);
    }

    .o-muted {
        color: var(--color-text-secondary);
    }

    .o-error {
        color: var(--color-danger);
        font-family: var(--font-sans);
        font-size: var(--text-table);
        line-height: 18px;
    }

    .o-dot {
        border-radius: 50%;
        flex-shrink: 0;
        height: 7px;
        width: 7px;
    }

    .o-dot--live { background-color: var(--color-success); }
    .o-dot--lease { background-color: var(--color-warning); }

    .o-empty {
        border: 1px dashed var(--color-separator);
        border-radius: var(--radius-card);
        display: flex;
        flex-direction: column;
        font-family: var(--font-sans);
        gap: 4px;
        padding: 20px;
    }

    .o-empty__title {
        color: var(--color-text);
        font-size: var(--text-body);
        font-weight: var(--font-weight-semibold);
        line-height: 20px;
    }

    .o-empty__text {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: 18px;
    }

    .o-empty code {
        font-family: var(--font-mono);
        font-size: var(--text-caption);
    }
</style>
