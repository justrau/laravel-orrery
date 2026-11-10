<?php

/*
 * bin/verify-install: consumer verification of the package.
 * bin/verify-install --help shows the options.
 *
 * It exports HEAD with `git archive`, installs the export into fresh Laravel
 * applications through a Composer `path` repository (`dev-candidate`), runs
 * the README install commands (one substitution: the `composer config
 * repositories` line and the `composer require` line), the job flows, the tick, the health check, a
 * browser smoke and the removal steps of docs/upgrading.md. The docs are not
 * in the export, so it reads them from HEAD. Every directory and process it
 * starts is its own, and it removes or stops them on success, on failure and
 * on SIGINT or SIGTERM.
 */

declare(strict_types=1);

namespace OrreryVerify;

use PDO;
use RuntimeException;
use Throwable;

/** A verification step failed: the message is the reason. */
final class VerifyFailure extends RuntimeException {}

/** SIGINT or SIGTERM arrived. */
final class Interrupted extends RuntimeException {}

final class Options
{
    public const DEFAULT_PHP = [
        '8.3' => '/opt/homebrew/opt/php@8.3/bin/php',
        '8.5' => '/opt/homebrew/opt/php@8.5/bin/php',
    ];

    public const DEFAULT_CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

    /** @var array<string, string> version => binary */
    public array $php = self::DEFAULT_PHP;

    public string $composer = 'composer';

    public string $node = 'node';

    public string $pnpm = 'pnpm';

    public string $chrome = self::DEFAULT_CHROME;

    public bool $dryRun = false;

    public bool $selfTest = false;

    public bool $keep = false;

    public bool $help = false;

    /** @var list<string> */
    public array $apps = [];

    public ?string $evidence = null;

    /**
     * @param  list<string>  $args  the arguments without the script name
     * @param  array<string, string>  $env
     */
    public static function parse(array $args, array $env): self
    {
        $options = new self;

        foreach (['8.3' => 'ORRERY_PHP_8_3', '8.5' => 'ORRERY_PHP_8_5'] as $version => $name) {
            if (($env[$name] ?? '') !== '') {
                $options->php[$version] = $env[$name];
            }
        }

        if (($env['PW_EXE'] ?? '') !== '') {
            $options->chrome = $env['PW_EXE'];
        }

        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                $options->help = true;
            } elseif ($arg === '--dry-run') {
                $options->dryRun = true;
            } elseif ($arg === '--self-test') {
                $options->selfTest = true;
            } elseif ($arg === '--keep') {
                $options->keep = true;
            } elseif (preg_match('/^--php-binary=(8\.[35])=(.+)$/', $arg, $m) === 1) {
                $options->php[$m[1]] = $m[2];
            } elseif (preg_match('/^--(composer|node|pnpm|chrome|evidence)=(.+)$/', $arg, $m) === 1) {
                $options->{$m[1]} = $m[2];
            } elseif (preg_match('/^--app=(.+)$/', $arg, $m) === 1) {
                foreach (explode(',', $m[1]) as $id) {
                    if (! isset(Apps::all()[$id])) {
                        throw new RuntimeException("unknown app: {$id} (known: ".implode(', ', array_keys(Apps::all())).')');
                    }

                    $options->apps[] = $id;
                }
            } else {
                throw new RuntimeException("unknown argument: {$arg}");
            }
        }

        if ($options->apps === []) {
            $options->apps = array_keys(Apps::all());
        }

        return $options;
    }

    public static function usage(): string
    {
        return <<<'TXT'
            Usage: bin/verify-install [--dry-run] [--self-test] [--app=<id>[,<id>]] [--keep]
                                      [--php-binary=8.3=<path>] [--php-binary=8.5=<path>]
                                      [--composer=<path>] [--node=<path>] [--pnpm=<path>]
                                      [--chrome=<path>] [--evidence=<dir>]

            Verifies a consumer installation of HEAD (git archive) in fresh Laravel apps on a
            SQLite file:
              l12-floor   laravel/laravel 12 with laravel/framework 12.69.0, on PHP 8.3
              l13-latest  laravel/laravel 13 latest, on PHP 8.5 (also publishes the jobs index)

            --dry-run      print the plan (with the README install commands) and exit
            --self-test    run the negative controls: dist/ removed, the main migration removed;
                           each must fail with its reason (on l13-latest)
            --keep         keep the temporary directory (for debugging)
            --evidence     the log directory (default .verify/<run-id>/)

            The PHP binaries default to ORRERY_PHP_8_3 and ORRERY_PHP_8_5, else Homebrew's.
            The browser smoke (tools/browser/smoke.mjs) uses --chrome, else PW_EXE, else
            Google Chrome. Exit codes: 0 pass, 1 fail, 64 usage, 65 refused (dirty tree),
            130 interrupted.
            TXT;
    }
}

final class Apps
{
    /**
     * @return array<string, array{skeleton: string, framework: ?string, php: string, jobs_index: bool}>
     */
    public static function all(): array
    {
        return [
            'l12-floor' => ['skeleton' => 'laravel/laravel:^12.0', 'framework' => '12.69.0', 'php' => '8.3', 'jobs_index' => false],
            'l13-latest' => ['skeleton' => 'laravel/laravel:^13.0', 'framework' => null, 'php' => '8.5', 'jobs_index' => true],
        ];
    }
}

/**
 * The commands that bin/verify-install reads from the candidate's README
 * (the install) and from the docs of HEAD (the CSP middleware, the removal).
 */
final class Docs
{
    public const README = 'README.md';

    public const SECURITY = 'docs/security.md';

    public const UPGRADING = 'docs/upgrading.md';

    public const REPOSITORY_LINE = 'composer config repositories.orrery vcs https://github.com/justrau/laravel-orrery';

    public const REQUIRE_LINE = 'composer require justrau/laravel-orrery:^0.1';

    public const CANDIDATE_REQUIRE = 'composer require justrau/laravel-orrery:dev-candidate';

    /**
     * The install steps of "## Installation" up to the next "## " or "### "
     * heading:
     * each `{kind: repository|command, text, readme}`. The repository command
     * and the require line are the one substitution (readme holds the
     * original); every other command is literal.
     *
     * @return list<array{kind: string, text: string, readme: ?string}>
     */
    public static function installSteps(string $readme, string $candidateDir): array
    {
        $section = self::section($readme, self::README, '## Installation', ['## ', '### ']);
        $steps = [];
        $repository = false;
        $require = false;

        foreach (self::blocks($section) as [$lang, $body]) {
            if ($lang !== 'bash') {
                continue;
            }

            foreach (preg_split('/\R/', trim($body)) ?: [] as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                if ($line === self::REPOSITORY_LINE) {
                    $steps[] = ['kind' => 'repository', 'text' => json_encode(self::pathRepository($candidateDir), JSON_UNESCAPED_SLASHES) ?: '', 'readme' => $line];
                    $repository = true;
                } elseif ($line === self::REQUIRE_LINE) {
                    $steps[] = ['kind' => 'command', 'text' => self::CANDIDATE_REQUIRE, 'readme' => $line];
                    $require = true;
                } else {
                    $steps[] = ['kind' => 'command', 'text' => $line, 'readme' => null];
                }
            }
        }

        if (! $repository || ! $require) {
            throw new VerifyFailure(self::README.': the installation lacks the line "'.self::REPOSITORY_LINE.'" or the line "'.self::REQUIRE_LINE.'"');
        }

        return $steps;
    }

