<?php

namespace Justrau\Orrery\Tests\Feature\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Justrau\Orrery\Database\Schema\IndexName;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Runtime\ErrorKinds;
use Justrau\Orrery\Storage\SiblingFileUnavailable;
use Justrau\Orrery\Tests\Support\DescribesPackageTables;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\MirrorOutage;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;

/**
 * The package schema. The mirror tables
 * (with the operation ledger and the fence) are on the mirror
 * connection. The publication fence table is on the database of each `database` queue
 * connection, with that connection's prefix.
 */
#[FreshSchema]
final class SchemaTest extends TestCase
{
    use DescribesPackageTables;

    /** The package tables on the mirror connection and their columns, in order. */
    private const MIRROR_TABLES = [
        'orrery_jobs' => [
            'id', 'uuid', 'cycle', 'cycle_gen', 'cycle_first_job_id', 'cycle_started_at', 'cycle_source',
            'occurrence_id', 'job_id', 'prev_job_id', 'job_id_unresolved', 'bulk_floor_id',
            'provider_id', 'batch_id', 'connection', 'queue', 'name',
            'type', 'status', 'silenced', 'monitored', 'monitor_version', 'tags_truncated', 'history_truncated',
            'history_discarded', 'retry_requested_at', 'payload', 'payload_bytes', 'max_tries', 'retry_until', 'max_exceptions',
            'timeout', 'fail_on_timeout', 'exception', 'exception_bytes', 'exception_class', 'exception_message',
            'failure_key', 'context', 'context_bytes', 'attempts', 'releases', 'exceptions', 'timeouts', 'reclaims',
            'observed_attempts', 'late_outcomes', 'last_outcome_attempt', 'last_exception_attempt',
            'last_timeout_attempt', 'observed_gap', 'retried_count', 'retried_count_inferred', 'worker_tries',
            'worker_timeout', 'worker_evidence_attempt', 'reserved_by', 'pushed_at', 'available_at', 'reserved_at',
            'finished_at', 'failed_at', 'retried_at', 'activity_at', 'absent_since', 'unknown_since',
            'unknown_reason', 'updated_at', 'runtime_ms', 'operation_id', 'provider_target',
        ],
        'orrery_job_tags' => ['tag', 'job_uuid', 'pushed_at'],
        'orrery_failed_tags' => ['tag', 'job_uuid', 'finished_at'],
        'orrery_job_history' => [
            'id', 'job_uuid', 'cycle', 'attempt', 'event', 'source', 'reason', 'dedupe_key', 'exception_class',
            'exception_message', 'exception', 'exception_bytes', 'context', 'context_bytes', 'occurred_at',
        ],
        'orrery_cycle_summaries' => [
            'job_uuid', 'cycle', 'cycle_gen', 'occurrence_id', 'outcome', 'merged', 'first_cycle', 'merged_cycles',
            'merged_retries', 'attempts', 'releases', 'exceptions', 'timeouts', 'reclaims', 'observed_attempts',
            'late_outcomes', 'last_outcome_attempt', 'last_exception_attempt', 'last_timeout_attempt',
            'observed_gap', 'history_truncated', 'exception_class', 'exception_message', 'max_tries',
            'max_exceptions', 'timeout', 'fail_on_timeout', 'retry_until', 'effective_tries',
            'effective_tries_source', 'effective_timeout', 'effective_timeout_source', 'started_at',
            'started_source', 'finished_at',
        ],
        'orrery_monitored_tags' => ['tag', 'created_at'],
        'orrery_settings' => ['key', 'value'],
        'orrery_worker_heartbeats' => [
            'worker_id', 'host', 'pid', 'started_at', 'ppid', 'parent_started_at', 'mode', 'connection',
            'subscribed_queues', 'current_queue', 'options', 'last_seen_at',
        ],
        'orrery_health_events' => [
            'id', 'context', 'process', 'started_at', 'ended_at', 'dropped', 'unmatched_observations',
            'unverified', 'rollback_unverified', 'last_error_operation', 'last_error_at',
        ],
        'orrery_signals' => ['id', 'signal', 'scope', 'scope_value', 'issued_at', 'expires_at', 'issued_by'],
        'orrery_markers' => ['id', 'kind', 'at', 'label', 'source'],
        // The per-minute samples; they also hold the queue state that orrery_queue_samples held.
        'orrery_samples' => [
            'scope', 'series', 'connection', 'queue', 'name', 'minute', 'completed', 'failed', 'runtime_ms_sum',
            'runtime_count', 'ready', 'workers', 'oldest_age', 'over_limit', 'over_limit_since', 'over_limit_since_censored',
            // Step 7: the arrivals, null in a minute counted before the step.
            'pushed',
        ],
        // The operation ledger and the fence.
        'orrery_operations' => [
            'id', 'action', 'state', 'mode', 'job_uuid', 'occurrence_id', 'queue_connection', 'queue',
            'record_connection', 'record_table', 'record_id', 'record_uuid', 'record_deadline', 'saved_record',
            'payload', 'queue_fingerprint', 'record_fingerprint', 'deadline', 'next_check_at', 'awaiting_since',
            'awaiting_reason', 'checks', 'result_job_id', 'error_code', 'error', 'actor', 'source', 'created_at',
            'updated_at',
            // Step 8: the bulk run that created the operation, null for every other operation.
            'bulk_ref',
        ],
        // Step 8: the audit row of each bulk run.
        'orrery_bulk_runs' => [
            'ref', 'action', 'source', 'source_hash', 'max_occurrence', 'total', 'total_at_least', 'confirmed_total',
            'claimed', 'accepted', 'refused', 'uncertain', 'not_member', 'already_done', 'not_confirmed', 'actor',
            'created_at', 'last_run_at',
        ],
        // Step 9: one incident per job cycle and stuck signal, with its delivery state.
        'orrery_incidents' => [
            'job_uuid', 'cycle', 'signal', 'first_seen_at', 'evidence', 'attempts_made', 'next_attempt_at',
            'delivered_at', 'delivery_failed_at',
            // Step 10: the notification state.
            'notified', 'notify_attempts', 'notify_next_at',
        ],
        // Step 10: the cooldowns of the stuck-job notifications.
        'orrery_notify_cooldowns' => ['key', 'connection', 'queue', 'signal', 'channel', 'cooldown_until', 'held', 'summary_tries'],
        'orrery_fences' => ['operation_id', 'slot', 'state', 'job_id', 'deadline', 'created_at'],
        'orrery_schema' => ['id', 'version'],
    ];

