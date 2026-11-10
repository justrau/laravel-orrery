<?php

/*
 * The test impact analysis of bin/tia. Plain PHP without the Composer
 * autoloader, as bin/lib/matrix.php.
 *
 * A recording (bin/tia record) runs PHPUnit with XDEBUG_MODE=coverage and
 * the extension Tests\Support\Tia\Extension. The extension records, per test
 * class, the files of the repository that the class ran: Xdebug's covered
 * files, the files that PHP included while the class ran, and the files of
 * the worker processes that the class started (WorkerProcess passes the
 * recording on, and tests/Support/bin/artisan records the child). The data
 * provider calls count for their class. MapBuilder turns that into a map:
 * every file of the tree with its hashes and its analysis, and the classes
 * that ran it. The map lives in .tia/map-<engine>.json (gitignored).
 *
 * A selection (bin/tia [engine]) hashes the tree now, compares it with the
 * map of the engine, and selects (Selector):
 * - for a changed PHP file: the classes that ran it when only function
 *   bodies changed; when the surface changed (anything outside the function
 *   bodies: constants, properties, signatures, the class hierarchy), also
 *   the classes that ran a file that names one of its classes, and so on
 *   through the files that name it in their own surface by extends,
 *   implements, a trait, an attribute, `new` or a constant expression. A
 *   comment-only change selects nothing, except a test file itself;
 * - for every change: the classes that ran a file that names the changed
 *   path in a string literal (a file read, a glob, a directory scan);
 * - a changed or new test file itself, and the failures of the recording;
 * - the group scripts for bin/, docker/, tests/Scripts/ and the CI and
 *   tooling files, the group workbench for workbench/;
 * - the classes whose worker coverage was lost, on any package change.
 * It falls back to the full scope with the reason when the map cannot prove
 * the selection: a changed or new PHP file that no recorded class ran, a
 * deleted file that a class ran, composer.json, phpunit.xml(.dist),
 * tests/bootstrap.php, testbench.yaml, the recorder itself, an unclassified
 * file, a group that no recording holds, a missing map for the engine, another
 * PHP minor version, another vendor tree or composer.lock, or a map more than
 * --max-commits commits behind HEAD.
 *
 * The other engines' maps merge in: a file that only a server recording ran
 * maps to the classes of that recording.
 */

declare(strict_types=1);

namespace OrreryTia;

use JsonException;
use PhpToken;
use RuntimeException;

/**
 * The analysis of one PHP file.
 */
final class PhpFile
{
    /** Top-level directories whose bare name in a string literal means the directory. */
    public const DIRECTORY_WORDS = ['src', 'tests', 'docs', 'dist', 'stubs', 'routes', 'config', 'resources', 'workbench', 'bin', 'docker', 'database'];

    /**
     * hash: the tokens without comments and whitespace (with their lines when
     * the file reads __LINE__). surface: the same without function bodies.
     * declares: the short names (lower case) of the classes, interfaces,
     * traits, enums, functions and constants that the file declares.
     * classes: the fully qualified names of the declared classes.
     * refs: every name that the file mentions, also in string literals.
     * surface_refs: the names that carry a surface change on (see the file
     * comment). literals: the repository paths that its string literals name.
     *
     * groups: the names of its #[Group] attributes.
     *
     * @return array{hash: string, surface: string, declares: list<string>, classes: list<string>, groups: list<string>, refs: list<string>, surface_refs: list<string>, literals: list<string>}
     */
    public static function analyze(string $code, string $path = ''): array
    {
        $tokens = [];

        foreach (PhpToken::tokenize($code) as $token) {
            if (! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                $tokens[] = $token;
            }
        }

        $count = count($tokens);
        $usesLines = false;

        foreach ($tokens as $token) {
            if ($token->id === T_LINE) {
                $usesLines = true;
                break;
            }
        }

        $body = self::bodies($tokens);
        $full = [];
        $surface = [];

        foreach ($tokens as $i => $token) {
            $text = self::text($token).($usesLines ? '@'.$token->line : '');
            $full[] = $text;

            if ($body[$i] === 0) {
                $surface[] = $text;
            } elseif ($body[$i] === 1) {
                $surface[] = '{}';
            }
        }

        $declares = [];
        $classes = [];
        $groups = [];
        $refs = [];
        $surfaceRefs = [];
        $literals = [];
        $namespace = '';
        $imports = [];
        $depth = 0;
        $classDepths = [];
        $inHeritage = false;
        $inTraitUse = false;
        $attributeDepth = 0;
        $qualify = static fn (string $name): string => strtolower(ltrim($namespace.'\\'.$name, '\\'));

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $previous = $i > 0 ? $tokens[$i - 1] : null;
            $next = $tokens[$i + 1] ?? null;
            $inBody = $body[$i] !== 0;

            if ($token->id === T_NAMESPACE && $next !== null && $next->is([T_STRING, T_NAME_QUALIFIED])) {
                $namespace = $next->text;
                $qualify = static fn (string $name): string => strtolower(ltrim($namespace.'\\'.$name, '\\'));
                $i++;

                continue;
            }

            // An import: use A\B [as C], use A\{B, C as D}, use function, use const.
            if ($token->id === T_USE && $depth === 0 && $next !== null && $next->text !== '(') {
                $i = self::imports($tokens, $i + 1, $imports);

                foreach ($imports as $fq) {
                    $refs[] = $fq;
                }

                continue;
            }

            if ($token->text === '{' || $token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;

                if ($classDepths !== [] && end($classDepths) === $depth) {
                    array_pop($classDepths);
                }
            }

            if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && $next !== null && $next->id === T_STRING
                && ($previous === null || ! $previous->is([T_DOUBLE_COLON, T_NEW]))) {
                if (! $inBody) {
                    $declares[] = $qualify($next->text);

                    if ($token->id === T_CLASS) {
                        $classes[] = ltrim($namespace.'\\'.$next->text, '\\');
                    }
                }

                $classDepths[] = $depth;
                $inHeritage = ! $inBody;
            }

            if (! $inBody && $depth === 0 && $token->is([T_FUNCTION, T_CONST])) {
                $name = $next !== null && $next->text === '&' ? ($tokens[$i + 2] ?? null) : $next;

                if ($name !== null && $name->id === T_STRING) {
                    $declares[] = $qualify($name->text);
                }
            }

            if ($token->text === '{') {
                $inHeritage = false;
            }

            if (! $inBody && $token->id === T_USE && $classDepths !== [] && $depth === end($classDepths) + 1) {
                $inTraitUse = true;
            } elseif ($token->text === ';' || $token->text === '{') {
                $inTraitUse = false;
            }

            if ($token->id === T_ATTRIBUTE) {
                $attributeDepth = 1;
            } elseif ($attributeDepth > 0 && $token->text === '[') {
                $attributeDepth++;
            } elseif ($attributeDepth > 0 && $token->text === ']') {
                $attributeDepth--;
            }

            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
                $lower = strtolower($token->text);

                if (in_array($lower, ['self', 'static', 'parent', 'true', 'false', 'null'], true)) {
                    continue;
                }

                $names = self::resolve($token, $namespace, $imports);
                array_push($refs, ...$names);

                // #[Group('name')]: the PHPUnit groups of a test file.
                $after = $tokens[$i + 2] ?? null;

                if ($attributeDepth > 0 && strcasecmp(substr((string) strrchr('\\'.$token->text, '\\'), 1), 'Group') === 0
                    && $next !== null && $next->text === '(' && $after !== null && $after->id === T_CONSTANT_ENCAPSED_STRING) {
                    $groups[] = self::unquote($after->text);
                }

