<?php

namespace Justrau\Orrery\Failures;

use Illuminate\Container\Container;
use Illuminate\Database\Query\Builder;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Storage\PackageSession;

/**
 * The projection of failed mirror rows for the failure groups:
 * FailedRow, with no payload and no trace. The
 * message comes as its first HEAD_CHARACTERS + 1 characters (substr()
 * counts characters on SQLite, MySQL and Postgres). KeyedFailures reads the
 * rows by their stored key through project() and rows().
 */
final class FailureScan
{
    /** The most candidate rows that one request scans. */
    public const CAP = 5000;

    /** The characters of the message head that the scan reads. */
    public const HEAD_CHARACTERS = 1024;

    /** The characters of the first line that a response shows (JobProjection::MESSAGE_CHARACTERS). */
    public const MESSAGE_CHARACTERS = 500;

    /** The rows per statement of the full-message reads. */
    public const CHUNK = 100;

    private const COLUMNS = ['id', 'uuid', 'occurrence_id', 'status', 'connection', 'queue', 'name', 'exception_class', 'failure_key', 'failed_at', 'retried_at', 'attempts'];

    /**
     * The rows of a projected statement (project()) as FailedRow, in order.
     * A row with a stored key needs no full message.
     *
     * @param  array<int, \stdClass>  $rows
     * @return list<FailedRow>
     */
    public static function rows(array $rows): array
    {
        return self::hydrate($rows);
    }

    /**
     * The whole stored messages of these rows for display, unredacted,
     * keyed by id: the scanned head when it is the whole message (at most
     * HEAD_CHARACTERS characters), else the stored exception_message
     * (at most 60,000 bytes), read again in one statement per CHUNK rows.
     * The redaction then matches the whole message before its first line is
     * cut. Only the shown rows go through it (at most a page).
     *
     * @param  list<FailedRow>  $rows
     * @return array<int, string|null>
     */
    public static function displayMessages(array $rows): array
    {
        $messages = [];
        $partial = [];

        foreach ($rows as $row) {
            $messages[$row->id] = $row->message;

            if ($row->message !== null && mb_strlen($row->message) > self::HEAD_CHARACTERS) {
                $partial[] = $row->id;
            }
        }

        foreach (array_chunk($partial, self::CHUNK) as $chunk) {
            foreach (Container::getInstance()->make(PackageSession::class)->mirror()->table(JobStore::TABLE)->whereIn('id', $chunk)->get(['id', 'exception_message']) as $row) {
                $messages[(int) $row->id] = is_string($row->exception_message) ? $row->exception_message : null;
            }
        }

        return $messages;
    }

    /**
     * The projection of FailedRow: no payload, no trace, the message head.
     */
    public static function project(Builder $query): Builder
    {
        return $query->select(self::COLUMNS)->selectRaw('substr(exception_message, 1, 1025) as message_head');
    }

    /**
     * One chunk of rows, in order.
     *
     * @param  array<int, \stdClass>  $rows
     * @return list<FailedRow>
     */
    private static function hydrate(array $rows): array
    {
        $full = self::fullMessages($rows);
        $hydrated = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $head = $row->message_head ?? null;

            if (array_key_exists($id, $full)) {
                $message = $full[$id];
                unset($full[$id]);
            } else {
                $message = is_string($head) ? $head : null;
            }

            $class = $row->exception_class ?? null;
            $name = (string) $row->name;
            $stored = $row->failure_key ?? null;
            // The stored key wins: JobStore wrote it from the stored columns.
            $key = GroupKey::valid($stored) ? (string) $stored : GroupKey::of(is_string($class) ? $class : null, $message, $name);

            $hydrated[] = new FailedRow(
                id: $id,
                uuid: strtolower((string) $row->uuid),
                occurrenceId: is_numeric($row->occurrence_id ?? null) ? (int) $row->occurrence_id : null,
                status: (string) $row->status,
                connection: (string) $row->connection,
                queue: (string) $row->queue,
                name: $name,
                exceptionClass: is_string($class) ? $class : null,
                message: $message,
                failedAt: is_numeric($row->failed_at ?? null) ? (float) $row->failed_at : null,
                retriedAt: is_numeric($row->retried_at ?? null) ? (float) $row->retried_at : null,
                attempts: (int) ($row->attempts ?? 0),
                key: $key,
            );
        }

        return $hydrated;
    }

    /**
     * The full messages of the rows of one chunk whose head cannot show the
     * whole first line (no line break, and longer than HEAD_CHARACTERS), in
     * one statement.
     *
     * @param  array<int, \stdClass>  $rows
     * @return array<int, string|null>
     */
    private static function fullMessages(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $head = $row->message_head ?? null;

            // A row with a stored key shows only the head: its key needs no full message.
            if (! GroupKey::valid($row->failure_key ?? null) && is_string($head) && mb_strlen($head) > self::HEAD_CHARACTERS && preg_match('/\r|\n/', $head) !== 1) {
                $ids[] = (int) $row->id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $messages = [];

        foreach (Container::getInstance()->make(PackageSession::class)->mirror()->table(JobStore::TABLE)->whereIn('id', $ids)->get(['id', 'exception_message']) as $row) {
            $messages[(int) $row->id] = is_string($row->exception_message) ? $row->exception_message : null;
        }

        return $messages;
    }
}
