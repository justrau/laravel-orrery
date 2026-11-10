<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The operator wording table:
 * resources/js/support/wording.js holds one sentence template per code, and
 * every screen renders codes only through it.
 *
 * The test reads the PHP sources, finds every value that an operator can see,
 * sorts it by kind, and checks it against the table of its own kind in
 * wording.js: a source against `SOURCES`, an awaiting reason against
 * `AWAITING_REASONS`, a result or an outcome against `OUTCOMES`, an operation
 * state against `OPERATION_STATES`, and every other code against `CODES`.
 *
 * - Constants (CONSTANT_FILES): the result kinds of `RetryResult`,
 *   `ForgetResult` and `Http\Bulk\BulkRun` (outcomes; not its actions); the codes of
 *   `RetryHandler`, `Publication` and the refusals of `Failed\FailedRecords`
 *   (codes; FailedRecords' adapter kinds `sql`, `none` and `read_only` are
 *   not shown as codes and are left out by name).
 * - Code positions in `src/Retry/**`, `src/Failed/ReadOnlyFailedRecords.php`,
 *   `src/Http/Bulk/**` and the bulk, job,
 *   stuck-list and batch controllers: the code argument of `new RetryResult(…)`, `new
 *   ForgetResult(…)`, `OperationStep::undecided()` and `blocked()`, `abort()`
 *   and `aborted()`, a `$code = …` assignment, the second element of an
 *   `[self::OUTCOME, …]` pair and the value of an `'error'`, `'code'` or
 *   `'error_code'` key (codes); the argument of `OperationStep::awaiting()`
 *   and an `'awaiting_reason'` value (awaiting reasons); an `'outcome'` or
 *   `'result'` value (outcomes); the refusal code of `ReadOnlyFailedRecords`'
 *   `new self(…)` (codes). A class constant in such a position is resolved.
 * - Sources: `Operations::SOURCES` and the `'cycle_source'` literals.
 * - States: `Operations::ACTIVE` and `Operations::TERMINAL`.
 *
 * The section 5.6 table is checked as well, so a code of a file that a later
 * task adds is worded before that file exists. It reads files only; it never
 * runs Node.
 */
#[Group('scripts')]
final class OperatorWordingTest extends TestCase
{
    /** The codes of the section 5.6 table, by the kind of 5.6. */
    private const SPEC_CODES = [
        'source' => ['push', 'native', 'dashboard', 'batch', 'bulk', 'cli', 'unknown'],
        'awaiting_reason' => ['sqlite_host_file', 'sqlite_reads_not_allowed'],
        'refused' => ['provider_read_only', 'ids_may_be_reused', 'occurrence_unprovable', 'fence_missing', 'queue_unreachable', 'mirror_unreachable', 'record_unreachable', 'target_changed', 'schema_outdated', 'app_key_missing'],
        'not_eligible' => ['not_failed', 'occurrence_mismatch', 'operation_in_progress', 'record_missing'],
        'failed' => ['prepare_failed', 'claim_failed', 'record_gone', 'record_delete_failed', 'cancelled', 'deadline_passed', 'publish_failed', 'provider_error', 'row_moved', 'restore_conflict', 'restore_failed', 'resolution_failed', 'finalize_failed', 'delete_decision_failed'],
        'uncertain' => ['claim_uncertain', 'publish_uncertain', 'record_delete_uncertain'],
        'outcome' => ['accepted', 'refused', 'uncertain', 'not_member', 'already_done', 'not_confirmed'],
        'item' => ['missing', 'occurrence_changed', 'other_group', 'out_of_scope', 'retry_in_progress', 'retried', 'gone', 'mirror_pending', 'retry_uncertain'],
        'http' => ['frozen_invalid', 'frozen_expired', 'confirmation_mismatch', 'frozen_mismatch', 'total_not_exact', 'too_many', 'config_invalid', 'index_missing', 'schema_outdated', 'app_key_missing'],
    ];

    /** The kind of each 5.6 group; every other group is a code. */
    private const SPEC_KINDS = ['source' => 'source', 'awaiting_reason' => 'awaiting', 'outcome' => 'outcome'];

