<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The legacy screens, their Bootstrap
 * styles, their npm packages and the legacy PHP list and metrics layer
 * are deleted, and nothing in the package points at them again.
 *
 * The test reads files only. It covers the package sources (`src/`,
 * `routes/`, `config/`, `resources/`) and the Workbench; the tests, the
 * docs and `dist/` are outside its scan (the committed build is checked by
 * bin/check-assets, and dist/styles.css by the file list below).
 */
#[Group('scripts')]
final class LegacyGoneTest extends TestCase
{
    /** The directories that the marker scan reads, relative to the root. */
    private const SCANNED = ['src', 'routes', 'config', 'resources', 'workbench'];

    /** The text files that the marker scan reads. */
    private const EXTENSIONS = ['php', 'js', 'mjs', 'vue', 'css', 'scss', 'json', 'stub', 'md', 'yaml', 'yml', 'html'];

    /** The directories below SCANNED that hold runtime files (the logs, caches and sessions of `composer serve`). */
    private const SKIPPED = ['workbench/storage'];

    /**
     * pattern => what it names. The legacy route marker and the shell's
     * padded Bootstrap page, the legacy PHP list helpers, Horizon's offset
     * paging parameter, and the transitional legacy API prefix.
     */
    private const MARKERS = [
        '/\bmeta\.legacy\b/' => 'meta.legacy (the legacy route marker)',
        '/\blegacy\s*:\s*true\b/' => 'a route with legacy: true',
        '/o-shell__legacy/' => "the shell's legacy page",
        '/\bListOffset\b/' => 'Http\ListOffset',
        '/\bDerivedFields\b/' => 'Http\Compat\DerivedFields',
        '/\bListPayload\b/' => 'Http\Compat\ListPayload',
        '/\bstarting_at\b/' => 'the offset parameter starting_at',
        '#/api/legacy\b#' => 'the /api/legacy routes',
    ];

    /** The files that the UI rebuild deleted. */
    private const DELETED = [
        'dist/styles.css',
        'resources/js/components/Alert.vue',
        'resources/js/components/LineChart.vue',
        'resources/js/components/Stacktrace.vue',
        'resources/js/screens/batches/index.vue',
        'resources/js/screens/batches/preview.vue',
        'resources/js/screens/failed/OperationResult.vue',
        'resources/js/screens/recentJobs',
        'resources/js/screens/failedJobs',
        'resources/js/screens/monitoring',
        'resources/js/screens/metrics',
        'src/Http/Compat',
        'src/Http/ListOffset.php',
        'src/Contracts/JobRepository.php',
        'src/Contracts/TagRepository.php',
        'src/Contracts/MetricsRepository.php',
        'src/Database/Repositories/DatabaseJobRepository.php',
        'src/Database/Repositories/DatabaseTagRepository.php',
        'src/Database/Repositories/DatabaseMetricsRepository.php',
        'src/Failures/GroupOperation.php',
        'src/Http/Controllers/PendingJobsController.php',
        'src/Http/Controllers/CompletedJobsController.php',
        'src/Http/Controllers/SilencedJobsController.php',
        'src/Http/Controllers/FailedJobsController.php',
        'src/Http/Controllers/JobsController.php',
        'src/Http/Controllers/RetryController.php',
        'src/Http/Controllers/RetriesOccurrences.php',
        'src/Http/Controllers/MonitoringController.php',
        'src/Http/Controllers/JobMetricsController.php',
        'src/Http/Controllers/QueueMetricsController.php',
        'src/Http/Controllers/FailureSelectionController.php',
        'src/Http/Controllers/FailureOperationsController.php',
        'src/Http/Controllers/LegacyBatchesController.php',
    ];

    /** The npm packages that only the legacy screens used. */
    private const PACKAGES = ['bootstrap', 'sass', 'phpunserialize', 'vue-json-pretty', 'chart.js', 'dayjs'];

    /** The data-only batch reader: it may name Laravel's BatchRepository to compare the bound class. */
    private const BATCH_READER = 'src/Database/Repositories/DatabaseBatchRepository.php';

