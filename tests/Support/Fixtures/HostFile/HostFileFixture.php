<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\HostFile;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * The application SQLite file of the D18 process tests:
 * the connection `app` on the file, the queue connection
 * `app_queue`, and an optional second WAL file with `second` and
 * `second_queue`. The parent test and the worker CLI (HostFileProvider)
 * build the same configuration from the same values.
 *
 * The failed jobs, the batches and the cache store go to the file only in
 * useHostTables(): the worker CLI checks the failed-job and batch
 * configuration against the harness before a command starts, so the
 * provider applies them when the command starts.
 */
final class HostFileFixture
{
    public const CONNECTION = 'app';

    public const QUEUE = 'app_queue';

    public const ALIAS = 'app_alias';

    public const SECOND = 'second';

    public const SECOND_QUEUE = 'second_queue';

    /**
     * The connection of a file: $extra holds the journal_mode, busy_timeout
     * and pragmas keys of the case.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function connection(string $file, array $extra): array
    {
        return array_replace(['driver' => 'sqlite', 'url' => null, 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    public static function queue(string $connection): array
    {
        return ['driver' => 'database', 'connection' => $connection, 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false];
    }

    /**
     * The connections and queue connections of the case.
     *
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>|null  $alias  the extra keys of an alias name on the same file, or null for none
     */
    public static function apply(Repository $config, string $file, array $extra, ?string $second = null, ?array $alias = null): void
    {
        $config->set('database.connections.'.self::CONNECTION, self::connection($file, $extra));
        $config->set('queue.connections.'.self::QUEUE, self::queue(self::CONNECTION));

        if ($alias !== null) {
            $config->set('database.connections.'.self::ALIAS, self::connection($file, $alias));
        }

        if ($second !== null) {
            $config->set('database.connections.'.self::SECOND, self::connection($second, ['journal_mode' => 'wal', 'busy_timeout' => 5000]));
            $config->set('queue.connections.'.self::SECOND_QUEUE, self::queue(self::SECOND));
        }
    }

    /**
     * The failed jobs, the batches and the default cache store on the file.
     */
    public static function useHostTables(Repository $config): void
    {
        self::useHostRecords($config);
        self::useHostCache($config);
    }

    /**
     * The failed jobs and the batches on the file.
     */
    public static function useHostRecords(Repository $config): void
    {
        $config->set('queue.failed', ['driver' => 'database-uuids', 'database' => self::CONNECTION, 'table' => 'failed_jobs']);
        $config->set('queue.batching', ['database' => self::CONNECTION, 'table' => 'job_batches']);
    }

    /**
     * The default cache store on the file (`app_database`).
     */
    public static function useHostCache(Repository $config): void
    {
        $config->set('cache.stores.app_database', ['driver' => 'database', 'connection' => self::CONNECTION, 'table' => 'cache', 'lock_connection' => self::CONNECTION, 'lock_table' => 'cache_locks']);
        $config->set('cache.default', 'app_database');
    }

    /**
     * The application's tables of the file.
     */
    public static function createTables(Builder $schema, bool $second = false): void
    {
        $schema->create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        if ($second) {
            return;
        }

        $schema->create('failed_jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        $schema->create('job_batches', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
        $schema->create('cache', static function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        $schema->create('cache_locks', static function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
        // The fence table, as orrery:install --fence and migrate give the queue's file.
        \Justrau\Orrery\Database\Schema\PackageSchema::create($schema, [\Justrau\Orrery\Retry\Fence::TABLE]);
    }
}