    /** The wording table of each kind. */
    private const TABLES = ['source' => 'SOURCES', 'awaiting' => 'AWAITING_REASONS', 'outcome' => 'OUTCOMES', 'state' => 'OPERATION_STATES', 'code' => 'CODES'];

    /**
     * The files whose string constants are shown: [kind, constant names left
     * out]. The left-out names of FailedRecords are adapter kinds; those of
     * Publication are outcomes that the runner handles.
     */
    private const CONSTANT_FILES = [
        'src/Retry/RetryResult.php' => ['outcome', []],
        'src/Retry/ForgetResult.php' => ['outcome', []],
        // The member outcomes of a bulk run (they replaced Failures\GroupOperation's); RETRY and FORGET are its actions.
        'src/Http/Bulk/BulkRun.php' => ['outcome', ['RETRY', 'FORGET']],
        'src/Retry/RetryHandler.php' => ['code', []],
        // A publication that is published, failed, uncertain or undecided goes on through the runner and is never an operator's code.
        'src/Retry/Publication.php' => ['code', ['PUBLISHED', 'FAILED', 'UNCERTAIN', 'UNDECIDED']],
        'src/Failed/FailedRecords.php' => ['code', ['SQL', 'NONE', 'READ_ONLY']],
        'src/Failed/ReadOnlyFailedRecords.php' => ['code', []],
    ];

    private const WORDING = 'resources/js/support/wording.js';

    /**
     * Rejects: a wording table that misses a value which the services, the
     * bulk runs, the publication or the operation steps can answer (a
     * new refusal code added in src/Retry without a sentence), a code of the
     * section 5.6 table, and a value worded only in the table of another kind
     * (an awaiting reason in CODES, a result kind only in CODES).
     * Still passes: a code built at run time from parts (for example
     * `'forget:' . $code`), and a code returned from a helper function in a
     * position that the scan does not know; the 5.6 table covers the known
     * ones.
     */
    public function test_every_operator_code_has_a_wording_of_its_kind(): void
    {
        $tables = $this->wordedTables();
        $missing = [];

        foreach ($this->sourceCodes() as $kind => $codes) {
            foreach ($codes as $code => $where) {
                if (! isset($tables[self::TABLES[$kind]][$code])) {
                    $missing[] = self::TABLES[$kind].": {$code} (".implode(', ', array_unique($where)).')';
                }
            }
        }

        foreach (self::SPEC_CODES as $group => $codes) {
            $table = self::TABLES[self::SPEC_KINDS[$group] ?? 'code'];

            foreach ($codes as $code) {
                if (! isset($tables[$table][$code])) {
                    $missing[] = "{$table}: {$code} (section 5.6, {$group})";
                }
            }
        }

        self::assertSame([], $missing, 'Values without an operator wording of their kind in '.self::WORDING.":\n".implode("\n", $missing));
    }

