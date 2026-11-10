<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Storage;

use Justrau\Orrery\Storage\EndpointIdentity;
use Justrau\Orrery\Storage\HostFileAccessNotAllowed;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\TestCase;

/**
 * The part of the host-file policy that this layer builds (D17): the real
 * files of the database queue connections, the failed-record connection
 * and the mirror's connection when orrery.database is not set, compared by
 * realpath(), and no automatic access to them until the D18
 * admission.
 */
final class HostFilesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(PackageSession::class);
        $this->beforeApplicationDestroyed(static function (): void {
            PackageSession::resetForTesting();
        });
    }

    /**
     * Rejects: a policy per connection name, a policy over every configured connection, and a path compared without realpath().
     */
    public function test_host_files_are_the_used_real_files(): void
    {
        $queueFile = $this->file();
        $failedFile = $this->file();
        $ownFile = $this->file();
        $unusedFile = $this->file();
        $linkDirectory = sys_get_temp_dir().'/orrery-c4a-link-'.bin2hex(random_bytes(6));
        mkdir($linkDirectory, 0700);
        $link = $linkDirectory.'/linked-queue.sqlite';
        symlink($queueFile, $link);
        $this->beforeApplicationDestroyed(static function () use ($link, $linkDirectory): void {
            unlink($link);
            rmdir($linkDirectory);
        });

        $sqlite = static fn (string $file): array => ['driver' => 'sqlite', 'url' => null, 'database' => $file, 'prefix' => ''];
        $config = $this->app['config'];
        $original = ['database.default' => $config->get('database.default'), 'queue.connections' => $config->get('queue.connections'), 'queue.failed' => $config->get('queue.failed'), 'queue.batching' => $config->get('queue.batching'), 'orrery.database' => $config->get('orrery.database')];
        $this->beforeApplicationDestroyed(static function () use ($config, $original): void {
            $config->set($original);
        });
        $config->set('database.default', 'app');
        $config->set('database.connections.app', $sqlite($ownFile));
        $config->set('database.connections.queue_file', $sqlite($queueFile));
        $config->set('database.connections.queue_alias', $sqlite($link));
        $config->set('database.connections.failed_file', $sqlite($failedFile));
        $config->set('database.connections.unused_file', $sqlite($unusedFile));
        $config->set('queue.connections', ['jobs' => ['driver' => 'database', 'connection' => 'queue_file', 'table' => 'jobs', 'queue' => 'default']]);
        $config->set('queue.failed', ['driver' => 'database-uuids', 'database' => 'failed_file', 'table' => 'failed_jobs']);
        $config->set('queue.batching', ['database' => 'queue_file', 'table' => 'job_batches']);
        $config->set('orrery.database', 'app');

        $files = $this->app->make(HostFiles::class);

        self::assertEqualsCanonicalizing([realpath($queueFile), realpath($failedFile)], $files->files(), 'Each used real file once.');
        self::assertTrue($files->isHost('queue_file'));
        self::assertTrue($files->isHost('queue_alias'), 'A second name of the queue file, through a symlink, is the same host file.');
        self::assertTrue($files->isHost('failed_file'));
        self::assertFalse($files->isHost('app'), 'A file that only orrery.database names is the package\'s own.');
        self::assertFalse($files->isHost('unused_file'), 'A configured file that the package does not use is not a host file.');
        self::assertFalse($files->allowsReads('queue_file'), 'C4a admits no automatic access to a host file.');
        self::assertTrue($files->allowsReads('app'));

        // Without orrery.database, the mirror's connection is the application's.
        $config->set('orrery.database', null);
        self::assertTrue($this->app->make(HostFiles::class)->isHost('app'));

        $this->expectException(HostFileAccessNotAllowed::class);
        $this->app->make(PackageSession::class)->for('queue_alias');
    }

    /**
     * Rejects: a relative SQLite path resolved against the working directory only, so the identity and the host-file policy miss the file that Laravel opens.
     */
    public function test_relative_sqlite_paths_resolve_like_laravel(): void
    {
        $file = $this->file();
        $base = dirname($file);
        $elsewhere = sys_get_temp_dir().'/orrery-c4a-cwd-'.bin2hex(random_bytes(6));
        mkdir($elsewhere, 0700);
        $relative = basename($file);
        $originalBase = $this->app->basePath();
        $originalDirectory = (string) getcwd();
        $config = $this->app['config'];
        $original = ['queue.connections' => $config->get('queue.connections')];
        $this->beforeApplicationDestroyed(function () use ($config, $original, $originalBase, $originalDirectory, $elsewhere): void {
            chdir($originalDirectory);
            rmdir($elsewhere);
            $this->app->setBasePath($originalBase);
            $config->set($original);
        });

        // Laravel's connector opens a relative path against base_path() when the working directory does not hold it.
        $this->app->setBasePath($base);
        chdir($elsewhere);
        $config->set('database.connections.relative_file', ['driver' => 'sqlite', 'url' => null, 'database' => $relative, 'prefix' => '']);
        $config->set('database.connections.absolute_file', ['driver' => 'sqlite', 'url' => null, 'database' => $file, 'prefix' => '']);
        $config->set('queue.connections', ['jobs' => ['driver' => 'database', 'connection' => 'absolute_file', 'table' => 'jobs', 'queue' => 'default']]);

        self::assertSame(realpath($file), EndpointIdentity::realFile($relative));
        self::assertTrue($this->app->make(HostFiles::class)->isHost('relative_file'), 'The relative name reaches the queue\'s host file.');
        self::assertSame(
            $this->app->make(PackageSession::class)->fingerprint('absolute_file'),
            $this->app->make(PackageSession::class)->fingerprint('relative_file'),
            'Both names reach one file, so they have one endpoint identity.',
        );
    }

    /**
     * Rejects: an identity without the startup options of server_options, so a changed option reuses a session of the old namespace.
     */
    public function test_server_options_enter_the_endpoint_identity(): void
    {
        $base = ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432, 'database' => 'app', 'username' => 'app', 'prefix' => ''];

        $without = EndpointIdentity::of($base);
        $one = EndpointIdentity::of($base + ['server_options' => ['search_path' => 'one', 'jit' => 'off']]);
        $sameSorted = EndpointIdentity::of($base + ['server_options' => ['jit' => 'off', 'search_path' => 'one']]);
        $two = EndpointIdentity::of($base + ['server_options' => ['search_path' => 'two', 'jit' => 'off']]);

        self::assertFalse($without->equals($one));
        self::assertTrue($one->equals($sameSorted), 'The keys are sorted.');
        self::assertFalse($one->equals($two));
    }

    private function file(): string
    {
        $file = SqliteFiles::create();
        $this->beforeApplicationDestroyed(static function () use ($file): void {
            PackageSession::releaseAll();
            SqliteFiles::remove($file);
        });

        return $file;
    }
}
