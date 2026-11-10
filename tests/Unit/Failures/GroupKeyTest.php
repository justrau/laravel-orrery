<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Failures;

use Justrau\Orrery\Failures\GroupKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The failure group key: an HMAC-SHA256
 * of the JSON of ["v2", exception_class, normalise(first_line(message)),
 * name], with exact bytes and nulls kept, keyed by the application key. These tests
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
            '["v2","App\\\\Exceptions\\\\Boom","Timeout after 30 s / ünïcode","App\\\\Jobs\\\\Sync"]',
            GroupKey::canonical('App\\Exceptions\\Boom', 'Timeout after 30 s / ünïcode', 'App\\Jobs\\Sync'),
        );
        self::assertSame(
            hash_hmac('sha256', '["v2","App\\\\Exceptions\\\\Boom","Timeout after 30 s / ünïcode","App\\\\Jobs\\\\Sync"]', 'orrery-failure-key|'.self::APP_KEY),
            self::key('App\\Exceptions\\Boom', "Timeout after 30 s / ünïcode\nStack line", 'App\\Jobs\\Sync'),
        );
    }

    /**
     * Rejects: a null turned into an empty string (or the reverse).
     */
    public function test_nulls_are_kept_and_differ_from_empty_strings(): void
    {
        self::assertSame('["v2",null,null,""]', GroupKey::canonical(null, null, ''));
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
        // Short numbers are kept, so messages that differ only in one make separate groups.
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
        self::assertSame('["v2","c",{"base64":"YmFkIIA="},"n"]', GroupKey::canonical('c', "bad \x80", 'n'));
    }

    /**
     * Rejects: a key of the exact first line (one group per order id), or
     * a digit rule that needs more than 4 digits.
     */
    public function test_order_ids_of_four_or_more_digits_fall_into_one_group(): void
    {
        self::assertSame(self::key('c', 'Order 1234 not found', 'n'), self::key('c', 'Order 98765 not found', 'n'));
        self::assertSame('["v2","c","Order {n} not found","n"]', GroupKey::canonical('c', 'Order 1234 not found', 'n'));
    }

    /**
     * Rejects: a digit rule that also replaces short numbers, so every
     * HTTP status falls into one group.
     */
    public function test_short_numbers_keep_groups_apart(): void
    {
        self::assertNotSame(self::key('c', 'HTTP 404', 'n'), self::key('c', 'HTTP 503', 'n'));
        self::assertSame('["v2","c","HTTP 404","n"]', GroupKey::canonical('c', 'HTTP 404', 'n'));
    }

    /**
     * Rejects: no cut at " (Connection: ", so the SQL and its bindings
     * split QueryExceptions; or a cut at "(Connection:" without the space.
     */
    public function test_query_exceptions_with_other_bindings_merge(): void
    {
        $first = 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry (Connection: mysql, SQL: insert into `items` (`sku`) values (ab-1))';
        $second = 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry (Connection: mysql, SQL: insert into `items` (`sku`) values (zz-9))';

        self::assertSame(self::key('Illuminate\\Database\\QueryException', $first, 'n'), self::key('Illuminate\\Database\\QueryException', $second, 'n'));
        self::assertSame(
            '["v2","c","SQLSTATE[{n}]: Integrity constraint violation: {n} Duplicate entry","n"]',
            GroupKey::canonical('c', $first, 'n'),
        );
        self::assertSame('["v2","c","Lost (Connection:mysql)","n"]', GroupKey::canonical('c', 'Lost (Connection:mysql)', 'n'));
    }

    /**
     * Rejects: a datetime rule without the time or the zone, a UUID rule
     * that runs after the hex or digit rules (it would leave dashes and
     * short runs), or a case-sensitive UUID rule.
     */
    public function test_a_uuid_and_a_datetime_merge(): void
    {
        $first = 'Import 9b2f8c4e-1d3a-4f5b-8c6d-7e8f9a0b1c2d failed at 2026-11-10T12:34:56+02:00';
        $second = 'Import 0A1B2C3D-4E5F-4A6B-8C7D-9E0F1A2B3C4D failed at 2026-11-11T08:00:01.250Z';

        self::assertSame(self::key('c', $first, 'n'), self::key('c', $second, 'n'));
        self::assertSame('["v2","c","Import {uuid} failed at {datetime}","n"]', GroupKey::canonical('c', $first, 'n'));
        self::assertSame('["v2","c","At {datetime} UTC","n"]', GroupKey::canonical('c', 'At 2026-11-10 12:34:56 UTC', 'n'));
    }

    /**
     * Rejects: a missing ULID, hex or email rule, a ULID rule without word
     * boundaries, or a hex rule that takes runs shorter than 16.
     */
    public function test_ulids_hex_runs_and_emails_are_replaced(): void
    {
        self::assertSame(
            '["v2","c","Lock {ulid} by {email} with token {hex}","n"]',
            GroupKey::canonical('c', 'Lock 01JCQ7ZK3W8X5V2N9M4B6T1R0Y by ops.bot+q@example.co.uk with token 9f86d081884c7d659a2feaa0c55ad015', 'n'),
        );
        self::assertSame('["v2","c","id deadbeefcafe","n"]', GroupKey::canonical('c', 'id deadbeefcafe', 'n'));
        self::assertSame('["v2","c","x01JCQ7ZK3W8X5V2N9M4B6T1R0Y","n"]', GroupKey::canonical('c', 'x01JCQ7ZK3W8X5V2N9M4B6T1R0Y', 'n'));
    }

    /**
     * Rejects: a normalised first line shown to the user (display keeps the
     * exact first line).
     */
    public function test_the_first_line_itself_is_not_normalised(): void
    {
        self::assertSame('Order 1234 not found (Connection: mysql)', GroupKey::firstLine("Order 1234 not found (Connection: mysql)\nstack"));
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
