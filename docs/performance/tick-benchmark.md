# Tick benchmark

`bin/benchmark-tick` seeds the application's `jobs` table and the mirror, and runs every `orrery:tick` phase. This file records the method and the current results: one `bin/benchmark-tick sqlite` run of 2026-11-10 at commit `e23522b`, with the full phase set (the phases `stuck_incidents` and `stuck_notifications` included). This run replaces the run of 2026-11-09, which exited 1 (see "History").

## Method

- `bin/benchmark-tick <engine>` runs `tests/Benchmark/TickBenchmark.php` through `bin/test`, so every run has the harness database, its lease and its endpoint proof. The file is in no test suite, and its tests skip without `ORRERY_BENCHMARK=1`.
- The seed of row i is deterministic, with payloads of 700 bytes:
  - `jobs`: 10 queues; 80% ready, 10% delayed, 5% reserved, 5% expired reservations.
  - `orrery_jobs`: 40% completed (half of them past the 60-minute retention, a tenth of them silenced), 20% pending (a quarter of them with a job id that `jobs` does not have), 5% reserved, 20% failed with a bound failed-job record (half past the 7-day retention), 5% unknown, 10% completed and tagged with a stale `monitor_version`.
  - Fixed rows: the per-minute samples of 25 hours (the counts frontier one minute behind, so `sample_counts` recounts the last 5 minutes; at 1,000,000 rows one of these minutes has about 200,000 completed rows), 1,000 closed and 10 open health windows, 50 heartbeats (half stale), and 100 unresolved bulk groups with their attempt keys.
  - The seed has no rows made for the stuck phases (no open incidents, cooldown rows or bulk runs past their retention). `stuck_incidents` and `stuck_notifications` read the general seed with the deferred indexes present.
- At each size, three ticks run, one phase at a time, each through `TickRunner` with new package sessions and **its own deadline**:
  1. the first tick, which also does the one-time work of the new rows;
  2. a tick without the optional `jobs` index;
  3. a tick with the optional `jobs` index (`queue`, `reserved_at`, `available_at`).
- So every total in this file is a **sum of isolated phase runs**, not one whole tick with the shared 30 s deadline. In isolation, `long_wait` and `samples` each take their own queue measurement; one whole tick takes it once and shares it (`TickMeasurement`).
- Per phase the report has the TickReport status (ran, failed, skipped, overrun), the latency and the statements. SQLite has no rows-read counter. A phase that does not complete fails the run with exit 1 after the report is written.
- SQLite runs 500,000 and 1,000,000 rows on a file, in WAL, with the package tables in the sibling file. The server engines (`bin/benchmark-tick mysql`, `pgsql`) size their runs to the container tmpfs: `bin/benchmark-tick --help` describes the rule. The latencies are single measurements on a shared machine: compare their size, not their last digit.

## Results (2026-11-10)

`bin/benchmark-tick sqlite` at `e23522b`, PHP 8.4.18, Laravel 13.40.0, PHPUnit 12.5.43, payload 700 bytes, WAL. The machine was loaded by other agents: a load average of 15.7 to 27.9 (one minute) and 14.4 to 22.2 (five minutes) while the run lasted. The run took 9 min 3 s and exited 0. The seed took 135.9 s (500,000 rows) and 195.5 s (1,000,000 rows); the optional index took 3.89 s and 2.32 s to build.

| Rows | Sum of the first-tick phase runs | Steady, without the index | Steady, with the index | The same three without `retention` (its 10 s share) |
| ---: | ---: | ---: | ---: | ---: |
| 500,000 | 21.8 s | 24.3 s | 15.9 s | 11.7 s, 14.1 s, 5.7 s |
| 1,000,000 | 37.9 s | 24.1 s | 14.7 s | 27.5 s, 14.0 s, 4.4 s |

Every phase of every tick reported complete: no phase failed, was skipped or overran its 30 s deadline.

### 500,000 rows

