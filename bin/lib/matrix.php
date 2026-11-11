<?php

/*
 * The local matrix runner (bin/matrix). Plain PHP without the
 * Composer autoloader, so it runs before any leg has a vendor directory.
 *
 * A run:
 * 1. Takes a snapshot of the checkout: the files of `git ls-files --cached
 *    --others --exclude-standard` (so new untracked files are included) are
 *    copied into .matrix/<run-id>/snapshot/, and their hash is recorded with
 *    the HEAD commit.
 * 2. For each leg (a PHP version, a Laravel major, latest or lowest):
 *    - copies the snapshot into .matrix/<run-id>/<leg>/ and verifies its hash;
 *    - checks that the leg's PHP binary reports the leg's PHP version;
 *    - runs Composer with that binary in the copy: `require --dev
 *      orchestra/testbench:^10.0|^11.0 --no-update`, then `update` (lowest
 *      legs add --prefer-lowest --prefer-stable, and only their Composer
 *      calls get COMPOSER_NO_BLOCKING=1);
 *    - records the resolved PHP, laravel/framework, Testbench and PHPUnit
 *      versions. A lowest leg fails when laravel/framework is not the floor
 *      of composer.json, or when the lock has a package that is not stable;
 *    - lists the tests (phpunit --list-tests-xml) for the group inventory;
 *    - then runs its units (Leg::units()): one per variant (mirror and
 *      prefix), and the scripts (or only the workbench) run. A variant unit runs PHPUnit through the leg's
 *      bin/test with the leg's PHP binary per engine: the suite without the
 *      required groups, then the required groups (harness, process,
 *      process-control) explicitly with --group, on the selected engines
 *      (in-memory SQLite, MySQL, Postgres, and MariaDB on MARIADB_LEGS or
 *      with --db) and always on file SQLite (the
 *      suite and the required groups, so a test that runs only on a SQLite
 *      file runs too). Only the members of the shared-database group may
 *      skip, and only on in-memory SQLite. The schema mode of each run is in
 *      SCHEMA_FRESH's comment. The MySQL and Postgres runs use the unit's
 *      database name with bin/test --drop-database, so each run's lease drops
 *      the databases when it releases;
 *    - checks every run: the known-failure check (bin/lib/known-failures.php,
 *      with the run's start time and output), PHPUnit's Runtime line, and the
 *      identity line of the harness (PHP, driver, database, prefix and
 *      evidence).
 *    Each bin/test run gets --timeout=<--timeout of bin/matrix>. bin/test
 *    stops a run at that limit and names its running test, which the run's
 *    failure reasons repeat. bin/matrix itself stops a run that is still
 *    running OUTER_MARGIN seconds later.
 *    Everything runs serially, one command at a time.
 * 3. After every leg, runs the docker group, once per PHP version.
 * 4. Writes a summary table to stdout and to .matrix/<run-id>/summary.md.
 *    After an internal error it stops the running child, writes the summary
 *    with the error, and lets the error reach PHP.
 *
 * Before its first leg, a run (not --ci, not --dry-run) takes the heavy-run
 * slot of the test registry (OrreryTestDb\Slot), and passes its token to
 * every bin/test run in ORRERY_SLOT_TOKEN, so its MySQL and Postgres runs
 * enter the slot that it holds. It waits up to --slot-wait seconds for a
 * slot that another heavy run holds, then exits 72.
 *
 * It continues after a failed leg, and exits 1 when any leg failed.
 */

declare(strict_types=1);

namespace OrreryMatrix;

use OrreryTestDb\Failure;
use OrreryTestDb\Registry;
use OrreryTestDb\Slot;

require_once __DIR__.'/known-failures.php';
require_once __DIR__.'/testdb.php';

const PHP_VERSIONS = ['8.3', '8.4', '8.5'];
const LARAVEL_MAJORS = [12, 13];
const ENGINES = ['sqlite', 'mysql', 'pgsql', 'mariadb'];
/** The engines of a leg without --db. MariaDB runs only on MARIADB_LEGS then. */
const DEFAULT_ENGINES = ['sqlite', 'mysql', 'pgsql'];
/** The engines of a server run (bin/test with a lease, or --no-provision in CI). */
const SERVER_ENGINES = ['mysql', 'pgsql', 'mariadb'];
/**
 * The MariaDB leg: without --db, only these legs also run on MariaDB (the
 * latest LTS release). Not with --ci: a CI job has only the service
 * containers that it declares, so the MariaDB job of tests.yml passes
 * --db=mariadb itself.
 */
const MARIADB_LEGS = ['php8.5-laravel13'];
const MIRRORS = ['same', 'separate'];
const TESTBENCH = [12 => '^10.0', 13 => '^11.0'];

/** Lowest legs run on the PHP floor of composer.json. */
const LOWEST_PHP = '8.3';

/** The latest leg that also runs the three other mirror and prefix variants. */
const VARIANT_PHP = '8.4';
const VARIANT_LARAVEL = 12;

const VARIANT_PREFIX = 'pfx_';

/** The labels of every variant (Variant::label()), in the order of a leg. --variants selects from them. */
const VARIANTS = ['same/none', 'separate/none', 'same/prefix', 'separate/prefix'];

/**
 * The schema modes of bin/test --schema. The fresh mode checks the harness
 * isolation and the migrations' down(), which differ per Laravel version, not
 * per PHP version, and #[FreshSchema] tests check them in reuse too. So only
 * these runs are fresh: in-memory SQLite (it has no other mode), the file and
 * server runs of the lowest legs and of the latest legs of FRESH_LATEST, the
 * file runs of the four variants of the variant leg, every run with --ci, and
 * every run with --all-fresh. The other file and server runs use reuse, the
 * server runs of the variant leg's variants too.
 */
const SCHEMA_FRESH = 'fresh';
const SCHEMA_REUSE = 'reuse';

/** The latest legs that stay fresh: the latest Laravel 13 must run fresh somewhere. */
const FRESH_LATEST = ['php8.5-laravel13'];

/**
 * The matrix runs strictly serially, one command at a time, on purpose: the
 * machine is shared with other agents and often loaded, and on 2026-11-07
 * four parallel server runs stalled the Docker VM for hours. It saves time by
 * running fewer runs instead (the schema modes, the scripts legs, --quick).
 */

/**
 * --quick, the preset of the wave merges: the variant leg (all four
 * variants), the latest Laravel 13 on the newest PHP (fresh), and
 * the Laravel 13 lowest leg (fresh). The docker group runs once, on PHP 8.4.
 * A phase end runs the full default matrix.
 *
 * @var list<array{0: string, 1: int, 2: bool}> PHP, Laravel, lowest
 */
const QUICK_LEGS = [['8.4', 12, false], ['8.5', 13, false], ['8.3', 13, true]];
const QUICK_DOCKER_LEG = 'php8.4-laravel12';

/** Groups whose every test must run on every engine. */
const REQUIRED_GROUPS = ['harness', 'process', 'process-control'];

/**
 * The documented engine skip: the tests that need a database
 * that several processes share carry this PHPUnit group, and they skip on
 * in-memory SQLite. Every other member of the required groups must run there
 * too, and no member may skip on file SQLite, MySQL or Postgres.
 */
const ENGINE_SKIP_GROUP = 'shared-database';

const DOCKER_GROUP = 'docker';

/**
 * The tests of a reachable second server: members of the
 * docker group that need `bin/test <mysql|pgsql> --alt`. The docker run has
 * no alt server, so it excludes them, and they are not required there. The
 * alt runs of the tasks and the merge points run them.
 */
const ALT_GROUP = 'alt';
const SCRIPT_GROUPS = ['scripts', 'workbench'];

/**
 * The scripts group tests bin/ and its PHP code, not Laravel, so it runs once
 * per PHP version: on the first selected latest leg of each PHP, preferring
 * Laravel 13, and on every lowest leg (the oldest PHPUnit). Every other leg
 * runs only the workbench group. The docker group runs once per PHP version
 * too (see Runner), on the leg that runs the scripts group, else on the first
 * lowest leg of that PHP.
 */
const WORKBENCH_GROUP = 'workbench';
const SCRIPTS_PREFERRED_LARAVEL = 13;

/**
 * A group that no test has. A command-line --exclude-group replaces the
 * exclusions of phpunit.xml.dist, so the test list names every test.
 */
const NO_EXCLUSION = 'orrery-matrix-no-exclusion';

// The longest measured run in a matrix leg is the MySQL suite in the fresh
// mode: 25:47 (1,547 s) in the wave-3 run of 2026-11-04 (PHP 8.3, Laravel 12,
// with other agents on the machine). The default fits 3 times that (4,641 s).
const DEFAULT_TIMEOUT = 4800;
// bin/test stops a run at --timeout and needs its grace and its cleanup after
// it. bin/matrix sends SIGTERM only to a run that is still running this much later.
const OUTER_MARGIN = 120;
const DEFAULT_SLOT_WAIT = 7200;
// The exit status when the heavy-run slot did not become free within --slot-wait.
const EXIT_SLOT = 72;
const COMPOSER_TIMEOUT = 1200;

final class UsageError extends \RuntimeException {}

final class Options
{
    /**
     * @param  list<string>  $php
     * @param  list<int>  $laravel
     * @param  list<string>  $db
     * @param  list<string>  $mirror
     * @param  array<string, string>  $phpBinaries
     * @param  list<string>  $variants  the selected variant labels (VARIANTS)
     */
    public function __construct(
        public readonly array $php,
        public readonly array $laravel,
        public readonly array $db,
        public readonly array $mirror,
        public readonly bool $latest,
        public readonly bool $lowest,
        public readonly bool $dryRun,
        public readonly array $phpBinaries,
        public readonly ?string $composer,
        public readonly ?string $runId,
        public readonly bool $ci,
        public readonly int $timeout,
        public readonly array $variants = VARIANTS,
        public readonly int $slotWait = DEFAULT_SLOT_WAIT,
        public readonly bool $allFresh = false,
        public readonly bool $quick = false,
        public readonly bool $memoryOnly = false,
        public readonly bool $dbGiven = false,
    ) {}

    /**
     * @param  list<string>  $arguments  without the program name
     */
    public static function parse(array $arguments): self
    {
        $values = [];
        $flags = [];
        $binaries = [];

        foreach ($arguments as $argument) {
            if (preg_match('/^--(php|laravel|db|mirror|variants|composer|run-id|timeout|slot-wait)=(.*)$/sD', $argument, $match) === 1) {
                if (array_key_exists($match[1], $values)) {
                    throw new UsageError("--{$match[1]} is given twice");
                }

                $values[$match[1]] = $match[2];

                continue;
            }

            if (preg_match('/^--php-binary=([0-9]+\.[0-9]+)=(.+)$/sD', $argument, $match) === 1) {
                $binaries[$match[1]] = $match[2];

                continue;
            }

            if (in_array($argument, ['--no-lowest', '--no-latest', '--dry-run', '--ci', '--all-fresh', '--quick', '--memory-only'], true)) {
                $flags[$argument] = true;

                continue;
            }

            throw new UsageError("unknown argument {$argument}");
        }

        if (isset($flags['--quick'])) {
            foreach (['--php' => isset($values['php']), '--laravel' => isset($values['laravel']), '--no-lowest' => isset($flags['--no-lowest']), '--no-latest' => isset($flags['--no-latest']), '--memory-only' => isset($flags['--memory-only'])] as $option => $given) {
                if ($given) {
                    throw new UsageError("--quick selects its own legs, so it takes no {$option}");
                }
            }
        }

        $php = self::list($values['php'] ?? implode(',', PHP_VERSIONS), PHP_VERSIONS, 'php');
        $laravel = array_map('intval', self::list($values['laravel'] ?? implode(',', LARAVEL_MAJORS), array_map('strval', LARAVEL_MAJORS), 'laravel'));
        if (isset($flags['--memory-only']) && isset($values['db']) && $values['db'] !== 'sqlite') {
            throw new UsageError('--memory-only runs in-memory SQLite only, so it takes no --db other than sqlite');
        }

        $db = isset($flags['--memory-only']) ? ['sqlite'] : self::list($values['db'] ?? implode(',', DEFAULT_ENGINES), ENGINES, 'db');
        $mirror = self::list($values['mirror'] ?? implode(',', MIRRORS), MIRRORS, 'mirror');
        $variants = self::list($values['variants'] ?? implode(',', VARIANTS), VARIANTS, 'variants');

        foreach (array_keys($binaries) as $version) {
            if (! in_array((string) $version, PHP_VERSIONS, true)) {
                throw new UsageError("--php-binary names PHP {$version}; the supported versions are ".implode(', ', PHP_VERSIONS));
            }
        }

        $runId = $values['run-id'] ?? null;

        if ($runId !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $runId) !== 1) {
            throw new UsageError('--run-id must match ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$');
        }