    /** @return array{type: string, url: string, options: array<string, mixed>} */
    public static function pathRepository(string $candidateDir): array
    {
        return ['type' => 'path', 'url' => $candidateDir, 'options' => ['symlink' => false, 'versions' => ['justrau/laravel-orrery' => 'dev-candidate']]];
    }

    /** The middleware class of "## Content Security Policy" in docs/security.md, and its config line. @return array{class: string, config: string} */
    public static function csp(string $security): array
    {
        $section = self::section($security, self::SECURITY, '## Content Security Policy', ['## ']);
        $class = null;
        $config = null;

        foreach (self::blocks($section) as [$lang, $body]) {
            if ($lang === 'php' && str_contains($body, 'final class OrreryContentSecurityPolicy')) {
                $class = "<?php\n\n".trim($body)."\n";
            } elseif ($lang === 'php' && preg_match("/^'middleware' => .+,$/m", $body, $m) === 1) {
                $config = $m[0];
            }
        }

        if ($class === null || $config === null) {
            throw new VerifyFailure(self::SECURITY.': the Content Security Policy section lacks the middleware class or its config line');
        }

        return ['class' => $class, 'config' => $config];
    }

    /**
     * The commands of "## Removing Orrery" in docs/upgrading.md: the package reset (step 2), the
     * jobs index reset (step 3) and the composer remove (step 7).
     *
     * @return array{reset: string, index_reset: string, remove: string}
     */
    public static function removal(string $upgrading): array
    {
        $section = self::section($upgrading, self::UPGRADING, '## Removing Orrery', ['## ']);
        $reset = null;

        foreach (self::blocks($section) as [$lang, $body]) {
            if ($lang === 'bash' && str_contains($body, 'migrate:reset')) {
                $reset = trim($body);
            }
        }

        preg_match('/`(php artisan migrate:reset --path=database\/migrations\/[^`]*jobs_index\.php)`/', $section, $index);
        preg_match('/`(composer remove justrau\/laravel-orrery)`/', $section, $remove);

        if ($reset === null || ! isset($index[1], $remove[1])) {
            throw new VerifyFailure(self::UPGRADING.': the removal section lacks the reset, the jobs index reset or the composer remove command');
        }

        return ['reset' => $reset, 'index_reset' => $index[1], 'remove' => $remove[1]];
    }

    /** The text after $heading up to the first of the $next headings. @param  list<string>  $next */
    private static function section(string $text, string $file, string $heading, array $next): string
    {
        $start = strpos($text, "\n".$heading."\n");

        if ($start === false) {
            throw new VerifyFailure("{$file}: no section \"{$heading}\"");
        }

        $body = substr($text, $start + strlen($heading) + 2);
        $ends = array_filter(array_map(static fn (string $n): int|false => strpos($body, "\n".$n), $next), static fn (int|false $e): bool => $e !== false);

        return $ends === [] ? $body : substr($body, 0, min($ends));
    }

    /** @return list<array{0: string, 1: string}> */
    private static function blocks(string $text): array
    {
        preg_match_all('/^[ \t]*```([a-z]*)\R(.*?)^[ \t]*```/ms', $text, $matches, PREG_SET_ORDER);

        return array_map(static function (array $m): array {
            // Remove the indentation of a block inside a list item.
            $lines = preg_split('/\R/', $m[2]) ?: [];
            $indent = PHP_INT_MAX;

            foreach ($lines as $line) {
                if (trim($line) !== '') {
                    $indent = min($indent, strlen($line) - strlen(ltrim($line, ' ')));
                }
            }

            $indent = $indent === PHP_INT_MAX ? 0 : $indent;

            return [$m[1], implode("\n", array_map(static fn (string $l): string => substr($l, min($indent, strlen($l) - strlen(ltrim($l, ' ')))), $lines))];
        }, $matches);
    }
}

/** The export check of the candidate (without the paths that do not exist yet). */
final class Export
{
    public const REQUIRED = [
        'src', 'config', 'routes', 'database/migrations/2026_09_23_000000_create_orrery_tables.php', 'database/optional',
        'resources/views', 'stubs', 'dist/app.js', 'dist/app.css', 'dist/THIRD_PARTY_NOTICES.txt',
        'composer.json', 'LICENSE', 'NOTICE.md', 'README.md', 'CHANGELOG.md',
    ];

    /** @return list<string> the required paths that the export lacks */
    public static function missing(string $dir): array
    {
        return array_values(array_filter(self::REQUIRED, static fn (string $path): bool => ! file_exists($dir.'/'.$path)));
    }

    /** A content hash of every file below $dir, by relative path. */
    public static function hash(string $dir): string
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $files[substr($file->getPathname(), strlen($dir) + 1)] = hash_file('sha256', $file->getPathname());
            }
        }

        ksort($files, SORT_STRING);

        return hash('sha256', json_encode($files) ?: '');
    }
}

/** The plan that --dry-run prints. */
final class Plan
{
    /**
     * @param  list<array{kind: string, text: string, readme: ?string}>  $install
     * @param  array{reset: string, index_reset: string, remove: string}  $removal
     * @return list<string>
     */
    public static function lines(Options $options, array $install, array $removal): array
    {
        $lines = ['Candidate: git archive HEAD of a clean tree, export check ('.implode(', ', Export::REQUIRED).')'];

        if ($options->selfTest) {
            $lines[] = 'Self-test on l13-latest: each broken candidate must fail with its reason';

            foreach (SelfTest::CONTROLS as $id => $control) {
                $lines[] = "  {$id}: {$control['break']}; expected reason /{$control['reason']}/";
            }

            return $lines;
        }

        foreach ($options->apps as $id) {
            $app = Apps::all()[$id];
            $php = $options->php[$app['php']];
            $lines[] = "App {$id}: composer create-project {$app['skeleton']} on PHP {$app['php']} ({$php})"
                .($app['framework'] !== null ? ", then composer require laravel/framework:{$app['framework']} --with-all-dependencies (exact)" : '');
        }

        $lines[] = 'Setup: .env (SQLite file, database queue and cache, database-uuids, file sessions, array mail), SQLite WAL, busy_timeout 5000, IMMEDIATE';
        $lines[] = 'Install (README "Installation", one substitution):';

        foreach ($install as $step) {
            if ($step['readme'] !== null) {
                $lines[] = '  [substituted] '.($step['kind'] === 'repository' ? 'repositories: '.$step['text'] : $step['text']).'  (README: '.str_replace("\n", ' ', (string) preg_replace('/\s+/', ' ', $step['readme'])).')';
            } else {
                $lines[] = '  '.$step['text'];
            }
        }

        $lines[] = 'Checks: vendor copy (not a symlink) equals the export, no dev autoload, the migration published and migrated';
        $lines[] = 'l13-latest: php artisan vendor:publish --tag=orrery-jobs-index, php artisan migrate';
        $lines[] = 'Fixtures: the CSP middleware of docs/security.md in orrery.middleware, gates for viewer and operator, a login route, users, two jobs';
        $lines[] = 'Production: APP_ENV=production, config:cache, route:cache; php -S on an owned port; an owned queue:work';
        $lines[] = 'Flows: access (guest 401, denied 403, viewer write 403); a job that succeeds and one that fails;';
        $lines[] = '  POST /api/jobs/{uuid}/retry and /forget as the operator (operations done, failed record gone); schedule:run (tick, samples)';
        $lines[] = '  GET /api/health without warnings; tools/browser/smoke.mjs over every screen under the enforced CSP';
        $lines[] = 'Removal (docs/upgrading.md "Removing Orrery"):';
        $lines[] = '  php artisan orrery:resolve --all';
        $lines[] = '  '.$removal['reset'];
        $lines[] = '  l13-latest: '.$removal['index_reset'].', and delete it';
        $lines[] = '  delete the published migration, the provider, config/orrery.php and the sibling files';
        $lines[] = '  '.$removal['remove'];
        $lines[] = 'After: no package row in migrations, the jobs index gone, php artisan list and php artisan migrate:fresh exit 0';

        return $lines;
    }
}

