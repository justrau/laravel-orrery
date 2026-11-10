# Performance

- With the connector swap on, an untagged job that succeeds costs 4 statements on the package connection (1 for the push, 1 for the reservation, 2 for the completion). A tagged push adds a transaction and the insert of its tags: 7 in all. A worker also reads the signals at most once per second and writes a heartbeat at most once per 15 seconds.
- These are the costs on a warm connection. The package opens its own connection to its database, separate from the application's: each PHP process that dispatches or works jobs (under PHP-FPM, each request that dispatches) opens one more connection to that server. Size the server's connection limit for two connections per such process.
- The tick phases do bounded work per run within the 30-second deadline. [the tick benchmark](performance/tick-benchmark.md) has the measured tick phases, the retention throughput, the effect of the optional `jobs` index and the build times of the deferred indexes.
