<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Exceptions\WatchedOperationFailed;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Support\Jobs\PersonalDataJob;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\TestCase;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * The watcher's reports and logs carry no job value. The
 * scenario: the package tables are missing (composer require without migrate), and a job
 * whose payload has a personal value is pushed and worked.
 */
#[FreshSchema]
final class SafeReportTest extends TestCase
{
    private const SECRET = 'probe-secret-pii@example.com';

    private const TABLES = ['orrery_job_tags', 'orrery_jobs', 'orrery_samples', 'orrery_worker_heartbeats', 'orrery_signals'];

    private string|false $previousIgnoreArgs = false;

    private string|false $previousErrorLog = false;

    private ?string $errorLog = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The default without a php.ini (for example in the official PHP images): frames carry their arguments.
        $this->previousIgnoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->previousIgnoreArgs === false ? '1' : $this->previousIgnoreArgs);

        if ($this->errorLog !== null) {
            ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
            @unlink($this->errorLog);
        }

        parent::tearDown();
    }

    /**
     * Rejects: reporting the original QueryException, a wrapper that keeps it as its previous, and a fresh exception with its natural trace (whose frames carry the job and its payload).
     * Still passes: a report whose message keeps a value that a host exception (not a query) put on its first line; that path is stated in docs/security.md.
     */
    public function test_failed_mirror_write_reports_no_payload_value(): void
    {
        $this->dropPackageTables(self::TABLES);
        Exceptions::fake();

        Queue::push(new PersonalDataJob(self::SECRET));
        self::assertStringContainsString(self::SECRET, (string) DB::table('jobs')->value('payload'), 'the payload carries the value');

        $this->work();
        self::assertSame(0, DB::table('jobs')->count(), 'the host job ran and was deleted');

        $reported = self::reportedExceptions();
        self::assertNotEmpty($reported, 'the failed mirror writes are reported');

        foreach ($reported as $error) {
            self::assertInstanceOf(WatchedOperationFailed::class, $error);
            self::assertNull($error->getPrevious(), 'no previous exception');
            self::assertSame([], $error->getTrace(), 'an empty trace');
            self::assertStringEndsWith('Watcher.php', $error->getFile());
            self::assertStringContainsString($error->operation, $error->getMessage(), 'the report names the operation');
            $this->assertClean($error);
        }

        if ($this->testEnvironment()->pooled) {
            // A pooled session names every table with its schema: a dropped table fails
            // before any query is sent (PooledRelationUnresolved), so no SQLSTATE exists.
            return;
        }

        $queries = array_filter($reported, static fn (Throwable $e): bool => $e instanceof WatchedOperationFailed && $e->sqlState !== null);
        self::assertNotEmpty($queries, 'a query failure keeps its SQLSTATE as a field');
    }

    /**
     * Rejects: an error_log() fallback or a degradation log that prints the raw message of the failure.
     */
    public function test_error_log_fallback_and_degradation_log_have_no_payload_value(): void
    {
        $this->dropPackageTables([...self::TABLES, 'orrery_health_events']);
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'orrery-safe-report-');
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->errorLog);

        $this->app->instance(ExceptionHandler::class, new class implements ExceptionHandler
        {
            public function report(Throwable $e): void
            {
                throw new RuntimeException('the exception handler fails');
            }

            public function shouldReport(Throwable $e): bool
            {
                return true;
            }

            public function render($request, Throwable $e)
            {
                throw $e;
            }

            public function renderForConsole($output, Throwable $e): void {}
        });

        /** @var list<string> $logged */
        $logged = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.print_r($event->context, true);
        });

        Queue::push(new PersonalDataJob(self::SECRET));
        $this->work();
        self::assertSame(0, DB::table('jobs')->count(), 'the host job ran and was deleted');

        Degradation::flush();

        $log = (string) file_get_contents($this->errorLog);
        self::assertStringContainsString('orrery:', $log, 'the fallback logged the failed reports');
        self::assertStringContainsString('the exception handler fails', $log);
        self::assertStringNotContainsString(self::SECRET, $log);

        self::assertNotEmpty($logged, 'the failed flush logged the record');
        self::assertStringContainsString('degradation record', implode("\n", $logged));
        self::assertStringNotContainsString(self::SECRET, implode("\n", $logged));
    }

    /**
     * Rejects: a degradation log that keeps the raw message of the failed flush (a QueryException message with its substituted bindings).
     */
    public function test_degradation_log_has_no_value_of_the_flush_error(): void
    {
        /** @var list<string> $logged */
        $logged = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.print_r($event->context, true);
        });

        $armed = true;
        Container::getInstance()->make(PackageSession::class)->mirror()->beforeExecuting(static function (string $sql) use (&$armed): void {
            // Only the flush's own statements; the teardown later drops the table.
            if ($armed && str_contains($sql, 'orrery_health_events')) {
                $pdo = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed');
                $pdo->errorInfo = ['23000', 19, 'UNIQUE constraint failed'];

                throw new QueryException('testbench', 'insert into "orrery_health_events" ("identity") values (?)', [self::SECRET], $pdo);
            }
        });

        Degradation::error('test:operation', 'a failure');
        Degradation::flush();
        $armed = false;

        $log = implode("\n", $logged);
        self::assertStringContainsString('degradation record', $log, 'the failed flush was logged');
        self::assertStringContainsString('SQLSTATE 23000', $log, 'with the summary of its error');
        self::assertStringNotContainsString(self::SECRET, $log);
    }

    /**
     * A Slack webhook URL carries its credential in the path, and Guzzle's message keeps the path
     * ("POST https://hooks.slack.com/services/T…/B…/<secret> resulted in a 404 …").
     *
     * Rejects: keeping the first line of the message as it is, and stripping only the query string.
     */
    public function test_failed_webhook_reports_no_url_path(): void
    {
        $secret = 'xoxsecretSEGMENT42';
        $url = 'https://hooks.slack.com/services/T000ORRERY/B000ORRERY/'.$secret.'?token=query-secret';
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(404, [], 'no_service'),
            new Response(404, [], 'no_service'),
            new Response(404, [], 'no_service'),
        ]))]);
        $post = static fn (): mixed => $client->post($url, ['json' => ['text' => 'a long wait']]);

        // The report.
        Exceptions::fake();
        $result = Watcher::instance()->attempt('notification:slack', $post);
        self::assertFalse($result->succeeded());
        self::assertInstanceOf(ClientException::class, $result->error(), 'the control: Guzzle threw its 404 exception');
        self::assertStringContainsString($secret, $result->error()->getMessage(), 'the control: Guzzle\'s own message keeps the path');

        $reported = self::reportedExceptions();
        self::assertCount(1, $reported);
        self::assertInstanceOf(WatchedOperationFailed::class, $reported[0]);
        self::assertStringContainsString('https://hooks.slack.com/', $reported[0]->getMessage(), 'the scheme and the host stay');
        self::assertStringContainsString('404', $reported[0]->getMessage());
        self::assertFalse(self::hasUrlPart($reported[0]->getMessage(), $secret), $reported[0]->getMessage());

        // The error_log fallback (a throwing handler).
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'orrery-safe-report-');
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->errorLog);
        $this->app->instance(ExceptionHandler::class, new class implements ExceptionHandler
        {
            public function report(Throwable $e): void
            {
                throw new RuntimeException('the exception handler fails');
            }

            public function shouldReport(Throwable $e): bool
            {
                return true;
            }

            public function render($request, Throwable $e)
            {
                throw $e;
            }

            public function renderForConsole($output, Throwable $e): void {}
        });
        Watcher::instance()->attempt('notification:slack:fallback', $post);
        $log = (string) file_get_contents($this->errorLog);
        self::assertStringContainsString('notification:slack:fallback', $log);
        self::assertFalse(self::hasUrlPart($log, $secret), $log);

        // The degradation log: the flush fails with the webhook error.
        /** @var list<string> $logged */
        $logged = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.print_r($event->context, true);
        });
        $armed = true;
        Container::getInstance()->make(PackageSession::class)->mirror()->beforeExecuting(static function (string $sql) use (&$armed, $post): void {
            if ($armed && str_contains($sql, 'orrery_health_events')) {
                $post();
            }
        });
        Degradation::error('test:operation', 'a failure');
        Degradation::flush();
        $armed = false;

        $text = implode("\n", $logged);
        self::assertStringContainsString('degradation record', $text);
        self::assertStringContainsString('https://hooks.slack.com/', $text);
        self::assertFalse(self::hasUrlPart($text, $secret), $text);
    }

    private static function hasUrlPart(string $text, string $secret): bool
    {
        return str_contains($text, $secret) || str_contains($text, 'T000ORRERY') || str_contains($text, 'B000ORRERY') || str_contains($text, '/services/') || str_contains($text, 'query-secret');
    }

    /**
     * @param  list<string>  $tables
     */
    private function dropPackageTables(array $tables): void
    {
        foreach ($tables as $table) {
            $this->mirrorSchema()->dropIfExists($table);
        }
    }

    private function assertClean(Throwable $error): void
    {
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            self::assertStringNotContainsString(self::SECRET, $e->getMessage(), 'the message of '.$e::class);

            foreach ($e->getTrace() as $frame) {
                self::assertFalse(self::carries($frame['args'] ?? [], 3), 'a trace argument of '.$e::class.' in '.($frame['function'] ?? '?'));
            }
        }
    }

    /**
     * Whether $value holds the secret: strings, arrays and objects, to $depth levels.
     */
    private static function carries(mixed $value, int $depth): bool
    {
        if (is_string($value)) {
            return str_contains($value, self::SECRET);
        }

        if ($depth <= 0 || (! is_array($value) && ! is_object($value))) {
            return false;
        }

        foreach ((array) $value as $item) {
            if (self::carries($item, $depth - 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every exception the faked handler recorded, of any class.
     * ExceptionHandlerFake::reported() does not exist on the lowest supported
     * Laravel 12, and assertReported() matches only one exact class, so the
     * fake's own list is read.
     *
     * @return list<Throwable>
     */
    private static function reportedExceptions(): array
    {
        $fake = Exceptions::getFacadeRoot();

        /** @var list<Throwable> $all */
        $all = (fn (): array => array_values($this->reported))->call($fake);

        return $all;
    }
}
