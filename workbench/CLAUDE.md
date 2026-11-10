# workbench/

`workbench/fixtures.md` describes the launcher, the build, the seeded states, the authorization modes and the Content Security Policy. Read it before you change the Workbench.

- Start every Workbench command through the launcher `php workbench/bin/testbench <command>`, never `vendor/bin/testbench`. The Composer scripts do this.
- The Workbench uses only SQLite files inside `workbench/database/`.
- `composer build` deletes `workbench/database/database.sqlite` and its sibling `database.orrery.sqlite` (with their `-wal`, `-shm`, `-journal` files), migrates, seeds, and runs `workbench/check.php`.
- `composer serve` runs `composer build`, then `testbench serve`. Open `/orrery`. Set the port with `SERVER_PORT` (Composer passes no `--port`). Press Ctrl-C in its terminal to stop it: a SIGINT to the Composer process alone leaves the `testbench serve` and `php -S` children running.
- `composer serve:strict` (or `WORKBENCH_AUTH=strict`) serves in the `production` environment with a login form and three seeded users; see `fixtures.md`. `ORRERY_TEST_SQLITE_JOURNAL=delete composer serve` serves in rollback mode (no automatic reads, D18).
- Every mode serves the dashboard under the enforced CSP of `docs/security.md` (`Workbench\App\Http\Middleware\ContentSecurityPolicy`).
- `composer workbench:worker` pushes one slow job onto the queue `slow` and starts a worker, so the dashboard shows a running job. `composer workbench:check` checks the database files, tables and counts.
- Stop `composer serve` and `composer workbench:worker` of this checkout before you run the group `workbench` (and so `composer test:all`): they share `workbench/storage/`.
