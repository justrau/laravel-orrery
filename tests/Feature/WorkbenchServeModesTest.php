<?php

namespace Justrau\Orrery\Tests\Feature;

use Justrau\Orrery\Tests\Support\WorkbenchCliTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Workbench\App\Http\Middleware\ContentSecurityPolicy;
use Workbench\App\Support\WorkbenchAuth;
use Workbench\App\Support\WorkbenchConfiguration;

/**
 * The Workbench switches reach the processes that they configure, through
 * the launcher: WORKBENCH_AUTH the CLI and the web server child of `serve`, and the rollback-mode
 * switch ORRERY_TEST_SQLITE_JOURNAL the web server only, and the display zone
 * ORRERY_DISPLAY_TIMEZONE the page; the served dashboard carries the enforced Content Security
 * Policy of the provider's middleware. WorkbenchStrictAuthTest covers the
 * HTTP rules of the strict mode.
 */
#[Group('workbench')]
final class WorkbenchServeModesTest extends WorkbenchCliTestCase
{
    private const SERVER_START_SECONDS = 30.0;

    /**
     * Rejects: a provider that reads the mode only in the CLI, so the served dashboard stays
     * allow-all (Laravel's serve command drops most variables of its own environment).
     */
    public function test_the_web_server_of_serve_runs_the_strict_mode(): void
    {
        $environment = ['DB_DATABASE' => $this->database(), WorkbenchAuth::VARIABLE => WorkbenchAuth::STRICT];

        $this->assertSucceeded($this->testbench(['migrate', '--force'], $environment), 'migrate --force in the strict mode');

        $port = self::freePort();
        $server = $this->startPhp([$this->repository.'/workbench/bin/testbench', 'serve', '--host=127.0.0.1', '--port='.$port, '--no-ansi', '--no-interaction'], $environment);
        $responses = [];

        try {
            $this->waitUntil(static fn (): bool => self::accepts($port), $server, sprintf('serve did not listen on port %d', $port));

            foreach (['/orrery/api/workload', '/orrery', '/'.WorkbenchAuth::LOGIN_PATH] as $path) {
                $responses[$path] = self::get($port, $path);
            }
        } finally {
            $server->stop(10);
        }

        $statuses = array_map(static fn (array $response): int => $response['status'], $responses);
        $this->assertSame(['/orrery/api/workload' => 401, '/orrery' => 401, '/login' => 200], $statuses, "The web server answered:\n".implode("\n", array_column($responses, 'body'))."\n".$server->getOutput().$server->getErrorOutput());
        $this->assertStringContainsString('{"error":"unauthenticated"}', $responses['/orrery/api/workload']['body']);
        $this->assertStringContainsString('name="_token"', $responses['/login']['body']);
    }

    /**
     * Rejects: a CLI that ignores the mode (the environment, the session driver and the gates).
     */
    public function test_the_cli_runs_the_strict_mode_in_production_with_file_sessions(): void
    {
        $environment = ['DB_DATABASE' => $this->database(), WorkbenchAuth::VARIABLE => WorkbenchAuth::STRICT];

        $this->assertSucceeded($this->testbench(['migrate', '--force'], $environment), 'migrate --force in the strict mode');
        $probe = $this->testbench(['workbench:ownership'], $environment);
        $this->assertSucceeded($probe, 'workbench:ownership in the strict mode');

        $report = self::probeReport($probe);

        $this->assertSame(WorkbenchAuth::STRICT_ENVIRONMENT, $report['environment']);
        $this->assertSame(['/orrery/dashboard' => 401, '/orrery/api/workload' => 401], $report['http']);
        $this->assertSame('Illuminate\Session\FileSessionHandler', $report['session']['handler']);
    }

