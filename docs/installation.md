# Installation

## Requirements

| Item | Supported |
| --- | --- |
| PHP | `^8.3` (tested on 8.3, 8.4 and 8.5) |
| Laravel | 12.69.0 or later, and 13.30.0 or later |
| Databases | MySQL 8.4, Postgres 18, and the SQLite of PHP 8.3 to 8.5 (the tested versions). MariaDB and other MySQL and Postgres versions are not tested yet. |
| Queue driver | `database` only |
| Failed-job driver | `database-uuids` (retry and forget) or `null` (retry from the mirror's copy of the payload, forget in the mirror only). Every other failed-job driver is read-only: its failures show, and retry and forget refuse them. See [Retry and forget](operations.md#retry-and-forget). |

- **Extensions.** `pcntl` is recommended: Laravel's worker needs it to stop cleanly on a signal and to enforce `--timeout`. `posix` is recommended for a terminate in a process that runs no Laravel `Worker` (see [Pause, continue and terminate](operations.md#pause-continue-and-terminate)).
- **Notification channels (optional).** Slack needs `laravel/slack-notification-channel` 3.x (tested from 3.8). SMS needs `laravel/vonage-notification-channel`.

## Install the package

Orrery is not on Packagist yet. Composer installs it from the public GitHub repository.

1. Add the repository to the `composer.json` of your application:

   ```bash
   composer config repositories.orrery vcs https://github.com/justrau/laravel-orrery
   ```

2. Require the package:

   ```bash
   composer require justrau/laravel-orrery:^0.2
   ```

3. Run the installer and the migrations:

   ```bash
   php artisan orrery:install
   php artisan migrate
   ```

   The installer:

   - publishes `config/orrery.php` (tag `orrery-config`);
   - publishes `app/Providers/OrreryServiceProvider.php` (tag `orrery-provider`) and adds it to `bootstrap/providers.php`. Without that file, add the provider to your providers list yourself;
   - publishes the package migration into `database/migrations` (tag `orrery-migrations`);
   - writes one fence migration for each other database that needs the fence table `orrery_fences` (see [Retry and forget](operations.md#retry-and-forget));
   - reports the package database, the schema state, the missing fence tables and invalid retention settings. It exits 1 when it refuses the package database (an `orrery.database` on the SQLite file of your queue, failed jobs, batches or cache).

   The migration creates the `orrery_*` tables on the connection `orrery.database` (`ORRERY_CONNECTION`), or on your default connection when it is empty. When `orrery.database` is empty, the package tables follow the default connection at every use. If your application changes its default connection or database at runtime (for example a multi-tenant package), set `ORRERY_CONNECTION` to a fixed connection. Otherwise the package rows go to each tenant database. The package reports the first such change in a process to your exception handler.

   The package migration does nothing when the package tables of this release already exist, so a second `migrate` from another migrations table keeps them.

   **A rollback of the package migration drops every package table and its rows, from whichever database's migrations table it runs.** `migrate:rollback`, `migrate:reset` or `migrate:refresh` on any database that recorded the package migration drops the tables on `ORRERY_CONNECTION`. If you run `migrate` for several databases (a database per tenant), set `ORRERY_LOAD_MIGRATIONS=false`, move the published package migration out of the tenants' migration path, and run it with `--path` on the database of `ORRERY_CONNECTION` only.

## The queue

- Use the `database` queue connection (`QUEUE_CONNECTION=database`). Create the `jobs` table with `php artisan make:queue-table` when your application does not have it.
- Use the failed-job driver `database-uuids` (`QUEUE_FAILED_DRIVER=database-uuids`, Laravel's default), so retry and forget work.
- When you use job batches, create the batches table with `php artisan make:queue-batches-table`.
- When a queue connection or the failed-job connection is on another database than the package tables, run `php artisan orrery:install --fence`, then `php artisan migrate`. Run it again after you add a queue connection: it writes only the missing fence migrations.

## SQLite

- Set `journal_mode` to `wal` and a `busy_timeout` above 0 on every connection of an SQLite file that the queue, the failed jobs, the batches or a cache store use. Without them, the package does not read that file automatically (see [SQLite files](storage.md#sqlite-files)).
- Set `'transaction_mode' => 'IMMEDIATE'` on these connections, for your application's own sake: in the default DEFERRED mode, Laravel's database queue fails against every concurrent writer (PHP 8.4 or later).
- With `orrery.database` empty and an SQLite file as the default connection, the package keeps its tables in a sibling file: `database/database.sqlite` gets `database/database.orrery.sqlite`. The package creates and installs this file itself.

## Access

The host application must authenticate its users. Orrery has two abilities, which you define in `app/Providers/OrreryServiceProvider.php`:

- `viewOrrery` opens the dashboard and every read route of the API.
- `operateOrrery` allows every write: pause, continue and terminate; the retry and the forget of a failed job; a bulk run; the resolve step of an operation; adding and removing a monitored tag. The server checks it by the HTTP method, so every route except GET, HEAD, OPTIONS and TRACE needs it.
- In the `local` environment, every user (also a guest) can view and operate. In other environments, the published stub allows nobody until you add users.
- A user who needs `operateOrrery` also needs `viewOrrery`.

For example, one admin can view and operate, and a support colleague can only view:

```php
protected function gate(): void
{
    Gate::define('viewOrrery', function ($user = null) {
        return in_array($user?->email ?? '', [
            'admin@example.com',
            'support@example.com',
        ]);
    });

    Gate::define('operateOrrery', function ($user = null) {
        return in_array($user?->email ?? '', [
            'admin@example.com',
        ]);
    });
}
```

The gates get the user of `orrery.guard` (`ORRERY_GUARD`; empty: the default guard). Set it when your dashboard users sign in on another guard (for example `admin`). A value that is not a usable guard denies both abilities. See [Security](security.md) for the 401 and 403 answers.

## Workers

Run your workers as usual, under an external process manager (systemd, supervisord or Forge):

```bash
php artisan queue:work database --queue=default
```

**Configure every connection that a worker uses in the application that serves the dashboard too.** The dashboard decides when a reservation expires from the `retry_after` of the job's queue connection in its own configuration. Without it, a running job has an unknown lease: the dashboard shows no expiry for it, never flags it as expired, and decides whether its worker is live from the heartbeat only.

## The scheduler

The package schedules `orrery:tick` every minute, with `onOneServer()` and `withoutOverlapping(10)`. Your application must run the Laravel scheduler:

```cron
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

- `onOneServer()` needs a cache store that all servers share (for example Redis, Memcached, database or DynamoDB). With a `file` or `array` store on several servers, each server runs the tick.
- To run the tick on one dedicated server only, set `orrery.schedule` to `false` (`ORRERY_SCHEDULE=false`) and add the line to your own schedule. Keep `onOneServer()` and `withoutOverlapping()`: two ticks at the same time do the same work twice.

  ```php
  use Illuminate\Support\Facades\Schedule;

  Schedule::command('orrery:tick')->everyMinute()->onOneServer()->withoutOverlapping(10);
  ```

- The tick runs in the foreground of `schedule:run`, before your own scheduled tasks. A slow tick (up to `orrery.tick.deadline_seconds`, plus the shares of the three notification phases: stuck events, stuck notifications and long-wait alerts) delays them. To run it in the background, set `orrery.schedule` to `false` and add `->runInBackground()` to the line above. Do not do this when the tick uses the file lock of a SQLite setup (see [SQLite files](storage.md#sqlite-files)): that lock belongs to the `schedule:run` process.
- Without the tick, the dashboard still shows the jobs. But old rows stay, the charts get no counts, interrupted retries stay open, and no alert is sent.

## Open the dashboard

Open `/orrery` in your browser (`ORRERY_PATH` changes the path). Then dispatch a job and see it:

```bash
php artisan tinker --execute="dispatch(fn () => logger('Hello from Orrery'));"
php artisan queue:work database --once
```

The job shows in the Jobs list as pending, then as completed.

You do not need Node or npm: the dashboard CSS and JavaScript ship prebuilt in `dist/`, and the page inlines them.
