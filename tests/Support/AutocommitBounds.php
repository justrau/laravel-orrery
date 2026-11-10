<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

/**
 * The statement text without the autocommit bounds of a pooled Postgres
 * session (PostgresSession::autocommitBounds()).
 *
 * A pooled session sends each autocommit statement as one simple query:
 * `SET LOCAL lock_timeout = '5s'; [SET LOCAL statement_timeout = <ms>; ]`
 * and then the statement. PDO's queryString and pg_stat_activity.query hold
 * that whole text. A test seam that matches a statement by its start
 * removes the bounds first, so it matches the same statement in both modes.
 * Only leading `SET LOCAL ...;` clauses are removed: the statement itself is
 * never changed.
 */
final class AutocommitBounds
{
    /** A POSIX regular expression of the leading bounds, for Postgres regexp_replace() with the flag 'i'. */
    public const POSTGRES_PATTERN = '^(set local [^;]*;\s*)+';

    private const PCRE_PATTERN = '/^\s*(?:set local [^;]*;\s*)+/i';

    public static function strip(string $sql): string
    {
        return (string) preg_replace(self::PCRE_PATTERN, '', $sql);
    }
}
