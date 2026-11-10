<?php

namespace Workbench\App\Providers;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Justrau\Orrery\OrreryApplicationServiceProvider;
use RuntimeException;
use Workbench\App\Console\DispatchSlowJobCommand;
use Workbench\App\Console\DropDatabaseCommand;
use Workbench\App\Console\OwnershipProbeCommand;
use Workbench\App\Console\ShowcaseWorkerCommand;
use Workbench\App\Console\ShowcaseWorkersCommand;
use Workbench\App\Http\Middleware\ContentSecurityPolicy;
use Workbench\App\Support\ProcessEnvironment;
use Workbench\App\Support\WorkbenchAuth;
use Workbench\App\Support\WorkbenchBootstrapCache;
use Workbench\App\Support\WorkbenchConfiguration;
use Workbench\App\Support\WorkbenchDatabase;
use Workbench\App\Support\WorkbenchShowcase;

/**
 * The application provider of the Workbench app. Only testbench.yaml loads it.
 * The PHPUnit suite never loads it (WorkbenchIsolationTest proves this).
 */
class WorkbenchServiceProvider extends OrreryApplicationServiceProvider
{
    /**
     * The environment of the default mode. The strict-auth mode (WORKBENCH_AUTH=strict) runs in
     * WorkbenchAuth::STRICT_ENVIRONMENT.
     */
    public const ENVIRONMENT = WorkbenchAuth::DEFAULT_ENVIRONMENT;

    /** Whether the strict-auth mode is on (WorkbenchAuth), read once in register(). */
    private bool $strict = false;

    /** Whether the showcase mode is on (WorkbenchShowcase), read once in register(). */
    private bool $showcase = false;

    public function register(): void
    {
        parent::register();

        $app = $this->app;

        if (! $app instanceof Application) {
            throw new RuntimeException(sprintf('The Workbench needs an %s instance, not %s.', Application::class, get_debug_type($app)));
        }

        $this->refuseForeignBootstrapCaches($app);

        // The path guard runs here, while the application registers its providers. That is
        // before any command runs, so `create-sqlite-db`, `migrate:fresh`, `serve` and
        // `queue:work` never start with a path outside workbench/database.
        $database = WorkbenchDatabase::resolve(WorkbenchDatabase::configured());

        // An inherited APP_ENV=production would make `migrate:fresh` ask for a confirmation.
        // A declined confirmation skips the rebuild, so the environment is always the one of the
        // mode: local, or production in the strict-auth mode, whose build passes --force.
        // An unknown WORKBENCH_AUTH value throws here, before any command runs.
        $this->strict = WorkbenchAuth::strict();
        $this->showcase = WorkbenchShowcase::enabled();

        if ($this->strict && $this->showcase) {
            throw new RuntimeException(sprintf('The Workbench runs %s or %s, not both.', WorkbenchAuth::VARIABLE.'='.WorkbenchAuth::STRICT, WorkbenchShowcase::VARIABLE.'='.WorkbenchShowcase::ON));
        }

        $environment = $this->showcase ? WorkbenchShowcase::ENVIRONMENT : WorkbenchAuth::environment($this->strict);
        $app->detectEnvironment(static fn (): string => $environment);
        $app->make('config')->set('app.env', $environment);

        // `create-sqlite-db` creates databasePath()/database.sqlite. The guard allows only that
        // file name, so the build step, the migrations and check.php all use $database.
        $app->useDatabasePath(dirname($database));

        // Laravel takes LARAVEL_STORAGE_PATH from the environment when no storage path is set.
        // Maintenance mode (storage/framework/down), the emergency log and real-time facades
        // read storage_path() at run time, so the owned directory must be the storage path.
        $storage = self::storagePath();
        $app->useStoragePath($storage);
        self::createStorageDirectories($storage);

        // The rollback-mode switch applies to the web server only. The build, the worker and
        // every other command keep WAL, so the build's counts hold and a worker can run beside
        // the dashboard. An unknown value throws in every process.
        $journal = WorkbenchConfiguration::journalMode(ProcessEnvironment::get(WorkbenchConfiguration::JOURNAL_VARIABLE));

        if ($app->runningInConsole()) {
            $journal = WorkbenchConfiguration::WAL;
        }

        WorkbenchConfiguration::apply($app->make('config'), 'sqlite', $database, $storage, $journal);

        // The package reads ORRERY_DISPLAY_TIMEZONE with env(), which the web server child of
        // `serve` does not see; the process environment does.
        WorkbenchConfiguration::displayTimezone($app->make('config'), ProcessEnvironment::get(WorkbenchConfiguration::DISPLAY_TIMEZONE_VARIABLE));
        WorkbenchAuth::apply($app->make('config'), $this->strict);

        if ($this->showcase) {
            WorkbenchShowcase::apply($app->make('config'));
        }

        // The dashboard runs under the enforced Content Security Policy of docs/security.md,
        // so every browser check of the Workbench proves it. The package's boot() reads
        // orrery.middleware after every register().
        $config = $app->make('config');
        $config->set('orrery.middleware', [...(array) $config->get('orrery.middleware', ['web']), ContentSecurityPolicy::class]);
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->strict) {
            WorkbenchAuth::routes($this->app->make('router'));
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchSlowJobCommand::class,
                DropDatabaseCommand::class,
                OwnershipProbeCommand::class,
                ShowcaseWorkerCommand::class,
                ShowcaseWorkersCommand::class,
            ]);
        }
    }

    /**
     * The owned storage directory: <repo>/workbench/storage (ignored by git).
     */
    public static function storagePath(): string
    {
        return dirname(WorkbenchDatabase::directory()).DIRECTORY_SEPARATOR.'storage';
    }

    /**
     * The Workbench log: <repo>/workbench/storage/logs/workbench.log.
     */
    public static function logFile(): string
    {
        return WorkbenchConfiguration::logFile(self::storagePath());
    }

    /**
     * Refuse to run unless all five bootstrap cache paths resolve to the owned files that
     * workbench/bin/testbench pins (see WorkbenchBootstrapCache). This runs in every Workbench
     * process: the CLI and the web server child of `serve`. Laravel has already used the services
     * and packages manifests when this runs, so the check cannot prevent those early reads and
     * writes: it stops a direct `vendor/bin/testbench` call from going on, and names the launcher.
     *
     * The package manifest fixed its path when Testbench created the application, before the
     * testbench.yaml env list loaded. So the check also compares the path that the manifest uses,
     * not only the path that the variable resolves to now.
     */
    private function refuseForeignBootstrapCaches(Application $app): void
    {
        WorkbenchBootstrapCache::refuseForeign(
            array_map(static fn (string $resolver): string => $app->{$resolver}(), WorkbenchBootstrapCache::RESOLVERS),
            $app->make(PackageManifest::class)->manifestPath,
        );
    }

    /**
     * The directories that Laravel expects in a storage path, for example for `testbench down`.
     * The file cache store needs framework/cache/data: its flush() fails without it, so the
     * build step cache:clear fails in a fresh checkout.
     */
    private static function createStorageDirectories(string $storage): void
    {
        foreach (['app/private', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
            $path = $storage.DIRECTORY_SEPARATOR.$directory;

            if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
                throw new RuntimeException(sprintf('Cannot create the Workbench storage directory [%s].', $path));
            }
        }
    }

    /**
     * The default mode lets every visitor open the dashboard; the strict-auth mode allows only
     * the seeded users' abilities (WorkbenchAuth::USERS).
     */
    protected function gate(): void
    {
        if ($this->showcase) {
            WorkbenchShowcase::defineGates();

            return;
        }

        WorkbenchAuth::defineGates($this->strict);
    }
}