                $declared = $previous !== null && $previous->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION, T_CONST]);
                $propagates = ! $declared && (
                    $inHeritage
                    || $inTraitUse
                    || ($attributeDepth > 0 && $token->id !== T_STRING)
                    || ($attributeDepth > 0 && $previous !== null && $previous->is([T_ATTRIBUTE, T_NS_SEPARATOR, ',']))
                    || ($attributeDepth > 0 && $next !== null && $next->is([T_DOUBLE_COLON, '(']))
                    || ($next !== null && $next->id === T_DOUBLE_COLON)
                    || ($previous !== null && $previous->id === T_NEW)
                );

                // These positions name classes, which never fall back to the global name.
                if (! $inBody && $propagates) {
                    $surfaceRefs[] = $names[0];
                }
            }

            if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
                $value = $token->id === T_CONSTANT_ENCAPSED_STRING ? self::unquote($token->text) : $token->text;

                // A class name in a string: 'Vendor\\Package\\Name' as written, and a
                // single word as a global name.
                if (preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $value) === 1) {
                    $refs[] = strtolower(ltrim($value, '\\'));
                }

                // A bare directory word is a path only outside array keys
                // ('tests' => …) and index lookups ($app['config']).
                $before = $i > 1 ? $tokens[$i - 2] : null;
                $key = ($next !== null && $next->id === T_DOUBLE_ARROW)
                    || ($previous !== null && $previous->text === '[' && $before !== null && ($before->is([T_VARIABLE, T_STRING]) || in_array($before->text, [']', ')', '}'], true)));

                foreach (Literals::targets($value, self::directoryBefore($tokens, $i, $path), ! $key) as $target) {
                    $literals[] = $target;
                }
            }
        }

        return [
            'hash' => sha1(implode("\n", $full)),
            'surface' => sha1(implode("\n", $surface)),
            'declares' => self::set($declares),
            'classes' => array_values(array_unique($classes)),
            'groups' => self::set($groups),
            'refs' => self::set($refs),
            'surface_refs' => self::set($surfaceRefs),
            'literals' => self::set($literals),
        ];
    }

    /**
     * Per token: 0 outside a function body, 1 for the opening brace of a
     * body, 2 inside a body (the closing brace included).
     *
     * @param  list<PhpToken>  $tokens
     * @return list<int>
     */
    private static function bodies(array $tokens): array
    {
        $count = count($tokens);
        $body = array_fill(0, $count, 0);

        for ($i = 0; $i < $count; $i++) {
            if ($body[$i] !== 0 || $tokens[$i]->id !== T_FUNCTION) {
                continue;
            }

            $depth = 0;
            $j = $i + 1;

            for (; $j < $count; $j++) {
                $text = $tokens[$j]->text;

                if ($text === '(' || $text === '[' || $tokens[$j]->id === T_ATTRIBUTE) {
                    $depth++;
                } elseif ($text === ')' || $text === ']') {
                    $depth--;
                } elseif ($depth === 0 && ($text === '{' || $text === ';')) {
                    break;
                }
            }

            if ($j >= $count || $tokens[$j]->text !== '{') {
                continue;
            }

            $body[$j] = 1;
            $braces = 1;

            for ($k = $j + 1; $k < $count && $braces > 0; $k++) {
                $text = $tokens[$k]->text;

                if ($text === '{' || $tokens[$k]->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $braces++;
                } elseif ($text === '}') {
                    $braces--;
                }

                $body[$k] = 2;
            }

            $i = $k - 1;
        }

        return $body;
    }

    /**
     * The directory (relative to the root) that the string at $i is appended
     * to: `__DIR__.'…'` is the file's directory, `dirname(__DIR__[, n]).'…'`
     * its parent n levels up. Null for any other base (a variable, a root):
     * then the literal counts only relative to the root.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function directoryBefore(array $tokens, int $i, string $path): ?string
    {
        if ($path === '' || ($tokens[$i - 1] ?? null)?->text !== '.') {
            return null;
        }

        $directory = dirname($path);
        $base = $tokens[$i - 2] ?? null;

        if ($base !== null && $base->id === T_DIR) {
            return $directory;
        }

        // dirname(__DIR__) or dirname(__DIR__, n)
        $levels = null;

        if ($base !== null && $base->text === ')') {
            $j = $i - 3;
            $levels = 1;

            if (($tokens[$j] ?? null)?->id === T_LNUMBER && ($tokens[$j - 1] ?? null)?->text === ',') {
                $levels = (int) $tokens[$j]->text;
                $j -= 2;
            }

            if (($tokens[$j] ?? null)?->id !== T_DIR || ($tokens[$j - 1] ?? null)?->text !== '(' || strtolower((string) ($tokens[$j - 2] ?? null)?->text) !== 'dirname') {
                return null;
            }
        }

        if ($levels === null) {
            return null;
        }

        for ($k = 0; $k < $levels; $k++) {
            $directory = dirname($directory);
        }

        return $directory === '.' ? '' : $directory;
    }

    /**
     * Reads one use statement from $i on, adds its aliases (lower case) to
     * $imports, and returns the index of its semicolon.
     *
     * @param  list<PhpToken>  $tokens
     * @param  array<string, string>  $imports
     */
    private static function imports(array $tokens, int $i, array &$imports): int
    {
        $count = count($tokens);
        $group = '';
        $current = '';
        $alias = null;
        $expectAlias = false;

        $flush = static function () use (&$current, &$alias, &$group, &$imports): void {
            if ($current !== '') {
                $fq = strtolower(ltrim($group.$current, '\\'));
                $short = $alias ?? (($p = strrpos($fq, '\\')) === false ? $fq : substr($fq, $p + 1));
                $imports[strtolower($short)] = $fq;
            }

            $current = '';
            $alias = null;
        };

        for (; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->text === ';') {
                $flush();

                return $i;
            }

            if ($token->is([T_FUNCTION, T_CONST])) {
                continue;
            }

            if ($token->id === T_AS) {
                $expectAlias = true;
            } elseif ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                if ($expectAlias) {
                    $alias = $token->text;
                    $expectAlias = false;
                } else {
                    $current .= $token->text;
                }
            } elseif ($token->id === T_NS_SEPARATOR) {
                $current .= '\\';
            } elseif ($token->text === '{') {
                $group = rtrim($current, '\\').'\\';
                $current = '';
            } elseif ($token->text === ',' || $token->text === '}') {
                $flush();
            }
        }

        $flush();

        return $i;
    }

    /**
     * The fully qualified names (lower case) that a name token can mean: by
     * the imports, else in the current namespace, and for an unqualified
     * name also the global name (functions and constants fall back to it).
     *
     * @param  array<string, string>  $imports
     * @return list<string>
     */
    private static function resolve(PhpToken $token, string $namespace, array $imports): array
    {
        $text = $token->text;
        $inNamespace = static fn (string $name): string => strtolower(ltrim($namespace.'\\'.$name, '\\'));

        return match ($token->id) {
            T_NAME_FULLY_QUALIFIED => [strtolower(ltrim($text, '\\'))],
            T_NAME_RELATIVE => [$inNamespace(substr($text, strlen('namespace\\')))],
            T_NAME_QUALIFIED => (static function () use ($text, $imports, $inNamespace): array {
                $first = strtolower(strstr($text, '\\', true) ?: $text);

                return isset($imports[$first])
                    ? [$imports[$first].strtolower(substr($text, strlen($first)))]
                    : [$inNamespace($text)];
            })(),
            default => isset($imports[strtolower($text)])
                ? [$imports[strtolower($text)]]
                : array_values(array_unique([$inNamespace($text), strtolower($text)])),
        };
    }

    private static function text(PhpToken $token): string
    {
        return match ($token->id) {
            T_OPEN_TAG => '<?php',
            T_CLOSE_TAG => '?>',
            default => $token->text,
        };
    }

    private static function unquote(string $literal): string
    {
        $quote = $literal[0] ?? '';

        if (($quote === "'" || $quote === '"') && strlen($literal) >= 2) {
            $inner = substr($literal, 1, -1);

            return $quote === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner) : stripcslashes($inner);
        }

        return $literal;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function set(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}

/**
 * Repository paths named by string literals. A target is a path relative to
 * the root; a target that ends in "/" or "*" is a prefix.
 */
