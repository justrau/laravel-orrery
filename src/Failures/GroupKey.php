<?php

namespace Justrau\Orrery\Failures;

use Illuminate\Container\Container;

/**
 * The key of a failure group.
 *
 * The key is the lowercase hexadecimal HMAC-SHA256 of the canonical form,
 * the JSON list ["v2", exception_class,
 * normalise(first_line(exception_message)), name], keyed by DOMAIN followed
 * by the application key (`app.key`). The
 * key appears in URLs and responses, and an unsalted hash of a first line
 * with a low-entropy secret could be brute forced (S-8). Rows keyed before
 * the UI rebuild keep their unsalted key, and a rotation of the application key splits
 * a group until its older rows age out (`trim.failed`). The computation
 * adds no statement.
 *
 * - normalise() replaces the variable parts of the first line (see there),
 *   so messages that differ only in an id, a time or a query binding make
 *   one group. Short numbers stay, so "HTTP 404" and "HTTP 503" stay apart.
 *   The display keeps the exact first line of the newest member.
 * - Apart from that, the values keep their exact bytes: no trim, no case
 *   fold, no unicode normalisation.
 * - Keys are stored at capture and never recomputed. A row keyed under
 *   "v1" (the exact first line) keeps its key, so a group can show twice
 *   until retention drops the older rows.
 * - A null stays null, and differs from an empty string.
 * - first_line() ends at the first CRLF, LF or CR.
 * - The JSON has unescaped slashes and unescaped unicode.
 * - A value that is not valid UTF-8 becomes {"base64": <its bytes>}, so
 *   distinct invalid byte strings keep distinct keys. The stored exception
 *   columns are valid UTF-8 (Lifecycle\ExceptionText), so this is only a
 *   guard.
 */
final class GroupKey
{
    public const VERSION = 'v2';

    /** The domain label of the HMAC key: no other HMAC of the package uses it. */
    public const DOMAIN = 'orrery-failure-key|';

    /**
     * The replacements of normalise(), in order.
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        '/\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:[.,]\d+)?)?(?:Z|[+-]\d{2}(?::?\d{2})?)?)?/' => '{datetime}',
        '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i' => '{uuid}',
        '/\b[0-7][0-9a-hjkmnp-tv-z]{25}\b/i' => '{ulid}',
        '/[0-9a-f]{16,}/i' => '{hex}',
        '/[a-z0-9._%+-]+@[a-z0-9-]+(?:\.[a-z0-9-]+)+/i' => '{email}',
        '/\d{4,}/' => '{n}',
    ];

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public static function of(?string $exceptionClass, ?string $message, ?string $name): string
    {
        $appKey = Container::getInstance()->make('config')->get('app.key');

        return self::keyed(self::canonical($exceptionClass, $message, $name), is_string($appKey) ? $appKey : '');
    }

    /**
     * The HMAC of a canonical form under an application key.
     */
    public static function keyed(string $canonical, string $appKey): string
    {
        return hash_hmac('sha256', $canonical, self::DOMAIN.$appKey);
    }

    public static function canonical(?string $exceptionClass, ?string $message, ?string $name): string
    {
        return json_encode([
            self::VERSION,
            self::value($exceptionClass),
            self::value(self::normalise(self::firstLine($message))),
            self::value($name),
        ], self::JSON);
    }

    /**
     * The text before the first CRLF, LF or CR; null stays null.
     */
    public static function firstLine(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        return preg_split('/\r\n|\n|\r/', $message, 2)[0] ?? '';
    }

    /**
     * The first line with its variable parts replaced; null stays null.
     *
     * 1. The text from the first " (Connection: " on is cut, as a
     *    QueryException appends the connection, the SQL and its bindings.
     * 2. In this order: ISO-8601 datetimes (a date, with an optional time
     *    and zone) become {datetime}; UUIDs {uuid}; ULIDs at word boundaries
     *    {ulid}; hexadecimal runs of 16 or more characters {hex}; email
     *    addresses {email}; digit runs of 4 or more {n}.
     *
     * The patterns are ASCII and byte-wise, so a line that is not valid
     * UTF-8 is normalised as well. If a pattern fails (the PCRE backtrack
     * limit), the line from the step before stays.
     */
    public static function normalise(?string $line): ?string
    {
        if ($line === null) {
            return null;
        }

        $cut = strpos($line, ' (Connection: ');

        if ($cut !== false) {
            $line = substr($line, 0, $cut);
        }

        foreach (self::PATTERNS as $pattern => $replacement) {
            $line = preg_replace($pattern, $replacement, $line) ?? $line;
        }

        return $line;
    }

    /**
     * Whether a value has the form of a key: 64 lowercase hexadecimal characters.
     */
    public static function valid(mixed $key): bool
    {
        return is_string($key) && preg_match('/^[0-9a-f]{64}$/D', $key) === 1;
    }

    /**
     * @return string|array{base64: string}|null
     */
    private static function value(?string $value): string|array|null
    {
        if ($value === null || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return ['base64' => base64_encode($value)];
    }
}
