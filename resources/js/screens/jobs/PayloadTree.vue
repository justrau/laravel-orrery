<script>
    import {treeNode, treeSummary, visibleChildren} from './detail.js';

    /** The children of a container that one "Show more" adds. */
    const PAGE = 200;

    /**
     * The tree of one value of the data-only projection:
     * the command, the payload envelope or the context of a job, as the
     * server built it. The browser never unserializes anything: the server
     * already parsed the serialized PHP into plain data, and this component
     * only draws it (detail.js treeNode() classifies each value).
     *
     * - Values are text, never markup. A value that is not shown is a dashed
     *   box with its reason and size (omitted, unavailable, closure,
     *   encrypted, reference, binary, custom serialization); "[redacted]" is
     *   a grey chip, "[redaction failed]" a red one.
     * - The root is open; every other container starts closed. The open
     *   state lives in the root (`state`, keyed by the path), so a poll that
     *   brings the same data keeps what the user opened.
     * - A long container draws 200 children at a time ("Show more").
     * - Each toggle is a button with aria-expanded, so the tree works by
     *   keyboard.
     */
    export default {
        name: 'PayloadTree',

        props: {
            value: {default: null},
            label: {type: String, default: null},
            visibility: {type: String, default: null},
            declaredIn: {type: String, default: null},
            depth: {type: Number, default: 0},
            path: {type: String, default: '$'},
            state: {type: Object, default: null},
        },

        data() {
            return {own: this.state ? null : {open: {}, shown: {}}};
        },

        computed: {
            tree() {
                return this.state || this.own;
            },

            node() {
                return treeNode(this.value);
            },

            container() {
                return this.node.kind === 'container';
            },

            open() {
                const choice = this.tree.open[this.path];

                return choice === undefined ? this.depth === 0 : choice === true;
            },

            summary() {
                return treeSummary(this.node);
            },

            page() {
                return visibleChildren(this.container ? this.node.children.length : 0, this.tree.shown[this.path] || PAGE);
            },

            children() {
                return this.container ? this.node.children.slice(0, this.page.count) : [];
            },

            indent() {
                // The design: 14 px at the root, 16 px per level; a leaf adds the 16 px of the chevron slot.
                return {paddingLeft: `${14 + this.depth * 16 + (this.container ? 0 : 16)}px`};
            },

            visibilityLabel() {
                return this.visibility && this.visibility !== 'public' ? this.visibility : null;
            },

            /** A private property names its declaring class on hover: two classes can declare the same name. */
            keyTitle() {
                return this.visibility === 'private' && this.declaredIn ? `${this.label} · private in ${this.declaredIn}` : this.label;
            },
        },

        methods: {
            toggle() {
                this.tree.open[this.path] = !this.open;
            },

            more() {
                this.tree.shown[this.path] = (this.tree.shown[this.path] || PAGE) + PAGE;
            },

            childPath(index) {
                return `${this.path}/${index}`;
            },
        },
    };
</script>

<template>
    <div class="o-tree" :class="{'o-tree--root': depth === 0}">
        <div class="o-tree__row" :style="indent">
            <button v-if="container" type="button" class="o-tree__toggle" :aria-expanded="open ? 'true' : 'false'" @click="toggle">
                <svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true" focusable="false" class="o-tree__chevron" :class="{'o-tree__chevron--open': open}">
                    <path d="M3.5 2l3 3-3 3" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span v-if="label !== null" class="o-tree__key" :title="keyTitle">{{ label }}<span v-if="visibilityLabel" class="o-tree__visibility">{{ visibilityLabel }}</span></span>
                <span v-if="node.className" class="o-tree__class">{{ node.className }}</span>
                <span class="o-tree__summary">{{ summary }}<template v-if="node.serialized"> · serialized</template></span>
            </button>

            <template v-else>
                <span v-if="label !== null" class="o-tree__key" :title="keyTitle">{{ label }}<span v-if="visibilityLabel" class="o-tree__visibility">{{ visibilityLabel }}</span></span>
                <span v-if="node.kind === 'marker'" class="o-tree__marker">{{ node.text }}</span>
                <span v-else-if="node.kind === 'redacted'" class="o-tree__chip">{{ node.text }}</span>
                <span v-else-if="node.kind === 'redaction_failed'" class="o-tree__chip o-tree__chip--failed">{{ node.text }}</span>
                <template v-else-if="node.kind === 'enum'">
                    <span class="o-tree__value">{{ node.text }}</span>
                    <span class="o-tree__hint">enum</span>
                </template>
                <span v-else-if="node.kind === 'scalar'" class="o-tree__value" :class="`o-tree__value--${node.type}`">{{ node.text }}</span>
                <span v-else class="o-tree__value o-tree__value--string">{{ node.text }}</span>
                <span v-if="node.serialized" class="o-tree__hint">serialized</span>
            </template>
        </div>

        <template v-if="container && open">
            <payload-tree v-for="(child, index) in children" :key="childPath(index)"
                          :value="child.value" :label="child.key" :visibility="child.visibility" :declared-in="child.declaredIn"
                          :depth="depth + 1" :path="childPath(index)" :state="tree" />
            <div v-if="page.more > 0" class="o-tree__row" :style="{paddingLeft: `${14 + (depth + 1) * 16 + 16}px`}">
                <button type="button" class="o-tree__more" @click="more">Show {{ Math.min(page.more, 200).toLocaleString() }} more of {{ page.more.toLocaleString() }}</button>
            </div>
            <div v-if="!node.children.length" class="o-tree__row o-tree__empty" :style="{paddingLeft: `${14 + (depth + 1) * 16 + 16}px`}">empty</div>
        </template>
    </div>
