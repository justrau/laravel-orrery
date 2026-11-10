<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Support;

use Justrau\Orrery\Runtime\SafeText;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;

/**
 * SafeText: the cleaning of an actor name or a marker label.
 */
final class SafeTextTest extends TestCase
{
    /**
     * Known limit: it would pass a filter that replaces only C0 control characters and DEL (no C1 character is tested).
     */
    public function test_control_characters_and_nul_become_one_space(): void
    {
        self::assertSame('al i c e', SafeText::clean("al\x00i\x07c\te\x7F"));
        self::assertSame('v1.2 hotfix', SafeText::clean("v1.2\nhotfix"));
        self::assertSame('bob smith', SafeText::clean("  bob\n smith\r\n "));
    }

    /**
     * Known limit: it would pass a cleaner that drops the whole value when any byte is invalid, if it also returned non-null here (it cannot).
     */
    public function test_invalid_utf8_becomes_valid_utf8(): void
    {
        $clean = SafeText::clean("ok\xC3\x28end");

        self::assertNotNull($clean);
        self::assertTrue(mb_check_encoding($clean, 'UTF-8'));
        self::assertStringStartsWith('ok', $clean);
        self::assertStringEndsWith('end', $clean);
    }

    /**
     * Known limit: it would pass a cap of 191 bytes only for ASCII text (the multibyte case guards it).
     */
    public function test_the_value_is_cut_to_191_unicode_characters(): void
    {
        self::assertSame(191, mb_strlen((string) SafeText::clean(str_repeat('界', 300)), 'UTF-8'));
        self::assertSame(str_repeat('a', 191), SafeText::clean(str_repeat('a', 191)));
    }

    /**
     * Known limit: it would pass a cleaner that casts other objects when they are not Stringable (only a Stringable and stdClass are tested).
     */
    public function test_only_strings_and_integers_are_accepted(): void
    {
        self::assertNull(SafeText::clean(null));
        self::assertNull(SafeText::clean(''));
        self::assertNull(SafeText::clean(" \x00\n "));
        self::assertNull(SafeText::clean(['x']));
        self::assertNull(SafeText::clean(new stdClass));
        self::assertNull(SafeText::clean(1.5));
        self::assertNull(SafeText::clean(new class implements Stringable
        {
            public function __toString(): string
            {
                return '{"id":1,"email":"alice@example.test"}';
            }
        }), 'a Stringable (for example a model, whose string is its JSON) is not an actor name');
        self::assertSame('42', SafeText::clean(42));
    }
}
