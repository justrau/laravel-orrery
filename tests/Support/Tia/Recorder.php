<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Tia;

use RuntimeException;

/**
 * The recorder of the PHPUnit process (bin/tia record). Extension starts it
 * only when ORRERY_TIA_RECORD names the recording directory; otherwise every
 * method returns at once and childEnvironment() returns [].
 *
 * Xdebug's code coverage runs for the whole run. At each change of context
 * (a test class starts or ends, a data provider is called or returns) the
 * recorder takes the covered files and the newly included files, gives them
 * to the context that ends (a test class, or "outside"), and restarts the
 * coverage. So every line that runs belongs to exactly one context, and a
 * file belongs to every class that ran a line of it. A file that a class only
 * loaded for its declarations shows up only for the first class (Xdebug
 * records no line for a later constant read): the selector covers that with
 * the names (see bin/lib/tia.php).
 *
 * The PHP children of the run record their included files for the class that
 * runs when they start (ChildRecorder): begin() writes the prepend file and
 * adds its ini directory to PHP_INI_SCAN_DIR, and each change of context
 * rewrites the "context" file that a starting child reads.
 *
 * At the end it writes "parent-<pid>.json" into the recording directory.
 */
final class Recorder
{
    public const RECORD = 'ORRERY_TIA_RECORD';

    private static ?self $active = null;

    private ?string $context = null;

    /** @var array<string, array<string, true>> class => path => true */
    private array $contexts = [];

    /** @var array<string, true> */
    private array $outside = [];

    /** @var array<string, true> the included files that a context already took */
    private array $included = [];

    /** @var array<string, array{file: string|null, groups: list<string>, tests: int, failed: bool}> */
    private array $classes = [];

    /** @var array<string, string> "<pid>-<seq>" => class ('' outside a class) */
    private array $children = [];

    private int $sequence = 0;

    /** @var array<string, int> class => the number in its context key */
    private array $keys = [];

    /** @var array<string, string|null> */
    private array $paths = [];

    private function __construct(private readonly string $directory, private readonly string $root)
    {
    }

