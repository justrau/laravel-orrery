<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use Illuminate\Database\Events\QueryExecuted;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The recorder of test_every_failed_record_delete_names_id_and_uuid
 * for a whole test class: every `delete` on a failed-job table
 * (a table whose name contains `failed_jobs`) that the package sends, on a
 * package session (the session recorder) or on an application connection
 * from package code (QueryExecuted with a package frame on the stack;
 * in-memory SQLite runs every package session there), must bind `id` and
 * `uuid`. Laravel's own queue:forget and queue:retry and the test's own
 * fixture deletes are not the package's. The check runs after each test
 * (assertPostConditions()). The session entries come through
 * PackageStatements, so this recorder keeps the other listeners; the test
 * reset removes it.
 */
trait RecordsFailedRecordDeletes
{
    /** @var list<string> */
    private array $failedRecordDeletes = [];

    protected function recordFailedRecordDeletes(): void
    {
        $this->failedRecordDeletes = [];
        $deletes = &$this->failedRecordDeletes;
        $matches = static fn (string $sql): bool => preg_match('/^\s*delete\s+from\s+(?:["`]?\w+["`]?\.)?["`]?\w*failed_jobs\w*["`]?/i', $sql) === 1;

        // Next to the other listeners of the one session recorder (the event bridge of TestCase,
        // a test's own counters): never in their place.
        PackageStatements::each(static function (string $kind, string $sql) use (&$deletes, $matches): void {
            if ($kind === PackageSession::STATEMENT && $matches($sql)) {
                $deletes[] = $sql;
            }
        });
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$deletes, $matches): void {
            if (! PackageSession::isPackageSession($query->connectionName) && $matches($query->sql) && self::fromPackageCode()) {
                $deletes[] = $query->sql;
            }
        });
    }

    /**
     * Whether the innermost frame of this package's namespace on the stack
     * is package code, not test code (a test's hook callback that runs
     * Laravel's queue:retry inside a package call is the test's).
     */
    private static function fromPackageCode(): bool
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        // The frames outside the event dispatch: from the database layer that ran the statement outward.
        $start = 0;

        foreach ($frames as $index => $frame) {
            if (str_starts_with($frame['class'] ?? '', 'Illuminate\\Database\\')) {
                $start = $index;

                break;
            }
        }

        foreach (array_slice($frames, $start) as $frame) {
            $class = $frame['class'] ?? '';

            if (str_starts_with($class, 'Justrau\\Orrery\\Tests\\')) {
                return false;
            }

            if (str_starts_with($class, 'Justrau\\Orrery\\')) {
                return true;
            }
        }

        return false;
    }

    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();

        foreach ($this->failedRecordDeletes as $sql) {
            $normalised = strtolower(str_replace(['"', '`'], '', $sql));
            self::assertMatchesRegularExpression('/\bid = \?/', $normalised, "A failed-record delete without the id: {$sql}");
            self::assertMatchesRegularExpression('/\buuid = \?/', $normalised, "A failed-record delete without the uuid: {$sql}");
        }
    }
}
