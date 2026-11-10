<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use OrreryMatrix\Check;
use OrreryMatrix\CheckResult;
use OrreryMatrix\InputError;
use OrreryMatrix\Inventory;
use OrreryMatrix\JunitProblem;
use OrreryMatrix\JunitReport;
use OrreryMatrix\Manifest;
use OrreryMatrix\TestIds;
use OrreryMatrix\Unconditional;
use Justrau\Orrery\Tests\Scripts\Concerns\RunsScripts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Version;

/**
 * The known-failure check (bin/lib/known-failures.php, bin/check-known-failures)
 * with fixture JUnit files, fixture copies of tests/known-failures.md and
 * tests/unconditional.txt, and fixture inventories. Only the last two tests
 * read the repository's own manifest and unconditional list, and only for
 * their format, because their content changes.
 *
 * It is in the group scripts: `composer test` skips it, `composer test:all`
 * runs it.
 */
#[Group('scripts')]
final class KnownFailuresCheckTest extends TestCase
{
    use RunsScripts;

    private const METRICS = 'Fx\\Feature\\MetricsTest::test_counter';

    private const HEARTBEAT = 'Fx\\Feature\\HeartbeatTest::test_stats';

    private const PLAIN = 'Fx\\Feature\\PlainTest::test_plain';

    private const IDENTITY = 'Fx\\Harness\\IdentityTest::test_identity';

    private const REFUSAL = 'Fx\\Harness\\IdentityTest::test_refusal#a named set';

    private const WORKER = 'Fx\\Process\\WorkerTest::test_worker';

    private const BARRIER = 'Fx\\Process\\WorkerTest::test_barrier';

    /** A process control that needs no shared database: it must run on in-memory SQLite too. */
    private const DEADLINE = 'Fx\\Process\\WorkerTest::test_deadline';

    /** A generated summary that agrees with the JUnit file of the test (see summaryOf()). */
    private const AUTO = "\0auto";

    private const WORKBENCH_CLASS = 'Justrau\\Orrery\\Tests\\Feature\\WorkbenchBootstrapCacheRefusalTest';

    private const WORKBENCH = self::WORKBENCH_CLASS.'::test_a_foreign_path_is_refused_for_each_variable#APP_CONFIG_CACHE';

    private const MANIFEST = <<<'MD'
        # Known runtime failures (fixture)

        | test | engine | signature | finding | fixed by |
        | --- | --- | --- | --- | --- |
        | `Fx\Feature\MetricsTest::test_counter` | `*/*/prefix` | `INSERT INTO orrery_metric_counters` | F10 | Spec B |
        | `Fx\Feature\HeartbeatTest::test_stats` | `mysql/*/*` | `Undefined array key "status"` | F8 | Spec B |
        | `Fx\Harness\IdentityTest::test_identity` | `*/*/*` | `identity drift` | F99 | Spec X |
        | `Justrau\Orrery\Tests\Feature\WorkbenchBootstrapCacheRefusalTest::test_a_foreign_path_is_refused_for_each_variable` | `*/*/*` | `was not refused` | F98 | Spec X |

        MD;

    private const UNCONDITIONAL = <<<'TXT'
        # fixture copy
        harness

        process-control
        Justrau\Orrery\Tests\Feature\WorkbenchBootstrapCacheRefusalTest
        TXT;

    protected function setUp(): void
    {
        parent::setUp();
        require_once self::repoRoot().'/bin/lib/known-failures.php';
    }

    protected function tearDown(): void
    {
        $this->cleanUpScripts();
        parent::tearDown();
    }