| Phase (priority) | First tick ms | Tick ms, without the index | Tick ms, with the index | Statements (first / tick) | Status |
| --- | ---: | ---: | ---: | ---: | --- |
| `monitoring_repair` (90) | 699 | 961 | 1,435 | 82 / 82 | complete |
| `sample_counts` (97) | 520 | 541 | 2,083 | 60 / 50 | complete |
| `retention` (100) | 10,070 | 10,240 | 10,130 | 721 / 631 | complete |
| `heartbeat_sweep` (200) | 12 | 5.9 | 4.3 | 2 / 2 | complete |
| `bulk_resolution` (250) | 1,462 | 2,024 | 1,025 | 1,605 / 504 | complete |
| `reconcile_active` (260) | 470 | 1,238 | 389 | 205 / 205 | complete |
| `signal_expiry` (300) | 16 | 2.6 | 4.5 | 4 / 4 | complete |
| `long_wait` (400) | 3,352 | 4,634 | 74 | 7 / 7 | complete |
| `samples` (405) | 4,736 | 3,392 | 207 | 11 / 11 | complete |
| `stuck_incidents` (410) | 61 | 448 | 131 | 185 / 180 | complete |
| `restart_markers` (420) | 7.9 | 0.8 | 0.6 | 1 / 1 | complete |
| `reconcile_failed` (460) | 197 | 458 | 353 | 9 / 9 | complete |
| `operations` (480) | 5.4 | 4.0 | 1.1 | 5 / 5 | complete |
| `operation_retention` (520) | 2.0 | 4.8 | 1.9 | 8 / 8 | complete |
| `sample_retention` (870) | 140 | 4.6 | 1.2 | 571 / 13 | complete |
| `marker_retention` (875) | 0.8 | 1.9 | 2.2 | 2 / 2 | complete |
| `health_retention` (900) | 7.4 | 1.8 | 0.4 | 2 / 2 | complete |
| `stuck_notifications` (950) | 55 | 378 | 26 | 255 / 255 | complete |

### 1,000,000 rows

| Phase (priority) | First tick ms | Tick ms, without the index | Tick ms, with the index | Statements (first / tick) | Status |
| --- | ---: | ---: | ---: | ---: | --- |
| `monitoring_repair` (90) | 2,474 | 768 | 308 | 82 / 82 | complete |
| `sample_counts` (97) | 3,066 | 4.3 | 5.3 | 54 / 49 | complete |
| `retention` (100) | 10,493 | 10,038 | 10,363 | 100 / 410 | complete |
| `heartbeat_sweep` (200) | 15 | 2.2 | 6.2 | 2 / 2 | complete |
| `bulk_resolution` (250) | 2,232 | 657 | 997 | 261 / 186 | complete |
| `reconcile_active` (260) | 5,699 | 1,321 | 1,668 | 205 / 205 | complete |
| `signal_expiry` (300) | 14 | 3.0 | 13 | 4 / 4 | complete |
| `long_wait` (400) | 7,064 | 4,626 | 350 | 6 / 6 | complete |
| `samples` (405) | 5,176 | 5,377 | 130 | 10 / 10 | complete |
| `stuck_incidents` (410) | 75 | 124 | 40 | 189 / 186 | complete |
| `restart_markers` (420) | 0.4 | 2.9 | 0.6 | 1 / 1 | complete |
| `reconcile_failed` (460) | 1,586 | 1,065 | 789 | 9 / 9 | complete |
| `operations` (480) | 1.7 | 1.4 | 2.9 | 5 / 5 | complete |
| `operation_retention` (520) | 1.1 | 4.3 | 2.1 | 8 / 8 | complete |
| `sample_retention` (870) | 9.7 | 3.0 | 6.1 | 49 / 4 | complete |
| `marker_retention` (875) | 0.8 | 1.0 | 5.3 | 2 / 2 | complete |
| `health_retention` (900) | 0.7 | 0.8 | 0.9 | 2 / 2 | complete |
| `stuck_notifications` (950) | 34 | 56 | 54 | 255 / 255 | complete |

Retention (each run used its whole 10 s share and stopped with expired rows left, `behind: true`):

| Rows | First tick | Tick, without the index | Tick, with the index |
| ---: | ---: | ---: | ---: |
| 500,000 | 24,000 rows, 2,383 rows/s | 20,000 rows, 1,953 rows/s | 10,000 rows, 987 rows/s |
| 1,000,000 | 1,000 rows, 95 rows/s | 11,500 rows, 1,146 rows/s | 20,500 rows, 1,978 rows/s |

## Findings

1. **No overrun at this load.** The run of 2026-11-09 (load 14 to 16) had `long_wait` at 82 s and 74 s and `samples` at 78 s and 62 s at 1,000,000 rows without the optional index. At a load of 15.7 to 27.9, this run measured `long_wait` at 4.6 to 7.1 s and `samples` at 5.2 to 5.4 s there, close to the 2.3 to 2.7 s of 2026-11-04 at a lower load. So the overruns of 2026-11-09 did not come back. The mechanism that permits them is still in the code:
   - At `e23522b`, one queue measurement (`WaitTime::measure()`) read the whole `jobs` table three times per target without the index: `SELECT DISTINCT queue`, then the ready and the expired `GROUP BY queue` reads. Since `3e1d7e0` it is one grouped read per target (`backlogs()`), without a WHERE clause: it visits every row once without the index, and every entry of the index with it. This run does not measure that read.
   - The tick deadline is cooperative. `TickRunner` checks it between phases, and a phase cannot stop inside one statement.
   - On SQLite no statement timeout bounds these reads: `orrery.sessions.statement_timeout_ms` applies to MySQL, MariaDB and Postgres sessions only.
   - So on SQLite a stalled scan (I/O contention, a machine that sleeps) runs to its end and overruns. On a server engine the 10 s statement timeout fails the read, and the rows become `backlog_unknown`.
   - With the index, `long_wait` took 74 to 350 ms and `samples` 130 to 207 ms at `e23522b`, where the ready and expired reads were range reads of the index. The grouped read visits every index entry, so these numbers may not hold for it until a new run measures it. Publish the index for large backlogs: `php artisan vendor:publish --tag=orrery-jobs-index`.
