<?php

/*
 * The known-failure check. bin/check-known-failures and
 * bin/matrix use it. It is plain PHP without the Composer autoloader, like
 * bin/lib/testdb.php, so it works in every checkout and matrix copy.
 *
 * Inputs of one check:
 * - the JUnit file of one PHPUnit run (--log-junit);
 * - the engine of the run: <driver>/<mirror>/<prefix>, for example
 *   mysql/separate/prefix;
 * - an inventory: the member tests of PHPUnit groups, one section per group,
 *   from `phpunit --list-tests-xml` (see Inventory);
 * - the groups that the run must execute (required), and the engine-skip
 *   groups: their members may skip in this run (the documented engine skip);
 * - PHPUnit's raw exit status and output, and the time when the run started;
 * - tests/known-failures.md (the manifest) and tests/unconditional.txt.
 *
 * Rules:
 * - A test case can have more than one fault (a <failure> or an <error>),
 *   for example an error in the test and a failed assertion in tearDown
 *   (PHPUnit 11.5, and 12.5 before 12.5.43; later releases report only the
 *   test's own error).
 *   The faults of a test that tests/unconditional.txt names, by class or by
 *   group, are never excused, also when a manifest row matches them.
 * - Every other fault must match a manifest row of its own: the test id
 *   without the data set, an engine pattern that matches the run's engine,
 *   and a signature that the text of that fault contains. One matched fault
 *   never excuses another fault of the same test case.
 * - Every member of a required group must be in the JUnit file as executed.
 *   A member that is also a member of an engine-skip group must be in the
 *   JUnit file, and it may be skipped. A required group without members
 *   fails ("ran zero tests").
 * - The JUnit file must exist, parse, hold at least one executed test, and
 *   be written at or after the start of the run (the freshness contract).
 * - Exit status 78 (tests/bootstrap.php refused) and every status other than
 *   0, 1 and 2 fail, and the status must agree with the JUnit file.
 * - PHPUnit's output is required: without it the result is incomplete (a
 *   failure), because a warning or a risky test changes neither the exit
 *   status nor the JUnit file. With it: a summary without tests fails, a
 *   warning or a risky test fails, and the summary's counts of errors,
 *   failures, assertions, skipped and executed tests must equal the JUnit
 *   file's. A fault outside a test case (for example in setUpBeforeClass) is
 *   in the summary but not in the JUnit file, and a JUnit file from another
 *   run has other counts.
 */

declare(strict_types=1);

namespace OrreryMatrix;

use DOMDocument;
use DOMElement;

/**
 * An input that the check cannot use: a bad argument, or an unreadable or
 * malformed manifest, unconditional list or inventory.
 */
final class InputError extends \RuntimeException {}

/**
 * The JUnit file is missing or cannot be read. The check still runs, and
 * this is one of its failure reasons.
 */
final class JunitProblem extends \RuntimeException {}

final class TestIds
{
    public const TEST_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*::[A-Za-z_][A-Za-z0-9_]*$/D';

    public const CLASS_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D';

    /**
     * The PHPUnit test id (TestMethod::id(): Class::method, plus #<data set
     * name> for a data provider) of a JUnit test case. PHPUnit names a test
     * case "method with data set "name"" or "method with data set #0".
     */
    public static function fromJunit(string $class, string $name): string
    {
        if ($class === '') {
            return $name;
        }

        if (preg_match('/^(\S+) with data set "(.*)"$/sD', $name, $match) === 1) {
            return $class.'::'.$match[1].'#'.$match[2];
        }

        if (preg_match('/^(\S+) with data set #(\d+)$/D', $name, $match) === 1) {
            return $class.'::'.$match[1].'#'.$match[2];
        }

        return $class.'::'.$name;
    }

    /**
     * Class::method without the data set. A class or method name cannot
     * contain "#".
     */
    public static function withoutDataSet(string $id): string
    {
        $position = strpos($id, '#');

        return $position === false ? $id : substr($id, 0, $position);
    }

    public static function className(string $id): string
    {
        $position = strpos($id, '::');

        return $position === false ? '' : substr($id, 0, $position);
    }
}

final class Engines
{
    public const PATTERN = '/^(sqlite|mysql|pgsql)\/(same|separate)\/(prefix|none)$/D';

    public const ROW_PATTERN = '/^(sqlite|mysql|pgsql|\*)\/(same|separate|\*)\/(prefix|none|\*)$/D';

    public static function validate(string $engine): string
    {
        if (preg_match(self::PATTERN, $engine) !== 1) {
            throw new InputError("invalid engine '{$engine}': use <sqlite|mysql|pgsql>/<same|separate>/<prefix|none>, for example sqlite/same/none");
        }

        return $engine;
    }

    public static function of(string $driver, string $mirror, string $prefix): string
    {
        return self::validate($driver.'/'.$mirror.'/'.($prefix === '' ? 'none' : 'prefix'));
    }

    public static function matches(string $pattern, string $engine): bool
    {
        $want = explode('/', $pattern);
        $have = explode('/', $engine);

        foreach ([0, 1, 2] as $position) {
            if ($want[$position] !== '*' && $want[$position] !== $have[$position]) {
                return false;
            }
        }

        return true;
    }
}

final class ManifestRow
{
    public function __construct(
        public readonly int $line,
        public readonly string $test,
        public readonly string $engine,
        public readonly string $signature,
        public readonly string $finding,
        public readonly string $fixedBy,
    ) {}

    public function matches(string $id, string $engine, string $text): bool
    {
        return $this->test === TestIds::withoutDataSet($id)
            && Engines::matches($this->engine, $engine)
            && str_contains($text, $this->signature);
    }
}