        $timeout = DEFAULT_TIMEOUT;

        if (isset($values['timeout'])) {
            if (preg_match('/^[1-9][0-9]{0,5}$/D', $values['timeout']) !== 1) {
                throw new UsageError('--timeout must be a number of seconds');
            }

            $timeout = (int) $values['timeout'];
        }

        $slotWait = DEFAULT_SLOT_WAIT;

        if (isset($values['slot-wait'])) {
            if (preg_match('/^[0-9]{1,6}$/D', $values['slot-wait']) !== 1) {
                throw new UsageError('--slot-wait must be a number of seconds');
            }

            $slotWait = (int) $values['slot-wait'];
        }

        if (isset($flags['--no-lowest']) && isset($flags['--no-latest'])) {
            throw new UsageError('--no-lowest and --no-latest together leave no leg');
        }

        return new self(
            $php,
            $laravel,
            $db,
            $mirror,
            ! isset($flags['--no-latest']),
            ! isset($flags['--no-lowest']),
            isset($flags['--dry-run']),
            $binaries,
            $values['composer'] ?? null,
            $runId,
            isset($flags['--ci']),
            $timeout,
            $variants,
            $slotWait,
            isset($flags['--all-fresh']),
            isset($flags['--quick']),
            isset($flags['--memory-only']),
            isset($values['db']),
        );
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string> in the order of $allowed
     */
    private static function list(string $value, array $allowed, string $option): array
    {
        $items = array_map('trim', explode(',', $value));

        foreach ($items as $item) {
            if (! in_array($item, $allowed, true)) {
                throw new UsageError("--{$option}: '{$item}' is not one of ".implode(', ', $allowed));
            }
        }

        return array_values(array_filter($allowed, static fn (string $item): bool => in_array($item, $items, true)));
    }
}

final class Variant
{
    public function __construct(public readonly string $mirror, public readonly string $prefix) {}

    public static function base(): self
    {
        return new self('same', '');
    }

    /**
     * For file names: same-none, separate-prefix.
     */
    public function key(): string
    {
        return $this->mirror.'-'.($this->prefix === '' ? 'none' : 'prefix');
    }

    public function label(): string
    {
        return $this->mirror.'/'.($this->prefix === '' ? 'none' : 'prefix');
    }

    public function engine(string $driver): string
    {
        return Engines::of($driver, $this->mirror, $this->prefix);
    }
}

final class RunSpec
{
    /**
     * @param  'memory'|'file'|'server'  $storage
     * @param  'suite'|'groups'|'scripts'|'workbench'|'docker'  $kind
     * @param  list<string>  $require
     * @param  list<string>  $engineSkip
     * @param  'fresh'|'reuse'  $schema  the bin/test --schema mode
     */
    public function __construct(
        public readonly Variant $variant,
        public readonly string $engine,
        public readonly string $storage,
        public readonly string $kind,
        public readonly array $require,
        public readonly array $engineSkip,
        public readonly string $schema = SCHEMA_FRESH,
    ) {}

    public function name(): string
    {
        return match ($this->kind) {
            'scripts', 'workbench', 'docker' => $this->kind,
            default => $this->variant->key().'.'.$this->engine.($this->storage === 'file' ? '-file' : '').'.'.$this->kind.($this->schema === SCHEMA_REUSE ? '.reuse' : ''),
        };
    }

    public function label(): string
    {
        return match ($this->kind) {
            'scripts' => 'groups '.implode(',', SCRIPT_GROUPS).' (sqlite in memory)',
            'workbench' => 'group '.WORKBENCH_GROUP.' (sqlite in memory)',
            'docker' => 'group docker',
            'suite' => $this->variant->label().' '.$this->storageLabel().' suite'.($this->schema === SCHEMA_REUSE ? ' (schema reuse)' : ''),
            default => $this->variant->label().' '.$this->storageLabel().' groups '.implode(',', REQUIRED_GROUPS).($this->schema === SCHEMA_REUSE ? ' (schema reuse)' : ''),
        };
    }

    public function storageLabel(): string
    {
        return match ($this->storage) {
            'memory' => 'sqlite (memory)',
            'file' => 'sqlite (file)',
            default => $this->engine,
        };
    }

    /**
     * The engine of the known-failure check. The scripts, workbench and
     * docker runs start PHPUnit on in-memory SQLite.
     */
    public function checkEngine(): string
    {
        return $this->variant->engine($this->engine);
    }

    /**
     * The PHPUnit selection. Each group is its own option: PHPUnit 11.5
     * deprecates comma-separated groups, and PHPUnit 12 reads them as one
     * group name.
     *
     * @param  list<string>  $configExcludes  the <groups><exclude> of the leg's PHPUnit configuration
     * @return list<string>
     */
    public function selection(array $configExcludes): array
    {
        return match ($this->kind) {
            // Everything that the configuration selects, without the
            // required groups, which the groups run selects explicitly.
            'suite' => self::options('--exclude-group', array_values(array_unique([...$configExcludes, ...REQUIRED_GROUPS]))),
            // A command-line exclusion replaces the configured one: the
            // required groups run completely, except their docker tests
            // (bin/matrix refuses a test in both).
            'groups' => [...self::options('--group', REQUIRED_GROUPS), '--exclude-group', DOCKER_GROUP],
            'scripts' => [...self::options('--group', SCRIPT_GROUPS), '--exclude-group', DOCKER_GROUP],
            'workbench' => ['--group', WORKBENCH_GROUP, '--exclude-group', DOCKER_GROUP],
            'docker' => ['--group', DOCKER_GROUP, '--exclude-group', ALT_GROUP],
        };
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function options(string $option, array $values): array
    {
        $options = [];

        foreach ($values as $value) {
            $options[] = $option;
            $options[] = $value;
        }

        return $options;
    }
}

final class Leg
{
    /**
     * @param  list<Variant>  $variants
     * @param  list<string>  $engines
     * @param  'fresh'|'reuse'  $fileSchema  the mode of the SQLite file runs of the variants
     * @param  'fresh'|'reuse'  $serverSchema  the mode of the MySQL and Postgres runs of the variants
     * @param  bool  $scripts  run the scripts and workbench groups; else only the workbench group
     * @param  bool  $docker  run the docker group, after every leg of the matrix
     * @param  bool  $memoryOnly  run only the in-memory SQLite runs of each variant (--memory-only)
     */
    public function __construct(
        public readonly string $php,
        public readonly int $laravel,
        public readonly bool $lowest,
        public readonly array $variants,
        public readonly array $engines,
        public readonly string $fileSchema = SCHEMA_FRESH,
        public readonly string $serverSchema = SCHEMA_FRESH,
        public readonly bool $scripts = true,
        public readonly bool $docker = false,
        public readonly bool $memoryOnly = false,
    ) {}

    public function id(): string
    {
        return 'php'.$this->php.'-laravel'.$this->laravel.($this->lowest ? '-lowest' : '');
    }

    /**
     * For database names: p84l12, p83l12lo.
     */
    public function short(): string
    {
        return 'p'.str_replace('.', '', $this->php).'l'.$this->laravel.($this->lowest ? 'lo' : '');
    }

    public function testbench(): string
    {
        return TESTBENCH[$this->laravel];
    }

    public function hasServerEngine(): bool
    {
        return array_intersect($this->engines, SERVER_ENGINES) !== [];
    }

    /**
     * The units of the leg, in the order of a serial run: one per variant,
     * and the scripts (or workbench) run. Each unit runs
     * after the leg's Composer step and test list, and its runs one after
     * another.
     *
     * @return list<Unit>
     */
    public function units(): array
    {
        $units = [];

        foreach ($this->variants as $variant) {
            $runs = [];

            if (in_array('sqlite', $this->engines, true)) {
                // In-memory SQLite has only the fresh mode.
                $runs[] = new RunSpec($variant, 'sqlite', 'memory', 'suite', [], []);
                $runs[] = new RunSpec($variant, 'sqlite', 'memory', 'groups', REQUIRED_GROUPS, [ENGINE_SKIP_GROUP]);
            }

            // Every leg runs the suite and the process groups on a file
            // SQLite database, also when --db selects only
            // server engines. The file suite runs the tests that skip in
            // memory and on the servers. No engine skip: only in-memory
            // SQLite may skip the shared-database members. --memory-only
            // (the quick CI tier) leaves them out.
            if (! $this->memoryOnly) {
                $runs = [...$runs, ...$this->fileAndServerRuns($variant)];
            }

            $units[] = new Unit($this, $variant->label(), Unit::suffix($variant), $runs);
        }

        if ($this->memoryOnly) {
            return $units;
        }

        $kind = $this->scripts ? 'scripts' : 'workbench';
        $units[] = new Unit($this, $kind, null, [new RunSpec(Variant::base(), 'sqlite', 'memory', $kind, [], [])]);

        return $units;
    }

    /**
     * The docker group needs the local orrery-testing project (CI has
     * service containers instead, bin/test --no-provision). Its tests skip
     * while a lease is live and otherwise stop the containers, so it runs
     * after every leg.
     */
    public function dockerRun(): ?RunSpec
    {
        return $this->docker ? new RunSpec(Variant::base(), 'sqlite', 'memory', 'docker', [DOCKER_GROUP], []) : null;
    }

    /**
     * Every run in the order of a serial run.
     *
     * @return list<RunSpec>
     */
    public function runs(): array
    {
        $runs = array_merge(...array_map(static fn (Unit $unit): array => $unit->runs, $this->units()));
        $docker = $this->dockerRun();

        return $docker === null ? $runs : [...$runs, $docker];
    }

    /**
     * @return list<RunSpec>
     */
    private function fileAndServerRuns(Variant $variant): array
    {
        $runs = [
            new RunSpec($variant, 'sqlite', 'file', 'suite', [], [], $this->fileSchema),
            new RunSpec($variant, 'sqlite', 'file', 'groups', REQUIRED_GROUPS, [], $this->fileSchema),
        ];

        foreach ($this->engines as $engine) {
            if ($engine !== 'sqlite') {
                $runs[] = new RunSpec($variant, $engine, 'server', 'suite', [], [], $this->serverSchema);
                $runs[] = new RunSpec($variant, $engine, 'server', 'groups', REQUIRED_GROUPS, [], $this->serverSchema);
            }
        }

        return $runs;
    }
}

/**
 * A run block of one leg: its runs run one after another.
 */
final class Unit
{
    /**
     * @param  string|null  $suffix  the suffix of the unit's base database name, null without a server run
     * @param  list<RunSpec>  $runs
     */
    public function __construct(
        public readonly Leg $leg,
        public readonly string $key,
        public readonly ?string $suffix,
        public readonly array $runs,
    ) {}

