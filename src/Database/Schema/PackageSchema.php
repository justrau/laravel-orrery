<?php

namespace Justrau\Orrery\Database\Schema;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\ConnectionName;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\SiblingFileUnavailable;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * The package tables, their schema version and the
 * schema version check.
 *
 * The main migration is a wrapper: its getConnection() is
 * migrationConnection(), its up() install() and its down() uninstall(), so
 * the policy of the install lives here and not in the frozen file. install()
 * runs create() and mark() on a server mirror without package tables, and
 * ensure() on the sibling. The sibling SQLite mirror file installs itself:
 * PackageSession calls ensureInstalled() when it first opens the file in a
 * process. That does not depend on the host's migrations table, which a
 * `schema:load` or a lost sibling makes wrong.
 *
 * The frozen baseline: BASELINE is the schema
 * version of the tag v0.1.0. What create() and mark() build at BASELINE is
 * recorded per engine in tests/Fixtures/schema-baseline/ (SchemaBaselineTest,
 * MigrationFreezeTest). A later schema change N raises VERSION, adds an
 * idempotent forward step N to upgrades(), changes create(), and adds one
 * migration file that calls upgradeTo(N). The main migration is never
 * edited again; install() keeps calling create(), so a fresh install gets
 * the newest schema in one step.
 *
 * ensure() runs under `BEGIN EXCLUSIVE` on the sibling: it reads the marker
 * again (another process may have finished first), refuses a schema from
 * before BASELINE (a marker below it, or package tables without a marker),
 * runs every forward step above BASELINE up to VERSION, creates every table
 * that is still missing with its indexes, writes the marker and commits.
 * The whole upgrade is one transaction, so a stop changes nothing. It never
 * drops a table or a row that the current code uses.
 *
 * Every package time is epoch seconds in a decimal(16,6) column. The
 * exceptions are retry_until (Laravel's integer retryUntil) and
 * orrery_signals.expires_at (dateTime). Every index and key has an explicit
 * name from IndexName, because Laravel's default names of the composite keys
 * exceed the MySQL limit of 64 characters. Every table has a primary key, so
 * the schema installs on a MySQL server with sql_require_primary_key = ON.
 * On MySQL and MariaDB, create() builds each table with its keys in one
 * `create table` statement (InlineKeys), with the same result as Laravel's
 * separate key statements.
 */
final class PackageSchema
{
    /**
     * The schema version of this release. Version 10 is the baseline of
     * v0.1.0 (BASELINE); every version above it has a forward step in
     * upgrades() and an upgrade migration.
     */
    public const VERSION = 10;

    /**
     * The schema version of the tag v0.1.0. It
     * never changes: MigrationFreezeTest fails when it does.
     */
    public const BASELINE = 10;

    /**
     * The pre-v0.1 upgrade path of an existing MySQL or Postgres mirror:
     * no forward step can bring a schema from
     * before BASELINE forward, so the package migration is published again
     * (a copy published before the schema marker holds the old inline
     * schema), rolled back and run again. Never a plain
     * `migrate:rollback`, `--step` or `migrate:reset`: they roll back
     * application migrations too. First no operation may be open: the
     * scheduler's tick finishes the operations in progress,
     * `orrery:resolve --all` those that wait for an operator, and
     * `orrery:resolve <id>` any other (drop() lists them). Every command and
     * option named here exists in this release (SchemaUpgradePathTest).
     */
    public const UPGRADE_STEPS = 'First run php artisan vendor:publish --tag=orrery-migrations --force '
        .'(a copy published by an early build holds an older schema); '
        .'then let the scheduler\'s php artisan orrery:tick finish the open operations, '
        .'run php artisan orrery:resolve --all for those that wait for an operator, '
        .'and php artisan orrery:resolve <id> for each operation that is still open; '
        .'then php artisan migrate:refresh --path=database/migrations/2026_09_23_000000_create_orrery_tables.php --realpath '
        .'(the package migration that orrery:install published; never a plain migrate:rollback, --step or migrate:reset; '
        .'if it reports Nothing to rollback, drop the remaining orrery_* tables, then run php artisan migrate); '
        .'then php artisan orrery:install --fence, then php artisan migrate; '
        .'then php artisan orrery:install --indexes. '
        .'The refresh drops every orrery_* table and its rows; jobs, failed_jobs and job_batches keep their data.';

    /**
     * The orrery_jobs indexes of the stuck signals: a
     * fresh install builds them in create() (the tables are empty), and no
     * forward step builds them, because the sibling installs itself on its
     * first open of a process, possibly inside a host push, and must not
     * index a large table under BEGIN EXCLUSIVE there. For an existing
     * schema the operator builds them with `php artisan orrery:install
     * --indexes` (Database\Schema\IndexBuild, on a dedicated connection);
     * until then DeferredIndexes::missing() names them, and their readers
     * answer `index_missing`.
     */
    public const DEFERRED_INDEXES = [
        'st_attempts' => ['status', 'attempts', 'id'],
        'st_gap' => ['status', 'observed_gap', 'id'],
        // "Repeated releases": a cycle's releases can exceed its
        // attempts (two lineages after a reclaim), so its list cannot read st_attempts.
        'st_releases' => ['status', 'releases', 'id'],
    ];

    /**
     * The hint of a server mirror whose schema is from before BASELINE: no
     * marker, or a marker below BASELINE (/api/health `schema`, orrery:install).
     */
    public const UPGRADE_HINT = 'The Orrery schema is from before Orrery 0.1, so php artisan migrate cannot upgrade it. '.self::UPGRADE_STEPS;

    /**
     * The hint of a server mirror whose marker table holds no row: the
     * state of a database loaded from `php artisan schema:dump`, which keeps
     * the structure and only the rows of the migrations table. create()
     * builds the marker table last, so its presence proves a complete
     * create(). orrery:install writes the marker (adoptUnmarked()).
     */
    public const UNMARKED_HINT = 'The Orrery schema marker table (orrery_schema) holds no row, as after a load of php artisan schema:dump. '
        .'Run php artisan orrery:install to write it: it keeps every table and row.';

    /** The hint of a server mirror whose marker is from BASELINE up to below VERSION. */
    public const MIGRATE_HINT = 'Run php artisan migrate.';

    /**
     * The hint of a server mirror without any package table: php artisan
     * migrate has not run, or it ran on another database before
     * orrery.database changed (then the migration is recorded, and only the
     * refresh of the package migration creates the tables here).
     */
    public const INSTALL_HINT = 'Run php artisan migrate to create the Orrery tables. '
        .'If it reports Nothing to migrate (the package migration ran on another database before orrery.database changed), '
        .'run php artisan migrate:refresh --path=database/migrations/2026_09_23_000000_create_orrery_tables.php --realpath.';

    /**
     * The reason of SiblingFileUnavailable for a sibling whose schema is from
     * before BASELINE: a marker below it, or package tables without a marker.
     * SiblingFileUnavailable adds the path and the
     * final period.
     */
    public const PRE_BASELINE_SIBLING = 'its schema is from before Orrery 0.1; stop the workers and delete the file with its -wal and -shm files, and Orrery rebuilds it';

    /** The most open operations that drop()'s refusal lists. */
    private const LISTED_OPERATIONS = 20;

    /**
     * The tables of the old retry protocol that a build before the core
     * rebuild created. drop() removes them, so the pre-v0.1
     * refresh leaves none of them.
     */
    public const REMOVED_TABLES = ['orrery_claim_receipts', 'orrery_provider_cleanups'];

    /** The marker table: one row, the installed version. */
    public const MARKER = 'orrery_schema';

    /** The hook point between the marker read and BEGIN EXCLUSIVE (tests only). */
    public const BEFORE_LOCK = 'package_schema.before_lock';

    /** @var array<int, Closure(Builder): void>|null */
    private ?array $upgradesForTesting = null;

    /** The schema version that replaces VERSION in tests (useUpgradesForTesting()). */
    private ?int $versionForTesting = null;

    /** @var (Closure(Builder, list<string>|null): void)|null */
    private ?Closure $createForTesting = null;

    /** Tests only: create() runs Laravel's separate key statements on MySQL too (useSeparateKeysForTesting()). */
    private bool $separateKeysForTesting = false;

    /**
     * drop() refuses while an operation is open. The test
     * environment turns it off (useOpenOperationGuardForTesting()): the
     * fresh schema mode rolls the migration back after every test, also
     * after tests that leave open operations.
     */
    private bool $openOperationGuard = true;

    /**
     * The default connection when Laravel's migrator last asked the package
     * migration for its connection (migrationConnection()), before
     * Migrator::runMethod() made the migration connection the default.
     * install() and uninstall() decide the refusal and the sibling against
     * it, and consume it.
     */
    private ?string $migrationDefault = null;

    /**
     * The default connection at the start of each running Artisan command
     * (CommandStarting, StorageModule), innermost last: the application's
     * own default before `migrate --database=<name>` makes <name> the
     * default (Migrator::setConnection()). atMigrationDefault() prefers it.
     *
     * @var list<string>
     */
    private array $commandDefaults = [];

    /**
     * The forward steps above BASELINE, by the version that they reach (Spec
     * D section 3, item 3). The pre-v0.1 steps 2 to 10 are gone: they were
     * only a sibling path, and they could not upgrade a server schema that
     * an earlier in-place create() built. A later schema change N adds its
     * step N here, raises VERSION, changes create(), and adds one migration
     * database/migrations/<date>_upgrade_orrery_schema_to_v<N>.php that calls
     * upgradeTo(). Each step takes the schema builder of the mirror and is
     * idempotent: it checks each object (hasTable(), hasColumn(), hasIndex())
     * before it changes it, because a sibling runs every step above
     * BASELINE again (ensure()), and an interrupted MySQL upgrade runs its
     * steps again on the next `migrate` (upgradeTo()).
     *
     * @return array<int, Closure(Builder): void>
     */
    public static function upgrades(): array
    {
        return self::bound()->upgradesForTesting ?? [];
    }

    /**
     * The notification state of an incident (Monitoring\StuckNotifications):
     * `notified`, a JSON object of each channel's state; `notify_attempts`,
     * the rounds claimed (at most StuckNotifications::MAX_ROUNDS, 10; each
     * channel gets up to 3 real tries); `notify_next_at`, when the next round is
     * due (null: nothing more to send), with its index.
     */
    private static function notifyColumns(Blueprint $table, string $index): void
    {
        $table->text('notified')->nullable();
        $table->unsignedInteger('notify_attempts')->default(0);
        $table->decimal('notify_next_at', 16, 6)->nullable();
        // The due rounds in a stable order: the time, then the key.
        $table->index(['notify_next_at', 'job_uuid', 'cycle', 'signal'], $index);
    }

    /**
     * Replaces the forward steps in tests, and with $version the schema
     * version of this release, so a test can upgrade to a version above
     * VERSION; null restores them.
     *
     * @param  array<int, Closure(Builder): void>|null  $upgrades
     */
    public static function useUpgradesForTesting(?array $upgrades, ?int $version = null): void
    {
        $state = self::bound();

        $state->upgradesForTesting = $upgrades;
        $state->versionForTesting = $upgrades === null ? null : $version;
    }

    /**
     * Makes create() also run $create after the package tables (tests only:
     * the fixture version of SchemaBaselineTest adds a fixture table there,
     * as a later schema change would add one to create()); the closure gets
     * the schema builder and create()'s $only. Null restores create().
     *
     * @param  (Closure(Builder, list<string>|null): void)|null  $create
     */
    public static function useCreateForTesting(?Closure $create): void
    {
        self::bound()->createForTesting = $create;
    }

    /**
     * Makes create() run Laravel's separate key statements also on MySQL
     * and MariaDB, so a test can compare their tables with the inline keys;
     * false restores the inline keys.
     */
    public static function useSeparateKeysForTesting(bool $separate): void
    {
        self::bound()->separateKeysForTesting = $separate;
    }

    /**
     * Turns drop()'s open-operation guard on or off (tests only; the test
     * environment turns it off).
     */
    public static function useOpenOperationGuardForTesting(bool $guard): void
    {
        self::bound()->guardOpenOperationsForTesting($guard);
    }

    /**
     * The same on this instance: the test environment sets it when the
     * container builds the instance (an afterResolving callback, which must
     * not resolve the class again).
     */
    public function guardOpenOperationsForTesting(bool $guard): void
    {
        $this->openOperationGuard = $guard;
    }

    /** The schema version of this release: VERSION, or the test's version. */
    public static function currentVersion(): int
    {
        return self::bound()->versionForTesting ?? self::VERSION;
    }

    /** The package tables, in creation order. */
    public const TABLES = [
        'orrery_jobs',
        'orrery_job_tags',
        'orrery_failed_tags',
        'orrery_job_history',
        'orrery_cycle_summaries',
        'orrery_monitored_tags',
        'orrery_settings',
        'orrery_worker_heartbeats',
        'orrery_health_events',
        'orrery_signals',
        'orrery_markers',
        'orrery_samples',
        'orrery_operations',
        self::BULK_RUNS,
        self::INCIDENTS,
        self::NOTIFY_COOLDOWNS,
        'orrery_fences',
        self::MARKER,
    ];

    /** The audit table of the bulk runs (schema step 8). */
    public const BULK_RUNS = 'orrery_bulk_runs';

    /** The stuck incidents (schema step 9). */
    public const INCIDENTS = 'orrery_incidents';

    /** The cooldowns of the stuck-job notifications (schema step 10). */
    public const NOTIFY_COOLDOWNS = 'orrery_notify_cooldowns';

    /**
     * The metric tables of the builds before orrery_samples; drop() removes
     * them, so the pre-v0.1 refresh leaves none of them.
     */
    public const RETIRED_TABLES = ['orrery_metric_counters', 'orrery_metric_snapshots', 'orrery_queue_samples'];

    /**
     * The binary NO PAD collation of the byte-exact columns: MySQL
     * `utf8mb4_0900_bin` (8.0.17 and later), MariaDB `utf8mb4_nopad_bin`;
     * null on SQLite and Postgres, which compare bytes already. A `mysql`
     * connection that pretends is taken as MySQL 8.0.17+.
     * Note: the byte-exact NO PAD guarantee needs MySQL 8.0.17+ or MariaDB
     * 10.2+; an older MySQL gets `utf8mb4_bin` (PAD SPACE), so names that
     * differ only by trailing spaces share one index range there.
     */
    public static function byteExactCollation(Connection $connection): ?string
    {
        $driver = $connection->getDriverName();

        if ($driver === 'mariadb') {
            return 'utf8mb4_nopad_bin';
        }

        if ($driver !== 'mysql') {
            return null;
        }

        // A pretending connection runs no statement: its create text names the MySQL collation.
        if ($connection->pretending()) {
            return 'utf8mb4_0900_bin';
        }

        try {
            if ($connection instanceof MySqlConnection && $connection->isMaria()) {
                return 'utf8mb4_nopad_bin';
            }

            $found = $connection->selectOne("select 1 as found from information_schema.collations where collation_name = 'utf8mb4_0900_bin'");
        } catch (Throwable) {
            $found = null;
        }

        // Before MySQL 8.0.17 (or on a compatible server without it): the PAD SPACE utf8mb4_bin, which
        // still ignores trailing spaces; retention stays exact through its byte-exact residual filters.
        return $found !== null ? 'utf8mb4_0900_bin' : 'utf8mb4_bin';
    }

    /**
     * Creates the package tables, or only the named ones, with their
     * indexes.
     *
     * @param  list<string>|null  $only
     */
    public static function create(Builder $schema, ?array $only = null): void
    {
        $connection = $schema->getConnection();
        // MySQL and MariaDB: each table with its keys in one `create table` (InlineKeys); the
        // other engines build every index with a statement of its own anyway.
        $inline = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) && ! self::bound()->separateKeysForTesting;
        $create = static function (string $table, Closure $definition) use ($schema, $only, $inline): void {
            if ($only !== null && ! in_array($table, $only, true)) {
                return;
            }

            if ($inline) {
                InlineKeys::create($schema, $table, $definition);
            } else {
                $schema->create($table, $definition);
            }
        };

        $name = static fn (string $table, string $suffix): string => IndexName::for($connection, $table, $suffix);
        $binaryTags = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
        // Tags are case-sensitive and compared byte for byte, as the
        // producer's PHP comparison does. SQLite and Postgres compare text that way by
        // default; MySQL and MariaDB need a binary collation on every tag column.
        $tag = static function (Blueprint $table) use ($binaryTags): void {
            $column = $table->string('tag', 255);

            if ($binaryTags) {
                $column->charset('utf8mb4')->collation('utf8mb4_bin');
            }
        };

        // orrery_jobs.connection, orrery_jobs.queue and orrery_settings.key compare byte for
        // byte: retention partitions and cursors key on them, and the
        // retention overrides compare names exactly. SQLite and Postgres compare bytes by
        // default; MySQL and MariaDB need a binary NO PAD collation (a PAD SPACE one, as
        // utf8mb4_bin, still ignores trailing spaces).
        $exact = self::byteExactCollation($connection);
        $exactly = static function (ColumnDefinition $column) use ($exact): void {
            if ($exact !== null) {
                $column->charset('utf8mb4')->collation($exact);
            }
        };

        $create('orrery_jobs', function (Blueprint $table) use ($name, $exactly): void {
            $table->bigIncrements('id');
            $table->char('uuid', 36);
            $table->unsignedInteger('cycle')->default(1);
            $table->string('cycle_gen', 64)->nullable();
            $table->unsignedBigInteger('cycle_first_job_id')->nullable();
            $table->decimal('cycle_started_at', 16, 6)->nullable();
            $table->string('cycle_source', 16)->default('push');
            $table->unsignedBigInteger('occurrence_id')->nullable();
            $table->unsignedBigInteger('job_id')->nullable();
            $table->unsignedBigInteger('prev_job_id')->nullable();
            $table->boolean('job_id_unresolved')->default(false);
            $table->unsignedBigInteger('bulk_floor_id')->nullable();
            // The id of the failed record that the package's provider bound to this occurrence.
            $table->unsignedBigInteger('provider_id')->nullable();
            $table->string('batch_id', 255)->nullable();
            $exactly($table->string('connection', 191));
            $exactly($table->string('queue', 255));
            $table->string('name', 255)->default('');
            $table->string('type', 32)->default('job');
            $table->string('status', 16);
            $table->boolean('silenced')->default(false);
            $table->boolean('monitored')->default(false);
            $table->unsignedBigInteger('monitor_version')->nullable();
            $table->boolean('tags_truncated')->default(false);
            $table->boolean('history_truncated')->default(false);
            $table->unsignedInteger('history_discarded')->default(0);
            $table->decimal('retry_requested_at', 16, 6)->nullable();
            $table->longText('payload');
            $table->unsignedBigInteger('payload_bytes');
            $table->unsignedInteger('max_tries')->nullable();
            // Laravel's payload retryUntil is integer epoch seconds (an exception to the time rule).
            $table->unsignedBigInteger('retry_until')->nullable();
            $table->unsignedInteger('max_exceptions')->nullable();
            $table->unsignedInteger('timeout')->nullable();
            $table->boolean('fail_on_timeout')->default(false);
            $table->longText('exception')->nullable();
            $table->unsignedBigInteger('exception_bytes')->nullable();
            $table->string('exception_class', 255)->nullable();
            $table->text('exception_message')->nullable();
            // The failure group key (Failures\GroupKey::of()) of the stored exception columns and name; JobStore writes it with every failure.
            $table->char('failure_key', 64)->nullable();
            $table->longText('context')->nullable();
            $table->unsignedBigInteger('context_bytes')->nullable();

            foreach (self::rowCounters() as $counter) {
                $table->unsignedInteger($counter)->default(0);
            }

            $table->unsignedInteger('retried_count')->default(0);
            $table->boolean('retried_count_inferred')->default(false);
            $table->unsignedInteger('worker_tries')->nullable();
            $table->unsignedInteger('worker_timeout')->nullable();
            $table->unsignedInteger('worker_evidence_attempt')->nullable();
            $table->string('reserved_by', 255)->nullable();
            $table->decimal('pushed_at', 16, 6);

            foreach (['available_at', 'reserved_at', 'finished_at', 'failed_at', 'retried_at', 'activity_at', 'absent_since', 'unknown_since'] as $column) {
                $table->decimal($column, 16, 6)->nullable();
            }

            $table->string('unknown_reason', 32)->nullable();
            $table->decimal('updated_at', 16, 6);
            // The runtime of the latest completion in milliseconds, null when unknown.
            $table->unsignedInteger('runtime_ms')->nullable();
            // After updated_at after runtime_ms, so that a fresh install and the forward steps 2 and 3 agree.
            self::operationColumns($table, ['operation_id', 'provider_target']);

            $table->unique('uuid', $name('orrery_jobs', 'uuid'));

            foreach ([
                // The count of recent jobs across all statuses.
                'pushed' => ['pushed_at', 'id'],
                'st_pushed' => ['status', 'pushed_at', 'id'],
                'st_reserved' => ['status', 'reserved_at', 'id'],
                'st_sil_finished' => ['status', 'silenced', 'finished_at', 'id'],
                'st_finished' => ['status', 'finished_at', 'id'],
                // The failed list, newest failure first (failed_at desc, id desc), and the recent-failure count.
                'st_failed' => ['status', 'failed_at', 'id'],
                // The per-key reads of the failure groups: count, first and last failure, members.
                'failure' => ['status', 'failure_key', 'failed_at', 'id'],
                // The same reads with a queue scope: they seek on the scope first, then the key.
                'failure_scope' => ['status', 'connection', 'queue', 'failure_key', 'failed_at', 'id'],
                'retention' => ['connection', 'queue', 'status', 'silenced', 'monitored', 'finished_at', 'id'],
                'cq_pushed' => ['connection', 'queue', 'status', 'pushed_at', 'id'],
                'cq_reserved' => ['connection', 'queue', 'status', 'reserved_at', 'id'],
                'monitor_version' => ['monitor_version', 'id'],
                'batch' => ['batch_id', 'status', 'finished_at', 'id'],
                'reserved_by' => ['status', 'reserved_by'],
                'job_id' => ['job_id'],
                'occurrence' => ['occurrence_id'],
                // The tick's reads of unresolved bulk rows,
                // per group (connection, queue, floor).
                'unresolved' => ['job_id_unresolved', 'bulk_floor_id', 'connection', 'queue', 'id'],
            ] + self::DEFERRED_INDEXES as $suffix => $columns) {
                $table->index($columns, $name('orrery_jobs', $suffix));
            }
        });

        $create('orrery_job_tags', static function (Blueprint $table) use ($name, $tag): void {
            $tag($table);
            $table->char('job_uuid', 36);
            $table->decimal('pushed_at', 16, 6);

            // The spec's unique key (tag, job_uuid), made the primary key for sql_require_primary_key.
            $table->primary(['tag', 'job_uuid'], $name('orrery_job_tags', 'primary'));
            $table->index(['tag', 'pushed_at', 'job_uuid'], $name('orrery_job_tags', 'tag_pushed'));
            $table->index('job_uuid', $name('orrery_job_tags', 'job'));
        });

        $create('orrery_failed_tags', static function (Blueprint $table) use ($name, $tag): void {
            $tag($table);
            $table->char('job_uuid', 36);
            $table->decimal('finished_at', 16, 6);

            // The spec's unique key (job_uuid, tag), made the primary key for sql_require_primary_key.
            $table->primary(['job_uuid', 'tag'], $name('orrery_failed_tags', 'primary'));
            $table->index(['tag', 'finished_at', 'job_uuid'], $name('orrery_failed_tags', 'tag_finished'));
        });

        $create('orrery_job_history', static function (Blueprint $table) use ($name): void {
            $table->bigIncrements('id');
            $table->char('job_uuid', 36);
            $table->unsignedInteger('cycle');
            $table->unsignedInteger('attempt')->nullable();
            $table->string('event', 20);
            $table->string('source', 16)->nullable();
            $table->string('reason', 64)->nullable();
            $table->string('dedupe_key', 64)->nullable();
            $table->string('exception_class', 255)->nullable();
            $table->text('exception_message')->nullable();
            $table->longText('exception')->nullable();
            $table->unsignedBigInteger('exception_bytes')->nullable();
            $table->longText('context')->nullable();
            $table->unsignedBigInteger('context_bytes')->nullable();
            $table->decimal('occurred_at', 16, 6);

            $table->index(['job_uuid', 'cycle', 'id'], $name('orrery_job_history', 'job_cycle'));
            $table->unique(['job_uuid', 'dedupe_key'], $name('orrery_job_history', 'dedupe'));
        });

        $create('orrery_cycle_summaries', function (Blueprint $table) use ($name): void {
            $table->char('job_uuid', 36);
            $table->unsignedInteger('cycle');
            $table->string('cycle_gen', 64)->nullable();
            $table->unsignedBigInteger('occurrence_id')->nullable();
            $table->string('outcome', 16)->nullable();
            $table->boolean('merged')->default(false);
            $table->unsignedInteger('first_cycle')->nullable();
            $table->unsignedInteger('merged_cycles')->nullable();
            $table->unsignedInteger('merged_retries')->nullable();

            foreach (self::rowCounters() as $counter) {
                $table->unsignedInteger($counter)->default(0);
            }

            $table->boolean('history_truncated')->default(false);
            $table->string('exception_class', 255)->nullable();
            $table->text('exception_message')->nullable();
            $table->unsignedInteger('max_tries')->nullable();
            $table->unsignedInteger('max_exceptions')->nullable();
            $table->unsignedInteger('timeout')->nullable();
            $table->boolean('fail_on_timeout')->default(false);
            // Laravel's payload retryUntil is integer epoch seconds (an exception to the time rule).
            $table->unsignedBigInteger('retry_until')->nullable();
            $table->unsignedInteger('effective_tries')->nullable();
            $table->string('effective_tries_source', 8)->default('unknown');
            $table->unsignedInteger('effective_timeout')->nullable();
            $table->string('effective_timeout_source', 8)->default('unknown');
            $table->decimal('started_at', 16, 6)->nullable();
            $table->string('started_source', 16)->nullable();
            $table->decimal('finished_at', 16, 6)->nullable();

            $table->primary(['job_uuid', 'cycle'], $name('orrery_cycle_summaries', 'primary'));
            $table->index(['job_uuid', 'cycle_gen'], $name('orrery_cycle_summaries', 'gen'));
        });

        $create('orrery_monitored_tags', static function (Blueprint $table) use ($name, $tag): void {
            $tag($table);
            $table->decimal('created_at', 16, 6);

            $table->primary('tag', $name('orrery_monitored_tags', 'primary'));
        });

        $create('orrery_settings', static function (Blueprint $table) use ($name, $exactly): void {
            $exactly($table->string('key', 255));
            $table->text('value')->nullable();

            $table->primary('key', $name('orrery_settings', 'primary'));
        });

        $create('orrery_worker_heartbeats', static function (Blueprint $table) use ($name): void {
            $table->string('worker_id', 255);
            $table->string('host', 191);
            $table->unsignedInteger('pid');
            $table->decimal('started_at', 16, 6);
            $table->unsignedInteger('ppid')->nullable();
            $table->decimal('parent_started_at', 16, 6)->nullable();
            $table->string('mode', 16);
            $table->string('connection', 191);
            $table->text('subscribed_queues');
            $table->string('current_queue', 255)->nullable();
            $table->text('options');
            $table->decimal('last_seen_at', 16, 6);

            $table->primary('worker_id', $name('orrery_worker_heartbeats', 'primary'));
            $table->index('last_seen_at', $name('orrery_worker_heartbeats', 'seen'));
            $table->index(['host', 'ppid'], $name('orrery_worker_heartbeats', 'host_ppid'));
        });

        $create('orrery_health_events', static function (Blueprint $table) use ($name): void {
            $table->bigIncrements('id');
            $table->string('context', 16);
            $table->string('process', 255);
            $table->decimal('started_at', 16, 6);
            $table->decimal('ended_at', 16, 6)->nullable();
            $table->unsignedInteger('dropped')->default(0);
            $table->unsignedInteger('unmatched_observations')->default(0);
            $table->unsignedInteger('unverified')->default(0);
            // A rollback after a deferred observation.
            $table->unsignedInteger('rollback_unverified')->default(0);
            $table->string('last_error_operation', 191)->nullable();
            $table->decimal('last_error_at', 16, 6)->nullable();

            $table->index('started_at', $name('orrery_health_events', 'started'));
            // One window per process and start.
            $table->unique(['process', 'started_at'], $name('orrery_health_events', 'window'));
        });

        $create('orrery_signals', static function (Blueprint $table) use ($name): void {
            $table->bigIncrements('id');
            // pause or terminate
            $table->string('signal', 20);
            // global, connection or queue
            $table->string('scope', 20);
            // '' for global, '<connection>' for connection, '<connection>:<queue>' for queue
            $table->string('scope_value', 191)->default('');
            $table->decimal('issued_at', 16, 6);
            // Keeps its dateTime type (an exception to the time rule).
            $table->dateTime('expires_at')->nullable();
            // The actor of a dashboard write (Orrery::actorUsing()), `cli` for a command, else null.
            $table->string('issued_by', 191)->nullable();

            $table->unique(['signal', 'scope', 'scope_value'], $name('orrery_signals', 'signal_scope'));
            $table->index('expires_at', $name('orrery_signals', 'expires'));
        });

        // The restart and deploy markers of the charts. Retention: 7 days.
        $create('orrery_markers', static function (Blueprint $table) use ($name): void {
            $table->bigIncrements('id');
            // restart or deploy
            $table->string('kind', 16);
            $table->decimal('at', 16, 6);
            $table->string('label', 191)->nullable();
            $table->string('source', 64);

            $table->unique(['kind', 'at', 'source'], $name('orrery_markers', 'kind_at_source'));
            $table->index('at', $name('orrery_markers', 'at'));
        });

        // Every per-minute number: counts and runtime per minute and series
        // (Monitoring\SampleCounts), and the queue state of a minute. `series` is the sha256 of
        // the JSON of the series identity, so two names that differ in any byte are two series.
        $create('orrery_samples', static function (Blueprint $table) use ($name): void {
            // total, queue or job
            $table->string('scope', 8);
            $table->char('series', 64);
            $table->string('connection', 191)->nullable();
            $table->string('queue', 255)->nullable();
            $table->string('name', 512)->nullable();
            // Integer epoch seconds at the minute boundary (an exception to the time rule).
            $table->unsignedBigInteger('minute');
            $table->unsignedInteger('completed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedBigInteger('runtime_ms_sum')->default(0);
            $table->unsignedInteger('runtime_count')->default(0);
            $table->unsignedInteger('ready')->nullable();
            $table->unsignedInteger('workers')->nullable();
            $table->decimal('oldest_age', 16, 6)->nullable();
            $table->boolean('over_limit')->nullable();
            // Integer epoch at a minute boundary (an exception to the time rule).
            $table->unsignedBigInteger('over_limit_since')->nullable();
            $table->boolean('over_limit_since_censored')->default(false);
            // The arrivals by pushed_at (schema step 7), last so that a fresh install and the
            // forward step agree. Null in a minute counted before the step: unknown, never 0.
            $table->unsignedInteger('pushed')->nullable();

            $table->primary(['scope', 'series', 'minute'], $name('orrery_samples', 'primary'));
            $table->index('minute', $name('orrery_samples', 'minute'));
        });

        // The operation ledger: the single authority of retry and forget.
        $create('orrery_operations', static function (Blueprint $table) use ($name): void {
            // 32 random hex characters: the retry's orreryGen and the fence key.
            $table->char('id', 32);
            // retry or forget
            $table->string('action', 8);
            // claimed, published, aborting, done or aborted
            $table->string('state', 12);
            // in_tx, before or none
            $table->string('mode', 8);
            $table->char('job_uuid', 36);
            $table->unsignedBigInteger('occurrence_id');
            $table->string('queue_connection', 191);
            $table->string('queue', 255);
            // The failed-record table (database-uuids only); the connection name is normalised.
            $table->string('record_connection', 191)->nullable();
            $table->string('record_table', 191)->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->char('record_uuid', 36)->nullable();
            // Mode before: the end of the delete window, in the failed-record database's clock.
            $table->decimal('record_deadline', 16, 6)->nullable();
            // Mode before: every column of the record as JSON, for the restore.
            $table->longText('saved_record')->nullable();
            $table->longText('payload')->nullable();
            $table->char('queue_fingerprint', 64);
            $table->char('record_fingerprint', 64)->nullable();
            // Retry: in the queue database's clock; forget: in the mirror database's clock.
            $table->decimal('deadline', 16, 6);
            // Always in the mirror database's clock.
            $table->decimal('next_check_at', 16, 6);
            // D17, D18: the next step needs an access to a host SQLite file that is not allowed automatically.
            $table->decimal('awaiting_since', 16, 6)->nullable();
            $table->string('awaiting_reason', 32)->nullable();
            $table->unsignedInteger('checks')->default(0);
            $table->unsignedBigInteger('result_job_id')->nullable();
            $table->string('error_code', 32)->nullable();
            $table->text('error')->nullable();
            $table->string('actor', 191)->nullable();
            // dashboard, batch, bulk or cli
            $table->string('source', 16);
            $table->decimal('created_at', 16, 6);
            $table->decimal('updated_at', 16, 6);
            // Schema step 8: the bulk run that created the operation; null for every other one.
            $table->char('bulk_ref', 32)->nullable();

            $table->primary('id', $name('orrery_operations', 'primary'));
            // The runner's selection: due operations in key order.
            $table->index(['state', 'next_check_at', 'id'], $name('orrery_operations', 'due'));
            $table->index(['job_uuid', 'occurrence_id', 'action', 'state'], $name('orrery_operations', 'occurrence'));
            // The ledger retention.
            $table->index(['created_at', 'id'], $name('orrery_operations', 'created'));
            $table->index(['awaiting_since', 'id'], $name('orrery_operations', 'awaiting'));
            // The ledger states of one bulk run (GET /api/bulk/{ref}).
            $table->index(['bulk_ref', 'state'], $name('orrery_operations', 'bulk'));
        });

        // The audit row of each bulk run (schema step 8): its frozen predicate, its totals,
        // the claims it made, and the outcome counters of every page that finished.
        $create(self::BULK_RUNS, static function (Blueprint $table) use ($name): void {
            // 32 random hex characters.
            $table->char('ref', 32);
            // retry or forget
            $table->string('action', 8);
            // The canonical JSON of the frozen source (keys sorted), and its sha256.
            $table->text('source');
            $table->char('source_hash', 64);
            // The global largest occurrence id, read before the members.
            $table->unsignedBigInteger('max_occurrence');
            // The frozen total: a count, and whether it is a lower bound.
            $table->unsignedInteger('total');
            $table->boolean('total_at_least')->default(false);
            // The total that the operator confirmed (a forget), else null.
            $table->unsignedInteger('confirmed_total')->nullable();
            // The claims that the services made for this run (each in its claim transaction).
            $table->unsignedInteger('claimed')->default(0);
            // The outcome counters: the sums of the answers of every page that finished.
            $table->unsignedInteger('accepted')->default(0);
            $table->unsignedInteger('refused')->default(0);
            $table->unsignedInteger('uncertain')->default(0);
            $table->unsignedInteger('not_member')->default(0);
            $table->unsignedInteger('already_done')->default(0);
            $table->unsignedInteger('not_confirmed')->default(0);
            $table->string('actor', 191)->nullable();
            $table->decimal('created_at', 16, 6);
            $table->decimal('last_run_at', 16, 6);

            $table->primary('ref', $name(self::BULK_RUNS, 'primary'));
            // The recent runs (GET /api/bulk) and the retention of the runs.
            $table->index(['last_run_at', 'ref'], $name(self::BULK_RUNS, 'last_run'));
        });

        // The stuck incidents (schema step 9): one row per job cycle and signal, created by
        // the tick phase `stuck_incidents` and delivered as StuckJobDetected at most 3 times.
        $create(self::INCIDENTS, static function (Blueprint $table) use ($name): void {
            $table->char('job_uuid', 36);
            $table->unsignedInteger('cycle');
            // A stuck signal (Monitoring\StuckSignals) or `unknown`.
            $table->string('signal', 24);
            $table->decimal('first_seen_at', 16, 6);
            // A JSON object of the signal's numeric facts and enum values, at most 1 KB.
            $table->text('evidence');
            // The delivery: the claims made (at most 3), the next claim, and its end.
            $table->unsignedInteger('attempts_made')->default(0);
            $table->decimal('next_attempt_at', 16, 6)->nullable();
            $table->decimal('delivered_at', 16, 6)->nullable();
            $table->decimal('delivery_failed_at', 16, 6)->nullable();
            // The notification state (step 10), last, as the forward step adds it.
            self::notifyColumns($table, $name(self::INCIDENTS, 'notify_due'));

            $table->primary(['job_uuid', 'cycle', 'signal'], $name(self::INCIDENTS, 'primary'));
            // The due incidents of the delivery.
            $table->index(['delivered_at', 'delivery_failed_at', 'attempts_made', 'next_attempt_at'], $name(self::INCIDENTS, 'due'));
        });

        // The cooldowns of the stuck-job notifications (step 10, Monitoring\StuckNotifications): one
        // row per (connection, queue, signal, channel), keyed by the sha256 of the byte-exact values.
        $create(self::NOTIFY_COOLDOWNS, static function (Blueprint $table) use ($name): void {
            $table->char('key', 64);
            // The values of the key, for the summary's text.
            $table->string('connection', 191);
            $table->string('queue', 255);
            $table->string('signal', 24);
            $table->string('channel', 64);
            // The end of the cooldown after the last message; the incidents held back since; the
            // tries of the pending summary.
            $table->decimal('cooldown_until', 16, 6);
            $table->unsignedInteger('held')->default(0);
            $table->unsignedInteger('summary_tries')->default(0);

            $table->primary('key', $name(self::NOTIFY_COOLDOWNS, 'primary'));
            // The summaries and the prune: the rows whose cooldown ended.
            $table->index(['cooldown_until', 'key'], $name(self::NOTIFY_COOLDOWNS, 'expiry'));
        });

        // The fence of the operations: every effect outside the mirror inserts its
        // row in the transaction of the effect, in the database where the effect happens.
        $create('orrery_fences', static function (Blueprint $table) use ($name): void {
            $table->char('operation_id', 32);
            // publish, delete or restore
            $table->string('slot', 8);
            // publish: published or cancelled; delete: deleted or delete_cancelled; restore: restored
            $table->string('state', 16);
            // publish: the published job id; restore: the restored record's new id
            $table->unsignedBigInteger('job_id')->nullable();
            // The operation's deadline in this database's clock.
            $table->decimal('deadline', 16, 6);
            // This database's clock.
            $table->decimal('created_at', 16, 6);

            $table->primary(['operation_id', 'slot'], $name('orrery_fences', 'primary'));
            // The fence retention's keyset position.
            $table->index(['created_at', 'operation_id', 'slot'], $name('orrery_fences', 'created'));
        });

        $create(self::MARKER, static function (Blueprint $table) use ($name): void {
            $table->unsignedTinyInteger('id');
            $table->unsignedInteger('version');
            $table->primary('id', $name(self::MARKER, 'primary'));
        });

        $extra = self::bound()->createForTesting;

        if ($extra !== null) {
            $extra($schema, $only);
        }
    }

    /**
     * The connection of the package migrations: their getConnection() only.
     * It is configuredConnection(). The migrator runs `--pretend` and the
     * migration's transaction on this connection, so every statement of
     * install() and uninstall() runs on it. It also records the default
     * connection at this moment: the migrator asks before runMethod() makes
     * the migration connection the default, so install() can decide the
     * refusal against the application's default (`--database` when given).
     */
    public static function migrationConnection(): ?string
    {
        self::bound()->migrationDefault = (string) Container::getInstance()->make('config')->get('database.default');

        return self::configuredConnection();
    }

    /**
     * CommandStarting and CommandFinished (StorageModule): the default
     * connection at the start of each running command, so a migration
     * decides against the application's default, also under
     * `migrate --database=<name>`.
     */
    public static function commandStarted(): void
    {
        self::bound()->commandDefaults[] = (string) Container::getInstance()->make('config')->get('database.default');
    }

    public static function commandFinished(): void
    {
        array_pop(self::bound()->commandDefaults);
    }

    /**
     * orrery.database when it names a connection, else null (the default
     * connection). An upgrade migration's up() passes it to upgradeTo().
     */
    public static function configuredConnection(): ?string
    {
        $configured = Container::getInstance()->make('config')->get('orrery.database');

        return is_string($configured) && $configured !== '' ? $configured : null;
    }

    /**
     * The connection object of the migration connection $wanted (null: the
     * default). Inside the migrator the default is the connection that it
     * pretends and runs its transaction on, with its PDO type (`<name>::direct`
     * when the connection has a `direct` key); when its base name is $wanted,
     * that object is used, never a second one of the plain name.
     */
    private static function migrationTarget(?string $wanted): Connection
    {
        $db = Container::getInstance()->make('db');
        $default = (string) $db->getDefaultConnection();

        if ($wanted === null || ConnectionName::base($db, $default) === ConnectionName::base($db, $wanted)) {
            /** @var Connection $connection */
            $connection = $db->connection($default);

            return $connection;
        }

        /** @var Connection $connection */
        $connection = $db->connection($wanted);

        return $connection;
    }

    /**
     * Runs $decision with database.default set to the default at the start
     * of the running Artisan command (commandStarted()), else the default
     * that migrationConnection() recorded (consumed here), so HostFiles resolves
     * the empty connection keys of the queue, failed-job, batch and cache
     * configuration to the application's default, not to the migration
     * connection.
     *
     * @template T
     *
     * @param  Closure(): T  $decision
     * @return T
     */
    private static function atMigrationDefault(Closure $decision): mixed
    {
        $schema = self::bound();
        $recorded = $schema->commandDefaults !== [] ? $schema->commandDefaults[array_key_last($schema->commandDefaults)] : $schema->migrationDefault;
        $schema->migrationDefault = null;
        $config = Container::getInstance()->make('config');
        $current = $config->get('database.default');

        if ($recorded === null || $recorded === '' || $recorded === $current) {
            return $decision();
        }

        $config->set('database.default', $recorded);

        try {
            return $decision();
        } finally {
            $config->set('database.default', $current);
        }
    }

    /**
     * The up() of the main migration. It refuses a mirror that the package
     * refuses at runtime (mirrorRefusal(): no package table goes into the
     * application's own SQLite file), decided, like the sibling, against the
     * default that migrationConnection() recorded before the migrator made
     * the migration connection the default. On the sibling it runs ensure(),
     * except under `migrate --pretend`: the sibling's session is not the
     * migration connection, so nothing could pretend there. On a server
     * mirror (the migration connection), by its marker:
     * - from BASELINE up to VERSION: nothing (installed; a second `migrate`
     *   from another migrations table). The upgrade migrations that
     *   follow bring an older marker forward;
     * - above VERSION: refused with downgradeHint();
     * - a marker table without a row: adoptUnmarked(), which refuses
     *   a dump of a newer release;
     * - no package table: create() and mark();
     * - anything else (package tables without the marker table, or a
     *   marker below BASELINE): refused with UPGRADE_STEPS.
     * Under `--pretend` every read answers nothing, so the pretend run shows
     * the statements of create() and mark().
     */
    public static function install(): void
    {
        $sessions = Container::getInstance()->make(PackageSession::class);
        [$refusal, $sibling] = self::atMigrationDefault(static fn (): array => [$sessions->mirrorRefusal(), $sessions->mirrorIsSibling()]);

        if ($refusal !== null) {
            throw new RuntimeException($refusal);
        }

        $mirror = self::migrationTarget(self::configuredConnection());

        if ($sibling) {
            if (! $mirror->pretending()) {
                self::ensure($sessions->mirror());
            }

            return;
        }

        $installed = self::markerVersion($mirror);
        $current = self::currentVersion();

        if ($installed !== null && $installed > $current) {
            throw new RuntimeException(self::downgradeHint($installed, $current));
        }

        if ($installed !== null && $installed >= self::BASELINE) {
            return;
        }

        if ($installed === null && self::adoptUnmarked($mirror, $current)) {
            return;
        }

        if ($installed === null && ! self::hasPackageTables($mirror)) {
            self::create($mirror->getSchemaBuilder());
            self::mark($mirror);

            return;
        }

        $marker = $installed === null ? 'no schema marker' : "schema version {$installed}";

        throw new RuntimeException("The Orrery schema on the connection {$mirror->getName()} ({$marker}) is from before Orrery 0.1. ".self::UPGRADE_STEPS);
    }

    /**
     * The down() of the main migration: drop() on a server mirror (the
     * migration connection, so `--pretend` drops nothing). The sibling stays
     * as it is: delete the file to remove it.
     */
    public static function uninstall(): void
    {
        $sessions = Container::getInstance()->make(PackageSession::class);

        if (self::atMigrationDefault(static fn (): bool => $sessions->mirrorIsSibling())) {
            return;
        }

        self::drop(self::migrationTarget(self::configuredConnection())->getSchemaBuilder());
    }

    /**
     * Writes the marker $version on a server mirror whose marker table holds
     * no row (a database loaded from a schema dump), after every forward
     * step above BASELINE up to $version (idempotent by rule). create()
     * builds the marker table last, so its presence proves a complete
     * schema. Returns false, and changes nothing, in every other state.
     * Postgres and SQLite run it in one transaction.
     */
    public static function adoptUnmarked(Connection $mirror, int $version): bool
    {
        if (! $mirror->getSchemaBuilder()->hasTable(self::MARKER) || $mirror->table(self::MARKER)->exists()) {
            return false;
        }

        $newer = self::newestRecordedUpgrade($mirror);
        $current = self::currentVersion();

        if ($newer !== null && $newer > $current) {
            throw new RuntimeException(self::downgradeHint($newer, $current));
        }

        self::runSteps($mirror, self::BASELINE, $version);

        return true;
    }

    /**
     * The highest N of an upgrade migration `*_upgrade_orrery_schema_to_v<N>`
     * that the migrations table on $mirror records, or null. A schema dump
     * keeps those rows, so a dump of a newer release is told apart from one
     * of this release. Only a migrations table on the mirror's own
     * connection is read: with the package tables on another connection, a
     * newer dump cannot be detected.
     */
    public static function newestRecordedUpgrade(Connection $mirror): ?int
    {
        $table = Container::getInstance()->make('config')->get('database.migrations');
        $table = is_array($table) ? ($table['table'] ?? 'migrations') : $table;
        $table = is_string($table) && $table !== '' ? $table : 'migrations';

        if (! $mirror->getSchemaBuilder()->hasTable($table)) {
            return null;
        }

        $newest = null;

        foreach ($mirror->table($table)->where('migration', 'like', '%upgrade_orrery_schema_to_v%')->pluck('migration') as $migration) {
            if (is_string($migration) && preg_match('/_upgrade_orrery_schema_to_v(\d+)$/D', $migration, $match) === 1) {
                $newest = max($newest ?? 0, (int) $match[1]);
            }
        }

        return $newest;
    }

    /**
     * The listener of Laravel's SchemaLoaded (`migrate` from a
     * `schema:dump` file, StorageModule): the dump keeps no row of the
     * marker table, so the loaded package schema gets its marker here
     * (adoptUnmarked()). Only for the server mirror's own connection.
     */
    public static function afterSchemaLoad(Connection $loaded): void
    {
        $container = Container::getInstance();

        try {
            $sessions = $container->make(PackageSession::class);

            if ($sessions->mirrorRefusal() !== null || $sessions->mirrorIsSibling()) {
                return;
            }

            $db = $container->make('db');
            $name = self::configuredConnection() ?? (string) $db->getDefaultConnection();

            if (ConnectionName::base($db, (string) $loaded->getName()) !== ConnectionName::base($db, $name)) {
                return;
            }

            self::adoptUnmarked($loaded, self::currentVersion());
        } catch (Throwable $error) {
            // Never stops the host's migrate: /api/health and orrery:install name the missing marker.
            $container->make(Watcher::class)->report('schema:after_load', $error);
        }
    }

    /**
     * Writes the marker row of VERSION.
     */
    public static function mark(Connection $connection): void
    {
        self::writeMarker($connection, self::currentVersion());
    }

    private static function writeMarker(Connection $connection, int $version): void
    {
        $connection->table(self::MARKER)->upsert([['id' => 1, 'version' => $version]], ['id'], ['version']);
    }

    /**
     * Drops every package table (the migration's down() on a connection
     * that is not the sibling), and the tables that an early build created
     * there (REMOVED_TABLES, RETIRED_TABLES): no forward step removes them,
     * so the pre-v0.1 refresh (UPGRADE_STEPS) is the one path that does.
     *
     * It refuses (a RuntimeException with UPGRADE_STEPS) while
     * orrery_operations holds an operation that is not terminal (`done` or
     * `aborted`): only the ledger can resolve its publication or delete.
     * The message lists the oldest LISTED_OPERATIONS of them
     * (id, action, state, awaiting reason, error code), so each can be
     * finished with `orrery:resolve <id>`.
     * `migrate:fresh` and `db:wipe` drop the tables without down() and so
     * bypass the guard.
     */
    public static function drop(Builder $schema): void
    {
        if (self::bound()->openOperationGuard && $schema->hasTable(Operations::TABLE)) {
            $open = $schema->getConnection()->table(Operations::TABLE)
                ->whereNotIn('state', Operations::TERMINAL)
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit(self::LISTED_OPERATIONS + 1)
                ->get(['id', 'action', 'state', 'awaiting_reason', 'error_code'])
                ->all();

            if ($open !== []) {
                throw new RuntimeException(self::openOperationsRefusal(array_values($open)));
            }
        }

        foreach ([...array_reverse(self::TABLES), ...self::REMOVED_TABLES, ...self::RETIRED_TABLES] as $table) {
            $schema->dropIfExists($table);
        }
    }

    /**
     * The refusal of drop(): the open operations (at most
     * LISTED_OPERATIONS, then "and more") and UPGRADE_STEPS.
     *
     * @param  list<stdClass>  $open
     */
    private static function openOperationsRefusal(array $open): string
    {
        $listed = [];

        foreach (array_slice($open, 0, self::LISTED_OPERATIONS) as $operation) {
            $details = [(string) $operation->action, (string) $operation->state];

            foreach (['awaiting_reason' => 'awaiting', 'error_code' => 'error'] as $column => $label) {
                if (is_string($operation->{$column}) && $operation->{$column} !== '') {
                    $details[] = $label.' '.$operation->{$column};
                }
            }

            $listed[] = (string) $operation->id.' ('.implode(', ', $details).')';
        }

        $more = count($open) > self::LISTED_OPERATIONS ? ', and more' : '';

        return 'Orrery refuses to drop its tables while operations are open: '.implode('; ', $listed).$more.'. '.self::UPGRADE_STEPS;
    }

    /**
     * The installed version of a session: null when the marker table or its
     * row is missing.
     */
    public static function installedVersion(Connection $session): ?int
    {
        try {
            $version = $session->table(self::MARKER)->where('id', 1)->value('version');
        } catch (Throwable $error) {
            if (self::isMissingTable($error)) {
                return null;
            }

            throw $error;
        }

        return is_numeric($version) ? (int) $version : null;
    }

    /**
     * installedVersion() inside a migration: the marker table is checked
     * first, because on Postgres a read of a missing table aborts the
     * migrator's transaction, and every later statement of the migration
     * then fails.
     */
    private static function markerVersion(Connection $mirror): ?int
    {
        return $mirror->getSchemaBuilder()->hasTable(self::MARKER) ? self::installedVersion($mirror) : null;
    }

    /**
     * The sibling's check when PackageSession first opens it in a process:
     * one marker read; ensure() when the marker is missing or older. A
     * higher version than VERSION, and a marker below BASELINE, are refused
     * (SiblingFileUnavailable) before the lock, so the watcher stays
     * degraded and nothing is written.
     */
    public static function ensureInstalled(Connection $session): void
    {
        $version = self::installedVersion($session);

        if ($version === self::currentVersion()) {
            return;
        }

        self::refuseSibling($session, $version);
        self::ensure($session, $version);
    }

    /**
     * Installs or upgrades the schema of the sibling under BEGIN EXCLUSIVE.
     * $known is the version that the caller read
     * before the lock; the marker is read again under the lock, and only
     * that read decides:
     * - no package table: create() builds the newest schema, and the marker
     *   is written;
     * - a marker below BASELINE, or package tables without a marker: refused
     *   (SiblingFileUnavailable, PRE_BASELINE_SIBLING);
     * - a marker from BASELINE up to below VERSION: every step above
     *   BASELINE up to VERSION runs in order (guarded, so a step that already
     *   ran changes nothing; a sibling that a build of another branch
     *   stamped gets that branch's missing objects too), then every table
     *   that is still missing is created, and the marker is written;
     * - the marker VERSION: nothing;
     * - a marker above VERSION: refused.
     */
    public static function ensure(Connection $session, ?int $known = null): void
    {
        Hooks::reach(self::BEFORE_LOCK);
        $sessions = PackageSession::class;
        $pdo = $session->getPdo();
        $sessions::record($sessions::BEGIN, 'BEGIN EXCLUSIVE', $session->getName());
        $pdo->exec('BEGIN EXCLUSIVE');

        try {
            // Another process may have finished first: then nothing to do.
            $version = self::installedVersion($session);
            $current = self::currentVersion();
            self::refuseSibling($session, $version);

            if ($version !== $current) {
                $schema = $session->getSchemaBuilder();
                $existing = array_filter(self::TABLES, static fn (string $table): bool => $schema->hasTable($table)) !== [];

                if ($existing && $version === null) {
                    throw new SiblingFileUnavailable((string) $session->getDatabaseName(), self::PRE_BASELINE_SIBLING);
                }

                // A brand-new sibling (no package table yet) needs no step:
                // create() below builds the current schema.
                if ($existing) {
                    foreach (self::stepsBetween(self::BASELINE, $current) as $step) {
                        $step($schema);
                    }
                }

                // Then every table that is still missing.
                $missing = array_values(array_filter(self::TABLES, static fn (string $table): bool => ! $schema->hasTable($table)));

                if ($missing !== []) {
                    self::create($schema, $missing);
                }

                self::mark($session);
            }

            $sessions::record($sessions::COMMIT, 'COMMIT', $session->getName());
            $pdo->exec('COMMIT');
        } catch (Throwable $error) {
            try {
                $sessions::record($sessions::ROLLBACK, 'ROLLBACK', $session->getName());
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // SQLite ended the transaction already.
            }

            throw $error;
        }
    }

    /**
     * Upgrades the mirror to schema version $version: the call of an upgrade
     * migration database/migrations/<date>_upgrade_orrery_schema_to_v<N>.php,
     * whose getConnection() is
     * `PackageSchema::migrationConnection()` (so `--pretend` and the
     * migration's transaction apply to the mirror), whose up() is
     * `PackageSchema::upgradeTo(PackageSchema::configuredConnection(), N)` and whose
     * down() does nothing (a forward step is not reversed, and the marker
     * stays N, so a later `migrate` runs the migration again as a no-op).
     * $connection is the mirror's connection name (null: the default
     * connection). In this order:
     * - $version above currentVersion(): refused (a published migration
     *   after a rollback of the code never writes its marker);
     * - a pretending connection (`migrate --pretend`): nothing, because
     *   every read answers nothing there;
     * - the sibling mirror: ensure() decides;
     * - a server mirror (every mirror that is not the sibling, also a SQLite
     *   file of its own named by orrery.database): a marker table without a
     *   row (a database loaded from a schema dump) gets every step above
     *   BASELINE up to $version and the marker $version (adoptUnmarked());
     *   no package table at all
     *   is refused with its own message (a changed orrery.database); no marker table, or a marker
     *   below BASELINE, is refused with UPGRADE_STEPS; a marker from BASELINE
     *   up to $version − 1 gets only the steps above it, up to $version, in
     *   order, then the marker $version; a marker from $version up to
     *   VERSION changes nothing; a marker above VERSION is refused.
     *
     * On Postgres and SQLite the steps and the marker run in one
     * transaction. MySQL and MariaDB commit each DDL statement implicitly, so
     * there the marker is written last: a failed run leaves the old marker,
     * and a rerun of `migrate` runs the idempotent steps again.
     */
    public static function upgradeTo(?string $connection, int $version): void
    {
        $current = self::currentVersion();

        if ($version > $current) {
            throw new RuntimeException("This Orrery migration (schema version {$version}) is newer than the installed Orrery code (version {$current}). Deploy the release that contains it.");
        }

        $sessions = Container::getInstance()->make(PackageSession::class);
        $mirror = self::migrationTarget($connection);

        // `migrate --pretend`: every read answers nothing, so no decision can be made.
        if ($mirror->pretending()) {
            return;
        }

        if ($sessions->mirrorIsSibling()) {
            self::ensure($sessions->mirror());

            return;
        }

        $installed = self::markerVersion($mirror);

        if ($installed === null && self::adoptUnmarked($mirror, $version)) {
            return;
        }

        if ($installed === null && ! self::hasPackageTables($mirror)) {
            throw new RuntimeException("The Orrery mirror connection {$mirror->getName()} holds no Orrery tables, so this migration has nothing to upgrade: "
                .'the package migration ran on another database before orrery.database changed. '
                .'Run php artisan migrate:refresh --path=database/migrations/2026_09_23_000000_create_orrery_tables.php --realpath to create them on this connection.');
        }

        if ($installed === null || $installed < self::BASELINE) {
            $marker = $installed === null ? 'no schema marker' : "schema version {$installed}";

            throw new RuntimeException("The Orrery schema on the connection {$mirror->getName()} ({$marker}) is from before Orrery 0.1, so this migration cannot upgrade it. ".self::UPGRADE_STEPS);
        }

        if ($installed > $current) {
            throw new RuntimeException(self::downgradeHint($installed, $current));
        }

        if ($installed >= $version) {
            return;
        }

        self::runSteps($mirror, $installed, $version);
    }

    /**
     * The forward steps above $from up to $to, then the marker $to. On
     * Postgres and SQLite in one transaction; MySQL and MariaDB commit each
     * DDL statement implicitly, so there the marker is written last.
     */
    private static function runSteps(Connection $mirror, int $from, int $to): void
    {
        $upgrade = static function () use ($mirror, $from, $to): void {
            $schema = $mirror->getSchemaBuilder();

            foreach (self::stepsBetween($from, $to) as $step) {
                $step($schema);
            }

            self::writeMarker($mirror, $to);
        };

        if (in_array($mirror->getDriverName(), ['mysql', 'mariadb'], true)) {
            $upgrade();
        } else {
            $mirror->transaction($upgrade);
        }
    }

    /** Whether the connection holds any package table. */
    public static function hasPackageTables(Connection $connection): bool
    {
        $schema = $connection->getSchemaBuilder();

        foreach (self::TABLES as $table) {
            if ($schema->hasTable($table)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The hint of a mirror whose schema is newer than this release (the code
     * was rolled back after a `migrate`).
     */
    public static function downgradeHint(int $installed, int $expected): string
    {
        return "The Orrery schema (version {$installed}) is newer than this release (version {$expected}): deploy the release that matches the installed schema.";
    }

    public static function newerHint(string $file): string
    {
        return "the Orrery schema of {$file} is newer than this release";
    }

    /**
     * The forward steps above $from up to $to, in version order.
     *
     * @return list<Closure(Builder): void>
     */
    private static function stepsBetween(int $from, int $to): array
    {
        $steps = self::upgrades();
        ksort($steps);

        return array_values(array_filter($steps, static fn (int $target): bool => $target > $from && $target <= $to, ARRAY_FILTER_USE_KEY));
    }

    /**
     * The sibling's refusals of a marker that the code cannot use: above
     * VERSION (newerHint()), or below BASELINE (PRE_BASELINE_SIBLING).
     */
    private static function refuseSibling(Connection $session, ?int $version): void
    {
        if ($version === null) {
            return;
        }

        $file = (string) $session->getDatabaseName();

        if ($version > self::currentVersion()) {
            throw new SiblingFileUnavailable($file, self::newerHint($file));
        }

        if ($version < self::BASELINE) {
            throw new SiblingFileUnavailable($file, self::PRE_BASELINE_SIBLING);
        }
    }

    /**
     * The operation columns of orrery_jobs: the operation that holds the row, and
     * the target of the bound failed record. Both nullable.
     *
     * @param  list<string>  $columns
     */
    private static function operationColumns(Blueprint $table, array $columns): void
    {
        if (in_array('operation_id', $columns, true)) {
            $table->char('operation_id', 32)->nullable();
        }

        if (in_array('provider_target', $columns, true)) {
            $table->char('provider_target', 64)->nullable();
        }
    }

    /**
     * The per-cycle counters and high-water columns, on the job row
     * and on each cycle summary.
     *
     * @return list<string>
     */
    private static function rowCounters(): array
    {
        return [
            'attempts', 'releases', 'exceptions', 'timeouts', 'reclaims', 'observed_attempts', 'late_outcomes',
            'last_outcome_attempt', 'last_exception_attempt', 'last_timeout_attempt', 'observed_gap',
        ];
    }

    private static function isMissingTable(Throwable $error): bool
    {
        $message = strtolower($error->getMessage());

        return str_contains($message, 'no such table') || str_contains($message, "doesn't exist") || str_contains($message, 'does not exist') || str_contains($message, 'undefined table');
    }

    /**
     * The application's instance (a container binding).
     */
    private static function bound(): self
    {
        return Container::getInstance()->make(self::class);
    }
}