/**
 * tests/known-failures.md: one Markdown table with the columns test, engine,
 * signature, finding and fixed by. A cell may be a code span (`...`).
 */
final class Manifest
{
    private const COLUMNS = ['test', 'engine', 'signature', 'finding', 'fixed by'];

    /**
     * @param  list<ManifestRow>  $rows
     */
    private function __construct(public readonly array $rows) {}

    public static function read(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InputError("cannot read the known-failure manifest {$path}");
        }

        return self::parse((string) file_get_contents($path), $path);
    }

    public static function parse(string $markdown, string $name = 'the manifest'): self
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $rows = [];
        $state = 'before';

        foreach ($lines as $index => $line) {
            $number = $index + 1;
            $trimmed = trim($line);
            $isRow = str_starts_with($trimmed, '|');

            if ($state === 'before') {
                if (! $isRow) {
                    continue;
                }

                $header = array_map('strtolower', self::cells($trimmed));

                if ($header !== self::COLUMNS) {
                    throw new InputError("{$name}:{$number}: the table header must be | ".implode(' | ', self::COLUMNS).' |');
                }

                $state = 'separator';

                continue;
            }

            if ($state === 'separator') {
                if (! $isRow || preg_match('/^\|(\s*:?-{3,}:?\s*\|){5}$/D', $trimmed) !== 1) {
                    throw new InputError("{$name}:{$number}: the table header must be followed by a separator row with five columns");
                }

                $state = 'rows';

                continue;
            }

            if ($state === 'rows') {
                if (! $isRow) {
                    $state = 'after';

                    continue;
                }

                $rows[] = self::row(self::cells($trimmed), $name, $number);

                continue;
            }

            if ($isRow) {
                throw new InputError("{$name}:{$number}: a second table; the manifest has one table");
            }
        }

        if ($state === 'before' || $state === 'separator') {
            throw new InputError("{$name}: no known-failure table (| ".implode(' | ', self::COLUMNS).' |)');
        }

        return new self($rows);
    }

    /**
     * @return list<string>
     */
    private static function cells(string $row): array
    {
        $row = trim($row);
        $row = substr($row, 1);

        if (str_ends_with($row, '|') && ! str_ends_with($row, '\\|')) {
            $row = substr($row, 0, -1);
        }

        $parts = preg_split('/(?<!\\\\)\|/', $row) ?: [];

        return array_map(static fn (string $cell): string => str_replace('\\|', '|', trim($cell)), $parts);
    }

    /**
     * A code span: the same run of backticks at both ends, with one space
     * of padding removed when both sides have it.
     */
    private static function unquote(string $cell): string
    {
        if (preg_match('/^(`+)(.*?)\1$/sD', $cell, $match) !== 1) {
            return $cell;
        }

        $inner = $match[2];

        if (strlen($inner) >= 2 && $inner[0] === ' ' && $inner[strlen($inner) - 1] === ' ') {
            $inner = substr($inner, 1, -1);
        }

        return $inner;
    }

    /**
     * @param  list<string>  $cells
     */
    private static function row(array $cells, string $name, int $number): ManifestRow
    {
        if (count($cells) !== 5) {
            throw new InputError(sprintf('%s:%d: a row must have 5 cells, not %d', $name, $number, count($cells)));
        }

        [$test, $engine, $signature, $finding, $fixedBy] = [self::unquote($cells[0]), self::unquote($cells[1]), self::unquote($cells[2]), $cells[3], $cells[4]];

        if (preg_match(TestIds::TEST_PATTERN, $test) !== 1) {
            throw new InputError("{$name}:{$number}: invalid test id '{$test}': use Class::method without a data set");
        }

        if (preg_match(Engines::ROW_PATTERN, $engine) !== 1) {
            throw new InputError("{$name}:{$number}: invalid engine '{$engine}': use <sqlite|mysql|pgsql|*>/<same|separate|*>/<prefix|none|*>");
        }

        if ($signature === '') {
            throw new InputError("{$name}:{$number}: the signature is empty");
        }

        if ($finding === '' || $fixedBy === '') {
            throw new InputError("{$name}:{$number}: the finding and the fixing spec must not be empty");
        }

        return new ManifestRow($number, $test, $engine, $signature, $finding, $fixedBy);
    }

    public function match(string $id, string $engine, string $text): ?ManifestRow
    {
        foreach ($this->rows as $row) {
            if ($row->matches($id, $engine, $text)) {
                return $row;
            }
        }

        return null;
    }
}

/**
 * tests/unconditional.txt: one PHPUnit group or fully qualified test class
 * per line. Lines that start with # and empty lines are ignored.
 */
final class Unconditional
{
    public const GROUP_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/D';

    /**
     * @param  list<string>  $classes
     * @param  list<string>  $groups
     */
    private function __construct(public readonly array $classes, public readonly array $groups) {}

    public static function read(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InputError("cannot read the unconditional list {$path}");
        }

