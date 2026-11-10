<?php

namespace Justrau\Orrery\Runtime;

use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * The summary of a watched failure that the package reports and logs.
 * It is an allowlist, so a report never carries a job value
 * from the SQL bindings or from the driver text.
 *
 * - A QueryException gives its class, its SQLSTATE, its driver code and the
 *   SQL template of getSql() with its `?` placeholders (never getRawSql(),
 *   never the bindings). The first line of the driver text is kept only when
 *   it has no value (safeDriverLine()). Every other driver text is left out.
 * - Any other Throwable gives its class and the first line of its message
 *   after strip(), cut to 500 bytes.
 * - The previous chain is summarised with the same rules, joined with " ← ".
 *   The previous of a QueryException (its PDOException) is skipped, because
 *   the QueryException's own summary covers it.
 * - A string problem goes through strip().
 * - Every http(s) URL in any part is reduced to its scheme and host, so a
 *   webhook path (a Slack credential) never leaves the package.
 * - The whole summary is cut to 2,000 bytes on a UTF-8 boundary.
 */
final class SafeError
{
    public const MAX_BYTES = 2000;

    public const OTHER_BYTES = 500;

    private const CHAIN_DEPTH = 8;

    /**
     * The SQLSTATE classes of MySQL and Postgres whose driver text has no row
     * value: connection, rollback, undefined object, resources, operator
     * intervention and catalog.
     */
    private const SAFE_CLASSES = ['08', '40', '42', '53', '57', '3D'];

    /** The SQLite texts (always SQLSTATE HY000) that have no row value. */
    private const SAFE_SQLITE_PREFIXES = [
        'no such table',
        'no such column',
        'database is locked',
        'database table is locked',
        'disk I/O error',
        'database or disk is full',
        'attempt to write a readonly database',
        'unable to open database file',
    ];

    /**
     * The summary of a failed watched operation: the operation, then the
     * problem.
     */
    public static function summary(string $operation, Throwable|string $problem): string
    {
        return self::cut(self::clean("Orrery's watched operation [{$operation}] failed: ".self::describe($problem)), self::MAX_BYTES);
    }

    /**
     * The problem alone, with the rules of summary().
     */
    public static function describe(Throwable|string $problem): string
    {
        if (is_string($problem)) {
            return self::strip($problem);
        }

        $parts = [];
        $seen = [];
        $error = $problem;

        while ($error !== null && count($parts) < self::CHAIN_DEPTH && ! isset($seen[spl_object_id($error)])) {
            $seen[spl_object_id($error)] = true;

            if ($error instanceof QueryException) {
                $parts[] = self::query($error);
                $previous = $error->getPrevious();
                // The QueryException's own PDOException is the driver text that its summary covers.
                $error = $previous instanceof PDOException ? $previous->getPrevious() : $previous;

                continue;
            }

            $parts[] = self::other($error);
            $error = $error->getPrevious();
        }

        return self::cut(implode(' ← ', $parts), self::MAX_BYTES);
    }

    /**
     * The fields of the reported exception (WatchedOperationFailed): the
     * problem's class (RuntimeException for a string problem, which the
     * watcher reported as a RuntimeException before), and the SQLSTATE and
     * driver code of the first QueryException or PDOException in its chain.
     *
     * @return array{class: class-string, sqlState: ?string, driverCode: int|string|null}
     */
    public static function cause(Throwable|string $problem): array
    {
        if (is_string($problem)) {
            return ['class' => RuntimeException::class, 'sqlState' => null, 'driverCode' => null];
        }

        // The first QueryException wins (a DeadlockException is a PDOException that wraps one), then the first PDOException.
        $query = null;
        $pdo = null;
        $error = $problem;
        $depth = 0;

        while ($error !== null && $query === null && $depth++ < self::CHAIN_DEPTH) {
            if ($error instanceof QueryException) {
                $query = $error;
            } elseif ($error instanceof PDOException) {
                $pdo ??= $error;
            }

            $error = $error->getPrevious();
        }

        $found = $query ?? $pdo;

        if ($found !== null) {
            return ['class' => $problem::class, 'sqlState' => self::sqlState($found), 'driverCode' => self::driverCode($found)];
        }

        return ['class' => $problem::class, 'sqlState' => null, 'driverCode' => null];
    }

    /**
     * Applies to every message and every string problem:
     * - it cuts at the first " (Connection: " (Laravel's suffix with the SQL
     *   and its substituted bindings);
     * - it replaces a "SQLSTATE[xxxxx]: …" tail with "SQLSTATE[xxxxx]", so a
     *   query message embedded in a sentence keeps only its code;
     * - it replaces control characters with spaces;
     * - it reduces each http(s) URL to its scheme and host;
     * - it cuts the text to 2,000 bytes on a UTF-8 boundary.
     */
    public static function strip(string $text): string
    {
        $position = strpos($text, ' (Connection: ');

        if ($position !== false) {
            $text = substr($text, 0, $position);
        }

        $text = (string) preg_replace('/SQLSTATE\[([0-9A-Za-z]{5})\]:.*\z/s', 'SQLSTATE[$1]', $text);

        return self::cut(self::clean($text), self::MAX_BYTES);
    }