final class Literals
{
    /**
     * @return list<string>
     */
    public static function targets(string $literal, ?string $directory, bool $bareWords = true): array
    {
        // A path has no spaces, quotes, backslashes or regular expression
        // syntax: messages and patterns are not paths.
        if (strlen($literal) > 300 || preg_match('#^[A-Za-z0-9_.\-/*?{}\[\],@+]+$#', $literal) !== 1) {
            return [];
        }

        $cut = strcspn($literal, '*?[{');
        $glob = $cut < strlen($literal);
        $literal = substr($literal, 0, $cut);

        if (! str_contains($literal, '/')) {
            return $bareWords && ! $glob && in_array($literal, PhpFile::DIRECTORY_WORDS, true) ? [$literal.'/'] : [];
        }

        // A separator alone ('/', './') names no path.
        if (preg_match('/[^\/.]/', $literal) !== 1 && ! str_contains($literal, '..')) {
            return [];
        }

        $targets = [];
        $suffix = $glob ? '*' : (str_ends_with($literal, '/') ? '/' : '');

        // Relative to the root: dirname(__DIR__, n).'/docs/x.md'.
        $rootRelative = preg_replace('#^(?:\.{1,2}/|/)+#', '', $literal);

        if (is_string($rootRelative) && trim($rootRelative, '/') !== '') {
            $targets[] = self::finish($rootRelative, $suffix);
        }

        // Relative to the directory of __DIR__ or dirname(__DIR__, n): __DIR__.'/../stubs/x.stub'.
        if ($directory !== null) {
            $resolved = self::normalize($directory.'/'.$literal);

            // The root itself ('/../..' from tests/Support) is a working
            // directory, not a read of the whole tree.
            if ($resolved !== null && $resolved !== '') {
                $targets[] = self::finish($resolved, $suffix);
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * @param  list<string>  $targets
     */
    public static function matches(array $targets, string $path): bool
    {
        foreach ($targets as $target) {
            if (str_ends_with($target, '*')) {
                if (str_starts_with($path, substr($target, 0, -1))) {
                    return true;
                }

                continue;
            }

            $base = rtrim($target, '/');

            if ($path === $base || str_starts_with($path, $base.'/')) {
                return true;
            }
        }

        return false;
    }

    private static function finish(string $path, string $suffix): string
    {
        $path = rtrim($path, '/');

        return $suffix === '*' ? $path.'*' : ($suffix === '/' ? $path.'/' : $path);
    }

    private static function normalize(string $path): ?string
    {
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }

                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }
}

/**
 * The files of a checkout and their hashes.
 */
final class Tree
{
    public static function isPhp(string $path): bool
    {
        return str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php');
    }

    /**
     * The files that git lists (tracked, and untracked but not ignored), as
     * bin/matrix takes its snapshot. Directories of nested checkouts and
     * .claude/ are left out.
     *
     * @return list<string>
     */
    public static function scanGit(string $root): array
    {
        $output = Shell::run(['git', '-C', $root, 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], $status);

        if ($status !== 0) {
            throw new RuntimeException("git ls-files failed in {$root}.");
        }

        $paths = [];

        foreach (explode("\0", $output) as $path) {
            if ($path === '' || str_ends_with($path, '/') || str_starts_with($path, '.claude/') || str_starts_with($path, '.tia/')) {
                continue;
            }

            if (is_file($root.'/'.$path)) {
                $paths[$path] = true;
            }
        }

        $paths = array_keys($paths);
        sort($paths);

        return $paths;
    }

    /**
     * Every file under $root, without dot directories (for fixtures).
     *
     * @return list<string>
     */
    public static function scanDirectory(string $root): array
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $file): bool => ! str_starts_with($file->getFilename(), '.'),
        ));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $paths[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * The raw hash of each file, and the analysis of each PHP file. With a
     * map, an unchanged file takes its analysis from the map.
     *
     * @param  list<string>  $paths
     * @param  array<string, mixed>|null  $map
     * @return array<string, array{raw: string, php: array<string, mixed>|null}>
     */
    public static function hashes(string $root, array $paths, ?array $map = null): array
    {
        $tree = [];

        foreach ($paths as $path) {
            $content = file_get_contents($root.'/'.$path);

            if ($content === false) {
                continue;
            }

            $raw = sha1($content);
            $php = null;

            if (self::isPhp($path)) {
                $known = $map['files'][$path] ?? null;
                $php = is_array($known) && ($known['raw'] ?? null) === $raw && is_array($known['php'] ?? null)
                    ? $known['php']
                    : PhpFile::analyze($content, $path);
            }

            $tree[$path] = ['raw' => $raw, 'php' => $php];
        }

        return $tree;
    }
}

/**
 * Builds a map from the files that the recorders wrote.
 */
final class MapBuilder
{
    public const VERSION = 3;

    /**
     * @param  list<string>  $recordDirectories
     * @param  array<string, array{raw: string, php: array<string, mixed>|null}>  $tree
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function build(array $recordDirectories, string $root, array $tree, array $meta): array
    {
        $contexts = [];
        $classes = [];
        $children = [];
        $outside = [];

        foreach ($recordDirectories as $directory) {
            $parents = glob($directory.'/parent-*.json') ?: [];

            if ($parents === []) {
                throw new RuntimeException("The recording in {$directory} has no parent file: PHPUnit did not finish its run.");
            }

            foreach ($parents as $file) {
                $data = self::json($file);

                foreach ((array) $data['contexts'] as $class => $paths) {
                    foreach ((array) $paths as $path) {
                        $contexts[$class][$path] = true;
                    }
                }

                foreach ((array) $data['outside'] as $path) {
                    $outside[$path] = true;
                }

                foreach ((array) $data['classes'] as $class => $info) {
                    $known = $classes[$class] ?? ['file' => null, 'groups' => [], 'tests' => 0, 'failed' => false, 'incomplete' => false];
                    $classes[$class] = [
                        'file' => $info['file'] ?? $known['file'],
                        'groups' => array_values(array_unique([...$known['groups'], ...(array) ($info['groups'] ?? [])])),
                        'tests' => max($known['tests'], (int) ($info['tests'] ?? 0)),
                        'failed' => $known['failed'] || (bool) ($info['failed'] ?? false),
                        'incomplete' => $known['incomplete'],
                    ];
                }

                foreach ((array) $data['children'] as $key => $class) {
                    $children[$directory.'/children/'.$key] = (string) $class;
                }
            }
        }

        // The child processes. A WorkerProcess launch ("<pid>-<seq>") must
        // leave a trace of its direct child (a .start or a .json file): with
        // none, the child ran without recording, and the class is incomplete.
        // A child that started (a .start file) but wrote no file was killed:
        // only a script that includes no file shows what it ran; else the
        // class is incomplete. (Complete runs of the same script do not show
        // it: a worker that its --timeout kills runs other code.)
        foreach ($children as $prefix => $class) {
            $direct = ! preg_match('~/children/\d+-\d+$~', $prefix) || glob($prefix.'-*.start') !== [];
            $complete = true;

            foreach (glob($prefix.'-*.start') ?: [] as $start) {
                if (is_file(substr($start, 0, -strlen('.start')).'.json')) {
                    continue;
                }

                $script = trim((string) file_get_contents($start));
                $files = self::selfContained($script, $root);

                if ($files === null) {
                    $complete = false;

                    continue;
                }

                foreach ($files as $path) {
                    if ($class === '') {
                        $outside[$path] = true;
                    } else {
                        $contexts[$class][$path] = true;
                    }
                }
            }

            foreach (glob($prefix.'-*.json') ?: [] as $file) {
                $data = self::json($file);
                $direct = $direct || (bool) ($data['direct'] ?? false);

                foreach ((array) $data['files'] as $path) {
                    if ($class === '') {
                        $outside[$path] = true;
                    } else {
                        $contexts[$class][$path] = true;
                    }
                }
            }

            if ($class !== '' && isset($classes[$class]) && (! $direct || ! $complete)) {
                $classes[$class]['incomplete'] = true;
            }
        }

        ksort($classes);
        $classList = array_keys($classes);
        $index = array_flip($classList);
        $files = [];

        foreach ($tree as $path => $entry) {
            $files[$path] = ['raw' => $entry['raw'], 'php' => $entry['php'], 'tests' => []];
        }

        foreach ($contexts as $class => $paths) {
            if (! isset($index[$class])) {
                continue;
            }

            foreach (array_keys($paths) as $path) {
                if (isset($files[$path])) {
                    $files[$path]['tests'][] = $index[$class];
                }
            }
        }

        foreach ($files as &$file) {
            sort($file['tests']);
        }

        unset($file);

        $outsideOnly = array_values(array_filter(array_keys($outside), static fn (string $path): bool => isset($files[$path]) && $files[$path]['tests'] === []));
        sort($outsideOnly);

        return [
            'version' => self::VERSION,
            ...$meta,
            'root' => $root,
            'class_list' => $classList,
            'classes' => $classes,
            'files' => $files,
            'outside_only' => $outsideOnly,
        ];
    }

    /**
     * The files of a killed child whose main script is a file of the tree
     * that includes no other file (no include or require: without one, no
     * autoloader exists either), so the script is all that it ran. Null
     * when that does not hold.
     *
     * @return list<string>|null
     */
    private static function selfContained(string $script, string $root): ?array
    {
        $root = realpath($root) ?: $root;

        if ($script === '' || ! str_starts_with($script, $root.'/') || ! is_file($script)) {
            return null;
        }

        foreach (PhpToken::tokenize((string) file_get_contents($script)) as $token) {
            if ($token->is([T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL])) {
                return null;
            }
        }

        return [substr($script, strlen($root) + 1)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function json(string $file): array
    {
        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("{$file} is not valid JSON: {$exception->getMessage()}");
        }

        if (! is_array($data)) {
            throw new RuntimeException("{$file} does not hold a JSON object.");
        }

        return $data;
    }
}

/**
 * The result of a selection.
 */
final class Selection
{
    /** The first reason to run the full scope, or null. */
    public ?string $fallback = null;