        return self::parse((string) file_get_contents($path), $path);
    }

    public static function parse(string $text, string $name = 'the unconditional list'): self
    {
        $classes = [];
        $groups = [];

        foreach (preg_split('/\R/', $text) ?: [] as $index => $line) {
            $entry = trim($line);

            if ($entry === '' || str_starts_with($entry, '#')) {
                continue;
            }

            if (str_contains($entry, '\\')) {
                if (preg_match(TestIds::CLASS_PATTERN, $entry) !== 1) {
                    throw new InputError(sprintf("%s:%d: invalid class name '%s'", $name, $index + 1, $entry));
                }

                $classes[] = ltrim($entry, '\\');

                continue;
            }

            if (preg_match(self::GROUP_PATTERN, $entry) !== 1) {
                throw new InputError(sprintf("%s:%d: invalid entry '%s': use a group name or a fully qualified class", $name, $index + 1, $entry));
            }

            $groups[] = $entry;
        }

        return new self(array_values(array_unique($classes)), array_values(array_unique($groups)));
    }

    /**
     * The entry that makes the test unconditional, or null.
     */
    public function entryFor(string $id, Inventory $inventory): ?string
    {
        $class = TestIds::className($id);

        if (in_array($class, $this->classes, true)) {
            return $class;
        }

        foreach ($this->groups as $group) {
            if ($inventory->contains($group, $id)) {
                return 'group '.$group;
            }
        }

        return null;
    }
}

/**
 * The member tests of PHPUnit groups. Text form:
 *
 *     # a comment
 *     [harness]
 *     Vendor\Tests\SomeTest::test_one
 *     Vendor\Tests\SomeTest::test_two#data set name
 *     [process]
 *
 * A section without lines is a group without tests.
 */
final class Inventory
{
    /**
     * @param  array<string, list<string>>  $groups
     */
    private function __construct(private readonly array $groups) {}

    /**
     * @param  array<string, list<string>>  $groups
     */
    public static function fromArray(array $groups): self
    {
        foreach ($groups as $group => $ids) {
            if (preg_match(Unconditional::GROUP_PATTERN, (string) $group) !== 1) {
                throw new InputError("invalid group name '{$group}'");
            }

            $groups[$group] = array_values(array_unique($ids));
        }

        return new self($groups);
    }

    public static function read(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InputError("cannot read the inventory {$path}");
        }

        return self::parse((string) file_get_contents($path), $path);
    }

    public static function parse(string $text, string $name = 'the inventory'): self
    {
        $groups = [];
        $current = null;

        foreach (preg_split('/\R/', $text) ?: [] as $index => $line) {
            $entry = trim($line);

            if ($entry === '' || str_starts_with($entry, '#')) {
                continue;
            }

            if (preg_match('/^\[([^\]]+)\]$/D', $entry, $match) === 1) {
                $current = $match[1];

                if (preg_match(Unconditional::GROUP_PATTERN, $current) !== 1) {
                    throw new InputError(sprintf("%s:%d: invalid group name '%s'", $name, $index + 1, $current));
                }

                if (array_key_exists($current, $groups)) {
                    throw new InputError(sprintf("%s:%d: the group '%s' has a second section", $name, $index + 1, $current));
                }

                $groups[$current] = [];

                continue;
            }

            if ($current === null) {
                throw new InputError(sprintf('%s:%d: a test id before the first [group] section', $name, $index + 1));
            }

            if (! str_contains($entry, '::')) {
                throw new InputError(sprintf("%s:%d: invalid test id '%s'", $name, $index + 1, $entry));
            }

            $groups[$current][] = $entry;
        }

        return self::fromArray($groups);
    }

    /**
     * The groups of `phpunit --list-tests-xml` (PHPUnit 11.5 and 12.5 write
     * the same format: a <groups> element with one <group name> element per
     * group, and one <test id> element per member).
     */
    public static function fromTestListXml(string $xml, string $name = 'the test list'): self
    {
        $document = self::document($xml, $name);
        $groups = [];

        foreach ($document->getElementsByTagName('group') as $group) {
            if (! $group instanceof DOMElement || ! $group->hasAttribute('name')) {
                throw new InputError("{$name}: a <group> without a name");
            }

            // PHPUnit also lists internal groups, for example
            // __phpunit_covers_<class>. A name that tests/unconditional.txt
            // and --require cannot use is left out.
            if (preg_match(Unconditional::GROUP_PATTERN, $group->getAttribute('name')) !== 1) {
                continue;
            }

            $members = [];

            foreach ($group->getElementsByTagName('test') as $test) {
                if ($test instanceof DOMElement && $test->getAttribute('id') !== '') {
                    $members[] = $test->getAttribute('id');
                }
            }

            $groups[$group->getAttribute('name')] = array_merge($groups[$group->getAttribute('name')] ?? [], $members);
        }

        return self::fromArray($groups);
    }

    /**
     * Every test id of `phpunit --list-tests-xml`.
     *
     * @return list<string>
     */
    public static function testIdsOfTestListXml(string $xml, string $name = 'the test list'): array
    {
        $ids = [];

        foreach (self::document($xml, $name)->getElementsByTagName('testMethod') as $method) {
            if ($method instanceof DOMElement && $method->getAttribute('id') !== '') {
                $ids[] = $method->getAttribute('id');
            }
        }

        return $ids;
    }

    private static function document(string $xml, string $name): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;

            if ($xml === '' || ! $document->loadXML($xml, LIBXML_NONET)) {
                throw new InputError("{$name} is not valid XML");
            }

            if ($document->documentElement?->localName !== 'testSuite') {
                throw new InputError("{$name} is not a PHPUnit test list (no <testSuite> root)");
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function has(string $group): bool
    {
        return array_key_exists($group, $this->groups);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->groups);
    }

    /**
     * @return list<string>
     */
    public function members(string $group): array
    {
        return $this->groups[$group] ?? [];
    }

    public function contains(string $group, string $id): bool
    {
        return in_array($id, $this->groups[$group] ?? [], true);
    }

    /**
     * The text form with a section for each named group, in this order. A
     * group without members gets an empty section.
     *
     * @param  list<string>  $groups
     */
    public function render(array $groups, string $comment = ''): string
    {
        $text = $comment === '' ? '' : '# '.str_replace("\n", "\n# ", $comment)."\n";

        foreach ($groups as $group) {
            $text .= "[{$group}]\n";

            foreach ($this->members($group) as $id) {
                $text .= $id."\n";
            }
        }

        return $text;
    }
}