/** The negative controls of --self-test. */
final class SelfTest
{
    public const CONTROLS = [
        'dist-removed' => ['break' => 'remove dist/', 'remove' => 'dist', 'reason' => 'dist|dashboard page'],
        'migration-removed' => ['break' => 'remove the main migration', 'remove' => 'database/migrations/2026_09_23_000000_create_orrery_tables.php', 'reason' => 'migration'],
    ];
}

/** Owned child processes and directories, cleaned up on every exit path. */
final class Owned
{
    /** @var array<int, resource> pid => proc handle */
    private array $processes = [];

    /** @var list<string> */
    private array $dirs = [];

    /** @param resource $proc */
    public function process($proc): int
    {
        $pid = proc_get_status($proc)['pid'];
        $this->processes[$pid] = $proc;

        return $pid;
    }

    public function stop(int $pid): void
    {
        $proc = $this->processes[$pid] ?? null;

        if ($proc === null) {
            return;
        }

        if (proc_get_status($proc)['running']) {
            proc_terminate($proc, 15);
            $deadline = microtime(true) + 10;

            while (proc_get_status($proc)['running'] && microtime(true) < $deadline) {
                usleep(100_000);
            }

            if (proc_get_status($proc)['running']) {
                proc_terminate($proc, 9);
                usleep(200_000);
            }
        }

        proc_close($proc);
        unset($this->processes[$pid]);
    }

    public function dir(string $dir): string
    {
        if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
            throw new RuntimeException("cannot create {$dir}");
        }

        $this->dirs[] = $dir;

        return $dir;
    }

    public function cleanup(bool $keepDirs): void
    {
        foreach (array_keys($this->processes) as $pid) {
            $this->stop($pid);
        }

        if (! $keepDirs) {
            foreach (array_reverse($this->dirs) as $dir) {
                Shell::removeTree($dir);
            }
        }

        $this->dirs = [];
    }
}

final class Shell
{
    public static function removeTree(string $dir): void
    {
        if (! file_exists($dir) && ! is_link($dir)) {
            return;
        }

        if (is_link($dir) || is_file($dir)) {
            unlink($dir);

            return;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dir);
    }

    public static function copyTree(string $from, string $to): void
    {
        $code = self::exec(['cp', '-R', $from, $to], null, [])['code'];

        if ($code !== 0) {
            throw new RuntimeException("cp -R {$from} {$to} failed");
        }
    }

    /**
     * Runs a command (no shell) and returns its exit code and output.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     * @return array{code: int, out: string}
     */
    public static function exec(array $command, ?string $cwd, array $env, int $timeout = 900): array
    {
        $out = tempnam(sys_get_temp_dir(), 'orrery-verify-out-');
        $proc = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out, 'w']], $pipes, $cwd, $env === [] ? null : $env);

        if (! is_resource($proc)) {
            throw new RuntimeException('cannot start '.$command[0]);
        }

        $deadline = microtime(true) + $timeout;

        try {
            while (($status = proc_get_status($proc))['running']) {
                if (microtime(true) > $deadline) {
                    proc_terminate($proc, 9);
                    proc_close($proc);

                    throw new VerifyFailure('timed out after '.$timeout.' s: '.implode(' ', $command));
                }

                usleep(50_000);
            }
        } catch (Interrupted $interrupted) {
            proc_terminate($proc, 15);
            proc_close($proc);
            @unlink($out);

            throw $interrupted;
        }

        proc_close($proc);
        $output = (string) file_get_contents($out);
        unlink($out);

        return ['code' => $status['exitcode'], 'out' => $output];
    }
}

/** A cookie session against the app's server (one per user). */
final class Http
{
    /** @var \CurlHandle */
    private $curl;

    public function __construct(private readonly string $base)
    {
        $this->curl = curl_init();
        curl_setopt_array($this->curl, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60]);
    }

    /** @return array{status: int, headers: string, body: string, json: mixed} */
    public function request(string $method, string $path, ?array $json = null): array
    {
        // As the SPA: every write needs X-Requested-With (Authenticate).
        $headers = ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'];

        foreach (curl_getinfo($this->curl, CURLINFO_COOKIELIST) ?: [] as $line) {
            $parts = explode("\t", (string) $line);

            if (($parts[5] ?? '') === 'XSRF-TOKEN') {
                $headers[] = 'X-XSRF-TOKEN: '.urldecode($parts[6] ?? '');
            }
        }

        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($this->curl, [
            CURLOPT_URL => $this->base.$path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $json === null ? null : json_encode($json),
            CURLOPT_HTTPGET => $method === 'GET',
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($method !== 'GET') {
            curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, $json === null ? '' : json_encode($json));
        }

        $response = curl_exec($this->curl);

        if ($response === false) {
            throw new VerifyFailure("{$method} {$path}: ".curl_error($this->curl));
        }

        $size = curl_getinfo($this->curl, CURLINFO_HEADER_SIZE);
        $body = substr((string) $response, $size);

        return ['status' => curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE), 'headers' => substr((string) $response, 0, $size), 'body' => $body, 'json' => json_decode($body, true)];
    }
}

final class Runner
{
    private Owned $owned;

    private string $runId;

    private string $evidence;

    private string $work;

    /** @var list<string> */
    private array $summary = [];

    public function __construct(private readonly Options $options, private readonly string $repo, private readonly array $env)
    {
        $this->owned = new Owned;
        $this->runId = gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
        $this->evidence = $options->evidence ?? $repo.'/.verify/'.$this->runId;
    }

    /** @param list<string> $argv */
    public static function main(array $argv, string $repo, array $env): int
    {
        try {
            $options = Options::parse(array_slice($argv, 1), $env);
        } catch (RuntimeException $error) {
            fwrite(STDERR, 'bin/verify-install: '.$error->getMessage()."\n\n".Options::usage()."\n");

            return 64;
        }

        if ($options->help) {
            fwrite(STDOUT, Options::usage()."\n");

            return 0;
        }

        return (new self($options, $repo, $env))->run();
    }

