<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Support;

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Justrau\Orrery\Runtime\SafeError;
use Justrau\Orrery\Storage\PackageTransactionBusy;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SafeError: the allowlisted summary of a watched failure, so
 * a report never carries a job value from the SQL bindings or the driver
 * text.
 */
final class SafeErrorTest extends TestCase
{
    private const SECRET = 'probe-secret-pii@example.com';

    /**
     * @return array<string, array{QueryException, string, string}>
     */
    public static function valueCarryingDriverTexts(): array
    {
        $secret = self::SECRET;

        return [
            'postgres 23502 failing row' => [
                self::query('pgsql', 'insert into "orrery_jobs" ("uuid", "payload") values (?, ?)', ['0b5f…', $secret], '23502', 7, "ERROR:  null value in column \"queue\" of relation \"orrery_jobs\" violates not-null constraint\nDETAIL:  Failing row contains (1, {$secret}, null)."),
                '23502',
                '7',
            ],
            'mysql 1062 duplicate entry' => [
                self::query('mysql', 'insert into `orrery_jobs` (`uuid`, `payload`) values (?, ?)', ['x', $secret], '23000', 1062, "Duplicate entry '{$secret}' for key 'orrery_jobs_uuid_unique'"),
                '23000',
                '1062',
            ],
            'postgres 22P02 invalid uuid' => [
                self::query('pgsql', 'select * from "orrery_jobs" where "uuid" = ?', [$secret], '22P02', 7, "ERROR:  invalid input syntax for type uuid: \"{$secret}\""),
                '22P02',
                '7',
            ],
            'mysql 42000 syntax error near a value' => [
                self::query('mysql', 'insert into `orrery_jobs` (`payload`) values (?)', [$secret], '42000', 1064, "You have an error in your SQL syntax; check the manual near '{$secret}' at line 1"),
                '42000',
                '1064',
            ],
        ];
    }

    /**
     * Rejects: the verbatim driver message, a first line kept for the SQLSTATE class 22 or 23, and the raw SQL with its bindings.
     * Still passes: a summary that also leaves out the driver text of a class 42 error without a quoted value.
     */
    #[DataProvider('valueCarryingDriverTexts')]
    public function test_driver_text_with_values_is_left_out(QueryException $error, string $state, string $code): void
    {
        $summary = SafeError::summary('mirror:write', $error);

        self::assertStringNotContainsString(self::SECRET, $summary);
        self::assertStringContainsString("SQLSTATE {$state}", $summary);
        self::assertStringContainsString("driver code {$code}", $summary);
        self::assertStringContainsString($error->getSql(), $summary, 'the SQL template with its ? placeholders');
        self::assertStringContainsString(QueryException::class, $summary);
        self::assertStringContainsString('mirror:write', $summary);
    }

    /**
     * Rejects: a summary so generic that the operator cannot find the cause (no driver text at all).
     */
    public function test_safe_driver_text_is_kept(): void
    {
        $sqlite = SafeError::summary('mirror:write', self::query('sqlite', 'insert into "orrery_jobs" ("payload") values (?)', [self::SECRET], 'HY000', 1, 'no such table: orrery_jobs'));
        $postgres = SafeError::summary('mirror:write', self::query('pgsql', 'insert into "orrery_jobs" ("payload") values (?)', [self::SECRET], '42P01', 7, "ERROR:  relation \"orrery_jobs\" does not exist\nLINE 1: insert into \"orrery_jobs\" (\"payload\") values (\$1)"));

        self::assertStringContainsString('no such table: orrery_jobs', $sqlite);
        self::assertStringContainsString('relation "orrery_jobs" does not exist', $postgres);
        self::assertStringNotContainsString('LINE 1', $postgres, 'only the first line of the driver text');
        self::assertStringNotContainsString(self::SECRET, $sqlite.$postgres);
    }

    /**
     * Rejects: a SQLite allowlist that keeps every HY000 text (SQLite's constraint errors carry values too).
     */
    public function test_sqlite_text_outside_the_prefix_list_is_left_out(): void
    {
        $summary = SafeError::summary('mirror:write', self::query('sqlite', 'update "orrery_jobs" set "queue" = ?', [self::SECRET], 'HY000', 1, 'datatype mismatch near '.self::SECRET));

        self::assertStringNotContainsString(self::SECRET, $summary);
        self::assertStringContainsString('SQLSTATE HY000', $summary);
    }