final class JunitCase
{
    /**
     * @param  'passed'|'failed'|'skipped'  $status
     * @param  list<array{type: 'failure'|'error', text: string}>  $faults  in the order of the JUnit file
     */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly array $faults,
        public readonly int $assertions,
    ) {}
}

final class JunitReport
{
    /**
     * @param  list<JunitCase>  $cases
     * @param  int|null  $rootSkipped  the skipped attribute of the outermost <testsuite>
     * @param  int|null  $modified  the time of the file's last write (Unix seconds)
     */
    private function __construct(
        public readonly array $cases,
        public readonly int $failureElements,
        public readonly int $errorElements,
        public readonly ?int $rootSkipped,
        public readonly ?int $modified = null,
    ) {}

    public static function read(string $path): self
    {
        clearstatcache(true, $path);

        if (! is_file($path)) {
            throw new JunitProblem("no JUnit file at {$path}: PHPUnit did not write one");
        }

        $xml = @file_get_contents($path);
        $modified = @filemtime($path);

        if ($xml === false || $modified === false) {
            throw new JunitProblem("cannot read the JUnit file {$path}");
        }

        return self::parse($xml, $path, $modified);
    }

    public static function parse(string $xml, string $name = 'the JUnit file', ?int $modified = null): self
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;

            if (trim($xml) === '' || ! $document->loadXML($xml, LIBXML_NONET)) {
                $error = libxml_get_last_error();

                throw new JunitProblem(sprintf('the JUnit file %s is malformed%s', $name, $error === false ? '' : ': '.trim($error->message)));
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($document->documentElement?->nodeName !== 'testsuites') {
            throw new JunitProblem("the JUnit file {$name} is malformed: its root is not <testsuites>");
        }

        $cases = [];
        $failures = 0;
        $errors = 0;

        foreach ($document->getElementsByTagName('testcase') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            $caseName = $element->getAttribute('name');

            if ($caseName === '') {
                throw new JunitProblem("the JUnit file {$name} is malformed: a <testcase> without a name");
            }

            $status = 'passed';
            $faults = [];

            foreach ($element->childNodes as $child) {
                if (! $child instanceof DOMElement) {
                    continue;
                }

                if ($child->nodeName === 'failure' || $child->nodeName === 'error') {
                    $status = 'failed';
                    $faults[] = ['type' => $child->nodeName, 'text' => $child->textContent];
                    $child->nodeName === 'failure' ? $failures++ : $errors++;
                } elseif ($child->nodeName === 'skipped' && $status !== 'failed') {
                    $status = 'skipped';
                }
            }

            $cases[] = new JunitCase(
                TestIds::fromJunit($element->getAttribute('class'), $caseName),
                $status,
                $faults,
                (int) $element->getAttribute('assertions'),
            );
        }

        // PHPUnit writes the totals of the whole run on the outermost
        // <testsuite>. Its skipped count includes the tests of a class that
        // PHPUnit 12.5 skipped as a whole (setUpBeforeClass), which have no
        // <testcase>. PHPUnit 11.5 does not count such a class there.
        $rootSkipped = null;

        foreach ($document->documentElement->childNodes as $child) {
            if ($child instanceof DOMElement && $child->nodeName === 'testsuite') {
                $rootSkipped = preg_match('/^\d+$/D', $child->getAttribute('skipped')) === 1 ? (int) $child->getAttribute('skipped') : null;

                break;
            }
        }

        return new self($cases, $failures, $errors, $rootSkipped, $modified);
    }

    /**
     * The skipped tests of the run: the root suite's count when the file has
     * one, else the skipped test cases.
     */
    public function skippedTotal(): int
    {
        return $this->rootSkipped ?? $this->counts()['skipped'];
    }

    /**
     * @return array<string, list<JunitCase>>
     */
    public function byId(): array
    {
        $cases = [];

        foreach ($this->cases as $case) {
            $cases[$case->id][] = $case;
        }

        return $cases;
    }

    /**
     * @return array{tests: int, executed: int, passed: int, failed: int, skipped: int, assertions: int}
     */
    public function counts(): array
    {
        $counts = ['tests' => count($this->cases), 'executed' => 0, 'passed' => 0, 'failed' => 0, 'skipped' => 0, 'assertions' => 0];

        foreach ($this->cases as $case) {
            $counts[$case->status]++;
            $counts['assertions'] += $case->assertions;
        }

        $counts['executed'] = $counts['passed'] + $counts['failed'];

        return $counts;
    }
}

/**
 * The result summary that PHPUnit prints at the end of a run.
 */
final class PhpunitSummary
{
    /**
     * @param  array<string, int>  $counts
     */
    private function __construct(public readonly string $line, public readonly array $counts) {}

    /**
     * The last summary in the output, or null.
     */
    public static function fromOutput(string $output): ?self
    {
        $output = (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $output);
        $lines = array_reverse(preg_split('/\R/', $output) ?: []);

        foreach ($lines as $line) {
            $line = trim($line);

            if (preg_match('/^OK \((\d+) tests?, (\d+) assertions?\)$/D', $line, $match) === 1) {
                return new self($line, ['Tests' => (int) $match[1], 'Assertions' => (int) $match[2]]);
            }

            if ($line === 'No tests executed!') {
                return new self($line, ['Tests' => 0, 'Assertions' => 0]);
            }

            if (preg_match('/^Tests: \d+, Assertions: \d+(, [A-Za-z][A-Za-z ]*: \d+)*\.$/D', $line) === 1) {
                preg_match_all('/([A-Za-z][A-Za-z ]*): (\d+)/', $line, $pairs, PREG_SET_ORDER);
                $counts = [];

                foreach ($pairs as $pair) {
                    $counts[$pair[1]] = (int) $pair[2];
                }

                return new self($line, $counts);
            }
        }

        return null;
    }