    public function run(): int
    {
        if ($this->options->dryRun) {
            try {
                $lines = Plan::lines($this->options, Docs::installSteps($this->doc(Docs::README), '<export of HEAD>'), Docs::removal($this->doc(Docs::UPGRADING)));
            } catch (VerifyFailure $failure) {
                fwrite(STDERR, 'bin/verify-install: '.$failure->getMessage()."\n");

                return 1;
            }

            fwrite(STDOUT, implode("\n", $lines)."\n");

            return 0;
        }

        $dirty = trim(Shell::exec(['git', '-C', $this->repo, 'status', '--porcelain', '--untracked-files=no'], null, [])['out']);

        if ($dirty !== '') {
            fwrite(STDERR, "bin/verify-install: refused: the working tree has uncommitted changes:\n{$dirty}\n");

            return 65;
        }

        pcntl_async_signals(true);
        $interrupt = static function (int $signal): void {
            throw new Interrupted('interrupted by signal '.$signal);
        };
        pcntl_signal(SIGINT, $interrupt);
        pcntl_signal(SIGTERM, $interrupt);

        $started = microtime(true);
        $code = 1;

        try {
            // The evidence directory is not owned: the logs stay after the run.
            if (! is_dir($this->evidence) && ! mkdir($this->evidence, 0700, true)) {
                throw new RuntimeException('cannot create '.$this->evidence);
            }

            $this->work = $this->owned->dir(sys_get_temp_dir().'/orrery-verify-'.$this->runId);
            $this->work = (string) realpath($this->work);
            $this->say('run '.$this->runId.'; logs in '.$this->evidence.'; work in '.$this->work);
            $code = $this->options->selfTest ? $this->selfTest() : $this->verify();
        } catch (Interrupted $interrupted) {
            $this->say($interrupted->getMessage());
            $code = 130;
        } catch (VerifyFailure $failure) {
            $this->summary[] = 'FAIL: '.$failure->getMessage();
            $code = 1;
        } catch (Throwable $error) {
            $this->say('internal error: '.$error->getMessage().' at '.$error->getFile().':'.$error->getLine());
            $code = 1;
        } finally {
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_signal(SIGTERM, SIG_DFL);
            $this->owned->cleanup($this->options->keep);
        }

        $this->summary[] = sprintf('duration %.0f s; exit %d', microtime(true) - $started, $code);
        $text = implode("\n", $this->summary)."\n";
        @file_put_contents($this->evidence.'/summary.txt', $text);
        fwrite(STDOUT, "\n".$text);

        return $code;
    }

    private function verify(): int
    {
        $candidate = $this->candidate();
        $failed = 0;

        foreach ($this->options->apps as $id) {
            $started = microtime(true);

            try {
                $base = $this->base($id);
                $this->app($id, $base, $candidate, 'main');
                $this->summary[] = sprintf('PASS %s (%.0f s)', $id, microtime(true) - $started);
            } catch (VerifyFailure $failure) {
                $failed++;
                $this->summary[] = sprintf('FAIL %s (%.0f s): %s', $id, microtime(true) - $started, $failure->getMessage());
            }

            $this->say(end($this->summary) ?: '');
        }

        return $failed === 0 ? 0 : 1;
    }

    private function selfTest(): int
    {
        $candidate = $this->candidate();
        $base = $this->base('l13-latest');
        $wrong = 0;

        foreach (SelfTest::CONTROLS as $control => $definition) {
            $broken = $this->work.'/candidate-'.$control;
            Shell::copyTree($candidate, $broken);
            Shell::removeTree($broken.'/'.$definition['remove']);
            $missing = Export::missing($broken);

            if ($missing === []) {
                $wrong++;
                $this->summary[] = "WRONG {$control}: the export check did not see it";
            } else {
                $this->summary[] = "ok {$control}: the export check reports ".implode(', ', $missing);
            }

            // The app flow without the export check must fail too, with its reason.
            try {
                $this->app('l13-latest', $base, $broken, $control);
                $wrong++;
                $this->summary[] = "WRONG {$control}: the app verification passed";
            } catch (VerifyFailure $failure) {
                if (preg_match('/'.$definition['reason'].'/i', $failure->getMessage()) === 1) {
                    $this->summary[] = "ok {$control}: the app verification failed: ".$failure->getMessage();
                } else {
                    $wrong++;
                    $this->summary[] = "WRONG {$control}: failed with another reason: ".$failure->getMessage();
                }
            }

            $this->say(end($this->summary) ?: '');
        }

        return $wrong === 0 ? 0 : 1;
    }

    /** A file of HEAD (the export holds the README but not docs/). */
    private function doc(string $path): string
    {
        $text = (string) Shell::exec(['git', '-C', $this->repo, 'show', 'HEAD:'.$path], null, [])['out'];

        if ($text === '') {
            throw new VerifyFailure("HEAD has no {$path}");
        }

        return $text;
    }

    /** The export of HEAD, checked. */
    private function candidate(): string
    {
        $head = trim(Shell::exec(['git', '-C', $this->repo, 'rev-parse', 'HEAD'], null, [])['out']);
        $dir = $this->work.'/candidate';
        mkdir($dir, 0700);
        $tar = $this->work.'/candidate.tar';
        $this->must(['git', '-C', $this->repo, 'archive', '--format=tar', '--output='.$tar, 'HEAD'], null, [], 'git archive');
        $this->must(['tar', '-xf', $tar, '-C', $dir], null, [], 'tar');
        unlink($tar);
        $missing = Export::missing($dir);

        if ($missing !== []) {
            throw new VerifyFailure('the export lacks '.implode(', ', $missing));
        }

        $hash = Export::hash($dir);
        $this->summary[] = "candidate HEAD {$head}, export hash {$hash}";
        $this->say(end($this->summary) ?: '');

        return $dir;
    }

    /** A fresh skeleton of the app (made once per run), at the exact framework version. */
    private function base(string $id): string
    {
        $app = Apps::all()[$id];
        $dir = $this->work.'/base-'.$id;

        if (is_dir($dir)) {
            return $dir;
        }

        $php = $this->php($id);
        $this->step($id, "composer create-project {$app['skeleton']}");
        $this->must([$php, $this->composer(), 'create-project', $app['skeleton'], $dir, '--no-interaction', '--prefer-dist'], $this->work, $this->childEnv(), "{$id}: create-project", 1200);

        if ($app['framework'] !== null) {
            $this->step($id, "composer require laravel/framework:{$app['framework']}");
            $this->must([$php, $this->composer(), 'require', 'laravel/framework:'.$app['framework'], '--with-all-dependencies', '--no-interaction'], $dir, $this->childEnv(), "{$id}: require the framework floor", 1200);
        }

        $versions = $this->must([$php, 'artisan', '--version'], $dir, $this->childEnv(), "{$id}: artisan --version");
        $sqlite = $this->must([$php, '-r', 'echo (new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn(), " ", PHP_VERSION;'], $dir, $this->childEnv(), 'versions');
        $framework = self::installedVersion($dir, 'laravel/framework');

        if ($app['framework'] !== null && ltrim($framework, 'v') !== $app['framework']) {
            throw new VerifyFailure("{$id}: laravel/framework resolved to {$framework}, not {$app['framework']}");
        }

        $this->summary[] = "{$id}: ".trim($versions).", laravel/framework {$framework}, SQLite and PHP ".trim($sqlite);
        $this->say(end($this->summary) ?: '');

        return $dir;
    }

