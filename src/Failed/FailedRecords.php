<?php

namespace Justrau\Orrery\Failed;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Justrau\Orrery\Storage\ConnectionName;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The failed-record adapter of the bound failer,
 * read at action time:
 *
 * | Bound failer | Adapter | Retry | Forget |
 * | --- | --- | --- | --- |
 * | CapturingUuidFailedJobProvider on a table whose ids are never reused | SqlFailedRecords | mode in_tx (the queue's own connection name) or before | exact delete |
 * | CapturingUuidFailedJobProvider on another table | ReadOnlyFailedRecords | refused ids_may_be_reused | refused |
 * | NullFailedJobProvider | NoFailedRecords | mode none (the mirror payload) | the mirror only |
 * | anything else | ReadOnlyFailedRecords | refused provider_read_only | refused |
 *
 * The failer must also target the configured table (FailerProvenance). No
 * provider method is ever called: the file and DynamoDB drivers, the legacy
 * `database` driver, wrappers and application subclasses cannot delete or
 * restore one exact record, so they are read-only.
 */
abstract class FailedRecords
{
    public const SQL = 'sql';

    public const NONE = 'none';

    public const READ_ONLY = 'read_only';

    /** The refusal codes of a read-only adapter. */
    public const PROVIDER_READ_ONLY = 'provider_read_only';

    public const IDS_MAY_BE_REUSED = 'ids_may_be_reused';

    /**
     * The adapter of the failer that the container binds now. The ids
     * check of a SQL table may read the table's definition; on a host
     * SQLite file outside an operator action without automatic reads that
     * read throws HostFileAccessNotAllowed (D18).
     */
    public static function for(Container $app, Repository $config): self
    {
        $failer = self::bound($app);

        if (! is_object($failer) || ! FailerProvenance::targetsConfiguration($app, $failer, $config)) {
            return ReadOnlyFailedRecords::providerReadOnly(is_object($failer) ? $failer::class : get_debug_type($failer));
        }

        if (! $failer instanceof CapturingUuidFailedJobProvider) {
            return new NoFailedRecords;
        }

        /** @var DatabaseManager $db */
        $db = $app->make('db');
        $failed = (array) $config->get('queue.failed', []);
        $database = is_string($failed['database'] ?? null) && $failed['database'] !== '' ? $failed['database'] : (string) $config->get('database.default');
        $records = new SqlFailedRecords($app->make(PackageSession::class), ConnectionName::base($db, $database), (string) $failed['table']);

        return $records->idsNeverReused() ? $records : ReadOnlyFailedRecords::idsMayBeReused();
    }

    /**
     * The failer that the container binds now, as an unknown value: the
     * application, a wrapper or another package may bind anything.
     */
    public static function bound(Container $app): mixed
    {
        return $app->make('queue.failer');
    }

    /** sql, none or read_only. */
    abstract public function kind(): string;

    /**
     * The mode of a retry to $queueConnection: Operations::IN_TX, BEFORE or
     * NONE; null when retry and forget are refused.
     */
    abstract public function mode(string $queueConnection): ?string;

    /** The refusal code of a read-only adapter, else null. */
    public function refusal(): ?string
    {
        return null;
    }

    /** The refusal message of a read-only adapter, else null. */
    public function message(): ?string
    {
        return null;
    }
}
