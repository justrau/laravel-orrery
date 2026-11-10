<?php

namespace Workbench\App\Console;

use Illuminate\Bus\BatchRepository;
use Illuminate\Console\Command;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Justrau\Orrery\Http\Lists\JobList;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Workbench\App\Support\WorkbenchBootstrapCache;

/**
 * Uses every storage and service of the Workbench once and prints where each one resolved.
 *
 * It sends two HTTP requests through the HTTP kernel (global middleware with the maintenance
 * check, the web group with the session), and uses the cache and a cache lock, every queue
 * connection, the failed-job store, the batch store, the package tables, the log, the mailer,
 * the broadcaster and the default disk. WorkbenchOwnershipTest runs it with a poisoned
 * environment. The output is one line: REPORT_PREFIX followed by a JSON object.
 */
#[AsCommand(name: 'workbench:ownership')]
class OwnershipProbeCommand extends Command
{
    public const REPORT_PREFIX = 'WORKBENCH-OWNERSHIP ';

    /** @var string */
    protected $signature = 'workbench:ownership';

    /** @var string */
    protected $description = 'Use every storage and service of the Workbench once and print where each one resolves, as JSON.';

    public function handle(HttpKernel $kernel): int
    {
        $token = bin2hex(random_bytes(8));
        $path = '/'.trim((string) config('orrery.path'), '/');

        $report = [
            'token' => $token,
            'environment' => $this->laravel->environment(),
            'http' => [],
        ];

        foreach ([$path.'/dashboard', $path.'/api/workload'] as $uri) {
            $response = $kernel->handle(Request::create($uri, 'GET'));
            $report['http'][$uri] = $response->getStatusCode();
        }

        $report['maintenance'] = [
            'driver' => get_class($this->laravel->maintenanceMode()),
            'active' => $this->laravel->isDownForMaintenance(),
        ];

        $session = $this->laravel->make('session')->driver();
        $report['session'] = [
            'handler' => get_class($session->getHandler()),
            'rows' => DB::connection()->table('sessions')->count(),
        ];

        $store = Cache::store();
        $key = 'workbench:ownership:'.$token;
        $store->put($key, $token, 60);
        $lockProvider = $store->getStore();

        if (! $lockProvider instanceof LockProvider) {
            throw new RuntimeException(sprintf('The Workbench cache store %s provides no locks.', get_class($lockProvider)));
        }

        $lock = $lockProvider->lock($key.':lock', 10);
        $report['cache'] = [
            'store' => get_class($store->getStore()),
            'roundtrip' => $store->get($key) === $token,
            'lock' => $lock->get(),
        ];
        $lock->release();
        $store->forget($key);

        $report['queue'] = ['default' => get_class(Queue::connection())];
        foreach (array_keys((array) config('queue.connections')) as $name) {
            $queue = Queue::connection($name);
            $report['queue'][$name] = ['class' => get_class($queue), 'size' => $queue->size()];
        }

        $failer = $this->laravel->make('queue.failer');
        $report['failed'] = ['class' => get_class($failer), 'count' => count($failer->all())];
        $report['batches'] = count($this->laravel->make(BatchRepository::class)->get(10, null));
        // The package tables, through the list engine's capped count of the pending tab: {count, at_least}.
        $jobs = $this->laravel->make(JobList::class);
        $report['mirror_pending'] = $jobs->tabCount('pending', $jobs->countCap());

        Log::info('Workbench ownership probe '.$token);
        $report['log'] = ['channel' => config('logging.default'), 'path' => config('logging.channels.'.config('logging.default').'.path')];

        Mail::raw('Workbench ownership probe '.$token, static function ($message): void {
            $message->to('probe@workbench.invalid')->subject('Workbench ownership probe');
        });
        $mailer = Mail::mailer();
        $report['mail'] = get_class($mailer instanceof Mailer ? $mailer->getSymfonyTransport() : $mailer);

        $broadcaster = $this->laravel->make(BroadcastFactory::class)->connection();
        $broadcaster->broadcast(['workbench-ownership'], 'probe', ['token' => $token]);
        $report['broadcast'] = get_class($broadcaster);

        $file = 'workbench-ownership-'.$token.'.txt';
        Storage::put($file, $token);
        $report['filesystem'] = [
            'disk' => config('filesystems.default'),
            'roundtrip' => Storage::get($file) === $token,
            'written_path' => Storage::path($file),
        ];
        Storage::delete($file);

        // Where the dashboard request compiled its Blade layout, and where the storage paths resolve.
        $compiledLayout = $this->laravel->make('blade.compiler')->getCompiledPath(
            $this->laravel->make('view')->getFinder()->find('orrery::layout'),
        );
        $report['storage'] = [
            'path' => $this->laravel->storagePath(),
            'maintenance_file' => $this->laravel->storagePath('framework/down'),
            'view_compiled' => config('view.compiled'),
            'compiled_layout' => $compiledLayout,
            'compiled_layout_exists' => is_file($compiledLayout),
            'local_root' => config('filesystems.disks.local.root'),
            'session_files' => config('session.files'),
            'cache_files' => config('cache.stores.file.path'),
            'cache_locks' => config('cache.stores.file.lock_path'),
            'links' => config('filesystems.links'),
        ];

        // Where each bootstrap cache variable resolves, and the path that the package manifest uses.
        $application = $this->laravel;
        $report['bootstrap_caches'] = $application instanceof Application
            ? array_map(static fn (string $resolver): string => $application->{$resolver}(), WorkbenchBootstrapCache::RESOLVERS)
            : [];
        $report['package_manifest'] = $this->laravel->make(PackageManifest::class)->manifestPath;

        $report['opened_connections'] = array_map(
            static fn (Connection $connection): array => ['driver' => $connection->getDriverName(), 'database' => $connection->getDatabaseName()],
            DB::getConnections(),
        );

        // The package's own sessions live outside the database manager.
        foreach (\Justrau\Orrery\Storage\PackageSession::sessionsForTesting() as $entry) {
            $report['opened_connections'][(string) $entry['connection']->getName().' ('.$entry['mode'].')'] = ['driver' => $entry['connection']->getDriverName(), 'database' => $entry['connection']->getDatabaseName()];
        }

        $report['config'] = [
            'database.default' => config('database.default'),
            'database.connections' => array_keys((array) config('database.connections')),
            'database.redis' => array_keys((array) config('database.redis')),
            'orrery.database' => config('orrery.database'),
            'queue.default' => config('queue.default'),
            'queue.connections' => array_map(static fn (array $connection): ?string => $connection['connection'] ?? null, (array) config('queue.connections')),
            'queue.failed.database' => config('queue.failed.database'),
            'queue.batching.database' => config('queue.batching.database'),
            'cache.default' => config('cache.default'),
            'cache.stores' => array_keys((array) config('cache.stores')),
            'cache.stores.file.driver' => config('cache.stores.file.driver'),
            'session.driver' => config('session.driver'),
            'session.connection' => config('session.connection'),
            'session.store' => config('session.store'),
            'app.maintenance.driver' => config('app.maintenance.driver'),
            'app.maintenance.store' => config('app.maintenance.store'),
            'logging.channels' => array_keys((array) config('logging.channels')),
            'mail.default' => config('mail.default'),
            'mail.mailers' => array_keys((array) config('mail.mailers')),
            'broadcasting.default' => config('broadcasting.default'),
            'broadcasting.connections' => array_keys((array) config('broadcasting.connections')),
            'filesystems.default' => config('filesystems.default'),
            'filesystems.disks' => array_keys((array) config('filesystems.disks')),
        ];

        $this->line(self::REPORT_PREFIX.json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