    /**
     * Every key of every package table: suffix => [kind, columns in order]. The suffix `primary`
     * is the primary key, whose name the engine decides. Every other key is named
     * IndexName::for(<connection>, <table>, <suffix>).
     */
    private const INDEXES = [
        'orrery_jobs' => [
            'primary' => ['primary', ['id']],
            'uuid' => ['unique', ['uuid']],
            'pushed' => ['index', ['pushed_at', 'id']],
            'st_pushed' => ['index', ['status', 'pushed_at', 'id']],
            'st_reserved' => ['index', ['status', 'reserved_at', 'id']],
            'st_sil_finished' => ['index', ['status', 'silenced', 'finished_at', 'id']],
            'st_finished' => ['index', ['status', 'finished_at', 'id']],
            'st_failed' => ['index', ['status', 'failed_at', 'id']],
            'failure' => ['index', ['status', 'failure_key', 'failed_at', 'id']],
            'failure_scope' => ['index', ['status', 'connection', 'queue', 'failure_key', 'failed_at', 'id']],
            'retention' => ['index', ['connection', 'queue', 'status', 'silenced', 'monitored', 'finished_at', 'id']],
            'cq_pushed' => ['index', ['connection', 'queue', 'status', 'pushed_at', 'id']],
            'cq_reserved' => ['index', ['connection', 'queue', 'status', 'reserved_at', 'id']],
            // Name_finished, name_pushed, name_reserved, gap, releases and attempts are
            // removed. No statement read them. A later feature adds an index together with its reader.
            'monitor_version' => ['index', ['monitor_version', 'id']],
            'batch' => ['index', ['batch_id', 'status', 'finished_at', 'id']],
            'reserved_by' => ['index', ['status', 'reserved_by']],
            'job_id' => ['index', ['job_id']],
            'occurrence' => ['index', ['occurrence_id']],
            'unresolved' => ['index', ['job_id_unresolved', 'bulk_floor_id', 'connection', 'queue', 'id']],
            // The deferred indexes of the stuck signals (a fresh install builds them).
            'st_attempts' => ['index', ['status', 'attempts', 'id']],
            'st_gap' => ['index', ['status', 'observed_gap', 'id']],
            // "Repeated releases" reads its own index, because releases can exceed attempts (two lineages after a reclaim).
            'st_releases' => ['index', ['status', 'releases', 'id']],
        ],
        'orrery_job_tags' => [
            'primary' => ['primary', ['tag', 'job_uuid']],
            'tag_pushed' => ['index', ['tag', 'pushed_at', 'job_uuid']],
            'job' => ['index', ['job_uuid']],
        ],
        'orrery_failed_tags' => [
            'primary' => ['primary', ['job_uuid', 'tag']],
            'tag_finished' => ['index', ['tag', 'finished_at', 'job_uuid']],
        ],
        'orrery_job_history' => [
            'primary' => ['primary', ['id']],
            'job_cycle' => ['index', ['job_uuid', 'cycle', 'id']],
            'dedupe' => ['unique', ['job_uuid', 'dedupe_key']],
        ],
        'orrery_cycle_summaries' => [
            'primary' => ['primary', ['job_uuid', 'cycle']],
            'gen' => ['index', ['job_uuid', 'cycle_gen']],
        ],
        'orrery_monitored_tags' => [
            'primary' => ['primary', ['tag']],
        ],
        'orrery_settings' => [
            'primary' => ['primary', ['key']],
        ],
        'orrery_worker_heartbeats' => [
            'primary' => ['primary', ['worker_id']],
            'seen' => ['index', ['last_seen_at']],
            'host_ppid' => ['index', ['host', 'ppid']],
        ],
        'orrery_health_events' => [
            'primary' => ['primary', ['id']],
            'started' => ['index', ['started_at']],
            'window' => ['unique', ['process', 'started_at']],
        ],
        'orrery_signals' => [
            'primary' => ['primary', ['id']],
            'signal_scope' => ['unique', ['signal', 'scope', 'scope_value']],
            'expires' => ['index', ['expires_at']],
        ],
        'orrery_markers' => [
            'primary' => ['primary', ['id']],
            'kind_at_source' => ['unique', ['kind', 'at', 'source']],
            'at' => ['index', ['at']],
        ],
        'orrery_samples' => [
            'primary' => ['primary', ['scope', 'series', 'minute']],
            'minute' => ['index', ['minute']],
        ],
        'orrery_operations' => [
            'primary' => ['primary', ['id']],
            'due' => ['index', ['state', 'next_check_at', 'id']],
            'occurrence' => ['index', ['job_uuid', 'occurrence_id', 'action', 'state']],
            'created' => ['index', ['created_at', 'id']],
            'awaiting' => ['index', ['awaiting_since', 'id']],
            // Step 8: the ledger states of one bulk run (GET /api/bulk/{ref}).
            'bulk' => ['index', ['bulk_ref', 'state']],
        ],
        'orrery_bulk_runs' => [
            'primary' => ['primary', ['ref']],
            // The recent runs (GET /api/bulk) and their retention.
            'last_run' => ['index', ['last_run_at', 'ref']],
        ],
        'orrery_incidents' => [
            'primary' => ['primary', ['job_uuid', 'cycle', 'signal']],
            // The due incidents of the delivery (StuckIncidents::deliver()).
            'due' => ['index', ['delivered_at', 'delivery_failed_at', 'attempts_made', 'next_attempt_at']],
            // The due notification rounds (StuckNotifications).
            'notify_due' => ['index', ['notify_next_at', 'job_uuid', 'cycle', 'signal']],
        ],
        'orrery_notify_cooldowns' => [
            'primary' => ['primary', ['key']],
            // The summaries and the prune.
            'expiry' => ['index', ['cooldown_until', 'key']],
        ],
        'orrery_fences' => [
            'primary' => ['primary', ['operation_id', 'slot']],
            'created' => ['index', ['created_at', 'operation_id', 'slot']],
        ],
        'orrery_schema' => [
            'primary' => ['primary', ['id']],
        ],
    ];

