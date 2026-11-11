<?php

namespace Justrau\Orrery\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Justrau\Orrery\Workers\Pools;
use Justrau\Orrery\Workers\ProcessConfig;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Prints the process-manager configuration of the declared worker pools
 * (`orrery.workers.pools`, Workers\Pools). It writes no file and starts no
 * worker: Orrery stays a watcher. It reads only the configuration, so it
 * also runs while the package is off. Exit 0 with no pools (a hint) or a
 * printed configuration; exit 1 for invalid pools, an invalid option or a
 * value that the format cannot carry.
 */
#[AsCommand(name: 'orrery:workers')]
class WorkersCommand extends Command
{
    public const FORMATS = ['list', 'supervisor', 'systemd', 'forge'];

    /** @var string */
    protected $signature = 'orrery:workers
        {--format=list : list, supervisor, systemd or forge}
        {--user= : The user that runs the workers (supervisor, systemd, forge); default: the current user}
        {--php= : The PHP binary (supervisor, systemd); default: this PHP binary under the CLI, else php (systemd: a bare name is looked up on PATH)}
        {--path= : The application directory; default: base_path()}
        {--prefix= : The program and unit name prefix ([a-z0-9-]+); default: the application name as a slug}
        {--log-dir= : The log directory (supervisor); default: storage/logs}';

    /** @var string */
    protected $description = 'Print the process-manager configuration of the Orrery worker pools (orrery.workers.pools)';

    public function handle(): int
    {
        $format = $this->option('format');

        if (! is_string($format) || ! in_array($format, self::FORMATS, true)) {
            $this->components->error('--format must be one of: '.implode(', ', self::FORMATS).'.');

            return self::FAILURE;
        }

        ['pools' => $pools, 'errors' => $errors, 'warnings' => $warnings] = Pools::read($this->laravel->make('config'));

        if ($errors !== []) {
            $this->components->error('The worker pools are invalid ('.Pools::KEY.'):');
            $this->components->bulletList($errors);

            return self::FAILURE;
        }

        if ($warnings !== []) {
            $this->components->warn('Check these worker pools ('.Pools::KEY.'):');
            $this->components->bulletList($warnings);
        }

        if ($pools === []) {
            $this->components->info('No worker pools are declared. Add them to '.Pools::KEY.' in config/orrery.php (docs/operations.md, "Worker pools").');

            return self::SUCCESS;
        }

        $prefix = $this->stringOption('prefix') ?? self::defaultPrefix($this->laravel->make('config')->get('app.name'));

        if (preg_match('/^[a-z0-9-]+$/D', $prefix) !== 1) {
            $this->components->error('--prefix must be lowercase letters, digits and dashes ([a-z0-9-]+).');

            return self::FAILURE;
        }

        $php = $this->stringOption('php') ?? ProcessConfig::defaultPhp(PHP_SAPI, PHP_BINARY);

        // systemd's ExecStart needs a path: a bare name is looked up on this PATH now.
        if ($format === 'systemd' && ! str_starts_with($php, '/')) {
            $found = self::onPath($php);

            if ($found === null) {
                $this->components->error("--php: \"{$php}\" is not on the PATH of this shell; pass --php=/absolute/path/to/php.");

                return self::FAILURE;
            }

            $php = $found;
        }

        $config = new ProcessConfig(
            php: $php,
            path: rtrim($this->stringOption('path') ?? $this->laravel->basePath(), '/'),
            user: $this->stringOption('user') ?? self::currentUser(),
            prefix: $prefix,
            logDirectory: rtrim($this->stringOption('log-dir') ?? $this->laravel->storagePath('logs'), '/'),
        );

        try {
            if ($format === 'forge') {
                $this->forge($config->forge($pools));

                return self::SUCCESS;
            }

            $this->output->write(match ($format) {
                'supervisor' => $config->supervisor($pools),
                'systemd' => $config->systemd($pools),
                default => $config->list($pools),
            });
        } catch (InvalidArgumentException $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The application name as a program and unit prefix, `laravel` when it
     * leaves nothing.
     */
    public static function defaultPrefix(mixed $name): string
    {
        $slug = is_string($name) ? Str::slug($name) : '';

        return $slug === '' ? 'laravel' : $slug;
    }

    /**
     * @param  list<array{pool: string, custom: list<array{string, string}>, queue_worker: list<array{string, string}>}>  $forms
     */
    private function forge(array $forms): void
    {
        $this->line('Laravel Forge: Processes > Add background process. Use the Custom tab: the Queue worker tab has no field');
        $this->line('for --name, so the dashboard could not tie its workers to the pool (nor for --max-jobs and --max-time).');

        foreach ($forms as $form) {
            $this->newLine();
            $this->line("Pool {$form['pool']}, Custom tab:");
            $this->table(['Field', 'Value'], $form['custom']);
            $this->line("Pool {$form['pool']}, the same values in the Queue worker tab (without --name):");
            $this->table(['Field', 'Value'], $form['queue_worker']);
        }
    }

    /** The absolute path of an executable $name on PATH, or null. */
    private static function onPath(string $name): ?string
    {
        if ($name === '' || str_contains($name, '/')) {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            $candidate = rtrim($directory, '/').'/'.$name;

            if ($directory !== '' && str_starts_with($candidate, '/') && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function currentUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $entry = posix_getpwuid(posix_geteuid());

            if (is_array($entry) && $entry['name'] !== '') {
                return $entry['name'];
            }
        }

        $user = get_current_user();

        return $user !== '' ? $user : 'www-data';
    }
}
