<?php

return [
    'name' => env('ORRERY_NAME', 'Orrery'),

    'domain' => env('ORRERY_DOMAIN'),
    'path' => env('ORRERY_PATH', 'orrery'),
    'proxy_path' => env('ORRERY_PROXY_PATH', ''),

    'middleware' => ['web'],

    // The connection of the package tables. Empty: the default connection,
    // and on a SQLite file a sibling file next to it (<name>.orrery.sqlite),
    // which the package creates and installs itself. A connection on the
    // SQLite file of a queue, the failed jobs or a cache store is refused.
    'database' => env('ORRERY_CONNECTION'),

    // The package's own database sessions: one per database connection.
    'sessions' => [
        // Postgres connections behind a transaction pooler (PgBouncer,
        // Supavisor and similar, transaction mode): true for all, or a list
        // of connection names. A pooled session sends no session SET, and
        // supports only the server's default search_path. MySQL and MariaDB
        // ignore it.
        'pooled' => env('ORRERY_SESSIONS_POOLED', false),

        // Every package statement stops after this many milliseconds (MySQL
        // max_execution_time, MariaDB max_statement_time, Postgres
        // statement_timeout; on a pooled Postgres connection only inside
        // package transactions and bounded reads; SQLite has none). 0 turns
        // the timeout off.
        'statement_timeout_ms' => 10000,
    ],

    // Automatic package reads of the application's SQLite files (a queue,
    // the failed jobs, the batches or a cache store). They happen only when
    // every connection name of the file sets journal_mode WAL, a positive
    // busy_timeout and no EXCLUSIVE locking mode; else those values are
    // unknown. Set false when the application switches a file to EXCLUSIVE
    // locking at runtime. The package never writes to such a file
    // automatically (only an operator's retry, forget, clear or reconcile).
    'sqlite_automatic_access' => env('ORRERY_SQLITE_AUTOMATIC_ACCESS', true),

    // The watcher: a dependency error of a package session
    // opens the mirror breaker for this many seconds.
    'observer' => [
        'breaker_seconds' => 30,
    ],

    // orrery:tick: the cooperative deadline of one run.
    'tick' => [
        'deadline_seconds' => 30,
    ],

    // Worker heartbeats: the least time between two heartbeat
    // writes of one worker process. Each write also flushes the process's
    // degradation record.
    'workers' => [
        'heartbeat_seconds' => (int) env('ORRERY_HEARTBEAT_SECONDS', 15),
    ],

    // The control plane: when the package storage cannot be
    // read, a worker keeps the last signals that it read for this many
    // seconds. After that it ignores the signals (fails open), logs it and
    // records it in the health events. A worker reads the signals at most
    // once per signal_cache_seconds (ORRERY_SIGNAL_CACHE_SECONDS).
    'control' => [
        'stale_signals_seconds' => 900,
        'signal_cache_seconds' => (float) env('ORRERY_SIGNAL_CACHE_SECONDS', 1),
    ],

    // The retry protocol: the days that orrery:tick keeps a
    // fence row (orrery_fences) after its operation ended and its deadline
    // passed, by that database's clock, and the seconds that a retry may
    // take from its claim to its publication (its deadline).
    'retry' => [
        'fence_retention_days' => 30,
        'publish_timeout' => 60,
    ],

    // The operation ledger of retry and forget: the days that
    // orrery:tick keeps a finished (done or aborted) operation.
    'operations' => [
        'retention_days' => 30,
    ],

    'connections' => [],

    // The package schedules orrery:tick (every minute), onOneServer() and
    // withoutOverlapping(). Set it to false to schedule it yourself, for
    // example on one dedicated server (README, "Schedule").
    'schedule' => env('ORRERY_SCHEDULE', true),

    // The connector swap: the `database` queue connector
    // returns the package's DatabaseQueue, whose hooks mirror every push,
    // reservation and release. It is a kill switch: with false, or when
    // another package's connector wins the `database` binding, the package
    // mirrors only the outcomes (completed and failed jobs), writes no
    // pending or reserved row, and GET /api/health lists the connection in
    // `uninstrumented_connections`. The backlog numbers still come from the
    // jobs table.
    'swap_connector' => true,

    // Long-wait alerts, in seconds, keyed `connection:queue`.
    // `*` is the threshold of every other checked queue. A queue alerts when
    // its oldest executable job or its estimated time to clear reaches the
    // threshold. 0 turns the alert off.
    'waits' => [
        '*' => 60,
    ],

    // Retention, in minutes. orrery:tick deletes a finished
    // mirror row when its finished_at is older than the retention of its
    // state: `completed`, `silenced` (a silenced completed job), `failed` or
    // `unknown`. A row with a monitored tag keeps the larger of its state
    // retention and `monitored`. Pending, reserved and retrying rows, and a
    // row that a retry or forget operation holds, are never deleted. Each value is a whole number of minutes, 0 or
    // more; orrery:tick and orrery:install report an invalid value, and tick
    // then deletes nothing.
    'trim' => [
        'completed' => 60,
        'silenced' => 60,
        'failed' => 10080,
        'unknown' => 10080,
        'monitored' => 10080,
    ],

    // The dashboard reads.
    'dashboard' => [
        // GET /api/counts and the failed-member total of a batch read at
        // most this many index entries per count; a larger count is
        // reported as "at least" this value (`at_least: true`).
        'count_cap' => 1_000_000,

        // The failure groups count at most this many failures per group
        // (the newest of the group in the range); a larger count is shown
        // as "at least".
        'group_count_cap' => 5000,

        // The /api/workload requests share one queue measurement through the
        // default cache store for this many seconds. 0 turns the cache off.
        'measure_cache_seconds' => 5,
    ],

    'silenced' => [],
    'silenced_tags' => [],

    // Payload classification: a job keeps at most this many
    // normalized tags. The payload key `tagsTruncated` records whether it
    // had more.
    'tags' => [
        'max_per_job' => 50,
    ],

    // The per-minute samples. The tick phase `sample_counts`
    // aggregates the completed and failed counts and the runtime of each
    // minute from the mirror rows into orrery_samples.
    'samples' => [
        // The time share of one aggregation run, in seconds.
        'count_seconds' => 5,
        // Retention keeps the rows that the counts have not passed while the
        // aggregation moved at most this many minutes ago (or within the
        // row's own retention, when that is longer). After that it trims as
        // usual, and the minutes of the trimmed rows stay unknown. With 0
        // and `trim.completed` 0, retention trims a row whose minute was
        // not counted yet at once, so most recent minutes stay unknown.
        'count_wait_minutes' => 120,
        // The samples and the invalid minutes are kept this many hours.
        'retention_hours' => 24,
    ],

    // The health events: the tick deletes a closed health
    // window that ended more than this many days ago.
    'health' => [
        'retention_days' => 7,
    ],
];
