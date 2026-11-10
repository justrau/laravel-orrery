<?php

namespace Justrau\Orrery\Failures;

use Illuminate\Container\Container;

/**
 * The key of a failure group.
 *
 * The key is the lowercase hexadecimal HMAC-SHA256 of the canonical form,
 * the JSON list ["v1", exception_class, first_line(exception_message),
 * name], keyed by DOMAIN followed by the application key (`app.key`). The
 * key appears in URLs and responses, and an unsalted hash of a first line
 * with a low-entropy secret could be brute forced (S-8). Rows keyed before
 * the UI rebuild keep their unsalted key, and a rotation of the application key splits
 * a group until its older rows age out (`trim.failed`). The computation
 * adds no statement.
 *
 * - The values keep their exact bytes: no trim, no case fold, no unicode
 *   normalisation. So messages that differ only in an id make separate
 *   groups.
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
    public const VERSION = 'v1';

    /** The domain label of the HMAC key: no other HMAC of the package uses it. */
    public const DOMAIN = 'orrery-failure-key|';

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
            self::value(self::firstLine($message)),
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