    /** @var array<string, string> class => the first reason it was selected */
    public array $classes = [];

    /** @var list<string> */
    public array $notices = [];

    /** @var list<string> one line per change */
    public array $changes = [];

    /** @var array<string, array<string, mixed>> the class metadata of every map */
    public array $known = [];

    /** @var array<string, true> classes selected through a rule of their own (see Selector::GATED) */
    public array $direct = [];

    /** @var array<string, string> classes of the gated groups that only their recorded files selected: left to the chunk-end gate */
    public array $deferred = [];

    /** @var array<string, list<string>> per selected class: its recorded groups and the #[Group] names of its file now */
    public array $groups = [];

    /** @var array<string, true> selected classes that no map holds */
    public array $unknown = [];

    /**
     * Selects $class. $direct marks a rule that may select a class of the
     * gated groups (its own test file, its group rule, its own helper, its
     * recorded failure); the other reasons may not.
     */
    public function add(string $class, string $reason, bool $direct = false): void
    {
        if ($direct && ! isset($this->direct[$class])) {
            $this->direct[$class] = true;
            $this->classes[$class] = $reason;

            return;
        }

        $this->classes[$class] ??= $reason;
    }

    public function fallBack(string $reason): void
    {
        $this->fallback ??= $reason;
    }

    public function notice(string $notice): void
    {
        if (! in_array($notice, $this->notices, true)) {
            $this->notices[] = $notice;
        }
    }

    /**
     * @return list<string>
     */
    public function classNames(): array
    {
        $names = array_keys($this->classes);
        sort($names);

        return $names;
    }
}

/**
 * The selection: a pure function of the map of the engine, the maps of the
 * other engines and the current tree.
 */
final class Selector
{
    /** These files change what every test runs: the full scope. */
    public const FULL = ['composer.json', 'phpunit.xml', 'phpunit.xml.dist', 'tests/bootstrap.php', 'testbench.yaml'];

    /** Path prefixes and files that select the group scripts. */
    public const SCRIPTS = ['bin/', 'docker/', 'tools/', 'tests/Scripts/', 'tests/Benchmark/', '.github/', '.gitignore', '.npmrc', '.nvmrc', 'phpstan.neon.dist', 'package.json', 'pnpm-lock.yaml', 'pnpm-workspace.yaml', 'vite.config.js', 'tests/known-failures.md', 'tests/unconditional.txt'];

    /**
     * The groups whose classes start PHPUnit or Workbench children that load
     * nearly every file, so their recorded files say little. Only their own
     * test files, their own helpers, their group rules and their recorded
     * failures select them; the chunk-end gate runs them whole.
     */
    public const GATED = ['scripts', 'workbench'];

    /** Path prefixes that select the group workbench. */
    public const WORKBENCH = ['workbench/'];

    /** The files whose change needs the docker group (`composer test:docker`; bin/tia prints a notice for them). */
    public const DOCKER = ['bin/test', 'bin/test-db', 'bin/lib/testdb.php', 'docker/', 'tests/Support/WorkerProcess.php', 'tests/Support/UsesAltServer.php'];

    /** Data files that no rule runs: only the classes whose files name them. */
    public const DATA = ['docs/', 'resources/', 'dist/', 'stubs/', 'tests/js/', 'LICENSE'];

    /** The selector itself: its path literals are rules, not reads. */
    public const TOOL = ['bin/lib/tia.php'];

    /** Directories whose files are package code or package data. */
    public const PACKAGE = ['config/', 'database/', 'dist/', 'resources/', 'routes/', 'src/', 'stubs/'];

    /**
     * @param  array<string, mixed>  $map  the map of the engine to run
     * @param  list<array<string, mixed>>  $others  the maps of the other engines
     * @param  array<string, array{raw: string, php: array<string, mixed>|null}>  $current
     */
    public static function select(array $map, array $others, array $current): Selection
    {
        $selection = new Selection;
        $baseline = $map['files'];

        /** @var array<string, list<string>> $coverage path => classes, over every map */
        $coverage = [];
        $classes = [];

        foreach ([$map, ...$others] as $one) {
            foreach ($one['classes'] as $class => $info) {
                $classes[$class] = isset($classes[$class])
                    ? [...$classes[$class], 'groups' => array_values(array_unique([...$classes[$class]['groups'], ...$info['groups']])), 'failed' => $classes[$class]['failed'] || $info['failed'], 'incomplete' => $classes[$class]['incomplete'] || $info['incomplete']]
                    : $info;
            }

            foreach ($one['files'] as $path => $file) {
                foreach ($file['tests'] as $i) {
                    $coverage[$path][] = $one['class_list'][$i];
                }
            }
        }

        $selection->known = $classes;

        // The class or classes of each test file.
        $testFiles = [];

        foreach ($classes as $class => $info) {
            if (is_string($info['file'] ?? null)) {
                $testFiles[$info['file']][] = $class;
            }
        }

        // The analysis of the tree after the change; a deleted file keeps its
        // old analysis for the references of the propagation.
        $effective = [];

        foreach ($baseline as $path => $file) {
            $effective[$path] = $current[$path]['php'] ?? $file['php'];
        }

        foreach ($current as $path => $file) {
            $effective[$path] = $file['php'];
        }

        $changes = [];

        foreach ($current as $path => $file) {
            if (! isset($baseline[$path])) {
                $changes[$path] = 'new';
            } elseif ($baseline[$path]['raw'] !== $file['raw']) {
                $changes[$path] = 'changed';
            }
        }

        foreach ($baseline as $path => $file) {
            if (! isset($current[$path])) {
                $changes[$path] = 'deleted';
            }
        }

        ksort($changes);
        $wantedGroups = [];
        $packageChange = false;

        foreach ($changes as $path => $kind) {
            $before = count($selection->classes);
            $note = $kind;
            $isPhp = Tree::isPhp($path);

            if ($isPhp || self::under($path, self::PACKAGE)) {
                $packageChange = true;
            }

            if (in_array($path, self::FULL, true) || str_starts_with($path, 'tests/Support/Tia/')) {
                $selection->fallBack("{$path} changed: it changes what every test runs");
                $selection->changes[] = "{$path}: {$kind}, full scope";

                continue;
            }

            $grouped = false;
            $isTestFile = isset($testFiles[$path]) || self::looksLikeTest($path);

            if (self::under($path, self::SCRIPTS) && ! $isTestFile) {
                $wantedGroups['scripts'][] = $path;
                $grouped = true;
            }

            if (self::under($path, self::WORKBENCH)) {
                $wantedGroups['workbench'][] = $path;
                $grouped = true;
            }

            $groupsNow = [...($current[$path]['php']['groups'] ?? []), ...($baseline[$path]['php']['groups'] ?? [])];

            if (self::under($path, self::DOCKER) || preg_match('~^tests/Scripts/[^/]*DockerTest\.php$~', $path) === 1 || in_array('docker', $groupsNow, true) || self::namedByDockerTest($path, $current[$path]['php'] ?? $baseline[$path]['php'] ?? null, $effective)) {
                $selection->notice("{$path} changed: also run composer test:docker (bin/tia never runs the group docker, which stops the shared containers).");
            }

            if (str_starts_with($path, 'resources/js/') || str_starts_with($path, 'tests/js/')) {
                $selection->notice("{$path} changed: also run pnpm test (and pnpm run build, then commit dist/).");
            }

            if (str_starts_with($path, 'resources/css/')) {
                $selection->notice("{$path} changed: also run pnpm run build and bin/check-assets.");
            }

            // Every change: the classes whose files name this path (not the
            // files of this tool: their paths are rules, not reads).
            foreach ($effective as $reader => $analysis) {
                if ($analysis !== null && $analysis['literals'] !== [] && ! in_array($reader, self::TOOL, true) && Literals::matches($analysis['literals'], $path)) {
                    foreach ($coverage[$reader] ?? [] as $class) {
                        $selection->add($class, "{$reader} names {$path}");
                    }

                    // A gated class whose own file names this path runs directly:
                    // an invariant guard that scans package code
                    // (StaticStateInventoryTest), or a test that reads a path below
                    // a top-level directory (JsTestCoverageTest: tests/js/). A bare
                    // top-level word ('tests') does not count: MatrixRunnerTest
                    // names a temporary tests/ directory.
                    if (self::under($path, self::PACKAGE) || self::namesBelowTopLevel($analysis['literals'], $path)) {
                        foreach ($testFiles[$reader] ?? [] as $class) {
                            $selection->add($class, "{$reader} names {$path}", true);
                        }
                    }
                }
            }

            if ($isTestFile && $kind !== 'deleted') {
                foreach ($testFiles[$path] ?? ($current[$path]['php']['classes'] ?? []) as $class) {
                    $selection->add($class, "{$path} {$kind}", true);
                }

                if (! isset($testFiles[$path]) && ($current[$path]['php']['classes'] ?? []) === []) {
                    $selection->fallBack("{$path} is new and declares no test class");
                }
            }

            // A helper under tests/ that only classes of the gated groups ran
            // belongs to them (WorkbenchCliTestCase, RunsScripts).
            $runners = array_values(array_unique($coverage[$path] ?? []));

            if (! $isTestFile && str_starts_with($path, 'tests/') && $runners !== [] && array_filter($runners, static fn (string $c): bool => ! self::gated($classes[$c] ?? null)) === []) {
                foreach ($runners as $class) {
                    $selection->add($class, "{$path} {$kind} (a helper of its group)", true);
                }
            }

            if ($isPhp) {
                $covered = $coverage[$path] ?? [];
                $note = self::php($selection, $path, $kind, $baseline[$path]['php'] ?? null, $current[$path]['php'] ?? null, $covered, $isTestFile, $grouped, $effective, $coverage);
            } elseif (str_ends_with($path, '.blade.php')) {
                foreach ($coverage[$path] ?? [] as $class) {
                    $selection->add($class, "{$path} {$kind} (a rendered view)");
                }
            } elseif (! $grouped && ! self::isData($path)) {
                $selection->fallBack("{$path} is {$kind} and no rule classifies it");
            }

            $selection->changes[] = sprintf('%s: %s, %d class%s', $path, $note, count($selection->classes) - $before, count($selection->classes) - $before === 1 ? '' : 'es');
        }

        if ($changes === []) {
            $selection->changes[] = 'no file changed since the recording';
        }

        foreach ($wantedGroups as $group => $paths) {
            $found = false;

            foreach ($classes as $class => $info) {
                if (in_array($group, $info['groups'], true)) {
                    $selection->add($class, "{$paths[0]} changed (group {$group})", true);
                    $found = true;
                }
            }

            if (! $found) {
                $selection->fallBack("{$paths[0]} needs the group {$group}, which no recording holds");
            }
        }

        foreach ($classes as $class => $info) {
            if ($info['failed']) {
                $selection->add($class, 'failed in the recording', true);
            }

            if ($packageChange && $info['incomplete']) {
                $selection->add($class, 'its worker coverage was lost in the recording');
            }
        }

        foreach ($selection->classes as $class => $reason) {
            if (self::gated($classes[$class] ?? null) && ! isset($selection->direct[$class])) {
                $selection->deferred[$class] = $reason;
                unset($selection->classes[$class]);
            }
        }

        ksort($selection->deferred);

        // The groups of each selected class, for the routing of Command::plan():
        // the recorded ones and the #[Group] names of its file now.
        $fileOf = [];

        foreach ($current as $path => $file) {
            foreach ($file['php']['classes'] ?? [] as $class) {
                $fileOf[$class] = $path;
            }
        }

        foreach (array_keys($selection->classes) as $class) {
            $file = $classes[$class]['file'] ?? $fileOf[$class] ?? null;
            $now = $file === null ? [] : ($current[$file]['php']['groups'] ?? []);

            // A new or changed test file says its groups now; a class can leave a group.
            $selection->groups[$class] = $file !== null && isset($changes[$file])
                ? $now
                : array_values(array_unique([...($classes[$class]['groups'] ?? []), ...$now]));

            if (! isset($classes[$class])) {
                $selection->unknown[$class] = true;
            }
        }

        return $selection;
    }

