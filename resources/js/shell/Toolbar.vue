<script>
    import Icon from '../components/ui/Icon.vue';
    import {liveIndicator} from './data.js';
    import {inspectorVisible, live, liveFailed, shell, toggleInspector, toggleNav} from './store.js';

    /**
     * The toolbar of the shell: the screen
     * title and subtitle, a slot for the screen's controls (ToolbarControls
     * teleports into #orrery-toolbar-controls), "Live · time", and the
     * inspector toggle of the screens with an inspector.
     *
     * Below 1024 px it starts with the sidebar toggle,
     * and every part may shrink: the title truncates,
     * and below 768 the subtitle goes and "Live" shows the minute with the
     * zone, so the toolbar never widens the page.
     */
    export default {
        components: {Icon},

        emits: ['nav-opened'],

        props: {
            now: {type: Number, default: null},
        },

        data() {
            return {shell, live};
        },

        computed: {
            title() {
                return this.shell.title || (this.$route.meta && this.$route.meta.title) || 'Orrery';
            },

            subtitle() {
                return this.shell.subtitle || (this.$route.meta && this.$route.meta.subtitle) || null;
            },

            liveState() {
                return liveIndicator(this.live.at, this.now, liveFailed());
            },

            inspectorShown() {
                return inspectorVisible();
            },
        },

        watch: {
            title: {
                immediate: true,
                handler(title) {
                    document.title = title === 'Orrery' ? 'Orrery' : `${title} · Orrery`;
                },
            },
        },

        methods: {
            toggleInspector() {
                toggleInspector();
            },

            toggleNav() {
                toggleNav();

                if (this.shell.navOpen) this.$emit('nav-opened');
            },
        },
    };
</script>

<template>
    <header class="o-toolbar" :class="{'o-toolbar--narrow': shell.narrow}">
        <button v-if="shell.narrow" id="orrery-nav-toggle" type="button" class="o-toolbar__toggle o-toolbar__nav"
                :class="{'o-toolbar__toggle--active': shell.navOpen}" aria-controls="orrery-nav"
                :aria-expanded="shell.navOpen ? 'true' : 'false'" :title="shell.navOpen ? 'Hide the sidebar' : 'Show the sidebar'"
                @click="toggleNav">
            <icon name="sidebar" :size="16" />
        </button>

        <div class="o-toolbar__title">
            <h1 class="o-toolbar__heading" tabindex="-1">{{ title }}</h1>
            <div v-if="subtitle" class="o-toolbar__subtitle" :title="subtitle">{{ subtitle }}</div>
        </div>

        <div id="orrery-toolbar-controls" class="o-toolbar__controls"></div>

        <div class="o-toolbar__live" :class="`o-toolbar__live--${liveState.tone}`" :title="liveState.title">
            <span class="o-toolbar__dot"></span>
            <span class="o-toolbar__live-long">{{ liveState.text }}</span>
            <span class="o-toolbar__live-short">{{ liveState.short }}</span>
        </div>

        <button v-if="shell.inspectorScreens > 0" type="button" class="o-toolbar__toggle" :class="{'o-toolbar__toggle--active': inspectorShown}"
                :aria-pressed="inspectorShown ? 'true' : 'false'" :title="inspectorShown ? 'Hide the inspector' : 'Show the inspector'"
                @click="toggleInspector">
            <icon name="inspector" :size="16" />
        </button>
    </header>
</template>

<style scoped>
    .o-toolbar {
        align-items: center;
        background-color: var(--color-bg);
        border-bottom: 1px solid var(--color-separator);
        box-sizing: border-box;
        display: flex;
        flex-shrink: 0;
        font-family: var(--font-sans);
        gap: 14px;
        height: 52px;
        padding-left: 24px;
        padding-right: 16px;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }

    .o-toolbar__title {
        display: flex;
        flex: 0 1 auto;
        flex-direction: column;
        max-width: 420px;
        min-width: 0;
    }

    .o-toolbar--narrow {
        gap: 10px;
        padding-left: 10px;
        padding-right: 12px;
    }

    .o-toolbar__nav {
        width: 32px;
    }

    .o-toolbar__live-short {
        display: none;
    }

    .o-toolbar__heading {
        color: var(--color-text);
        font-size: var(--text-section);
        font-weight: var(--font-weight-semibold);
        letter-spacing: -0.01em;
        line-height: 22px;
        margin: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* The shell focuses the heading after a held navigation (AppShell holdChanged()); only the Tab key's focus shows a ring. */
    .o-toolbar__heading:focus:not(:focus-visible) {
        outline: none;
    }

    .o-toolbar__subtitle {
        color: var(--color-text-secondary);
        font-size: var(--text-caption);
        line-height: 16px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* Never narrower than its own controls: a long title truncates instead, so the screen's
       controls never shrink under "Live" (a long job name at 375 and 768 px). */
    .o-toolbar__controls {
        align-items: center;
        display: flex;
        flex: 1 0 auto;
        gap: 8px;
        min-width: 0;
    }

    .o-toolbar__live {
        align-items: center;
        color: var(--color-text-secondary);
        display: flex;
        flex-shrink: 0;
        font-size: var(--text-table);
        font-variant-numeric: tabular-nums;
        gap: 6px;
        height: 28px;
        line-height: 18px;
        padding-inline: 4px;
    }

    .o-toolbar__dot {
        border-radius: 50%;
        flex-shrink: 0;
        height: 7px;
        width: 7px;
    }

    .o-toolbar__live--live .o-toolbar__dot { background-color: var(--color-success); }
    .o-toolbar__live--stale .o-toolbar__dot { background-color: var(--color-warning); }
    .o-toolbar__live--unknown .o-toolbar__dot { background-color: var(--color-text-tertiary); }

    .o-toolbar__toggle {
        align-items: center;
        background: none;
        border: none;
        border-radius: var(--radius-control);
        color: var(--color-text-secondary);
        cursor: pointer;
        display: flex;
        flex-shrink: 0;
        height: 28px;
        justify-content: center;
        padding: 0;
        width: 30px;
    }

    .o-toolbar__toggle:hover {
        background-color: var(--color-subtle);
    }

    .o-toolbar__toggle--active,
    .o-toolbar__toggle--active:hover {
        background-color: var(--color-separator);
    }

    .o-toolbar__toggle:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: 1px;
    }

    /* Below 768 px: no subtitle, and the minute with its zone. */
    @media (max-width: 767px) {
        .o-toolbar__subtitle,
        .o-toolbar__live-long {
            display: none;
        }

        .o-toolbar__live-short {
            display: inline;
        }

        .o-toolbar__live {
            font-size: var(--text-caption);
        }

        .o-toolbar__heading {
            line-height: 20px;
        }
    }
</style>
