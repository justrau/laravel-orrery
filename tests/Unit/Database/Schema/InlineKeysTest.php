<?php

namespace Justrau\Orrery\Tests\Unit\Database\Schema;

use Justrau\Orrery\Database\Schema\InlineKeys;
use PHPUnit\Framework\TestCase;

final class InlineKeysTest extends TestCase
{
    private const HEAD = 'create table `pfx_t` (';

    /**
     * Rejects: a splice at the first `)` (inside `varchar(5)`), at the last `)` of the statement
     * (inside a quoted table option after the column list), or one that counts a parenthesis inside
     * a quoted default, a quoted comment with an escaped quote, or a quoted identifier.
     */
    public function test_the_keys_go_before_the_parenthesis_that_closes_the_column_list(): void
    {
        $create = self::HEAD."`a` varchar(5) not null default ')(', `b)` decimal(16, 6) null comment 'it\\'s (x', `c` int not null default '''('"
            .") default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB comment = 'a)'";

        $this->assertSame(
            self::HEAD."`a` varchar(5) not null default ')(', `b)` decimal(16, 6) null comment 'it\\'s (x', `c` int not null default '''('"
            .', unique `pfx_t_a`(`a`), index `pfx_t_b`(`b)`, `c`)'
            .") default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB comment = 'a)'",
            InlineKeys::splice($create, self::HEAD, ['unique `pfx_t_a`(`a`)', 'index `pfx_t_b`(`b)`, `c`)']),
        );
    }

    /**
     * Rejects: a splice that reads a backslash inside a quoted identifier as an escape (MySQL
     * takes it literally, so `a\` ends at its second backtick), or that does not read a doubled
     * backtick as a backtick inside the identifier.
     */
    public function test_backslashes_and_doubled_backticks_in_identifiers(): void
    {
        $create = self::HEAD."`a\\` int not null, `b``)` int null) default character set utf8mb4";

        $this->assertSame(
            self::HEAD."`a\\` int not null, `b``)` int null, index `pfx_t_b`(`b``)`)) default character set utf8mb4",
            InlineKeys::splice($create, self::HEAD, ['index `pfx_t_b`(`b``)`)']),
        );
    }

    /**
     * Rejects: a splice into a statement of an unknown form (another table, an unclosed column
     * list, an unclosed quote), which would build a wrong table instead of using Laravel's
     * separate statements.
     */
    public function test_an_unknown_statement_gives_null(): void
    {
        $this->assertNull(InlineKeys::splice('create table `pfx_other` (`a` int)', self::HEAD, ['index `i`(`a`)']));
        $this->assertNull(InlineKeys::splice('create temporary table `pfx_t` (`a` int)', self::HEAD, ['index `i`(`a`)']));
        $this->assertNull(InlineKeys::splice(self::HEAD.'`a` decimal(16, 6)', self::HEAD, ['index `i`(`a`)']));
        $this->assertNull(InlineKeys::splice(self::HEAD."`a` int default ')", self::HEAD, ['index `i`(`a`)']));
    }

    /**
     * Rejects: a splice that adds a trailing ", " when the table has no key to inline.
     */
    public function test_no_keys_leave_the_statement_unchanged(): void
    {
        $create = self::HEAD.'`a` int not null, primary key (`a`)) default character set utf8mb4';

        $this->assertSame($create, InlineKeys::splice($create, self::HEAD, []));
    }
}
