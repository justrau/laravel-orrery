<?php

namespace Justrau\Orrery\Runtime;

/**
 * A short text from outside the package (an actor name, a marker label) made
 * safe to store and to show:
 *
 * - only a string or an integer; any other value (also a Stringable, for
 *   example an Eloquent model, whose string form is its JSON) gives null;
 * - valid UTF-8: invalid byte sequences are replaced (mb_scrub());
 * - each control character (Unicode category Cc, NUL included) becomes a
 *   space, and runs of spaces become one space, so "v1.2\nhotfix" becomes
 *   "v1.2 hotfix";
 * - trimmed, and cut to $max Unicode characters;
 * - an empty result gives null.
 */
final class SafeText
{
    public const MAX_CHARACTERS = 191;

    public static function clean(mixed $value, int $max = self::MAX_CHARACTERS): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $text = mb_scrub($value, 'UTF-8');
        $text = (string) preg_replace('/\p{Cc}/u', ' ', $text);
        $text = trim((string) preg_replace('/ {2,}/', ' ', $text));

        if ($text === '') {
            return null;
        }

        $text = trim(mb_substr($text, 0, max(1, $max), 'UTF-8'));

        return $text === '' ? null : $text;
    }
}
