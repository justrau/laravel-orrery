# Storage

## Database sessions

- Orrery reads and writes through its own database sessions: one per connection name, with its own PDO. A session uses the writer of the connection (never a read replica), sends no Laravel event (so Telescope, Pulse and query counts do not see it), and never joins a transaction of the application.
- Each session connects with a timeout of 5 seconds and does not connect again after a failed connect, so an unreachable server costs at most one connect timeout per statement. A connection that drops after a successful connect is opened again once. A connector that the application binds as `db.connector.<driver>` is used as it is.
- Each session has a lock timeout of 5 seconds and the statement timeout `sessions.statement_timeout_ms`:
  - MySQL and MariaDB: `innodb_lock_wait_timeout` and `lock_wait_timeout`, `max_execution_time` (MariaDB `max_statement_time`), autocommit, after the application's own init command. MySQL's `max_execution_time` ends only reads. MySQL has no idle-in-transaction timeout.
  - Postgres: `lock_timeout`, `statement_timeout`, `idle_in_transaction_session_timeout` of 30 seconds, and READ COMMITTED.
  - SQLite: `busy_timeout` of 5 seconds. A package transaction that gets `SQLITE_BUSY` starts again, at most three times, each time with that wait, so a write-locked file can hold one package write for up to 15 seconds.
- **Postgres behind a transaction pooler** (PgBouncer, Supavisor and similar, in transaction mode): name the connection in `sessions.pooled`. A pooled session sends no session `SET`; it sets its timeouts with `SET LOCAL` in each transaction and in the same query as each statement outside one. It supports only the server's default `search_path`: remove a `search_path` or `schema` key from the connection, or set it to the server's default (Laravel's default `database.php` sets `public`, which is not the default `"$user", public`). A `SET ROLE` that another client left on a pooled backend still changes the privileges of the package's statements.
- An unreachable package database pauses the observations for `observer.breaker_seconds`. Another unreachable queue database affects only the work on that database.

## SQLite files

- **The sibling file.** The package creates the sibling file, installs its schema and upgrades it. It never drops it: `migrate:rollback` and `migrate:fresh` leave it as it is. Laravel's default `.gitignore` (`database/*.sqlite*`) covers it. Each process of Laravel's parallel testing gets its own sibling. Tests that use `RefreshDatabase` keep its rows between tests; the tick marks a row whose job is gone as `unknown`.
- An `orrery.database` that names the SQLite file of a queue, the failed jobs, the batches or a cache store (also through a link, another letter case, or a `file:` URI of that connection), or a SQLite `file:` URI, is refused (`sqlite_shared_mirror` in `/orrery/api/health`). Name a file of its own, or leave the key empty.
- **No automatic write to your files.** The package never writes to an application SQLite file (a queue, the failed jobs, the batches, or a cache store) on its own. Only an operator's action writes there, in short transactions: a retry, a forget, a bulk run, `orrery:clear`, `orrery:forget` and `orrery:resolve`. A retry briefly locks the database, like a native `queue:retry`. A step of an interrupted operation that needs such a write waits for an operator ("awaiting resolution").
- **Automatic reads only in WAL.** The package reads an application SQLite file automatically only when every connection of the file sets `journal_mode` `wal`, a `busy_timeout` above 0 and no `EXCLUSIVE` locking mode, and `sqlite_automatic_access` is true. In rollback mode a reader can make the application's COMMIT fail. On any other file the values that the package would read show as unknown (the backlog and wait of its queues, the batches, the fence check, the restart markers), and `/orrery/api/health` (`sqlite_warnings`) and `orrery:install` warn: "SQLite: enable WAL (journal_mode wal) and a busy_timeout so Orrery can observe the queue without affecting it." Run `php artisan orrery:reconcile` to reconcile against such a file. The package never changes the application's journal mode. Set `sqlite_automatic_access` to `false` when your application switches a file to `EXCLUSIVE` locking at runtime.
- When the default cache store is a `database` store on an application SQLite file, the package does not use it: the measurement cache is off, the long-wait lock and cooldowns go to the package tables, and Laravel's native pause state of each queue is unknown. When the scheduler's cache store is such a store, the tick takes a file lock next to the sibling (`<file>.orrery-tick.lock`) instead of `onOneServer()`: that setup has one server.
- With `SESSION_DRIVER=database` on an SQLite file, the `web` middleware writes the session row on every dashboard poll. Use `file` or `cookie` sessions, or change `orrery.middleware`.

## The optional `jobs` index

The long-wait check and the dashboard read the backlog of your `jobs` table with one grouped read per queue connection, which visits every row of the table. With an index on (`queue`, `reserved_at`, `available_at`) the read visits every entry of that smaller index instead of the table rows; its cost still grows with the number of rows. Add it when your `jobs` table often holds many thousands of rows:

```bash
php artisan vendor:publish --tag=orrery-jobs-index
php artisan migrate
```

The package never runs this migration itself.

The build locks the `jobs` table. On Postgres the migration uses `CREATE INDEX CONCURRENTLY`, so pushes and reservations go on while it builds. On MySQL and MariaDB the build is online for most table formats. On SQLite every write to the database waits until the build ends, so run it at a quiet time on a large table.

## The deferred indexes

The stuck-job lists read three `orrery_jobs` indexes (`st_attempts`, `st_gap`, `st_releases`). A new install creates them with the tables. On an existing install, no migration builds them, because a build on a large table holds locks. Build them once after `php artisan migrate`:

```bash
php artisan orrery:install --indexes
```

- The build runs on a connection of its own, without the package's statement timeout. `--timeout=<seconds>` sets one (Postgres; MariaDB `max_statement_time`); the default 0 means none.
- Postgres: `CREATE INDEX CONCURRENTLY`, so writes continue; one build at a time; an invalid index from an interrupted build is built again. Behind a transaction pooler the build uses the connection's `direct` endpoint, else name a direct connection with `--connection=<name>`.
- MySQL and MariaDB: `ALGORITHM=INPLACE, LOCK=NONE`. While the build waits for a metadata lock (5 s, at most 12 tries), the package's own writes to `orrery_jobs` wait too and can count as dropped.
- SQLite: the build locks the package database file while it runs.
- Until the indexes exist, the stuck lists answer `index_missing`, and `/orrery/api/health` lists them in `missing_indexes` with the command.

## The measurement cache

The dashboard's queue read measures the backlog of your `jobs` tables, and each open dashboard polls it every 10 seconds. The reads share one measurement through the default cache store for `dashboard.measure_cache_seconds`. With several servers, a shared store lets them share it. The cache key holds no credentials. A `queue:pause` or `queue:continue` from the command line can show up to that many seconds late; a pause or continue from Orrery shows at the next poll. A measurement slower than the lifetime is never served from the cache, and the health events record `measure_cache:slow`. The tick never uses this cache: an alert always comes from a new measurement.
