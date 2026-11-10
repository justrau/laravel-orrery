<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The package never opens a host database file, or its -wal, -shm or
 * -journal file, other than through SQLite (D18): on POSIX,
 * closing any descriptor of a file drops all
 * of the process's fcntl locks on it, SQLite's own included, so a plain PHP
 * read of the header can lose committed work. realpath() and stat() are
 * allowed.
 *
 * The test reads the tokens of every file under src/ and fails on each call
 * of a banned function, `new SplFileObject`, `new SQLite3`, `->openFile()`,
 * every static call of the `File` and `Storage` facades and every import of
 * them (an alias included), a filesystem service by its container name
 * (`app('files')`, `$app['files']`), `unlink`, `rename`, `touch` and
 * `chmod`, and any use of
 * Illuminate\Filesystem\Filesystem, unless ALLOWED names the call: the file
 * and the exact source line, with what it opens. (The allowlist is by line
 * text, not by line number, so an edit elsewhere in a file does not move
 * it.)
 */
#[Group('scripts')]
final class HostFileOpenScanTest extends TestCase
{
    private const BANNED = [
        'fopen', 'file', 'file_get_contents', 'file_put_contents', 'readfile', 'hash_file', 'md5_file',
        'sha1_file', 'copy', 'finfo_file', 'gzopen', 'parse_ini_file', 'hash_hmac_file', 'hash_update_file',
        'gzfile', 'bzopen', 'mime_content_type', 'getimagesize', 'exif_read_data', 'highlight_file',
        'show_source', 'php_strip_whitespace', 'simplexml_load_file',
        // They change a file without opening it: a host file or its -wal, -shm or -journal (D17).
        'unlink', 'rename', 'touch', 'chmod',
    ];

    /** The facades whose every static call opens or names a file. */
    private const BANNED_FACADES = ['File', 'Storage'];

    /** The container names of the filesystem services. */
    private const BANNED_SERVICES = ['files', 'filesystem', 'filesystem.disk', 'filesystem.cloud'];

    private const BANNED_CLASSES = ['SplFileObject', 'SQLite3'];

    /** file => [the trimmed source line => what it opens] */
    private const ALLOWED = [
        'src/Orrery.php' => [
            '$contents = file_get_contents(__DIR__.\'/../dist/app.css\');' => 'the package\'s own built CSS in dist/',
            '$contents = file_get_contents(__DIR__.\'/../dist/app.js\');' => 'the package\'s own built JavaScript in dist/',
        ],
        'src/Console/InstallCommand.php' => [
            '$contents = (string) file_get_contents($providerPath);' => 'the generated application service-provider PHP file',
            'file_put_contents($providerPath, str_replace(' => 'the generated application service-provider PHP file',
            '$contents = (string) file_get_contents($appConfig);' => 'the application\'s config/app.php',
            '$stub = (string) file_get_contents(__DIR__.\'/../../stubs/fence-migration.stub\');' => 'the package\'s own fence migration stub',
            'if (@file_put_contents($file, str_replace(\'{{ connection }}\', addcslashes($connection, "\'\\\\"), $stub)) === false) {' => 'a new fence migration PHP file in the application\'s database/migrations (orrery:install --fence)',
        ],
        'src/Runtime/ProcessContext.php' => [
            '$stat = @file_get_contents("/proc/{$pid}/stat");' => 'the Linux process table',
            '$system = @file_get_contents(\'/proc/stat\');' => 'the Linux process table',
        ],
        'src/Runtime/SharedBreaker.php' => [
            '$content = @file_get_contents($path);' => 'the package\'s own shared breaker file (orrery-breaker-<key> in observer.shared_breaker_path), never a database file',
            '@unlink($path);' => 'the package\'s own shared breaker file, removed after its end',
            '@unlink($temporary);' => 'the temporary file of the package\'s own shared breaker file',
            'if (! @rename($temporary, $path)) {' => 'the temporary file of the package\'s own shared breaker file, renamed over it',
            'if (@file_get_contents($path) === $content) {' => 'the package\'s own shared breaker file, read again before its removal',
            'if (@file_put_contents($temporary, $content) === false) {' => 'the temporary file of the package\'s own shared breaker file, renamed over it',
        ],
        'src/Storage/PackageSession.php' => [
            '$lock = @fopen($lockPath, \'c\');' => 'the package\'s own sibling creation lock file (<sibling>.create.lock), never a database file',
            '$lock = $lock === false ? @fopen($lockPath, \'r\') : $lock;' => 'the package\'s own sibling creation lock file (<sibling>.create.lock), never a database file',
            '@chmod($lockPath, $mode & 0666);' => 'the package\'s own sibling creation lock file, given the host file\'s mode',
            '@unlink($path.$suffix);' => 'a stale -wal, -shm or -journal file of the package\'s own sibling file, under its creation lock, before the sibling exists (never a host file)',
            'if (! @touch($path) || ! is_file($path)) {' => 'the package\'s own new sibling file, under its creation lock',
            '@chmod($path, $mode & 0666);' => 'the package\'s own new sibling file, given the host file\'s mode',
        ],
        'src/Database/Schema/IndexBuild.php' => [
            '$handle = fopen($lockFile, \'c\');' => 'the package\'s own index build lock file (<mirror file>.orrery-indexes.lock, orrery:install --indexes), never a database file',
        ],
        'src/Maintenance/SiblingFileMutex.php' => [
            '$handle = @fopen($this->path, \'c\');' => 'the package\'s own schedule lock file (<file>.orrery-<event>.lock), never a database file',
            '@chmod($this->path, 0664);' => 'the package\'s own new schedule lock file',
        ],
    ];