    /**
     * The database suffix of a variant: s(ame) or p (a separate pair), then
     * n(one) or p(refix).
     */
    public static function suffix(Variant $variant): string
    {
        return ($variant->mirror === 'same' ? 's' : 'p').($variant->prefix === '' ? 'n' : 'p');
    }

    public function hasServerRun(): bool
    {
        foreach ($this->runs as $run) {
            if ($run->storage === 'server') {
                return true;
            }
        }

        return false;
    }

    /**
     * The base database name of the unit's server runs: one per unit, so
     * two units that run at once never share a database.
     */
    public function database(string $runShort): ?string
    {
        return $this->suffix === null || ! $this->hasServerRun() ? null : 'orrery_m_'.$runShort.'_'.$this->leg->short().'_'.$this->suffix;
    }
}

final class Plan
{
    /**
     * @return list<Leg>
     */
    public static function legs(Options $options): array
    {
        $specs = [];

        if ($options->quick) {
            $specs = QUICK_LEGS;
        } elseif ($options->latest) {
            foreach ($options->php as $php) {
                foreach ($options->laravel as $laravel) {
                    $specs[] = [$php, $laravel, false];
                }
            }
        }

        if (! $options->quick && $options->lowest && in_array(LOWEST_PHP, $options->php, true)) {
            foreach ($options->laravel as $laravel) {
                $specs[] = [LOWEST_PHP, $laravel, true];
            }
        }

        if ($specs === []) {
            throw new UsageError('the selection has no leg (lowest legs run on PHP '.LOWEST_PHP.')');
        }

        if ($options->ci && count($specs) !== 1) {
            $ids = array_map(static fn (array $spec): string => (new Leg($spec[0], $spec[1], $spec[2], [], []))->id(), $specs);

            throw new UsageError(sprintf('--ci runs exactly one leg, but the selection has %d: %s', count($specs), implode(', ', $ids)));
        }

        // The scripts group: the first selected latest leg of each PHP,
        // preferring Laravel 13; the docker group: the same leg, else the
        // first lowest leg of the PHP.
        $scripts = [];
        $docker = [];

        foreach ($specs as $spec) {
            [$php, $laravel, $lowest] = $spec;

            if (! $lowest && (! isset($scripts[$php]) || ($laravel === SCRIPTS_PREFERRED_LARAVEL && $scripts[$php] !== SCRIPTS_PREFERRED_LARAVEL))) {
                $scripts[$php] = $laravel;
            }
        }

        foreach ($specs as $spec) {
            [$php, $laravel, $lowest] = $spec;
            $key = $php.'-'.$laravel.($lowest ? '-lowest' : '');

            if (! isset($docker[$php]) && ($lowest ? ! isset($scripts[$php]) : $scripts[$php] === $laravel)) {
                $docker[$php] = $key;
            }
        }

        $legs = [];

        foreach ($specs as [$php, $laravel, $lowest]) {
            $key = $php.'-'.$laravel.($lowest ? '-lowest' : '');
            $isVariantLeg = ! $lowest && $php === VARIANT_PHP && $laravel === VARIANT_LARAVEL;
            $variants = $isVariantLeg
                ? [Variant::base(), new Variant('separate', ''), new Variant('same', VARIANT_PREFIX), new Variant('separate', VARIANT_PREFIX)]
                : [Variant::base()];
            $selected = array_values(array_filter($variants, static fn (Variant $variant): bool => in_array($variant->mirror, $options->mirror, true) && in_array($variant->label(), $options->variants, true)));
            $id = (new Leg($php, $laravel, $lowest, [], []))->id();

            if ($selected === []) {
                throw new UsageError(sprintf(
                    '--mirror=%s --variants=%s leaves the leg %s without a variant: every leg runs same/none, and only php%s-laravel%d adds the other variants (select --php=%s --laravel=%d --no-lowest)',
                    implode(',', $options->mirror),
                    implode(',', $options->variants),
                    $id,
                    VARIANT_PHP,
                    VARIANT_LARAVEL,
                    VARIANT_PHP,
                    VARIANT_LARAVEL,
                ));
            }

            $serverFresh = $options->ci || $options->allFresh || $lowest || in_array($id, FRESH_LATEST, true);
            $fileFresh = $serverFresh || $isVariantLeg;

            $engines = $options->db;

            if (! $options->dbGiven && ! $options->ci && ! $options->memoryOnly && in_array($id, MARIADB_LEGS, true)) {
                $engines[] = 'mariadb';
            }

            $hasServer = array_intersect($engines, SERVER_ENGINES) !== [];
            $legs[] = new Leg(
                $php,
                $laravel,
                $lowest,
                $selected,
                $engines,
                $fileFresh ? SCHEMA_FRESH : SCHEMA_REUSE,
                $serverFresh ? SCHEMA_FRESH : SCHEMA_REUSE,
                $lowest || ($scripts[$php] ?? null) === $laravel,
                ! $options->ci && $hasServer && ($options->quick ? $id === QUICK_DOCKER_LEG : ($docker[$php] ?? null) === $key),
                $options->memoryOnly,
            );
        }

        return $legs;
    }
}

/**
 * Composer's version rules for the lowest legs.
 */
final class LockRules
{
    /**
     * Composer\Semver\VersionParser::parseStability().
     *
     * @return 'stable'|'RC'|'beta'|'alpha'|'dev'
     */
    public static function stability(string $version): string
    {
        $version = (string) preg_replace('{#.+$}', '', $version);

        if (str_starts_with($version, 'dev-') || str_ends_with($version, '-dev')) {
            return 'dev';
        }

        preg_match('{[._-]?(?:(stable|beta|b|RC|alpha|a|patch|pl|p)((?:[.-]?\d+)*+)?)?([.-]?dev)?(?:\+.*)?$}i', strtolower($version), $match);

        if (! empty($match[3])) {
            return 'dev';
        }

        if (! empty($match[1])) {
            if ($match[1] === 'beta' || $match[1] === 'b') {
                return 'beta';
            }

            if ($match[1] === 'alpha' || $match[1] === 'a') {
                return 'alpha';
            }

            if ($match[1] === 'rc') {
                return 'RC';
            }
        }

        return 'stable';
    }

    /**
     * 12.0.1 for v12.0.1, 12.0.1 and 12.0.1.0. Other values are returned unchanged.
     */
    public static function normalize(string $version): string
    {
        if (preg_match('/^[vV]?(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.0+)?$/D', trim($version), $match) !== 1) {
            return trim($version);
        }

        return sprintf('%d.%d.%d', (int) $match[1], (int) ($match[2] ?? 0), (int) ($match[3] ?? 0));
    }

    /**
     * The lowest Laravel version of $major that composer.json declares: the
     * highest lower bound among the laravel/framework and illuminate/*
     * constraints for that major.
     */
    public static function floor(array $composerJson, int $major): ?string
    {
        $floor = null;

        foreach (($composerJson['require'] ?? []) as $package => $constraint) {
            if (! is_string($package) || ($package !== 'laravel/framework' && ! str_starts_with($package, 'illuminate/')) || ! is_string($constraint)) {
                continue;
            }

            foreach (preg_split('/\s*\|\|?\s*/', trim($constraint)) ?: [] as $alternative) {
                if (preg_match('/^(?:\^|~|>=|==?)?\s*v?(\d+)(?:\.(\d+))?(?:\.(\d+))?$/D', $alternative, $match) !== 1 || (int) $match[1] !== $major) {
                    continue;
                }

                $bound = sprintf('%d.%d.%d', (int) $match[1], (int) ($match[2] ?? 0), (int) ($match[3] ?? 0));

                if ($floor === null || version_compare($bound, $floor, '>')) {
                    $floor = $bound;
                }
            }
        }

        return $floor;
    }

    /**
     * @return array<string, string> package => version
     */
    public static function packages(array $lock): array
    {
        $packages = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach (($lock[$section] ?? []) as $package) {
                if (is_array($package) && isset($package['name'], $package['version'])) {
                    $packages[(string) $package['name']] = (string) $package['version'];
                }
            }
        }

        return $packages;
    }

    /**
     * @return array{php: string|null, framework: string|null, testbench: string|null, testbench_core: string|null, phpunit: string|null}
     */
    public static function versions(array $lock): array
    {
        $packages = self::packages($lock);

        return [
            'php' => null,
            'framework' => $packages['laravel/framework'] ?? $packages['illuminate/support'] ?? null,
            'testbench' => $packages['orchestra/testbench'] ?? null,
            'testbench_core' => $packages['orchestra/testbench-core'] ?? null,
            'phpunit' => $packages['phpunit/phpunit'] ?? null,
        ];
    }

    /**
     * Every leg: the framework is installed, and its major is the leg's.
     *
     * @return list<string>
     */
    public static function checkFramework(array $lock, int $major): array
    {
        $framework = self::versions($lock)['framework'];

        if ($framework === null) {
            return ['the lock has no laravel/framework and no illuminate/support'];
        }

        $normalized = self::normalize($framework);

        if (preg_match('/^(\d+)\./', $normalized, $match) !== 1 || (int) $match[1] !== $major) {
            return ["the lock has laravel/framework {$framework}, not Laravel {$major}"];
        }

        return [];
    }

    /**
     * The two rules of a lowest leg: laravel/framework is exactly the floor
     * of composer.json, and every package of the lock is a stable release.
     * Each package's version field is read with Composer's stability rules,
     * so a stable release that has a branch alias in its metadata passes.
     *
     * @return list<string>
     */
    public static function checkLowest(array $lock, array $composerJson, int $major): array
    {
        $reasons = [];
        $framework = self::versions($lock)['framework'];
        $floor = self::floor($composerJson, $major);

        if ($floor === null) {
            $reasons[] = "composer.json declares no Laravel {$major} floor in its laravel/framework or illuminate/* constraints";
        } elseif ($framework === null || self::normalize($framework) !== $floor) {
            $reasons[] = sprintf('the lowest leg resolved laravel/framework %s, not the floor %s that composer.json declares', $framework ?? '(none)', $floor);
        }

        foreach (self::packages($lock) as $package => $version) {
            $stability = self::stability($version);

            if ($stability !== 'stable') {
                $reasons[] = "the lowest leg resolved {$package} {$version}, which is not a stable release ({$stability})";
            }
        }

        return $reasons;
    }
}