    public function count(string $name): int
    {
        return $this->counts[$name] ?? 0;
    }
}

final class CheckResult
{
    /**
     * @param  list<string>  $reasons
     * @param  list<array{id: string, line: int, finding: string, fixed_by: string, faults: list<array{type: string, line: int}>}>  $listed
     * @param  list<array{id: string, message: string}>  $unlisted
     * @param  list<array{id: string, entry: string, manifest_line: int|null, message: string}>  $unconditional
     * @param  array<string, array{members: int, exempt: int, executed: int, skipped: int, missing: int}>  $groups
     * @param  array{tests: int, executed: int, passed: int, failed: int, skipped: int, assertions: int}|null  $counts
     * @param  array<string, int>|null  $summary
     * @param  list<string>  $engineSkip
     * @param  bool  $incomplete  no PHPUnit output: the check could not see warnings, risky tests or the summary counts
     */
    public function __construct(
        public readonly string $engine,
        public readonly int $exit,
        public readonly array $reasons,
        public readonly array $listed,
        public readonly array $unlisted,
        public readonly array $unconditional,
        public readonly array $groups,
        public readonly ?array $counts,
        public readonly ?array $summary,
        public readonly array $engineSkip = [],
        public readonly bool $incomplete = false,
    ) {}

    public function passed(): bool
    {
        return in_array($this->verdict(), ['pass', 'pass-listed'], true);
    }

    /**
     * pass, pass-listed (every fault is a listed known failure), fail, or
     * incomplete (no reason to fail, but no PHPUnit output either). Only the
     * first two pass.
     */
    public function verdict(): string
    {
        if ($this->reasons !== []) {
            return 'fail';
        }

        if ($this->incomplete) {
            return 'incomplete';
        }

        return $this->listed === [] ? 'pass' : 'pass-listed';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict(),
            'engine' => $this->engine,
            'exit' => $this->exit,
            'counts' => $this->counts,
            'summary' => $this->summary,
            'engine_skip' => $this->engineSkip,
            'groups' => $this->groups,
            'listed' => $this->listed,
            'unlisted' => $this->unlisted,
            'unconditional' => $this->unconditional,
            'reasons' => $this->reasons,
        ];
    }

    public function report(string $junit): string
    {
        $lines = [];
        $lines[] = "JUnit file: {$junit}";
        $lines[] = "Engine: {$this->engine}";
        $lines[] = "PHPUnit exit status (raw): {$this->exit}";

        if ($this->counts !== null) {
            $c = $this->counts;
            $lines[] = sprintf('Test cases: %d (%d passed, %d failed, %d skipped), %d assertions', $c['tests'], $c['passed'], $c['failed'], $c['skipped'], $c['assertions']);
        }

        if ($this->engineSkip !== []) {
            $lines[] = 'Engine skip: the members of '.implode(', ', $this->engineSkip).' may skip';
        }

        foreach ($this->groups as $group => $g) {
            $lines[] = sprintf('Group %s (required): %d members (%d may skip), %d executed, %d skipped, %d missing', $group, $g['members'], $g['exempt'], $g['executed'], $g['skipped'], $g['missing']);
        }

        $lines[] = sprintf('Classification: %d listed, %d unlisted, %d unconditional', count($this->listed), count($this->unlisted), count($this->unconditional));

        foreach ($this->listed as $listed) {
            $faults = implode(', ', array_map(static fn (array $fault): string => "{$fault['type']}: manifest line {$fault['line']}", $listed['faults']));
            $lines[] = "  listed: {$listed['id']} ({$faults}; {$listed['finding']}, fixed by {$listed['fixed_by']})";
        }

        foreach ($this->reasons as $reason) {
            $lines[] = "  FAIL: {$reason}";
        }

        $lines[] = match ($this->verdict()) {
            'pass' => 'Result: PASS',
            'pass-listed' => sprintf('Result: PASS with %d listed known failure%s', count($this->listed), count($this->listed) === 1 ? '' : 's'),
            'incomplete' => 'Result: INCOMPLETE: without --output=<PHPUnit output> the check cannot see warnings, risky tests or the summary counts, so it does not pass',
            default => sprintf('Result: FAIL (%d reason%s)', count($this->reasons), count($this->reasons) === 1 ? '' : 's'),
        };

        return implode("\n", $lines)."\n";
    }
}

final class Check
{
    /**
     * Exit statuses that PHPUnit or bin/test use for a run that did not
     * complete. PHPUnit itself exits 0 (success), 1 (a failure, or an issue
     * that the configuration fails on, such as a warning or a risky test) or
     * 2 (an error: a test error, or PHPUnit could not run). 255 is a crash.
     */
    private const EXIT_MEANINGS = [
        64 => 'bin/test refused its arguments',
        72 => 'bin/test did not get the heavy-run slot within --slot-wait',
        74 => 'bin/test could not confirm the ownership of its processes',
        78 => 'tests/bootstrap.php refused the configuration or the lease proof. A refusal is never a known failure',
        79 => 'the startup gate of bin/test did not open',
        124 => 'bin/test stopped the run at its --timeout',
        129 => 'a signal (SIGHUP) ended the run',
        130 => 'a signal (SIGINT) ended the run',
        143 => 'a signal (SIGTERM) ended the run',
        255 => 'PHP ended with a fatal error',
    ];