    /**
     * Rejects: a display zone that only the CLI sees (Laravel's Env repository reads $_SERVER and
     * $_ENV, which PHP's built-in server leaves without the process environment), so the phase
     * gate's `ORRERY_DISPLAY_TIMEZONE=Asia/Tokyo composer serve` would serve the browser's zone.
     */
    public function test_the_web_server_of_serve_passes_the_display_zone_to_the_page(): void
    {
        $environment = ['DB_DATABASE' => $this->database(), WorkbenchConfiguration::DISPLAY_TIMEZONE_VARIABLE => 'Asia/Tokyo'];

        $this->assertSucceeded($this->testbench(['migrate', '--force'], $environment), 'migrate --force with a display zone');

        $port = self::freePort();
        $server = $this->startPhp([$this->repository.'/workbench/bin/testbench', 'serve', '--host=127.0.0.1', '--port='.$port, '--no-ansi', '--no-interaction'], $environment);

        try {
            $this->waitUntil(static fn (): bool => self::accepts($port), $server, sprintf('serve did not listen on port %d', $port));
            // The page inlines the bundle before the variables, so the whole body is read.
            $response = self::get($port, '/orrery/overview', null);
        } finally {
            $server->stop(10);
        }

        $this->assertSame(200, $response['status'], "The page failed:\n".substr($response['body'], 0, 2000));
        $this->assertStringContainsString('"displayTimezone":"Asia\\/Tokyo"', $response['body']);
    }

    /**
     * Rejects: a Workbench whose provider does not add its Content Security Policy middleware to
     * `orrery.middleware` (WorkbenchServiceProvider::register()), so `composer serve` and every
     * browser check run without an enforced policy; a report-only header; and a header nonce
     * that the inlined elements of the served page do not carry.
     */
    public function test_the_web_server_of_serve_sends_the_enforced_policy(): void
    {
        $environment = ['DB_DATABASE' => $this->database()];

        $this->assertSucceeded($this->testbench(['migrate', '--force'], $environment), 'migrate --force');

        $port = self::freePort();
        $server = $this->startPhp([$this->repository.'/workbench/bin/testbench', 'serve', '--host=127.0.0.1', '--port='.$port, '--no-ansi', '--no-interaction'], $environment);

        try {
            $this->waitUntil(static fn (): bool => self::accepts($port), $server, sprintf('serve did not listen on port %d', $port));
            $response = self::get($port, '/orrery/overview', null);
        } finally {
            $server->stop(10);
        }

        $this->assertSame(200, $response['status'], "The page failed:\n".substr($response['body'], 0, 2000));
        [$head, $body] = explode("\r\n\r\n", $response['body'], 2) + [1 => ''];
        $this->assertDoesNotMatchRegularExpression('/^Content-Security-Policy-Report-Only:/mi', $head);
        $this->assertSame(1, preg_match("/^Content-Security-Policy: (.+)\r?$/mi", $head, $header), "The served page has no enforced policy:\n".$head);
        $this->assertSame(1, preg_match("/'nonce-([A-Za-z0-9]{40})'/", $header[1], $nonce));
        $this->assertSame(str_replace('{nonce}', $nonce[1], ContentSecurityPolicy::POLICY), trim($header[1]));

        preg_match_all('/<(?:script|style)\b[^>]*>/', $body, $tags);
        $this->assertCount(3, $tags[0], 'the stylesheet, window.Orrery and the application');
        foreach ($tags[0] as $tag) {
            $this->assertStringContainsString('nonce="'.$nonce[1].'"', $tag);
        }
    }

    /**
     * Rejects: a mistyped mode that falls back to the allow-all mode.
     */
    public function test_an_unknown_mode_refuses_to_start(): void
    {
        $run = $this->testbench(['about'], ['DB_DATABASE' => $this->database(), WorkbenchAuth::VARIABLE => 'Strict']);

        $this->assertNotSame(0, $run->getExitCode(), "The Workbench started with WORKBENCH_AUTH=Strict:\n".$run->getOutput().$run->getErrorOutput());
        $this->assertOutputContains('The Workbench refuses WORKBENCH_AUTH=[Strict]', $run);
    }