    /**
     * Rejects: a scan that finds nothing (a broken pattern would let every
     * code pass), one that misses a form (a literal in a ternary, a resolved
     * constant, a `[self::OUTCOME, …]` pair, an `OperationStep` code, the
     * constants of the result classes and of the publication, a refusal of
     * the read-only adapter), and one that puts a value under another kind.
     */
    public function test_the_scan_finds_the_codes_of_each_position_and_kind(): void
    {
        $codes = $this->sourceCodes();

        foreach ([
            ['code', 'occurrence_unprovable'],   // new RetryResult(…, 'occurrence_unprovable', …)
            ['code', 'operation_in_progress'],   // inside a ternary of the code argument
            ['awaiting', 'sqlite_host_file'],    // OperationStep::awaiting(Operations::SQLITE_HOST_FILE), resolved
            ['code', 'retry_uncertain'],         // [self::UNCERTAIN, $result->code ?? 'retry_uncertain']
            ['code', 'occurrence_changed'],      // [self::NOT_MEMBER, 'occurrence_changed']
            ['code', 'handler_missing'],         // OperationStep::undecided('handler_missing'), only there
            ['code', 'publish_failed'],          // $this->aborted($operation, 'publish_failed', …)
            ['code', 'deadline_passed'],         // $code = … ? 'cancelled' : 'deadline_passed'
            ['code', 'record_gone'],             // Publication::RECORD_GONE
            ['code', 'provider_read_only'],      // FailedRecords::PROVIDER_READ_ONLY, and new self(self::PROVIDER_READ_ONLY, …)
            ['outcome', 'not_eligible'],         // RetryResult::NOT_ELIGIBLE
            ['outcome', 'mirror_pending'],       // ForgetResult::MIRROR_PENDING
            ['code', 'restore_conflict'],        // RetryHandler::RESTORE_CONFLICT
            ['outcome', 'not_member'],           // BulkRun::NOT_MEMBER
            ['outcome', 'not_confirmed'],        // BulkRun::NOT_CONFIRMED
            ['source', 'dashboard'],             // Operations::SOURCES
            ['source', 'push'],                  // 'cycle_source' => $push ? 'push' : 'unknown'
            ['state', 'aborting'],               // Operations::ACTIVE
            ['state', 'aborted'],                // Operations::TERMINAL
        ] as [$kind, $expected]) {
            self::assertArrayHasKey($expected, $codes[$kind] ?? [], "the scan misses the {$kind} {$expected}");
        }

        self::assertContains('src/Retry/Publication.php', $codes['code']['record_gone'] ?? [], 'record_gone is also found as a constant of the publication');
        self::assertContains('src/Http/Controllers/StuckJobsController.php', $codes['code']['index_missing'] ?? [], "the stuck list's 'error' => 'index_missing'");
        self::assertArrayNotHasKey('undecided', $codes['code'] ?? [], 'a publication outcome that the runner handles is not a code');
        self::assertArrayNotHasKey('sqlite_host_file', $codes['code'] ?? [], 'an awaiting reason is not a code');
        $all = array_merge(...array_values(array_map('array_keys', $codes)));
        self::assertNotContains('orrery_operations', $all, 'a table name is not a code');
        self::assertNotContains('default', $all, 'a queue name default is not a code');
        self::assertNotContains('read_only', $all, 'an adapter kind is not a code');
        self::assertNotContains('retry.before_commit', $all, 'a hook point is not a code');
    }

    /**
     * Rejects: a parser of the wording table that reads nothing (every check
     * would then fail for the wrong reason), one that counts a template's
     * text as a key, and one that puts the keys of one table into another.
     */
    public function test_the_wording_parser_reads_the_keys_of_each_table(): void
    {
        $tables = self::tablesOf(<<<'JS'
            export const OUTCOMES = Object.freeze({
                accepted: 'Accepted.',
            });
            export const CODES = Object.freeze({
                not_failed: 'This failure is no longer failed: nothing changed.',
                record_missing: "The failed-job record is gone: nothing changed.",
                restore_failed: `The record could not be put back. Run {command}.`,
            });
            export function codeText(code) { return CODES[code]; }
            JS);

        self::assertSame(['accepted'], array_keys($tables['OUTCOMES']));
        self::assertSame(['not_failed', 'record_missing', 'restore_failed'], array_keys($tables['CODES']));

        $real = $this->wordedTables();
        foreach (self::TABLES as $table) {
            self::assertNotEmpty($real[$table] ?? [], "{$table} is missing from ".self::WORDING);
        }
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function wordedTables(): array
    {
        $path = self::root().'/'.self::WORDING;
        self::assertFileExists($path);

        return self::tablesOf((string) file_get_contents($path));
    }

    /**
     * The keys of each `export const NAME = Object.freeze({…});` table: every
     * `key: '…'`, `key: "…"` or ``key: `…` `` entry on its own line.
     *
     * @return array<string, array<string, true>>
     */
    private static function tablesOf(string $source): array
    {
        preg_match_all('/^export const ([A-Z_]+) = Object\.freeze\(\{(.*?)^\}\);/ms', $source, $blocks, PREG_SET_ORDER);
        $tables = [];

        foreach ($blocks as [, $name, $body]) {
            preg_match_all('/^\s+([a-z][a-z0-9_]*)\s*:\s*[\'"`]/m', $body, $matches);
            $tables[$name] = array_fill_keys($matches[1], true);
        }

        return $tables;
    }

    /**
     * Every shown value of the scanned sources by kind, with the files that
     * use it.
     *
     * @return array<string, array<string, list<string>>>
     */
    private function sourceCodes(): array
    {
        $codes = [];
        $add = static function (string $kind, string $code, string $file) use (&$codes): void {
            if (preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $code) === 1) {
                $codes[$kind][$code][] = $file;
            }
        };

        foreach (self::CONSTANT_FILES as $file => [$kind, $leftOut]) {
            preg_match_all("/public\\s+const\\s+([A-Z_]+)\\s*=\\s*'([^']*)'\\s*;/", (string) file_get_contents(self::root().'/'.$file), $matches, PREG_SET_ORDER);

            foreach ($matches as [, $name, $value]) {
                if (! in_array($name, $leftOut, true)) {
                    $add($kind, $value, $file);
                }
            }
        }

        foreach ($this->scannedFiles() as $file) {
            $source = (string) file_get_contents(self::root().'/'.$file);

            foreach (self::codeExpressions($source, $file) as [$kind, $expression, $resolveConstants]) {
                foreach (self::valuesIn($expression, $source, $resolveConstants) as $value) {
                    $add($kind, $value, $file);
                }
            }
        }

        $operations = 'Justrau\\Orrery\\Retry\\Operations';
        foreach ((array) constant($operations.'::SOURCES') as $source) {
            $add('source', (string) $source, 'src/Retry/Operations.php');
        }
        foreach (array_merge((array) constant($operations.'::ACTIVE'), (array) constant($operations.'::TERMINAL')) as $state) {
            $add('state', (string) $state, 'src/Retry/Operations.php');
        }

        preg_match_all("/'cycle_source'\\s*=>\\s*([^,\\]\\n]*)/", (string) file_get_contents(self::root().'/src/Lifecycle/Rules.php'), $cycleSources);
        foreach ($cycleSources[1] as $expression) {
            foreach (self::valuesIn($expression, '', false) as $value) {
                $add('source', $value, 'src/Lifecycle/Rules.php');
            }
        }

        foreach ($codes as &$byCode) {
            ksort($byCode);
        }

        return $codes;
    }