    /**
     * @param  list<string>  $require  groups whose members must run
     * @param  list<string>  $engineSkip  groups whose members may skip in this run
     * @param  string|null  $output  PHPUnit's output; null gives an incomplete result
     * @param  int|null  $started  the start of the run (Unix seconds); the JUnit file and the output must not be older
     * @param  int|null  $outputModified  the time of the output file's last write (Unix seconds)
     */
    public static function evaluate(
        JunitReport|JunitProblem $junit,
        string $engine,
        Inventory $inventory,
        array $require,
        array $engineSkip,
        int $exit,
        ?string $output,
        Manifest $manifest,
        Unconditional $unconditional,
        ?int $started = null,
        ?int $outputModified = null,
    ): CheckResult {
        Engines::validate($engine);

        foreach ($require as $group) {
            if (! $inventory->has($group)) {
                throw new InputError("the required group '{$group}' has no section in the inventory");
            }
        }

        foreach ($engineSkip as $group) {
            if (! $inventory->has($group)) {
                throw new InputError("the engine-skip group '{$group}' has no section in the inventory");
            }
        }

        foreach ($unconditional->groups as $group) {
            if (! $inventory->has($group)) {
                throw new InputError("tests/unconditional.txt names the group '{$group}', but the inventory has no section for it, so the check cannot recognise its tests");
            }
        }

        $reasons = [];
        $listed = [];
        $unlisted = [];
        $unconditionalFailures = [];
        $groups = [];
        $counts = null;

        if ($started !== null) {
            if ($junit instanceof JunitReport && ($junit->modified === null || $junit->modified < $started)) {
                $reasons[] = sprintf('the JUnit file was last written %s, before the run started (%s): it is not from this run', self::time($junit->modified), self::time($started));
            }

            if ($output !== null && ($outputModified === null || $outputModified < $started)) {
                $reasons[] = sprintf('the PHPUnit output was last written %s, before the run started (%s): it is not from this run', self::time($outputModified), self::time($started));
            }
        }

        if ($junit instanceof JunitProblem) {
            $reasons[] = $junit->getMessage();
        } else {
            $counts = $junit->counts();

            if ($counts['executed'] === 0) {
                $reasons[] = 'the JUnit file has no executed test';
            }

            foreach ($junit->cases as $case) {
                if ($case->status !== 'failed') {
                    continue;
                }

                // Each fault is matched on its own text: one matched fault
                // never excuses another fault of the same test case.
                $rows = array_map(static fn (array $fault): ?ManifestRow => $manifest->match($case->id, $engine, $fault['text']), $case->faults);
                $matched = array_values(array_filter($rows));
                $entry = $unconditional->entryFor($case->id, $inventory);

                if ($entry !== null) {
                    $row = $matched[0] ?? null;
                    $message = self::firstLine($case->faults[0]['text']);
                    $unconditionalFailures[] = ['id' => $case->id, 'entry' => $entry, 'manifest_line' => $row?->line, 'message' => $message];
                    $reasons[] = sprintf(
                        'unconditional failure: %s (tests/unconditional.txt: %s)%s: %s',
                        $case->id,
                        $entry,
                        $row === null ? '' : ", never excused, although manifest line {$row->line} matches",
                        $message,
                    );

                    continue;
                }

                if (count($matched) === count($case->faults)) {
                    $listed[] = [
                        'id' => $case->id,
                        'line' => $matched[0]->line,
                        'finding' => $matched[0]->finding,
                        'fixed_by' => $matched[0]->fixedBy,
                        'faults' => array_map(static fn (array $fault, ManifestRow $row): array => ['type' => $fault['type'], 'line' => $row->line], $case->faults, $matched),
                    ];

                    continue;
                }

                $total = count($case->faults);

                foreach ($case->faults as $index => $fault) {
                    if ($rows[$index] !== null) {
                        continue;
                    }

                    $message = self::firstLine($fault['text']);
                    $unlisted[] = ['id' => $case->id, 'message' => $message];
                    $reasons[] = $total === 1
                        ? "unlisted failure on {$engine}: {$case->id}: {$message}"
                        : sprintf('unlisted failure on %s: %s (%s %d of %d; a listed fault of the same test does not excuse it): %s', $engine, $case->id, $fault['type'], $index + 1, $total, $message);
                }
            }

            $byId = $junit->byId();
            $exempt = [];

            foreach ($engineSkip as $group) {
                foreach ($inventory->members($group) as $id) {
                    $exempt[$id] = $group;
                }
            }

            foreach ($require as $group) {
                $members = $inventory->members($group);
                $stats = ['members' => count($members), 'exempt' => 0, 'executed' => 0, 'skipped' => 0, 'missing' => 0];

                if ($members === []) {
                    $reasons[] = "the required group '{$group}' has no tests, so it ran zero tests";
                }

                foreach ($members as $id) {
                    if (isset($exempt[$id])) {
                        $stats['exempt']++;
                    }

                    $cases = $byId[$id] ?? [];

                    if ($cases === []) {
                        $stats['missing']++;
                        $reasons[] = "a test of the required group '{$group}' is missing from the JUnit file: {$id}";

                        continue;
                    }

                    $executed = array_filter($cases, static fn (JunitCase $case): bool => $case->status !== 'skipped');

                    if ($executed !== []) {
                        $stats['executed']++;

                        continue;
                    }

                    $stats['skipped']++;

                    if (! isset($exempt[$id])) {
                        $reasons[] = "a test of the required group '{$group}' was skipped: {$id}".($engineSkip === [] ? '' : ' (only the members of '.implode(', ', $engineSkip).' may skip in this run)');
                    }
                }

                $groups[$group] = $stats;
            }
        }

        $failed = $counts['failed'] ?? 0;

        if (array_key_exists($exit, self::EXIT_MEANINGS)) {
            $reasons[] = "PHPUnit exit status {$exit}: ".self::EXIT_MEANINGS[$exit];
        } elseif (! in_array($exit, [0, 1, 2], true)) {
            $reasons[] = "PHPUnit exit status {$exit}: the run did not complete";
        } elseif ($junit instanceof JunitReport) {
            // PHPUnit's ShellExitCodeCalculator: 2 when the run has an
            // error, else 1 when it has a failure or an issue that the
            // configuration fails on, else 0.
            if ($exit === 0 && $failed > 0) {
                $reasons[] = "PHPUnit exited 0, but the JUnit file records {$failed} failed test".($failed === 1 ? '' : 's');
            } elseif ($exit === 1 && $junit->errorElements > 0) {
                $reasons[] = "PHPUnit exited 1, but the JUnit file records {$junit->errorElements} error".($junit->errorElements === 1 ? '' : 's').' (PHPUnit exits 2 on an error): the JUnit file is not from this run';
            } elseif ($exit === 1 && $junit->failureElements === 0) {
                $reasons[] = 'PHPUnit exited 1, but the JUnit file records no failure: PHPUnit failed for a reason that the JUnit file does not record (for example a warning or a risky test)';
            } elseif ($exit === 2 && $junit->errorElements === 0) {
                $reasons[] = 'PHPUnit exited 2, but the JUnit file records no error: PHPUnit could not run its tests, or an error happened outside a test case';
            }
        }

        $summary = null;

        if ($output !== null) {
            $parsed = PhpunitSummary::fromOutput($output);

            if ($parsed === null) {
                $reasons[] = 'the PHPUnit output has no result summary: PHPUnit did not finish';
            } else {
                $summary = $parsed->counts;
                array_push($reasons, ...self::summaryReasons($parsed, $junit));
            }
        }

        return new CheckResult($engine, $exit, $reasons, $listed, $unlisted, $unconditionalFailures, $groups, $counts, $summary, $engineSkip, $output === null);
    }