2. **The first tick at 1,000,000 rows sums to 37.9 s.** That is above the 30 s tick deadline. Its largest parts are `retention` (10.5 s, its share), `long_wait` (7.1 s), `reconcile_active` (5.7 s), `samples` (5.2 s), `sample_counts` (3.1 s, the recount of the minute with about 200,000 completed rows) and `monitoring_repair` (2.5 s, the repair of the new stale rows). One whole tick takes the queue measurement once, so it would cost about 33 s. Its later phases would be skipped with `tick_overrun:<phase>`, and the next tick runs them. This is the one-time work of a newly seeded table on a loaded machine. The steady sums stay below 30 s: 24.1 s without the index and 14.7 s with it.
3. **The stuck phases are cheap on this seed.** `stuck_incidents` took 40 to 448 ms and `stuck_notifications` 26 to 378 ms, far below the 10 s share of `stuck_notifications`. The seed has no open incidents and no due notifications, so these numbers measure the candidate reads, not the sending of a notification burst.
4. **`retention`** used its whole 10 s share in every run and stayed behind, at 95 to 2,383 rows per second. The 95 rows/s run is the first tick at 1,000,000 rows: there the partition discovery used most of the share. See "Retention throughput" for the design bound.
5. **`reconcile_active`** took 0.4 to 5.7 s for its 1,000 rows per connection (205 statements), and `bulk_resolution` 0.7 to 2.2 s. In 2026-09 these took 0.2 to 0.4 s and 0.4 to 0.7 s on a less loaded machine. Their statement counts did not change, so the load is the likely cause.

## Retention throughput

The retention has no row cap per run. A run deletes in batches of 500 until every partition is done, its 10 s share ends, or the tick budget ends. On a SQLite file of its own it waits 150 ms before a batch that follows a write transaction, at most once per 150 ms of work; MySQL and Postgres do not pause. Each run writes the settings row `retention_state` (`at`, `deleted`, `behind`, `lag_seconds`, `partitions_unchecked`), and `GET /api/health` returns it.

- Measured (2026-11-10, above, a loaded machine): 95 to 2,383 rows per second of the share. One run per minute with a 10 s share then deletes about 1,000 to 24,000 rows per minute.
- The bound of the design, an estimate from the batch time on an idle machine: a 500-row batch took about 0.1 s (2026-09-26). With the 150 ms pause one batch takes about 0.25 s. So SQLite retention can delete about 2,000 rows per second of its share, about 20,000 rows per tick, enough for about 330 finished jobs per second. Without the pause (MySQL, Postgres) the bound is about 5,000 rows per second, about 50,000 rows per tick. Measure on the target host, and read `retention_state` (`behind`, `lag_seconds`) in production.

## The deferred indexes

Two `orrery_jobs` indexes serve the stuck signals: `st_attempts` (`status`, `attempts`, `id`) and `st_gap` (`status`, `observed_gap`, `id`). A fresh install creates them; an existing one builds them with `php artisan orrery:install --indexes`. `tests/Benchmark/IndexCostBenchmark.php` (no test suite; `ORRERY_BENCHMARK=1`, run by path through `bin/test`) measures both costs. PHP 8.4.18, Laravel 13.40.0, a loaded machine (load average 10 to 18 from other agents).

**The write cost.** 10,000 untagged push, reserve and complete cycles with the two indexes and 10,000 without them, through the real queue and worker, in blocks of 1,000 in the order A B B A. Only the package's own statements are timed, through the session recorder (from its `statement` entry to its `executed` entry). Medians in milliseconds, with / without:

| Statement | SQLite file (run 1) | SQLite file (run 2) | MySQL 8.4 | Postgres 18 |
| --- | ---: | ---: | ---: | ---: |
| push insert | 0.167 / 0.147 (+13%) | 0.165 / 0.158 (+5%) | 1.328 / 1.346 (−1%) | 2.271 / 2.256 (+1%) |
| reserve update | 0.144 / 0.123 (+17%) | 0.142 / 0.131 (+9%) | 1.488 / 1.486 (0%) | 2.298 / 2.281 (+1%) |
| complete read | 0.032 / 0.031 (+5%) | 0.034 / 0.034 (0%) | 1.140 / 1.144 (0%) | 1.936 / 1.975 (−2%) |
| complete update | 0.130 / 0.109 (+19%) | 0.130 / 0.118 (+10%) | 1.458 / 1.422 (+3%) | 2.221 / 2.191 (+1%) |