    /** Installs the candidate into a copy of the base and runs every flow. */
    private function app(string $id, string $base, string $candidate, string $scenario): void
    {
        $app = Apps::all()[$id];
        $php = $this->php($id);
        $dir = $this->work.'/app-'.$id.'-'.$scenario;
        Shell::copyTree($base, $dir);
        $env = $this->childEnv();
        $readme = (string) file_get_contents($candidate.'/README.md');
        $steps = Docs::installSteps($readme, $candidate);

        // The "The queue" and "SQLite" settings of docs/installation.md.
        $this->writeEnv($dir, 'local');
        $this->edit($dir.'/config/database.php', [
            "'busy_timeout' => null," => "'busy_timeout' => 5000,",
            "'journal_mode' => null," => "'journal_mode' => 'wal',",
            "'transaction_mode' => 'DEFERRED'," => "'transaction_mode' => 'IMMEDIATE',",
        ], "{$id}: the sqlite connection of config/database.php");

        foreach ($steps as $step) {
            if ($step['kind'] === 'repository') {
                $this->step($id, 'repository (substituted): path '.$candidate);
                $composer = json_decode((string) file_get_contents($dir.'/composer.json'), true);
                $composer['repositories'] = [Docs::pathRepository($candidate)];
                file_put_contents($dir.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

                continue;
            }

            $this->step($id, $step['text'].($step['readme'] !== null ? ' (substituted)' : ''));
            $this->must($this->command($step['text'], $php), $dir, $env, "{$id}: {$step['text']}", 1200);
        }

        $this->checkInstall($id, $dir, $candidate);

        if ($app['jobs_index']) {
            $this->step($id, 'php artisan vendor:publish --tag=orrery-jobs-index; php artisan migrate');
            $this->must([$php, 'artisan', 'vendor:publish', '--tag=orrery-jobs-index'], $dir, $env, "{$id}: publish the jobs index");
            $this->must([$php, 'artisan', 'migrate'], $dir, $env, "{$id}: migrate the jobs index");

            if ($this->query($dir.'/database/database.sqlite', "select count(*) from sqlite_master where type = 'index' and name like '%orrery_backlog'") !== 1) {
                throw new VerifyFailure("{$id}: the optional jobs index was not created");
            }
        }

        $this->fixtures($dir, $this->doc(Docs::SECURITY));
        $this->writeEnv($dir, 'production');
        $this->must([$php, 'artisan', 'verify:users'], $dir, $env, "{$id}: the fixture users");
        $this->must([$php, 'artisan', 'config:cache'], $dir, $env, "{$id}: config:cache");
        $this->must([$php, 'artisan', 'route:cache'], $dir, $env, "{$id}: route:cache");

        $server = null;
        $worker = null;

        try {
            [$server, $url] = $this->serve($id, $dir, $php, $env);
            $this->flows($id, $dir, $php, $env, $url, $worker);
        } finally {
            if ($worker !== null) {
                $this->owned->stop($worker);
            }

            if ($server !== null) {
                $this->owned->stop($server);
            }

            if (is_file($dir.'/storage/logs/laravel.log')) {
                @copy($dir.'/storage/logs/laravel.log', $this->evidence."/{$id}-{$scenario}-laravel.log");
            }
        }

        $this->removal($id, $dir, $php, $env, $this->doc(Docs::UPGRADING), $app['jobs_index']);
    }

    private function checkInstall(string $id, string $dir, string $candidate): void
    {
        $installed = $dir.'/vendor/justrau/laravel-orrery';

        if (is_link($installed) || ! is_dir($installed)) {
            throw new VerifyFailure("{$id}: vendor/justrau/laravel-orrery is not a copied directory");
        }

        if (Export::hash($installed) !== Export::hash($candidate)) {
            throw new VerifyFailure("{$id}: vendor/justrau/laravel-orrery differs from the export");
        }

        $psr4 = (string) file_get_contents($dir.'/vendor/composer/autoload_psr4.php');

        foreach (['Workbench\\\\', 'Justrau\\\\Orrery\\\\Tests\\\\'] as $namespace) {
            if (str_contains($psr4, "'".$namespace)) {
                throw new VerifyFailure("{$id}: the development autoload {$namespace} is installed");
            }
        }

        foreach (['orchestra/testbench', 'phpunit/phpunit', 'larastan/larastan'] as $dev) {
            if (self::installedVersion($dir, $dev) !== '' && ! str_contains((string) file_get_contents($dir.'/composer.json'), '"'.$dev.'"')) {
                throw new VerifyFailure("{$id}: the package's development dependency {$dev} is installed");
            }
        }

        $published = glob($dir.'/database/migrations/*_create_orrery_tables.php') ?: [];

        if ($published === []) {
            throw new VerifyFailure("{$id}: orrery:install published no package migration (database/migrations/*_create_orrery_tables.php)");
        }

        if ($this->query($dir.'/database/database.sqlite', "select count(*) from migrations where migration like '%_create_orrery_tables'") !== 1) {
            throw new VerifyFailure("{$id}: migrate did not record the package migration");
        }

        foreach (['config/orrery.php', 'app/Providers/OrreryServiceProvider.php'] as $file) {
            if (! is_file($dir.'/'.$file)) {
                throw new VerifyFailure("{$id}: orrery:install did not publish {$file}");
            }
        }

        if (! str_contains((string) file_get_contents($dir.'/bootstrap/providers.php'), 'OrreryServiceProvider')) {
            throw new VerifyFailure("{$id}: orrery:install did not add the provider to bootstrap/providers.php");
        }
    }

    private function fixtures(string $dir, string $security): void
    {
        $csp = Docs::csp($security);
        @mkdir($dir.'/app/Http/Middleware', 0755, true);
        file_put_contents($dir.'/app/Http/Middleware/OrreryContentSecurityPolicy.php', $csp['class']);
        $this->edit($dir.'/config/orrery.php', ["'middleware' => ['web']," => $csp['config']], 'the middleware key of config/orrery.php');

        $provider = (string) file_get_contents($dir.'/app/Providers/OrreryServiceProvider.php');
        $provider = (string) preg_replace("~// 'admin@example.com',~", "'viewer@verify.test', 'operator@verify.test',", $provider, 1, $first);
        $provider = (string) preg_replace("~// 'admin@example.com',~", "'operator@verify.test',", $provider, 1, $second);

        if ($first !== 1 || $second !== 1) {
            throw new VerifyFailure('the published provider has not the two gate lists of the stub');
        }

        file_put_contents($dir.'/app/Providers/OrreryServiceProvider.php', $provider);

        @mkdir($dir.'/app/Jobs', 0755, true);

        foreach (['VerifySucceeds' => "logger('verify: succeeded');", 'VerifyFails' => "throw new \\RuntimeException('verify: this job fails on purpose');"] as $class => $body) {
            file_put_contents($dir."/app/Jobs/{$class}.php", <<<PHP
                <?php

                namespace App\\Jobs;

                use Illuminate\\Contracts\\Queue\\ShouldQueue;
                use Illuminate\\Foundation\\Queue\\Queueable;

                class {$class} implements ShouldQueue
                {
                    use Queueable;

                    public int \$tries = 1;

                    public function handle(): void
                    {
                        {$body}
                    }
                }

                PHP);
        }

        file_put_contents($dir.'/routes/web.php', <<<'PHP'

            // bin/verify-install fixture: signs a fixture user in.
            Route::get('/verify-login/{email}', function (string $email) {
                \Illuminate\Support\Facades\Auth::login(\App\Models\User::query()->where('email', $email)->firstOrFail());
                request()->session()->regenerate();

                return response('signed in');
            })->middleware('web');

            PHP, FILE_APPEND);

        file_put_contents($dir.'/routes/console.php', <<<'PHP'

            // bin/verify-install fixtures.
            Artisan::command('verify:users', function () {
                foreach (['viewer', 'operator', 'denied'] as $name) {
                    \App\Models\User::query()->updateOrCreate(['email' => "{$name}@verify.test"], ['name' => $name, 'password' => bcrypt(\Illuminate\Support\Str::random(32))]);
                }
            });

            Artisan::command('verify:dispatch', function () {
                dispatch(new \App\Jobs\VerifySucceeds);
                dispatch(new \App\Jobs\VerifyFails);
            });

            PHP, FILE_APPEND);
    }

    /** @return array{0: int, 1: string} the server pid and its base URL */
    private function serve(string $id, string $dir, string $php, array $env): array
    {
        $port = self::freePort();
        $router = $dir.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
        $log = $this->evidence."/{$id}-server.log";
        $proc = proc_open([$php, '-S', '127.0.0.1:'.$port, '-t', $dir.'/public', $router], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $dir.'/public', $env);

        if (! is_resource($proc)) {
            throw new VerifyFailure("{$id}: cannot start php -S");
        }

        $pid = $this->owned->process($proc);
        $url = 'http://127.0.0.1:'.$port;
        $this->step($id, "php -S on {$url} (pid {$pid})");
        $this->waitFor(static function () use ($url): bool {
            $socket = @fsockopen('127.0.0.1', (int) parse_url($url, PHP_URL_PORT), $errno, $error, 0.5);

            if ($socket === false) {
                return false;
            }

            fclose($socket);

            return true;
        }, 20, "{$id}: the server did not start");

        return [$pid, $url];
    }

    private function flows(string $id, string $dir, string $php, array $env, string $url, ?int &$worker): void
    {
        $path = '/orrery';
        $operator = new Http($url);
        $operator->request('GET', '/verify-login/operator@verify.test');

        $this->step($id, 'the dashboard page under the CSP');
        $page = $operator->request('GET', $path);

        if ($page['status'] !== 200 || ! str_contains($page['body'], '<script nonce=')) {
            throw new VerifyFailure("{$id}: the dashboard page answered {$page['status']} without its inlined script (dist/)");
        }

        if (preg_match('/^content-security-policy: (.*)$/mi', $page['headers'], $policy) !== 1 || str_contains($policy[1], 'unsafe-inline')) {
            throw new VerifyFailure("{$id}: the dashboard page has no strict Content-Security-Policy");
        }

        $this->step($id, 'access: guest 401, denied 403, viewer write 403');
        $guest = (new Http($url))->request('GET', $path.'/api/health');
        $denied = new Http($url);
        $denied->request('GET', '/verify-login/denied@verify.test');
        $deniedRead = $denied->request('GET', $path.'/api/health');
        $viewer = new Http($url);
        $viewer->request('GET', '/verify-login/viewer@verify.test');
        $viewerRead = $viewer->request('GET', $path.'/api/health');
        $viewerWrite = $viewer->request('POST', $path.'/api/control', ['action' => 'pause', 'scope' => 'global']);

        foreach ([['guest read', $guest, 401], ['denied read', $deniedRead, 403], ['viewer read', $viewerRead, 200], ['viewer write', $viewerWrite, 403]] as [$what, $response, $status]) {
            if ($response['status'] !== $status) {
                throw new VerifyFailure("{$id}: {$what} answered {$response['status']}, not {$status}");
            }
        }

        $this->step($id, 'queue:work and two jobs');
        $log = $this->evidence."/{$id}-worker.log";
        $proc = proc_open([$php, 'artisan', 'queue:work', 'database', '--queue=default', '--sleep=1'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $dir, $env);

        if (! is_resource($proc)) {
            throw new VerifyFailure("{$id}: cannot start queue:work");
        }

        $worker = $this->owned->process($proc);
        $this->must([$php, 'artisan', 'verify:dispatch'], $dir, $env, "{$id}: dispatch the jobs");
        $mirror = $dir.'/database/database.orrery.sqlite';
        $host = $dir.'/database/database.sqlite';
        $row = fn (string $class): ?array => $this->row($mirror, 'select uuid, status, cycle, occurrence_id from orrery_jobs where name = ?', [$class]);
        $this->waitFor(fn (): bool => ($row('App\Jobs\VerifySucceeds')['status'] ?? null) === 'completed', 60, "{$id}: the succeeding job did not complete in the mirror");
        $this->waitFor(fn (): bool => ($row('App\Jobs\VerifyFails')['status'] ?? null) === 'failed', 60, "{$id}: the failing job did not fail in the mirror");
        $failed = $row('App\Jobs\VerifyFails');
        $uuid = (string) $failed['uuid'];

        if ($this->query($host, 'select count(*) from failed_jobs where uuid = ?', [$uuid]) !== 1) {
            throw new VerifyFailure("{$id}: failed_jobs has no record of the failed job");
        }

        $this->step($id, "retry {$uuid} through the API");
        $retry = $operator->request('POST', $path."/api/jobs/{$uuid}/retry", ['occurrence_id' => (int) $failed['occurrence_id']]);

        if ($retry['status'] !== 200 || ($retry['json']['result'] ?? null) !== 'accepted') {
            throw new VerifyFailure("{$id}: the retry answered {$retry['status']} ".substr($retry['body'], 0, 300));
        }

        $this->waitFor(function () use ($row, $mirror, $uuid, $php, $dir, $env): bool {
            $now = $row('App\Jobs\VerifyFails');
            $done = $this->query($mirror, "select count(*) from orrery_operations where job_uuid = ? and action = 'retry' and state = 'done'", [$uuid]) === 1;

            if (! $done) {
                Shell::exec([$php, 'artisan', 'schedule:run'], $dir, $env, 120);
            }

            return $done && ($now['status'] ?? null) === 'failed' && (int) ($now['cycle'] ?? 0) >= 2;
        }, 120, "{$id}: the retry did not finish (operation done, a second failed cycle)");

        $failed = $row('App\Jobs\VerifyFails');

        if ($this->query($host, 'select count(*) from failed_jobs where uuid = ?', [$uuid]) !== 1) {
            throw new VerifyFailure("{$id}: after the retry, failed_jobs does not hold exactly the new record");
        }

        $this->step($id, "forget {$uuid} through the API");
        $forget = $operator->request('POST', $path."/api/jobs/{$uuid}/forget", ['occurrence_id' => (int) $failed['occurrence_id']]);

        if ($forget['status'] !== 200 || ($forget['json']['result'] ?? null) !== 'deleted') {
            throw new VerifyFailure("{$id}: the forget answered {$forget['status']} ".substr($forget['body'], 0, 300));
        }

        $this->waitFor(fn (): bool => $this->query($mirror, "select count(*) from orrery_operations where job_uuid = ? and action = 'forget' and state = 'done'", [$uuid]) === 1
            && $this->query($host, 'select count(*) from failed_jobs where uuid = ?', [$uuid]) === 0
            && $row('App\Jobs\VerifyFails') === null, 60, "{$id}: the forget did not finish (operation done, the record and the mirror row gone)");

        $this->step($id, 'schedule:run (the tick)');
        $this->waitFor(function () use ($mirror, $php, $dir, $env): bool {
            Shell::exec([$php, 'artisan', 'schedule:run'], $dir, $env, 120);

            return $this->query($mirror, "select count(*) from orrery_settings where key = 'observer_tick_at'") === 1
                && $this->query($mirror, "select count(*) from orrery_samples where scope = 'total'") > 0;
        }, 120, "{$id}: the tick wrote no observer_tick_at or no samples");

        $this->step($id, '/api/health');
        $health = $operator->request('GET', $path.'/api/health');
        file_put_contents($this->evidence."/{$id}-health.json", $health['body']);
        $warnings = $health['status'] === 200 && is_array($health['json']) ? Health::warnings($health['json']) : ["status {$health['status']}"];

        if ($warnings !== []) {
            throw new VerifyFailure("{$id}: /api/health warns: ".implode('; ', $warnings));
        }

        $this->step($id, 'browser smoke (tools/browser/smoke.mjs)');
        $tools = $this->repo.'/tools/browser';

        if (! is_dir($tools.'/node_modules/playwright-core')) {
            $this->must([$this->options->pnpm, 'install', '--frozen-lockfile'], $tools, $this->childEnv(), 'pnpm install in tools/browser');
        }

        $smoke = Shell::exec([$this->options->node, $tools.'/smoke.mjs', '--base='.$url, '--login=/verify-login/operator@verify.test', '--path=orrery', '--expect-text=VerifySucceeds', '--screenshots='.$this->evidence."/{$id}-screens"], $tools, $this->childEnv() + ['PW_EXE' => $this->options->chrome], 300);
        file_put_contents($this->evidence."/{$id}-smoke.json", $smoke['out']);

        if ($smoke['code'] !== 0) {
            $result = json_decode(substr($smoke['out'], (int) strpos($smoke['out'], '{')), true);
            throw new VerifyFailure("{$id}: the browser smoke failed: ".(is_array($result) ? implode('; ', array_slice($result['problems'], 0, 5)) : trim($smoke['out'])));
        }
    }

    private function removal(string $id, string $dir, string $php, array $env, string $upgrading, bool $jobsIndex): void
    {
        $commands = Docs::removal($upgrading);
        $this->writeEnv($dir, 'local');
        $this->must([$php, 'artisan', 'config:clear'], $dir, $env, "{$id}: config:clear");
        $this->must([$php, 'artisan', 'route:clear'], $dir, $env, "{$id}: route:clear");

        $this->step($id, 'removal 1: php artisan orrery:resolve --all');
        $this->must([$php, 'artisan', 'orrery:resolve', '--all'], $dir, $env, "{$id}: orrery:resolve --all");
        $this->step($id, 'removal 2: '.$commands['reset']);
        $this->must($this->command($commands['reset'], $php), $dir, $env, "{$id}: {$commands['reset']}");

        if ($jobsIndex) {
            // docs/upgrading.md names the published copy by its time prefix (`<date>`): the one file that vendor:publish wrote.
            $published = glob($dir.'/database/migrations/*_add_orrery_jobs_index.php') ?: [];

            if (count($published) !== 1) {
                throw new VerifyFailure("{$id}: expected one published jobs index migration, found ".count($published));
            }

            $indexReset = str_replace('<date>_add_orrery_jobs_index.php', basename($published[0]), $commands['index_reset']);
            $this->step($id, 'removal 3: '.$indexReset);
            $this->must($this->command($indexReset, $php), $dir, $env, "{$id}: {$indexReset}");

            foreach (glob($dir.'/database/migrations/*_add_orrery_jobs_index.php') ?: [] as $file) {
                unlink($file);
            }

            if ($this->query($dir.'/database/database.sqlite', "select count(*) from sqlite_master where type = 'index' and name like '%orrery_backlog'") !== 0) {
                throw new VerifyFailure("{$id}: the jobs index is still there after its reset");
            }
        }

        $this->step($id, 'removal 4-6: delete the migration, the provider, the config and the sibling');

        foreach (glob($dir.'/database/migrations/*_create_orrery_tables.php') ?: [] as $file) {
            unlink($file);
        }

        $this->edit($dir.'/bootstrap/providers.php', ["    App\\Providers\\OrreryServiceProvider::class,\n" => ''], "{$id}: the provider line of bootstrap/providers.php");
        unlink($dir.'/app/Providers/OrreryServiceProvider.php');
        unlink($dir.'/config/orrery.php');

        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($dir.'/database/database.orrery.sqlite'.$suffix);
        }

        $rows = $this->query($dir.'/database/database.sqlite', "select count(*) from migrations where migration like '%create_orrery_tables' or migration like '%orrery_jobs_index'");

        if ($rows !== 0) {
            throw new VerifyFailure("{$id}: {$rows} package rows are left in the migrations table");
        }

        $this->step($id, 'removal 7: '.$commands['remove']);
        $this->must($this->command($commands['remove'], $php), $dir, $env, "{$id}: {$commands['remove']}", 1200);
        $this->must([$php, 'artisan', 'list'], $dir, $env, "{$id}: php artisan list after the removal");
        $this->must([$php, 'artisan', 'migrate:fresh'], $dir, $env, "{$id}: php artisan migrate:fresh after the removal");
    }

    /** A docs command as an argument list: `php` and `composer` become the app's binaries. @return list<string> */
    private function command(string $line, string $php): array
    {
        $words = preg_split('/\s+/', trim($line)) ?: [];

        return match ($words[0] ?? '') {
            'php' => [$php, ...array_slice($words, 1)],
            'composer' => [$php, $this->composer(), ...array_slice($words, 1), '--no-interaction'],
            default => throw new VerifyFailure("docs command not supported: {$line}"),
        };
    }

    private function writeEnv(string $dir, string $environment): void
    {
        // A whole new .env (the skeleton's is never read): the key stays the same per app.
        $keyFile = $dir.'/storage/verify-app-key';

        if (! is_file($keyFile)) {
            file_put_contents($keyFile, 'base64:'.base64_encode(random_bytes(32)));
        }

        $debug = $environment === 'local' ? 'true' : 'false';
        file_put_contents($dir.'/.env', implode("\n", [
            'APP_NAME=OrreryVerify',
            "APP_ENV={$environment}",
            'APP_KEY='.file_get_contents($keyFile),
            "APP_DEBUG={$debug}",
            'APP_URL=http://127.0.0.1',
            'LOG_CHANNEL=single',
            'DB_CONNECTION=sqlite',
            'QUEUE_CONNECTION=database',
            'QUEUE_FAILED_DRIVER=database-uuids',
            'CACHE_STORE=database',
            'SESSION_DRIVER=file',
            'MAIL_MAILER=array',
            '',
        ]));
    }

    /** @param array<string, string> $replacements */
    private function edit(string $file, array $replacements, string $what): void
    {
        $text = (string) file_get_contents($file);

        foreach ($replacements as $from => $to) {
            if (substr_count($text, $from) !== 1) {
                throw new VerifyFailure("{$what}: expected exactly one \"{$from}\"");
            }

            $text = str_replace($from, $to, $text);
        }

        file_put_contents($file, $text);
    }

    /** @param list<mixed> $bindings */
    private function query(string $file, string $sql, array $bindings = []): int
    {
        return (int) ($this->row($file, $sql, $bindings, true)[0] ?? 0);
    }

    /**
     * One row from a SQLite file, on a read-only PDO that is closed at once.
     *
     * @param  list<mixed>  $bindings
     * @return array<int|string, mixed>|null
     */
    private function row(string $file, string $sql, array $bindings = [], bool $numeric = false): ?array
    {
        if (! is_file($file)) {
            return null;
        }

        $pdo = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);

        try {
            $statement = $pdo->prepare($sql);
            $statement->execute($bindings);
            $row = $statement->fetch($numeric ? PDO::FETCH_NUM : PDO::FETCH_ASSOC);

            return $row === false ? null : $row;
        } catch (\PDOException $error) {
            if (str_contains($error->getMessage(), 'no such table')) {
                return null;
            }

            throw $error;
        } finally {
            $statement = null;
            $pdo = null;
        }
    }

