# Orrery documentation

Orrery is a Horizon-style dashboard for the `database` queue driver of Laravel. The [README](../README.md) has the overview and the quick install. These pages have the details.

## For users

- [Installation](installation.md): the requirements, the full install, the queue, SQLite, access, workers, the scheduler and the first look at the dashboard.
- [The dashboard](dashboard.md): the screens, with screenshots, and what the numbers mean.
- [Configuration](configuration.md): every key of `config/orrery.php`, and the retention.
- [Notifications](notifications.md): the long-wait alerts, the stuck jobs and their notifications.
- [Operations](operations.md): pause, continue and terminate, retry and forget, bulk runs, deploys, worker pools, turning Orrery off and the commands.
- [Storage](storage.md): the database sessions, SQLite files, the optional `jobs` index, the deferred indexes and the measurement cache.
- [Security](security.md): access, redaction, error reports and the Content Security Policy.
- [Known limits](known-limits.md): what Orrery does not do, or does only in part.
- [Performance](performance.md): the cost per job and of the tick.
- [Upgrading](upgrading.md): upgrades, schema dumps, an install from a build before 0.1.0, and removing Orrery.

## For contributors

- [CONTRIBUTING.md](../CONTRIBUTING.md): the development setup, the tests and the conventions.
- [Architecture overview](architecture/overview.md): the architecture for a new reader, with the decision records.
- [Lifecycle rules](architecture/lifecycle-rules.md): every rule of the job lifecycle.
- [Tick benchmark](performance/tick-benchmark.md): the measured tick phases and index builds.