    /**
     * @param  array<string, mixed>|null  $info
     */
    private static function gated(?array $info): bool
    {
        return $info !== null && array_intersect((array) ($info['groups'] ?? []), self::GATED) !== [];
    }

    /**
     * The rules of a PHP file. Returns the note for the change line.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @param  list<string>  $covered
     * @param  array<string, array<string, mixed>|null>  $effective
     * @param  array<string, list<string>>  $coverage
     */
    private static function php(Selection $selection, string $path, string $kind, ?array $old, ?array $new, array $covered, bool $isTestFile, bool $grouped, array $effective, array $coverage): string
    {
        if ($kind === 'deleted') {
            if ($covered !== []) {
                $selection->fallBack("{$path} was deleted, and recorded classes ran it");
            }

            // A file that no class ran can still declare what others name
            // (an interface, a class of constants): their users break.
            $affected = self::propagate($selection, $path, $old['declares'] ?? [], $effective, $coverage);

            return sprintf('deleted, %d referencing file%s', $affected, $affected === 1 ? '' : 's');
        }

        if ($kind === 'new') {
            if (! $isTestFile && ! $grouped) {
                $selection->fallBack("{$path} is a new PHP file that no recording ran");
            }

            return 'new';
        }

        if ($covered === []) {
            if (! $isTestFile && ! $grouped) {
                $selection->fallBack("{$path} changed, and no recorded class ran it");
            }

            return 'changed, not run by any recorded class';
        }

        if ($old === null || $new === null) {
            $selection->fallBack("{$path} has no analysis in the map");

            return 'changed';
        }

        if ($old['hash'] === $new['hash']) {
            return 'comments or whitespace only';
        }

        foreach ($covered as $class) {
            $selection->add($class, "{$path} changed");
        }

        if ($old['surface'] === $new['surface']) {
            return 'function bodies changed';
        }

        // The surface changed: follow the names.
        $affected = self::propagate($selection, $path, [...$old['declares'], ...$new['declares']], $effective, $coverage);

        return sprintf('surface changed, %d referencing file%s', $affected, $affected === 1 ? '' : 's');
    }

    /**
     * Selects the classes that ran a file that names one of $declared, and
     * so on through the files that name it in their own surface. Returns
     * the number of referencing files.
     *
     * @param  list<string>  $declared
     * @param  array<string, array<string, mixed>|null>  $effective
     * @param  array<string, list<string>>  $coverage
     */
    private static function propagate(Selection $selection, string $path, array $declared, array $effective, array $coverage): int
    {
        $names = array_fill_keys($declared, true);
        $seen = $names;
        $affected = [$path => true];

        while ($names !== []) {
            $next = [];

            foreach ($effective as $other => $analysis) {
                if ($analysis === null || $other === $path) {
                    continue;
                }

                $refers = false;

                foreach ($analysis['refs'] as $ref) {
                    if (isset($names[$ref])) {
                        $refers = true;
                        break;
                    }
                }

                if (! $refers) {
                    continue;
                }

                $affected[$other] = true;

                foreach ($analysis['surface_refs'] as $ref) {
                    if (isset($names[$ref])) {
                        foreach ($analysis['declares'] as $name) {
                            if (! isset($seen[$name])) {
                                $seen[$name] = true;
                                $next[$name] = true;
                            }
                        }

                        break;
                    }
                }
            }

            $names = $next;
        }

        foreach (array_keys($affected) as $other) {
            foreach ($coverage[$other] ?? [] as $class) {
                $selection->add($class, "{$other} names a class of {$path}, whose surface changed");
            }
        }

        return count($affected) - 1;
    }

