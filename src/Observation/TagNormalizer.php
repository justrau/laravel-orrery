<?php

namespace Justrau\Orrery\Observation;

use Stringable;
use Throwable;

/**
 * The one tag normalizer of every producer and consumer.
 *
 * - A string is used as it is. An int or a float is cast to a string, and a
 *   Stringable object is cast with (string). Every other type is dropped.
 * - A tag with a NUL byte anywhere is dropped (checked before the trim,
 *   which would strip a NUL at an end): Postgres stops a bound text at NUL,
 *   so `a\0b` would be stored as `a`. Dropping it, not stripping NUL, keeps
 *   it from aliasing another tag (`ab`).
 * - The tag is trimmed. An empty tag, or a tag that is not valid UTF-8, is
 *   dropped.
 * - A tag over MAX_BYTES bytes becomes its first CUT_BYTES bytes, cut on a
 *   character boundary, then "…", then the first 12 hex characters of the
 *   SHA-256 of the full tag. So it has at most 200 + 3 + 12 bytes.
 * - A list keeps the first occurrence of each tag, in order, and at most
 *   $max tags. `truncated` is true when more valid tags existed.
 */
final class TagNormalizer
{
    public const MAX_BYTES = 255;

    public const CUT_BYTES = 200;

    private const ELLIPSIS = '…';

    private const HASH_CHARACTERS = 12;

    /**
     * The normalized tag, or null when the value is not a usable tag.
     */
    public static function normalize(mixed $tag): ?string
    {
        if (is_int($tag) || is_float($tag)) {
            $tag = (string) $tag;
        } elseif ($tag instanceof Stringable) {
            try {
                // An explicit call: a user's __toString() can throw.
                $tag = $tag->__toString();
            } catch (Throwable) {
                return null;
            }
        }

        if (! is_string($tag) || str_contains($tag, "\0") || ! mb_check_encoding($tag, 'UTF-8')) {
            return null;
        }

        $tag = trim($tag);

        if ($tag === '') {
            return null;
        }

        if (strlen($tag) <= self::MAX_BYTES) {
            return $tag;
        }

        return mb_strcut($tag, 0, self::CUT_BYTES, 'UTF-8')
            .self::ELLIPSIS
            .substr(hash('sha256', $tag), 0, self::HASH_CHARACTERS);
    }

    /**
     * @param  iterable<mixed>  $tags
     * @return array{tags: list<string>, truncated: bool}
     */
    public static function normalizeList(iterable $tags, int $max): array
    {
        $max = max(0, $max);
        $kept = [];

        foreach ($tags as $tag) {
            $normalized = self::normalize($tag);

            if ($normalized === null || isset($kept[$normalized])) {
                continue;
            }

            if (count($kept) >= $max) {
                // One more distinct valid tag exists: the list is truncated.
                return ['tags' => array_map('strval', array_keys($kept)), 'truncated' => true];
            }

            $kept[$normalized] = true;
        }

        return ['tags' => array_map('strval', array_keys($kept)), 'truncated' => false];
    }
}
