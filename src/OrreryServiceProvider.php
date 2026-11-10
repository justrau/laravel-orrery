<?php

namespace Justrau\Orrery;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application as FoundationApplication;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Justrau\Orrery\Connectors\DatabaseConnector;
use Justrau\Orrery\Http\Middleware\Authenticate;
use Justrau\Orrery\Observation\DatabaseDriverFilter;
use Justrau\Orrery\Registration\Module;
use Justrau\Orrery\Registration\Modules;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Runtime\WatchesItself;
use Throwable;

class OrreryServiceProvider extends ServiceProvider
{
    use EventMap;
    use ServiceBindings;

    /**
     * The registration modules, in registration and boot order. A module
     * registers its listeners just before its boot(), so this order is also
     * the order of the module listeners of one event. Each comment names
     * what a later module needs from the module. A stage that deletes a
     * module removes its line.
     *
     * @var list<class-string<Module>>
     */
    public const MODULES = [
        // The package sessions, the host SQLite file policy, PackageTransaction and the package schema: every later module reads and writes through them.
        Modules\StorageModule::class,
        // The watcher, the breaker, the degradation record and the process context: every later listener and tick phase runs inside the watcher.
        Modules\RuntimeModule::class,
        // The commit deferral, its units of work and the payload classification: the hooks defer through it, and the lifecycle store reads the stamped type, tags and generation.
        Modules\ObservationModule::class,
        // JobStore, history, summaries, the monitoring cache, the outcome listeners and the bulk capture: the services and the tick phases write through the store.
        Modules\LifecycleModule::class,
        // The operation ledger, retry, forget, the verifying failed-job extender and the operation phases: the dashboard routes, the commands and reconciliation call them.
        Modules\OperationsModule::class,
        // The tick runner, retention, reconciliation, orrery:clear and the schedule: the tick runs the phases that the later modules tag.
        Modules\MaintenanceModule::class,
        // Wait times, the samples, the markers, the tag monitoring and the jobs index: the dashboard reads them.
        Modules\MonitoringModule::class,
        // Heartbeats, live workers, signals and the stop latch: the wait times and the dashboard read the live workers.
        Modules\ControlModule::class,
        // The public static API (gate, operate and actor callbacks, notification routes, Tags): the HTTP layer of this provider reads it.
        Modules\HttpModule::class,
    ];

    /**
     * The registration modules, in the order of MODULES (Registration\Modules).
     *
     * @var list<Module>
     */
    protected array $modules = [];

    public function boot(): void
    {
        $this->bootModules();
        $this->registerEvents();
        $this->registerMigrations();
        $this->offerPublishing();

        // ─── HTTP layer (WB3) ───
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'orrery');

        Route::middlewareGroup('orrery', config('orrery.middleware', ['web']));

        $routesCached = $this->app instanceof FoundationApplication
            && $this->app->routesAreCached();

        if (! $routesCached) {
            Route::group(self::routeGroup(), function (): void {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });
        }

