<script>
    import {createSingleFlight} from '../shell/poll.js';

    export default {
        data() {
            return {
                lastExecutionTime: 0,
                pollingInterval: null,
            }
        },


        props: {
            /**
             * The poll's work: a function that returns a promise. The next poll
             * starts only after this promise settles, however long it takes, so
             * requests never overlap (shell/poll.js). Every GET of the shared
             * HTTP client times out after 30 s, so every poll settles.
             */
            request: {
                type: Function,
                required: true,
            },

            interval: {
                type: Number,
                default: 3,
            },

            keepAlive: {
                type: Boolean,
                default: false,
            },

            immediate: {
                type: Boolean,
                default: true,
            }
        },


        beforeMount() {
            this.flight = createSingleFlight();
            this.updatePollingInterval();

            if (this.immediate) {
                this.emitPoll();
            }
        },


        mounted() {
            this.createListener();

            if (!this.keepAlive) {
                document.addEventListener('visibilitychange', this.visibilitychangeListener = this.changedVisibility);
            }
        },


        beforeUnmount() {
            this.removeListener();

            if (this.visibilitychangeListener) {
                document.removeEventListener('visibilitychange', this.visibilitychangeListener);
            }
        },


        methods: {
            emitPoll() {
                const started = this.flight.run(() => this.request());

                if (started) {
                    this.lastExecutionTime = Date.now();
                }
            },


            removeListener() {
                if (this.poll) {
                    clearInterval(this.poll);

                    this.poll = null;
                }
            },


            createListener() {
                this.poll = setInterval(() => {
                    this.emitPoll();
                }, this.pollingInterval);
            },


            updatePollingInterval() {
                if (this.keepAlive) {
                    this.pollingInterval = this.interval * 1000;
                    return;
                }

                if (document.visibilityState === 'visible') {
                    this.pollingInterval = 1000 * this.interval;
                } else if (document.visibilityState === 'hidden') {
                    // One hour...
                    this.pollingInterval = 1000 * 60 * 60;
                }
            },


            changedVisibility() {
                this.updatePollingInterval();
                this.removeListener();
                this.createListener();

                // throttling
                if ((Date.now() - this.lastExecutionTime) >= this.pollingInterval) {
                    this.emitPoll();
                }
            },
        },


        render(h) {
            return null;
        }
    }
</script>