    /**
     * The summary against the JUnit file. PHPUnit counts a test in "Tests"
     * when it finishes, and when it is skipped, incomplete or errors before
     * it is prepared. The JUnit file has a <testcase> for each of them, so the
     * executed tests are Tests - Skipped - Incomplete on both sides. A class
     * that setUpBeforeClass skips is in Skipped and in the root suite's
     * skipped count (PHPUnit 12.5), not in a <testcase>. A fault in
     * setUpBeforeClass adds a test and an error to the summary only.
     *
     * @return list<string>
     */
    private static function summaryReasons(PhpunitSummary $summary, JunitReport|JunitProblem $junit): array
    {
        $reasons = [];
        $tests = $summary->count('Tests');
        $warnings = $summary->count('Warnings') + $summary->count('PHPUnit Warnings');

        if ($tests === 0) {
            $reasons[] = "the PHPUnit summary says that no test ran ({$summary->line}): whatever the JUnit file holds, this run tested nothing";
        }

        if ($warnings > 0) {
            $reasons[] = "PHPUnit reported {$warnings} warning".($warnings === 1 ? '' : 's').' ('.$summary->line.'). The manifest cannot excuse a warning';
        }

        if ($summary->count('Risky') > 0) {
            $reasons[] = "PHPUnit reported {$summary->count('Risky')} risky test".($summary->count('Risky') === 1 ? '' : 's').' ('.$summary->line.'). The manifest cannot excuse a risky test';
        }

        if (! $junit instanceof JunitReport) {
            return $reasons;
        }

        $counts = $junit->counts();
        $faultsAgree = $summary->count('Errors') === $junit->errorElements && $summary->count('Failures') === $junit->failureElements;

        if (! $faultsAgree) {
            $reasons[] = sprintf(
                'the PHPUnit summary reports %d errors and %d failures, the JUnit file %d and %d: a failure outside a test case (for example in setUpBeforeClass) is not in the JUnit file',
                $summary->count('Errors'),
                $summary->count('Failures'),
                $junit->errorElements,
                $junit->failureElements,
            );
        }

        if ($summary->count('Assertions') !== $counts['assertions']) {
            $reasons[] = sprintf('the PHPUnit summary reports %d assertions, the JUnit file %d: the JUnit file is not from this run', $summary->count('Assertions'), $counts['assertions']);
        }

        $skipped = $summary->count('Skipped') + $summary->count('Incomplete');

        if ($skipped !== $junit->skippedTotal()) {
            $reasons[] = sprintf('the PHPUnit summary reports %d skipped or incomplete tests, the JUnit file %d: the JUnit file is not from this run', $skipped, $junit->skippedTotal());
        }

        // A fault outside a test case adds a test to the summary only, and
        // the reason above already names it.
        if ($faultsAgree && $tests - $skipped !== $counts['executed']) {
            $reasons[] = sprintf('the PHPUnit summary reports %d executed tests (%d tests, %d skipped or incomplete), the JUnit file %d: the JUnit file is not from this run', $tests - $skipped, $tests, $skipped, $counts['executed']);
        }

        return $reasons;
    }

