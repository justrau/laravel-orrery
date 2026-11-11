# Contributing

This guide tells you how to set up the package, which tests to run, and how to commit. The nested `CLAUDE.md` files hold the full reference (see [Where to read more](#where-to-read-more)).

## Setup

- PHP 8.3 or later, with `pcntl` and `posix` for the process and script tests. Composer. Docker, for the MySQL and Postgres tests only.
- Node 24 and pnpm 11, for frontend work only. `.nvmrc` holds the Node version (`fnm use` or `nvm use`), `package.json` `packageManager` the pnpm version, and `engines` both constraints. `pnpm-workspace.yaml` sets a minimum release age, so pnpm installs no version that was published too recently, and it runs no install script of a dependency.

```bash
composer install
pnpm install    # frontend work only
```

`composer.lock` is not committed, because this is a library. `pnpm-lock.yaml` is committed.

## Tests

Run as few tests as possible. Never run two test runs at the same time.

- **While you work, and at the end of a change,** run `bin/tia`. It runs only the test classes that your change can affect, from a map that `bin/tia record` writes, and it falls back to the full scope, with the reason, when it cannot prove the selection. To run one test or one file: `composer test -- --filter test_name` or `composer test -- tests/Feature/X.php`.
- **On a database engine,** run the test files that you added or changed once on each engine whose SQL your change touches:

  ```bash
  bin/test sqlite --sqlite-file -- tests/Feature/X.php
  bin/test mysql --drop-database -- tests/Feature/X.php
  bin/test pgsql --drop-database -- tests/Feature/X.php
  ```

  `bin/test mysql`, `bin/test pgsql` and `bin/test mariadb` start the project's own test containers (`orrery-testing`, on `127.0.0.1:33106`, `127.0.0.1:54336` and `127.0.0.1:33108`) and give each run its own database. Put the `bin/test` options directly after the engine. `bin/test --help` prints every option. Never point the test variables at a database server that you use for other work.
- The process tests start real workers. They need a shared database, so they skip on in-memory SQLite: run them on a SQLite file or a server engine.
- A test that runs DDL or changes the schema or a database setting carries `#[Tests\Support\FreshSchema]`.
- The whole suites (`composer test:all`, the server suites, `composer test:docker`) and the full support matrix (`bin/matrix`) run rarely: after a large change, and once at the release gate.
- `tests/known-failures.md` is empty. Never add a row for a bug of your own change: fix it.

## Static analysis

```bash
composer lint
```

PHPStan runs at level 8 with Larastan, for PHP 8.3. It must report no errors, with no baseline, no `ignoreErrors` entry and no `@phpstan-ignore` comment. Fix a finding in the code, never in the configuration. Start it only through `composer lint`.

## Code style

```bash
composer format        # Pint with the Laravel preset (pint.json)
composer format:check  # reports, changes nothing
```

Run `composer format` before committing. `pint.json` excludes the files that tests pin byte for byte: the main migration and the frozen pre-D8 configuration fixture. It also turns off `php_unit_method_casing`, which renames every method whose name starts with `test`, helpers too, without their callers. `.git-blame-ignore-revs` lists the commit that applied Pint to the whole code base: run `git config blame.ignoreRevsFile .git-blame-ignore-revs` once, so `git blame` skips it.

## Frontend

The package ships the built dashboard in `dist/`, and `dist/` is committed. After a change in `resources/js/` or `resources/css/`:

```bash
pnpm test         # the JavaScript unit tests
pnpm run build    # writes dist/, including dist/THIRD_PARTY_NOTICES.txt
bin/check-assets  # proves that the build inputs reproduce the committed dist/
```

Commit the changed `dist/` files with the change. `bin/check-assets` refuses to start when `dist/`, `package.json`, `pnpm-lock.yaml` or `pnpm-workspace.yaml` has uncommitted changes, and it runs `pnpm install --frozen-lockfile`, which brings `node_modules/` in line with the lockfile. The page runs under a strict Content Security Policy: no `eval` in the bundle, and no static `style=`, `<style>` or `v-html` in a template.

To check the dashboard in a browser, run `composer serve` (it builds and seeds the Workbench) and open `/orrery` on the address that it prints. `SERVER_PORT=8793 composer serve` chooses the port. `workbench/fixtures.md` lists the seeded data.

## Commits

- Commit subjects are shorter than 70 characters, with a conventional prefix: `feat:`, `fix:`, `docs:`, `test:`, `chore:` or `refactor:`.
- Stage explicit paths only. Never `git add -A` or `git add .`.
- Never commit anything under `.excluded/`.
- CI has three tiers. `quick.yml` runs on every push to `main` and every pull request (not for a change of only `docs/` or Markdown): static analysis, Pint, the assets check, four in-memory SQLite legs and one PHP 8.5 and Laravel 13 job on every engine. `tests.yml` (the full matrix) runs nightly when `main` has a commit from the last 24 hours, `framework-latest.yml` weekly; both also start by hand (`gh workflow run tests.yml --ref main`). `zizmor.yml` lints a change of `.github/`, and Dependabot updates the actions, which are pinned by commit SHA.

## Where to read more

| File | Holds |
| --- | --- |
| `CLAUDE.md` | The hard rules of every change, the commands and the test policy. |
| `src/CLAUDE.md` and `src/<Area>/CLAUDE.md` | The layout and the rules of each area: `Storage`, `Runtime`, `Observation`, `Lifecycle`, `Retry`, `Monitoring`, `Control`, `Maintenance`, `Database`, `Http`. |
| `resources/js/CLAUDE.md` | The dashboard: its rules, components, screens and JavaScript tests. |
| `tests/CLAUDE.md` | The test harness, the schema modes, the worker processes and the groups. |
| `bin/CLAUDE.md` | `bin/tia`, `bin/test`, `bin/test-db`, the test containers, `bin/matrix` and `bin/check-assets`. |
| `workbench/CLAUDE.md` | The Workbench. |
| `docs/architecture/overview.md` | The architecture for a new reader. |
