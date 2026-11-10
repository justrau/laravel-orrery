# CLAUDE.md

A Laravel package (`justrau/laravel-orrery`, namespace `Justrau\Orrery`) that gives Laravel's **database** queue driver a Horizon-style dashboard. It is a **watcher only**: it never spawns or supervises workers (users run `queue:work` under systemd, supervisord or Forge). It observes the queue lifecycle and gives a dashboard, per-minute metrics, tag monitoring, failed-job retry and forget, bulk runs, stuck-job incidents and a pause/continue/terminate control plane.

The GitHub repository is `justrau/laravel-orrery` (public). The package is not on Packagist. ADR-001 in `docs/architecture/overview.md` maps old names to new ones; new code uses the new names.

## Where to read more

Claude Code loads a nested `CLAUDE.md` when it reads files in that folder. Read the one for the area you change.

| File | Holds |
| --- | --- |
| `src/CLAUDE.md` | The layout, the registration modules, binding lifetimes, commands, configuration. |
| `src/Storage/CLAUDE.md` | Package sessions, the connect bound, SQLite host files (D17/D18), transactions, clocks. |
| `src/Runtime/CLAUDE.md` | The watcher, the breaker, degradation, health events, `SafeError`. |
| `src/Observation/CLAUDE.md` | Payload classification, the swapped queue, commit deferral. |
| `src/Lifecycle/CLAUDE.md` | `JobStore`, `Rules`, the hot-path budget. |
| `src/Retry/CLAUDE.md` | The operation ledger, the fence, retry, forget, the runner, the resolver. |
| `src/Monitoring/CLAUDE.md` | Samples, wait times, stuck signals, incidents and notifications. |
| `src/Control/CLAUDE.md` | Workers, the pause model, terminate, signals. |
| `src/Maintenance/CLAUDE.md` | The tick, its phases, retention. |
| `src/Database/CLAUDE.md` | Schema rules, deferred indexes, the upgrade path. |
| `src/Http/CLAUDE.md` | The JSON API: auth, conventions, lists, bulk runs, endpoints. |
| `resources/js/CLAUDE.md` | The SPA: rules, CSP, components, screens, JS tests. |
| `tests/CLAUDE.md` | The harness, schema modes, worker processes, hold points, groups, known failures. |
| `bin/CLAUDE.md` | `bin/test`, `bin/test-db`, the heavy-run slot, the ownership contract, `bin/matrix`, `bin/tia`, `bin/check-assets`. |
| `workbench/CLAUDE.md` | The Workbench (details in `workbench/fixtures.md`). |

The user docs are in `docs/` (index `docs/README.md`); `README.md` is the short overview. `bin/verify-install` runs the install commands of the README's "Installation", and reads the CSP middleware from `docs/security.md` and the removal from `docs/upgrading.md`: keep those sections parseable. `docs/` is not in the export.