    /** The complete exception list of the time rule: these time columns are not decimal epoch seconds. */
    private const TIME_EXCEPTIONS = [
        'orrery_jobs.retry_until' => 'integer',
        'orrery_cycle_summaries.retry_until' => 'integer',
        'orrery_signals.expires_at' => 'datetime',
        'orrery_samples.over_limit_since' => 'integer',
    ];

    /** type_name values per driver and type family, as Laravel's schema builder reports them. */
    private const TYPE_NAMES = [
        'decimal' => ['sqlite' => 'numeric', 'mysql' => 'decimal', 'pgsql' => 'numeric'],
        'integer' => ['sqlite' => 'integer', 'mysql' => 'bigint', 'pgsql' => 'int8'],
        'datetime' => ['sqlite' => 'datetime', 'mysql' => 'datetime', 'pgsql' => 'timestamp'],
    ];

    /**
     * Rejects: a missing or misspelt column, a table of the old schema, or a mirror table on the queue connection.
     */
    public function test_every_table_and_column_exists_on_the_mirror_connection(): void
    {
        foreach (self::MIRROR_TABLES as $table => $columns) {
            $this->assertTrue($this->mirrorSchema()->hasTable($table), "{$table} is missing on the mirror connection");
            $this->assertSame($columns, $this->mirrorSchema()->getColumnListing($table), "the columns of {$table}");
        }

        $expected = array_keys(self::MIRROR_TABLES);

        if ($this->testEnvironment()->separate() || $this->testEnvironment()->siblingDatabase() !== null) {
            // A separate mirror database, or the sibling SQLite mirror file: the main migration writes
            // nothing to the queue's database; orrery:install --fence gives it its fence table.
            $this->assertSame([], MirrorOutage::packageTables($this->queueConnection()), 'the queue connection holds a package table');
        }

        sort($expected);

        $this->assertSame($expected, MirrorOutage::packageTables($this->mirror()), 'the package tables on the mirror connection');
    }

