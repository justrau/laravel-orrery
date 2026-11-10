<script>
    import BarChart from '../../components/ui/BarChart.vue';
    import BulkRun from '../../components/ui/BulkRun.vue';
    import Button from '../../components/ui/Button.vue';
    import ConfirmSheet from '../../components/ui/ConfirmSheet.vue';
    import CursorPager from '../../components/ui/CursorPager.vue';
    import FilterBar from '../../components/ui/FilterBar.vue';
    import Notice from '../../components/ui/Notice.vue';
    import Sparkline from '../../components/ui/Sparkline.vue';
    import WaitGauge from '../../components/ui/WaitGauge.vue';
    import InspectorLayout from '../../shell/InspectorLayout.vue';
    import ToolbarControls from '../../shell/ToolbarControls.vue';
    import {setToolbar} from '../../shell/store';
    import {findDip, findPeak} from '../../support/series';
    import {filtersFromQuery, pageRows} from '../../support/lists.js';
    import {formatCount, formatHourMinute, nowSeconds} from '../../support/time';

    /** The rows of the sample list of the pager: ids 1–137, the newest first. */
    const SAMPLE_ROWS = 137;

    /**
     * A keyset page of the sample list, as the list engine answers it:
     * `after:<id>` and `before:<id>` cursors in the request
     * order. Development only.
     */
    function samplePage(order, cursor, perPage) {
        const ids = Array.from({length: SAMPLE_ROWS}, (_, index) => index + 1);
        const ordered = order === 'asc' ? ids : ids.reverse();
        const position = id => ordered.indexOf(id);
        let start = 0;

        if (cursor && cursor.startsWith('after:')) start = position(Number(cursor.slice(6))) + 1;
        if (cursor && cursor.startsWith('before:')) start = Math.max(0, position(Number(cursor.slice(7))) - perPage);

        const rows = ordered.slice(start, start + perPage).map(id => ({id: `job-${String(id).padStart(3, '0')}`, n: id}));
        const end = start + rows.length;

        return {
            jobs: rows,
            total: {count: SAMPLE_ROWS, at_least: false},
            order,
            prev_cursor: start > 0 ? `before:${rows[0].n}` : null,
            next_cursor: end < ordered.length ? `after:${rows[rows.length - 1].n}` : null,
        };
    }

    /**
     * A fake API client for the BulkRun sample (development only): the
     * freeze, three pages of a run, the run record and the recent runs, or a
     * refused freeze. Each answer waits a moment, as a server would.
     */
    function sampleBulkClient(scenario) {
        const wait = value => new Promise(resolve => setTimeout(() => resolve(value), 400));
        const fail = (status, data) => new Promise((resolve, reject) => setTimeout(() => reject({response: {status, data}, config: {}}), 400));
        const ref = '0123456789abcdef0123456789abcdef';
        const now = Date.now() / 1000;
        let page = 0;

        return {
            post(url, body) {
                if (url === '/api/bulk/freeze') {
                    if (scenario === 'too_many') return fail(422, {error: 'too_many', message: 'Too many.', maximum: 10000});

                    return wait({data: {
                        frozen: {
                            token: 'sample.token', ref, action: body.action, source: body.source, source_hash: 'h', max_occurrence: 991,
                            frozen_at: now, total: {count: 23, at_least: false}, exact: true,
                        },
                        held: {count: 2, at_least: false},
                        preview: [
                            {id: '71b2c9e0-0000-4000-8000-000000000001', name: 'App\\Jobs\\SendInvoice', exception_class: 'GuzzleHttp\\Exception\\ConnectException', failed_at: now - 60},
                            {id: '2a6d91c5-0000-4000-8000-000000000002', name: 'App\\Mail\\OrderShipped', exception_class: 'Symfony\\Component\\Mailer\\Exception\\TransportException', failed_at: now - 200},
                        ],
                    }});
                }

                page++;

                const results = Array.from({length: page < 3 ? 10 : 3}, (_, index) => ({
                    id: `${page}${index}b2c9e0-0000-4000-8000-00000000000${index}`, occurrence_id: page * 100 + index,
                    outcome: index === 1 && page === 1 ? 'refused' : (index === 2 && page === 2 ? 'uncertain' : 'accepted'),
                    code: index === 1 && page === 1 ? 'occurrence_mismatch' : (index === 2 && page === 2 ? 'publish_uncertain' : null),
                    message: null, operation_id: null,
                }));
                const totals = {accepted: 0, refused: 0, uncertain: 0, not_member: 0, already_done: 0, not_confirmed: 0};

                results.forEach(item => { totals[item.outcome]++; });

                return wait({data: {
                    results, totals, refused_reasons: page === 1 ? {occurrence_mismatch: 1} : {},
                    next_after: page < 3 ? `page-${page}` : null, done: page >= 3, remaining: {count: Math.max(0, 23 - page * 10), at_least: false},
                }});
            },

            get(url) {
                const record = {
                    ref, action: 'retry', source: {kind: 'batch', batch_id: 'b-november'}, max_occurrence: 991, total: {count: 23, at_least: false},
                    confirmed_total: null, claimed: 21, counters: {accepted: 21, refused: 1, uncertain: 1, not_member: 0, already_done: 0, not_confirmed: 0},
                    actor: 'ops@acme.test', created_at: now - 120, last_run_at: now - 60,
                    operations: {claimed: 0, published: 1, done: 20, aborting: 0, aborted: 0},
                };

                if (url === '/api/bulk') return wait({data: {runs: [record, {...record, ref: 'f'.repeat(32), action: 'forget', actor: null, last_run_at: now - 7200}]}});

                return wait({data: record});
            },
        };
    }

    /**
     * A temporary, development-only gallery of the UI components with
     * sample props. The router registers /gallery only in the
     * local environment. Remove it with the last task of the UI work.
     */
    export default {
        components: {BarChart, BulkRun, 'o-button': Button, ConfirmSheet, CursorPager, FilterBar, InspectorLayout, Notice, Sparkline, ToolbarControls, WaitGauge},

        data() {
            // The past hour: 60 buckets, the last one the current, partial minute.
            const now = Math.floor(nowSeconds() / 60) * 60 + 42;
            const to = now;
            const from = Math.floor(to / 60) * 60 - 59 * 60;

            return {
                now,
                from,
                to,
                sheet: {open: false, tone: 'primary'},
                live: {tick: 0},
                // The inspector slot is added and dropped at runtime (InspectorLayout).
                showInspector: false,
                sheetKey: 0,
                // Mutated in place every second, as a background refresh of a
                // screen would: the open sheet must keep its snapshot.
                selection: {
                    scope: {queue: 'database:emails', from, to},
                    keys: ['a1b2c3'],
                    pairs: [
                        {uuid: '1f9e8c52-6a52-4c8e-9d3b-2f1e3a4b5c6d', occurrence_id: 3},
                        {uuid: '7c2d1e0f-3b4a-4f5e-8a9b-0c1d2e3f4a5b', occurrence_id: 1},
                    ],
                    liveTick: 0,
                },
                confirmed: null,
                alertAnswer: null,
                ticker: null,
                pager: {order: 'desc', response: samplePage('desc', null, 25)},
                perPage: 25,
                bulk: {open: false, action: 'retry', scenario: 'ok', source: {kind: 'batch', batch_id: 'b-november'}, key: 0},
                sampleCounts: {
                    pending: {count: 10216, at_least: false}, running: {count: 14, at_least: false},
                    completed: {count: 1000000, at_least: true}, silenced: {count: 3408, at_least: false},
                    failed: {count: 312, at_least: false}, retrying: {count: 2, at_least: false}, unknown: {count: null, at_least: false},
                },
            };
        },

        computed: {
            listFilters() {
                return filtersFromQuery(this.$route.query);
            },

            pagerRows() {
                return pageRows(this.pager.response.jobs, this.pager.order, 'desc');
            },

            bulkClient() {
                return sampleBulkClient(this.bulk.scenario);
            },

            buckets() {
                const first = Math.floor(this.from / 60) * 60;
                const buckets = [];

                for (let index = 0; index < 60; index++) {
                    const at = first + index * 60;

                    // Minutes 30–35 were never observed: absent buckets, shown as a gap.
                    if (index >= 30 && index <= 35) continue;

                    const deploy = index >= 42 && index <= 47;
                    let completed = 1150 + ((index * 37) % 130);

                    if (index === 42) completed = 2900;
                    if (index === 43) completed = 1750;
                    if (index >= 44 && index <= 46) completed = 560 + index * 3;

                    buckets.push({
                        at,
                        completed,
                        failed: deploy ? [5, 10, 8, 3, 2, 1][index - 42] : (index % 11 === 3 ? 1 : 0),
                        other: deploy ? 1 : (index % 7 === 2 ? 1 : 0),
                        partial: index === 59 ? true : undefined,
                    });
                }

                return buckets;
            },

            retentionFrom() {
                return Math.floor(this.from / 60) * 60 + 8 * 60;
            },

            deployAt() {
                return Math.floor(this.from / 60) * 60 + 42 * 60 + 12;
            },

            markers() {
                return [
                    {at: this.deployAt, kind: 'deploy', label: null},
                    {at: Math.floor(this.from / 60) * 60 + 14 * 60 + 5, kind: 'restart', label: 'Restart requested'},
                ];
            },

            annotations() {
                const peak = findPeak(this.buckets, 'completed');
                const dip = findDip(this.buckets, 'completed', 60);
                const first = Math.floor(this.from / 60) * 60;

                return [
                    peak && {at: peak.at, text: `Peak ${formatCount(peak.value)}/min`, target: 'main'},
                    dip && {at: dip.from, to: dip.to, text: `Dip to ~${formatCount(Math.round(dip.value / 10) * 10)}/min`, target: 'main'},
                    {at: first + 42 * 60, to: first + 47 * 60, text: `29 failures ${formatHourMinute(first + 42 * 60)}–${formatHourMinute(first + 47 * 60)}`, target: 'band'},
                ].filter(Boolean);
            },

            sparkBuckets() {
                return this.buckets.map(bucket => ({at: bucket.at, failed: bucket.failed}));
            },

            backlog() {
                const first = Math.floor(this.from / 60) * 60;
                const buckets = [];

                for (let index = 0; index < 60; index++) {
                    if (index >= 20 && index <= 23) continue;

                    buckets.push({at: first + index * 60, ready: index < 42 ? 1340 + (index % 5) * 3 : 1340 + (index - 41) * 420});
                }

                return buckets;
            },

        },

        mounted() {
            setToolbar({title: 'Component gallery', subtitle: 'Development only · sample props'});

            // A background "refresh": it changes the live selection every second.
            // The open confirmation sheet must keep showing its snapshot.
            this.ticker = setInterval(() => {
                this.live.tick++;
                this.selection.liveTick = this.live.tick;
                this.selection.pairs[0].occurrence_id = 3 + this.live.tick;
            }, 1000);
        },

        beforeUnmount() {
            clearInterval(this.ticker);
        },

        methods: {
            openSheet(tone) {
                this.confirmed = null;
                this.sheetKey++;
                this.sheet = {open: true, tone};
            },

            showAlert(type) {
                const alert = this.$root.alert;

                alert.type = type;
                alert.autoClose = 0;
                alert.message = {
                    error: 'The request failed (HTTP 500). A sample error of the alert host.',
                    reload: 'Your session has expired. Reload the page to sign in again.',
                    success: 'The sample action is done.',
                    confirmation: 'Do you want to run the sample action?',
                }[type];
                alert.confirmationProceed = type === 'confirmation' ? () => { this.alertAnswer = 'Yes'; } : null;
                alert.confirmationCancel = type === 'confirmation' ? () => { this.alertAnswer = 'Cancel'; } : null;
            },

            navigate(request) {
                this.pager = {order: request.order, response: samplePage(request.order, request.cursor, this.perPage)};
            },

            setPerPage(value) {
                this.perPage = value;
                this.pager = {order: 'desc', response: samplePage('desc', null, value)};
            },

            openBulk(action, scenario, source = {kind: 'batch', batch_id: 'b-november'}) {
                this.bulk = {open: true, action, scenario, source, key: this.bulk.key + 1};
            },

            onConfirm(selection) {
                this.confirmed = selection;
                this.sheet.open = false;
            },
        },
    };
