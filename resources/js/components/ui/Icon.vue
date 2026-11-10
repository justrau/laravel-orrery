<script>
    /**
     * The icons of the design. Every
     * icon draws with currentColor; the parent sets the color.
     */
    export default {
        props: {
            name: {type: String, required: true},
            size: {type: Number, default: 16},
        },
    };
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg" class="o-icon" aria-hidden="true" focusable="false">
        <template v-if="name === 'overview'">
            <path d="M2.5 11a5.5 5.5 0 1 1 11 0" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
            <path d="M8 11l2.6-3.2" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
        </template>
        <template v-else-if="name === 'pending'">
            <circle cx="8" cy="8" r="5.8" fill="none" stroke="currentColor" stroke-width="1.4" />
            <path d="M8 5v3.2l2 1.3" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
        </template>
        <template v-else-if="name === 'completed'">
            <circle cx="8" cy="8" r="5.8" fill="none" stroke="currentColor" stroke-width="1.4" />
            <path d="M5.4 8.2l1.8 1.8 3.4-3.6" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
        </template>
        <template v-else-if="name === 'failed'">
            <circle cx="8" cy="8" r="5.8" fill="none" stroke="currentColor" stroke-width="1.4" />
            <path d="M8 4.8v3.8M8 10.6v.4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
        </template>
        <template v-else-if="name === 'running'">
            <circle cx="8" cy="8" r="5.8" fill="none" stroke="currentColor" stroke-width="1.4" />
            <path d="M6.6 5.4v5.2L10.8 8z" fill="currentColor" />
        </template>
        <template v-else-if="name === 'failed-job'">
            <circle cx="8" cy="8" r="5.8" fill="none" stroke="currentColor" stroke-width="1.4" />
            <path d="M6 6l4 4M10 6l-4 4" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
        </template>
        <template v-else-if="name === 'stuck'">
            <path d="M8 2.6l5.6 9.8H2.4z" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" />
            <path d="M8 6.6v2.6M8 10.9v.2" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
        </template>
        <template v-else-if="name === 'sidebar'">
            <rect x="1.2" y="2" width="13.6" height="12" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.3" />
            <path d="M5.8 2.5v11" fill="none" stroke="currentColor" stroke-width="1.3" />
        </template>
        <template v-else-if="name === 'silenced'">
            <path d="M3 6.2h2.3L8.4 3.5v9L5.3 9.8H3z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round" />
            <path d="M11 6.2l3 3.4M14 6.2l-3 3.4" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" />
        </template>
        <template v-else-if="name === 'queue'">
            <rect x="2.5" y="3" width="11" height="3" rx="1" fill="none" stroke="currentColor" stroke-width="1.3" />
            <rect x="2.5" y="7.5" width="11" height="3" rx="1" fill="none" stroke="currentColor" stroke-width="1.3" />
            <path d="M4 12.8h8" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" />
        </template>
        <template v-else-if="name === 'batches'">
            <rect x="2.5" y="2.5" width="11" height="11" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.3" />
            <path d="M5 8h6M5 10.8h3.5M5 5.2h6" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" />
        </template>
        <template v-else-if="name === 'metrics'">
            <path d="M3 13V8.5M6.3 13V5M9.7 13V7M13 13V3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
        </template>
        <template v-else-if="name === 'tags'">
            <path d="M2.5 3.5v4.2l6.3 6.3 5-5-6.3-6.3H3.5z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round" />
            <circle cx="5.4" cy="5.4" r="1" fill="currentColor" />
        </template>
        <template v-else-if="name === 'workers'">
            <rect x="2" y="3" width="12" height="4" rx="1.2" fill="none" stroke="currentColor" stroke-width="1.3" />
            <rect x="2" y="9" width="12" height="4" rx="1.2" fill="none" stroke="currentColor" stroke-width="1.3" />
            <circle cx="4.6" cy="5" r="0.8" fill="currentColor" />
            <circle cx="4.6" cy="11" r="0.8" fill="currentColor" />
        </template>
        <template v-else-if="name === 'pause'">
            <rect x="4" y="3.5" width="2.6" height="9" rx="0.8" fill="currentColor" />
            <rect x="9.4" y="3.5" width="2.6" height="9" rx="0.8" fill="currentColor" />
        </template>
        <template v-else-if="name === 'paused-small'">
            <rect x="3.3" y="2.7" width="3.2" height="10.6" rx="0.8" fill="currentColor" />
            <rect x="9.5" y="2.7" width="3.2" height="10.6" rx="0.8" fill="currentColor" />
        </template>
        <template v-else-if="name === 'play'">
            <path d="M5 3.4v9.2a.6.6 0 0 0 .9.5l7.3-4.6a.6.6 0 0 0 0-1L5.9 2.9a.6.6 0 0 0-.9.5z" fill="currentColor" />
        </template>
        <template v-else-if="name === 'retry'">
            <path d="M12.6 8.2A4.6 4.6 0 1 1 11.2 4.6" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
            <path d="M11.6 2.2v2.8H8.8" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
        </template>
        <template v-else-if="name === 'power'">
            <path d="M5.2 4.4a5 5 0 1 0 5.6 0" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
            <path d="M8 2.5v5" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
        </template>
        <template v-else-if="name === 'chevron'">
            <path d="M6 3.3L10.7 8 6 12.7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
        </template>
        <template v-else-if="name === 'warning'">
            <path d="M8 2L14.4 13.2H1.6z" fill="currentColor" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round" />
            <path d="M8 6.4v3.2" fill="none" stroke="#FFFFFF" stroke-width="1.45" stroke-linecap="round" />
            <circle cx="8" cy="11.4" r="0.8" fill="#FFFFFF" />
        </template>
        <template v-else-if="name === 'warning-small'">
            <path d="M8 1.6L14.9 13.8H1.1z" fill="currentColor" />
        </template>
        <template v-else-if="name === 'error'">
            <circle cx="8" cy="8" r="6.4" fill="currentColor" />
            <path d="M8 4.6v4" fill="none" stroke="#FFFFFF" stroke-width="1.45" stroke-linecap="round" />
            <circle cx="8" cy="11.1" r="0.8" fill="#FFFFFF" />
        </template>
        <template v-else-if="name === 'info'">
            <circle cx="8" cy="8" r="6" fill="none" stroke="currentColor" stroke-width="1.3" />
            <path d="M8 7.2v4" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
            <circle cx="8" cy="5" r="0.8" fill="currentColor" />
        </template>
        <template v-else-if="name === 'success'">
            <circle cx="8" cy="8" r="6.4" fill="none" stroke="currentColor" stroke-width="1.3" />
            <path d="M5.4 8.2l1.8 1.8 3.4-3.6" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
        </template>
        <template v-else-if="name === 'more'">
            <circle cx="3" cy="8" r="1.4" fill="currentColor" />
            <circle cx="8" cy="8" r="1.4" fill="currentColor" />
            <circle cx="13" cy="8" r="1.4" fill="currentColor" />
        </template>
        <template v-else-if="name === 'inspector'">
            <rect x="1.2" y="2" width="13.6" height="12" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.3" />
            <path d="M10.2 2.5v11" fill="none" stroke="currentColor" stroke-width="1.3" />
        </template>
        <template v-else-if="name === 'marker'">
            <path d="M8 1.5v13" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-dasharray="2 1.6" />
        </template>
    </svg>
</template>

<style scoped>
    .o-icon {
        flex-shrink: 0;
        display: block;
    }
</style>
