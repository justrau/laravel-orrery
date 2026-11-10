<script>
    /**
     * A screen's controls in the shell's toolbar, between the title and
     * "Live". Use it anywhere in a screen: <toolbar-controls>…</toolbar-controls>.
     * The content is teleported after the shell has mounted (defer), so the
     * target always exists.
     */
    export default {};
</script>

<template>
    <teleport defer to="#orrery-toolbar-controls">
        <slot />
    </teleport>
</template>