        // ─── Console layer (WB4) ───
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\InstallCommand::class,
                Console\ClearCommand::class,
                Console\ClearMetricsCommand::class,
                Console\TickCommand::class,
                Console\ForgetFailedCommand::class,
                // A retry of one job, or a bulk run.
                Console\RetryCommand::class,
                Console\PauseCommand::class,
                Console\ContinueCommand::class,
                Console\TerminateCommand::class,
                Console\DeployedCommand::class,
                Console\ReconcileCommand::class,
                // The operator's resolve of an operation.
                Console\ResolveCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../stubs/OrreryServiceProvider.stub' => app_path('Providers/OrreryServiceProvider.php'),
            ], 'orrery-provider');
        }

        // The schedule entries are in Registration\Modules\MaintenanceModule.
    }

    /**
     * The attributes of the package route group. Authenticate is on the
     * group, so every route in routes/web.php, also one without a package
     * controller, needs the view ability, and a write route also the operate
     * ability.
     *
     * @return array{domain: mixed, prefix: mixed, namespace: string, middleware: list<string>}
     */
    public static function routeGroup(): array
    {
        return [
            'domain' => config('orrery.domain'),
            'prefix' => config('orrery.path'),
            'namespace' => 'Justrau\\Orrery\\Http\\Controllers',
            'middleware' => ['orrery', Authenticate::class],
        ];
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/orrery.php', 'orrery');

        $this->modules = Modules::all();

        foreach ($this->modules as $module) {
            $module->register($this->app);
        }

        $this->registerServices();
        $this->registerQueueConnectors();
    }

    /**
     * Boots the modules in the order of MODULES. Each module's listeners register
     * just before its boot(), so the modules' listeners come before the
     * legacy EventMap listeners, in the order of MODULES.
     */
    protected function bootModules(): void
    {
        $events = $this->app->make(Dispatcher::class);

        foreach ($this->modules as $module) {
            foreach ($module->listeners() as $event => $listeners) {
                foreach ($listeners as $listener) {
                    $events->listen($event, $this->wrapListener($listener));
                }
            }

            $module->boot($this->app);
        }
    }

    protected function registerEvents(): void
    {
        $events = $this->app->make(Dispatcher::class);

        foreach ($this->events as $event => $listeners) {
            foreach ($listeners as $listener) {
                $events->listen($event, $this->wrapListener($listener));
            }
        }
    }

    /**
     * A package listener as a closure. It rejects the events of
     * queue connections whose driver is not `database` (it returns null), and
     * it runs the listener inside the watcher, so an error of the package
     * never stops the dispatch to the application's own listeners. It
     * returns the listener's own value, so the false of a Looping listener
     * reaches the worker. A listener that implements WatchesItself runs its
     * own work inside the watcher; the wrapper only catches and reports its
     * errors, so the open breaker never skips it as a whole.
     *
     * @param  class-string  $listener
     */
    protected function wrapListener(string $listener): Closure
    {
        $app = $this->app;

        return static function (mixed ...$payload) use ($app, $listener): mixed {
            $event = $payload[0] ?? null;

            if (is_object($event) && ! DatabaseDriverFilter::accepts($event)) {
                return null;
            }

            $watcher = $app->make(Watcher::class);

            if (is_subclass_of($listener, WatchesItself::class)) {
                // The listener watches its own work: the outer breaker skip
                // must not stop the part that runs while the breaker is open.
                try {
                    return $app->make($listener)->handle(...$payload);
                } catch (TimeoutExceededException $timeout) {
                    // The worker's own timeout (Worker::$killOnTimeout false) belongs to the job (Watcher).
                    throw $timeout;
                } catch (Throwable $error) {
                    Degradation::error('listener:'.$listener, $error);
                    $watcher->report('listener:'.$listener, $error);

                    return null;
                }
            }

            return $watcher->run(
                'listener:'.$listener,
                static fn (): mixed => $app->make($listener)->handle(...$payload),
            );
        };
    }

    /**
     * The package migrations from the package's own directory, unless
     * orrery.load_migrations is false (an application that runs
     * `migrate` for several databases runs them only on the database of the
     * package tables).
     */
    protected function registerMigrations(): void
    {
        $load = $this->app->make('config')->get('orrery.load_migrations', true);
        $load = is_string($load) ? trim($load) : $load;

        // Only an explicit false turns the loading off: false, or a word of ORRERY_LOAD_MIGRATIONS
        // such as "0", "false", "off" or "no". Null, a blank value and any other word keep it on.
        if ($load === false || ($load !== '' && is_string($load) && filter_var($load, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === false)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * Lets `vendor:publish` name the published copy of $source by the time
     * of the publish, as Laravel's publishesMigrations() does for package
     * migrations, so it runs after the application's own
     * migrations of that day, such as the jobs table of make:queue-table.
     * Unlike publishesMigrations(), it does not depend on the application's
     * `database.migrations.update_date_on_publish`.
     */
    public static function publishWithCurrentDate(string $source): void
    {
        static::$publishableMigrationPaths = array_values(array_unique([...static::$publishableMigrationPaths, $source]));
    }

    protected function offerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/orrery.php' => config_path('orrery.php'),
        ], 'orrery-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'orrery-migrations');
    }

    protected function registerQueueConnectors(): void
    {
        $this->callAfterResolving(QueueManager::class, function (QueueManager $manager) {
            if (! config('orrery.swap_connector', true)) {
                return;
            }

            $manager->addConnector('database', function () {
                return new DatabaseConnector($this->app->make('db'));
            });
        });
    }
}
