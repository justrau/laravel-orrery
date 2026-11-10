<?php

namespace Justrau\Orrery\Lifecycle;

use Throwable;

/**
 * The stored text of an exception.
 *
 * - exception: (string) $e, with invalid UTF-8 scrubbed and each NUL byte
 *   written as the two characters \0. Postgres refuses both in a text column.
 * - exception_class: the class name; an anonymous class without the NUL
 *   byte and the declaring file that PHP adds to its name.
 * - exception_message: the sanitised message, cut to 60,000 bytes on a
 *   character boundary (the MySQL TEXT limit is 65,535 bytes).
 * - context: the JSON of $e->context() when the method exists.
 * - Every byte length is measured on the stored value.
 */
final class ExceptionText
{
    public const MESSAGE_BYTES = 60000;

    public const CLASS_CHARACTERS = 255;

    /**
     * @return array{exception: ?string, exception_bytes: ?int, exception_class: ?string, exception_message: ?string, context: ?string, context_bytes: ?int}
     */
    public static function columns(?Throwable $e): array
    {
        if ($e === null) {
            return [
                'exception' => null,
                'exception_bytes' => null,
                'exception_class' => null,
                'exception_message' => null,
                'context' => null,
                'context_bytes' => null,
            ];
        }

        $text = self::sanitise(self::describe($e));
        $context = self::context($e);

        return [
            'exception' => $text,
            'exception_bytes' => strlen($text),
            'exception_class' => mb_substr(self::className($e), 0, self::CLASS_CHARACTERS),
            'exception_message' => mb_strcut(self::sanitise($e->getMessage()), 0, self::MESSAGE_BYTES, 'UTF-8'),
            'context' => $context,
            'context_bytes' => $context === null ? null : strlen($context),
        ];
    }

    /**
     * The class name without the part after a NUL byte: PHP names an
     * anonymous class `Parent@anonymous`, a NUL byte, and the path and line
     * of the file that declared it.
     */
    private static function className(Throwable $e): string
    {
        $class = $e::class;
        $end = strpos($class, "\0");

        return self::sanitise($end === false ? $class : substr($class, 0, $end));
    }

    /**
     * Valid UTF-8 without NUL bytes.
     */
    public static function sanitise(string $text): string
    {
        return str_replace("\0", '\\0', mb_scrub($text, 'UTF-8'));
    }

    private static function describe(Throwable $e): string
    {
        try {
            // An explicit call: __toString() of a subclass can throw.
            return $e->__toString();
        } catch (Throwable) {
            return $e::class.': '.$e->getMessage();
        }
    }

    private static function context(Throwable $e): ?string
    {
        if (! method_exists($e, 'context')) {
            return null;
        }

        try {
            $json = json_encode($e->context(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        // JSON escapes a NUL byte as \u0000, and the substitution keeps it valid UTF-8.
        return is_string($json) ? self::sanitise($json) : null;
    }
}
