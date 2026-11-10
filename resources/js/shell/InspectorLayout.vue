<script>
    import {modalOpen} from '../support/focus.js';
    import {closeOverlay, inspectorVisible, shell} from './store';

    /**
     * The content area of a screen: the list pane, and, when the screen
     * gives the slot `inspector`, the inspector on the right
     * (340 px, a separator on its left). The toolbar's inspector
     * toggle shows or hides it; the choice is kept per viewer. Both panes
     * scroll on their own.
     *
     * Below 1024 px (store.js `shell.narrow`) the inspector is
     * an overlay over the pane: a selection the user makes opens it
     * (store.js revealInspector()), and so does the toolbar's toggle. Its
     * Close button and Escape close it and give the focus back to the
     * element that had it when the overlay opened. Below 768 px it covers
     * the whole pane, which is inert while it is open.
     */
    export default {
        data() {
            return {shell, registered: false};
        },

        computed: {
            shown() {
                return inspectorVisible();
            },

            overlay() {
                return this.shell.narrow;
            },
        },

        watch: {
            shown(shown) {
                if (!this.overlay) return;

                if (shown) {
                    this.opener = document.activeElement;
                    this.$nextTick(() => {
                        const close = this.$el.querySelector('.o-layout__close');

                        if (close) close.focus();
                    });
                } else {
                    this.returnFocus();
                }
            },
        },

        // `$slots` is not reactive, so a computed over it would keep its first
        // value. The template asks hasInspector() on every render, and each
        // render (mounted, updated) brings the toolbar registration in line: a
        // screen may add or drop its inspector slot at runtime, for example
        // <template v-if="selected" #inspector> on a reused route instance.
        mounted() {
            this.register(this.hasInspector());
            this.onKeydown = event => {
                if (event.key === 'Escape' && this.overlay && this.shown && !event.defaultPrevented && !this.sheetOpen()) {
                    event.preventDefault();
                    closeOverlay();
                }
            };
            document.addEventListener('keydown', this.onKeydown);
        },

        updated() {
            this.register(this.hasInspector());
        },

        beforeUnmount() {
            this.register(false);
            // The overlay belongs to this screen: the next screen starts without it.
            closeOverlay();
            document.removeEventListener('keydown', this.onKeydown);
        },

        methods: {
            hasInspector() {
                return !!this.$slots.inspector;
            },

            register(present) {
                if (present && !this.registered) {
                    this.shell.inspectorScreens++;
                    this.registered = true;
                } else if (!present && this.registered) {
                    this.shell.inspectorScreens--;
                    this.registered = false;
                }
            },

            close() {
                closeOverlay();
            },

            /** A confirmation sheet and the alert handle their own Escape first. */
            sheetOpen() {
                return modalOpen(document);
            },

            returnFocus() {
                const opener = this.opener;

                this.opener = null;

                if (opener && typeof opener.focus === 'function' && document.contains(opener)) {
                    this.$nextTick(() => opener.focus());
                }
            },
        },
    };
</script>

<template>
    <div class="o-layout" :class="{'o-layout--narrow': overlay}">
        <div class="o-layout__pane" :inert="overlay && shown && shell.phone && hasInspector()">
            <slot />
        </div>
        <aside v-if="hasInspector() && shown" class="o-layout__inspector" :class="{'o-layout__inspector--overlay': overlay}" aria-label="Inspector">
            <div v-if="overlay" class="o-layout__bar">
                <button type="button" class="o-layout__close" @click="close">Close</button>
            </div>
            <slot name="inspector" />
        </aside>
    </div>
</template>

<style scoped>
    .o-layout {
        display: flex;
        flex: 1 1 0%;
        font-family: var(--font-sans);
        min-height: 0;
        position: relative;
    }

    .o-layout__pane {
        box-sizing: border-box;
        display: flex;
        flex: 1 1 0%;
        flex-direction: column;
        gap: 28px;
        min-width: 0;
        overflow-y: auto;
        padding: 22px 24px 24px;
    }

    .o-layout--narrow .o-layout__pane {
        gap: 20px;
        padding: 16px 16px 24px;
    }

    .o-layout__inspector {
        background-color: var(--color-bg);
        border-left: 1px solid var(--color-separator);
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
        flex-shrink: 0;
        gap: 16px;
        overflow-y: auto;
        padding: 18px 20px 20px;
        width: var(--container-inspector);
    }

    /* Below 1024 px: over the pane, the full width below 768. */
    .o-layout__inspector--overlay {
        bottom: 0;
        box-shadow: 0 0 24px color-mix(in srgb, var(--color-text) 16%, transparent);
        max-width: 100%;
        position: absolute;
        right: 0;
        top: 0;
        width: 400px;
        z-index: 20;
    }

    .o-layout__bar {
        display: flex;
        justify-content: flex-end;
        margin-bottom: -8px;
    }

    .o-layout__close {
        background: none;
        border: 1px solid var(--color-separator);
        border-radius: var(--radius-control);
        color: var(--color-text);
        cursor: pointer;
        font-family: var(--font-sans);
        font-size: var(--text-table);
        line-height: 18px;
        padding: 4px 10px;
    }

    .o-layout__close:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: 1px;
    }

    @media (max-width: 767px) {
        .o-layout__inspector--overlay {
            border-left: none;
            width: 100%;
        }
    }
</style>