</template>

<style scoped>
    .o-tree {
        font-family: var(--font-mono), ui-monospace, monospace;
        font-size: var(--text-caption);
        line-height: 16px;
    }

    .o-tree__row {
        align-items: flex-start;
        box-sizing: border-box;
        display: flex;
        gap: 6px;
        min-height: 26px;
        padding-bottom: 5px;
        padding-right: 14px;
        padding-top: 5px;
    }

    .o-tree__toggle {
        align-items: flex-start;
        background: none;
        border: none;
        border-radius: 4px;
        color: inherit;
        cursor: pointer;
        display: flex;
        font: inherit;
        gap: 6px;
        margin: 0;
        min-width: 0;
        padding: 0;
        text-align: left;
    }

    .o-tree__toggle:focus-visible,
    .o-tree__more:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--color-accent) 55%, transparent);
        outline-offset: 1px;
    }

    .o-tree__chevron {
        color: var(--color-text-secondary);
        flex-shrink: 0;
        margin-top: 3px;
        transition: transform 0.12s ease;
    }

    .o-tree__chevron--open {
        transform: rotate(90deg);
    }

    .o-tree__key {
        color: var(--color-text-secondary);
        flex-shrink: 0;
        max-width: 40%;
        overflow-wrap: anywhere;
        width: 180px;
    }

    .o-tree--root > .o-tree__row .o-tree__key {
        width: auto;
    }

    .o-tree__visibility {
        color: var(--color-text-tertiary);
        margin-left: 6px;
    }

    .o-tree__class {
        color: var(--color-text);
        font-weight: var(--font-weight-semibold);
        overflow-wrap: anywhere;
    }

    .o-tree__summary {
        color: var(--color-text-tertiary);
        overflow-wrap: anywhere;
    }

    .o-tree__value {
        color: var(--color-text);
        min-width: 0;
        overflow-wrap: anywhere;
        white-space: pre-wrap;
    }

    .o-tree__value--null,
    .o-tree__value--boolean {
        color: var(--color-text-secondary);
    }

    .o-tree__hint {
        color: var(--color-text-tertiary);
        font-family: var(--font-sans);
    }

    .o-tree__marker {
        border: 1px dashed color-mix(in srgb, var(--color-text) 25%, transparent);
        border-radius: 4px;
        color: var(--color-text-secondary);
        font-family: var(--font-sans);
        overflow-wrap: anywhere;
        padding-inline: 6px;
    }

    .o-tree__chip {
        background-color: var(--color-sidebar);
        border-radius: 4px;
        color: var(--color-text-secondary);
        padding-inline: 6px;
    }

    .o-tree__chip--failed {
        background-color: var(--color-danger-tint);
        color: var(--color-danger-text);
    }

    .o-tree__more {
        background: none;
        border: none;
        color: var(--color-accent);
        cursor: pointer;
        font-family: var(--font-sans);
        font-size: var(--text-caption);
        padding: 0;
    }

    .o-tree__empty {
        color: var(--color-text-tertiary);
        font-family: var(--font-sans);
    }
</style>