final class Snapshot
{
    /**
     * @return list<string> the relative paths, sorted
     */
    public static function files(string $source): array
    {
        $result = Process::capture(['git', '-C', $source, 'ls-files', '--cached', '--others', '--exclude-standard', '-z']);

        if ($result['exit'] !== 0) {
            throw new \RuntimeException("git ls-files failed in {$source}: ".trim($result['stderr']));
        }

        $files = [];

        foreach (explode("\0", $result['stdout']) as $path) {
            if ($path === '' || $path === '.matrix' || str_starts_with($path, '.matrix/')) {
                continue;
            }

            if (str_ends_with($path, '/')) {
                throw new \RuntimeException("cannot take a snapshot of {$path}: it is a nested repository");
            }

            $files[$path] = true;
        }

        $files = array_keys($files);
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * The paths that exist in $dir, as a file (f), an executable file (x) or
     * a symbolic link (l). A path that git lists but that the working tree
     * has deleted is not in the snapshot.
     *
     * @param  list<string>  $paths
     * @return array<string, string>
     */
    public static function entries(string $dir, array $paths): array
    {
        $entries = [];

        foreach ($paths as $path) {
            $full = $dir.'/'.$path;

            if (is_link($full)) {
                $entries[$path] = 'l';
            } elseif (is_dir($full)) {
                throw new \RuntimeException("cannot take a snapshot of {$path}: it is a directory (a submodule or a nested repository)");
            } elseif (is_file($full)) {
                $entries[$path] = is_executable($full) ? 'x' : 'f';
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, string>  $entries
     */
    public static function hash(string $dir, array $entries): string
    {
        $context = hash_init('sha256');

        foreach ($entries as $path => $type) {
            $full = $dir.'/'.$path;
            $content = $type === 'l' ? (string) readlink($full) : hash_file('sha256', $full);

            if ($content === false) {
                throw new \RuntimeException("cannot read {$full}");
            }

            hash_update($context, $path."\0".$type."\0".$content."\n");
        }

        return hash_final($context);
    }

    /**
     * @param  array<string, string>  $entries
     */
    public static function copy(string $from, string $to, array $entries): void
    {
        if (file_exists($to)) {
            throw new \RuntimeException("{$to} exists already");
        }

        mkdir($to, 0755, true);

        foreach ($entries as $path => $type) {
            $source = $from.'/'.$path;
            $target = $to.'/'.$path;

            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }

            if ($type === 'l') {
                if (! symlink((string) readlink($source), $target)) {
                    throw new \RuntimeException("cannot copy the link {$path}");
                }

                continue;
            }

            if (! copy($source, $target)) {
                throw new \RuntimeException("cannot copy {$path}");
            }

            chmod($target, $type === 'x' ? 0755 : 0644);
        }
    }

    /**
     * @return array{head: string, dirty: bool|null}
     */
    public static function head(string $source): array
    {
        $head = Process::capture(['git', '-C', $source, 'rev-parse', '--verify', '--quiet', 'HEAD']);
        // --no-optional-locks: status does not write the index of the checkout.
        $status = Process::capture(['git', '--no-optional-locks', '-C', $source, 'status', '--porcelain', '--untracked-files=normal']);

        return [
            'head' => $head['exit'] === 0 ? trim($head['stdout']) : 'none',
            'dirty' => $status['exit'] === 0 ? trim($status['stdout']) !== '' : null,
        ];
    }
}

final class Process
{
    /**
     * A short command whose output this program reads.
     *
     * @param  list<string>  $command
     * @param  array<string, string>|null  $env
     * @return array{exit: int, stdout: string, stderr: string}
     */
    public static function capture(array $command, ?string $cwd = null, ?array $env = null, int $timeout = 120): array
    {
        $pipes = [];
        $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);

        if (! is_resource($process)) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => 'could not start '.$command[0]];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = ['', ''];
        $deadline = microtime(true) + $timeout;
        $exit = null;

        while (true) {
            $output[0] .= (string) stream_get_contents($pipes[1]);
            $output[1] .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);

            if (! $status['running']) {
                $exit = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];

                break;
            }

            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                $exit = 124;

                break;
            }

            usleep(10_000);
        }

        $output[0] .= (string) stream_get_contents($pipes[1]);
        $output[1] .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return ['exit' => (int) $exit, 'stdout' => $output[0], 'stderr' => $output[1]];
    }
}

/**
 * A long command of a leg: Composer, the test list or a bin/test run.
 */
final class Command
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    public function __construct(
        public readonly array $command,
        public readonly string $cwd,
        public readonly array $env,
        public readonly string $log,
        public readonly int $timeout,
    ) {}
}

/**
 * A running long command: stdout and stderr go to its log. On its timeout it
 * gets SIGTERM (bin/test then stops its own processes), and SIGKILL 60
 * seconds later.
 */
final class Child
{
    /** @var array<int, self> the child that runs now (at most one), for the signal handler */
    public static array $running = [];

    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $handle = null;

    private float $started;

    private ?float $killAt = null;

    private bool $timedOut = false;

    /** @var array{exit: int, seconds: float, timed_out: bool}|null */
    private ?array $result = null;

    private function __construct(private readonly Command $command)
    {
        $this->started = microtime(true);
    }

    public static function start(Command $command): self
    {
        $child = new self($command);
        $handle = fopen($command->log, 'a');

        if ($handle === false) {
            throw new \RuntimeException("cannot write {$command->log}");
        }

        fwrite($handle, '$ '.implode(' ', array_map('escapeshellarg', $command->command))."\n");
        fflush($handle);
        $pipes = [];
        $process = @proc_open($command->command, [0 => ['file', '/dev/null', 'r'], 1 => $handle, 2 => $handle], $pipes, $command->cwd, $command->env);

        if (! is_resource($process)) {
            fwrite($handle, "could not start {$command->command[0]}\n");
            fclose($handle);
            $child->result = ['exit' => 127, 'seconds' => 0.0, 'timed_out' => false];

            return $child;
        }

        $child->process = $process;
        $child->handle = $handle;
        self::$running[spl_object_id($child)] = $child;

        return $child;
    }

    /**
     * Polls the child once: applies its timeout, and collects its exit.
     */
    public function finished(): bool
    {
        if ($this->result !== null) {
            return true;
        }

        $status = proc_get_status($this->process);

        if (! $status['running']) {
            $exit = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
            proc_close($this->process);
            fclose($this->handle);
            unset(self::$running[spl_object_id($this)]);
            $this->result = ['exit' => (int) $exit, 'seconds' => microtime(true) - $this->started, 'timed_out' => $this->timedOut];

            return true;
        }

        $now = microtime(true);

        if (! $this->timedOut && $now > $this->started + $this->command->timeout) {
            $this->timedOut = true;
            fwrite($this->handle, "\nbin/matrix: the command ran longer than {$this->command->timeout} seconds; sending SIGTERM\n");
            proc_terminate($this->process, 15);
            $this->killAt = $now + 60;
        } elseif ($this->killAt !== null && $now > $this->killAt) {
            proc_terminate($this->process, 9);
            $this->killAt = null;
        }

        return false;
    }

    /**
     * @return array{exit: int, seconds: float, timed_out: bool}
     */
    public function result(): array
    {
        if ($this->result === null) {
            throw new \LogicException('the child still runs');
        }

        return $this->result;
    }

    public function terminate(int $signal): void
    {
        if ($this->result === null && is_resource($this->process)) {
            proc_terminate($this->process, $signal);
        }
    }

    /**
     * Waits for the end of the child (its timeout still applies).
     *
     * @return array{exit: int, seconds: float, timed_out: bool}
     */
    public function wait(): array
    {
        while (! $this->finished()) {
            usleep(100_000);
        }

        return $this->result();
    }

    /**
     * After an internal error: SIGTERM, so bin/test stops its processes and
     * releases its lease, then SIGKILL when it has not ended after $grace
     * seconds.
     */
    public function stop(float $grace): void
    {
        $this->terminate(15);
        $deadline = microtime(true) + $grace;

        while (! $this->finished()) {
            if (microtime(true) > $deadline) {
                $this->terminate(9);
                $deadline = PHP_FLOAT_MAX;
            }

            usleep(100_000);
        }
    }
}

final class RunResult
{
    /**
     * @param  list<string>  $reasons  reasons outside the known-failure check
     * @param  array<string, mixed>|null  $identity
     */
    public function __construct(
        public readonly RunSpec $spec,
        public readonly int $exit,
        public readonly float $seconds,
        public readonly ?CheckResult $check,
        public readonly array $reasons,
        public readonly ?array $identity,
        public readonly ?string $timedOutTest = null,
        public readonly ?string $database = null,
    ) {}

    /**
     * @return list<string>
     */
    public function allReasons(): array
    {
        return [...$this->reasons, ...($this->check?->reasons ?? [])];
    }

    public function verdict(): string
    {
        if ($this->allReasons() !== []) {
            return 'fail';
        }

        return $this->check?->verdict() ?? 'fail';
    }
}

final class LegResult
{
    /** @var list<string> */
    public array $reasons = [];

    /** @var list<RunResult> */
    public array $runs = [];

    /** @var array<string, string|null> */
    public array $versions = ['php' => null, 'framework' => null, 'testbench' => null, 'testbench_core' => null, 'phpunit' => null];

    public ?string $hash = null;

    public float $seconds = 0.0;

    public bool $interrupted = false;

    /** The inputs of the runs, after the leg's preparation succeeded. */
    public ?Prepared $prepared = null;

    /** The seconds of the leg's docker run, which runs after every leg; part of $seconds. */
    public float $dockerSeconds = 0.0;

    /**
     * @param  array<string, string|null>  $databases  unit key => base database name (null without a server run)
     */
    public function __construct(public readonly Leg $leg, public readonly array $databases) {}

    public function verdict(): string
    {
        // A leg without a run never passes, whatever stopped it.
        if ($this->reasons !== [] || $this->interrupted || $this->runs === []) {
            return 'fail';
        }

        $listed = false;

        foreach ($this->runs as $run) {
            $verdict = $run->verdict();

            if ($verdict === 'fail') {
                return 'fail';
            }

            $listed = $listed || $verdict === 'pass-listed';
        }

        return $listed ? 'pass-listed' : 'pass';
    }

    /**
     * @return array{tests: int, assertions: int, listed: int}
     */
    public function totals(): array
    {
        $totals = ['tests' => 0, 'assertions' => 0, 'listed' => 0];

        foreach ($this->runs as $run) {
            $totals['tests'] += $run->check?->counts['tests'] ?? 0;
            $totals['assertions'] += $run->check?->counts['assertions'] ?? 0;
            $totals['listed'] += count($run->check?->listed ?? []);
        }

        return $totals;
    }
}

/**
 * What a leg's preparation gives its units.
 */
final class Prepared
{
    /**
     * @param  list<string>  $configExcludes
     */
    public function __construct(
        public readonly string $dir,
        public readonly string $php,
        public readonly string $version,
        public readonly array $configExcludes,
        public readonly Inventory $inventory,
        public readonly Manifest $manifest,
        public readonly Unconditional $unconditional,
        public readonly string $logs,
    ) {}
}

final class Runner
{
    private bool $interrupted = false;

    private int $signal = 0;

    /** @var array<string, string> */
    private array $baseEnv;

    private string $runDir;

    private string $runId;

    /** @var array{head: string, dirty: bool|null, hash: string, files: int, source: string} */
    private array $snapshot;

    /** @var array<string, string> */
    private array $binaries = [];

    private string $composer;

    /** @var array{database: string, token: string}|null */
    private ?array $ci = null;

    /** @var resource */
    private $out;

    /** The heavy-run slot while this run holds it. */
    private ?Slot $slot = null;

    /**
     * @param  list<string>  $argv  the full argv, for the summary
     * @param  array<string, string>  $env  the inherited environment
     */
    public function __construct(
        private readonly Options $options,
        private readonly string $source,
        private readonly string $outBase,
        private readonly array $env,
        private readonly array $argv,
        private readonly ?string $fakeBinTest,
        $out = null,
    ) {
        $this->out = $out ?? STDOUT;
        $this->baseEnv = self::baseEnv($env, $fakeBinTest !== null);
    }

