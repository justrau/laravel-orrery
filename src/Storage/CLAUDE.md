# src/Storage/

## Package sessions

- Every package read and write runs on a package session (`PackageSession`), never on the application's connection. `for($name)` gives the session `orrery.session.<name>`: one per connection name, cached per process by name, endpoint identity (`EndpointIdentity`), the session settings and a digest of the whole writer configuration, so a retargeted name, or a rotated password, gets a new session. Two names that reach one database stay two sessions. `forQueue($queueConnection)` uses `queue.connections.<q>.connection`, else `database.default`.
- `mirror()` is the session of `orrery.database`, else `database.default`. With that key empty and a SQLite file as default, the mirror is the sibling file `<name>.orrery.sqlite`, derived from the real path of the default connection's file at connection time. `PackageSchema::ensure()` installs the sibling itself. A file that cannot be created opens the breaker (`SiblingFileUnavailable`). An explicit `orrery.database` on a SQLite file that the package uses (by path, or by device and inode; a used connection given as a `file:` URI by the file it names) or on a `file:` URI is refused (`mirrorRefusal()`), also when it names that connection itself (`migrate` stops with the refusal and creates nothing; `orrery:install` and `/api/health` name the refusal). With `orrery.database` empty, the first change of the default connection or its endpoint inside a process is reported once to the application's exception handler (`MirrorMoved`).
- The package builds every session itself, never through `DatabaseManager::build()`: writer configuration (`read`, `sticky`, `direct`, `pooled` removed), not persistent, a 5 s connect timeout, no transaction manager, no event dispatcher, its own reconnector. So package statements never reach Telescope, Pulse or a test's query count.
- In-memory SQLite cannot be cloned: there every session is the configured connection (tests only).
- `PackageSession::bounded($session, $work)` gives one unit of maintenance or dashboard work its timeouts.
- `PackageSession::releaseAutomatic()` runs at `terminating` and on each `Looping`: it closes the automatic sessions of host SQLite files, so an idle reader never blocks a journal-mode change.

### Per engine

