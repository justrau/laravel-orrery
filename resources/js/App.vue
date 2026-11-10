<script>
    import AlertHost from './components/ui/AlertHost.vue';
    import AppShell from './shell/AppShell.vue';
    import {dismissedAlert, emptyAlert} from './support/alert.js';
    import {readStored} from './support/storage.js';

    const LOCALSTORAGE_AUTOLOAD_KEY = 'orreryAutoLoadsNewEntries';

    /**
     * The root of the SPA. It holds what the in-DOM template of
     * layout.blade.php held before: the global alert (errors.js and the
     * screens write `alert`; AlertHost shows it and asks the root to
     * clear it) and the shell. A compiled
     * component instead of an in-DOM template, so the page runs on Vue's
     * runtime-only build, which needs no `new Function()` and so no
     * 'unsafe-eval' in a Content Security Policy.
     */
    export default {
        components: {AlertHost, AppShell},

        data() {
            return {
                alert: emptyAlert(),
                lastDismissedAlert: null,
                autoLoadsNewEntries: readStored(LOCALSTORAGE_AUTOLOAD_KEY) === '1',
                downForMaintenance: window.Orrery.downForMaintenance === true,
            };
        },

        methods: {
            /**
             * The alert was closed: a failed poll repeats its message every
             * few seconds, so errors.js does not show a dismissed message
             * again for a while. The object stays the same: errors.js writes
             * its fields.
             */
            dismissAlert(message) {
                this.lastDismissedAlert = dismissedAlert(message, Date.now());
                Object.assign(this.alert, emptyAlert());
            },
        },
    };
</script>

<template>
    <alert-host :alert="alert" @dismiss="dismissAlert"></alert-host>

    <app-shell :down-for-maintenance="downForMaintenance"></app-shell>
</template>