- The statement count does not change (`HotPathBudgetTest` is unchanged); the indexes add index maintenance to the push insert, and to the reserve and complete updates, which change `status` (and `attempts` or `observed_gap`).
- On SQLite the two runs differ by about a factor of two in the extra cost (+0.01 to +0.02 ms per write); run 1 passed the 15% limit on the reserve and complete updates. On MySQL and Postgres the difference is within the noise of the network round trip.

**The build.** `orrery:install --indexes` after the rows were seeded without the two indexes (rows of 10% failed and 90% completed status, a minimal payload):

| Engine | Measured | Build time | 1,000,000 rows |
| --- | --- | ---: | ---: |
| SQLite (file, sibling) | 500,000 / 1,000,000 rows | 1.96 s / 4.11 s | 4.1 s (measured) |
| MySQL 8.4 (`ALGORITHM=INPLACE, LOCK=NONE`) | 20,000 / 40,000 rows | 0.24 s / 0.50 s | about 13 s (linear extrapolation) |
| Postgres 18 (`CONCURRENTLY`) | 50,000 / 100,000 rows | 0.33 s / 0.42 s | about 2 s (linear extrapolation) |

- The server sizes stay within 60% of the container tmpfs less the used bytes and 200 MB (the rule of `bin/benchmark-tick`); the benchmark checks each size before it seeds it. Postgres measured 1,557 bytes per row without the two indexes.
- The extrapolations are linear through the two sizes and follow noise; a production table with real payloads is larger on disk, and the build reads only the index columns.

### `st_releases`

A third deferred index, `st_releases` (`status`, `releases`, `id`), for the stuck list "repeated releases": a cycle's releases can exceed its attempts (two lineages after a reclaim past `retry_after`), so that list cannot read `st_attempts`. The write cost of this index alone, with `ORRERY_BENCHMARK_INDEXES=st_releases ORRERY_BENCHMARK_JOB=release` (each cycle: a push, attempt 1 released, attempt 2 completed; the other two deferred indexes stay in both variants). Medians in milliseconds, with / without:

| Statement | SQLite file (10,000 cycles per variant, load 8) | Postgres 18 (4,000 cycles per variant, load 8 to 10) |
| --- | ---: | ---: |
| push insert | 0.163 / 0.145 (+13%) | 1.237 / 1.140 (+9%) |
| reserve update | 0.138 / 0.120 (+15%) | 1.256 / 1.167 (+8%) |
| release update | 0.134 / 0.116 (+15%) | 1.283 / 1.176 (+9%) |
| complete read | 0.033 / 0.032 (+3%) | 1.092 / 1.009 (+8%) |
| complete update | 0.133 / 0.116 (+15%) | 1.227 / 1.136 (+8%) |

- SQLite: +0.017 ms on each write that changes `status` or `releases`, about the cost of each of the first two indexes; the read does not change.
- Postgres: the read, which maintains no index, moved by the same 8% as the writes, so the difference is the drift of the run, not the index. MySQL was not measured.
- The statement count does not change (`HotPathBudgetTest` is unchanged). The build times above were measured with two indexes; the third adds about one more index build of the same size.


## History

The earlier runs measured other phase sets and seeds, so they do not compare directly with the results above. Their full tables are in the git history of this file.

- **2026-11-09** (PHP 8.4.18, Laravel 13.40.0, SQLite, load 14 to 16): exited 1. At 1,000,000 rows without the optional index, `long_wait` (82 s, 74 s) and `samples` (78 s, 62 s) overran their 30 s deadline. `sample_counts` took 392 ms to 1,956 ms after the arrivals read was added. The run of 2026-11-10 replaces it.
- **2026-11-04** (PHP 8.4.18, Laravel 13.40.0, SQLite in WAL): before the stuck phases, and with the old retry phases next to `operations`. At 1,000,000 rows: 18.1 s first tick, 17.0 s steady without the index, 15.3 s with it (8.0 s, 6.9 s and 5.2 s without `retention`).
- **2026-11-04** (SQLite in WAL, before orrery_samples): 14.8 s, 14.5 s and 10.7 s at 1,000,000 rows.
- **2026-11-03** (SQLite, load 24 to 55): retention throughput only, 341 to 600 rows per second.
- **2026-09-26** (PHP 8.3.30, Laravel 13.33.0), **before the later rebuild**: SQLite at 1,000,000 rows (3.7 s first tick, 3.5 s and 1.8 s steady), Postgres 18 at 95,000 rows and MySQL 8.4 at 25,000 rows, extrapolated to 1,000,000 rows. These are the only server runs. They measured a phase set that no longer exists (with a retention cap of 5,000 rows per tick), so they do not describe the current code.