</script>

<template>
    <inspector-layout>
        <toolbar-controls>
            <o-button size="md" icon="pause">Pause…</o-button>
        </toolbar-controls>

        <section class="g-section" data-gallery="inspector">
            <h2 class="g-heading">InspectorLayout · a runtime inspector slot</h2>
            <div class="g-row g-row--center">
                <o-button data-toggle-inspector @click="showInspector = !showInspector">{{ showInspector ? 'Drop the inspector slot' : 'Add the inspector slot' }}</o-button>
                <span class="g-note">Inspector slot: <span data-inspector-slot>{{ showInspector ? 'present' : 'absent' }}</span></span>
            </div>
        </section>

        <template v-if="showInspector" #inspector>
            <div class="g-inspector" data-gallery-inspector>
                <div class="g-label">Inspector</div>
                <wait-gauge size="large" :oldest-age="134" :threshold="60" :over-limit="true" />
            </div>
        </template>

        <section class="g-section" data-gallery="gauges">
            <h2 class="g-heading">WaitGauge</h2>
            <div class="g-row">
                <div class="g-card"><div class="g-label">Within limit</div><wait-gauge :oldest-age="12" :threshold="60" :over-limit="false" /></div>
                <div class="g-card g-card--over"><div class="g-label">Over limit</div><wait-gauge :oldest-age="134" :threshold="60" :over-limit="true" /></div>
                <div class="g-card"><div class="g-label">Beyond the scale (9 min)</div><wait-gauge :oldest-age="540" :threshold="60" :over-limit="true" /></div>
                <div class="g-card"><div class="g-label">No limit</div><wait-gauge :oldest-age="45" :threshold="0" :over-limit="false" /></div>
                <div class="g-card g-card--paused"><div class="g-label">Paused</div>
                    <wait-gauge :oldest-age="0" :threshold="60" :paused="{scope: 'database:webhooks', issued_at: now - 840, issued_by: 'operator'}" :now="now" /></div>
                <div class="g-card"><div class="g-label">Nothing waiting</div><wait-gauge :oldest-age="null" :threshold="60" /></div>
            </div>
            <div class="g-row">
                <div class="g-card"><div class="g-label">Large, over limit</div><wait-gauge size="large" :oldest-age="134" :threshold="60" :over-limit="true" /></div>
                <div class="g-card"><div class="g-label">Large, within limit</div><wait-gauge size="large" :oldest-age="3" :threshold="60" /></div>
                <div class="g-card"><div class="g-label">Large, no limit</div><wait-gauge size="large" :oldest-age="75" :threshold="0" /></div>
            </div>
        </section>

        <section class="g-section" data-gallery="chart">
            <h2 class="g-heading">BarChart · throughput with a failures band, markers, annotations and gaps</h2>
            <div class="g-chart">
                <bar-chart :from="from" :to="to" :bucket-seconds="60" :buckets="buckets" unit="/min"
                           :series="[{key: 'completed', label: 'completed', color: 'var(--color-accent)', retentionFrom: retentionFrom}]"
                           :band="{key: 'failed', label: 'Failed', color: 'var(--color-danger)', retentionFrom: from}"
                           :markers="markers" :annotations="annotations" />
            </div>
            <h2 class="g-heading">BarChart · stacked (a group and the other groups)</h2>
            <div class="g-chart">
                <bar-chart :from="from" :to="to" :bucket-seconds="60" :buckets="buckets" :y-ticks="2"
                           :series="[{key: 'failed', label: 'TransportException', color: 'var(--color-danger)', retentionFrom: from},
                                     {key: 'other', label: 'other groups', color: 'var(--color-separator)', retentionFrom: from}]"
                           :markers="[markers[0]]" />
            </div>
        </section>

        <section class="g-section" data-gallery="sparklines">
            <h2 class="g-heading">Sparkline</h2>
            <div class="g-row g-row--center">
                <div class="g-spark"><div class="g-label">Bars</div>
                    <sparkline :from="from" :to="to" :bucket-seconds="60" :buckets="sparkBuckets" value-key="failed" :retention-from="from" /></div>
                <div class="g-spark"><div class="g-label">Bars with an unknown start</div>
                    <sparkline :from="from" :to="to" :bucket-seconds="60" :buckets="sparkBuckets" value-key="failed" :retention-from="retentionFrom" /></div>
                <div class="g-spark"><div class="g-label">Line with a gap</div>
                    <sparkline variant="line" :width="168" :height="60" :from="from" :to="to" :bucket-seconds="60" :buckets="backlog" value-key="ready"
                               color="var(--color-warning)" fill="var(--color-warning-tint)" /></div>
            </div>
        </section>

        <section class="g-section" data-gallery="notices">
            <h2 class="g-heading">Notice</h2>
            <notice tone="warning" title="emails has waited 2 min 14 s, over its 60 s limit" detail="8,931 ready on 4 workers · over the limit since 09:17">
                <template #link><o-button variant="link" to="/queues">Show queue</o-button></template>
                <template #actions><o-button icon="pause" operate>Pause emails…</o-button></template>
            </notice>
            <notice tone="danger" title="27 failed with TransportException since 09:14"
                    detail="Connection to smtp.postmarkapp.com:587 timed out · OrderShipped · emails · last 2 min ago">
                <template #badge><span class="g-chip">Deploy 09:14</span></template>
                <template #link><o-button variant="link" to="/failed">Show group</o-button></template>
                <template #actions><o-button icon="retry" operate>Retry 27…</o-button></template>
            </notice>
            <notice tone="info" title="Older failures are no longer kept" detail="Failures before 09:00 are past the retention." />
            <notice tone="success" title="No other failures on emails in the past hour" />
        </section>

        <section class="g-section" data-gallery="buttons">
            <h2 class="g-heading">Button</h2>
            <div class="g-row g-row--center">
                <o-button variant="link" to="/queues">Show queue</o-button>
                <o-button icon="pause">Pause all…</o-button>
                <o-button size="sm" icon="pause">Pause</o-button>
                <o-button size="sm" icon="play" accent>Continue</o-button>
                <o-button variant="danger" icon="power">Terminate all workers…</o-button>
                <o-button variant="primary" size="lg" icon="retry">Retry 27 jobs</o-button>
                <o-button size="lg">Forget…</o-button>
                <o-button size="lg" icon="more" title="More" />
                <o-button icon="pause" disabled>Disabled</o-button>
            </div>
        </section>

        <section class="g-section" data-gallery="sheet">
            <h2 class="g-heading">ConfirmSheet</h2>
            <div class="g-row g-row--center">
                <o-button variant="primary" size="lg" icon="retry" data-open-sheet="primary" @click="openSheet('primary')">Retry 2 jobs…</o-button>
                <o-button variant="danger" icon="power" data-open-sheet="danger" @click="openSheet('danger')">Terminate workers…</o-button>
                <span class="g-note">Live selection tick: <span data-live-tick>{{ live.tick }}</span></span>
                <span v-if="confirmed" class="g-note" data-confirmed>Confirmed the snapshot of tick {{ confirmed.liveTick }}</span>
            </div>
        </section>

        <confirm-sheet :open="sheet.open" :tone="sheet.tone" :selection="selection" :snapshot-key="sheetKey"
                       :title="sheet.tone === 'danger' ? 'Terminate workers…' : `Retry the 2 most recent of 2 (tick ${live.tick})`"
                       :message="sheet.tone === 'danger'
                           ? 'Stops every worker after its current job. Your process manager starts them again.'
                           : 'The selection below is frozen when this sheet opens.'"
                       :confirm-label="sheet.tone === 'danger' ? 'Terminate' : 'Retry 2 jobs'"
                       @confirm="onConfirm" @cancel="sheet.open = false">
            <template #default="{selection: frozen}">
                <div class="g-frozen" data-frozen-tick>Snapshot tick: {{ frozen.liveTick }}</div>
                <button type="button" class="g-resnap" data-resnapshot @click="sheetKey++">Take a new snapshot (snapshotKey)</button>
                <ul class="g-pairs">
                    <li v-for="pair in frozen.pairs" :key="pair.uuid"><code>{{ pair.uuid }}</code> · occurrence {{ pair.occurrence_id }}</li>
                </ul>
            </template>
        </confirm-sheet>

        <section class="g-section" data-gallery="alert-host">
            <h2 class="g-heading">AlertHost · the global alert ($root.alert)</h2>
            <div class="g-row g-row--center">
                <o-button data-alert-sample="error" @click="showAlert('error')">Error</o-button>
                <o-button data-alert-sample="reload" @click="showAlert('reload')">Session ended</o-button>
                <o-button data-alert-sample="success" @click="showAlert('success')">Success</o-button>
                <o-button data-alert-sample="confirmation" @click="showAlert('confirmation')">Confirmation</o-button>
                <span v-if="alertAnswer" class="g-note" data-alert-answer>Answered: {{ alertAnswer }}</span>
            </div>
        </section>

        <section class="g-section" data-gallery="filter-bar">
            <h2 class="g-heading">FilterBar · tabs, search, queue, class, tag, time range (synced to this URL)</h2>
            <filter-bar :filters="listFilters" tabs :counts="sampleCounts" />
            <div class="g-note" data-filter-query>Query: {{ JSON.stringify($route.query) }}</div>
        </section>

        <section class="g-section" data-gallery="cursor-pager">
            <h2 class="g-heading">CursorPager · 137 sample rows</h2>
            <div class="g-note" data-pager-rows>Rows: {{ pagerRows.length ? `${pagerRows[0].id} … ${pagerRows[pagerRows.length - 1].id}` : 'none' }}</div>
            <cursor-pager :page="pager" :rows="pager.response.jobs.length" :total="pager.response.total" :per-page="perPage"
                          @navigate="navigate" @update:per-page="setPerPage" />
        </section>

        <section class="g-section" data-gallery="bulk-run">
            <h2 class="g-heading">BulkRun · a sample client, no server writes</h2>
            <div class="g-row g-row--center">
                <o-button operate icon="retry" data-bulk-sample="retry" @click="openBulk('retry', 'ok')">Retry all 23…</o-button>
                <o-button operate data-bulk-sample="forget" @click="openBulk('forget', 'ok')">Forget all 23…</o-button>
                <o-button operate data-bulk-sample="too-many" @click="openBulk('forget', 'too_many')">Forget (too many)…</o-button>
                <o-button operate data-bulk-sample="search" @click="openBulk('retry', 'ok', {kind: 'list', connection: 'database', queue: 'emails', q: 'mail'})">Retry all matching (search set)…</o-button>
            </div>
            <bulk-run :open="bulk.open" :action="bulk.action" :source="bulk.source" :selection-key="bulk.key"
                      :client="bulkClient" recover storage-key="orreryGalleryBulkRun" source-kind="batch"
                      @started="bulk.open = false" @close="bulk.open = false" @restart="openBulk('retry', 'ok')" />
        </section>
    </inspector-layout>
