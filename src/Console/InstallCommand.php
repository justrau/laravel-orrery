<?php

namespace Justrau\Orrery\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Justrau\Orrery\Database\Schema\DeferredIndexes;
use Justrau\Orrery\Database\Schema\IndexBuild;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Database\Schema\SchemaStatus;
use Justrau\Orrery\Http\Controllers\HealthController;
use Justrau\Orrery\Http\Controllers\PingController;
use Justrau\Orrery\Maintenance\RetentionPolicy;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\FenceTargets;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Workers\Pools;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'orrery:install')]
class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'orrery:install
        {--fence : Only write the missing fence migrations of the database connections}
        {--indexes : Only build the deferred orrery_jobs indexes on the mirror database (a dedicated connection, no statement timeout)}
        {--timeout=0 : With --indexes: the statement timeout of the build in seconds (Postgres; MariaDB max_statement_time); 0 means none}
        {--connection= : With --indexes: a direct connection to the mirror database, for a pooled Postgres mirror without a `direct` endpoint}';

    /** The longest --timeout of --indexes: Postgres takes it in milliseconds as a 32-bit integer. */
    public const MAX_TIMEOUT_SECONDS = 2_147_483;

    /** @var string */
    protected $description = 'Publish the Orrery config, provider stub, and migrations';

    public function handle(): int
    {
        if ((bool) $this->option('fence')) {
            return $this->writeFenceMigrations();
        }

        if ((bool) $this->option('indexes')) {
            return $this->buildIndexes();
        }

        $this->components->info('Installing Orrery resources.');

        collect([
            'Configuration' => fn () => $this->callSilent('vendor:publish', ['--tag' => 'orrery-config']) === 0,
            'Service Provider' => fn () => $this->callSilent('vendor:publish', ['--tag' => 'orrery-provider']) === 0,
            'Migrations' => fn () => $this->callSilent('vendor:publish', ['--tag' => 'orrery-migrations']) === 0,
        ])->each(fn ($task, $description) => $this->components->task($description, $task));

        $this->registerServiceProvider();
        $fence = $this->writeFenceMigrations();

        $this->components->info('Orrery scaffolding installed successfully.');
        $this->components->bulletList([
            'Run "php artisan migrate" to create the orrery_* tables.',
            'On an existing install, run "'.DeferredIndexes::COMMAND.'" after migrate to build the deferred indexes.',
            'Ensure "* * * * * php artisan schedule:run" is wired into cron so the package can tick.',
        ]);

        $this->reportMirror();
        $this->reportSchema();
        $this->reportFence();
        $this->reportRetention();
        $this->reportWorkerPools();
        $this->reportPing();

        // A refused mirror (reportMirror() printed why), a refused sibling file or one that cannot
        // be created (reportSchema() printed why), or a fence migration that could not be written,
        // is no usable install: a deploy script stops here.
        $sessions = $this->laravel->make(PackageSession::class);
        $usable = $sessions->mirrorRefusal() === null && SchemaStatus::siblingRefusal($sessions) === null;

        return $fence === self::SUCCESS && $usable ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Builds the deferred orrery_jobs indexes
     * (PackageSchema::DEFERRED_INDEXES) through IndexBuild, on a dedicated
     * connection. Exit 1 when an index is still missing afterwards, or the
     * build refused.
     */
    protected function buildIndexes(): int
    {
        $timeout = $this->option('timeout');
        $connection = $this->option('connection');

        // At most 2,147,483 s: Postgres takes the timeout in milliseconds as a 32-bit integer.
        if (! is_string($timeout) || preg_match('/^\d{1,7}$/D', $timeout) !== 1 || (int) $timeout > self::MAX_TIMEOUT_SECONDS) {
            $this->components->error('--timeout is a whole number of seconds from 0 (no timeout) to '.self::MAX_TIMEOUT_SECONDS.'.');

            return self::FAILURE;
        }

        if ($connection === '') {
            $this->components->error('--connection names a database connection.');

            return self::FAILURE;
        }

        $this->components->info('Building the deferred orrery_jobs indexes ('.implode(', ', array_keys(PackageSchema::DEFERRED_INDEXES)).').');

        $code = $this->laravel->make(IndexBuild::class)->run($connection, (int) $timeout, function (string $level, string $text): void {
            match ($level) {
                'error' => $this->components->error($text),
                'warn' => $this->components->warn($text),
                default => $this->components->info($text),
            };
        });

        return $code === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * D17 and D18: where the package keeps its tables, the
     * refusal of an orrery.database on the SQLite file of a connection that
     * the package uses, and the application SQLite files without automatic
     * reads.
     */
    protected function reportMirror(): void
    {
        $sessions = $this->laravel->make(PackageSession::class);
        $refusal = $sessions->mirrorRefusal();

        if ($refusal !== null) {
            $this->components->error($refusal);
        } elseif (($sibling = $sessions->siblingPath()) !== null) {
            $this->components->info("Orrery keeps its tables in the SQLite file {$sibling}, next to the application's database. The package creates and upgrades it itself; it holds only package data.");
        }

        $warnings = HealthController::sqliteWarnings($this->laravel->make(HostFiles::class));

        if ($warnings !== []) {
            $this->components->warn(HostFiles::READS_WARNING);
            $this->components->bulletList(array_values(array_unique(array_column($warnings, 'file'))));
        }
    }

    /**
     * The mirror's schema marker against this
     * release's version, with the same hint as `/api/health` `schema`
     * (SchemaStatus), read now. Nothing when the mirror is refused
     * (reportMirror() names that).
     */
    protected function reportSchema(): void
    {
        $sessions = $this->laravel->make(PackageSession::class);

        if ($sessions->mirrorRefusal() !== null) {
            return;
        }

        $this->markLoadedSchema($sessions);

        try {
            $status = SchemaStatus::read($sessions);
        } catch (Throwable $error) {
            $this->components->warn('Could not read the Orrery schema version: '.$error->getMessage());

            return;
        }

        if ($status['hint'] === null) {
            $this->components->info("The Orrery schema is at version {$status['expected']}, as this release expects.");

            return;
        }

        $installed = $status['installed'] === null ? 'The Orrery schema version is unknown' : "The Orrery schema is at version {$status['installed']}";
        $this->components->warn("{$installed}; this release expects version {$status['expected']}. {$status['hint']}");
    }

    /**
     * A server mirror whose marker table holds no row (a database
     * loaded from a schema dump outside `migrate`) gets its marker
     * (PackageSchema::adoptUnmarked(), PackageSchema::UNMARKED_HINT). It
     * keeps every table and row.
     */
    protected function markLoadedSchema(PackageSession $sessions): void
    {
        try {
            if ($sessions->mirrorIsSibling()) {
                return;
            }

            /** @var Connection $mirror */
            $mirror = $this->laravel->make('db')->connection(PackageSchema::configuredConnection());

            if (PackageSchema::adoptUnmarked($mirror, PackageSchema::currentVersion())) {
                $this->components->info('Orrery wrote its schema marker (version '.PackageSchema::currentVersion().'): the marker table had no row, as after a load of php artisan schema:dump.');
            }
        } catch (Throwable $error) {
            $this->components->warn('Could not write the Orrery schema marker: '.$error->getMessage());
        }
    }

    /**
     * Every connection whose database has no fence table
     * (FenceTargets::report()). Until the table exists, Orrery refuses to
     * retry the failed jobs of those connections (fence_missing).
     */
    protected function reportFence(): void
    {
        /** @var Repository $config */
        $config = $this->laravel->make('config');
        $retention = self::positive($config->get('orrery.retry.fence_retention_days'), 30);
        $timeout = self::positive($config->get('orrery.retry.publish_timeout'), 60);

        try {
            // An operator's command: it may read an application SQLite file without automatic reads (D18).
            $missing = PackageSession::asOperator(fn (): array => $this->laravel->make(FenceTargets::class)->report($this->laravel->make(Fence::class), $this->laravel->make(HostFiles::class))['missing']);
        } catch (Throwable $error) {
            $this->components->warn('Could not check the fence tables: '.$error->getMessage());

            return;
        }

        if ($missing === []) {
            $this->components->info('Every connection that a retry needs has its fence table ('.Fence::TABLE.').');
        } else {
            $this->components->warn(
                'These connections have no fence table ('.Fence::TABLE.') on their database. '
                .'Run "'.HealthController::FENCE_COMMAND.'", then "php artisan migrate": until the table exists, Orrery refuses to retry their failed jobs (fence_missing).'
            );
            $this->components->bulletList($missing);
        }

        $this->components->twoColumnDetail('Fence rows and finished operations are kept', "{$retention} days");
        $this->components->twoColumnDetail('A package publication times out after', "{$timeout} seconds");
    }

    /**
     * The file slug of a connection name: the name itself when it is lowercase
     * letters, digits and underscores, else its cleaned form with a hash of
     * the name, so two names never share one file (`tenant-1`, `tenant_1`).
     */
    private static function fenceSlug(string $connection): string
    {
        if (preg_match('/^[a-z0-9_]+$/D', $connection) === 1) {
            return $connection;
        }

        return strtolower((string) preg_replace('/[^A-Za-z0-9_]+/', '_', $connection)).'_'.substr(hash('sha256', $connection), 0, 8);
    }

    private static function positive(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    /**
     * One self-contained migration per database
     * connection that needs the fence table orrery_fences and does not get it
     * from the package's main migration: every other database connection of
     * a `database` queue connection, the failed-record connection of
     * `database-uuids`, and the queue's own SQLite file beside a sibling
     * mirror. The file imports only Illuminate classes and creates the
     * table only when it is missing. A connection that already has its file
     * gets none, so a queue connection added later gets its own on the next
     * run.
     */
    protected function writeFenceMigrations(): int
    {
        $stub = (string) file_get_contents(__DIR__.'/../../stubs/fence-migration.stub');
        $directory = $this->laravel->databasePath('migrations');
        $written = [];

        foreach ($this->laravel->make(FenceTargets::class)->withoutMirror() as $index => $connection) {
            $slug = self::fenceSlug($connection);

            if (glob($directory.'/*_create_orrery_fences_on_'.$slug.'.php') !== []) {
                continue;
            }

            if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                $this->components->error("The directory {$directory} could not be created.");

                return self::FAILURE;
            }

            // One second apart, so the files keep their order.
            $file = $directory.'/'.date('Y_m_d_His', time() + $index).'_create_orrery_fences_on_'.$slug.'.php';
            if (@file_put_contents($file, str_replace('{{ connection }}', addcslashes($connection, "'\\"), $stub)) === false) {
                $this->components->error("The fence migration {$file} could not be written.");

                return self::FAILURE;
            }

            $written[] = $file;
        }

        if ($written === []) {
            $this->components->info('Every fence migration exists.');
        } else {
            $this->components->info('Wrote the fence migrations; run "php artisan migrate" to create the '.Fence::TABLE.' tables.');
            $this->components->bulletList($written);
        }

        return self::SUCCESS;
    }

    /**
     * The retention policy is validated at the install and
     * maintenance entry points. orrery:tick deletes nothing while it is
     * invalid.
     */
    protected function reportRetention(): void
    {
        $errors = $this->laravel->make(RetentionPolicy::class)->validate();

        if ($errors === []) {
            $this->components->info('The retention policy is valid (orrery.trim and orrery.trim_overrides).');

            return;
        }

        $this->components->warn('The retention policy is invalid: orrery:tick deletes no mirror rows until it is fixed.');
        $this->components->bulletList($errors);
    }

    /**
     * The declared worker pools (orrery.workers.pools), validated as
     * orrery:workers reads them. Nothing when none are declared; invalid
     * pools are a warning, as an invalid retention policy.
     */
    protected function reportWorkerPools(): void
    {
        ['pools' => $pools, 'errors' => $errors] = Pools::read($this->laravel->make('config'));

        if ($errors !== []) {
            $this->components->warn('The worker pools are invalid ('.Pools::KEY.'): orrery:workers prints no configuration until they are fixed.');
            $this->components->bulletList($errors);

            return;
        }

        if ($pools !== []) {
            $this->components->info('The worker pools are valid ('.count($pools).' in '.Pools::KEY.'); php artisan orrery:workers prints their process-manager configuration.');
        }
    }

    /**
     * The monitoring ping: a token that is too short keeps the route off,
     * and a limiter store on a host SQLite file leaves failed token checks
     * unlimited (PingController::state(), also in /api/health). Nothing
     * when no token is set.
     */
    protected function reportPing(): void
    {
        $state = PingController::state();

        if ($state['token'] === 'too_short') {
            $this->components->warn('ORRERY_PING_TOKEN is shorter than '.PingController::MIN_TOKEN_LENGTH.' characters: the ping route stays off (404) until you set a longer token.');
        }

        if ($state['rate_limit'] === 'off_host_sqlite') {
            $this->components->warn('The cache store of the rate limiter (cache.limiter, else the default store) writes your SQLite database: the ping does not limit failed token checks. Use another store for cache.limiter.');
        }
    }

    protected function registerServiceProvider(): void
    {
        /** @var Application $app */
        $app = $this->laravel;
        $namespace = Str::replaceLast('\\', '', $app->getNamespace());
        $providerClass = "{$namespace}\\Providers\\OrreryServiceProvider";
        $providerPath = $app->path('Providers/OrreryServiceProvider.php');

        if (file_exists($providerPath)) {
            $contents = (string) file_get_contents($providerPath);
            if (str_contains($contents, 'namespace App\\Providers;') && $namespace !== 'App') {
                file_put_contents($providerPath, str_replace(
                    'namespace App\\Providers;',
                    "namespace {$namespace}\\Providers;",
                    $contents,
                ));
            }
        }

        if (file_exists($app->bootstrapPath('providers.php'))) {
            ServiceProvider::addProviderToBootstrapFile($providerClass);

            return;
        }

        $appConfig = config_path('app.php');
        if (! file_exists($appConfig)) {
            return;
        }

        $contents = (string) file_get_contents($appConfig);
        if (str_contains($contents, $providerClass.'::class')) {
            return;
        }

        $this->components->warn(
            "Could not auto-register {$providerClass}. Add it to your providers list manually."
        );
    }
}