    private static function query(QueryException $error): string
    {
        $state = self::sqlState($error);
        $code = self::driverCode($error);
        $line = self::driverLine($error);
        $text = $error::class.' [SQLSTATE '.($state ?? 'unknown').', driver code '.($code ?? 'unknown').']';

        if ($line !== null && self::safeDriverLine($state, $line)) {
            $text .= ': '.self::cut(self::clean($line), self::OTHER_BYTES);
        }

        return $text.'; SQL: '.self::cut(self::clean($error->getSql()), self::MAX_BYTES);
    }

    private static function other(Throwable $error): string
    {
        $first = preg_split('/\r\n|\n|\r/', $error->getMessage(), 2)[0] ?? '';

        return $error::class.': '.self::cut(self::strip($first), self::OTHER_BYTES);
    }

    /**
     * The driver text has no value only for these SQLSTATE classes, or, on
     * SQLite (HY000), for these prefixes. A syntax error that quotes the
     * statement near a token is left out too: with emulated prepares (the
     * MySQL default), that token can be a substituted binding.
     */
    private static function safeDriverLine(?string $state, string $line): bool
    {
        if ($state === null) {
            return false;
        }

        if (str_contains($line, "near '") || str_contains($line, 'near "')) {
            return false;
        }

        if (strtoupper($state) === 'HY000') {
            foreach (self::SAFE_SQLITE_PREFIXES as $prefix) {
                if (str_starts_with($line, $prefix)) {
                    return true;
                }
            }

            return false;
        }

        return in_array(strtoupper(substr($state, 0, 2)), self::SAFE_CLASSES, true);
    }

    /**
     * The first line of the driver text: errorInfo[2], else the PDO message
     * without its "SQLSTATE[xxxxx]: <name>: <code> " prefix.
     */
    private static function driverLine(PDOException $error): ?string
    {
        $text = $error->errorInfo[2] ?? null;

        if (! is_string($text)) {
            $previous = $error->getPrevious();
            $message = $previous instanceof PDOException ? $previous->getMessage() : null;

            if ($message === null || preg_match('/^SQLSTATE\[[0-9A-Za-z]{5}\]: [^:]*: (?:-?\d+ )?(.*)\z/s', $message, $match) !== 1) {
                return null;
            }

            $text = $match[1];
        }

        $line = trim(preg_split('/\r\n|\n|\r/', $text, 2)[0] ?? '');

        return $line === '' ? null : $line;
    }

    private static function sqlState(PDOException $error): ?string
    {
        $state = $error->errorInfo[0] ?? null;

        if (is_string($state) && preg_match('/^[0-9A-Za-z]{5}$/', $state) === 1) {
            return $state;
        }

        $code = $error->getCode();

        if (is_string($code) && preg_match('/^[0-9A-Za-z]{5}$/', $code) === 1) {
            return $code;
        }

        return preg_match('/^SQLSTATE\[([0-9A-Za-z]{5})\]/', $error->getMessage(), $match) === 1 ? $match[1] : null;
    }

    private static function driverCode(PDOException $error): int|string|null
    {
        $code = $error->errorInfo[1] ?? null;

        return is_int($code) || (is_string($code) && $code !== '') ? $code : null;
    }

    /**
     * Valid UTF-8, each control character a space, and each URL reduced to
     * its scheme and host.
     */
    private static function clean(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');

        return self::redactUrls((string) preg_replace('/\p{Cc}/u', ' ', $text));
    }

    /**
     * Each http(s) URL becomes its scheme and host (for example
     * "https://hooks.slack.com/…"): a path can be a credential (a Slack
     * webhook), and so can user information, a query or a fragment.
     *
     * - A URL also matches right after a word character
     *   ("webhook_https://…") and in its JSON-escaped form ("https:\/\/…").
     * - The authority is the text before the first "/", "?", "#" or "\".
     *   With an "@", the host is the text after its last "@".
     * - An "@" after the authority (in the path, query or fragment), or
     *   anywhere in the URL while the authority has none, is ambiguous
     *   (a password with an unencoded "/", "?" or "#", or an "@" in the
     *   path, query or fragment), so the URL becomes "scheme://…" with no
     *   host at all.
     * - A host must be a host name or a bracketed IPv6 address, with an
     *   optional numeric port; anything else (for example "user:pass")
     *   becomes "…".
     */
    private static function redactUrls(string $text): string
    {
        return (string) preg_replace_callback(
            '~(https?):(?:\\\\?/){2}([^\s`\'"<>]*)~i',
            static function (array $match): string {
                $rest = $match[2];
                $authority = substr($rest, 0, strcspn($rest, '/?#\\'));
                $tail = substr($rest, strlen($authority));
                $at = strrpos($authority, '@');

                if (str_contains($tail, '@') || ($at === false && str_contains($rest, '@'))) {
                    // Ambiguous: a password with an unencoded "/", "?" or "#", or an "@" in the path, query or fragment. No host at all.
                    return $match[1].'://…';
                }

                $host = $at === false ? $authority : substr($authority, $at + 1);

                // A host name or a bracketed IPv6 address, with an optional port: never a "user:pass" shape.
                if (preg_match('/^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.\-]+)(:\d+)?$/', $host) !== 1) {
                    $host = '…';
                }

                return $match[1].'://'.$host.($tail === '' ? '' : '/…');
            },
            $text,
        );
    }

    private static function cut(string $text, int $bytes): string
    {
        return strlen($text) <= $bytes ? $text : mb_strcut($text, 0, $bytes, 'UTF-8');
    }
}