- MySQL and MariaDB: autocommit, `innodb_lock_wait_timeout` and `lock_wait_timeout` 5, `max_execution_time` (MariaDB `max_statement_time`) from `sessions.statement_timeout_ms`, in one `SET` right after the connect. The application's init command stays unchanged and runs first; the package never adds to it, so its settings work with multi-statements off. An application init command that names an isolation level gets `REPEATABLE READ` after it, as a statement of its own.
- Postgres, direct: `READ COMMITTED`, `lock_timeout = '5s'`, `statement_timeout`, `idle_in_transaction_session_timeout = '30s'`.
- Postgres, pooled (`sessions.pooled`, read by `PackageSession::pooledSetting()`: true, a boolean word or a list of names, also comma-separated; or Laravel's `pooled`/`direct` keys): no session `SET`. Transactions begin with `SET LOCAL` timeouts in one `exec()`; each autocommit statement carries its own `SET LOCAL`. Only the server's default `search_path` is supported; relations are named with their schema. Namespace-changing `server_options` are refused (`PooledSessionRefused`).
- SQLite: `busy_timeout` 5000 ms. On a host file only `busy_timeout` and `foreign_key_constraints`; an automatic session opens it read-only, an operator session read-write.

## The connect bound

An unreachable server costs one connect timeout (`CONNECT_TIMEOUT_SECONDS`, 5 s) per statement, on every OS. Laravel connects again when its lost-connection detector knows the error, and mysqlnd on Linux reports a connect timeout in a form it knows (`[2002] Connection timed out`), so one statement could wait 4 × 5 s.

- Sessions build their PDO through `SessionConnectionFactory` with the package's connectors (`Connectors\*`, trait `ConnectsOnce`), which never connect again after a failed connect. A connector the application binds as `db.connector.<driver>` is used as is. SQLite keeps Laravel's connector. The application's own connections never use this factory.
- A server session reconnects only after a connect that succeeded (`ReconnectsOnlyAfterAConnect`).
- Limits: several configured hosts get one attempt each; libpq applies its timeout per address; a slow name lookup adds to it.
- No read timeout: only `PDO::ATTR_TIMEOUT`, the connect timeout, is set. A statement on an established connection to a server that vanishes without a TCP reset waits until the kernel gives up; the server-side statement timeout cannot end it. libpq's `tcp_user_timeout` and keepalive keys are not set (an older libpq refuses an unknown key), and mysqlnd's read timeout is a global ini setting that would change the application's connections too. `docs/known-limits.md` lists it.
- `ConnectAttemptsTest` reproduces the Linux text on every OS through `PackageSession::useConnectAttemptForTesting()`.

## Transactions

`PackageTransaction::run()` is the one package transaction. On SQLite it runs `BEGIN IMMEDIATE` on the PDO (at most 3 attempts on `SQLITE_BUSY`, then `PackageTransactionBusy`) and reports its own `BEGIN`, `COMMIT`, `ROLLBACK` to the recorder. Inside an open transaction it uses a savepoint. On MySQL and Postgres it uses Laravel's transaction methods and never retries. `abandon($session)` rolls back a package transaction that a job-timeout alarm interrupted: the `JobFailed` and `JobTimedOut` listeners call it on the mirror first, so their writes run in autocommit instead of joining a transaction that the kill discards. It also finds an alarm that arrives while the begin waits: on SQLite run() holds the PDO from just before `BEGIN IMMEDIATE`, and on a server it ends a transaction that the PDO reports at Laravel's level 0. With `Worker::$killOnTimeout` false the alarm's exception leaves the begin, and run() and `PackageSession::bounded()` end that transaction too (a SQLite `ROLLBACK`; a server session is closed).

## Clocks and times

- Every stored package time is epoch seconds in a `decimal(16,6)` column, bound through `Clock::toDecimal()` from one sampled value. Exceptions: `retry_until` (integer), `orrery_signals.expires_at` (`dateTime` in UTC, `Models\Casts\UtcDateTime`), `orrery_samples.minute` and `over_limit_since` (integer minute epochs).
- A stored time that PHP compares with `Clock::now()`, or with a bound computed from it, reads through `Clock::stored()`, never a plain `(float)` cast: some SQLite builds keep a decimal as the float next to the nearest one, so a check at the exact end of a grace, a lease or a cooldown decided wrongly. Tests write such a float with `Support\OffGridTime`.
- `DatabaseClock::now($connection)` reads a database server's clock. Operations use it for every deadline, each in its own database's clock; terminate signals use the mirror's clock. `DatabaseClock::expression()` gives the SQL form.

## SQLite host files (D17, D18)

- `HostFiles` decides the host files by `realpath()`: every `database` queue connection's file, the failed-record and batch connections, the default connection when the mirror is the sibling, and the data and lock connections of the default and schedule cache stores. Two names or a symlink to one file are one host file.
- **D17: no automatic write.** No runner step, fence retention, cache access or schedule mutex writes a host file. Only operator actions do, through `PackageSession::operator($work)` or `asOperator($work)` (and `forAction()`): retry, forget, `orrery:clear`, `orrery:reconcile`, `orrery:resolve`, `POST /api/operations/{id}/resolve`, bulk runs. A step that needs such a write waits as "awaiting resolution" (`Retry\AwaitingOperator`).
- **D18: automatic reads only in WAL.** `allowsReads()` holds only when `sqlite_automatic_access` is true, every configured name of the file sets WAL and no EXCLUSIVE locking mode, and every `busy_timeout` is above 0. WAL is confirmed once per unit of work on a read-only PDO. Otherwise the values are unknown and `/api/health` lists the file in `sqlite_warnings`.
- **The schedule.** When the schedule store writes a host file, the tick uses `Maintenance\SiblingFileMutex` (a `flock()` on `<file>.orrery-tick.lock`) instead of `onOneServer()->withoutOverlapping(10)`.