    /**
     * @return list<string> paths relative to the repository root
     */
    private function scannedFiles(): array
    {
        $root = self::root();
        $files = [];

        foreach (['src/Retry', 'src/Http/Bulk'] as $directory) {
            if (! is_dir($root.'/'.$directory)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        foreach (['src/Failed/ReadOnlyFailedRecords.php', 'src/Http/Controllers/JobController.php', 'src/Http/Controllers/StuckJobsController.php', 'src/Http/Controllers/JobWriteController.php', 'src/Http/Controllers/BulkController.php', 'src/Http/Controllers/BatchesController.php', 'src/Http/Controllers/WaitingJobsController.php', 'src/Http/Controllers/DelayedJobsController.php'] as $file) {
            if (is_file($root.'/'.$file)) {
                $files[] = $file;
            }
        }

        // The error codes of the tags API.
        if (is_file($root.'/src/Http/Controllers/TagsController.php')) {
            $files[] = 'src/Http/Controllers/TagsController.php';
        }

        sort($files);

        return $files;
    }

    /**
     * The kind and the source text of every code position in a file, and
     * whether its class constants are values. In an `[self::OUTCOME, …]` pair
     * and a `$code = …` assignment only the literals are: their constants are
     * list members (`[self::CLAIMED, self::PUBLISHED]`) or compared values
     * (`$fenced === Fence::DUPLICATE ? …`).
     *
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    private static function codeExpressions(string $source, string $file): array
    {
        $expressions = [];

        // The argument of a call: [pattern of the call up to its "(", index of the argument, kind].
        $calls = [
            ['/new\s+(?:RetryResult|ForgetResult)\s*\(/', 1, 'code'],
            ['/OperationStep::undecided\s*\(/', 0, 'code'],
            ['/OperationStep::blocked\s*\(/', 1, 'code'],
            ['/OperationStep::awaiting\s*\(/', 0, 'awaiting'],
            ['/->abort(?:ed)?\s*\(/', 1, 'code'],
        ];

        // The read-only adapter's refusal code: new self(<code>, <message>).
        if (str_ends_with($file, 'ReadOnlyFailedRecords.php')) {
            $calls[] = ['/new\s+self\s*\(/', 0, 'code'];
        }

        foreach ($calls as [$pattern, $index, $kind]) {
            preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [$text, $offset]) {
                $arguments = self::argumentsAt($source, $offset + strlen($text) - 1, ')');
                if (isset($arguments[$index])) {
                    $expressions[] = [$kind, $arguments[$index], true];
                }
            }
        }

        // [self::OUTCOME, <code>] pairs.
        preg_match_all('/\[\s*self::[A-Z_]+\s*,/', $source, $pairs, PREG_OFFSET_CAPTURE);
        foreach ($pairs[0] as [, $offset]) {
            $arguments = self::argumentsAt($source, $offset, ']');
            if (isset($arguments[1])) {
                $expressions[] = ['code', $arguments[1], false];
            }
        }

        // $code = <expression>;
        preg_match_all('/\$code\s*=(?!=)\s*([^;]*);/', $source, $assignments);
        foreach ($assignments[1] as $expression) {
            $expressions[] = ['code', $expression, false];
        }

        // The code keys of a response array.
        $keys = ['error' => 'code', 'code' => 'code', 'error_code' => 'code', 'awaiting_reason' => 'awaiting', 'outcome' => 'outcome', 'result' => 'outcome'];
        foreach ($keys as $key => $kind) {
            preg_match_all("/'{$key}'\\s*=>\\s*([^,\\]\\n]*)/", $source, $values);
            foreach ($values[1] as $expression) {
                $expressions[] = [$kind, $expression, true];
            }
        }

        return $expressions;
    }

    /**
     * The code values in an expression: its single-quoted literals, and its
     * class constants resolved through the file's namespace and imports.
     *
     * @return list<string>
     */
    private static function valuesIn(string $expression, string $source, bool $resolveConstants): array
    {
        // An array index (`$outcome['state']`) is a key, not a value.
        preg_match_all("/(?<!\\[)'([^'\\\\]*)'(?!\\])/", $expression, $literals);
        $values = $literals[1];

        if (! $resolveConstants) {
            return $values;
        }

        preg_match_all('/\b([A-Z][A-Za-z0-9_]*|self|static)::([A-Z][A-Z0-9_]*)\b/', $expression, $constants, PREG_SET_ORDER);
        foreach ($constants as [, $class, $name]) {
            $value = self::constantValue($class, $name, $source);
            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    private static function constantValue(string $class, string $name, string $source): mixed
    {
        preg_match('/^namespace\s+([^;]+);/m', $source, $namespace);
        $namespace = $namespace[1] ?? '';

        if ($class === 'self' || $class === 'static') {
            preg_match('/^(?:final\s+|abstract\s+)*(?:class|trait|enum|interface)\s+(\w+)/m', $source, $declared);
            $fqcn = $namespace.'\\'.($declared[1] ?? '');
        } elseif (preg_match('/^use\s+([\w\\\\]+\\\\'.preg_quote($class, '/').')\s*;/m', $source, $import) === 1) {
            $fqcn = $import[1];
        } else {
            $fqcn = $namespace.'\\'.$class;
        }

        return defined($fqcn.'::'.$name) ? constant($fqcn.'::'.$name) : null;
    }

    /**
     * The top-level arguments of the bracket that opens at $open, split on
     * commas outside strings and nested brackets.
     *
     * @return list<string>
     */
    private static function argumentsAt(string $source, int $open, string $close): array
    {
        $arguments = [];
        $current = '';
        $depth = 0;
        $length = strlen($source);

        for ($i = $open + 1; $i < $length; $i++) {
            $char = $source[$i];

            if ($char === "'" || $char === '"') {
                $end = $i + 1;
                while ($end < $length && $source[$end] !== $char) {
                    $end += $source[$end] === '\\' ? 2 : 1;
                }
                $current .= substr($source, $i, $end - $i + 1);
                $i = $end;

                continue;
            }

            if ($depth === 0 && $char === $close) {
                $arguments[] = trim($current);

                return $arguments;
            }

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
            }

            if ($depth === 0 && $char === ',') {
                $arguments[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        return $arguments;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