    /**
     * The environment of every child: the inherited one without the
     * variables that could point a run at another database, registry or
     * Composer project, and without COMPOSER_NO_BLOCKING, which only the
     * Composer calls of a lowest leg get.
     *
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    public static function baseEnv(array $env, bool $testMode): array
    {
        $keep = ['ORRERY_OWNERS', 'ORRERY_GROUP_TOKEN'];
        $result = [];

        foreach ($env as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                continue;
            }

            if (in_array($name, ['COMPOSER_NO_BLOCKING', 'COMPOSER', 'COMPOSER_VENDOR_DIR', 'COMPOSER_BIN_DIR', 'DATABASE_URL'], true)) {
                continue;
            }

            if ((str_starts_with($name, 'DB_') || str_starts_with($name, 'ORRERY_')) && ! in_array($name, $keep, true)) {
                if (! ($testMode && str_starts_with($name, 'ORRERY_MATRIX_'))) {
                    continue;
                }
            }

            $result[$name] = $value;
        }

        $result['XDEBUG_MODE'] = 'off';

        return $result;
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    public static function composerEnv(array $baseEnv, Leg $leg): array
    {
        $env = array_replace($baseEnv, ['COMPOSER_NO_INTERACTION' => '1']);

        if ($leg->lowest) {
            // Composer blocks releases with security advisories by default,
            // so --prefer-lowest would not reach the declared floors.
            $env['COMPOSER_NO_BLOCKING'] = '1';
        }

        return $env;
    }

    public function resolveTools(): void
    {
        $versions = [];

        foreach (Plan::legs($this->options) as $leg) {
            $versions[$leg->php] = true;
        }

        foreach (array_keys($versions) as $version) {
            $this->binaries[$version] = self::phpBinary((string) $version, $this->options->phpBinaries, $this->env);
        }

        $this->composer = self::composerPath($this->options->composer, $this->env);

        if ($this->options->ci) {
            if (($this->env['CI'] ?? '') !== 'true') {
                throw new UsageError('--ci changes composer.json and vendor/ of this checkout in place, so it runs only where CI=true (GitHub Actions sets it)');
            }

            $needsServer = array_intersect($this->options->db, SERVER_ENGINES) !== [];

            if ($needsServer) {
                $database = $this->env['ORRERY_TEST_DATABASE'] ?? '';
                $token = $this->env['ORRERY_TEST_TOKEN'] ?? '';

                if (preg_match('/^orrery_[a-z0-9_]{1,40}$/D', $database) !== 1 || str_ends_with($database, '_mirror') || $database === 'orrery_marker') {
                    throw new UsageError('--ci with a server engine needs ORRERY_TEST_DATABASE: the provisioned base name (^orrery_[a-z0-9_]{1,40}$)');
                }

                if (preg_match('/^[0-9a-f]{32}$/D', $token) !== 1) {
                    throw new UsageError('--ci with a server engine needs ORRERY_TEST_TOKEN: the 32-character hex token of the provisioned markers');
                }

                $this->ci = ['database' => $database, 'token' => $token];
            }
        }
    }

    /**
     * --php-binary, then $ORRERY_PHP_<major>_<minor>, then Homebrew.
     *
     * @param  array<string, string>  $explicit
     * @param  array<string, string>  $env
     */
    public static function phpBinary(string $version, array $explicit, array $env): string
    {
        $variable = 'ORRERY_PHP_'.str_replace('.', '_', $version);
        $candidates = isset($explicit[$version])
            ? [$explicit[$version]]
            : (isset($env[$variable]) && $env[$variable] !== ''
                ? [$env[$variable]]
                : ["/opt/homebrew/opt/php@{$version}/bin/php", "/usr/local/opt/php@{$version}/bin/php"]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new UsageError(sprintf(
            'no PHP %s binary: %s. Pass --php-binary=%s=<path> or set %s',
            $version,
            implode(', ', array_map(static fn (string $path): string => "{$path} is not an executable file", $candidates)),
            $version,
            $variable,
        ));
    }

    /**
     * --composer, else composer on PATH. It runs as `<leg PHP> <composer>`,
     * so it must be a PHP script or a phar, not a shell wrapper.
     *
     * @param  array<string, string>  $env
     */
    public static function composerPath(?string $option, array $env): string
    {
        $path = $option;

        if ($path === null) {
            foreach (explode(':', $env['PATH'] ?? '') as $dir) {
                if ($dir !== '' && is_file($dir.'/composer') && is_executable($dir.'/composer')) {
                    $path = $dir.'/composer';

                    break;
                }
            }
        }

        if ($path === null || ! is_file($path)) {
            throw new UsageError('no Composer: pass --composer=<path of composer or composer.phar>, or put composer on PATH');
        }

        $head = (string) file_get_contents($path, false, null, 0, 256);
        $firstLine = strtok($head, "\n") ?: '';

        if (str_starts_with($firstLine, '#!') && ! str_contains($firstLine, 'php')) {
            throw new UsageError("{$path} is a shell script; bin/matrix runs Composer with each leg's PHP binary, so pass --composer=<composer.phar>");
        }

        return realpath($path) ?: $path;
    }

    public function dryRun(): int
    {
        $this->resolveTools();
        $legs = Plan::legs($this->options);
        $units = array_sum(array_map(static fn (Leg $leg): int => count($leg->units()), $legs));
        $this->say(sprintf('bin/matrix --dry-run: %d legs, %d units, from %s%s', count($legs), $units, $this->source, $this->options->ci ? ' (in place, --ci)' : ''));
        $this->say('Composer: '.$this->composer);

        foreach ($legs as $leg) {
            $this->say('');
            $this->say(sprintf('%s: PHP %s (%s), Laravel %d, %s, orchestra/testbench:%s', $leg->id(), $leg->php, $this->binaries[$leg->php], $leg->laravel, $leg->lowest ? 'lowest (--prefer-lowest --prefer-stable, COMPOSER_NO_BLOCKING=1)' : 'latest', $leg->testbench()));
            $this->say('  variants: '.implode(', ', array_map(static fn (Variant $variant): string => $variant->label(), $leg->variants)).'; engines: '.implode(', ', $leg->engines).'; file runs: --schema='.$leg->fileSchema.'; server runs: --schema='.$leg->serverSchema);

            foreach ($leg->units() as $unit) {
                $database = $unit->hasServerRun() ? ($this->ci['database'] ?? $unit->database('<run>')) : null;
                $this->say('  unit '.$unit->key.($database !== null && $unit->hasServerRun() ? " (database {$database})" : '').':');

                foreach ($unit->runs as $run) {
                    $this->say('  - '.$run->label());
                }
            }

            $docker = $leg->dockerRun();

            if ($docker !== null) {
                $this->say('  alone, after every leg:');
                $this->say('  - '.$docker->label());
            }
        }

        return 0;
    }

    public function run(): int
    {
        $this->resolveTools();
        $legs = Plan::legs($this->options);
        $this->installSignalHandlers();

        $random = bin2hex(random_bytes(3));
        $this->runId = $this->options->runId ?? gmdate('Ymd\THis\Z').'-'.$random;
        $short = $this->options->runId === null ? $random : substr(hash('sha256', $this->runId), 0, 6);
        $this->runDir = $this->outBase.'/'.$this->runId;

        if (file_exists($this->runDir)) {
            throw new UsageError("{$this->runDir} exists already; choose another --run-id");
        }

        $refused = $this->takeSlot();

        if ($refused !== null) {
            return $refused;
        }

        mkdir($this->runDir.'/logs', 0755, true);
        $started = microtime(true);
        $this->say("bin/matrix run {$this->runId}: ".count($legs).' legs, output in '.$this->runDir);

        try {
            $this->takeSnapshot();
        } catch (\RuntimeException $failure) {
            $this->say('bin/matrix: the snapshot failed: '.$failure->getMessage());
            file_put_contents($this->runDir.'/summary.md', "# bin/matrix run {$this->runId}\n\nThe snapshot failed: {$failure->getMessage()}\n");

            return 1;
        }

        $results = [];

        foreach ($legs as $leg) {
            $databases = [];

            foreach ($leg->units() as $unit) {
                $databases[$unit->key] = $unit->hasServerRun() ? ($this->ci['database'] ?? $unit->database($short)) : null;
            }

            $results[] = new LegResult($leg, $databases);
        }

        // The leg whose work runs now, for an internal error.
        $current = null;

        try {
            // 1. Each leg in turn: its preparation, then its units.
            foreach ($results as $result) {
                $current = $result;
                $legStarted = microtime(true);
                $this->runLeg($result);
                $result->seconds = microtime(true) - $legStarted;
                $this->writeLeg($result);
            }

            // 2. The docker runs, after every leg: the docker tests skip
            // while a lease is live and otherwise stop the containers.
            foreach ($results as $result) {
                $spec = $result->leg->dockerRun();

                if ($spec === null || $result->prepared === null) {
                    continue;
                }

                if ($this->interrupted) {
                    $result->interrupted = true;
                    $result->reasons[] = 'interrupted before '.$spec->label();
                    $this->writeLeg($result);

                    continue;
                }

                $current = $result;
                $run = $this->runOne($result->leg, $spec, $result->prepared, null);
                $result->runs[] = $run;
                $this->report($result->leg, $run);
                // Its own interval: the time between the leg's units and its
                // docker run belongs to the other legs.
                $result->dockerSeconds = $run->seconds;
                $result->seconds += $run->seconds;
                $this->writeLeg($result);
            }

            $current = null;
        } catch (\Throwable $failure) {
            // An internal error: stop the running bin/test (it releases its
            // lease), write what is known, and let the error reach PHP.
            foreach (Child::$running as $child) {
                $child->stop(70);
            }

            $error = $failure::class.': '.$failure->getMessage();
            $this->say('bin/matrix: internal error: '.$error);

            // No leg that the error stopped, or that did not run, may pass.
            if ($current !== null) {
                $current->reasons[] = 'stopped by an internal error: '.$error;
            }

            // Also an earlier leg: its docker run comes after every leg.
            foreach ($results as $result) {
                if ($result === $current || $result->reasons !== []) {
                    continue;
                }

                $recorded = array_map(static fn (RunResult $run): string => $run->spec->name(), $result->runs);
                $missing = array_values(array_filter($result->leg->runs(), static fn (RunSpec $spec): bool => ! in_array($spec->name(), $recorded, true)));

                if ($missing !== []) {
                    $result->reasons[] = $result->runs === []
                        ? 'not run: bin/matrix stopped with an internal error'
                        : 'not run: '.$missing[0]->label().' (bin/matrix stopped with an internal error)';
                }
            }

            $summary = new Summary($this->runId, $this->snapshot, $results, $this->argv, microtime(true) - $started, $this->interrupted, $error);
            file_put_contents($this->runDir.'/summary.md', $summary->markdown());
            file_put_contents($this->runDir.'/summary.json', json_encode($summary->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n");

            throw $failure;
        }

        foreach ($results as $result) {
            $this->say(sprintf('[%s] %s (%s)', $result->leg->id(), Summary::verdictLabel($result->verdict(), $result->totals()['listed']), Summary::duration($result->seconds)));
        }

        $summary = new Summary($this->runId, $this->snapshot, $results, $this->argv, microtime(true) - $started, $this->interrupted);
        $markdown = $summary->markdown();
        file_put_contents($this->runDir.'/summary.md', $markdown);
        file_put_contents($this->runDir.'/summary.json', json_encode($summary->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
        $this->say('');
        $this->say($markdown);

        if ($this->interrupted) {
            return 128 + $this->signal;
        }

        foreach ($results as $result) {
            if ($result->verdict() === 'fail') {
                return 1;
            }
        }

        return 0;
    }

    /**
     * A leg: its preparation, then its units, one run after another. After
     * an interruption it records the first run that did not start.
     */
    private function runLeg(LegResult $result): void
    {
        $first = $result->leg->runs()[0];

        if ($this->interrupted) {
            $result->interrupted = true;
            $result->reasons[] = 'interrupted before the preparation of the leg (its first run: '.$first->label().')';

            return;
        }

        if (! $this->prepare($result)) {
            if ($this->interrupted) {
                $result->interrupted = true;
                $result->reasons[] = 'interrupted before '.$first->label().' (during the preparation of the leg)';
            }

            return;
        }

        foreach ($result->leg->units() as $unit) {
            if (! $this->runUnit($result, $unit->runs, $result->databases[$unit->key])) {
                return;
            }
        }
    }

    private function writeLeg(LegResult $result): void
    {
        if (is_dir($this->logDir($result->leg))) {
            file_put_contents($this->logDir($result->leg).'/leg.json', json_encode(Summary::legArray($result), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
        }
    }

    /**
     * Runs a long command to its end. The test mode can raise an internal
     * error while a given run runs (ORRERY_MATRIX_TEST_THROW=<leg>/<run>).
     *
     * @return array{exit: int, seconds: float, timed_out: bool}
     */
    private function execute(Command $command): array
    {
        // After a signal nothing new starts, also inside a leg's preparation.
        if ($this->interrupted) {
            return ['exit' => 128 + $this->signal, 'seconds' => 0.0, 'timed_out' => false];
        }

        $child = Child::start($command);
        $throwAt = $this->fakeBinTest !== null ? ($this->env['ORRERY_MATRIX_TEST_THROW'] ?? '') : '';

        if ($throwAt !== '' && ($command->env['ORRERY_MATRIX_RUN'] ?? null) === $throwAt) {
            usleep(500_000);

            // Not a RuntimeException: prepare() catches those.
            throw new \LogicException("test: an internal error while {$throwAt} runs");
        }

        return $child->wait();
    }

    private function report(Leg $leg, RunResult $run): void
    {
        $counts = $run->check?->counts;
        $this->say(sprintf(
            '[%s] %s: %s, exit %d, %s (%s)',
            $leg->id(),
            $run->spec->label(),
            Summary::verdictLabel($run->verdict(), count($run->check?->listed ?? [])),
            $run->exit,
            $counts === null ? 'no JUnit file' : sprintf('%d tests, %d skipped', $counts['tests'], $counts['skipped']),
            Summary::duration($run->seconds),
        ));

        foreach ($run->allReasons() as $reason) {
            $this->say('    '.$reason);
        }
    }

    /**
     * Takes the heavy-run slot (not with --ci), and gives its token to every
     * bin/test run. The fixture tests take it only with a test registry
     * (ORRERY_REGISTRY_TEST=1), never in the real registry.
     *
     * @return int|null the exit status when the run cannot start, else null
     */
    private function takeSlot(): ?int
    {
        if ($this->options->ci || ($this->fakeBinTest !== null && ($this->env['ORRERY_REGISTRY_TEST'] ?? '') !== '1')) {
            return null;
        }

        $every = 60.0;
        $override = $this->fakeBinTest !== null ? ($this->env['ORRERY_TEST_SLOT_REPORT_SECONDS'] ?? '') : '';

        if (is_numeric($override) && (float) $override > 0) {
            $every = (float) $override;
        }

        $inherited = $this->env['ORRERY_SLOT_TOKEN'] ?? '';

        try {
            $this->slot = Slot::acquire(
                Registry::resolve(),
                ['run_id' => $this->runId, 'pid' => getmypid(), 'checkout' => $this->source, 'command' => 'bin/matrix '.implode(' ', array_slice($this->argv, 1))],
                $this->options->slotWait,
                $inherited !== '' ? $inherited : null,
                function (string $line): void {
                    $this->say('bin/matrix: '.$line);
                },
                function (float $seconds): bool {
                    usleep((int) ($seconds * 1_000_000));

                    return $this->interrupted;
                },
                $every,
            );
        } catch (Failure $failure) {
            $this->say('bin/matrix: '.$failure->getMessage());

            if ($this->interrupted) {
                return 128 + $this->signal;
            }

            return $failure->exitCode === \OrreryTestDb\EXIT_SLOT ? EXIT_SLOT : 1;
        }

        $this->baseEnv['ORRERY_SLOT_TOKEN'] = $this->slot->token;

        return null;
    }

    private function takeSnapshot(): void
    {
        $paths = Snapshot::files($this->source);
        $entries = Snapshot::entries($this->source, $paths);
        $head = Snapshot::head($this->source);

        if ($this->options->ci) {
            $hash = Snapshot::hash($this->source, $entries);
        } else {
            Snapshot::copy($this->source, $this->runDir.'/snapshot', $entries);
            $hash = Snapshot::hash($this->runDir.'/snapshot', $entries);
        }

        $this->snapshot = ['head' => $head['head'], 'dirty' => $head['dirty'], 'hash' => $hash, 'files' => count($entries), 'source' => $this->source];
        file_put_contents($this->runDir.'/snapshot.json', json_encode($this->snapshot + ['entries' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->say(sprintf('snapshot %s: HEAD %s, %d files%s', substr($hash, 0, 12), $head['head'], count($entries), $head['dirty'] === true ? ', with uncommitted changes' : ''));
    }

    private function logDir(Leg $leg): string
    {
        return $this->runDir.'/logs/'.$leg->id();
    }

    private function legDir(Leg $leg): string
    {
        return $this->options->ci ? $this->source : $this->runDir.'/'.$leg->id();
    }

    /**
     * A leg's preparation: the copy, the PHP binary, Composer and the test
     * list. It returns false when the leg's units cannot run.
     */
    private function prepare(LegResult $result): bool
    {
        $leg = $result->leg;
        $dir = $this->legDir($leg);
        $logs = $this->logDir($leg);
        mkdir($logs, 0755, true);
        $php = $this->binaries[$leg->php];

        // 1. The copy.
        try {
            if (! $this->options->ci) {
                $entries = json_decode((string) file_get_contents($this->runDir.'/snapshot.json'), true)['entries'];
                Snapshot::copy($this->runDir.'/snapshot', $dir, $entries);
                $result->hash = Snapshot::hash($dir, $entries);

                if ($result->hash !== $this->snapshot['hash']) {
                    $result->reasons[] = "the copy's hash {$result->hash} is not the snapshot hash {$this->snapshot['hash']}";

                    return false;
                }
            } else {
                $result->hash = $this->snapshot['hash'];
            }
        } catch (\RuntimeException $failure) {
            $result->reasons[] = 'the copy failed: '.$failure->getMessage();

            return false;
        }

        // 2. The PHP binary.
        $probe = Process::capture([$php, '-n', '-r', 'echo json_encode(["version" => PHP_VERSION, "binary" => PHP_BINARY]);'], $dir, $this->baseEnv);
        $probed = json_decode($probe['stdout'], true);
        file_put_contents($logs.'/php.json', $probe['stdout']."\n");

        if ($probe['exit'] !== 0 || ! is_array($probed) || ! is_string($probed['version'] ?? null)) {
            $result->reasons[] = "{$php} did not report its version (exit {$probe['exit']}): ".trim($probe['stderr']);

            return false;
        }

        $result->versions['php'] = $probed['version'];

        if (! str_starts_with($probed['version'], $leg->php.'.')) {
            $result->reasons[] = "{$php} is PHP {$probed['version']}, not PHP {$leg->php}";

            return false;
        }

        $this->say(sprintf('[%s] PHP %s (%s)', $leg->id(), $probed['version'], $php));

        // 3. Composer.
        $composerEnv = self::composerEnv($this->baseEnv, $leg);
        $steps = [
            ['require', '--dev', 'orchestra/testbench:'.$leg->testbench(), '--no-update', '--no-interaction', '--working-dir='.$dir],
            ['update', '--no-interaction', '--no-progress', '--working-dir='.$dir, ...($leg->lowest ? ['--prefer-lowest', '--prefer-stable'] : [])],
        ];

        foreach ($steps as $step) {
            $this->say(sprintf('[%s] composer %s ...', $leg->id(), implode(' ', array_slice($step, 0, 3))));
            $composer = $this->execute(new Command([$php, $this->composer, ...$step], $dir, $composerEnv, $logs.'/composer.log', COMPOSER_TIMEOUT));

            if ($composer['exit'] !== 0) {
                $result->reasons[] = sprintf('composer %s failed (exit %d%s); see %s', $step[0], $composer['exit'], $composer['timed_out'] ? ', timed out' : '', $logs.'/composer.log');

                return false;
            }
        }

        $lock = json_decode((string) @file_get_contents($dir.'/composer.lock'), true);
        $composerJson = json_decode((string) @file_get_contents($dir.'/composer.json'), true);

        if (! is_array($lock) || ! is_array($composerJson)) {
            $result->reasons[] = 'composer update wrote no readable composer.lock';

            return false;
        }

        $versions = LockRules::versions($lock);
        $versions['php'] = $probed['version'];
        $result->versions = $versions;
        file_put_contents($logs.'/versions.json', json_encode($result->versions, JSON_PRETTY_PRINT)."\n");
        $this->say(sprintf(
            '[%s] laravel/framework %s, orchestra/testbench %s, testbench-core %s, phpunit/phpunit %s',
            $leg->id(),
            $versions['framework'] ?? '(none)',
            $versions['testbench'] ?? '(none)',
            $versions['testbench_core'] ?? '(none)',
            $versions['phpunit'] ?? '(none)',
        ));

        $lockReasons = LockRules::checkFramework($lock, $leg->laravel);

        if ($leg->lowest) {
            // The floor comes from `require`. The `require --dev` above
            // changed only the Testbench constraint in `require-dev`.
            $lockReasons = [...$lockReasons, ...LockRules::checkLowest($lock, $composerJson, $leg->laravel)];
        }

        // A wrong resolution fails the leg, but the dependencies are
        // installed, so the runs still show what passes on them.
        foreach ($lockReasons as $reason) {
            $result->reasons[] = $reason;
            $this->say("[{$leg->id()}] FAIL: {$reason}");
        }

        // 4. The inputs of the known-failure check.
        try {
            $manifest = Manifest::read($dir.'/tests/known-failures.md');
            $unconditional = Unconditional::read($dir.'/tests/unconditional.txt');
            $configExcludes = self::configExcludes($dir);
            $inventory = $this->inventory($leg, $dir, $php, $probed['version'], $unconditional, $logs);
        } catch (InputError|\RuntimeException $failure) {
            $result->reasons[] = $failure->getMessage();

            return false;
        }

        $result->prepared = new Prepared($dir, $php, $probed['version'], $configExcludes, $inventory, $manifest, $unconditional, $logs);

        return true;
    }

    /**
     * One unit: its runs one after another, in the leg's copy. It returns
     * false after an interruption.
     *
     * @param  list<RunSpec>  $specs
     */
    private function runUnit(LegResult $result, array $specs, ?string $base): bool
    {
        foreach ($specs as $spec) {
            if ($this->interrupted) {
                $result->interrupted = true;
                $result->reasons[] = 'interrupted before '.$spec->label();

                return false;
            }

            $run = $this->runOne($result->leg, $spec, $result->prepared, $base);
            $result->runs[] = $run;
            $this->report($result->leg, $run);
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public static function configExcludes(string $dir): array
    {
        $file = is_file($dir.'/phpunit.xml') ? $dir.'/phpunit.xml' : $dir.'/phpunit.xml.dist';
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_file($file);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            throw new \RuntimeException("cannot read the PHPUnit configuration {$file}");
        }

        return array_values(array_map('strval', $xml->xpath('/phpunit/groups/exclude/group') ?: []));
    }

    private function inventory(Leg $leg, string $dir, string $php, string $version, Unconditional $unconditional, string $logs): Inventory
    {
        $list = $logs.'/tests.xml';
        $command = [...$this->binTest($dir, $php), 'sqlite', '--php='.$php, '--', '--list-tests-xml', $list, '--exclude-group', NO_EXCLUSION, '--colors=never'];
        $env = array_replace($this->baseEnv, ['ORRERY_EXPECT_PHP' => $version, 'ORRERY_EXPECT_DRIVER' => 'sqlite', 'ORRERY_MATRIX_RUN' => $leg->id().'/list']);
        $listed = $this->execute(new Command($command, $dir, $env, $logs.'/tests.log', $this->options->timeout));

        if ($listed['exit'] !== 0 || ! is_file($list)) {
            throw new \RuntimeException("phpunit --list-tests-xml failed (exit {$listed['exit']}); see {$logs}/tests.log");
        }

        $xml = (string) file_get_contents($list);
        $inventory = Inventory::fromTestListXml($xml, $list);
        $groups = array_values(array_unique([...REQUIRED_GROUPS, ENGINE_SKIP_GROUP, ...$unconditional->groups, DOCKER_GROUP]));
        $sections = array_combine($groups, array_map(static fn (string $group): array => $inventory->members($group), $groups));
        // The docker run excludes the alt group, so its members are not required there.
        $sections[DOCKER_GROUP] = array_values(array_diff($sections[DOCKER_GROUP], $inventory->members(ALT_GROUP)));
        $inventory = Inventory::fromArray($sections);

        foreach (REQUIRED_GROUPS as $group) {
            $both = array_values(array_intersect($inventory->members($group), $inventory->members(DOCKER_GROUP)));

            if ($both !== []) {
                throw new \RuntimeException("a test is in the required group {$group} and in the docker group, which runs on its own: ".implode(', ', $both));
            }
        }

        file_put_contents($logs.'/inventory.txt', $inventory->render($groups, "bin/matrix: the groups of phpunit --list-tests-xml ({$leg->id()})"));

        return $inventory;
    }

    /**
     * @return list<string>
     */
    private function binTest(string $dir, string $php): array
    {
        return $this->fakeBinTest !== null ? [$php, $this->fakeBinTest] : [$dir.'/bin/test'];
    }

    private function runOne(Leg $leg, RunSpec $spec, Prepared $prepared, ?string $base): RunResult
    {
        $dir = $prepared->dir;
        $php = $prepared->php;
        $version = $prepared->version;
        $logs = $prepared->logs;
        $base = $spec->storage === 'server' ? $base : null;
        $name = $spec->name();
        $junit = $logs.'/'.$name.'.junit.xml';
        $log = $logs.'/'.$name.'.log';
        $identityLog = $logs.'/'.$name.'.identity';

        $env = array_replace($this->baseEnv, [
            'ORRERY_EXPECT_PHP' => $version,
            'ORRERY_EXPECT_DRIVER' => $spec->engine,
            'ORRERY_TEST_MIRROR' => $spec->variant->mirror,
            'ORRERY_MATRIX_RUN' => $leg->id().'/'.$name,
        ]);

        if ($spec->variant->prefix !== '') {
            $env['ORRERY_TEST_PREFIX'] = $spec->variant->prefix;
        }

        $wrapper = [];

        if ($spec->storage === 'server') {
            if ($base === null) {
                throw new \LogicException("the server run {$name} has no database");
            }

            $env['ORRERY_TEST_DATABASE'] = $base;

            if ($this->ci !== null) {
                $env['ORRERY_TEST_TOKEN'] = $this->ci['token'];
                $wrapper[] = '--no-provision';
            } else {
                // The unit's name is explicit, and bin/test keeps explicit
                // databases. With --drop-database its lease drops them at
                // release, after its processes are confirmed stopped and
                // while it still holds the names: after a failed run and
                // after an interruption too.
                $wrapper[] = '--drop-database';
            }
        }

        if ($spec->storage === 'file') {
            $wrapper[] = '--sqlite-file';
        }

        // bin/test chooses reuse for a lease or a SQLite file by default; the
        // matrix always names the mode.
        $wrapper[] = '--schema='.$spec->schema;
        // bin/test stops the run at the limit and names its running test.
        $wrapper[] = '--timeout='.$this->options->timeout;

        if ($spec->kind === 'groups') {
            $env['ORRERY_IDENTITY_LOG'] = $identityLog;
        }

        if ($spec->kind === 'docker') {
            $env['ORRERY_DOCKER_TESTS'] = '1';
        }

        $command = [
            ...$this->binTest($dir, $php),
            $spec->engine,
            ...$wrapper,
            '--php='.$php,
            '--',
            ...$spec->selection($prepared->configExcludes),
            '--log-junit', $junit,
            '--colors=never',
            '--do-not-cache-result',
        ];

        foreach ([$junit, $identityLog] as $stale) {
            if (file_exists($stale)) {
                unlink($stale);
            }
        }

        // Whole seconds: a file system can stamp a file up to one clock tick
        // before the time that this process read.
        $started = (int) floor(microtime(true));
        $process = $this->execute(new Command($command, $dir, $env, $log, $this->options->timeout + OUTER_MARGIN));
        clearstatcache(true, $log);
        $output = (string) @file_get_contents($log);
        $outputModified = @filemtime($log);
        $reasons = [];
        $timedOut = self::binTestTimeout($output);

        if ($timedOut !== null) {
            $reasons[] = "bin/test stopped the run at its --timeout={$timedOut['seconds']} s {$timedOut['text']}";
        }

        if ($process['timed_out']) {
            $reasons[] = 'the run was stopped after '.($this->options->timeout + OUTER_MARGIN).' seconds';
        }

        try {
            $report = JunitReport::read($junit);
        } catch (JunitProblem $problem) {
            $report = $problem;
        }

        $check = Check::evaluate($report, $spec->checkEngine(), $prepared->inventory, $spec->require, $spec->engineSkip, $process['exit'], $output, $prepared->manifest, $prepared->unconditional, $started, $outputModified === false ? null : $outputModified);
        file_put_contents($logs.'/'.$name.'.check.json', json_encode($check->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n");

        if (preg_match('/^Runtime:\s+PHP (\S+)/m', $output, $match) !== 1) {
            $reasons[] = 'the PHPUnit output has no Runtime line';
        } elseif ($match[1] !== $version) {
            $reasons[] = "PHPUnit ran on PHP {$match[1]}, not on the leg's PHP {$version}";
        }

        $identity = null;

        if ($spec->kind === 'groups') {
            [$identity, $identityReasons] = Identity::check($identityLog, $spec, $version, $base ?? '');
            array_push($reasons, ...$identityReasons);
        }

        return new RunResult($spec, $process['exit'], $process['seconds'], $check, $reasons, $identity, $timedOut['test'] ?? null, $base);
    }

    /**
     * The timeout line of bin/test in a run's output: its limit, the phrase
     * with the running test, and that test (null when no test ran).
     *
     * @return array{seconds: int, text: string, test: string|null}|null
     */
    public static function binTestTimeout(string $output): ?array
    {
        if (preg_match('/^bin\/test: timeout: the run passed --timeout=([0-9]+) s (.+); stopping it \(exit 124\)$/m', $output, $match) !== 1) {
            return null;
        }

        $test = preg_match('/^while it ran (.+)$/D', $match[2], $running) === 1 ? $running[1] : null;

        return ['seconds' => (int) $match[1], 'text' => $match[2], 'test' => $test];
    }

    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
            pcntl_signal($signal, function (int $signal): void {
                $this->interrupted = true;
                $this->signal = $this->signal ?: $signal;

                // The children run in this process group, so a terminal's
                // SIGINT reaches them too. A SIGTERM from elsewhere does not.
                foreach (Child::$running as $child) {
                    $child->terminate(15);
                }
            });
        }
    }

    private function say(string $line): void
    {
        fwrite($this->out, $line."\n");
    }
}

final class Identity
{
    /**
     * The identity line that HarnessIdentityTest writes to
     * ORRERY_IDENTITY_LOG must show the leg's PHP and the run's
     * driver, databases, prefix and evidence.
     *
     * @return array{0: array<string, mixed>|null, 1: list<string>}
     */
    public static function check(string $log, RunSpec $spec, string $version, string $base): array
    {
        $lines = is_file($log) ? array_values(array_filter(array_map('trim', file($log) ?: []), static fn (string $line): bool => str_starts_with($line, 'ORRERY-IDENTITY '))) : [];

        if (count($lines) !== 1) {
            return [null, [sprintf('the identity log has %d ORRERY-IDENTITY lines, expected 1 (HarnessIdentityTest::test_the_resolved_identity_equals_the_environment)', count($lines))]];
        }

        $record = json_decode(substr($lines[0], strlen('ORRERY-IDENTITY ')), true);

        if (! is_array($record)) {
            return [null, ['the identity line holds no JSON object']];
        }

        $get = static function (string $path) use ($record): mixed {
            $value = $record;

            foreach (explode('.', $path) as $key) {
                if (! is_array($value) || ! array_key_exists($key, $value)) {
                    return null;
                }

                $value = $value[$key];
            }

            return $value;
        };

        $separate = $spec->variant->mirror === 'separate';
        $kind = match ($spec->storage) {
            'memory' => 'sqlite-memory',
            'file' => 'sqlite-file',
            default => 'endpoint-token',
        };
        $primary = $get('primary.database');

        $expected = [
            'php' => $version,
            'primary.driver' => $spec->engine,
            'mirror.driver' => $spec->engine,
            'primary.evidence.kind' => $kind,
            'mirror.evidence.kind' => $kind,
            'mirror.prefix' => $spec->variant->prefix,
            'primary.prefix' => $separate ? '' : $spec->variant->prefix,
        ];

        if ($spec->storage === 'server') {
            $expected['primary.database'] = $base;
            $expected['mirror.database'] = $separate ? $base.'_mirror' : $base;
        } elseif ($spec->storage === 'memory') {
            $expected['primary.database'] = ':memory:';
            $expected['mirror.database'] = ':memory:';
        } else {
            $expected['mirror.database'] = is_string($primary) ? ($separate ? $primary.'.mirror' : $primary) : '(a file)';
        }

        $reasons = [];

        foreach ($expected as $path => $value) {
            if ($get($path) !== $value) {
                $reasons[] = sprintf('identity %s: expected %s, got %s', $path, json_encode($value), json_encode($get($path), JSON_UNESCAPED_SLASHES));
            }
        }

        if ($spec->storage === 'file' && (! is_string($primary) || preg_match('#/orrery-sqlite-run_[0-9]+_[0-9a-f]{8}/primary\.sqlite$#D', $primary) !== 1)) {
            $reasons[] = 'identity primary.database: expected the primary.sqlite file of bin/test --sqlite-file, got '.json_encode($primary, JSON_UNESCAPED_SLASHES);
        }

        return [[
            'php' => $get('php'),
            'driver' => $get('primary.driver'),
            'database' => $primary,
            'mirror_database' => $get('mirror.database'),
            'prefix' => $get('mirror.prefix'),
            'evidence' => $get('primary.evidence.kind'),
            'server_version' => $get('primary.server_version'),
        ], $reasons];
    }
}

final class Summary
{
    /**
     * @param  array{head: string, dirty: bool|null, hash: string, files: int, source: string}  $snapshot
     * @param  list<LegResult>  $legs
     * @param  list<string>  $argv
     */
    public function __construct(
        private readonly string $runId,
        private readonly array $snapshot,
        private readonly array $legs,
        private readonly array $argv,
        private readonly float $seconds,
        private readonly bool $interrupted,
        private readonly ?string $error = null,
    ) {}

    public static function duration(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%.1f s', $seconds);
        }

        return sprintf('%d:%02d', intdiv((int) round($seconds), 60), (int) round($seconds) % 60);
    }

    public static function verdictLabel(string $verdict, int $listed): string
    {
        return match ($verdict) {
            'pass' => 'PASS',
            'pass-listed' => "PASS with {$listed} listed known failure".($listed === 1 ? '' : 's'),
            default => 'FAIL',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function legArray(LegResult $leg): array
    {
        return [
            'leg' => $leg->leg->id(),
            'php' => $leg->leg->php,
            'laravel' => $leg->leg->laravel,
            'lowest' => $leg->leg->lowest,
            'databases' => array_values(array_unique(array_filter($leg->databases, static fn (?string $name): bool => $name !== null))),
            'snapshot' => $leg->hash,
            'versions' => $leg->versions,
            'verdict' => $leg->verdict(),
            'seconds' => round($leg->seconds, 1),
            'docker_seconds' => round($leg->dockerSeconds, 1),
            'totals' => $leg->totals(),
            'reasons' => $leg->reasons,
            'runs' => array_map(static fn (RunResult $run): array => [
                'run' => $run->spec->name(),
                'label' => $run->spec->label(),
                'engine' => $run->spec->checkEngine(),
                'database' => $run->database,
                'exit' => $run->exit,
                'seconds' => round($run->seconds, 1),
                'verdict' => $run->verdict(),
                'identity' => $run->identity,
                'check' => $run->check?->toArray(),
                'reasons' => $run->reasons,
                'timed_out_test' => $run->timedOutTest,
            ], $leg->runs),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'snapshot' => $this->snapshot,
            'command' => implode(' ', $this->argv),
            'seconds' => round($this->seconds, 1),
            'interrupted' => $this->interrupted,
            'error' => $this->error,
            'legs' => array_map(self::legArray(...), $this->legs),
        ];
    }

    public function markdown(): string
    {
        $s = $this->snapshot;
        $lines = [];
        $lines[] = "# bin/matrix run {$this->runId}";
        $lines[] = '';
        $lines[] = sprintf('- Snapshot: `%s` (HEAD `%s`, %d files%s)', $s['hash'], $s['head'], $s['files'], $s['dirty'] === true ? ', with uncommitted changes' : ($s['dirty'] === false ? ', no uncommitted changes' : ''));
        $lines[] = '- Command: `'.implode(' ', $this->argv).'`';
        $lines[] = '- Duration: '.self::duration($this->seconds).($this->interrupted ? ' (interrupted)' : '');

        if ($this->error !== null) {
            $lines[] = '- Internal error: '.$this->error.' (the run stopped there)';
        }
        $lines[] = '';
        $lines[] = '| Leg | Snapshot | PHP | laravel/framework | orchestra/testbench | PHPUnit | Tests | Assertions | Exit codes | Duration | Result |';
        $lines[] = '| --- | --- | --- | --- | --- | --- | ---: | ---: | --- | ---: | --- |';

        foreach ($this->legs as $leg) {
            $totals = $leg->totals();
            $exits = array_values(array_unique(array_map(static fn (RunResult $run): int => $run->exit, $leg->runs)));
            sort($exits);
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s | %s | %d | %d | %s | %s | %s |',
                $leg->leg->id(),
                $leg->hash === null ? '-' : substr($leg->hash, 0, 12),
                $leg->versions['php'] ?? '-',
                $leg->versions['framework'] ?? '-',
                $leg->versions['testbench'] ?? '-',
                $leg->versions['phpunit'] ?? '-',
                $totals['tests'],
                $totals['assertions'],
                $exits === [] ? '-' : implode(', ', $exits),
                self::duration($leg->seconds),
                self::verdictLabel($leg->verdict(), $totals['listed']),
            );
        }

        $lines[] = '';
        $lines[] = '## Runs';
        $lines[] = '';
        $lines[] = '| Leg | Run | Engine | Evidence | Tests | Assertions | Skipped | Listed | Exit | Duration | Result |';
        $lines[] = '| --- | --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |';

        foreach ($this->legs as $leg) {
            foreach ($leg->runs as $run) {
                $counts = $run->check?->counts;
                $lines[] = sprintf(
                    '| %s | %s | %s | %s | %s | %s | %s | %d | %d | %s | %s |',
                    $leg->leg->id(),
                    $run->spec->label(),
                    $run->spec->checkEngine(),
                    $run->identity === null ? '-' : ($run->identity['evidence'] ?? '-').($run->identity['server_version'] !== null && $run->identity['server_version'] !== '' ? ' '.$run->identity['server_version'] : ''),
                    $counts === null ? '-' : (string) $counts['tests'],
                    $counts === null ? '-' : (string) $counts['assertions'],
                    $counts === null ? '-' : (string) $counts['skipped'],
                    count($run->check?->listed ?? []),
                    $run->exit,
                    self::duration($run->seconds),
                    self::verdictLabel($run->verdict(), count($run->check?->listed ?? [])),
                );
            }
        }

        $lines[] = '';
        $lines[] = '## Required groups';
        $lines[] = '';
        $lines[] = 'May skip: the members that are also in '.ENGINE_SKIP_GROUP.' (the documented engine skip). They may skip only on in-memory SQLite.';
        $lines[] = '';
        $lines[] = '| Leg | Run | Group | Members | May skip | Executed | Skipped | Missing |';
        $lines[] = '| --- | --- | --- | ---: | ---: | ---: | ---: | ---: |';

        foreach ($this->legs as $leg) {
            foreach ($leg->runs as $run) {
                foreach ($run->check?->groups ?? [] as $group => $stats) {
                    $lines[] = sprintf('| %s | %s | %s | %d | %s | %d | %d | %d |', $leg->leg->id(), $run->spec->label(), $group, $stats['members'], $run->spec->engineSkip === [] ? '-' : (string) $stats['exempt'], $stats['executed'], $stats['skipped'], $stats['missing']);
                }
            }
        }

        $failures = [];

        foreach ($this->legs as $leg) {
            foreach ($leg->reasons as $reason) {
                $failures[] = "- {$leg->leg->id()}: {$reason}";
            }

            foreach ($leg->runs as $run) {
                foreach ($run->allReasons() as $reason) {
                    $failures[] = "- {$leg->leg->id()}, {$run->spec->label()}: {$reason}";
                }
            }
        }

        $lines[] = '';
        $lines[] = '## Failure reasons';
        $lines[] = '';
        $lines = [...$lines, ...($failures === [] ? ['None.'] : $failures)];

        $passed = count(array_filter($this->legs, static fn (LegResult $leg): bool => $leg->verdict() !== 'fail'));
        $listed = count(array_filter($this->legs, static fn (LegResult $leg): bool => $leg->verdict() === 'pass-listed'));
        $lines[] = '';
        $lines[] = sprintf('Result: %d legs, %d passed (%d with listed known failures), %d failed.', count($this->legs), $passed, $listed, count($this->legs) - $passed);

        return implode("\n", $lines)."\n";
    }
}

final class MatrixCommand
{
    public const USAGE = <<<'TXT'
        Usage: bin/matrix [--php=8.3,8.4,8.5] [--laravel=12,13] [--db=sqlite,mysql,pgsql,mariadb]
                          [--mirror=same,separate] [--variants=same/none,...]
                          [--no-lowest] [--no-latest] [--quick] [--all-fresh] [--memory-only]
                          [--dry-run] [--php-binary=<version>=<path>]... [--composer=<path>]
                          [--run-id=<id>] [--timeout=<seconds>] [--slot-wait=<seconds>] [--ci]

        Runs the support matrix of Spec A §4.7 in copies of this checkout under
        .matrix/<run-id>/, and writes .matrix/<run-id>/summary.md.

        Legs:
          latest   each selected PHP x Laravel pair (orchestra/testbench ^10.0 for
                   Laravel 12, ^11.0 for Laravel 13)
          lowest   --prefer-lowest --prefer-stable for each selected Laravel major on
                   PHP 8.3 (when 8.3 is selected); COMPOSER_NO_BLOCKING=1 for Composer only
        Each leg runs mirror=same without a prefix on every selected engine.
        Without --db the engines are sqlite, mysql and pgsql, and the MariaDB leg
        php8.5-laravel13 also runs on mariadb (not with --ci: the MariaDB job of
        CI passes --db=mariadb). The
        latest PHP 8.4 x Laravel 12 leg (the variant leg) also runs separate,
        same+prefix and separate+prefix. --mirror selects the mirror modes of
        these variants.

        Schema modes: in-memory SQLite always runs --schema=fresh. The file and
        server runs are fresh on the lowest legs and on php8.5-laravel13. The
        variant leg runs its file runs fresh and its server runs with
        --schema=reuse. The other latest legs run their file and server runs
        with --schema=reuse.

        Units: each leg prepares its copy (Composer, the test list), then runs
        its units: one per variant, and the scripts or workbench run. The
        scripts group runs on the first selected latest leg of each PHP
        (Laravel 13 when selected) and on every lowest leg; the
        other legs run only the workbench group. After every leg, the docker
        group runs once per PHP version (with a server engine, not with
        --ci). Each unit with a server run has its own base database
        (orrery_m_<run>_<leg>_<sn|pn|sp|pp>). Everything runs serially, on
        purpose: the machine is shared and often loaded.

          --quick                    the wave-merge preset: php8.4-laravel12 (all
                                     variants), php8.5-laravel13
                                     and php8.3-laravel13-lowest; docker once (PHP 8.4).
                                     Not with --php, --laravel, --no-lowest, --no-latest
          --all-fresh                every file and server run uses --schema=fresh
          --memory-only              each variant runs only the suite and the required
                                     groups on in-memory SQLite: no file or server run,
                                     no scripts, workbench or docker group (the quick
                                     CI tier). Implies --db=sqlite; not with --quick
          --variants=<labels>        the variants to run, from same/none,
                                     separate/none, same/prefix, separate/prefix
                                     (default: all); with --mirror, a variant runs
                                     only when both select it. A leg left without
                                     a variant is refused (64). CI runs the
                                     PHP 8.4 x Laravel 12 leg as one job per variant.
          --no-lowest, --no-latest   leave out the lowest or the latest legs
          --dry-run                  print the legs, their units and their runs
          --php-binary=8.4=<path>    the PHP binary of a version; else
                                     $ORRERY_PHP_8_4, else /opt/homebrew/opt/php@8.4/bin/php
          --composer=<path>          composer or composer.phar; else composer on PATH
          --run-id=<id>              the name of the run directory
          --timeout=<seconds>        per PHPUnit run (default 4800); bin/test gets it as
                                     --timeout, stops the run at it and names its running
                                     test; bin/matrix stops a run 120 seconds later
          --slot-wait=<seconds>      the longest wait for the heavy-run slot that another
                                     bin/test or bin/matrix holds (default 7200; 0 fails at
                                     once). Without --ci, a run holds the slot from its
                                     first leg to its end
          --ci                       GitHub Actions only (CI=true): one leg, in this
                                     checkout, without copies, every run fresh; server
                                     engines use bin/test --no-provision
                                     with ORRERY_TEST_DATABASE and ORRERY_TEST_TOKEN; no
                                     docker group

        Exit status: 0 every leg passed (some may have listed known failures),
        1 a leg failed, 64 invalid arguments, 72 the heavy-run slot did not
        become free within --slot-wait, 128+n after signal n, 255 an internal
        error (the summary names it).

        TXT;

    /**
     * @param  list<string>  $argv
     * @param  array<string, string>  $env
     */
    public static function main(array $argv, string $root, array $env): int
    {
        $testMode = ($env['ORRERY_MATRIX_TEST'] ?? '') === '1';
        $source = $testMode && ($env['ORRERY_MATRIX_SOURCE'] ?? '') !== '' ? $env['ORRERY_MATRIX_SOURCE'] : $root;
        $outBase = $testMode && ($env['ORRERY_MATRIX_OUT'] ?? '') !== '' ? $env['ORRERY_MATRIX_OUT'] : $source.'/.matrix';
        $fakeBinTest = $testMode && ($env['ORRERY_MATRIX_BIN_TEST'] ?? '') !== '' ? $env['ORRERY_MATRIX_BIN_TEST'] : null;

        try {
            $arguments = array_slice($argv, 1);

            if (in_array('-h', $arguments, true) || in_array('--help', $arguments, true)) {
                fwrite(STDOUT, self::USAGE);

                return 0;
            }

            $options = Options::parse($arguments);
            $runner = new Runner($options, $source, $outBase, $env, $argv, $fakeBinTest);

            return $options->dryRun ? $runner->dryRun() : $runner->run();
        } catch (UsageError|InputError $error) {
            fwrite(STDERR, 'bin/matrix: '.$error->getMessage()."\n\n".self::USAGE);

            return 64;
        }
    }
}