    private static function time(?int $time): string
    {
        return $time === null ? 'at an unknown time' : 'at '.gmdate('Y-m-d H:i:s', $time).' UTC';
    }

    private static function firstLine(string $text): string
    {
        $message = trim($text);

        foreach (preg_split('/\R/', $message) ?: [] as $index => $line) {
            // The first line of a JUnit fault is the test id.
            if ($index > 0 && trim($line) !== '') {
                $message = trim($line);

                break;
            }
        }

        return strlen($message) > 300 ? substr($message, 0, 297).'...' : $message;
    }
}

/**
 * bin/check-known-failures.
 */
final class CheckCommand
{
    public const USAGE = <<<'TXT'
        Usage: bin/check-known-failures <junit.xml> <engine> <inventory.txt> --exit=<status>
                                        --started=<unix seconds> --output=<file> [options]

        Classifies the faults of one PHPUnit run against tests/known-failures.md,
        and checks that every test of the required groups ran (Spec A §4.8).

          <engine>              <sqlite|mysql|pgsql>/<same|separate>/<prefix|none>
          <inventory.txt>       [group] sections with the member test ids of each
                                group (bin/matrix writes it from phpunit --list-tests-xml)
          --exit=<status>       PHPUnit's raw exit status (bin/test passes it through)
          --started=<seconds>   required: the Unix time when the run started, for
                                example from `date +%s` before bin/test. The JUnit
                                file and the output must not be older.
          --output=<file>       PHPUnit's output (stdout and stderr of bin/test). Its
                                summary must show tests, no warning and no risky test,
                                and agree with the JUnit file. Without it the result
                                is INCOMPLETE (exit 1).
          --require=<groups>    the groups that must run (default: every inventory
                                section; --require= for none)
          --engine-skip=<groups>
                                groups whose members may skip in this run (the
                                documented engine skip: shared-database on in-memory
                                SQLite). A skip of any other required test fails.
          --json=<file>         also write the result as JSON
          --manifest=<file>     default: tests/known-failures.md of this checkout
          --unconditional=<file>
                                default: tests/unconditional.txt of this checkout

        Exit status: 0 pass (clean, or every fault is listed), 1 fail or incomplete,
        64 invalid input.

        TXT;

    /**
     * @param  list<string>  $argv
     */
    public static function main(array $argv, string $root): int
    {
        try {
            $positional = [];
            $options = [];

            foreach (array_slice($argv, 1) as $argument) {
                if ($argument === '-h' || $argument === '--help') {
                    fwrite(STDOUT, self::USAGE);

                    return 0;
                }

                if (preg_match('/^--(exit|started|require|engine-skip|output|json|manifest|unconditional)=(.*)$/sD', $argument, $match) === 1) {
                    if (array_key_exists($match[1], $options)) {
                        throw new InputError("--{$match[1]} is given twice");
                    }

                    $options[$match[1]] = $match[2];

                    continue;
                }

                if (str_starts_with($argument, '-')) {
                    throw new InputError("unknown option {$argument}");
                }

                $positional[] = $argument;
            }

            if (count($positional) !== 3) {
                throw new InputError('expected <junit.xml> <engine> <inventory.txt>');
            }

            [$junitPath, $engine, $inventoryPath] = $positional;

            if (! isset($options['exit']) || preg_match('/^[0-9]{1,3}$/D', $options['exit']) !== 1 || (int) $options['exit'] > 255) {
                throw new InputError('--exit=<status> is required: the raw PHPUnit exit status, 0 to 255');
            }

            if (! isset($options['started']) || preg_match('/^[0-9]{1,12}$/D', $options['started']) !== 1) {
                throw new InputError('--started=<unix seconds> is required: the time when the run started (for example `date +%s` before bin/test). The JUnit file and the output must not be older, so a file from an earlier run cannot pass');
            }

            $inventory = Inventory::read($inventoryPath);
            $require = isset($options['require']) ? self::list($options['require']) : $inventory->names();
            $engineSkip = isset($options['engine-skip']) ? self::list($options['engine-skip']) : [];

            $output = null;
            $outputModified = null;

            if (isset($options['output'])) {
                clearstatcache(true, $options['output']);

                if (! is_file($options['output']) || ! is_readable($options['output'])) {
                    throw new InputError("cannot read the PHPUnit output {$options['output']}");
                }

                $output = (string) file_get_contents($options['output']);
                $outputModified = (int) filemtime($options['output']);
            }

            try {
                $junit = JunitReport::read($junitPath);
            } catch (JunitProblem $problem) {
                $junit = $problem;
            }

            $result = Check::evaluate(
                $junit,
                $engine,
                $inventory,
                $require,
                $engineSkip,
                (int) $options['exit'],
                $output,
                Manifest::read($options['manifest'] ?? $root.'/tests/known-failures.md'),
                Unconditional::read($options['unconditional'] ?? $root.'/tests/unconditional.txt'),
                (int) $options['started'],
                $outputModified,
            );
        } catch (InputError $error) {
            fwrite(STDERR, 'bin/check-known-failures: '.$error->getMessage()."\n\n".self::USAGE);

            return 64;
        }

        fwrite(STDOUT, $result->report($junitPath));

        if (isset($options['json'])) {
            file_put_contents($options['json'], json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
        }

        return $result->passed() ? 0 : 1;
    }

    /**
     * @return list<string>
     */
    private static function list(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        $groups = array_map('trim', explode(',', $value));

        foreach ($groups as $group) {
            if (preg_match(Unconditional::GROUP_PATTERN, $group) !== 1) {
                throw new InputError("invalid group name '{$group}'");
            }
        }

        return array_values(array_unique($groups));
    }
}
