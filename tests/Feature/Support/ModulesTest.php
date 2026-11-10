<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Support;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Exceptions\Handler;
use Justrau\Orrery\OrreryServiceProvider;
use Justrau\Orrery\Registration\Modules;
use Justrau\Orrery\Registration\Modules\StorageModule;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use Justrau\Orrery\Tests\Support\Fixtures\Registration\EarlyTestModule;
use Justrau\Orrery\Tests\Support\Fixtures\Registration\LateTestModule;
use Justrau\Orrery\Tests\Support\Fixtures\Registration\ListenerTestModule;
use Justrau\Orrery\Tests\TestCase;
use LogicException;
use ReflectionClass;
use stdClass;
use Throwable;

/**
 * The registration modules: the explicit list (OrreryServiceProvider::MODULES),
 * the test modules and their anchors, listeners before the legacy event map,
 * and the reset hook.
 */
final class ModulesTest extends TestCase
{
    /**
     * The test modules must exist before the provider registers, so they are
     * added here, while Testbench builds the application.
     */
    protected function getPackageProviders($app): array
    {
        EarlyTestModule::$log = [];
        EarlyTestModule::$resets = [];
        EarlyTestModule::$event = self::legacyEvent()[0];
        Modules::addForTesting(LateTestModule::class);
        Modules::addForTesting(EarlyTestModule::class, StorageModule::class);

        return parent::getPackageProviders($app);
    }

    /**
     * Rejects: a test module anchored before StorageModule (which binds the package sessions) that registers after it.
     */
    public function test_modules_register_and_boot_in_list_order(): void
    {
        self::assertSame(
            ['Early.register sessions=unbound', 'Late.register sessions=bound', 'Early.boot', 'Late.boot'],
            EarlyTestModule::$log,
        );

        $order = array_values(array_filter(
            array_map(static fn (object $module): string => $module::class, Modules::all()),
            static fn (string $class): bool => in_array($class, [EarlyTestModule::class, StorageModule::class, LateTestModule::class], true),
        ));

        self::assertSame([EarlyTestModule::class, StorageModule::class, LateTestModule::class], $order);
        self::assertTrue($this->app->bound(PackageSession::class));
        self::assertTrue($this->app->bound(PackageTransaction::class));
        self::assertSame($this->app->make(PackageSession::class), $this->app->make(PackageSession::class), 'PackageSession is a singleton.');
    }

    /**
     * The nine modules, in their order: storage,
     * runtime, observation, lifecycle, operations, maintenance, monitoring,
     * control, HTTP.
     *
     * Rejects: a list in alphabetical order, or two modules swapped.
     */
    public function test_module_list_keeps_the_previous_order(): void
    {
        $expected = [
            StorageModule::class,
            Modules\RuntimeModule::class,
            Modules\ObservationModule::class,
            Modules\LifecycleModule::class,
            Modules\OperationsModule::class,
            Modules\MaintenanceModule::class,
            Modules\MonitoringModule::class,
            Modules\ControlModule::class,
            Modules\HttpModule::class,
        ];

        self::assertSame($expected, OrreryServiceProvider::MODULES);

        $registered = array_values(array_filter(
            array_map(static fn (object $module): string => $module::class, Modules::all()),
            static fn (string $class): bool => ! in_array($class, [EarlyTestModule::class, LateTestModule::class], true),
        ));

        self::assertSame($expected, $registered, 'Modules::all() does not follow MODULES.');
    }

    /**
     * Rejects: a module file that is not listed, a listed class without a
     * file, and a duplicate line.
     */
    public function test_every_module_file_is_listed_once(): void
    {
        $files = glob(dirname(__DIR__, 3).'/src/Registration/Modules/*.php');
        self::assertIsArray($files);
        self::assertNotSame([], $files);

        $classes = array_map(static fn (string $file): string => 'Justrau\\Orrery\\Registration\\Modules\\'.basename($file, '.php'), $files);
        sort($classes);

        $listed = OrreryServiceProvider::MODULES;
        self::assertSame(array_values(array_unique($listed)), $listed, 'MODULES lists a module twice.');

        sort($listed);
        self::assertSame($classes, $listed);
    }