    /** The one file of src/ that may call unserialize(): an operator's retry (hard constraint 6). */
    private const UNSERIALIZE_ALLOWED = 'src/Retry/RetryPayload.php';

    /**
     * Rejects: a kept file that still names a legacy helper, the legacy
     * route marker or the offset parameter, for example a route left with
     * `meta: {legacy: true}`, a controller that reads `starting_at`, or a
     * screen that still calls `/api/legacy/batches`.
     * Still passes: a marker built from pieces at runtime (`'/api/' +
     * 'legacy'`), and a marker in tests/, docs/ or dist/.
     */
    public function test_no_kept_file_names_a_legacy_marker(): void
    {
        $root = self::root();
        $found = [];

        foreach (self::files($root) as $relative => $source) {
            foreach (self::MARKERS as $pattern => $what) {
                if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
                    continue;
                }

                foreach ($matches[0] as [, $offset]) {
                    $found[] = $relative.':'.(substr_count(substr($source, 0, $offset), "\n") + 1).' '.$what;
                }
            }
        }

        self::assertSame([], $found, 'A kept file still names the legacy layer (D8 spec section 6).');
    }

    /**
     * Rejects: a Sass directory or a Bootstrap stylesheet left behind, and
     * a Vite input or an Orrery::css() entry that still builds or inlines it.
     * Still passes: Bootstrap rules copied by hand into a CSS file.
     */
    public function test_the_sass_stylesheet_is_gone(): void
    {
        $root = self::root();

        self::assertDirectoryDoesNotExist($root.'/resources/sass');
        self::assertFileDoesNotExist($root.'/dist/styles.css');
        self::assertStringNotContainsString('sass', (string) file_get_contents($root.'/vite.config.js'), 'The Vite inputs still name a Sass file.');
        self::assertStringNotContainsString('styles.css', (string) file_get_contents($root.'/src/Orrery.php'), 'Orrery::css() still inlines dist/styles.css.');
    }

    /**
     * Rejects: a deleted screen, component, controller, contract or
     * repository that is still in the tree (for example one restored by a
     * merge).
     * Still passes: the same code under another file name.
     */
    public function test_the_deleted_files_stay_deleted(): void
    {
        $root = self::root();
        $present = array_values(array_filter(self::DELETED, static fn (string $path): bool => file_exists($root.'/'.$path)));

        self::assertSame([], $present, 'A file that D8-19 deleted is back.');
    }

    /**
     * Rejects: a legacy npm package left in package.json or in the root
     * entry or the package list of package-lock.json, so `npm ci` would
     * still install it.
     * Still passes: a package that another dependency pulls in under its
     * own name (none of these is, today).
     */
    public function test_the_legacy_npm_packages_are_gone(): void
    {
        $root = self::root();
        /** @var array<string, mixed> $package */
        $package = json_decode((string) file_get_contents($root.'/package.json'), true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $lock */
        $lock = json_decode((string) file_get_contents($root.'/package-lock.json'), true, 512, JSON_THROW_ON_ERROR);
        $found = [];

        foreach (['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'] as $section) {
            foreach (self::PACKAGES as $name) {
                if (is_array($package[$section] ?? null) && array_key_exists($name, $package[$section])) {
                    $found[] = "package.json {$section} {$name}";
                }

                if (is_array($lock['packages']['']['devDependencies'] ?? null) && $section === 'devDependencies' && array_key_exists($name, $lock['packages']['']['devDependencies'])) {
                    $found[] = "package-lock.json root {$name}";
                }
            }
        }

        foreach (self::PACKAGES as $name) {
            if (is_array($lock['packages'] ?? null) && array_key_exists('node_modules/'.$name, $lock['packages'])) {
                $found[] = "package-lock.json node_modules/{$name}";
            }
        }

        self::assertSame([], $found, 'A legacy npm package is still a dependency.');
    }

    /**
     * Rejects: an unserialize() call in src/ outside Retry\RetryPayload
     * (hard constraint 6: no viewer read unserializes job or batch data),
     * as a function call, a fully qualified call, a method call such as
     * Laravel's `DatabaseBatchRepository::unserialize()`, or a string
     * callable `'unserialize'`.
     * Still passes: a call through a variable function name assembled at
     * runtime.
     */
    public function test_unserialize_is_called_only_by_the_retry_payload(): void
    {
        $root = self::root();
        $found = [];
        $allowed = 0;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src')) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);

            foreach (self::unserializeCalls((string) file_get_contents($file->getPathname())) as $line) {
                if ($relative === self::UNSERIALIZE_ALLOWED) {
                    $allowed++;

                    continue;
                }

                $found[] = $relative.':'.$line;
            }
        }

        self::assertSame([], $found, 'unserialize() outside Retry\RetryPayload.');
        self::assertGreaterThan(0, $allowed, 'The scan found no call in Retry\RetryPayload: it no longer sees calls.');
    }

    /**
     * Rejects: a viewer read that unserializes job or batch data without
     * the word unserialize: a decrypt() of an encrypted job
     * (`decrypt()`, `Crypt::decrypt()`, `->decryptString()`, which
     * unserializes by default), a Laravel batch through `Bus::findBatch()`
     * or `BatchRepository::find()` (it unserializes the options), and Laravel's
     * BatchRepository or its concrete Illuminate\Bus\DatabaseBatchRepository
     * used outside the data-only reader, which may only compare its class. Retry\RetryPayload (an operator's retry) may
     * decrypt.
     * Still passes: a call through a variable method name, and the concrete
     * class imported in a group use (`use Illuminate\Bus\{DatabaseBatchRepository}`).
     */
    public function test_no_viewer_read_decrypts_or_builds_a_laravel_batch(): void
    {
        $root = self::root();
        $found = [];
        $allowed = 0;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src')) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);

            foreach (self::decodingCalls((string) file_get_contents($file->getPathname()), $relative === self::BATCH_READER) as [$line, $what]) {
                if ($relative === self::UNSERIALIZE_ALLOWED) {
                    // An operator's retry rebuilds the job, as Laravel's queue:retry does.
                    $allowed++;

                    continue;
                }

                $found[] = "{$relative}:{$line} {$what}";
            }
        }

        self::assertSame([], $found, 'A read that decrypts or builds a Laravel batch (hard constraint 6).');
        self::assertGreaterThan(0, $allowed, 'The scan found no decrypt() in Retry\\RetryPayload: it no longer sees calls.');
    }

    /**
     * Rejects: a scanner that misses one of the forms.
     */
    public function test_the_decoding_scanner_finds_every_form(): void
    {
        $source = <<<'PHP'
            <?php
            use Illuminate\Bus\BatchRepository;
            $a = decrypt($x);
            $b = Crypt::decrypt($x);
            $c = $encrypter->decryptString($x);
            $d = Bus::findBatch($id);
            $e = $this->bound->find($id);
            $f = app(\Illuminate\Bus\BatchRepository::class);
            function decrypt_like(): void {}
            $g = new \Illuminate\Bus\DatabaseBatchRepository($factory, $db, 'job_batches');
            use Illuminate\Bus\DatabaseBatchRepository as Concrete;
            $h = new DatabaseBatchRepository($x);
            PHP;

        $what = array_map(static fn (array $call): string => $call[1], self::decodingCalls($source, false));
        self::assertSame(['BatchRepository', 'decrypt', 'decrypt', 'decryptString', 'findBatch', '$this->bound->find', 'BatchRepository', 'DatabaseBatchRepository', 'DatabaseBatchRepository'], $what);
        $reader = array_map(static fn (array $call): string => $call[1], self::decodingCalls($source, true));
        self::assertSame(['decrypt', 'decrypt', 'decryptString', 'findBatch', '$this->bound->find'], $reader, 'the batch reader may name the class, never call the bound repository');
    }

    /**
     * The decoding calls of a PHP source: [line, what]. In the batch reader
     * ($reader) the class name is allowed, a call on `$this->bound` never.
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function decodingCalls(string $source, bool $reader): array
    {
        $tokens = array_values(array_filter(token_get_all($source), static fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $calls = [];

        foreach ($tokens as $i => $token) {
            if (! is_array($token)) {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;
            $name = strtolower(ltrim($token[1], '\\'));

            if ($token[0] === T_VARIABLE && $token[1] === '$this' && is_array($next) && $next[0] === T_OBJECT_OPERATOR
                && is_array($tokens[$i + 2] ?? null) && $tokens[$i + 2][1] === 'bound'
                && is_array($tokens[$i + 3] ?? null) && $tokens[$i + 3][0] === T_OBJECT_OPERATOR && is_array($tokens[$i + 4] ?? null)) {
                $calls[] = [$token[2], '$this->bound->'.$tokens[$i + 4][1]];

                continue;
            }

            if (! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            if ($name === 'illuminate\\bus\\batchrepository' || ($name === 'batchrepository' && $token[0] === T_STRING)) {
                if (! $reader) {
                    $calls[] = [$token[2], 'BatchRepository'];
                }

                continue;
            }

            // Laravel's concrete repository by its full name (its find() unserializes the options); the
            // package's own DatabaseBatchRepository has the same short name, so only the full name counts.
            if ($name === 'illuminate\\bus\\databasebatchrepository') {
                if (! $reader) {
                    $calls[] = [$token[2], 'DatabaseBatchRepository'];
                }

                continue;
            }

            if (is_array($previous) && $previous[0] === T_FUNCTION) {
                continue;
            }

            if (in_array($name, ['decrypt', 'decryptstring', 'findbatch'], true) && $next === '(') {
                $calls[] = [$token[2], $token[1]];
            }
        }

        return $calls;
    }

    /**
     * The lines of the unserialize calls in a PHP source: a name token
     * `unserialize` (bare, fully qualified, or after `->`/`::`) followed by
     * `(`; the string `'unserialize'` (also with leading backslashes, a
     * callable); and a `use function … unserialize` import (with or without
     * `as`), whose alias would then call it under another name. A method
     * declaration named unserialize (a Serializable class) is no call.
     * Comments do not count.
     *
     * @return list<int>
     */
    private static function unserializeCalls(string $source): array
    {
        $significant = array_values(array_filter(
            token_get_all($source),
            static fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $lines = [];
        $importingFunctions = false;

        foreach ($significant as $index => $token) {
            if (! is_array($token)) {
                if ($token === ';') {
                    $importingFunctions = false;
                }

                continue;
            }

            [$kind, $text, $line] = $token;
            $previous = $significant[$index - 1] ?? null;

            if ($kind === T_FUNCTION && is_array($previous) && $previous[0] === T_USE) {
                $importingFunctions = true;

                continue;
            }

            if ($kind === T_CONSTANT_ENCAPSED_STRING && strtolower(ltrim(trim($text, '\'"'), '\\')) === 'unserialize') {
                $lines[] = $line;

                continue;
            }

            if (! in_array($kind, [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                continue;
            }

            $name = strtolower(ltrim($text, '\\'));

            if ($importingFunctions) {
                // `use function unserialize;`, `use function \unserialize as decode;`.
                if ($name === 'unserialize' || str_ends_with($name, '\\unserialize')) {
                    $lines[] = $line;
                }

                continue;
            }

            if ($name !== 'unserialize' || (is_array($previous) && $previous[0] === T_FUNCTION)) {
                // Another name, or the declaration of a method named unserialize.
                continue;
            }

            if (($significant[$index + 1] ?? null) === '(') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The text files of SCANNED, relative path => contents.
     *
     * @return array<string, string>
     */
    private static function files(string $root): array
    {
        $files = [];

        foreach (self::SCANNED as $directory) {
            if (! is_dir($root.'/'.$directory)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen($root) + 1);

                if (! in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                    continue;
                }

                foreach (self::SKIPPED as $skipped) {
                    if (str_starts_with($relative, $skipped.'/')) {
                        continue 2;
                    }
                }

                $files[$relative] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