    private function waitFor(callable $condition, int $seconds, string $failure): void
    {
        $deadline = microtime(true) + $seconds;

        while (! $condition()) {
            if (microtime(true) > $deadline) {
                throw new VerifyFailure($failure." (waited {$seconds} s)");
            }

            usleep(500_000);
        }
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private function must(array $command, ?string $cwd, array $env, string $what, int $timeout = 600): string
    {
        $result = Shell::exec($command, $cwd, $env, $timeout);
        file_put_contents($this->evidence.'/commands.log', '$ '.implode(' ', $command)."\n".$result['out']."\n[exit {$result['code']}]\n\n", FILE_APPEND);

        if ($result['code'] !== 0) {
            $tail = implode(' | ', array_slice(array_filter(array_map('trim', preg_split('/\R/', $result['out']) ?: [])), -4));

            throw new VerifyFailure("{$what} exited {$result['code']}: {$tail}");
        }

        return $result['out'];
    }

    private function php(string $id): string
    {
        $php = $this->options->php[Apps::all()[$id]['php']];

        if (! is_executable($php)) {
            throw new VerifyFailure("{$id}: the PHP binary {$php} is not executable");
        }

        return $php;
    }

    private function composer(): string
    {
        $composer = $this->options->composer;

        if (! str_contains($composer, '/')) {
            $found = trim(Shell::exec(['/usr/bin/which', $composer], null, ['PATH' => $this->env['PATH'] ?? '/usr/bin:/bin'])['out']);
            $composer = $found !== '' ? $found : $composer;
        }

        return $composer;
    }

    /**
     * The environment of every child: only these variables, so the settings
     * of another application (DB_*, APP_*, QUEUE_*) never reach the apps.
     *
     * @return array<string, string>
     */
    private function childEnv(): array
    {
        $env = ['COMPOSER_NO_INTERACTION' => '1'];

        foreach (['PATH', 'HOME', 'USER', 'LOGNAME', 'TMPDIR', 'LANG', 'SSH_AUTH_SOCK', 'COMPOSER_HOME', 'COMPOSER_CACHE_DIR', 'COMPOSER_AUTH'] as $name) {
            if (isset($this->env[$name])) {
                $env[$name] = $this->env[$name];
            }
        }

        return $env;
    }

    private static function installedVersion(string $dir, string $package): string
    {
        $installed = json_decode((string) @file_get_contents($dir.'/vendor/composer/installed.json'), true);

        foreach ($installed['packages'] ?? [] as $entry) {
            if (($entry['name'] ?? null) === $package) {
                return (string) $entry['version'];
            }
        }

        return '';
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === false) {
            throw new VerifyFailure("no free port: {$error}");
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function step(string $id, string $text): void
    {
        $this->say("[{$id}] {$text}");
    }

    private function say(string $text): void
    {
        $line = gmdate('H:i:s').' '.$text."\n";
        fwrite(STDOUT, $line);
        @file_put_contents($this->evidence.'/run.log', $line, FILE_APPEND);
    }
}

/** The warnings of an /api/health answer. */
final class Health
{
    /**
     * @param  array<string, mixed>  $health
     * @return list<string>
     */
    public static function warnings(array $health): array
    {
        $warnings = [];

        if (($health['windows'] ?? null) !== []) {
            $warnings[] = 'degradation windows: '.json_encode($health['windows'] ?? null);
        }

        if (($health['tick']['observer_tick_at'] ?? null) === null) {
            $warnings[] = 'no tick';
        }

        // An absent or renamed key is a warning, never clean.
        foreach (['missing_fence_tables', 'fence_unknown_connections', 'sqlite_warnings', 'uninstrumented_connections', 'missing_indexes'] as $list) {
            if (($health[$list] ?? null) !== []) {
                $warnings[] = "{$list}: ".json_encode($health[$list] ?? null);
            }
        }

        foreach (['sqlite_shared_mirror', 'queue_pause_cache_local'] as $hint) {
            if (! array_key_exists($hint, $health) || $health[$hint] !== null) {
                $warnings[] = "{$hint}: ".json_encode($health[$hint] ?? 'absent');
            }
        }

        if (($health['guard_invalid'] ?? null) !== false) {
            $warnings[] = 'guard_invalid';
        }

        if (($health['failed_records']['adapter'] ?? null) !== 'sql') {
            $warnings[] = 'failed_records: '.json_encode($health['failed_records'] ?? null);
        }

        if (($health['awaiting_resolution']['count'] ?? null) !== 0) {
            $warnings[] = 'awaiting_resolution: '.json_encode($health['awaiting_resolution'] ?? null);
        }

        // The schema status is {installed, expected, hint}: a current schema has no hint.
        $schema = $health['schema'] ?? null;

        if (! is_array($schema) || ! array_key_exists('installed', $schema) || ($schema['installed'] ?? null) !== ($schema['expected'] ?? false) || ($schema['hint'] ?? null) !== null) {
            $warnings[] = 'schema: '.json_encode($schema);
        }

        return $warnings;
    }
}
