<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Failures;

use Justrau\Orrery\Failures\GroupKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The failure group key: an HMAC-SHA256
 * of the JSON of ["v1", exception_class, first_line(message), name], with
 * exact bytes and nulls kept, keyed by the application key. These tests
 * run without an application, so they key with APP_KEY through keyed();
 * tests/Feature/Projection/FailureGroupKeyTest.php tests of() with the
 * configured key.
 */
final class GroupKeyTest extends TestCase
{
    private const APP_KEY = 'base64:test-key';

    /**
     * Rejects: another version tag, escaped slashes or unicode, or a changed element order.
     */
    public function test_the_canonical_form_is_the_versioned_json_list(): void
    {
        self::assertSame(
            '["v1","App\\\\Exceptions\\\\Boom","Timeout after 30 s / ünïcode","App\\\\Jobs\\\\Sync"]',
            GroupKey::canonical('App\\Exceptions\\Boom', 'Timeout after 30 s / ünïcode', 'App\\Jobs\\Sync'),
        );
        self::assertSame(
            hash_hmac('sha256', '["v1","App\\\\Exceptions\\\\Boom","Timeout after 30 s / ünïcode","App\\\\Jobs\\\\Sync"]', 'orrery-failure-key|'.self::APP_KEY),
            self::key('App\\Exceptions\\Boom', "Timeout after 30 s / ünïcode\nStack line", 'App\\Jobs\\Sync'),
        );
    }

    /**
     * Rejects: a null turned into an empty string (or the reverse).
     */
    public function test_nulls_are_kept_and_differ_from_empty_strings(): void
    {
        self::assertSame('["v1",null,null,""]', GroupKey::canonical(null, null, ''));
        self::assertNotSame(self::key(null, null, ''), self::key('', '', ''));
        self::assertNotSame(self::key(null, 'm', 'n'), self::key('', 'm', 'n'));
        self::assertNotSame(self::key('c', null, 'n'), self::key('c', '', 'n'));
    }

    /**
     * Rejects: a split on LF only, or on the first line break of another kind.
     *
     * @return array<string, array{0: string}>
     */
    public static function lineBreaks(): array
    {
        return [
            'LF' => ["first\nsecond"],
            'CRLF' => ["first\r\nsecond"],
            'CR' => ["first\rsecond"],
            'no break' => ['first'],
        ];
    }

    #[DataProvider('lineBreaks')]
    public function test_the_first_line_ends_at_crlf_lf_or_cr(string $message): void
    {
        self::assertSame('first', GroupKey::firstLine($message));
        self::assertSame(self::key('c', 'first', 'n'), self::key('c', $message, 'n'));
    }

    /**
     * Rejects: a trim, a case fold or a unicode normalisation of the message.
     */
    public function test_exact_bytes_make_separate_keys(): void
    {
        $composed = "caf\u{00E9}";
        $decomposed = "cafe\u{0301}";

        self::assertNotSame(self::key('c', $composed, 'n'), self::key('c', $decomposed, 'n'));
        self::assertNotSame(self::key('c', 'Boom', 'n'), self::key('c', 'boom', 'n'));
        self::assertNotSame(self::key('c', 'Boom', 'n'), self::key('c', 'Boom ', 'n'));
        self::assertNotSame(self::key('c', ' Boom', 'n'), self::key('c', 'Boom', 'n'));
        // Messages that differ only in an id make separate groups (documented).
        self::assertNotSame(self::key('c', 'User 1 not found', 'n'), self::key('c', 'User 2 not found', 'n'));
    }

    /**
     * Rejects: a substitution of invalid UTF-8 that merges distinct byte strings, or a failed encoding.
     */
    public function test_invalid_utf8_keeps_its_bytes_apart(): void
    {
        $first = self::key('c', "bad \x80", 'n');
        $second = self::key('c', "bad \x81", 'n');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        self::assertNotSame($first, $second);
        self::assertNotSame($first, self::key('c', "bad \u{FFFD}", 'n'));
        self::assertSame('["v1","c",{"base64":"YmFkIIA="},"n"]', GroupKey::canonical('c', "bad \x80", 'n'));
    }

    /**
     * Rejects: a key of the class and message only.
     */
    public function test_the_job_name_is_part_of_the_key(): void
    {
        self::assertNotSame(self::key('c', 'm', 'App\\Jobs\\A'), self::key('c', 'm', 'App\\Jobs\\B'));
    }

    public function test_valid_accepts_only_lowercase_sha256_hex(): void
    {
        self::assertTrue(GroupKey::valid(str_repeat('a', 64)));
        self::assertFalse(GroupKey::valid(str_repeat('A', 64)));
        self::assertFalse(GroupKey::valid(str_repeat('a', 63)));
        self::assertFalse(GroupKey::valid(str_repeat('g', 64)));
    }

    private static function key(?string $exceptionClass, ?string $message, ?string $name): string
    {
        return GroupKey::keyed(GroupKey::canonical($exceptionClass, $message, $name), self::APP_KEY);
    }
}
