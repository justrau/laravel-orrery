<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Tia;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\ConsideredRisky;
use PHPUnit\Event\Test\ConsideredRiskySubscriber;
use PHPUnit\Event\Test\DataProviderMethodCalled;
use PHPUnit\Event\Test\DataProviderMethodCalledSubscriber;
use PHPUnit\Event\Test\DataProviderMethodFinished;
use PHPUnit\Event\Test\DataProviderMethodFinishedSubscriber;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;
use PHPUnit\Event\Test\AfterLastTestMethodErrored;
use PHPUnit\Event\Test\AfterLastTestMethodErroredSubscriber;
use PHPUnit\Event\Test\AfterLastTestMethodFailed;
use PHPUnit\Event\Test\AfterLastTestMethodFailedSubscriber;
use PHPUnit\Event\Test\BeforeFirstTestMethodErrored;
use PHPUnit\Event\Test\BeforeFirstTestMethodErroredSubscriber;
use PHPUnit\Event\Test\BeforeFirstTestMethodFailed;
use PHPUnit\Event\Test\BeforeFirstTestMethodFailedSubscriber;
use PHPUnit\Event\Test\ErrorTriggered;
use PHPUnit\Event\Test\ErrorTriggeredSubscriber;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;
use PHPUnit\Event\Test\PhpunitWarningTriggered;
use PHPUnit\Event\Test\PhpunitWarningTriggeredSubscriber;
use PHPUnit\Event\Test\PhpWarningTriggered;
use PHPUnit\Event\Test\PhpWarningTriggeredSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\Test\WarningTriggered;
use PHPUnit\Event\Test\WarningTriggeredSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Event\TestSuite\Finished as SuiteFinished;
use PHPUnit\Event\TestSuite\FinishedSubscriber as SuiteFinishedSubscriber;
use PHPUnit\Event\TestSuite\Started as SuiteStarted;
use PHPUnit\Event\TestSuite\StartedSubscriber as SuiteStartedSubscriber;
use PHPUnit\Event\TestSuite\TestSuiteForTestClass;
use PHPUnit\Metadata\Group;
use PHPUnit\Runner\Extension\Extension as PhpUnitExtension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * The PHPUnit extension of bin/tia record. bin/tia passes it with
 * --extension; phpunit.xml.dist does not register it. Without
 * ORRERY_TIA_RECORD it does nothing at all: no subscriber, no coverage.
 */
