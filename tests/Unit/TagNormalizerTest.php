<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit;

use Justrau\Orrery\Observation\TagNormalizer;
use PHPUnit\Framework\TestCase;
use Stringable;

final class TagNormalizerTest extends TestCase
{
    /**
     * Rejects: keeping whitespace or empty tags.
     */
    public function test_trims_and_drops_empty_tags(): void
    {
        self::assertSame('orders', TagNormalizer::normalize("  orders \n"));
        self::assertNull(TagNormalizer::normalize(''));
        self::assertNull(TagNormalizer::normalize("   \t\n"));

        self::assertSame(
            ['tags' => ['a', 'b'], 'truncated' => false],
            TagNormalizer::normalizeList([' a', '', 'b ', '  ', 'a'], 50),
        );
    }

    /**
     * A tag with a NUL byte is dropped, not cut or stripped: Postgres stops
     * a bound text at NUL, so `a\0b` would be stored as `a`, and a stripped
     * `ab` would alias the tag `ab`. trim() would also strip a NUL at an end.
     *
     * Rejects: a normalizer that keeps, trims or strips a NUL byte.
     */
    public function test_a_tag_with_a_nul_byte_is_dropped(): void
    {
        self::assertNull(TagNormalizer::normalize("a\0b"));
        self::assertNull(TagNormalizer::normalize("a\0"));
        self::assertNull(TagNormalizer::normalize("\0a"));
        self::assertNull(TagNormalizer::normalize("\0"));

        self::assertSame(
            ['tags' => ['a', 'ab'], 'truncated' => false],
            TagNormalizer::normalizeList(["a\0b", 'a', "a\0", 'ab'], 50),
        );
    }

    /**
     * Rejects: storing bytes that Postgres refuses.
     */
    public function test_invalid_utf8_is_dropped(): void
    {
        self::assertNull(TagNormalizer::normalize("tenant:\xC3\x28"));
        self::assertNull(TagNormalizer::normalize("\xFF\xFE"));

        self::assertSame(
            ['tags' => ['valid'], 'truncated' => false],
            TagNormalizer::normalizeList(["bad\xC3\x28", 'valid', "\xFF"], 50),
        );
    }

    /**
     * Rejects: dropping integers, or a fatal error on an array tag.
     */
    public function test_non_string_scalars_and_stringables(): void
    {
        $stringable = new class implements Stringable
        {
            public function __toString(): string
            {
                return ' order:7 ';
            }
        };

        $throwing = new class implements Stringable
        {
            public function __toString(): string
            {
                throw new \RuntimeException('no string');
            }
        };

        self::assertSame('42', TagNormalizer::normalize(42));
        self::assertSame('1.5', TagNormalizer::normalize(1.5));
        self::assertSame('order:7', TagNormalizer::normalize($stringable));
        self::assertNull(TagNormalizer::normalize(['nested']));
        self::assertNull(TagNormalizer::normalize(null));
        self::assertNull(TagNormalizer::normalize(true));
        self::assertNull(TagNormalizer::normalize(new \stdClass));
        self::assertNull(TagNormalizer::normalize($throwing));

        self::assertSame(
            ['tags' => ['42', 'order:7', 'x'], 'truncated' => false],
            TagNormalizer::normalizeList([42, ['nested'], $stringable, null, 'x', '42'], 50),
        );
    }

    /**
     * Rejects: substr, which splits a character.
     */
    public function test_long_tag_is_cut_on_a_character_boundary_with_a_hash(): void
    {
        // 100 three-byte characters: 300 bytes of valid UTF-8. Byte 200 is
        // inside the 67th character, so a byte cut at 200 splits it.
        $tag = str_repeat('€', 100);
        self::assertSame(300, strlen($tag));

        $normalized = TagNormalizer::normalize($tag);

        self::assertIsString($normalized);
        self::assertTrue(mb_check_encoding($normalized, 'UTF-8'), 'the cut tag is not valid UTF-8');
        self::assertLessThanOrEqual(TagNormalizer::CUT_BYTES + 3 + 12, strlen($normalized));
        self::assertSame(str_repeat('€', 66).'…'.substr(hash('sha256', $tag), 0, 12), $normalized);

        // A tag of exactly 255 bytes is kept, a tag of 256 bytes is cut.
        self::assertSame(str_repeat('a', 255), TagNormalizer::normalize(str_repeat('a', 255)));
        self::assertSame(
            str_repeat('a', 200).'…'.substr(hash('sha256', str_repeat('a', 256)), 0, 12),
            TagNormalizer::normalize(str_repeat('a', 256)),
        );
    }

    /**
     * Rejects: no limit, or a limit without the truncation flag.
     */
    public function test_ten_thousand_tags_keep_fifty_and_mark_truncation(): void
    {
        $tags = array_map(static fn (int $n): string => 'App\\Models\\User:'.$n, range(1, 10_000));

        $result = TagNormalizer::normalizeList($tags, 50);

        self::assertCount(50, $result['tags']);
        self::assertSame(array_slice($tags, 0, 50), $result['tags']);
        self::assertTrue($result['truncated']);

        // Exactly the limit is not a truncation, and duplicates do not count.
        $exact = TagNormalizer::normalizeList([...array_slice($tags, 0, 50), $tags[0], ' '.$tags[1]], 50);
        self::assertCount(50, $exact['tags']);
        self::assertFalse($exact['truncated']);

        // The iterable can be a generator.
        $generated = TagNormalizer::normalizeList((static function () {
            yield 'one';
            yield 'two';
            yield 'three';
        })(), 2);
        self::assertSame(['tags' => ['one', 'two'], 'truncated' => true], $generated);
    }

    /**
     * Rejects: an ASCII-only filter.
     */
    public function test_non_ascii_tags_are_kept(): void
    {
        $tags = ['klientas:Žemaitė', 'город:Москва', '顧客:東京', 'emoji:🚀'];

        self::assertSame(['tags' => $tags, 'truncated' => false], TagNormalizer::normalizeList($tags, 50));
    }
}
