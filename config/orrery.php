<?php

return [
    // The off switch (ORRERY_ENABLED). False: the package observes
    // nothing. Laravel's own database queue, failed-job provider and events
    // run as without the package: no listener, no heartbeat, no scheduled
    // tick and no package database connection. The dashboard answers 503,
    // and the commands that act refuse. Migrations still load. A cached
    // configuration needs `php artisan config:cache`, and running workers
    // keep their loaded configuration until `php artisan queue:restart`.
    'enabled' => env('ORRERY_ENABLED', true),

    'name' => env('ORRERY_NAME', 'Orrery'),

    'domain' => env('ORRERY_DOMAIN'),
    'path' => env('ORRERY_PATH', 'orrery'),
    'proxy_path' => env('ORRERY_PROXY_PATH', ''),

    'middleware' => ['web'],

    // The auth guard of the dashboard: the viewOrrery and
    // operateOrrery gates get its user, and a control write stores it as
    // the actor. When the view check fails, the dashboard answers 401 (sign
    // in again) while this guard has no user, else 403. Null or empty: the
    // default guard. A name that is not a key of auth.guards denies both
    // abilities, gives 403 and a warning in /api/health (guard_invalid).
    'guard' => env('ORRERY_GUARD'),

    // The time zone in which the dashboard shows times: an
    // IANA zone name such as Europe/Vilnius. Null, or a value that is not a
    // zone name: the browser's own zone. Absolute times show the zone.
    'display_timezone' => env('ORRERY_DISPLAY_TIMEZONE'),

    // The connection of the package tables. Empty: the default connection,
    // and on a SQLite file a sibling file next to it (<name>.orrery.sqlite),
    // which the package creates and installs itself. A connection on the
    // SQLite file of a queue, the failed jobs or a cache store is refused.
    // Empty, the package tables follow the default connection at every use:
    // an application that changes its default connection or database at
    // runtime (a multi-tenant package) must set a fixed connection here.
    'database' => env('ORRERY_CONNECTION'),

    // Whether the package loads its migrations from its own directory, so
    // a plain `php artisan migrate` runs them (ORRERY_LOAD_MIGRATIONS: a
    // boolean word such as 0 or false). A rollback of the package migration
    // from any database's migrations table drops the package tables. Set
    // false when you run `migrate` for several databases (a database per
    // tenant): then run the package migration with --path on the database
    // of the package tables only.
    'load_migrations' => env('ORRERY_LOAD_MIGRATIONS', true),

    // The package's own database sessions: one per database connection.
    'sessions' => [
        // Postgres connections behind a transaction pooler (PgBouncer,
        // Supavisor and similar, transaction mode): true for all, or a list
        // of connection names (ORRERY_SESSIONS_POOLED: a boolean word such as
        // 1 or true, or a comma-separated list). A pooled session sends no
        // session SET, and supports only the server's default search_path.
        // MySQL and MariaDB ignore it.
        'pooled' => env('ORRERY_SESSIONS_POOLED', false),

        // A package statement stops after this many milliseconds (MySQL
        // max_execution_time, which ends only reads; MariaDB
        // max_statement_time; Postgres statement_timeout, on a pooled
        // connection a SET LOCAL in each package transaction and with each
        // statement outside one; SQLite has none). 0 turns the timeout off.
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

    // The watcher: a dependency error of the session of the
    // package tables (a lost connection), or two lock timeouts on it within
    // a minute, opens the mirror breaker for this many seconds.
    //
    // shared_breaker: the open breaker reaches the next requests through a
    // small file, because under PHP-FPM every request starts with a new
    // application. null (the default) uses the file only when PHP does not
    // run as cli (so not under Octane on Swoole or RoadRunner; FrankenPHP,
    // also in worker mode, uses it), true always,
    // false never. shared_breaker_path is its directory (null:
    // storage/framework).
    'observer' => [
        'breaker_seconds' => 30,
        'shared_breaker' => null,
        'shared_breaker_path' => null,
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

        // The intended worker layout. Orrery never starts workers: `php
        // artisan orrery:workers --format=supervisor|systemd|forge|list`
        // prints the process-manager configuration of these pools, and
        // every worker gets --name=<pool>. Keyed by a stable pool name
        // (lowercase letters, digits and dashes). Each pool: `connection`
        // (a `database` queue connection), `queues` (in priority order),
        // `processes` (at least 1), and the optional queue:work options
        // tries, timeout, sleep, backoff (seconds, or a list), memory (MB),
        // max_jobs, max_time, rest and force. An option left out keeps
        // Laravel's default. An unknown key or a wrong type is an error.
        'pools' => [
            // 'default' => [
            //     'connection' => 'database',
            //     'queues' => ['high', 'default'],
            //     'processes' => 2,
            //     'tries' => 3,
            //     'timeout' => 90,
            // ],
        ],
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

    // The bulk runs of the dashboard and of orrery:retry. A bulk forget
    // needs an exact total of at most this many records, which the operator
    // types to confirm; each forget takes one slot of that total, so a run
    // never forgets more. A whole number from 1 to 100,000; any other value
    // refuses every bulk forget. Retries have no such limit.
    'bulk' => [
        'forget_max' => env('ORRERY_BULK_FORGET_MAX', 10000),
    ],

    // More queues to measure, beside the queues of `waits` and the queues
    // that the jobs table holds: 'connection' => ['queue', ...], or
    // 'connection:queue' entries. Names compare exactly.
    'connections' => [],

    // The package schedules orrery:tick (every minute), onOneServer() and
    // withoutOverlapping(). Set it to false to schedule it yourself, for
    // example on one dedicated server (docs/installation.md, "The scheduler").
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

    // More alerts on the long-wait routes (mail, Slack, SMS).
    'notifications' => [
        // A long-wait incident ends after a few consecutive known ticks
        // below the threshold. The event LongWaitRecovered always fires;
        // with `recovered` true the package also sends a recovery message
        // to the channels that got the incident's start message.
        'long_wait' => [
            'recovered' => true,
        ],

        // A minute whose failed jobs (all queues) reach `per_minute` sends one
        // notice, with the count and the largest failure groups. null turns
        // it off. After a notice, each channel waits `cooldown` minutes.
        'failure_spike' => [
            'per_minute' => null,
            'cooldown' => 30,
        ],
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

    // Retention per connection and queue. A list of entries; each has
    // a `connection` (a queue connection with the database driver), a
    // `queue`, or both, and one or more of the `trim` keys above, in
    // minutes. Per key, an entry with the connection and the queue wins,
    // then one with the queue only, then one with the connection only, then
    // `trim`. Queue and connection names compare exactly (case and spaces
    // count, also on MySQL and MariaDB), and may contain ':'. An entry's
    // connection also covers the other queue connections on the same jobs
    // table; two such connections in entries of one level are an error.
    // 0 means "trim at the next tick". The overrides trim only
    // the package's mirror rows, never the jobs or failed_jobs tables. An
    // entry whose connection is not configured is reported as unmatched in
    // GET /api/health; an invalid entry stops retention, as an invalid
    // `trim` value does. Example:
    // [
    //     ['connection' => 'database', 'queue' => 'stripe', 'completed' => 43200],
    //     ['queue' => 'emails', 'failed' => 20160],
    // ]
    'trim_overrides' => [],

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

        // The Jobs list: a list total counts at most this many rows;
        // a larger total is "at least" this value. At least 101 (a full page
        // of 100 and its extra row); a smaller value counts as 101.
        'list_count_cap' => 10000,

        // The Jobs list: a filtered search (a job class, a connection
        // without a queue, a search text) examines at most this many rows
        // per request, in the list's order; then the answer says
        // `search_limited` and the next page continues the scan.
        'search_candidates' => 50000,

        // The Classes screen (GET /api/classes) reads at most this many
        // per-class rows in all, newest minute first: enough for 500 classes
        // in every minute of the hour. With more (for example job names that
        // change per job), the older minutes are shown as not counted and
        // every sum as "at least".
        'class_rows' => 36060,
    ],

    // The stuck-job signals: GET /api/jobs/stuck and the job detail.
    // A pending or running job shows "repeated releases" once its current
    // cycle was released this many times, and "attempts not capped" once it
    // has this many attempts while Laravel enforces no attempt limit (no
    // tries, or a retry deadline that has not passed). A running job's
    // worker is "silent" when its lease has expired and its last heartbeat
    // is at least `worker_silence_seconds` old; that is a fact shown with
    // the job, not a stuck signal. Each value is a whole number of at
    // least 1 (the two thresholds also false, which turns that signal off);
    // another value takes the default.
    'stuck' => [
        'release_threshold' => 20,
        'attempt_threshold' => 20,
        'worker_silence_seconds' => 60,
        // Send each stuck incident to the routes of the long-wait notification
        // (mail, Slack, SMS), one message per queue, signal and channel in 5
        // minutes with a summary of the others. False keeps the event
        // StuckJobDetected and sends no notification.
        'notify' => env('ORRERY_STUCK_NOTIFY', true),
    ],

    // The redaction of job data in every dashboard response. The
    // built-in keys (password, secret, token, api_key, authorization,
    // cookie, ...) and patterns (bearer tokens, URL passwords, key=value
    // pairs and query parameters, webhook URLs) always apply; `keys` and
    // `patterns` add to them. `defaults` set to false removes the built-in
    // lists. A key matches by its trailing words (`stripe_api_key` matches
    // `api_key`). A pattern is a complete PCRE with delimiters and flags,
    // for example '~ACME-\d{4}~i'; its whole match is redacted. An invalid
    // pattern makes every job-data read answer 500 until it is fixed.
    // Job, queue and connection names, tags and uuids are never redacted.
    'redact' => [
        'defaults' => true,
        'keys' => [],
        'patterns' => [],
    ],

    // Silenced jobs: their completions show under "silenced" and keep
    // `trim.silenced`. `silenced` lists exact job classes (a string job
    // matches by its exact handler); a class that implements the package's
    // Silenced interface is silenced too. `silenced_tags` lists the tags that
    // silence a job.
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