Design docs are in `docs/architecture/`: `overview.md` (the architecture for a new reader) and `lifecycle-rules.md` (every rule of `Lifecycle\Rules`). Both describe the current code. The specs and plans are in `.internal/specs/` and `.internal/plans/` (the maintainers' notes, not in the repository); the current spec for the API and UI is `.internal/specs/2026-11-08-d8-ui-api-end-state.md`.

## Hard rules

Every change keeps these.

- **Watcher only, never throw into the host.** Every package operation runs inside `Runtime\Watcher::run()` or `attempt()`. The queue, the job and the application's transactions behave as without the package. Reports carry no job value (`SafeError`).
- **Unknown is never zero.** A value that could not be read is null, absent or `unknown`, never 0, "idle" or "healthy", in the API and in the SPA. A capped or censored value says "at least".
- **The hot-path budget is fixed.** `HotPathBudgetTest` and `FailurePathBudgetTest` pin the round trips of the success and failure paths exactly (untagged 1 + 1 + 2, tagged 4 + 1 + 2). Change a budget only with a spec change.
- **Every package read and write goes through `Storage\PackageSession`**, never the application's connection.
- **SQLite host files.** D17: the package never writes a host file automatically; only operator actions do. D18: automatic reads only in WAL. See `src/Storage/CLAUDE.md`.
- **Static state.** Package state lives in container bindings (singleton for process state, scoped for a unit of work). A new static is allowed only for the public static API or a test seam, listed in `StaticStateInventoryTest` with its reset in the owning module's `resetForTesting()`. See `tests/CLAUDE.md`.
- **Schema edits only in `Database\Schema\PackageSchema`**, and the main migration is never edited. The baseline (version 10, v0.1) is frozen by fixtures and hashes. Before the tag, edit `create()` and re-capture the fixtures; after it, add a step and an upgrade migration. See `src/Database/CLAUDE.md`.
- **The SPA.** No static `style=`, `<style>` or `v-html` in templates, no eval in the bundle (the CSP); every write control hides without `canOperate`, and the server still refuses with 403. See `resources/js/CLAUDE.md`.
- **Every write route needs the operate ability**, by HTTP method (`Http\Middleware\Authenticate`).

## Commands

```bash
composer install                     # composer.lock is gitignored; in a worktree, copy the main checkout's composer.lock first, so bin/tia's map matches the vendor versions
pnpm install                         # frontend work only (pnpm-lock.yaml is committed; pnpm-workspace.yaml sets the minimum release age)
bin/tia                              # the inner loop: only the test classes the change can affect
composer test -- --filter test_name  # one test; composer test -- tests/Feature/X.php for one file
vendor/bin/phpunit --group <scripts|workbench> <file>  # a file of an excluded group (composer test runs none of it)
bin/test <sqlite|mysql|pgsql> [--sqlite-file] -- <files>   # tests on an engine (see bin/CLAUDE.md)
composer lint                        # PHPStan level 8 with Larastan (through workbench/bin/phpstan)
composer format                      # Pint, Laravel preset (pint.json); composer format:check only reports
pnpm test                            # JavaScript unit tests
pnpm run build                       # Vite build to dist/ (committed)
bin/check-assets                     # proves that the build inputs reproduce dist/
composer serve                       # builds and seeds the Workbench, then serves /orrery
```

`composer test` (in-memory SQLite, the default selection) takes under a minute; a server suite takes minutes; `bin/matrix` takes hours.

**PHPStan** runs at level 8 for PHP 8.3 (`phpVersion: 80300`, the floor of `composer.json`), with no baseline, no `ignoreErrors` and no `@phpstan-ignore` (`StaticAnalysisConfigTest` checks this). Fix a finding in the code, never in the configuration. Start it only through `composer lint`: a direct `vendor/bin/phpstan` fails with "Application bootstrap failed". Its cache is `.phpstan-cache/` per checkout. `Storage\TrustedSql` is the only place that asserts literal-string.

## Test policy

Run as few tests as possible. While working and at the end of a task: `bin/tia` (or one test file), plus the task's own new or changed test files once on each engine whose SQL the task touches (`bin/test <engine> --drop-database -- <files>`), and `composer lint`. When the task changes `resources/`, also `pnpm test`, `pnpm run build` and `bin/check-assets`. Whole suites, `composer test:all`, server suites and docker run only rarely, after a large change is wrapped up. The full matrix runs before a release (`bin/matrix` locally, or the same legs in parallel on CI: `.github/workflows/tests.yml`). Never run two test runs at once on one machine.

Each parallel run (a worktree, an agent, a matrix leg) uses its own database name (see `bin/CLAUDE.md`). `tests/known-failures.md` is empty: never add a row for a bug of your own task; fix it.

## Conventions

- Commit subjects are shorter than 70 characters, with a conventional prefix (`feat:`, `fix:`, `docs:`, `test:`, `chore:`, `refactor:`).
- Run `composer format` before committing. `pint.json` excludes the files that tests pin byte for byte (the main migration, the frozen pre-D8 configuration fixture).
- Stage explicit paths only. Never `git add -A` or `git add .`.
- `dist/` is committed. After a change in `resources/js/` or `resources/css/`, run `pnpm test` and `pnpm run build` and commit `dist/`. The package publishes no assets: `Orrery::css()` and `Orrery::js()` inline `dist/` into the page.
- `.excluded/` is gitignored reference material (`.excluded/horizon-issues/index.md` sorts Horizon's issues by demand). Never commit anything under it.
- Put a class in the directory of its area; a tick phase goes in the `Phases/` directory of its area. `src/Support/` is gone.
- The `origin` remote is `git@github.com:justrau/laravel-orrery.git`. `main` is the trunk; direct commits to `main` are authorized.
- The workflows in `.github/workflows/` run only when started by hand (`gh workflow run tests.yml --ref main`), to save Actions minutes.
