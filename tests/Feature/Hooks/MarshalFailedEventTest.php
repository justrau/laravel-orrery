<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Events\JobFailed;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\SiblingFileUnavailable;
use Justrau\Orrery\Tests\Feature\Jobs\FailingJob;
use Justrau\Orrery\Tests\Support\FreshSchema;
use RuntimeException;

/**
 * The package's own JobFailed event (MarshalFailedEvent, README "What it
 * is"): the application listens to it, so it is no package observation.
 */
#[FreshSchema]
final class MarshalFailedEventTest extends HooksTestCase
{
    /**
     * Rejects: a marshal listener inside the watcher, so the application's
     * listeners of the package's JobFailed get no event while the mirror
     * breaker is open, and an application listener's exception that counts
     * as a package error. It would still pass a listener that
     * swallows the application's exception without a report.
     */
    public function test_the_failed_event_reaches_the_application_while_the_breaker_is_open(): void
    {
        Queue::push(new FailingJob);

        $marshalled = 0;
        Event::listen(JobFailed::class, static function () use (&$marshalled): void {
            $marshalled++;

            throw new RuntimeException('an application listener fails');
        });

        Watcher::instance()->run('test:lost', static function (): never {
            throw new SiblingFileUnavailable('test.sqlite', 'the test session is lost');
        });
        self::assertTrue(Watcher::instance()->breakerOpen());

        $this->workAndLogFailures();

        self::assertSame(1, $marshalled, "The application's listener got no event while the breaker was open.");
        self::assertArrayNotHasKey('listener:'.\Justrau\Orrery\Listeners\MarshalFailedEvent::class, Degradation::record()['errors'] ?? [], "The application listener's error was recorded as a package error.");
    }
}