    public function test_a_clean_run_passes_and_counts_the_required_groups(): void
    {
        $result = $this->check([self::PLAIN => 'passed', self::IDENTITY => 'passed', self::REFUSAL => 'passed', self::WORKER => 'passed', self::BARRIER => 'passed', self::DEADLINE => 'passed'], 'mysql/same/none', exit: 0);

        self::assertSame('pass', $result->verdict(), implode("\n", $result->reasons));
        self::assertSame(['members' => 2, 'exempt' => 0, 'executed' => 2, 'skipped' => 0, 'missing' => 0], $result->groups['harness']);
        self::assertSame(['members' => 3, 'exempt' => 0, 'executed' => 3, 'skipped' => 0, 'missing' => 0], $result->groups['process']);
        self::assertSame(['tests' => 6, 'executed' => 6, 'passed' => 6, 'failed' => 0, 'skipped' => 0, 'assertions' => 6], $result->counts);
        self::assertSame(['Tests' => 6, 'Assertions' => 6], $result->summary);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function listedFailures(): iterable
    {
        yield 'a prefix row on SQLite' => [self::METRICS, 'sqlite/separate/prefix', 'error', 'SQLSTATE[HY000]: General error: 1 no such table: INSERT INTO orrery_metric_counters (x)'];
        yield 'a MySQL row on MySQL with a prefix' => [self::HEARTBEAT, 'mysql/same/prefix', 'error', 'ErrorException: Undefined array key "status"'];
    }

    #[DataProvider('listedFailures')]
    public function test_a_failure_that_matches_a_row_is_listed(string $id, string $engine, string $type, string $text): void
    {
        $result = $this->check([self::PLAIN => 'passed', $id => [$type, $text]], $engine, require: [], exit: $type === 'error' ? 2 : 1);

        self::assertSame('pass-listed', $result->verdict(), implode("\n", $result->reasons));
        self::assertSame($id, $result->listed[0]['id']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function unlistedFailures(): iterable
    {
        yield 'the row is for another engine' => [self::HEARTBEAT, 'pgsql/same/none', 'Undefined array key "status"'];
        yield 'the row is for a prefix only' => [self::METRICS, 'sqlite/same/none', 'INSERT INTO orrery_metric_counters'];
        yield 'another signature' => [self::HEARTBEAT, 'mysql/same/none', 'Undefined array key "queue"'];
        yield 'no row for the test' => [self::PLAIN, 'mysql/same/none', 'Undefined array key "status"'];
    }

    #[DataProvider('unlistedFailures')]
    public function test_a_failure_without_a_matching_row_fails(string $id, string $engine, string $text): void
    {
        $result = $this->check([$id => ['failure', $text]], $engine, require: [], exit: 1);

        self::assertSame('fail', $result->verdict());
        self::assertSame([['id' => $id, 'message' => $text]], $result->unlisted);
    }

    public function test_a_data_set_matches_the_row_of_its_method(): void
    {
        $result = $this->check([self::METRICS.'#with a named set' => ['error', 'INSERT INTO orrery_metric_counters'], self::METRICS.'#0' => ['error', 'INSERT INTO orrery_metric_counters']], 'pgsql/separate/prefix', require: [], exit: 2);

        self::assertSame('pass-listed', $result->verdict(), implode("\n", $result->reasons));
        self::assertCount(2, $result->listed);
    }

    /**
     * PHPUnit writes an error in the test and a failed
     * assertion in tearDown as two faults of one test case.
     */
    public function test_a_listed_fault_does_not_excuse_another_fault_of_the_same_test(): void
    {
        $faults = [['error', 'SQLSTATE[HY000]: INSERT INTO orrery_metric_counters failed'], ['failure', 'Failed asserting that false is true (tearDown)']];

        $result = $this->check([self::PLAIN => 'passed', self::METRICS => $faults], 'sqlite/same/prefix', require: [], exit: 2);

        self::assertSame('fail', $result->verdict());
        self::assertSame([], $result->listed);
        self::assertSame([['id' => self::METRICS, 'message' => 'Failed asserting that false is true (tearDown)']], $result->unlisted);
        self::assertSame(['unlisted failure on sqlite/same/prefix: '.self::METRICS.' (failure 2 of 2; a listed fault of the same test does not excuse it): Failed asserting that false is true (tearDown)'], $result->reasons);

        // The control: with a row for each fault, the test is listed with both rows.
        $manifest = self::MANIFEST."| `Fx\\Feature\\MetricsTest::test_counter` | `*/*/prefix` | `(tearDown)` | F11 | Spec B |\n";
        $listed = $this->check([self::PLAIN => 'passed', self::METRICS => $faults], 'sqlite/same/prefix', require: [], exit: 2, manifest: $manifest);

        self::assertSame('pass-listed', $listed->verdict(), implode("\n", $listed->reasons));
        self::assertSame([['type' => 'error', 'line' => 5], ['type' => 'failure', 'line' => 9]], $listed->listed[0]['faults']);
    }

    /**
     * A Workbench isolation
     * class that tests/unconditional.txt names, with a manifest row that
     * matches its failure by test, engine and signature.
     */
    public function test_a_failure_of_an_unconditional_class_is_never_excused_by_a_matching_row(): void
    {
        $result = $this->check([self::PLAIN => 'passed', self::WORKBENCH => ['failure', 'the foreign path was not refused']], 'sqlite/same/none', require: [], exit: 1);

        self::assertSame('fail', $result->verdict());
        self::assertSame([], $result->listed);
        self::assertSame([['id' => self::WORKBENCH, 'entry' => self::WORKBENCH_CLASS, 'manifest_line' => 8, 'message' => 'the foreign path was not refused']], $result->unconditional);
        self::assertStringContainsString('never excused, although manifest line 8 matches', $result->reasons[0]);
    }

    public function test_a_failure_of_an_unconditional_group_member_is_never_excused_by_a_matching_row(): void
    {
        $result = $this->check([self::IDENTITY => ['failure', 'identity drift on testbench'], self::REFUSAL => 'passed'], 'mysql/same/none', require: ['harness'], exit: 1);

        self::assertSame('fail', $result->verdict());
        self::assertSame('group harness', $result->unconditional[0]['entry']);
        self::assertSame(7, $result->unconditional[0]['manifest_line']);
    }

    public function test_the_unconditional_group_needs_an_inventory_section(): void
    {
        $this->expectException(InputError::class);
        $this->expectExceptionMessage("tests/unconditional.txt names the group 'process-control', but the inventory has no section for it");

        Check::evaluate(JunitReport::parse(self::junit([self::PLAIN => 'passed'])), 'sqlite/same/none', Inventory::fromArray(['harness' => []]), [], [], 0, null, Manifest::parse(self::MANIFEST), Unconditional::parse(self::UNCONDITIONAL));
    }

    /**
     * @return iterable<string, array{0: string|null, 1: string}>
     */
    public static function unusableJunitFiles(): iterable
    {
        yield 'a missing file' => [null, 'no JUnit file at '];
        yield 'an empty file' => ['', 'is malformed'];
        yield 'not XML' => ['<testsuites><testcase name="x"', 'is malformed'];
        yield 'another root' => ['<?xml version="1.0"?><testsuite name="x"/>', 'its root is not <testsuites>'];
        yield 'zero test cases' => ['<?xml version="1.0"?><testsuites><testsuite name="x" tests="0"/></testsuites>', 'the JUnit file has no executed test'];
        yield 'only skipped test cases' => [self::junit([self::PLAIN => 'skipped']), 'the JUnit file has no executed test'];
    }

    #[DataProvider('unusableJunitFiles')]
    public function test_an_unusable_junit_file_fails(?string $xml, string $reason): void
    {
        $path = $this->makeTempDir('orrery-junit-').'/junit.xml';

        if ($xml !== null) {
            file_put_contents($path, $xml);
        }

        try {
            $junit = JunitReport::read($path);
        } catch (JunitProblem $problem) {
            $junit = $problem;
        }

        $result = Check::evaluate($junit, 'sqlite/same/none', self::inventory(), [], [], 0, null, Manifest::parse(self::MANIFEST), Unconditional::parse(self::UNCONDITIONAL));

        self::assertSame('fail', $result->verdict());
        self::assertStringContainsString($reason, implode("\n", $result->reasons));
    }

    public function test_a_skipped_required_test_fails_but_an_engine_skip_passes(): void
    {
        $cases = [self::IDENTITY => 'passed', self::REFUSAL => 'passed', self::WORKER => 'skipped', self::BARRIER => 'skipped', self::DEADLINE => 'passed'];

        // File SQLite and the servers: no engine skip, so no member may skip.
        $required = $this->check($cases, 'sqlite/same/none', exit: 0);
        self::assertSame('fail', $required->verdict());
        self::assertSame([
            "a test of the required group 'process' was skipped: ".self::WORKER,
            "a test of the required group 'process' was skipped: ".self::BARRIER,
            "a test of the required group 'process-control' was skipped: ".self::BARRIER,
        ], $required->reasons);

        // In-memory SQLite: the shared-database members may skip.
        $engineSkip = $this->check($cases, 'sqlite/same/none', engineSkip: ['shared-database'], exit: 0);
        self::assertSame('pass', $engineSkip->verdict(), implode("\n", $engineSkip->reasons));
        self::assertSame(['members' => 3, 'exempt' => 2, 'executed' => 1, 'skipped' => 2, 'missing' => 0], $engineSkip->groups['process']);
        self::assertSame(['members' => 2, 'exempt' => 1, 'executed' => 1, 'skipped' => 1, 'missing' => 0], $engineSkip->groups['process-control']);
    }

    /**
     * The engine skip covers the shared-database members
     * only, not the whole process groups.
     */
    public function test_a_skip_of_a_required_test_outside_the_engine_skip_group_fails(): void
    {
        $result = $this->check([self::IDENTITY => 'passed', self::REFUSAL => 'passed', self::WORKER => 'skipped', self::BARRIER => 'skipped', self::DEADLINE => 'skipped'], 'sqlite/same/none', engineSkip: ['shared-database'], exit: 0);

        self::assertSame('fail', $result->verdict());
        self::assertSame([
            "a test of the required group 'process' was skipped: ".self::DEADLINE.' (only the members of shared-database may skip in this run)',
            "a test of the required group 'process-control' was skipped: ".self::DEADLINE.' (only the members of shared-database may skip in this run)',
        ], $result->reasons);
    }

    public function test_an_engine_skip_group_needs_an_inventory_section(): void
    {
        $this->expectException(InputError::class);
        $this->expectExceptionMessage("the engine-skip group 'shared-database' has no section in the inventory");

        Check::evaluate(JunitReport::parse(self::junit([self::PLAIN => 'passed'])), 'sqlite/same/none', Inventory::fromArray(['harness' => [], 'process-control' => []]), [], ['shared-database'], 0, null, Manifest::parse(self::MANIFEST), Unconditional::parse(self::UNCONDITIONAL));
    }

    public function test_a_missing_required_test_fails_also_when_it_may_skip(): void
    {
        $result = $this->check([self::IDENTITY => 'passed', self::REFUSAL => 'passed', self::WORKER => 'skipped', self::DEADLINE => 'passed'], 'sqlite/same/none', engineSkip: ['shared-database'], exit: 0);

        self::assertSame('fail', $result->verdict());
        self::assertSame([
            "a test of the required group 'process' is missing from the JUnit file: ".self::BARRIER,
            "a test of the required group 'process-control' is missing from the JUnit file: ".self::BARRIER,
        ], $result->reasons);
    }

    public function test_a_required_group_without_tests_fails(): void
    {
        $result = Check::evaluate(
            JunitReport::parse(self::junit([self::IDENTITY => 'passed'])),
            'mysql/same/none',
            Inventory::fromArray(['harness' => [self::IDENTITY], 'process' => [], 'process-control' => []]),
            ['harness', 'process', 'process-control'],
            [],
            0,
            null,
            Manifest::parse(self::MANIFEST),
            Unconditional::parse(self::UNCONDITIONAL),
        );

        self::assertSame([
            "the required group 'process' has no tests, so it ran zero tests",
            "the required group 'process-control' has no tests, so it ran zero tests",
        ], $result->reasons);
    }

    /**
     * @return iterable<string, array{0: array<string, string|array{0: string, 1: string}>, 1: int, 2: string|null}>
     */
    public static function exitStatuses(): iterable
    {
        $clean = [self::PLAIN => 'passed'];
        $listedError = [self::PLAIN => 'passed', self::METRICS => ['error', 'INSERT INTO orrery_metric_counters']];
        $listedFailure = [self::PLAIN => 'passed', self::METRICS => ['failure', 'INSERT INTO orrery_metric_counters']];

        yield '0 with a clean file' => [$clean, 0, null];
        yield '1 with a listed failure' => [$listedFailure, 1, null];
        yield '2 with a listed error' => [$listedError, 2, null];
        yield '78, a bootstrap refusal' => [$clean, 78, 'PHPUnit exit status 78: tests/bootstrap.php refused the configuration or the lease proof. A refusal is never a known failure'];
        yield '255, a crash' => [$clean, 255, 'PHPUnit exit status 255: PHP ended with a fatal error'];
        yield '74 from bin/test' => [$clean, 74, 'PHPUnit exit status 74: bin/test could not confirm'];
        yield '124, the timeout of bin/test' => [$clean, 124, 'PHPUnit exit status 124: bin/test stopped the run at its --timeout'];
        yield '72, no heavy-run slot' => [$clean, 72, 'PHPUnit exit status 72: bin/test did not get the heavy-run slot within --slot-wait'];
        yield '3, unknown' => [$clean, 3, 'PHPUnit exit status 3: the run did not complete'];
        yield '0 with a failure in the file' => [$listedFailure, 0, 'PHPUnit exited 0, but the JUnit file records 1 failed test'];
        yield '1 without a failure in the file' => [$clean, 1, 'PHPUnit exited 1, but the JUnit file records no failure'];
        yield '1 with an error in the file' => [$listedError, 1, 'PHPUnit exited 1, but the JUnit file records 1 error'];
        yield '2 without an error in the file' => [$listedFailure, 2, 'PHPUnit exited 2, but the JUnit file records no error'];
    }

    /**
     * @param  array<string, string|array{0: string, 1: string}>  $cases
     */
    #[DataProvider('exitStatuses')]
    public function test_the_raw_exit_status_must_agree_with_the_junit_file(array $cases, int $exit, ?string $reason): void
    {
        $result = $this->check($cases, 'sqlite/same/prefix', require: [], exit: $exit);

        if ($reason === null) {
            self::assertTrue($result->passed(), implode("\n", $result->reasons));
            self::assertSame($exit, $result->exit);

            return;
        }

        self::assertSame('fail', $result->verdict());
        self::assertStringContainsString($reason, implode("\n", $result->reasons));
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function outputs(): iterable
    {
        yield 'a summary that agrees' => ["PHPUnit 12.5.0\n\nRuntime: PHP 8.4.18\n\nERRORS!\nTests: 2, Assertions: 2, Errors: 1.\n", null];
        yield 'a colored summary' => ["\e[37;41mERRORS!\e[0m\n\e[37;41mTests: 2\e[0m\e[37;41m, Assertions: 2\e[0m\e[37;41m, Errors: 1\e[0m\e[37;41m.\e[0m\n", null];
        yield 'a warning' => ["ERRORS!\nTests: 2, Assertions: 2, Errors: 1, Warnings: 1.\n", 'PHPUnit reported 1 warning'];
        yield 'a PHPUnit warning' => ["ERRORS!\nTests: 2, Assertions: 2, Errors: 1, PHPUnit Warnings: 2.\n", 'PHPUnit reported 2 warnings'];
        yield 'a risky test' => ["ERRORS!\nTests: 2, Assertions: 2, Errors: 1, Risky: 1.\n", 'PHPUnit reported 1 risky test'];
        yield 'an error outside the JUnit file' => ["ERRORS!\nTests: 2, Assertions: 2, Errors: 2.\n", 'the PHPUnit summary reports 2 errors and 0 failures, the JUnit file 1 and 0'];
        yield 'no summary' => ["PHPUnit 12.5.0\n\nRuntime: PHP 8.4.18\n\nPHP Fatal error: out of memory\n", 'the PHPUnit output has no result summary'];
        // Output of another run than the JUnit file.
        yield 'no test executed' => ["PHPUnit 12.5.0\n\nNo tests executed!\n", 'the PHPUnit summary says that no test ran (No tests executed!)'];
        yield 'other assertions' => ["ERRORS!\nTests: 2, Assertions: 7, Errors: 1.\n", 'the PHPUnit summary reports 7 assertions, the JUnit file 2'];
        yield 'other skipped tests' => ["ERRORS!\nTests: 3, Assertions: 2, Errors: 1, Skipped: 1.\n", 'the PHPUnit summary reports 1 skipped or incomplete tests, the JUnit file 0'];
        yield 'other executed tests' => ["ERRORS!\nTests: 3, Assertions: 2, Errors: 1.\n", 'the PHPUnit summary reports 3 executed tests (3 tests, 0 skipped or incomplete), the JUnit file 2'];
    }

    #[DataProvider('outputs')]
    public function test_the_phpunit_summary_shows_what_the_junit_file_cannot(string $output, ?string $reason): void
    {
        $result = $this->check([self::PLAIN => 'passed', self::METRICS => ['error', 'INSERT INTO orrery_metric_counters']], 'sqlite/same/prefix', require: [], exit: 2, output: $output);

        if ($reason === null) {
            self::assertSame('pass-listed', $result->verdict(), implode("\n", $result->reasons));

            return;
        }

        self::assertSame('fail', $result->verdict());
        self::assertStringContainsString($reason, implode("\n", $result->reasons));
    }

    /**
     * A JUnit file that an earlier run wrote passes no
     * count check when that run had the same results. The start time decides.
     */
    public function test_files_older_than_the_start_of_the_run_are_not_from_this_run(): void
    {
        $cases = [self::PLAIN => 'passed'];
        $started = 1_800_000_000;
        $evaluate = static fn (int $junitTime, ?int $outputTime): array => Check::evaluate(
            JunitReport::parse(self::junit($cases), 'junit.xml', $junitTime),
            'sqlite/same/none',
            self::inventory(),
            [],
            [],
            0,
            self::summaryOf($cases),
            Manifest::parse(self::MANIFEST),
            Unconditional::parse(self::UNCONDITIONAL),
            $started,
            $outputTime,
        )->reasons;

        self::assertSame([], $evaluate($started, $started), 'a file of this second counts as fresh');
        self::assertSame(['the JUnit file was last written at 2027-01-15 07:59:59 UTC, before the run started (at 2027-01-15 08:00:00 UTC): it is not from this run'], $evaluate($started - 1, $started + 5));
        self::assertSame(['the PHPUnit output was last written at 2027-01-15 07:58:20 UTC, before the run started (at 2027-01-15 08:00:00 UTC): it is not from this run'], $evaluate($started + 5, $started - 100));
        self::assertSame(['the PHPUnit output was last written at an unknown time, before the run started (at 2027-01-15 08:00:00 UTC): it is not from this run'], $evaluate($started, null));
    }

    /**
     * Without PHPUnit's output the check cannot see a
     * warning, which changes neither the exit status nor the JUnit file.
     */
    public function test_without_the_phpunit_output_the_result_is_incomplete(): void
    {
        $result = $this->check([self::PLAIN => 'passed', self::METRICS => ['error', 'INSERT INTO orrery_metric_counters']], 'sqlite/same/prefix', require: [], exit: 2, output: null);

        self::assertSame('incomplete', $result->verdict());
        self::assertFalse($result->passed());
        self::assertSame([], $result->reasons);
        self::assertCount(1, $result->listed);
        self::assertStringContainsString('Result: INCOMPLETE', $result->report('junit.xml'));
    }

    /**
     * Findings 1 and 2 against the installed PHPUnit (11.5 or 12.5): tests
     * that pass, skip in the body, in setUp and by a requirement, are
     * incomplete, have data sets, and a test with an error plus a failure in
     * tearDown. The summary and the JUnit file must agree for all of them.
     *
     * PHPUnit 11.5 and PHPUnit 12.5 before 12.5.43 report the teardown
     * failure as a second fault of the errored test, and that fault needs a
     * row of its own. PHPUnit 12.5.43 and later report only the test's own
     * error (its ChangeLog: an assertion that fails in tearDown() of a test
     * that already errored is no longer reported in addition), so the error
     * row alone passes, and the summary counts no failure.
     *
     * Rejects: a check that lets one listed fault excuse another fault of the
     * same test (on a PHPUnit that reports both), and a test that expects the
     * teardown failure from a PHPUnit that no longer reports it, or does not
     * expect it from one that does.
     */
    public function test_the_rules_hold_for_the_output_and_junit_file_of_the_installed_phpunit(): void
    {
        $run = $this->probeRun('Counts');
        $id = 'Justrau\\Orrery\\Tests\\Scripts\\Fixtures\\PhpunitProbes\\Counts\\TeardownProbe::test_error_then_teardown_failure';
        $row = static fn (string $signature, string $finding): string => "| `Justrau\\Orrery\\Tests\\Scripts\\Fixtures\\PhpunitProbes\\Counts\\TeardownProbe::test_error_then_teardown_failure` | `*/*/*` | `{$signature}` | {$finding} | Spec X |\n";

        self::assertSame(2, $run['exit'], $run['output']);

        if (! self::phpunitReportsAnAfterTestFailureOfAFailedTest()) {
            self::assertMatchesRegularExpression('/^Tests: 8, Assertions: \d+, Errors: 1, Skipped: 3, Incomplete: 1\.$/m', $run['output']);
            self::assertStringNotContainsString('orrery-teardown-failure', (string) file_get_contents($run['junit']));

            $errorOnly = $this->evaluateProbe($run, self::MANIFEST.$row('orrery-listed-error', 'F97'));
            self::assertSame('pass-listed', $errorOnly->verdict(), implode("\n", $errorOnly->reasons));
            self::assertSame(['tests' => 8, 'executed' => 4, 'passed' => 3, 'failed' => 1, 'skipped' => 4, 'assertions' => $errorOnly->summary['Assertions']], $errorOnly->counts);

            $unlisted = $this->evaluateProbe($run, self::MANIFEST);
            self::assertSame(["unlisted failure on sqlite/same/none: {$id}: RuntimeException: orrery-listed-error"], $unlisted->reasons);

            return;
        }

        self::assertMatchesRegularExpression('/^Tests: 8, Assertions: \d+, Errors: 1, Failures: 1, Skipped: 3, Incomplete: 1\.$/m', $run['output']);

        $errorOnly = $this->evaluateProbe($run, self::MANIFEST.$row('orrery-listed-error', 'F97'));
        self::assertSame(["unlisted failure on sqlite/same/none: {$id} (failure 2 of 2; a listed fault of the same test does not excuse it): orrery-teardown-failure"], $errorOnly->reasons);

        $both = $this->evaluateProbe($run, self::MANIFEST.$row('orrery-listed-error', 'F97').$row('orrery-teardown-failure', 'F96'));
        self::assertSame('pass-listed', $both->verdict(), implode("\n", $both->reasons));
        self::assertSame(['tests' => 8, 'executed' => 4, 'passed' => 3, 'failed' => 1, 'skipped' => 4, 'assertions' => $both->summary['Assertions']], $both->counts);
    }

    public function test_an_error_in_set_up_before_class_of_the_installed_phpunit_fails_the_check(): void
    {
        $run = $this->probeRun('BeforeClass');

        self::assertSame(2, $run['exit'], $run['output']);
        $result = $this->evaluateProbe($run, self::MANIFEST);

        self::assertSame('fail', $result->verdict());
        self::assertContains('PHPUnit exited 2, but the JUnit file records no error: PHPUnit could not run its tests, or an error happened outside a test case', $result->reasons);
        self::assertContains('the PHPUnit summary reports 1 errors and 0 failures, the JUnit file 0 and 0: a failure outside a test case (for example in setUpBeforeClass) is not in the JUnit file', $result->reasons);
    }

    public function test_junit_names_become_phpunit_test_ids(): void
    {
        self::assertSame('A\\B::test_x', TestIds::fromJunit('A\\B', 'test_x'));
        self::assertSame('A\\B::test_x#a named set', TestIds::fromJunit('A\\B', 'test_x with data set "a named set"'));
        self::assertSame('A\\B::test_x#7', TestIds::fromJunit('A\\B', 'test_x with data set #7'));
        self::assertSame('A\\B::test_x', TestIds::withoutDataSet('A\\B::test_x#7'));
    }

    public function test_the_inventory_comes_from_a_phpunit_test_list(): void
    {
        $list = <<<'XML'
            <?xml version="1.0"?>
            <testSuite xmlns="https://xml.phpunit.de/testSuite">
             <tests>
              <testClass name="Fx\Harness\IdentityTest" file="/x/IdentityTest.php">
               <testMethod id="Fx\Harness\IdentityTest::test_identity" name="test_identity"/>
               <testMethod id="Fx\Harness\IdentityTest::test_refusal#a named set" name="test_refusal"/>
              </testClass>
             </tests>
             <groups>
              <group name="__phpunit_covers_class_Fx\Something">
               <test id="Fx\Harness\IdentityTest::test_identity"/>
              </group>
              <group name="harness">
               <test id="Fx\Harness\IdentityTest::test_identity"/>
               <test id="Fx\Harness\IdentityTest::test_refusal#a named set"/>
              </group>
             </groups>
            </testSuite>
            XML;

        $inventory = Inventory::fromTestListXml($list);

        self::assertSame(['harness'], $inventory->names());
        self::assertSame([self::IDENTITY, self::REFUSAL], $inventory->members('harness'));
        self::assertSame("# comment\n[harness]\n".self::IDENTITY."\n".self::REFUSAL."\n[process]\n", $inventory->render(['harness', 'process'], 'comment'));

        $parsed = Inventory::parse($inventory->render(['harness', 'process']));
        self::assertSame(['harness', 'process'], $parsed->names());
        self::assertSame([], $parsed->members('process'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function malformedManifests(): iterable
    {
        $header = "| test | engine | signature | finding | fixed by |\n| --- | --- | --- | --- | --- |\n";

        yield 'no table' => ["# nothing\n", 'no known-failure table'];
        yield 'a data set in the test id' => [$header.'| `A\\B::test_x#1` | `*/*/*` | `x` | F1 | Spec B |', "invalid test id 'A\\B::test_x#1'"];
        yield 'an unknown engine' => [$header.'| `A\\B::test_x` | `oracle/*/*` | `x` | F1 | Spec B |', "invalid engine 'oracle/*/*'"];
        yield 'an empty signature' => [$header.'| `A\\B::test_x` | `*/*/*` | `` | F1 | Spec B |', 'the signature is empty'];
        yield 'four cells' => [$header.'| `A\\B::test_x` | `*/*/*` | `x` | F1 |', 'a row must have 5 cells, not 4'];
        yield 'another header' => ["| test | engine | signature |\n| --- | --- | --- |\n", 'the table header must be'];
    }

    #[DataProvider('malformedManifests')]
    public function test_a_manifest_that_cannot_be_read_is_refused(string $markdown, string $message): void
    {
        $this->expectException(InputError::class);
        $this->expectExceptionMessage($message);

        Manifest::parse($markdown);
    }

    public function test_a_signature_can_hold_an_escaped_pipe(): void
    {
        $manifest = Manifest::parse("| test | engine | signature | finding | fixed by |\n| --- | --- | --- | --- | --- |\n| `A\\B::test_x` | `*/*/*` | `a \\| b` | F1 | Spec B |\n");

        self::assertSame('a | b', $manifest->rows[0]->signature);
    }

    public function test_the_command_reports_the_raw_exit_status_and_its_verdict(): void
    {
        $cases = [self::PLAIN => 'passed', self::METRICS => ['error', 'INSERT INTO orrery_metric_counters'], self::IDENTITY => 'passed', self::REFUSAL => 'passed'];
        [$dir, $arguments] = $this->commandFiles($cases, self::summaryOf($cases));

        $pass = $this->command([...$arguments, '--exit=2', '--require=harness', '--json='.$dir.'/result.json']);
        self::assertSame(0, $pass['exit'], $pass['stdout'].$pass['stderr']);
        self::assertStringContainsString('PHPUnit exit status (raw): 2', $pass['stdout']);
        self::assertStringContainsString('Result: PASS with 1 listed known failure', $pass['stdout']);
        self::assertSame('pass-listed', json_decode((string) file_get_contents($dir.'/result.json'), true)['verdict']);

        // Without --require, every inventory section is required, and the
        // JUnit file has no test of process-control.
        $fail = $this->command([...$arguments, '--exit=2']);
        self::assertSame(1, $fail['exit'], $fail['stdout']);
        self::assertStringContainsString("FAIL: a test of the required group 'process-control' is missing from the JUnit file: ".self::BARRIER, $fail['stdout']);

        $missing = $this->command([...$arguments, '--exit=0', '--require=', $dir.'/extra']);
        self::assertSame(64, $missing['exit']);
        self::assertStringContainsString('expected <junit.xml> <engine> <inventory.txt>', $missing['stderr']);

        $noExit = $this->command($arguments);
        self::assertSame(64, $noExit['exit']);
        self::assertStringContainsString('--exit=<status> is required', $noExit['stderr']);

        $noStart = $this->command(array_values(array_filter([...$arguments, '--exit=2'], static fn (string $argument): bool => ! str_starts_with($argument, '--started='))));
        self::assertSame(64, $noStart['exit']);
        self::assertStringContainsString('--started=<unix seconds> is required', $noStart['stderr']);

        $badEngine = $this->command([$dir.'/junit.xml', 'sqlite', $dir.'/inventory.txt', '--exit=0', ...array_slice($arguments, 3)]);
        self::assertSame(64, $badEngine['exit']);
        self::assertStringContainsString("invalid engine 'sqlite'", $badEngine['stderr']);
    }

    /**
     * Through the command: a listed failure plus a warning
     * has the exit status of the listed failure alone.
     */
    public function test_the_command_needs_the_output_and_fails_a_listed_failure_with_a_warning(): void
    {
        $cases = [self::PLAIN => 'passed', self::METRICS => ['error', 'INSERT INTO orrery_metric_counters']];
        [, $clean] = $this->commandFiles($cases, self::summaryOf($cases));
        [, $warning] = $this->commandFiles($cases, "ERRORS!\nTests: 2, Assertions: 2, Errors: 1, Warnings: 1.\n");

        $pass = $this->command([...$clean, '--exit=2', '--require=']);
        self::assertSame(0, $pass['exit'], $pass['stdout'].$pass['stderr']);

        $warned = $this->command([...$warning, '--exit=2', '--require=']);
        self::assertSame(1, $warned['exit'], $warned['stdout']);
        self::assertStringContainsString('FAIL: PHPUnit reported 1 warning (Tests: 2, Assertions: 2, Errors: 1, Warnings: 1.). The manifest cannot excuse a warning', $warned['stdout']);

        $blind = $this->command([...array_values(array_filter($warning, static fn (string $argument): bool => ! str_starts_with($argument, '--output='))), '--exit=2', '--require=']);
        self::assertSame(1, $blind['exit'], $blind['stdout']);
        self::assertStringContainsString('Result: INCOMPLETE', $blind['stdout']);
    }

    /**
     * Through the command: a JUnit file from an earlier run.
     */
    public function test_the_command_refuses_a_junit_file_from_an_earlier_run(): void
    {
        $cases = [self::PLAIN => 'passed'];

        // An earlier passing run left its JUnit file, and this run tested nothing.
        [, $empty] = $this->commandFiles($cases, "PHPUnit 12.5.0\n\nNo tests executed!\n");
        $nothing = $this->command([...$empty, '--exit=0', '--require=']);
        self::assertSame(1, $nothing['exit'], $nothing['stdout']);
        self::assertStringContainsString('FAIL: the PHPUnit summary says that no test ran (No tests executed!)', $nothing['stdout']);

        // The same results, but the JUnit file is older than the run.
        [$dir, $stale] = $this->commandFiles($cases, self::summaryOf($cases));
        touch($dir.'/junit.xml', time() - 600);
        $old = $this->command([...$stale, '--exit=0', '--require=']);
        self::assertSame(1, $old['exit'], $old['stdout']);
        self::assertStringContainsString('before the run started', $old['stdout']);
        self::assertStringContainsString('it is not from this run', $old['stdout']);

        // The control: the same files, written after the start, pass.
        touch($dir.'/junit.xml');
        self::assertSame(0, $this->command([...$stale, '--exit=0', '--require='])['exit']);
    }

    /**
     * A verbatim copy of tests/unconditional.txt on main after the fix
     * rounds (commit 3f561e6): groups, class names, comment lines and blank
     * lines between sections. This branch still has the older list, so the
     * copy is a fixture, not the repository's file.
     */
    public function test_the_sectioned_unconditional_list_of_the_a3_fix_rounds_is_read(): void
    {
        $list = Unconditional::parse(<<<'TXT'
            # Tests that the known-failure check never excuses: the
            # bootstrap, the engine identity and the isolation and ownership controls. A
            # failure of any test listed here fails the leg, even when
            # tests/known-failures.md has a row for it.
            #
            # One entry per line: a PHPUnit group name, or a fully qualified test class.
            # Lines that start with # and empty lines are ignored. A class is listed
            # even when one of the groups below covers it, so the rule survives a
            # changed group attribute. Every class that has no group is listed.

            # Groups
            harness
            process-control
            scripts
            docker
            workbench

            # Harness identity, lease proof, mirror outage and reset hook (group harness)
            Justrau\Orrery\Tests\Feature\HarnessIdentityTest

            # Test database registry, bin/test and the bootstrap refusal (groups scripts and docker)
            Justrau\Orrery\Tests\Scripts\TestDbLogicTest
            Justrau\Orrery\Tests\Scripts\HarnessBootstrapTest
            Justrau\Orrery\Tests\Scripts\DockerOptInTest
            Justrau\Orrery\Tests\Scripts\TestDbDockerTest

            # Workbench isolation, path guard, bootstrap cache and launcher (group workbench)
            Justrau\Orrery\Tests\Feature\WorkbenchBootstrapCacheTest
            Justrau\Orrery\Tests\Feature\WorkbenchBuildTest
            Justrau\Orrery\Tests\Feature\WorkbenchCliHelperTest
            Justrau\Orrery\Tests\Feature\WorkbenchOwnershipTest
            Justrau\Orrery\Tests\Feature\WorkbenchPathGuardTest
            Justrau\Orrery\Tests\Feature\WorkbenchSeederTest
            Justrau\Orrery\Tests\Feature\WorkbenchSkeletonConcurrencyTest

            # Workbench controls without a group (they run in composer test)
            Justrau\Orrery\Tests\Feature\WorkbenchBootstrapCacheRefusalTest
            Justrau\Orrery\Tests\Feature\WorkbenchDatabaseTest
            Justrau\Orrery\Tests\Feature\WorkbenchIsolationTest
            Justrau\Orrery\Tests\Feature\WorkbenchLauncherTest
            Justrau\Orrery\Tests\Feature\WorkbenchSkeletonTest

            TXT);

        self::assertSame(['harness', 'process-control', 'scripts', 'docker', 'workbench'], $list->groups);
        self::assertCount(17, $list->classes);
        self::assertSame('Justrau\\Orrery\\Tests\\Feature\\HarnessIdentityTest', $list->classes[0]);
        self::assertSame('Justrau\\Orrery\\Tests\\Feature\\WorkbenchSkeletonTest', $list->classes[16]);

        // A class without a group, and a member of the scripts group, are
        // both unconditional under this list, also with a matching row.
        $skeleton = 'Justrau\\Orrery\\Tests\\Feature\\WorkbenchSkeletonTest::test_a_missing_link_is_created_to_the_vendor_directory';
        $script = 'Justrau\\Orrery\\Tests\\Scripts\\MatrixRunnerTest::test_a_leg_without_process_tests_fails';
        $manifest = Manifest::parse(self::MANIFEST."| `Justrau\\Orrery\\Tests\\Feature\\WorkbenchSkeletonTest::test_a_missing_link_is_created_to_the_vendor_directory` | `*/*/*` | `link` | F97 | Spec X |\n| `Justrau\\Orrery\\Tests\\Scripts\\MatrixRunnerTest::test_a_leg_without_process_tests_fails` | `*/*/*` | `process` | F96 | Spec X |\n");
        $inventory = Inventory::fromArray(['harness' => [], 'process-control' => [], 'scripts' => [$script], 'docker' => [], 'workbench' => []]);

        $result = Check::evaluate(
            JunitReport::parse(self::junit([self::PLAIN => 'passed', $skeleton => ['failure', 'the link was not created'], $script => ['failure', 'no process tests']])),
            'sqlite/same/none',
            $inventory,
            [],
            [],
            1,
            null,
            $manifest,
            $list,
        );

        self::assertSame([], $result->listed);
        self::assertSame(['Justrau\\Orrery\\Tests\\Feature\\WorkbenchSkeletonTest', 'group scripts'], array_column($result->unconditional, 'entry'));
        self::assertSame([9, 10], array_column($result->unconditional, 'manifest_line'));
    }

    public function test_the_repository_manifest_has_a_valid_format(): void
    {
        $manifest = Manifest::read(self::repoRoot().'/tests/known-failures.md');

        // The manifest can be empty: every listed failure is fixed.
        self::assertIsArray($manifest->rows);

        foreach ($manifest->rows as $row) {
            self::assertMatchesRegularExpression('/::test_/', $row->test);
        }
    }

    public function test_the_repository_unconditional_list_has_a_valid_format(): void
    {
        $list = Unconditional::read(self::repoRoot().'/tests/unconditional.txt');

        foreach ($list->classes as $class) {
            self::assertTrue(class_exists($class), "tests/unconditional.txt names {$class}, which does not exist");
        }

        self::assertNotSame([], [...$list->classes, ...$list->groups]);
    }

    // Helpers

    /**
     * Test cases: id => 'passed' | 'skipped' | [failure|error, text] | a list
     * of [failure|error, text] (several faults of one test case). The
     * outermost <testsuite> carries the totals, as PHPUnit writes them.
     *
     * @param  array<string, string|array<mixed>>  $cases
     */
    private static function junit(array $cases): string
    {
        $body = '';
        $skipped = 0;

        foreach ($cases as $id => $status) {
            [$class, $method] = explode('::', $id, 2);
            $name = $method;

            if (str_contains($method, '#')) {
                [$method, $set] = explode('#', $method, 2);
                $name = ctype_digit($set) ? "{$method} with data set #{$set}" : "{$method} with data set \"{$set}\"";
            }

            $attributes = sprintf('name="%s" class="%s" classname="%s" file="/x.php" line="1"', htmlspecialchars($name), htmlspecialchars($class), htmlspecialchars(str_replace('\\', '.', $class)));

            if ($status === 'passed') {
                $body .= "   <testcase {$attributes} assertions=\"1\" time=\"0.01\"/>\n";
            } elseif ($status === 'skipped') {
                $skipped++;
                $body .= "   <testcase {$attributes} assertions=\"0\" time=\"0.01\">\n    <skipped/>\n   </testcase>\n";
            } else {
                $faults = '';

                foreach (self::faults($status) as [$type, $text]) {
                    $faults .= sprintf("    <%s type=\"Exception\">%s</%s>\n", $type, htmlspecialchars($id."\n".$text."\n\n/x.php:10"), $type);
                }

                $body .= "   <testcase {$attributes} assertions=\"1\" time=\"0.01\">\n{$faults}   </testcase>\n";
            }
        }

        return sprintf("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<testsuites>\n <testsuite name=\"fixture\" tests=\"%d\" skipped=\"%d\">\n%s </testsuite>\n</testsuites>\n", count($cases), $skipped, $body);
    }

    /**
     * The summary that PHPUnit prints for these test cases: one assertion
     * per executed test.
     *
     * @param  array<string, string|array<mixed>>  $cases
     */
    private static function summaryOf(array $cases): string
    {
        $counts = ['Errors' => 0, 'Failures' => 0, 'Skipped' => 0];
        $assertions = 0;

        foreach ($cases as $status) {
            if ($status === 'skipped') {
                $counts['Skipped']++;

                continue;
            }

            $assertions++;

            if ($status !== 'passed') {
                foreach (self::faults($status) as [$type]) {
                    $counts[$type === 'error' ? 'Errors' : 'Failures']++;
                }
            }
        }

        if (array_sum($counts) === 0) {
            return sprintf("PHPUnit 12.5.0\n\nOK (%d tests, %d assertions)\n", count($cases), $assertions);
        }

        $line = sprintf('Tests: %d, Assertions: %d', count($cases), $assertions);

        foreach ($counts as $name => $count) {
            $line .= $count > 0 ? ", {$name}: {$count}" : '';
        }

        $head = $counts['Errors'] > 0 ? 'ERRORS!' : ($counts['Failures'] > 0 ? 'FAILURES!' : 'OK, but some tests were skipped!');

        return "PHPUnit 12.5.0\n\n{$head}\n{$line}.\n";
    }

    /**
     * @param  array<mixed>  $status
     * @return list<array{0: string, 1: string}>
     */
    private static function faults(array $status): array
    {
        return is_array($status[0]) ? $status : [$status];
    }

    private static function inventory(): Inventory
    {
        return Inventory::fromArray([
            'harness' => [self::IDENTITY, self::REFUSAL],
            'process' => [self::WORKER, self::BARRIER, self::DEADLINE],
            'process-control' => [self::BARRIER, self::DEADLINE],
            'shared-database' => [self::WORKER, self::BARRIER],
        ]);
    }

    /**
     * @param  array<string, string|array<mixed>>  $cases
     * @param  list<string>  $require
     * @param  list<string>  $engineSkip
     * @param  string|null  $output  PHPUnit's output; AUTO gives the summary that agrees with the cases
     */
    private function check(array $cases, string $engine, array $require = ['harness', 'process', 'process-control'], array $engineSkip = [], int $exit = 0, ?string $output = self::AUTO, string $manifest = self::MANIFEST): CheckResult
    {
        return Check::evaluate(
            JunitReport::parse(self::junit($cases)),
            $engine,
            self::inventory(),
            $require,
            $engineSkip,
            $exit,
            $output === self::AUTO ? self::summaryOf($cases) : $output,
            Manifest::parse($manifest),
            Unconditional::parse(self::UNCONDITIONAL),
        );
    }

    /**
     * Writes the JUnit file, PHPUnit's output, the inventory and the fixture
     * manifest and list into a new directory, and returns it with the
     * arguments of the command (JUnit, engine sqlite/same/prefix, inventory,
     * --started, --output, --manifest, --unconditional). The start is a
     * second before the files.
     *
     * @param  array<string, string|array<mixed>>  $cases
     * @return array{0: string, 1: list<string>}
     */
    private function commandFiles(array $cases, string $output): array
    {
        $dir = $this->makeTempDir('orrery-check-');
        $started = time() - 1;
        file_put_contents($dir.'/junit.xml', self::junit($cases));
        file_put_contents($dir.'/output.txt', $output);
        file_put_contents($dir.'/inventory.txt', self::inventory()->render(['harness', 'process-control']));
        file_put_contents($dir.'/known-failures.md', self::MANIFEST);
        file_put_contents($dir.'/unconditional.txt', self::UNCONDITIONAL);

        return [$dir, [
            $dir.'/junit.xml',
            'sqlite/same/prefix',
            $dir.'/inventory.txt',
            '--started='.$started,
            '--output='.$dir.'/output.txt',
            '--manifest='.$dir.'/known-failures.md',
            '--unconditional='.$dir.'/unconditional.txt',
        ]];
    }

    /**
     * Whether the installed PHPUnit reports a failed assertion in tearDown()
     * of a test that already errored as a fault of its own. PHPUnit 12.5.43
     * stopped it; PHPUnit 11.5 (up to 11.5.57, the latest release on
     * 2026-11-03) still reports it.
     */
    private static function phpunitReportsAnAfterTestFailureOfAFailedTest(): bool
    {
        return version_compare(Version::id(), '12.5.43', '<');
    }

    /**
     * Runs the installed PHPUnit on tests/Scripts/Fixtures/PhpunitProbes/<dir>.
     *
     * @return array{exit: int, output: string, junit: string, started: int}
     */
    private function probeRun(string $dir): array
    {
        $junit = $this->makeTempDir('orrery-probe-').'/junit.xml';
        $started = time();
        $run = $this->runProcess([
            PHP_BINARY, 'vendor/bin/phpunit', '--no-configuration', '--do-not-cache-result', '--colors=never',
            '--test-suffix', 'Probe.php', '--log-junit', $junit, 'tests/Scripts/Fixtures/PhpunitProbes/'.$dir,
        ], $this->scriptEnv(), 60.0);

        return ['exit' => $run['exit'], 'output' => $run['stdout'].$run['stderr'], 'junit' => $junit, 'started' => $started];
    }

    /**
     * @param  array{exit: int, output: string, junit: string, started: int}  $run
     */
    private function evaluateProbe(array $run, string $manifest): CheckResult
    {
        return Check::evaluate(
            JunitReport::read($run['junit']),
            'sqlite/same/none',
            Inventory::fromArray(['harness' => [], 'process-control' => []]),
            [],
            [],
            $run['exit'],
            $run['output'],
            Manifest::parse($manifest),
            Unconditional::parse(self::UNCONDITIONAL),
            $run['started'],
            $run['started'],
        );
    }

    /**
     * @param  list<string>  $arguments
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function command(array $arguments): array
    {
        $run = $this->runProcess([PHP_BINARY, self::repoRoot().'/bin/check-known-failures', ...$arguments], $this->scriptEnv(), 60.0);

        return ['exit' => $run['exit'], 'stdout' => $run['stdout'], 'stderr' => $run['stderr']];
    }
}