    /**
     * Rejects: a kept glob() (or another directory read) next to the list.
     */
    public function test_boot_reads_no_directory(): void
    {
        $forbidden = ['glob', 'scandir', 'opendir', 'readdir', 'directoryiterator', 'filesystemiterator', 'recursivedirectoryiterator'];

        foreach (['src/Registration/Modules.php', 'src/OrreryServiceProvider.php'] as $path) {
            $code = file_get_contents(dirname(__DIR__, 3).'/'.$path);
            self::assertIsString($code);

            $names = [];

            foreach (token_get_all($code) as $token) {
                if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $parts = explode('\\', $token[1]);
                    $names[] = strtolower((string) end($parts));
                }
            }

            self::assertSame([], array_values(array_intersect($names, $forbidden)), "{$path} reads a directory.");
        }
    }

    /**
     * Rejects: keeping only the last test module of one anchor, and a test
     * module that is added twice registering twice.
     */
    public function test_test_modules_of_one_anchor_keep_call_order_and_register_once(): void
    {
        // Start from no test modules; the teardown resets again.
        Modules::resetAllForTesting();

        Modules::addForTesting(ListenerTestModule::class, Modules\LifecycleModule::class);
        Modules::addForTesting(EarlyTestModule::class, Modules\LifecycleModule::class);
        Modules::addForTesting(ListenerTestModule::class);

        $all = array_map(static fn (object $module): string => $module::class, Modules::all());
        $at = array_search(Modules\LifecycleModule::class, $all, true);
        self::assertIsInt($at);

        self::assertSame(
            [Modules\ObservationModule::class, ListenerTestModule::class, EarlyTestModule::class, Modules\LifecycleModule::class],
            array_slice($all, $at - 3, 4),
        );
        self::assertCount(count(OrreryServiceProvider::MODULES) + 2, $all);
        self::assertSame(Modules\HttpModule::class, end($all));
    }

    /**
     * Rejects: test modules appended after MODULES whatever their anchor,
     * and an unknown anchor that is ignored silently.
     */
    public function test_test_module_is_inserted_before_its_anchor(): void
    {
        $classes = static fn (): array => array_map(static fn (object $module): string => $module::class, Modules::all());

        self::assertSame([EarlyTestModule::class, ...OrreryServiceProvider::MODULES, LateTestModule::class], $classes());

        Modules::addForTesting(ListenerTestModule::class, Modules\LifecycleModule::class);

        $all = $classes();
        $at = array_search(Modules\LifecycleModule::class, $all, true);
        self::assertIsInt($at);
        self::assertSame(ListenerTestModule::class, $all[$at - 1]);
        self::assertSame(Modules\ObservationModule::class, $all[$at - 2]);
        self::assertSame(LateTestModule::class, end($all));
        self::assertCount(count(OrreryServiceProvider::MODULES) + 3, $all);

        try {
            Modules::addForTesting(ListenerTestModule::class, 'Justrau\\Orrery\\Registration\\Modules\\NoSuchModule');
            self::fail('An unknown anchor was accepted.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('NoSuchModule', $exception->getMessage());
        }

        try {
            Modules::addForTesting(stdClass::class);
            self::fail('A class that is not a module was accepted.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('not a registration module', $exception->getMessage());
        }
    }

    /**
     * Rejects: modules that boot after the legacy listeners.
     */
    public function test_module_listeners_register_before_the_legacy_event_map(): void
    {
        [$event, $legacy] = self::legacyEvent();

        EarlyTestModule::$log = [];
        $spy = new class
        {
            public function handle(mixed $event): void
            {
                EarlyTestModule::$log[] = 'legacy';
            }
        };

        foreach ($legacy as $listener) {
            $this->app->instance($listener, $spy);
        }

        $this->app['events']->dispatch($event, [new stdClass]);

        self::assertSame(
            ['Early.listener', 'Late.listener', ...array_fill(0, count($legacy), 'legacy')],
            EarlyTestModule::$log,
        );
    }

    /**
     * The reset hook calls every module's reset and removes the test
     * modules. The package's container state is not its work:
     * it belongs to the application, which the next test builds new.
     *
     * Rejects: a reset hook that misses the modules, or keeps the test modules for the next test.
     */
    public function test_reset_calls_every_module_and_removes_test_modules(): void
    {
        EarlyTestModule::$resets = [];
        self::resetPackageState();

        self::assertSame([EarlyTestModule::class => 1, LateTestModule::class => 1], EarlyTestModule::$resets);

        $classes = array_map(static fn (object $module): string => $module::class, Modules::all());

        self::assertContains(StorageModule::class, $classes);
        self::assertNotContains(EarlyTestModule::class, $classes);
        self::assertNotContains(LateTestModule::class, $classes);
    }

    /**
     * Rejects: a boot resolution whose failure is swallowed (a process
     * singleton then lives for one Octane request, unnoticed), or one that
     * throws into the host's boot.
     */
    public function test_a_failing_boot_resolution_is_reported(): void
    {
        $reported = [];
        $this->app->instance(ExceptionHandler::class, new class($reported) extends Handler
        {
            /** @param  list<Throwable>  $reported */
            public function __construct(private array &$reported) {}

            public function report(Throwable $e): void
            {
                $this->reported[] = $e;
            }
        });
        $this->app->singleton('orrery.test.failing_binding', static function (): never {
            throw new \RuntimeException('the factory fails');
        });

        Modules::resolveAtBoot($this->app, ['orrery.test.failing_binding']);

        self::assertCount(1, $reported, 'One report, and no exception.');
        self::assertStringContainsString('boot:resolve:orrery.test.failing_binding', $reported[0]->getMessage());
    }

    /**
     * The first event of the legacy event map whose listeners run inline, and
     * those listeners.
     *
     * @return array{0: class-string, 1: list<class-string>}
     */
    private static function legacyEvent(): array
    {
        $events = (new ReflectionClass(OrreryServiceProvider::class))->getProperty('events')->getDefaultValue();

        foreach ((array) $events as $event => $listeners) {
            $inline = array_filter($listeners, static fn (string $listener): bool => ! is_subclass_of($listener, ShouldQueue::class));

            if ($listeners !== [] && count($inline) === count($listeners)) {
                return [$event, array_values($listeners)];
            }
        }

        throw new LogicException('The legacy event map has no event with inline listeners.');
    }
}