    /**
     * Whether one of $targets that matches $path is more than a bare
     * top-level directory.
     *
     * @param  list<string>  $targets
     */
    private static function namesBelowTopLevel(array $targets, string $path): bool
    {
        foreach ($targets as $target) {
            if (Literals::matches([$target], $path) && ! in_array(rtrim($target, '/*'), PhpFile::DIRECTORY_WORDS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a test file of the group docker names $path exactly, or a
     * class of it. (Directories that docker tests use are in DOCKER.)
     *
     * @param  array<string, mixed>|null  $analysis
     * @param  array<string, array<string, mixed>|null>  $effective
     */
    private static function namedByDockerTest(string $path, ?array $analysis, array $effective): bool
    {
        $declared = array_fill_keys($analysis['declares'] ?? [], true);

        foreach ($effective as $other => $candidate) {
            if ($candidate === null || $other === $path || ! in_array('docker', $candidate['groups'] ?? [], true)) {
                continue;
            }

            if (in_array($path, $candidate['literals'], true)) {
                return true;
            }

            foreach ($candidate['refs'] as $ref) {
                if (isset($declared[$ref])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $prefixes
     */
    private static function under(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($path === $prefix || (str_ends_with($prefix, '/') && str_starts_with($path, $prefix))) {
                return true;
            }
        }

        return false;
    }

    private static function isData(string $path): bool
    {
        return str_ends_with($path, '.md') || self::under($path, self::DATA);
    }

    private static function looksLikeTest(string $path): bool
    {
        return str_starts_with($path, 'tests/') && str_ends_with($path, 'Test.php');
    }
}

/**
 * The checks of a map against the environment.
 */
final class Checks
{
    /**
     * The reason why the map cannot serve, or null.
     *
     * @param  array<string, mixed>  $meta
     * @param  array{php: string, vendor: string|null, lock: string|null}  $now
     */
    public static function reason(array $meta, array $now, ?int $commitsBehind, int $maxCommits): ?string
    {
        if (($meta['php'] ?? null) !== $now['php']) {
            return sprintf('the map was recorded on PHP %s, this is PHP %s', $meta['php'] ?? '?', $now['php']);
        }

        if (($meta['vendor'] ?? null) !== $now['vendor']) {
            return 'the installed vendor packages changed since the recording (vendor/composer/installed.json)';
        }

        if (($meta['lock'] ?? null) !== $now['lock']) {
            return 'composer.lock changed since the recording';
        }

        if (($meta['variant'] ?? null) !== ($now['variant'] ?? null)) {
            return sprintf('the map was recorded on another test variant (%s), this run is %s', self::variant($meta['variant'] ?? null), self::variant($now['variant'] ?? null));
        }

        if ($commitsBehind === null) {
            return sprintf('the recorded commit %s is not an ancestor of HEAD', substr((string) ($meta['head'] ?? '?'), 0, 12));
        }

        if ($commitsBehind > $maxCommits) {
            return sprintf('the map is %d commits behind HEAD (more than %d): run bin/tia record', $commitsBehind, $maxCommits);
        }

        return null;
    }

    /**
     * Why a recording cannot give a map: PHPUnit failed, but no class was
     * marked failed, so the failure would never run again. Null when fine.
     *
     * @param  array<string, array<string, mixed>>  $classes
     */
    public static function recordingProblem(int $exit, array $classes): ?string
    {
        if ($exit === 0) {
            return null;
        }

        foreach ($classes as $info) {
            if ((bool) ($info['failed'] ?? false)) {
                return null;
            }
        }

        return "PHPUnit ended with exit {$exit}, but the recorder saw no failed class: fix the run (or its warnings) and record again";
    }

    /**
     * The test variant of this process (the variables that bin/test passes
     * to the tests and that change what they run).
     *
     * @return array{mirror: string|null, prefix: string|null, sqlite_journal: string|null, pg_pooled: string|null}
     */
    public static function currentVariant(): array
    {
        // A value that names the default (bin/test spells some out) is the default.
        $value = static fn (string $name, ?string $default = null): ?string => is_string($v = getenv($name)) && $v !== '' && $v !== $default ? $v : null;

        return [
            'mirror' => $value('ORRERY_TEST_MIRROR', 'same'),
            'prefix' => $value('ORRERY_TEST_PREFIX'),
            'sqlite_journal' => $value('ORRERY_TEST_SQLITE_JOURNAL', 'wal'),
            'pg_pooled' => $value('ORRERY_TEST_PG_POOLED'),
        ];
    }

    /**
     * @param  mixed  $variant
     */
    private static function variant($variant): string
    {
        if (! is_array($variant)) {
            return 'none recorded';
        }

        $set = array_filter($variant, static fn ($v): bool => $v !== null);

        return $set === [] ? 'the default' : implode(', ', array_map(static fn ($k, $v): string => "{$k}={$v}", array_keys($set), $set));
    }

    /**
     * @return array{php: string, vendor: string|null, lock: string|null, variant: array<string, string|null>}
     */
    public static function environment(string $root, string $php): array
    {
        $installed = $root.'/vendor/composer/installed.json';
        $lock = $root.'/composer.lock';
        $version = trim(Shell::run([$php, '-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'], $status));

        return [
            'php' => $status === 0 ? $version : '?',
            'vendor' => is_file($installed) ? sha1_file($installed) ?: null : null,
            'lock' => is_file($lock) ? sha1_file($lock) ?: null : null,
            'variant' => self::currentVariant(),
        ];
    }

    public static function commitsBehind(string $root, string $head): ?int
    {
        Shell::run(['git', '-C', $root, 'merge-base', '--is-ancestor', $head, 'HEAD'], $status);

        if ($status !== 0) {
            return null;
        }

        $count = trim(Shell::run(['git', '-C', $root, 'rev-list', '--count', $head.'..HEAD'], $status));

        return $status === 0 && ctype_digit($count) ? (int) $count : null;
    }
}

final class Shell
{
    /**
     * Runs a command without a shell and returns its stdout.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $env  variables to set for the command
     */
    public static function run(array $command, ?int &$status = null, ?string $cwd = null, array $env = []): string
    {
        // Only stdout is read; stderr goes to /dev/null, so a full stderr pipe cannot block the child.
        $environment = $env === [] ? null : array_replace(getenv(), $env);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $cwd, $environment);

        if (! is_resource($process)) {
            $status = 127;

            return '';
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);

        return $output;
    }

    /**
     * Runs a command with this process's terminal and returns its exit code.
     *
     * @param  list<string>  $command
     * @param  array<string, string|false>  $env  variables to set (false unsets)
     */
    public static function passthru(array $command, string $cwd, array $env = []): int
    {
        $environment = getenv();

        foreach ($env as $name => $value) {
            if ($value === false) {
                unset($environment[$name]);
            } else {
                $environment[$name] = $value;
            }
        }

        $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $cwd, $environment);

        if (! is_resource($process)) {
            return 127;
        }

        return proc_close($process);
    }
}

/**
 * The command line of bin/tia.
 */
final class Command
{
    public const ENGINES = ['sqlite', 'sqlite-file', 'mysql', 'pgsql'];

    public const EXTENSION = 'Justrau\\Orrery\\Tests\\Support\\Tia\\Extension';

    public const DEFAULT_MAX_COMMITS = 500;

    private const USAGE = <<<'TXT'
usage: bin/tia [<engine>] [--dry-run] [--max-commits=<n>] [--map-dir=<dir>] [-- <phpunit arguments>]
       bin/tia record [--engine=<engine>] [--scope=<all|default>] [--map-dir=<dir>]
       bin/tia status [--map-dir=<dir>]

Runs only the tests that a change can affect (test impact analysis).

Engines: sqlite (in-memory, the default), sqlite-file, mysql, pgsql.

bin/tia record runs the suite once under Xdebug (XDEBUG_MODE=coverage) and
writes .tia/map-<engine>.json: for each file of the tree, its hashes and the
test classes that ran it (worker processes included). --scope=all (the
default for sqlite) records every group except docker; --scope=default (the
default for the other engines) records the default selection of
phpunit.xml.dist. A server engine recording is a heavy run: bin/test takes
the heavy-run slot.

bin/tia [engine] compares the tree with the map of the engine (also
uncommitted changes, in any worktree), prints why each class runs, and runs
them through vendor/bin/phpunit (sqlite) or bin/test <engine> (classes of the
groups scripts and workbench always run on in-memory SQLite). It runs the
full scope instead, and says why, when the map cannot prove the selection.
--dry-run prints the selection only. A worktree without its own .tia/ uses
the map of the main checkout.

bin/tia status prints the maps.

Exit codes: PHPUnit's exit code (also bin/test's); 0 when no test is
affected; 64 usage; 1 when a recording fails.
TXT;

    /**
     * @param  list<string>  $argv
     */
    public static function main(array $argv, string $root): int
    {
        $args = array_slice($argv, 1);
        $passthrough = [];
        $separator = array_search('--', $args, true);

        if ($separator !== false) {
            $passthrough = array_values(array_slice($args, $separator + 1));
            $args = array_values(array_slice($args, 0, $separator));
        }

        try {
            if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
                fwrite(STDOUT, self::USAGE."\n");

                return 0;
            }

            if (($args[0] ?? null) === 'record') {
                return self::record(array_slice($args, 1), $passthrough, $root);
            }

            if (($args[0] ?? null) === 'status') {
                return self::status(array_slice($args, 1), $root);
            }

            return self::run($args, $passthrough, $root);
        } catch (UsageError $error) {
            fwrite(STDERR, 'bin/tia: '.$error->getMessage()."\n".self::USAGE."\n");

            return 64;
        } catch (RuntimeException $exception) {
            fwrite(STDERR, 'bin/tia: '.$exception->getMessage()."\n");

            return 1;
        }
    }

    /**
     * @param  list<string>  $args
     * @param  list<string>  $passthrough
     */
    private static function record(array $args, array $passthrough, string $root): int
    {
        // A filter, a group or a test file would leave classes out of the map,
        // and a selection from it would miss them.
        if ($passthrough !== []) {
            throw new UsageError('A recording runs the whole scope, so it takes no PHPUnit arguments (a filter or a group would write a partial map).');
        }

        $options = self::options($args, ['engine', 'scope', 'map-dir']);
        $engine = self::engine($options['engine'] ?? null);
        $scope = $options['scope'] ?? ($engine === 'sqlite' ? 'all' : 'default');

        if (! in_array($scope, ['all', 'default'], true)) {
            throw new UsageError("--scope must be all or default, not '{$scope}'.");
        }

        $php = self::php();
        $probe = trim(Shell::run([$php, '-r', 'echo function_exists("xdebug_info") && in_array("coverage", xdebug_info("mode"), true) ? "yes" : "no";'], $status, null, ['XDEBUG_MODE' => 'coverage']));

        if ($probe !== 'yes') {
            throw new RuntimeException("{$php} has no Xdebug with the coverage mode. Install Xdebug for this PHP (XDEBUG_MODE=coverage turns it on).");
        }

        $mapDir = $options['map-dir'] ?? $root.'/.tia';

        if (! is_dir($mapDir) && ! mkdir($mapDir, 0777, true) && ! is_dir($mapDir)) {
            throw new RuntimeException("Cannot create {$mapDir}.");
        }

        $head = trim(Shell::run(['git', '-C', $root, 'rev-parse', 'HEAD'], $status));
        $paths = Tree::scanGit($root);
        $before = Tree::hashes($root, $paths);
        $record = $mapDir.'/record-'.$engine.'-'.getmypid();
        self::removeDirectory($record);
        mkdir($record.'/children', 0777, true);

        $command = self::recordCommand($engine, $scope, $php);

        fwrite(STDOUT, sprintf("bin/tia record: %s (scope %s) under Xdebug: %s\n", $engine, $scope, implode(' ', $command)));
        $started = microtime(true);
        $exit = Shell::passthru($command, $root, ['XDEBUG_MODE' => 'coverage', 'ORRERY_TIA_RECORD' => $record, 'ORRERY_TIA_ROOT' => $root]);
        $seconds = microtime(true) - $started;

        $after = Tree::hashes($root, Tree::scanGit($root), ['files' => array_map(static fn (array $e): array => ['raw' => $e['raw'], 'php' => $e['php']], $before)]);
        $moved = array_keys(array_diff_key($after, $before) + array_diff_key($before, $after));

        foreach ($before as $path => $entry) {
            if (isset($after[$path]) && $after[$path]['raw'] !== $entry['raw']) {
                $moved[] = $path;
            }
        }

        if ($moved !== []) {
            self::removeDirectory($record);

            throw new RuntimeException('The tree changed during the recording, so the map would not match it: '.implode(', ', array_slice($moved, 0, 10)));
        }

        $environment = Checks::environment($root, $php);
        $map = MapBuilder::build([$record], $root, $before, [
            'variant' => $environment['variant'],
            'engine' => $engine,
            'scope' => $scope,
            'head' => $head,
            'recorded_at' => time(),
            'seconds' => round($seconds, 1),
            'phpunit_exit' => $exit,
            'php' => $environment['php'],
            'vendor' => $environment['vendor'],
            'lock' => $environment['lock'],
        ]);

        $problem = Checks::recordingProblem($exit, $map['classes']);

        if ($problem !== null) {
            self::removeDirectory($record);

            throw new RuntimeException("No map written: {$problem}.");
        }

        $target = $mapDir.'/map-'.$engine.'.json';
        file_put_contents($target.'.tmp', json_encode($map, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        rename($target.'.tmp', $target);
        self::removeDirectory($record);

        $failed = array_keys(array_filter($map['classes'], static fn (array $c): bool => $c['failed']));
        $incomplete = array_keys(array_filter($map['classes'], static fn (array $c): bool => $c['incomplete']));
        $mapped = count(array_filter($map['files'], static fn (array $f): bool => $f['tests'] !== []));

        fwrite(STDOUT, sprintf(
            "bin/tia record: %s in %.1f s (PHPUnit exit %d): %d classes, %d of %d files ran by a class, %d failed classes, %d incomplete classes. Map: %s\n",
            $engine, $seconds, $exit, count($map['classes']), $mapped, count($map['files']), count($failed), count($incomplete), $target,
        ));

        foreach ($failed as $class) {
            fwrite(STDOUT, "  failed: {$class} (it runs in every selection until the next recording)\n");
        }

        foreach ($incomplete as $class) {
            fwrite(STDOUT, "  incomplete: {$class} (a worker's coverage was lost; it runs on every package change)\n");
        }

        return 0;
    }

    /**
     * @param  list<string>  $args
     * @param  list<string>  $passthrough
     */
    private static function run(array $args, array $passthrough, string $root): int
    {
        $positional = array_values(array_filter($args, static fn (string $a): bool => ! str_starts_with($a, '--')));
        $options = self::options(array_values(array_filter($args, static fn (string $a): bool => str_starts_with($a, '--'))), ['engine', 'dry-run', 'max-commits', 'map-dir']);

        if (count($positional) > 1) {
            throw new UsageError('Give at most one engine.');
        }

        $engine = self::engine($positional[0] ?? $options['engine'] ?? null);

        // PHPUnit keeps the last --filter, so one here would replace the selection.
        foreach ($passthrough as $argument) {
            if ($argument === '--filter' || str_starts_with($argument, '--filter=')) {
                throw new UsageError('bin/tia selects with --filter itself; it takes no --filter of its own (run vendor/bin/phpunit --filter for one test).');
            }
        }

        $dryRun = array_key_exists('dry-run', $options);
        $maxCommits = isset($options['max-commits']) ? self::count($options['max-commits']) : self::DEFAULT_MAX_COMMITS;
        $started = microtime(true);
        $mapDir = $options['map-dir'] ?? self::mapDirectory($root);
        $maps = [];

        foreach (glob($mapDir.'/map-*.json') ?: [] as $file) {
            $map = MapBuilder::json($file);

            if (($map['version'] ?? null) === MapBuilder::VERSION) {
                $maps[(string) $map['engine']] = $map;
            }
        }

        $php = self::php();
        $selection = null;

        if (! isset($maps[$engine])) {
            $reason = "there is no map for {$engine} in {$mapDir}: run bin/tia record --engine={$engine}";
        } else {
            $map = $maps[$engine];
            $commits = Checks::commitsBehind($root, (string) $map['head']);
            $reason = Checks::reason($map, Checks::environment($root, $php), $commits, $maxCommits);
            fwrite(STDOUT, sprintf("bin/tia: %s, map of %s at %s (%s), %s\n", $engine, date('Y-m-d H:i T', (int) $map['recorded_at']), substr((string) $map['head'], 0, 8), $commits === null ? 'not an ancestor of HEAD' : "{$commits} commits behind HEAD", $mapDir === $root.'/.tia' ? '.tia/' : $mapDir));

            if ($reason === null) {
                $others = array_values(array_diff_key($maps, [$engine => true]));
                $current = Tree::hashes($root, Tree::scanGit($root), $map);
                $selection = Selector::select($map, $others, $current);
                $reason = $selection->fallback;

                foreach ($selection->changes as $line) {
                    fwrite(STDOUT, "  {$line}\n");
                }
            }
        }

        foreach ($selection?->notices ?? [] as $notice) {
            fwrite(STDOUT, "  notice: {$notice}\n");
        }

        $commands = [];

        if ($reason !== null) {
            fwrite(STDOUT, "bin/tia: the full scope runs: {$reason}.\n");
            $commands = self::plan($engine, null, $reason, $passthrough, $php);
        } else {
            assert($selection !== null);
            $known = $selection->known;
            $classes = $selection->classNames();
            $totalTests = array_sum(array_map(static fn (array $c): int => (int) $c['tests'], $known));
            $selectedTests = array_sum(array_map(static fn (string $c): int => (int) ($known[$c]['tests'] ?? 0), $classes));
            fwrite(STDOUT, sprintf("bin/tia: selected %d of %d classes (%d of %d recorded tests) in %.2f s.\n", count($classes), count($known), $selectedTests, $totalTests, microtime(true) - $started));

            foreach ($selection->classes as $class => $why) {
                fwrite(STDOUT, "  {$class}: {$why}\n");
            }

            if ($selection->deferred !== []) {
                fwrite(STDOUT, sprintf("bin/tia: %d class%s of the groups scripts and workbench left to the chunk-end gate (composer test:all): %s\n", count($selection->deferred), count($selection->deferred) === 1 ? '' : 'es', implode(', ', array_map(static fn (string $c): string => substr((string) strrchr('\\'.$c, '\\'), 1), array_keys($selection->deferred)))));
            }

            if ($classes === []) {
                fwrite(STDOUT, "bin/tia: no test is affected.\n");

                return 0;
            }

            $commands = self::plan($engine, $selection, null, $passthrough, $php);
        }

        foreach ($commands as $command) {
            fwrite(STDOUT, 'bin/tia: '.($dryRun ? 'would run' : 'running').': '.self::display($command)."\n");
        }

        if ($dryRun) {
            return 0;
        }

        $exit = 0;

        foreach ($commands as $command) {
            $status = Shell::passthru($command, $root);
            $exit = $exit !== 0 ? $exit : $status;
        }

        return $exit;
    }

    /**
     * The command of a recording. A SQLite-file or server recording runs in
     * the fresh schema mode: the reuse mode does the migration once per
     * process and so credits its files to the first class.
     *
     * @return list<string>
     */
    public static function recordCommand(string $engine, string $scope, string $php): array
    {
        $groups = $scope === 'all' ? ['--exclude-group', 'docker'] : [];
        $phpunit = [...$groups, '--extension', self::EXTENSION];

        return match ($engine) {
            'sqlite' => [$php, 'vendor/bin/phpunit', '--do-not-cache-result', ...$phpunit],
            'sqlite-file' => ['bin/test', 'sqlite', '--sqlite-file', '--schema=fresh', '--', ...$phpunit],
            default => ['bin/test', $engine, '--schema=fresh', '--', ...$phpunit],
        };
    }

    /**
     * The PHPUnit runs of a selection. A fallback runs the full default
     * selection on the engine and the groups scripts and workbench in memory
     * (one run with --exclude-group docker on in-memory SQLite). Otherwise a
     * class runs on the engine unless its groups (recorded, or #[Group] in
     * its file now) hold scripts or workbench; those run in memory; a class
     * that no map holds runs in both.
     *
     * @param  list<string>  $passthrough
     * @return list<list<string>>
     */
    public static function plan(string $engine, ?Selection $selection, ?string $fallback, array $passthrough, string $php): array
    {
        if ($fallback !== null || $selection === null) {
            return self::commands($engine, null, null, true, $passthrough, $php);
        }

        $classes = $selection->classNames();

        if ($classes === []) {
            return [];
        }

        $main = [];
        $memory = [];

        foreach ($classes as $class) {
            $unknown = isset($selection->unknown[$class]);
            $gated = array_intersect($selection->groups[$class] ?? [], Selector::GATED) !== [];

            if ($unknown || ! $gated) {
                $main[] = $class;
            }

            if ($unknown || $gated) {
                $memory[] = $class;
            }
        }

        return self::commands($engine, $main, $memory, true, $passthrough, $php);
    }

    /**
     * The PHPUnit runs: the engine run of the main classes (null: all), and
     * an in-memory run of the classes of the groups scripts and workbench
     * (null: those groups whole, when $extra). On in-memory SQLite one run.
     *
     * @param  list<string>|null  $main
     * @param  list<string>|null  $extraClasses
     * @param  list<string>  $passthrough
     * @return list<list<string>>
     */
    public static function commands(string $engine, ?array $main, ?array $extraClasses, bool $extra, array $passthrough, string $php): array
    {
        $memory = static fn (array $args): array => [$php, 'vendor/bin/phpunit', ...$args, ...$passthrough];

        if ($engine === 'sqlite') {
            if ($main === null) {
                return [$memory($extra ? ['--exclude-group', 'docker'] : [])];
            }

            return [$memory(['--exclude-group', 'docker', '--filter', self::filter([...$main, ...(array) $extraClasses])])];
        }

        $wrapper = $engine === 'sqlite-file' ? ['bin/test', 'sqlite', '--sqlite-file', '--'] : ['bin/test', $engine, '--'];
        $commands = [];

        if ($main === null) {
            $commands[] = [...$wrapper, ...$passthrough];

            if ($extra) {
                $commands[] = $memory(['--group', 'scripts', '--group', 'workbench', '--exclude-group', 'docker']);
            }

            return $commands;
        }

        if ($main !== []) {
            $commands[] = [...$wrapper, '--filter', self::filter($main), ...$passthrough];
        }

        if ($extraClasses !== null && $extraClasses !== []) {
            $commands[] = $memory(['--exclude-group', 'docker', '--filter', self::filter($extraClasses)]);
        }

        return $commands;
    }

    /**
     * A --filter that matches exactly the tests of these classes.
     *
     * @param  list<string>  $classes
     */
    public static function filter(array $classes): string
    {
        return '/^(?:'.implode('|', array_map(static fn (string $c): string => preg_quote($c, '/'), $classes)).')::/';
    }

    /**
     * @param  list<string>  $args
     */
    private static function status(array $args, string $root): int
    {
        $options = self::options($args, ['map-dir']);
        $mapDir = $options['map-dir'] ?? self::mapDirectory($root);
        $files = glob($mapDir.'/map-*.json') ?: [];

        if ($files === []) {
            fwrite(STDOUT, "bin/tia: no map in {$mapDir}. Run bin/tia record.\n");

            return 0;
        }

        foreach ($files as $file) {
            $map = MapBuilder::json($file);
            $commits = Checks::commitsBehind($root, (string) $map['head']);
            fwrite(STDOUT, sprintf(
                "%s: scope %s, recorded %s at %s (%s) in %s s on PHP %s, %d classes, %d failed, %d incomplete\n",
                $map['engine'], $map['scope'], date('Y-m-d H:i T', (int) $map['recorded_at']), substr((string) $map['head'], 0, 8),
                $commits === null ? 'not an ancestor of HEAD' : "{$commits} commits behind", $map['seconds'], $map['php'],
                count($map['classes']), count(array_filter($map['classes'], static fn (array $c): bool => $c['failed'])), count(array_filter($map['classes'], static fn (array $c): bool => $c['incomplete'])),
            ));
        }

        return 0;
    }

    /**
     * The map directory: this checkout's .tia/, else the main checkout's
     * (the hashes are by content, so a map serves every checkout).
     */
    private static function mapDirectory(string $root): string
    {
        if (glob($root.'/.tia/map-*.json')) {
            return $root.'/.tia';
        }

        $common = trim(Shell::run(['git', '-C', $root, 'rev-parse', '--path-format=absolute', '--git-common-dir'], $status));

        if ($status === 0 && $common !== '' && glob(dirname($common).'/.tia/map-*.json')) {
            return dirname($common).'/.tia';
        }

        return $root.'/.tia';
    }

    private static function engine(?string $engine): string
    {
        $engine ??= 'sqlite';

        if (! in_array($engine, self::ENGINES, true)) {
            throw new UsageError("Unknown engine '{$engine}'. Use one of: ".implode(', ', self::ENGINES).'.');
        }

        return $engine;
    }

    private static function count(string $value): int
    {
        if (! ctype_digit($value)) {
            throw new UsageError("--max-commits needs a whole number, not '{$value}'.");
        }

        return (int) $value;
    }

    private static function php(): string
    {
        $php = getenv('ORRERY_TEST_PHP');

        return is_string($php) && $php !== '' ? $php : 'php';
    }

    /**
     * @param  list<string>  $args
     * @param  list<string>  $allowed
     * @return array<string, string>
     */
    private static function options(array $args, array $allowed): array
    {
        $options = [];

        foreach ($args as $arg) {
            if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $match) !== 1 || ! in_array($match[1], $allowed, true)) {
                throw new UsageError("Unknown argument '{$arg}'.");
            }

            $options[$match[1]] = $match[2] ?? '';
        }

        return $options;
    }

    /**
     * @param  list<string>  $command
     */
    private static function display(array $command): string
    {
        return implode(' ', array_map(static function (string $part): string {
            $shown = strlen($part) > 160 ? substr($part, 0, 150).'…('.strlen($part).' bytes)' : $part;

            return preg_match('/^[A-Za-z0-9_.\/=:-]+$/', $shown) === 1 ? $shown : "'".str_replace("'", "'\\''", $shown)."'";
        }, $command));
    }

    private static function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            $item instanceof \SplFileInfo && $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}

final class UsageError extends RuntimeException {}