    /**
     * Starts the recording. Removes the recording variables and XDEBUG_MODE
     * from this process's environment, so a child process records only when
     * childEnvironment() hands them on (a nested PHPUnit run of a scripts
     * test never records into this directory).
     */
    public static function begin(string $directory): void
    {
        if (! ChildRecorder::coverageAvailable()) {
            throw new RuntimeException('bin/tia record needs Xdebug with XDEBUG_MODE=coverage.');
        }

        if (! is_dir($directory.'/children') && ! mkdir($directory.'/children', 0777, true) && ! is_dir($directory.'/children')) {
            throw new RuntimeException("Cannot create {$directory}/children.");
        }

        $recorder = new self($directory, ChildRecorder::root());

        foreach ([self::RECORD, ChildRecorder::ROOT, 'XDEBUG_MODE'] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        // Every PHP child records through auto_prepend_file (see ChildRecorder).
        // An empty entry in PHP_INI_SCAN_DIR keeps PHP's own scan directory.
        if (! is_dir($directory.'/ini') && ! mkdir($directory.'/ini', 0777, true) && ! is_dir($directory.'/ini')) {
            throw new RuntimeException("Cannot create {$directory}/ini.");
        }

        file_put_contents($directory.'/prepend.php', sprintf(
            "<?php\nrequire_once %s;\n\\Justrau\\Orrery\\Tests\\Support\\Tia\\ChildRecorder::startFromEnvironment(%s, %s);\n",
            var_export(__DIR__.'/ChildRecorder.php', true),
            var_export($directory, true),
            var_export($recorder->root, true),
        ));
        file_put_contents($directory.'/ini/orrery-tia.ini', 'auto_prepend_file="'.$directory.'/prepend.php"'."\n");
        $scan = getenv('PHP_INI_SCAN_DIR');
        $scan = (is_string($scan) ? $scan : '').':'.$directory.'/ini';
        putenv('PHP_INI_SCAN_DIR='.$scan);
        $_ENV['PHP_INI_SCAN_DIR'] = $scan;
        $_SERVER['PHP_INI_SCAN_DIR'] = $scan;
        $recorder->writeContext();

        // The files loaded before the recording (the bootstrap) belong to no class.
        foreach (get_included_files() as $path) {
            $recorder->included[$path] = true;
        }

        self::$active = $recorder;
        ChildRecorder::filter($recorder->root);
        xdebug_start_code_coverage();
    }

    public static function active(): bool
    {
        return self::$active !== null;
    }

    /**
     * The variables that make a child process record for the current test
     * class ([] when no recording runs). WorkerProcess merges them into the
     * environment of each worker it launches.
     *
     * @return array<string, string>
     */
    public static function childEnvironment(): array
    {
        $recorder = self::$active;

        if ($recorder === null) {
            return [];
        }

        $key = getmypid().'-'.(++$recorder->sequence);
        $recorder->children[$key] = $recorder->context ?? '';

        return [
            ChildRecorder::VARIABLE => $recorder->directory.'/children/'.$key,
            ChildRecorder::DIRECT => '1',
            ChildRecorder::ROOT => $recorder->root,
        ];
    }

    /**
     * A test class starts: its context begins.
     *
     * @param  list<string>  $groups
     */
    public static function classStarted(string $class, string $file, array $groups, int $tests): void
    {
        $recorder = self::$active;

        if ($recorder === null) {
            return;
        }

        $known = $recorder->classes[$class] ?? ['file' => null, 'groups' => [], 'tests' => 0, 'failed' => false];
        $relative = ChildRecorder::relevant([$file], $recorder->root, $recorder->paths);
        $recorder->classes[$class] = [
            'file' => $relative[0] ?? $known['file'],
            'groups' => array_values(array_unique([...$known['groups'], ...$groups])),
            'tests' => $known['tests'] + $tests,
            'failed' => $known['failed'],
        ];
        $recorder->switchTo($class);
    }

    /**
     * The context of $class (null: outside every class) begins.
     */
    public static function enter(?string $class): void
    {
        self::$active?->switchTo($class);
    }

    public static function failed(string $class): void
    {
        $recorder = self::$active;

        if ($recorder === null) {
            return;
        }

        $recorder->classes[$class] ??= ['file' => null, 'groups' => [], 'tests' => 0, 'failed' => false];
        $recorder->classes[$class]['failed'] = true;
    }

    public static function finish(): void
    {
        $recorder = self::$active;

        if ($recorder === null) {
            return;
        }

        $recorder->switchTo(null);
        xdebug_stop_code_coverage(true);
        self::$active = null;

        $contexts = [];

        foreach ($recorder->contexts as $class => $paths) {
            $contexts[$class] = array_keys($paths);
        }

        $file = $recorder->directory.'/parent-'.getmypid().'.json';
        file_put_contents($file.'.tmp', json_encode([
            'root' => $recorder->root,
            'contexts' => $contexts,
            'outside' => array_keys($recorder->outside),
            'classes' => $recorder->classes,
            'children' => $recorder->children,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        rename($file.'.tmp', $file);
    }

    private function switchTo(?string $class): void
    {
        if ($class === $this->context) {
            return;
        }

        $ran = array_keys(xdebug_get_code_coverage());

        foreach (get_included_files() as $path) {
            if (! isset($this->included[$path])) {
                $this->included[$path] = true;
                $ran[] = $path;
            }
        }

        foreach (ChildRecorder::relevant($ran, $this->root, $this->paths) as $path) {
            if ($this->context === null) {
                $this->outside[$path] = true;
            } else {
                $this->contexts[$this->context][$path] = true;
            }
        }

        xdebug_stop_code_coverage(true);
        xdebug_start_code_coverage();
        $this->context = $class;
        $this->writeContext();
    }

    /**
     * Names the current context for the children that start now: they read
     * this file (ChildRecorder) when no ORRERY_TIA_CHILD reaches them.
     */
    private function writeContext(): void
    {
        $key = $this->context === null ? 'outside' : 'class-'.($this->keys[$this->context] ??= count($this->keys) + 1);
        $this->children[$key] = $this->context ?? '';
        file_put_contents($this->directory.'/context.tmp', $key);
        rename($this->directory.'/context.tmp', $this->directory.'/context');
    }
}
