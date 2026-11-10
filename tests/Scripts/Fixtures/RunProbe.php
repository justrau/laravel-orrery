<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts\Fixtures;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * A probe for the Docker tests of bin/test. It is in no test suite: the tests
 * run it by path, for example `bin/test mysql tests/Scripts/Fixtures/RunProbe.php`.
 *
 * It connects to the database that bin/test admitted, over the project port,
 * and writes what it saw to ORRERY_PROBE_OUT/probe-<pid>.json before it
 * asserts: the endpoint marker equals ORRERY_TEST_TOKEN, the connection
 * uses ORRERY_TEST_DATABASE, and the mirror database exists.
 *
 * - ORRERY_PROBE_SENTINEL=1 creates the table orrery_probe_sentinel first.
 * - ORRERY_PROBE_HOLD=<dir> creates <dir>/reached, then waits for <dir>/release.
 * - ORRERY_PROBE_IGNORE_TERM=1 ignores SIGTERM, like a process that is slow to stop.
 * - ORRERY_PROBE_MEET=<dir> with ORRERY_PROBE_PEERS=<n>: the two-sided hold of
 *   n concurrent runs (see meet()). The result is in "met".
 */
final class RunProbe extends TestCase
{
    public function test_probe(): void
    {
        if (getenv('ORRERY_PROBE_IGNORE_TERM') === '1') {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, SIG_IGN);
        }

        $driver = (string) getenv('ORRERY_TEST_DRIVER');
        $database = (string) getenv('ORRERY_TEST_DATABASE');
        $password = getenv('ORRERY_TEST_PASSWORD') ?: 'orrery';
        $record = ['pid' => getmypid(), 'ppid' => posix_getppid(), 'env' => $this->environment(), 'driver' => $driver];

        try {
            if ($driver === 'mysql') {
                $port = getenv('ORRERY_TEST_MYSQL_PORT') ?: '33106';
                $pdo = new PDO("mysql:host=127.0.0.1;port={$port};dbname={$database}", 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $record['marker'] = $pdo->query('SELECT token FROM orrery_marker.marker')->fetchColumn();
                $record['current'] = $pdo->query('SELECT DATABASE()')->fetchColumn();
                $record['session'] = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
                $mirror = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
            } elseif ($driver === 'pgsql') {
                $port = getenv('ORRERY_TEST_PGSQL_PORT') ?: '54336';
                $pdo = new PDO("pgsql:host=127.0.0.1;port={$port};dbname={$database}", 'postgres', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $record['marker'] = $pdo->query("SELECT shobj_description(oid, 'pg_database') FROM pg_database WHERE datname = 'orrery_marker'")->fetchColumn();
                $record['current'] = $pdo->query('SELECT current_database()')->fetchColumn();
                $record['session'] = (int) $pdo->query('SELECT pg_backend_pid()')->fetchColumn();
                $mirror = $pdo->prepare('SELECT COUNT(*) FROM pg_database WHERE datname = ?');
            } else {
                self::fail("RunProbe needs ORRERY_TEST_DRIVER mysql or pgsql, not {$driver}");
            }

            $mirror->execute([$database.'_mirror']);
            $record['mirror_exists'] = (int) $mirror->fetchColumn() === 1;

            if (getenv('ORRERY_PROBE_SENTINEL') === '1') {
                $pdo->exec('CREATE TABLE orrery_probe_sentinel (id INT)');
                $record['sentinel'] = true;
            }

            $meet = getenv('ORRERY_PROBE_MEET');

            if (is_string($meet) && $meet !== '') {
                $record['met'] = $this->meet($pdo, $driver, $meet, $database, $record['session']);
            }
        } catch (\Throwable $throwable) {
            $record['error'] = $throwable->getMessage();
        }

        $this->write($record);
        $this->hold();

        self::assertArrayNotHasKey('error', $record, $record['error'] ?? '');
        self::assertSame(getenv('ORRERY_TEST_TOKEN'), $record['marker'], 'the endpoint marker differs from the token');
        self::assertSame($database, $record['current']);
        self::assertTrue($record['mirror_exists'], "{$database}_mirror does not exist");

        foreach ($record['met'] ?? [] as $peer) {
            self::assertTrue($peer['live'], "the session of run {$peer['pid']} on {$peer['database']} was not open while this run held its own");
        }
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        return array_filter(
            getenv(),
            static fn (string $name): bool => preg_match('/^(DB_|ORRERY_TEST_|ORRERY_EXPECT_|ORRERY_IDENTITY_LOG$|DATABASE_URL$|ORRERY_START_GATE$|TMPDIR$)/', $name) === 1,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function write(array $record): void
    {
        $out = getenv('ORRERY_PROBE_OUT');

        if (is_string($out) && $out !== '') {
            file_put_contents($out.'/probe-'.getmypid().'.json', json_encode($record, JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * The two-sided hold: every probe of a group (ORRERY_PROBE_PEERS of them)
     * arrives at ORRERY_PROBE_MEET with its database and session, and waits
     * until all have arrived. While its own session stays open, it asks the
     * server whether each peer's session is connected to the peer's database.
     * Then it waits until every probe has checked, so no session closes
     * before its peers saw it. Runs that do not overlap never all arrive, and
     * the wait fails after ORRERY_PROBE_MEET_TIMEOUT seconds (default 120).
     *
     * @return list<array{pid: int, database: string, session: int, live: bool}>
     */
    private function meet(PDO $pdo, string $driver, string $dir, string $database, int $session): array
    {
        $peers = (int) getenv('ORRERY_PROBE_PEERS');
        $timeout = (float) (getenv('ORRERY_PROBE_MEET_TIMEOUT') ?: 120);
        $me = getmypid();

        file_put_contents("{$dir}/arrived-{$me}.tmp", json_encode(['pid' => $me, 'database' => $database, 'session' => $session]));
        rename("{$dir}/arrived-{$me}.tmp", "{$dir}/arrived-{$me}.json");
        $this->awaitCount($dir.'/arrived-*.json', $peers, $timeout, 'not every run arrived at the meeting point, so the runs did not overlap');

        $query = $driver === 'mysql'
            ? $pdo->prepare('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = ? AND DB = ?')
            : $pdo->prepare('SELECT COUNT(*) FROM pg_stat_activity WHERE pid = ? AND datname = ?');
        $seen = [];

        foreach (glob($dir.'/arrived-*.json') ?: [] as $file) {
            $peer = json_decode((string) file_get_contents($file), true);

            if (! is_array($peer) || $peer['pid'] === $me) {
                continue;
            }

            $query->execute([$peer['session'], $peer['database']]);
            $seen[] = ['pid' => $peer['pid'], 'database' => $peer['database'], 'session' => $peer['session'], 'live' => (int) $query->fetchColumn() === 1];
        }

        touch("{$dir}/checked-{$me}");
        $this->awaitCount($dir.'/checked-*', $peers, $timeout, 'not every run checked its peers');

        return $seen;
    }

    private function awaitCount(string $pattern, int $count, float $timeout, string $message): void
    {
        $deadline = microtime(true) + $timeout;

        while (count(glob($pattern) ?: []) < $count) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException($message);
            }

            usleep(20_000);
            clearstatcache();
        }
    }

    private function hold(): void
    {
        $hold = getenv('ORRERY_PROBE_HOLD');

        if (! is_string($hold) || $hold === '') {
            return;
        }

        touch($hold.'/reached');
        $deadline = microtime(true) + 120;

        while (! file_exists($hold.'/release')) {
            if (microtime(true) >= $deadline) {
                self::fail('the probe hold was never released');
            }

            usleep(10_000);
            clearstatcache();
        }
    }
}