    /**
     * Rejects: a switch that the web server does not see, or that also changes the CLI (the
     * build's counts need automatic reads, and a worker runs beside the dashboard in WAL).
     */
    public function test_the_rollback_switch_serves_without_automatic_reads_and_keeps_the_cli_in_wal(): void
    {
        $database = $this->database();
        $rollback = ['DB_DATABASE' => $database, WorkbenchConfiguration::JOURNAL_VARIABLE => WorkbenchConfiguration::ROLLBACK];

        $this->assertSucceeded($this->testbench(['migrate', '--force'], $rollback), 'migrate --force with the rollback switch');
        $this->assertSame('wal', self::journalMode($database), 'The CLI left WAL.');

        $warnings = [];

        foreach (['default' => ['DB_DATABASE' => $database], 'rollback' => $rollback] as $mode => $environment) {
            $port = self::freePort();
            $server = $this->startPhp([$this->repository.'/workbench/bin/testbench', 'serve', '--host=127.0.0.1', '--port='.$port, '--no-ansi', '--no-interaction'], $environment);

            try {
                $this->waitUntil(static fn (): bool => self::accepts($port), $server, sprintf('serve did not listen on port %d', $port));
                $response = self::get($port, '/orrery/api/health');
            } finally {
                $server->stop(10);
            }

            $this->assertSame(200, $response['status'], "The {$mode} health read failed:\n".$response['body']);
            $body = (array) json_decode(substr($response['body'], (int) strpos($response['body'], "\r\n\r\n") + 4), true);
            $warnings[$mode] = array_column((array) ($body['sqlite_warnings'] ?? []), 'file');
            $journals[$mode] = self::journalMode($database);
        }

        $this->assertSame([], $warnings['default'], 'The default mode has a SQLite warning.');
        $this->assertContains(realpath($database), $warnings['rollback'], 'The rollback mode allows automatic reads.');
        $this->assertSame(['default' => 'wal', 'rollback' => 'delete'], $journals);

        $invalid = $this->testbench(['about'], ['DB_DATABASE' => $database, WorkbenchConfiguration::JOURNAL_VARIABLE => 'rollback']);
        $this->assertNotSame(0, $invalid->getExitCode(), 'The Workbench started with an unknown journal switch.');
        $this->assertOutputContains('The Workbench refuses ORRERY_TEST_SQLITE_JOURNAL=[rollback]', $invalid);
    }

    private static function journalMode(string $database): string
    {
        $pdo = new \PDO('sqlite:'.$database);
        $mode = $pdo->query('PRAGMA journal_mode')?->fetchColumn();
        $pdo = null;

        return is_string($mode) ? $mode : '';
    }

    private function database(): string
    {
        return $this->ownedDirectory.DIRECTORY_SEPARATOR.'database.sqlite';
    }

    private function waitUntil(callable $condition, Process $child, string $what): void
    {
        $deadline = microtime(true) + self::SERVER_START_SECONDS;

        while (microtime(true) < $deadline) {
            $child->checkTimeout();

            if ($condition()) {
                return;
            }

            if (! $child->isRunning()) {
                self::fail($what.": the child exited first.\n".$child->getOutput().$child->getErrorOutput());
            }

            usleep(20_000);
        }

        self::fail($what.".\n".$child->getOutput().$child->getErrorOutput());
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, 'Cannot find a free port: '.$errorMessage);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private static function accepts(int $port): bool
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:'.$port, $errorCode, $errorMessage, 0.5);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * One plain HTTP/1.1 GET request; the body is cut at `$limit` bytes (null: the whole body).
     *
     * @return array{status: int, body: string}
     */
    private static function get(int $port, string $path, ?int $limit = 20000): array
    {
        $socket = stream_socket_client('tcp://127.0.0.1:'.$port, $errorCode, $errorMessage, 5);
        self::assertIsResource($socket, 'Cannot connect to the web server: '.$errorMessage);
        stream_set_timeout($socket, 30);

        fwrite($socket, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nAccept: application/json\r\nConnection: close\r\n\r\n");
        $response = (string) stream_get_contents($socket);
        fclose($socket);

        preg_match('#^HTTP/\S+ (\d{3})#', $response, $matches);

        return ['status' => (int) ($matches[1] ?? 0), 'body' => $limit === null ? $response : substr($response, 0, $limit)];
    }
}
