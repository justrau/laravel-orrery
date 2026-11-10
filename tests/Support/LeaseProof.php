<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use OrreryTestDb\Failure;
use OrreryTestDb\Processes;

/**
 * The ownership proof of a server run.
 *
 * Before the first migration, a process that uses a MySQL or Postgres test
 * database proves that its run owns that database:
 * - the lease of ORRERY_TEST_RUN_ID exists in the registry
 *   (`php -n bin/lib/testdb.php status --no-services`) and is live;
 * - ORRERY_TEST_TOKEN is the registry token;
 * - the lease holds both names, <base> and <base>_mirror, for the engine;
 * - this process belongs to a recorded process group that is owned now: the
 *   group of bin/test's anchor, which PHPUnit and every worker that it starts
 *   belong to.
 *
 * Ownership uses the rules of bin/lib/testdb.php, never the raw entry alone.
 * A raw group entry stays in the lease after its group ended, and a later
 * group can get the same number. So the proof requires all of these:
 * - the registry counts the entry as owned now (its PID is in alive.children);
 * - this process is not in alive.unverified;
 * - Processes::groupMembers() of testdb.php, for the entry's PID, recorded
 *   leader start and group token, lists this process as owned. A leader with
 *   another start (a reused number) gives no owned member at all.
 *
 * A closing lease, and a lease in which another process is unverified, still
 * hold their names, so they pass for an owned caller. ORRERY_TEST_CI=1
 * (bin/test --no-provision) has no registry, so the proof is skipped; the
 * endpoint token (see IdentityRecord) is then the only proof.
 *
 * The token itself is compared with the service marker by IdentityRecord,
 * through the resolved Laravel connections, before every test's migrations.
 */
final class LeaseProof
{
    /** @var array<string, true> the proofs that passed in this process, by proof context */
    private static array $proved = [];

    /** The number of proofs that read the registry in this process (not from the cache). */
    private static int $verifications = 0;

    /**
     * For tests/bootstrap.php: prove once per process, or print why not and
     * exit 78 before any test runs.
     */
    public static function bootstrap(): void
    {
        try {
            self::ensure(TestEnvironment::fromEnv());
        } catch (HarnessRefusal $refusal) {
            fwrite(STDERR, 'orrery: '.$refusal->getMessage()."\n");

            exit(78);
        }
    }

    /**
     * Proves the lease once per process and proof context. The context is
     * everything that the proof reads or compares: the run, the engine, the
     * database, the token, this process and its group, and the registry
     * selection. Any other value gives a new proof.
     *
     * $status and $members replace the registry read and the process
     * inspection (see verify()); only the tests of the proof pass them.
     *
     * @param  (callable(): array<string, mixed>)|null  $status
     * @param  (callable(int, string, ?string): (array{owned: array<int, string>, unverified: array<int, string>}|null))|null  $members
     * @return string  'lease' (proved), 'ci' (no registry) or 'none' (SQLite)
     *
     * @throws HarnessRefusal
     */
    public static function ensure(TestEnvironment $env, ?callable $status = null, ?callable $members = null): string
    {
        if (! $env->isServer()) {
            return 'none';
        }

        if ($env->ci) {
            return 'ci';
        }

        $key = self::context($env);

        if (! isset(self::$proved[$key])) {
            self::verify($env, $status, $members);
            self::$proved[$key] = true;
        }

        return 'lease';
    }

    /**
     * Reads the registry and proves the lease, without the cache.
     *
     * @param  (callable(): array<string, mixed>)|null  $status
     * @param  (callable(int, string, ?string): (array{owned: array<int, string>, unverified: array<int, string>}|null))|null  $members
     *
     * @throws HarnessRefusal
     */
    public static function verify(TestEnvironment $env, ?callable $status = null, ?callable $members = null): void
    {
        self::$verifications++;
        $status ??= self::status(...);
        $members ??= self::members(...);

        self::evaluate($status(), $env, (int) getmypid(), self::processGroup(), $members);
    }

    /**
     * The number of proofs that read the registry in this process.
     */
    public static function verifications(): int
    {
        return self::$verifications;
    }