    /**
     * Rejects: a wrong column order in a key, a missing or additional key, or a unique key where the spec has a primary key.
     */
    public function test_every_index_has_the_listed_columns_in_order(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            $connection = $this->mirror();

            $this->assertSame(
                $this->expectedIndexes($connection, $table, $indexes),
                $this->actualIndexes($connection, $table),
                "the keys of {$table}",
            );
        }
    }

    /**
     * Rejects: a create with the keys inside `create table` (MySQL and MariaDB, task S-F) that
     * builds other tables than Laravel's separate key statements: another key kind, name, column
     * order, prefix length, visibility or index type, another column, or another engine, charset,
     * collation, row format or table option; and a MySQL create that still runs one statement per
     * key. On the other engines both forms are the same statements, so their schemas match too.
     * Still passes: a difference that neither SHOW CREATE TABLE nor the catalog rows read here
     * show (for example the internal order of the index ids).
     */
    public function test_the_inline_keys_build_the_tables_of_separate_key_statements(): void
    {
        $connection = $this->mirror();
        $schema = $this->mirrorSchema();
        $separate = $this->createdStatements($connection, true);
        $inline = $this->createdStatements($connection, false);

        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->assertCount(count(PackageSchema::TABLES), $inline, 'one statement per table on MySQL');
            $this->assertGreaterThan(count(PackageSchema::TABLES), count($separate));
        } else {
            $this->assertSame($separate, $inline);
        }

        try {
            PackageSchema::drop($schema);
            PackageSchema::useSeparateKeysForTesting(true);
            PackageSchema::create($schema);
            $before = $this->describeTables($connection);

            PackageSchema::drop($schema);
            PackageSchema::useSeparateKeysForTesting(false);
            PackageSchema::create($schema);
            $after = $this->describeTables($connection);
        } finally {
            PackageSchema::useSeparateKeysForTesting(false);

            if (! $schema->hasTable(PackageSchema::MARKER)) {
                PackageSchema::drop($schema);
                PackageSchema::create($schema);
            }

            PackageSchema::mark($connection);
        }

        $this->assertCount(count(PackageSchema::TABLES), $before['tables'], 'every package table is described');
        $this->assertSame($before, $after);
    }

    /**
     * Rejects: no key without `status`, so the count of recent jobs needs one statement per status.
     */
    public function test_recent_jobs_key_covers_pushed_at_and_id(): void
    {
        $name = IndexName::for($this->mirror(), 'orrery_jobs', 'pushed');
        $indexes = [];

        foreach ($this->mirrorSchema()->getIndexes('orrery_jobs') as $index) {
            $indexes[$index['name']] = $index;
        }

        $this->assertArrayHasKey($name, $indexes, 'orrery_jobs has no key (pushed_at, id)');
        $this->assertSame(['pushed_at', 'id'], $indexes[$name]['columns']);
        $this->assertFalse($indexes[$name]['unique']);
        $this->assertFalse($indexes[$name]['primary']);
    }

    /**
     * orrery_jobs.connection, orrery_jobs.queue and orrery_settings.key
     * compare byte for byte on every engine: the
     * retention partitions, their cursors and the overrides key on them.
     *
     * Rejects: a MySQL or MariaDB column with a case-insensitive or PAD
     * SPACE collation (utf8mb4_unicode_ci, or utf8mb4_bin, which still
     * ignores trailing spaces): the three settings keys collide on the
     * primary key, and the queue filter returns the folded names.
     */
    public function test_partition_names_and_settings_keys_compare_byte_for_byte(): void
    {
        $mirror = $this->mirror();
        $mirror->table('orrery_settings')->insert([['key' => 'probe', 'value' => '1'], ['key' => 'PROBE', 'value' => '2'], ['key' => 'probe ', 'value' => '3']]);
        $this->assertSame('3', (string) $mirror->table('orrery_settings')->where('key', 'probe ')->value('value'));

        foreach (['emails', 'Emails', 'emails ', 'émails'] as $i => $queue) {
            $mirror->table('orrery_jobs')->insert(['uuid' => sprintf('00000000-0000-4000-8000-%012d', $i), 'connection' => $i === 0 ? 'db' : 'DB', 'queue' => $queue, 'status' => 'completed', 'payload' => '{}', 'payload_bytes' => 2, 'pushed_at' => '1.000000', 'updated_at' => '1.000000']);
        }

        $this->assertSame(['emails'], $mirror->table('orrery_jobs')->where('queue', 'emails')->pluck('queue')->all());
        $this->assertSame(['db'], $mirror->table('orrery_jobs')->where('connection', 'db')->pluck('connection')->all());

        if (in_array($mirror->getDriverName(), ['mysql', 'mariadb'], true)) {
            $expected = PackageSchema::byteExactCollation($mirror);
            // utf8mb4_bin only on a MySQL server without utf8mb4_0900_bin (before 8.0.17): exact through the residual filters.
            $this->assertContains($expected, ['utf8mb4_0900_bin', 'utf8mb4_nopad_bin', 'utf8mb4_bin']);

            foreach ([['orrery_jobs', 'connection'], ['orrery_jobs', 'queue'], ['orrery_settings', 'key']] as [$table, $column]) {
                $row = (array) $mirror->selectOne('select collation_name as c from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?', [$mirror->getTablePrefix().$table, $column]);
                $this->assertSame($expected, $row['c'] ?? $row['C'] ?? null, "{$table}.{$column}");
            }
        }
    }

    /**
     * Rejects: Laravel's default key names, which exceed 64 characters for the composite keys.
     */
    public function test_index_names_fit_64_characters_with_a_16_character_prefix(): void
    {
        $prefix = str_repeat('p', 16);

        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $suffix) {
                $name = IndexName::compose($prefix, $table, $suffix);

                $this->assertSame($prefix.$table.'_'.$suffix, $name, "the name of {$table} {$suffix} was shortened");
                $this->assertLessThanOrEqual(64, strlen($name), "{$name} is longer than 64 characters");
            }

            $connection = $this->mirror();
            $ownPrefix = strlen($connection->getTablePrefix());

            foreach ($connection->getSchemaBuilder()->getIndexes($table) as $index) {
                if ($index['primary']) {
                    continue;
                }

                $this->assertLessThanOrEqual(
                    64,
                    strlen($index['name']) - $ownPrefix + 16,
                    "the key {$index['name']} of {$table} is longer than 64 characters with a 16-character prefix",
                );
            }
        }
    }

    /**
     * Rejects: a `timestamp` column, or a decimal `retry_until` that the integer payload value does not fit.
     */
    public function test_time_columns_are_decimal_epoch_seconds(): void
    {
        // MariaDB reports the types of MySQL.
        $driver = $this->mirror()->getDriverName() === 'mariadb' ? 'mysql' : $this->mirror()->getDriverName();
        $checked = [];

        foreach (array_keys(self::MIRROR_TABLES) as $table) {
            foreach ($this->mirror()->getSchemaBuilder()->getColumns($table) as $column) {
                if (preg_match('/_(at|since|until|deadline)$/', $column['name']) !== 1) {
                    continue;
                }

                $key = $table.'.'.$column['name'];
                $family = self::TIME_EXCEPTIONS[$key] ?? 'decimal';
                $checked[] = $key;

                $this->assertSame(self::TYPE_NAMES[$family][$driver], $column['type_name'], "{$key} is not a {$family} column ({$column['type']})");

                if ($family === 'decimal' && $driver !== 'sqlite') {
                    $this->assertStringEndsWith('(16,6)', $column['type'], "{$key} is not decimal(16,6)");
                }
            }
        }

        foreach (array_keys(self::TIME_EXCEPTIONS) as $exception) {
            $this->assertContains($exception, $checked, "the exception {$exception} names no column");
        }
    }

    /**
     * Rejects: a `down()` that keeps a fence table or a mirror table, also
     * the two tables of the old retry protocol on a mirror that an older
     * release migrated (MySQL and Postgres run no forward step: there the
     * rollback is the one path that removes them).
     */
    public function test_rollback_drops_every_package_table(): void
    {
        $migrations = $this->migrations();

        if ($this->testEnvironment()->siblingDatabase() === null) {
            foreach (PackageSchema::REMOVED_TABLES as $removed) {
                $this->mirrorSchema()->create($removed, static function (Blueprint $table): void {
                    $table->bigIncrements('id');
                });
            }
        }

        try {
            foreach (array_reverse($migrations) as $migration) {
                $migration->down();
            }

            if ($this->testEnvironment()->siblingDatabase() === null) {
                $this->assertSame([], MirrorOutage::packageTables($this->mirror()), 'a package table is left on the mirror connection');
            } else {
                // down() leaves the sibling SQLite mirror file as it is.
                $this->assertContains('orrery_jobs', MirrorOutage::packageTables($this->mirror()), 'the rollback dropped a table of the sibling file');
            }

            $this->assertSame([], MirrorOutage::packageTables($this->queueConnection()), 'a package table is left on the queue connection');
        } finally {
            foreach ($migrations as $migration) {
                $migration->up();
            }
        }
    }

    /**
     * Rejects: a unique key instead of a primary key on `orrery_job_tags` or `orrery_failed_tags`.
     */
    public function test_migrations_run_with_sql_require_primary_key(): void
    {
        if ($this->mirror()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('sql_require_primary_key is a MySQL setting (Review Focus 1). This run uses '.$this->mirror()->getDriverName().'.');
        }

        $migrations = $this->migrations();
        $connections = [$this->mirror(), $this->queueConnection()];

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }

        try {
            foreach ($connections as $connection) {
                $connection->statement('SET SESSION sql_require_primary_key = 1');
                $this->assertSame(1, (int) $connection->scalar('SELECT @@SESSION.sql_require_primary_key'));
            }

            foreach ($migrations as $migration) {
                $migration->up();
            }
        } finally {
            foreach ($connections as $connection) {
                $connection->statement('SET SESSION sql_require_primary_key = 0');
            }
        }

        foreach (array_keys(self::MIRROR_TABLES) as $table) {
            $this->assertTrue($this->mirrorSchema()->hasTable($table), "{$table} was not created");
        }
    }

    private function queueConnection(): Connection
    {
        /** @var Connection $connection */
        $connection = $this->app['db']->connection(TestEnvironment::PRIMARY);

        return $connection;
    }

    /**
     * @param  array<string, array{0: string, 1: list<string>}>  $indexes
     * @return array<string, string>
     */
    private function expectedIndexes(Connection $connection, string $table, array $indexes): array
    {
        $expected = [];

        foreach ($indexes as $suffix => [$kind, $columns]) {
            $name = $suffix === 'primary' ? 'primary' : IndexName::for($connection, $table, $suffix);
            $expected[$name] = $kind.' ('.implode(', ', $columns).')';
        }

        ksort($expected);

        return $expected;
    }

    /**
     * The statements of PackageSchema::create() on this connection, without running them.
     *
     * @return list<string>
     */
    private function createdStatements(Connection $connection, bool $separate): array
    {
        PackageSchema::useSeparateKeysForTesting($separate);

        try {
            return array_column($connection->pretend(static function () use ($connection): void {
                PackageSchema::create($connection->getSchemaBuilder());
            }), 'query');
        } finally {
            PackageSchema::useSeparateKeysForTesting(false);
        }
    }

    /**
     * The keys of a table: name => "kind (columns)". Every primary key entry is named `primary`,
     * because the engine names it. SQLite reports a primary key on a non-integer column twice.
     *
     * @return array<string, string>
     */
    private function actualIndexes(Connection $connection, string $table): array
    {
        $actual = [];
        $primaries = [];

        foreach ($connection->getSchemaBuilder()->getIndexes($table) as $index) {
            $columns = implode(', ', $index['columns']);

            if ($index['primary']) {
                $primaries[$columns] = 'primary ('.$columns.')';

                continue;
            }

            $actual[$index['name']] = ($index['unique'] ? 'unique' : 'index').' ('.$columns.')';
        }

        if ($primaries !== []) {
            $actual['primary'] = implode(' and ', $primaries);
        }

        ksort($actual);

        return $actual;
    }

    /**
     * Rejects: an ensure() that creates the missing tables before the
     * forward steps run. The fixture is the baseline
     * release (marker BASELINE) as a later version BASELINE + 1 would find
     * it: no orrery_markers table, no orrery_signals.issued_by column, and
     * its last deploy time in orrery_settings. The test step (BASELINE + 1, through the seam) is
     * idempotent: it adds the column when it is missing, and creates
     * orrery_markers and moves the deploy time into it only when the table
     * is missing. With the steps first, the upgraded sibling has the
     * schema of a new one and the moved row; with the tables first, the
     * step finds the table already there and the deploy time is lost.
     * Still passes: a step that is not idempotent, as long as this fixture
     * does not need it.
     */
    public function test_an_upgraded_sibling_equals_the_migrated_schema(): void
    {
        $files = [SqliteFiles::create(), SqliteFiles::create()];
        $config = $this->app['config'];

        try {
            foreach (['upgrade_old' => $files[0], 'upgrade_new' => $files[1]] as $name => $file) {
                $config->set("database.connections.{$name}", ['driver' => 'sqlite', 'url' => null, 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false]);
            }

            $old = $this->app['db']->connection('upgrade_old');
            PackageSchema::ensure($old);

            // Make it the older release.
            $oldSchema = $old->getSchemaBuilder();
            $oldSchema->drop('orrery_markers');
            $oldSchema->table('orrery_signals', static function (Blueprint $table): void {
                $table->dropColumn('issued_by');
            });
            $old->table('orrery_settings')->insert(['key' => 'fixture_last_deploy', 'value' => '1700000000.5']);
            $old->table(PackageSchema::MARKER)->update(['version' => PackageSchema::BASELINE]);
            $next = PackageSchema::BASELINE + 1;

            PackageSchema::useUpgradesForTesting([$next => static function (Builder $schema): void {
                // Guarded for every start: an older sibling, this version, or a new empty file.
                if ($schema->hasTable('orrery_signals') && ! $schema->hasColumn('orrery_signals', 'issued_by')) {
                    $schema->table('orrery_signals', static function (Blueprint $table): void {
                        $table->string('issued_by', 191)->nullable();
                    });
                }

                if ($schema->hasTable('orrery_settings') && ! $schema->hasTable('orrery_markers')) {
                    PackageSchema::create($schema, ['orrery_markers']);
                    $connection = $schema->getConnection();
                    $at = $connection->table('orrery_settings')->where('key', 'fixture_last_deploy')->value('value');

                    if ($at !== null) {
                        $connection->table('orrery_markers')->insert(['kind' => 'deploy', 'at' => $at, 'label' => null, 'source' => 'orrery:deployed']);
                        $connection->table('orrery_settings')->where('key', 'fixture_last_deploy')->delete();
                    }
                }
            }], $next);

            PackageSchema::ensure($old, PackageSchema::BASELINE);
            $new = $this->app['db']->connection('upgrade_new');
            PackageSchema::ensure($new);

            self::assertSame($next, PackageSchema::installedVersion($old));
            self::assertSame($next, PackageSchema::installedVersion($new));
            self::assertSame($this->sqliteSchema($new), $this->sqliteSchema($old), 'The upgraded sibling has the schema of a new one.');
            self::assertSame([['deploy', '1700000000.500000', 'orrery:deployed']], array_map(static fn (object $row): array => [(string) $row->kind, number_format((float) $row->at, 6, '.', ''), (string) $row->source], $old->table('orrery_markers')->get()->all()), 'The step moved the deploy time.');
            self::assertNull($old->table('orrery_settings')->where('key', 'fixture_last_deploy')->value('value'));
        } finally {
            PackageSchema::useUpgradesForTesting(null);
            $this->app['db']->purge('upgrade_old');
            $this->app['db']->purge('upgrade_new');

            foreach ($files as $file) {
                SqliteFiles::remove($file);
            }
        }
    }

    /**
     * Rejects: a sibling from before Orrery 0.1 that ensure() brings
     * forward instead of refusing it (package tables without a marker row, or a marker below
     * BASELINE), a refusal that changes the file, a refusal without the delete-and-rebuild hint,
     * and an ensure() that runs only the steps above the stored version: a
     * sibling that a build of another branch stamped BASELINE + 1 without that version's column
     * (it had a different step) gets every step above BASELINE. Two guarded test steps
     * (through the seam): BASELINE + 1 adds orrery_signals.issued_by, BASELINE + 2 adds
     * orrery_markers.label. Running a step twice changes nothing.
     * Still passes: an unguarded step on a sibling that already has its object fails the whole
     * upgrade, which is the step author's guard to add.
     */
    public function test_a_pre_baseline_sibling_is_refused_and_a_mis_stamped_one_gets_every_step(): void
    {
        $file = SqliteFiles::create();
        $this->app['config']->set('database.connections.upgrade_steps', ['driver' => 'sqlite', 'url' => null, 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false]);
        $column = static function (string $table, string $name): \Closure {
            return static function (Builder $schema) use ($table, $name): void {
                if ($schema->hasTable($table) && ! $schema->hasColumn($table, $name)) {
                    $schema->table($table, static function (Blueprint $blueprint) use ($name): void {
                        $blueprint->string($name, 191)->nullable();
                    });
                }
            };
        };
        $drop = static function (Connection $session, string $table, string $name): void {
            $session->getSchemaBuilder()->table($table, static function (Blueprint $blueprint) use ($name): void {
                $blueprint->dropColumn($name);
            });
        };
        $baseline = PackageSchema::BASELINE;

        try {
            $session = $this->app['db']->connection('upgrade_steps');
            PackageSchema::ensure($session);
            PackageSchema::useUpgradesForTesting([$baseline + 1 => $column('orrery_signals', 'issued_by'), $baseline + 2 => $column('orrery_markers', 'label')], $baseline + 2);
            $drop($session, 'orrery_signals', 'issued_by');

            // The marker row is lost (a schema from before the marker), or the marker is below BASELINE.
            foreach ([null, $baseline - 1, 1] as $marker) {
                $marker === null
                    ? $session->table(PackageSchema::MARKER)->delete()
                    : $session->table(PackageSchema::MARKER)->upsert([['id' => 1, 'version' => $marker]], ['id'], ['version']);
                $label = $marker === null ? 'no marker' : "marker {$marker}";

                try {
                    PackageSchema::ensure($session);
                    self::fail("A sibling with {$label} was brought forward.");
                } catch (SiblingFileUnavailable $refused) {
                    self::assertSame("Orrery cannot use its SQLite mirror file {$file}: ".PackageSchema::PRE_BASELINE_SIBLING.'.', $refused->getMessage(), $label);
                    self::assertTrue(ErrorKinds::isDependency($refused), 'The refusal opens the breaker.');
                }

                self::assertSame($marker, PackageSchema::installedVersion($session), "The refusal kept the {$label}.");
                self::assertFalse($session->getSchemaBuilder()->hasColumn('orrery_signals', 'issued_by'), "No step ran on a sibling with {$label}.");
            }

            // Stamped BASELINE + 1 by a build whose step was another branch's: that column is missing.
            $drop($session, 'orrery_markers', 'label');
            $session->table(PackageSchema::MARKER)->update(['version' => $baseline + 1]);
            PackageSchema::ensure($session);
            self::assertTrue($session->getSchemaBuilder()->hasColumn('orrery_signals', 'issued_by'), 'A mis-stamped sibling gets the steps at or below its stamp.');
            self::assertTrue($session->getSchemaBuilder()->hasColumn('orrery_markers', 'label'));
            self::assertSame($baseline + 2, PackageSchema::installedVersion($session));

            // A marker at BASELINE gets both steps; a rerun changes nothing.
            $drop($session, 'orrery_signals', 'issued_by');
            $drop($session, 'orrery_markers', 'label');
            $session->table(PackageSchema::MARKER)->update(['version' => $baseline]);
            PackageSchema::ensure($session);
            PackageSchema::ensure($session);
            self::assertTrue($session->getSchemaBuilder()->hasColumn('orrery_signals', 'issued_by'));
            self::assertTrue($session->getSchemaBuilder()->hasColumn('orrery_markers', 'label'));
            self::assertSame($baseline + 2, PackageSchema::installedVersion($session));
        } finally {
            PackageSchema::useUpgradesForTesting(null);
            $this->app['db']->purge('upgrade_steps');
            SqliteFiles::remove($file);
        }
    }

    /**
     * Rejects: a registered step that is not a no-op where it has nothing
     * to do, and an ensure() that runs the steps on a brand-new sibling.
     * Every step of the real upgrades() runs on an empty file
     * and on a freshly created sibling without an error and without a
     * change (an unguarded test step proves that the rule sees a change);
     * and ensure() on an empty file runs no step at all (a test step that
     * throws).
     */
    public function test_every_registered_step_is_a_no_op(): void
    {
        $files = [SqliteFiles::create(), SqliteFiles::create(), SqliteFiles::create()];
        $config = $this->app['config'];

        try {
            foreach (['steps_empty', 'steps_fresh', 'steps_new'] as $index => $name) {
                $config->set("database.connections.{$name}", ['driver' => 'sqlite', 'url' => null, 'database' => $files[$index], 'prefix' => '', 'foreign_key_constraints' => false]);
            }

            $empty = $this->app['db']->connection('steps_empty');
            $fresh = $this->app['db']->connection('steps_fresh');
            PackageSchema::ensure($fresh);

            self::assertSame([], $this->changingSteps(PackageSchema::upgrades(), [$empty, $fresh]), 'A registered step changed a schema that already is current, or empty.');

            // The rule itself: a step that is not guarded changes the current schema, and is named.
            $unguarded = [PackageSchema::currentVersion() + 1 => static function (Builder $schema): void {
                if ($schema->hasTable('orrery_signals')) {
                    $schema->table('orrery_signals', static function (Blueprint $table): void {
                        $table->string('unguarded_'.bin2hex(random_bytes(4)), 16)->nullable();
                    });
                }
            }];
            self::assertSame([PackageSchema::currentVersion() + 1], $this->changingSteps($unguarded, [$fresh]));
            $fresh->getSchemaBuilder()->drop('orrery_signals');
            PackageSchema::create($fresh->getSchemaBuilder(), ['orrery_signals']);

            PackageSchema::useUpgradesForTesting([PackageSchema::currentVersion() + 1 => static function (): void {
                throw new \RuntimeException('A step ran on a brand-new sibling.');
            }], PackageSchema::currentVersion() + 1);
            $new = $this->app['db']->connection('steps_new');
            PackageSchema::ensure($new);
            self::assertSame(PackageSchema::currentVersion(), PackageSchema::installedVersion($new));
            PackageSchema::useUpgradesForTesting(null);
            self::assertSame($this->sqliteSchema($fresh), $this->sqliteSchema($new), 'The new sibling has the current schema.');
        } finally {
            PackageSchema::useUpgradesForTesting(null);

            foreach (['steps_empty', 'steps_fresh', 'steps_new'] as $name) {
                $this->app['db']->purge($name);
            }

            foreach ($files as $file) {
                SqliteFiles::remove($file);
            }
        }
    }

    /**
     * The versions of the steps that changed one of the schemas (each schema is compared before
     * and after every step).
     *
     * @param  array<int, \Closure(Builder): void>  $steps
     * @param  list<Connection>  $sessions
     * @return list<int>
     */
    private function changingSteps(array $steps, array $sessions): array
    {
        $changing = [];

        foreach ($sessions as $session) {
            foreach ($steps as $target => $step) {
                $before = $this->sqliteSchema($session);
                $step($session->getSchemaBuilder());

                if ($before !== $this->sqliteSchema($session)) {
                    $changing[] = $target;
                }
            }
        }

        return array_values(array_unique($changing));
    }

    /**
     * The tables of a SQLite file with their columns and indexes, without
     * the order of the columns (an added column goes last).
     *
     * @return array<string, array{columns: list<list<mixed>>, indexes: list<list<mixed>>}>
     */
    private function sqliteSchema(Connection $connection): array
    {
        $pdo = $connection->getPdo();
        $schema = [];

        foreach ($pdo->query("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name")->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $columns = array_map(static fn (array $column): array => [$column['name'], strtolower((string) $column['type']), (int) $column['notnull'], $column['dflt_value'], (int) $column['pk']], $pdo->query("pragma table_info('{$table}')")->fetchAll(\PDO::FETCH_ASSOC));
            sort($columns);
            $indexes = [];

            foreach ($pdo->query("pragma index_list('{$table}')")->fetchAll(\PDO::FETCH_ASSOC) as $index) {
                $indexes[] = [$index['name'], (int) $index['unique'], array_column($pdo->query("pragma index_info('{$index['name']}')")->fetchAll(\PDO::FETCH_ASSOC), 'name')];
            }

            sort($indexes);
            $schema[(string) $table] = ['columns' => $columns, 'indexes' => $indexes];
        }

        return $schema;
    }

    /**
     * The package migrations, in the order in which `migrate` runs them.
     *
     * @return list<Migration>
     */
    private function migrations(): array
    {
        $files = glob(dirname(__DIR__, 3).'/database/migrations/*.php') ?: [];
        sort($files);

        $this->assertNotSame([], $files, 'the package has no migrations');

        return array_map(static fn (string $file): Migration => require $file, $files);
    }
}