    /**
     * Rejects: cleaning only Throwable problems, so a string problem that embeds getMessage() of a QueryException keeps the values.
     */
    public function test_string_problem_with_embedded_query_message_is_stripped(): void
    {
        $query = self::query('mysql', 'select `id` from `jobs` where `id` in (?)', [self::SECRET], '40001', 1213, "Deadlock found when trying to get lock; value '".self::SECRET."'");
        $problem = 'The jobs of the queue connection [database] were not checked: '.$query->getMessage();

        $stripped = SafeError::strip($problem);
        $summary = SafeError::summary('reconcile:active', $problem);

        self::assertSame('The jobs of the queue connection [database] were not checked: SQLSTATE[40001]', $stripped);
        self::assertStringNotContainsString(self::SECRET, $summary);
        self::assertStringContainsString('SQLSTATE[40001]', $summary);
        self::assertStringContainsString('reconcile:active', $summary);
    }

    /**
     * Rejects: a strip() that keeps Laravel's "(Connection: …, SQL: …)" suffix with the substituted bindings.
     */
    public function test_strip_cuts_the_connection_suffix_and_control_characters(): void
    {
        self::assertSame('it failed', SafeError::strip('it failed (Connection: mysql, SQL: insert into x values ('.self::SECRET.'))'));
        self::assertSame('a b c', SafeError::strip("a\nb\x00c"));
    }

    /**
     * Rejects: a substr() that splits a multibyte character, and a summary without a length limit.
     */
    public function test_summary_is_cut_on_a_utf8_boundary(): void
    {
        $long = str_repeat('ž', 5000); // 10,000 bytes
        $string = SafeError::summary('test:long', 'x'.$long);
        $throwable = SafeError::summary('test:long', new RuntimeException($long));

        foreach ([$string, $throwable, SafeError::strip('x'.$long)] as $summary) {
            self::assertLessThanOrEqual(SafeError::MAX_BYTES, strlen($summary));
            self::assertGreaterThan(400, strlen($summary));
            self::assertTrue(mb_check_encoding($summary, 'UTF-8'), 'the cut keeps valid UTF-8');
        }

        // Another Throwable keeps at most 500 bytes of its first line.
        self::assertLessThanOrEqual(SafeError::OTHER_BYTES, strlen((string) strstr($throwable, 'ž')));
    }

    /**
     * Rejects: the whole message of another Throwable (only its first line is kept).
     */
    public function test_other_throwable_keeps_its_class_and_first_line(): void
    {
        $summary = SafeError::summary('test:other', new RuntimeException("The cache store is down.\n".self::SECRET));

        self::assertStringContainsString(RuntimeException::class.': The cache store is down.', $summary);
        self::assertStringNotContainsString(self::SECRET, $summary);
    }

    /**
     * Rejects: the outer exception only (the query cause is lost), and the PDO message twice (the QueryException's own PDOException is summarised again).
     */
    public function test_previous_chain_is_summarised_once(): void
    {
        $query = self::query('sqlite', 'insert into "orrery_jobs" ("payload") values (?)', [self::SECRET], 'HY000', 5, 'database is locked');
        $busy = new PackageTransactionBusy('The SQLite package transaction got SQLITE_BUSY in each of its 3 attempts: '.$query->getMessage(), 0, $query);

        $summary = SafeError::summary('mirror:write', $busy);

        self::assertStringContainsString(PackageTransactionBusy::class.': The SQLite package transaction got SQLITE_BUSY in each of its 3 attempts: SQLSTATE[HY000]', $summary);
        self::assertStringContainsString(' ← '.QueryException::class, $summary);
        self::assertSame(1, substr_count($summary, 'database is locked'), 'the driver text appears once');
        self::assertStringNotContainsString(PDOException::class, $summary);
        self::assertStringNotContainsString(self::SECRET, $summary);
    }

    /**
     * Rejects: a cause without the SQLSTATE and the driver code of the query in its chain.
     */
    public function test_cause_reads_the_state_of_the_first_query_in_the_chain(): void
    {
        $query = self::query('pgsql', 'select 1', [], '57014', 7, 'ERROR:  canceling statement due to statement timeout');
        $cause = SafeError::cause(new RuntimeException('outer', 0, $query));

        self::assertSame(['class' => RuntimeException::class, 'sqlState' => '57014', 'driverCode' => 7], $cause);
        self::assertSame(['class' => RuntimeException::class, 'sqlState' => null, 'driverCode' => null], SafeError::cause('a string problem'));
    }

    /**
     * Rejects: a URL rule for throwables only, one that strips only the query string, and one that keeps user information.
     */
    public function test_urls_keep_only_their_scheme_and_host(): void
    {
        $string = SafeError::summary('notification:slack', 'The webhook https://hooks.slack.com/services/T1/B2/secret-path failed.');
        $throwable = SafeError::summary('notification:mail', new RuntimeException('Cannot reach http://user:pa55@mail.example.com:8025/send?key=k#f now'));

        self::assertStringContainsString('The webhook https://hooks.slack.com/… failed.', $string);
        self::assertStringContainsString('Cannot reach http://mail.example.com:8025/… now', $throwable);
        self::assertStringNotContainsString('secret-path', $string);
        self::assertStringNotContainsString('pa55', $throwable);
        self::assertStringNotContainsString('/send', $throwable);
        self::assertSame('see https://example.com', SafeError::strip('see https://example.com'));
    }

