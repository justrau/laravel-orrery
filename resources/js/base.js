import {live} from './shell/store.js';
import {failedRecordsReadOnly} from './support/failed-records.js';
import {writeStored} from './support/storage.js';

const LOCALSTORAGE_AUTOLOAD_KEY = 'orreryAutoLoadsNewEntries';

/**
 * The global mixin of the SPA (app.js): the page variables and the operate
 * ability, the request sequences that keep a slow response from replacing a
 * newer one, and the auto-load toggle of the Jobs list. Times are formatted
 * by support/time.js, never here.
 */
export default {
    computed: {
        Orrery() {
            return Orrery;
        },

        /**
         * Whether the user may use the write actions (pause, continue,
         * terminate, retry, tag monitoring): the `operateOrrery` ability that
         * the server puts in the page. The server still refuses a write with
         * 403; the screens only hide the actions.
         */
        canOperate() {
            return typeof Orrery !== 'undefined' && Orrery.canOperate === true;
        },

        /**
         * The read-only failed-record driver ({reason, message}) from the
         * shell's polled health, or null (support/failed-records.js). Its
         * failures show no Retry or Forget action; the
         * server refuses them with provider_read_only anyway.
         */
        failedReadOnly() {
            return failedRecordsReadOnly(live.health);
        },

        /**
         * Whether the Retry and Forget actions of failures are shown: the
         * operate ability, and a failed-record driver that is not read-only.
         */
        canOperateFailures() {
            return this.canOperate && !this.failedReadOnly;
        },
    },

    methods: {
        /**
         * Start a request of the given kind, and return its sequence number.
         * Only the response of the newest request of a kind may change the
         * screen: see isCurrentRequest().
         */
        startRequest(kind) {
            const sequences = this.orreryRequestSequences || (this.orreryRequestSequences = {});

            sequences[kind] = (sequences[kind] || 0) + 1;

            return sequences[kind];
        },

        /**
         * Whether the request with the given sequence number is the newest of its kind.
         */
        isCurrentRequest(kind, sequence) {
            return !!this.orreryRequestSequences && this.orreryRequestSequences[kind] === sequence;
        },

        /**
         * Extract the job base name.
         */
        jobBaseName(name) {
            if (!name.includes('\\')) return name;

            var parts = name.split('\\');

            return parts[parts.length - 1];
        },

        /**
         * Turn the auto-load of new entries (the Jobs list) on or off, and remember it.
         */
        autoLoadNewEntries() {
            this.autoLoadsNewEntries = !this.autoLoadsNewEntries;
            writeStored(LOCALSTORAGE_AUTOLOAD_KEY, Number(this.autoLoadsNewEntries));
        },
    },
};
