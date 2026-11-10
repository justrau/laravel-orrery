<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Support;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Listeners\ObserveJobProcessed;
use Justrau\Orrery\Observation\DatabaseDriverFilter;
use Justrau\Orrery\Registration\Modules;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\Fixtures\Registration\ListenerTestModule;
use Justrau\Orrery\Tests\TestCase;
use RuntimeException;

/**
 * Listener wrapping and the driver filter.
 */
final class ListenerWrappingTest extends TestCase
{
    /**
     * The test module must exist before the provider registers.
     */
    protected function getPackageProviders($app): array
    {
        ListenerTestModule::$event = null;
        ListenerTestModule::$mode = 'throw';
        ListenerTestModule::$value = null;

        if ($this->name() === 'test_module_listeners_are_wrapped') {
            ListenerTestModule::$event = JobProcessed::class;
        } elseif ($this->name() === 'test_looping_listener_value_is_returned') {
            ListenerTestModule::$event = Looping::class;
            ListenerTestModule::$mode = 'return';
            ListenerTestModule::$value = false;
        }

        if (ListenerTestModule::$event !== null) {
            Modules::addForTesting(ListenerTestModule::class);
        }

        return parent::getPackageProviders($app);
    }

    /**
     * Rejects: registering the listener class directly, so its exception stops the dispatch.
     */
    public function test_a_throwing_package_listener_does_not_skip_application_listeners(): void
    {
        $this->app->instance(ObserveJobProcessed::class, new class
        {
            public function handle(JobProcessed $event): void
            {
                throw new RuntimeException('the package listener fails');
            }
        });

        $appListenerRan = false;
        $jobException = null;
        Event::listen(JobProcessed::class, static function () use (&$appListenerRan): void {
            $appListenerRan = true;
        });
        Event::listen(JobExceptionOccurred::class, static function (JobExceptionOccurred $event) use (&$jobException): void {
            $jobException = $event->exception->getMessage();
        });

        Queue::push(new BasicJob);
        $this->work();

        self::assertNull($jobException, 'the package listener turned a successful job into JobExceptionOccurred');
        self::assertTrue($appListenerRan, "the application's own JobProcessed listener did not run");
        self::assertSame(0, $this->app['db']->table('failed_jobs')->count());
    }

    /**
     * Rejects: wrapping only the legacy EventMap listeners.
     */
    public function test_module_listeners_are_wrapped(): void
    {
        $appListenerRan = false;
        Event::listen(JobProcessed::class, static function () use (&$appListenerRan): void {
            $appListenerRan = true;
        });

        $thrown = null;

        try {
            $this->app['events']->dispatch(new JobProcessed('database', new SyncJob($this->app, '{}', 'database', 'default')));
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        self::assertSame(1, ListenerTestModule::$calls, 'the module listener did not run');
        self::assertTrue($appListenerRan, "the module listener's exception skipped the application's listener");
        self::assertNull($thrown, "the module listener's exception reached the dispatcher's caller");
    }

    /**
     * Rejects: a wrapper that returns null, so a paused queue's false never reaches the worker.
     */
    public function test_looping_listener_value_is_returned(): void
    {
        $result = $this->app['events']->until(new Looping('database', 'default'));

        self::assertSame(1, ListenerTestModule::$calls);
        self::assertFalse($result, "the listener's false did not reach the caller of until()");
    }

    /**
     * Rejects: listeners that handle redis or sync events.
     */
    public function test_events_of_non_database_connections_are_rejected(): void
    {
        $this->app['config']->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default']);
        $this->app['config']->set('queue.connections.sync', ['driver' => 'sync']);
        $this->app['config']->set('queue.connections.second-database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default']);

        $handled = [];
        foreach ([ObserveJobProcessed::class] as $listener) {
            $this->app->instance($listener, new class($handled, $listener)
            {
                /** @param list<string> $handled */
                public function __construct(private array &$handled, private string $listener) {}

                public function handle(JobProcessed $event): void
                {
                    $this->handled[] = $event->connectionName.':'.class_basename($this->listener);
                }
            });
        }

        foreach (['redis', 'sync', 'unknown', 'database', 'second-database'] as $connection) {
            $this->app['events']->dispatch(new JobProcessed($connection, new SyncJob($this->app, '{}', $connection, 'default')));
        }

        self::assertSame([
            'database:ObserveJobProcessed',
            'second-database:ObserveJobProcessed',
        ], $handled);

        self::assertTrue(DatabaseDriverFilter::isDatabase('database'));
        self::assertFalse(DatabaseDriverFilter::isDatabase('redis'));
        self::assertTrue(DatabaseDriverFilter::accepts(new \stdClass), 'an event without connectionName was rejected');
    }
}