</template>

<style scoped>
    .g-section { display: flex; flex-direction: column; gap: 12px; font-family: var(--font-sans); }
    .g-heading { color: var(--color-text); font-size: var(--text-section); font-weight: 600; letter-spacing: -0.01em; line-height: 22px; margin: 0; }
    .g-row { display: flex; flex-wrap: wrap; gap: 16px; }
    .g-row--center { align-items: center; }
    .g-card { border: 1px solid var(--color-separator); border-radius: var(--radius-card); display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 14px 16px; }
    .g-card--over { border-color: color-mix(in srgb, var(--color-warning) 65%, transparent); box-shadow: color-mix(in srgb, var(--color-warning) 12%, transparent) 0 0 0 3px; }
    .g-card--paused { background-color: var(--color-subtle); }
    .g-label { align-self: flex-start; color: var(--color-text-secondary); font-size: var(--text-caption); line-height: 16px; }
    .g-chart { border: 1px solid var(--color-separator); border-radius: var(--radius-card); padding: 14px 16px 12px; max-width: 900px; }
    .g-spark { display: flex; flex-direction: column; gap: 6px; }
    .g-chip { align-items: center; background: var(--color-bg); border: 1px solid var(--color-separator); border-radius: 5px; color: var(--color-text-secondary); display: inline-flex; font-size: var(--text-caption); font-weight: 500; height: 20px; padding-inline: 7px; }
    .g-note { color: var(--color-text-secondary); font-size: var(--text-table); }
    .g-resnap { background: none; border: none; color: var(--color-accent); cursor: pointer; font-size: var(--text-caption); margin-bottom: 6px; padding: 0; }
    .g-frozen { font-weight: 600; margin-bottom: 6px; }
    .g-pairs { margin: 0; padding-left: 18px; color: var(--color-text-secondary); }
    .g-pairs code { font-family: var(--font-mono); font-size: var(--text-caption); color: var(--color-text); }
</style>