final class Extension implements PhpUnitExtension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $directory = getenv(Recorder::RECORD);

        if (! is_string($directory) || $directory === '') {
            return;
        }

        Recorder::begin($directory);

        $classOf = static fn (mixed $test): ?string => $test instanceof TestMethod ? $test->className() : null;

        $facade->registerSubscribers(
            new class implements SuiteStartedSubscriber
            {
                public function notify(SuiteStarted $event): void
                {
                    $suite = $event->testSuite();

                    if (! $suite instanceof TestSuiteForTestClass) {
                        return;
                    }

                    $groups = [];

                    foreach ($suite->tests() as $test) {
                        if ($test instanceof TestMethod) {
                            foreach ($test->metadata()->isGroup() as $group) {
                                if ($group instanceof Group) {
                                    $groups[$group->groupName()] = true;
                                }
                            }
                        }
                    }

                    Recorder::classStarted($suite->className(), $suite->file(), array_keys($groups), $suite->count());
                }
            },
            new class implements SuiteFinishedSubscriber
            {
                public function notify(SuiteFinished $event): void
                {
                    if ($event->testSuite() instanceof TestSuiteForTestClass) {
                        Recorder::enter(null);
                    }
                }
            },
            new class implements DataProviderMethodCalledSubscriber
            {
                public function notify(DataProviderMethodCalled $event): void
                {
                    Recorder::enter($event->testMethod()->className());
                }
            },
            new class implements DataProviderMethodFinishedSubscriber
            {
                public function notify(DataProviderMethodFinished $event): void
                {
                    Recorder::enter(null);
                }
            },
            new class($classOf) implements PreparationStartedSubscriber
            {
                public function __construct(private readonly \Closure $classOf)
                {
                }

                public function notify(PreparationStarted $event): void
                {
                    $class = ($this->classOf)($event->test());

                    if ($class !== null) {
                        Recorder::enter($class);
                    }
                }
            },
            new class implements ExecutionFinishedSubscriber
            {
                public function notify(ExecutionFinished $event): void
                {
                    Recorder::finish();
                }
            },
        );

        foreach ([FailedSubscriber::class, ErroredSubscriber::class, ConsideredRiskySubscriber::class, WarningTriggeredSubscriber::class, PhpWarningTriggeredSubscriber::class, PhpunitWarningTriggeredSubscriber::class, ErrorTriggeredSubscriber::class] as $interface) {
            $facade->registerSubscriber(self::failure($interface, $classOf));
        }

        // setUpBeforeClass() and tearDownAfterClass(): the event names only the
        // class. The Failed events exist from PHPUnit 12 on.
        foreach ([BeforeFirstTestMethodErroredSubscriber::class, BeforeFirstTestMethodFailedSubscriber::class, AfterLastTestMethodErroredSubscriber::class, AfterLastTestMethodFailedSubscriber::class] as $interface) {
            if (interface_exists($interface)) {
                $facade->registerSubscriber(self::classFailure($interface));
            }
        }
    }

    /**
     * A subscriber that marks the class of a failed, errored, risky or
     * warning test as failed.
     *
     * @param  class-string  $interface
     */
    private static function failure(string $interface, \Closure $classOf): object
    {
        $mark = static function (object $event) use ($classOf): void {
            // A warning under @ fails nothing: PHPUnit reports it, but not as an issue.
            if ((method_exists($event, 'wasSuppressed') && $event->wasSuppressed()) || (method_exists($event, 'ignoredByBaseline') && $event->ignoredByBaseline())) {
                return;
            }

            $class = $classOf($event->test());

            if ($class !== null) {
                Recorder::failed($class);
            }
        };

        return match ($interface) {
            FailedSubscriber::class => new class($mark) implements FailedSubscriber
            {
                public function __construct(private readonly \Closure $mark)
                {
                }

                public function notify(Failed $event): void
                {
                    ($this->mark)($event);
                }
            },
            ErroredSubscriber::class => new class($mark) implements ErroredSubscriber
            {
                public function __construct(private readonly \Closure $mark)
                {
                }

                public function notify(Errored $event): void
                {
                    ($this->mark)($event);
                }
            },
            ConsideredRiskySubscriber::class => new class($mark) implements ConsideredRiskySubscriber
            {
                public function __construct(private readonly \Closure $mark)
                {
                }

                public function notify(ConsideredRisky $event): void
                {
                    ($this->mark)($event);
                }
            },
            WarningTriggeredSubscriber::class => new class($mark) implements WarningTriggeredSubscriber
            {
                public function __construct(private readonly \Closure $mark)
                {
                }

                public function notify(WarningTriggered $event): void
                {
                    ($this->mark)($event);
                }
            },
            PhpunitWarningTriggeredSubscriber::class => new class($mark) implements PhpunitWarningTriggeredSubscriber
            {
                public function __construct(private readonly \Closure $mark)
                {
                }

                public function notify(PhpunitWarningTriggered $event): void
                {
                    ($this->mark)($event);
                }
            },
            ErrorTriggeredSubscriber::class => new class($mark) implements ErrorTriggeredSubscriber
            {
                public function __construct(private readonly \Closure $mark)
                {
                }

                public function notify(ErrorTriggered $event): void
                {
                    ($this->mark)($event);
                }
            },
            default => new class($mark) implements PhpWarningTriggeredSubscriber
            {
                public function __construct(private readonly \Closure $mark)
                {
                }

                public function notify(PhpWarningTriggered $event): void
                {
                    ($this->mark)($event);
                }
            },
        };
    }

    /**
     * A subscriber that marks the class of a failed setUpBeforeClass() or
     * tearDownAfterClass() as failed.
     *
     * @param  class-string  $interface
     */
    private static function classFailure(string $interface): object
    {
        return match ($interface) {
            BeforeFirstTestMethodErroredSubscriber::class => new class implements BeforeFirstTestMethodErroredSubscriber
            {
                public function notify(BeforeFirstTestMethodErrored $event): void
                {
                    Recorder::failed($event->testClassName());
                }
            },
            BeforeFirstTestMethodFailedSubscriber::class => new class implements BeforeFirstTestMethodFailedSubscriber
            {
                public function notify(BeforeFirstTestMethodFailed $event): void
                {
                    Recorder::failed($event->testClassName());
                }
            },
            AfterLastTestMethodErroredSubscriber::class => new class implements AfterLastTestMethodErroredSubscriber
            {
                public function notify(AfterLastTestMethodErrored $event): void
                {
                    Recorder::failed($event->testClassName());
                }
            },
            default => new class implements AfterLastTestMethodFailedSubscriber
            {
                public function notify(AfterLastTestMethodFailed $event): void
                {
                    Recorder::failed($event->testClassName());
                }
            },
        };
    }
}