    /**
     * The decision on a parsed `status --no-services` result, for the process
     * $pid in the process group $group.
     *
     * @param  array<string, mixed>  $status
     * @param  callable(int, string, ?string): (array{owned: array<int, string>, unverified: array<int, string>}|null)  $members
     *
     * @throws HarnessRefusal
     */
    public static function evaluate(array $status, TestEnvironment $env, int $pid, int $group, callable $members): void
    {
        if (! $env->isServer() || $env->runId === null) {
            throw new HarnessRefusal('The lease proof needs a server run with ORRERY_TEST_RUN_ID.');
        }

        $runId = $env->runId;
        $base = $env->database;
        $mirror = $base.'_mirror';
        $hint = " Run the suite through bin/test {$env->driver}; a test process never uses a database that its run does not hold.";

        if (($status['ok'] ?? false) !== true || ! is_array($status['leases'] ?? null)) {
            throw new HarnessRefusal('The registry status is not readable: '.json_encode($status).$hint);
        }

        $lease = $status['leases'][$runId] ?? null;

        if (! is_array($lease)) {
            throw new HarnessRefusal("The registry holds no lease for {$runId}.".$hint);
        }

        // The endpoint token of this run is the registry's token. IdentityRecord
        // then compares it with the marker of each resolved connection.
        if (($status['token'] ?? null) !== $env->token) {
            throw new HarnessRefusal('ORRERY_TEST_TOKEN differs from the registry token.'.$hint);
        }

        $names = is_array($lease['names'] ?? null) ? $lease['names'] : [];

        foreach ([$base, $mirror] as $name) {
            if (! in_array($name, $names, true) || ($status['names'][$name] ?? null) !== $runId) {
                throw new HarnessRefusal("The lease of {$runId} does not hold the database name {$name} (it holds ".json_encode($names).').'.$hint);
            }
        }

        if (($lease['engine'] ?? null) !== $env->driver) {
            throw new HarnessRefusal(sprintf('The lease of %s is for the engine %s, not %s.', $runId, json_encode($lease['engine'] ?? null), $env->driver).$hint);
        }

        if (($lease['live'] ?? false) !== true) {
            throw new HarnessRefusal("The lease of {$runId} is not live.".$hint);
        }

        $alive = is_array($lease['alive'] ?? null) ? $lease['alive'] : [];
        $ownedEntries = is_array($alive['children'] ?? null) ? $alive['children'] : [];
        $unverified = is_array($alive['unverified'] ?? null) ? $alive['unverified'] : [];

        if (in_array($pid, $unverified, true)) {
            throw new HarnessRefusal("This process ({$pid}) is unverified in the lease of {$runId}: it cannot be proved to belong to a recorded process group.".$hint);
        }

        $entries = array_values(array_filter(
            is_array($lease['children'] ?? null) ? $lease['children'] : [],
            static fn (mixed $child): bool => is_array($child) && ($child['group'] ?? false) === true && ($child['pid'] ?? null) === $group,
        ));

        if ($entries === []) {
            throw new HarnessRefusal("The lease of {$runId} does not list the process group {$group} of this process.".$hint);
        }

        if (! in_array($group, $ownedEntries, true)) {
            throw new HarnessRefusal("The registry does not count the recorded process group {$group} of {$runId} as owned now: the group ended, or a later group reused its number.".$hint);
        }

        foreach ($entries as $entry) {
            $start = is_string($entry['start'] ?? null) ? $entry['start'] : '';
            $token = is_string($entry['token'] ?? null) ? $entry['token'] : null;
            $owned = $members($group, $start, $token);

            if ($owned !== null && array_key_exists($pid, $owned['owned'])) {
                return;
            }
        }

        throw new HarnessRefusal("This process ({$pid}) is not an owned member of the recorded process group {$group} of {$runId}: the leader has another start time (a reused group number), or the process cannot be proved to belong to the group.".$hint);
    }

    /**
     * The cache key of a proof: every input of the proof.
     */
    private static function context(TestEnvironment $env): string
    {
        return hash('sha256', (string) json_encode([
            $env->runId,
            $env->driver,
            $env->database,
            $env->token,
            (int) getmypid(),
            self::processGroup(),
            getenv('ORRERY_REGISTRY_TEST'),
            getenv('ORRERY_REGISTRY_DIR'),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private static function status(): array
    {
        $script = self::testdbPath();
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-n', $script, 'status', '--no-services'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            throw new HarnessRefusal("Cannot run {$script} status.");
        }

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $status = json_decode(trim($out), true);

        if ($exit !== 0 || ! is_array($status)) {
            throw new HarnessRefusal("{$script} status --no-services failed (exit {$exit}): ".trim($err));
        }

        return $status;
    }

    /**
     * The members of a recorded process group, by the rules of testdb.php
     * (Processes::groupMembers()): null when its leader has another start.
     *
     * @return array{owned: array<int, string>, unverified: array<int, string>}|null
     */
    private static function members(int $group, string $leaderStart, ?string $token): ?array
    {
        require_once self::testdbPath();

        try {
            return Processes::groupMembers(
                Processes::snapshot(),
                $group,
                Processes::normalise($leaderStart),
                static fn (array $pids): array => Processes::carryToken($pids, $token),
            );
        } catch (Failure $failure) {
            throw new HarnessRefusal('The process inspection for the lease proof failed: '.$failure->getMessage());
        }
    }

    private static function testdbPath(): string
    {
        return dirname(__DIR__, 2).'/bin/lib/testdb.php';
    }

    public static function processGroup(): int
    {
        if (function_exists('posix_getpgrp')) {
            return posix_getpgrp();
        }

        $out = trim((string) shell_exec('ps -o pgid= -p '.getmypid()));

        if (preg_match('/^[0-9]+$/D', $out) !== 1) {
            throw new HarnessRefusal('Cannot read the process group of this process.');
        }

        return (int) $out;
    }
}
