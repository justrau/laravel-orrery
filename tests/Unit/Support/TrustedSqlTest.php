<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\SQLiteConnection;
use InvalidArgumentException;
use Justrau\Orrery\Lifecycle\OutcomeAccounting;
use Justrau\Orrery\Storage\EscapedValue;
use Justrau\Orrery\Storage\TrustedSql;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TrustedSql builds the same SQL as the string concatenations that it
 * replaced. Each expected value is built exactly as
 * the old call site built it.
 */
final class TrustedSqlTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @return array<string, array{int}>
     */
    public static function integers(): array
    {
        return ['zero' => [0], 'one' => [1], 'large' => [9_007_199_254], 'negative' => [-3]];
    }

    /**
     * Rejects: a template or a placeholder that changes the SQL of an integer call site
     * (History, JobStore fast R1 and L1, BulkResolver), for example `%d` output or another spacing.
     * Still passes: a call site that uses another template than the one copied here.
     */
    #[DataProvider('integers')]
    public function test_integer_call_sites_build_the_same_sql_as_before(int $n): void
    {
        self::assertSame('history_discarded + '.$n, self::sql(TrustedSql::expression('history_discarded + %s', $n)));
        self::assertSame('COALESCE(cycle_first_job_id, '.$n.')', self::sql(TrustedSql::expression('COALESCE(cycle_first_job_id, %s)', $n)));
        self::assertSame(
            "CASE WHEN last_outcome_attempt < {$n} THEN observed_attempts + 1 ELSE observed_attempts END",
            self::sql(TrustedSql::expression('CASE WHEN last_outcome_attempt < %s THEN observed_attempts + 1 ELSE observed_attempts END', $n)),
        );
        self::assertSame(
            "CASE WHEN last_outcome_attempt < {$n} THEN {$n} ELSE last_outcome_attempt END",
            self::sql(TrustedSql::expression('CASE WHEN last_outcome_attempt < %1$s THEN %1$s ELSE last_outcome_attempt END', $n)),
        );
    }

    /**
     * Rejects: a gap template that differs from the old interpolated string by one byte.
     * Still passes: an old string that was itself wrong (the lifecycle tests check the value).
     */
    #[DataProvider('integers')]
    public function test_gap_expression_is_the_same_sql_as_before(int $n): void
    {
        $counted = "(observed_attempts + late_outcomes + (CASE WHEN last_outcome_attempt < {$n} THEN 1 ELSE 0 END))";

        self::assertSame("CASE WHEN {$counted} < {$n} THEN {$n} - {$counted} ELSE 0 END", self::sql(OutcomeAccounting::gapExpression($n)));
    }

    /**
     * Rejects: a batch expression that does not use the connection's escaping, or quotes differently.
     * Still passes: a connection whose escape() is itself wrong.
     */
    public function test_the_batch_call_site_builds_the_same_sql_with_the_connection_escaping(): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));

        foreach (['batch-1', "it's", '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'] as $batch) {
            self::assertSame(
                'COALESCE(batch_id, '.$connection->escape($batch).')',
                self::sql(TrustedSql::expression('COALESCE(batch_id, %s)', EscapedValue::of($connection, $batch))),
            );
        }

        self::assertSame("COALESCE(batch_id, 'it''s')", self::sql(TrustedSql::expression('COALESCE(batch_id, %s)', EscapedValue::of($connection, "it's"))));
    }

    /**
     * Rejects: an EscapedValue that quotes the value itself instead of calling Connection::escape().
     * Still passes: an EscapedValue that calls escape() and then changes the result.
     */
    public function test_escaped_value_goes_through_the_connection(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('escape')->once()->with('x\'y')->andReturn('<escaped>');

        $value = EscapedValue::of($connection, 'x\'y');

        self::assertSame('<escaped>', $value->sql);
        self::assertSame('COALESCE(batch_id, <escaped>)', self::sql(TrustedSql::expression('COALESCE(batch_id, %s)', $value)));
    }

    /**
     * Rejects: a template with another `%` sequence (for example `%d`, which casts an escaped string to 0, or `%%`).
     * Still passes: a literal template that is unsafe SQL by itself (PHPStan checks that it is literal).
     */
    public function test_a_template_with_another_placeholder_is_refused(): void
    {
        foreach (['COALESCE(batch_id, %d)', 'a %% b', 'x %'] as $template) {
            try {
                TrustedSql::expression($template, 1);
                self::fail("The template [{$template}] was accepted.");
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString($template, $error->getMessage());
            }
        }
    }

    private static function sql(Expression $expression): string
    {
        return (string) (fn () => $this->value)->call($expression);
    }
}