    /**
     * Rejects: a rule that needs a word boundary before the scheme, one that misses the JSON-escaped form, and one that takes the host after the first "@" or stops the user information at a "/".
     *
     * @return array<string, array{string, string, list<string>}>
     */
    public static function hiddenUrls(): array
    {
        return [
            'JSON-escaped' => ['{"url":"https:\\/\\/hooks.slack.com\\/services\\/T1\\/B2\\/json-secret"}', 'https://hooks.slack.com/…', ['json-secret', 'services', 'T1']],
            'after a word character' => ['webhook_https://hooks.slack.com/services/T1/B2/word-secret failed', 'webhook_https://hooks.slack.com/… failed', ['word-secret', 'services']],
            'user information with @' => ['get https://user:p@ss@host.example.com/path-secret failed', 'get https://host.example.com/… failed', ['user', 'p@ss', 'path-secret']],
            'a password with / and @' => ['get https://us/er:p@ss/w@host.example.com/path-secret?q=1 failed', 'get https://… failed', ['us/er', 'p@ss', 'path-secret', 'er:p', 'host.example.com', '/w@']],
            'an @ in the path' => ['post https://hooks.slack.com/services/T/B/se@cret/x failed', 'post https://… failed', ['cret', 'se@', 'services', 'hooks.slack.com']],
            'an @ in the segment after the host' => ['post https://hooks.slack.com/@secret-seg/x failed', 'post https://… failed', ['secret-seg', 'hooks.slack.com']],
            'an @ only in the query' => ['get https://host.example.com/x?mail=a@leak.example failed', 'get https://… failed', ['leak.example', 'mail', 'host.example.com']],
            'a password with ?' => ['get https://user:pa?ss@host.example.com/x failed', 'get https://… failed', ['user', 'pa?ss', 'host.example.com']],
            'a password with #' => ['get https://user:pa#ss@host.example.com failed', 'get https://… failed', ['user', 'pa#ss', 'host.example.com']],
            'a password @ and a later @ after a /' => ['get https://user:p@ss/w@host.example.com failed', 'get https://… failed', ['user', 'ss', 'host.example.com']],
            'two @ in the user information' => ['get https://user:p@ss@host.example.com/x failed', 'get https://host.example.com/… failed', ['user', 'p@ss']],
            'a user:pass host' => ['get https://user:secretpass/x failed', 'get https://…/… failed', ['user', 'secretpass']],
            'an IPv6 host with a port' => ['get https://[2001:db8::1]:8443/path-secret failed', 'get https://[2001:db8::1]:8443/… failed', ['path-secret']],
            'a host with a port' => ['get http://mail.example.com:8025/path-secret failed', 'get http://mail.example.com:8025/… failed', ['path-secret']],
        ];
    }

    #[DataProvider('hiddenUrls')]
    public function test_hidden_url_forms_keep_only_their_scheme_and_host(string $message, string $expected, array $absent): void
    {
        foreach ([SafeError::strip($message), SafeError::summary('test:url', new RuntimeException($message))] as $text) {
            self::assertStringContainsString($expected, $text);

            foreach ($absent as $part) {
                self::assertStringNotContainsString($part, $text);
            }
        }
    }

    /**
     * Rejects: a cause that takes the first PDOException of the chain, so a DeadlockException that wraps a QueryException loses the driver code.
     */
    public function test_cause_prefers_the_query_exception_of_a_deadlock(): void
    {
        $query = self::query('mysql', 'update `orrery_jobs` set `status` = ?', [], '40001', 1213, 'Deadlock found when trying to get lock');
        $deadlock = new DeadlockException($query->getMessage(), 0, $query);

        self::assertSame(['class' => DeadlockException::class, 'sqlState' => '40001', 'driverCode' => 1213], SafeError::cause($deadlock));
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private static function query(string $connection, string $sql, array $bindings, string $state, int $code, string $driverText): QueryException
    {
        $pdo = new PDOException("SQLSTATE[{$state}]: General error: {$code} {$driverText}");
        $pdo->errorInfo = [$state, $code, $driverText];
        $reflection = new \ReflectionProperty(\Exception::class, 'code');
        $reflection->setValue($pdo, $state);

        return new QueryException($connection, $sql, $bindings, $pdo);
    }
}