    /**
     * Rejects: a plain PHP open of a file in src/ that the allowlist does not
     * name, for example a header read of a SQLite file with fopen() or
     * SplFileObject.
     * Still passes: a call through a variable function name or a callable
     * string, a container name held in a variable, and the facade's real
     * class name (`Illuminate\Support\Facades\File` behind a variable or a
     * class_alias): the scan reads tokens, it does not run the code.
     */
    public function test_src_opens_no_file_other_than_the_allowed_ones(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        $used = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src')) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $source = (string) file_get_contents($file->getPathname());
            $lines = explode("\n", $source);

            foreach (self::calls($source) as [$line, $call]) {
                $text = trim($lines[$line - 1] ?? '');

                if (isset(self::ALLOWED[$relative][$text])) {
                    $used[$relative][$text] = true;

                    continue;
                }

                $found[] = "{$relative}:{$line} {$call}: {$text}";
            }
        }

        self::assertSame([], $found, 'A file open in src/ that the allowlist does not name.');

        foreach (self::ALLOWED as $relative => $entries) {
            foreach (array_keys($entries) as $text) {
                self::assertArrayHasKey($text, $used[$relative] ?? [], "The allowlist names a call that {$relative} no longer has: {$text}");
            }
        }
    }

    /**
     * Rejects: a scanner that misses a call, so the test above passes on a
     * banned open (the control: a source with each banned form).
     */
    public function test_the_scanner_finds_every_banned_form(): void
    {
        $source = <<<'PHP'
            <?php
            use Illuminate\Filesystem\Filesystem;
            $a = fopen('x', 'r');
            $b = \file('x');
            $c = new SplFileObject('x');
            $d = new \SQLite3('x');
            $e = $info->openFile();
            $f = File::get('x');
            $g = copy('a', 'b');
            $h = $object->fopen('x');
            $i = Other::file('x');
            function file_like(): void {}
            $j = hash_hmac_file('sha256', 'x', 'k');
            $k = File::exists('x');
            $l = \Illuminate\Support\Facades\Storage::disk('local');
            $m = app('files');
            $n = $this->app->make('filesystem');
            $o = Storage\Clock::now();
            $p = Illuminate\Support\Facades\File::lines('x');
            $q = $this->app['files'];
            use Illuminate\Support\Facades\Storage as Disk;
            $r = unlink('x');
            $s = rename('a', 'b');
            $t = touch('x');
            $u = chmod('x', 0644);
            $v = $config['files_path'];
            PHP;

        $calls = array_map(static fn (array $call): string => $call[1], self::calls($source));

        self::assertSame(['Filesystem', 'fopen', 'file', 'new SplFileObject', 'new SQLite3', '->openFile', 'File::get', 'copy', 'hash_hmac_file', 'File::exists', 'Storage::disk', "'files'", "'filesystem'", 'File::lines', "'files'", 'use Storage', 'unlink', 'rename', 'touch', 'chmod'], $calls);
    }

    /**
     * The banned calls of a source: [line, call].
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function calls(string $source): array
    {
        $tokens = array_values(array_filter(token_get_all($source), static fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $calls = [];

        foreach ($tokens as $i => $token) {
            if (! is_array($token)) {
                continue;
            }

            $text = $token[1];
            $name = ltrim($text, '\\');
            $previous = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;
            $before = is_array($previous) ? $previous[0] : $previous;

            if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && $name === 'Illuminate\\Filesystem\\Filesystem') {
                $calls[] = [$token[2], 'Filesystem'];

                continue;
            }

            // An import of a banned facade, with or without an alias (a static call by its full name is found below).
            if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && $next !== '(' && ! (is_array($next) && $next[0] === T_DOUBLE_COLON)
                && str_starts_with($name, 'Illuminate\\Support\\Facades\\') && in_array(substr($name, strlen('Illuminate\\Support\\Facades\\')), self::BANNED_FACADES, true)) {
                $calls[] = [$token[2], 'use '.substr($name, strlen('Illuminate\\Support\\Facades\\'))];

                continue;
            }

            // A filesystem service by its container name: app('files'), ->make('filesystem'), $app['files'].
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING && in_array(trim($text, '\'"'), self::BANNED_SERVICES, true)) {
                $callee = $tokens[$i - 2] ?? null;

                if ($previous === '[' || ($previous === '(' && is_array($callee) && in_array(strtolower(ltrim($callee[1], '\\')), ['app', 'resolve', 'make', 'makewith', 'get'], true))) {
                    $calls[] = [$token[2], "'".trim($text, '\'"')."'"];
                }

                continue;
            }

            if (! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) || $next !== '(') {
                continue;
            }

            if ($before === T_NEW) {
                if (in_array($name, self::BANNED_CLASSES, true)) {
                    $calls[] = [$token[2], 'new '.$name];
                }

                continue;
            }

            if ($before === T_OBJECT_OPERATOR || $before === T_NULLSAFE_OBJECT_OPERATOR) {
                if ($name === 'openFile') {
                    $calls[] = [$token[2], '->openFile'];
                }

                continue;
            }

            if ($before === T_DOUBLE_COLON) {
                $class = $tokens[$i - 2] ?? null;

                $facade = is_array($class) ? ltrim($class[1], '\\') : '';
                $facade = str_starts_with($facade, 'Illuminate\\Support\\Facades\\') ? substr($facade, strlen('Illuminate\\Support\\Facades\\')) : $facade;

                if (is_array($class) && in_array($facade, self::BANNED_FACADES, true)) {
                    $calls[] = [$token[2], $facade.'::'.$name];
                }

                continue;
            }

            if ($before === T_FUNCTION || $before === T_CONST) {
                continue;
            }

            if (in_array(strtolower($name), self::BANNED, true)) {
                $calls[] = [$token[2], strtolower($name)];
            }
        }

        return $calls;
    }
}
