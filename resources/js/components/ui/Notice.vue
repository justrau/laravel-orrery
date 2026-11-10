<script>
    import Icon from './Icon.vue';

    const ICONS = {warning: 'warning', danger: 'error', info: 'info', success: 'success'};

    /**
     * An attention notice: a tinted row with an icon, a title, a detail line
     * and actions. The slot `badge` follows the title (for example a marker
     * chip), the slot `link` holds a read link, and the slot `actions` the
     * write buttons. The text is rendered as text, never as HTML.
     */
    export default {
        components: {Icon},

        props: {
            tone: {
                type: String,
                default: 'warning',
                validator: value => Object.prototype.hasOwnProperty.call(ICONS, value),
            },
            title: {type: String, required: true},
            detail: {type: String, default: null},
        },

        computed: {
            icon() {
                return ICONS[this.tone];
            },
        },
    };
</script>

<template>
    <div class="o-notice" :class="`o-notice--${tone}`" :role="tone === 'danger' || tone === 'warning' ? 'alert' : 'status'">
        <icon :name="icon" :size="20" class="o-notice__icon" />
        <div class="o-notice__text">
            <div class="o-notice__title-row">
                <div class="o-notice__title">{{ title }}</div>
                <slot name="badge" />
            </div>
            <div v-if="detail || $slots.detail" class="o-notice__detail">
                <slot name="detail">{{ detail }}</slot>
            </div>
        </div>
        <div v-if="$slots.link" class="o-notice__link"><slot name="link" /></div>
        <div v-if="$slots.actions" class="o-notice__actions"><slot name="actions" /></div>
    </div>
</template>

<style scoped>
    .o-notice {
        align-items: center;
        border-radius: var(--radius-card);
        border: 1px solid transparent;
        box-sizing: border-box;
        display: flex;
        font-family: var(--font-sans);
        gap: 12px;
        padding: 11px 14px;
    }

    .o-notice--warning {
        background-color: var(--color-warning-tint);
        border-color: color-mix(in srgb, var(--color-warning) 35%, transparent);
    }

    .o-notice--warning .o-notice__icon { color: var(--color-warning); }

    .o-notice--danger {
        background-color: var(--color-danger-tint);
        border-color: color-mix(in srgb, var(--color-danger) 28%, transparent);
    }

    .o-notice--danger .o-notice__icon { color: var(--color-danger); }

    .o-notice--info {
        background-color: var(--color-accent-tint);
        border-color: color-mix(in srgb, var(--color-accent) 30%, transparent);
    }

    .o-notice--info .o-notice__icon { color: var(--color-accent); }

    .o-notice--success {
        background-color: var(--color-subtle);
        border-color: var(--color-separator);
    }

    .o-notice--success .o-notice__icon { color: var(--color-success); }

    .o-notice__text {
        display: flex;
        flex: 1 1 0%;
        flex-direction: column;
        gap: 1px;
        min-width: 0;
    }

    .o-notice__title-row {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .o-notice__title {
        color: var(--color-text);
        font-size: var(--text-body);
        font-weight: var(--font-weight-semibold);
        line-height: 20px;
    }

    .o-notice__detail {
        color: var(--color-text-secondary);
        font-size: var(--text-table);
        line-height: 18px;
        overflow-wrap: anywhere;
    }

    .o-notice__link {
        display: flex;
        flex-shrink: 0;
        justify-content: flex-end;
        padding-inline: 8px;
        width: 124px;
    }

    .o-notice__actions {
        align-items: center;
        display: flex;
        flex-shrink: 0;
        gap: 8px;
    }

    /* Below 768 px the link and the actions go below the text, so the title keeps the width. */
    @media (max-width: 767px) {
        .o-notice {
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 8px 12px;
        }

        .o-notice__text {
            flex-basis: calc(100% - 32px);
        }

        .o-notice__link {
            justify-content: flex-start;
            padding-inline: 32px 0;
            width: auto;
        }

        .o-notice__actions {
            flex-shrink: 1;
            flex-wrap: wrap;
            min-width: 0;
            padding-left: 32px;
        }
    }
</style>
