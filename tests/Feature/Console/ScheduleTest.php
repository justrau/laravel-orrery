<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Justrau\Orrery\Tests\TestCase;

/**
 * The schedule of the package commands (only
 * orrery:tick, orrery:snapshot is gone).
 */
final class ScheduleTest extends TestCase
{
    /**
     * Rejects: an unbounded overlap lock, and a schedule that keeps orrery:snapshot.
     */
    public function test_tick_uses_one_server_and_a_bounded_overlap_lock(): void
    {
        $tick = $this->scheduled('orrery:tick');
        self::assertSame('* * * * *', $tick->expression, 'tick runs every minute');
        self::assertTrue($tick->onOneServer, 'tick runs on one server');
        self::assertTrue($tick->withoutOverlapping, 'tick never overlaps');
        self::assertSame(10, $tick->expiresAt, 'the overlap lock of tick expires after 10 minutes');

        self::assertSame([], $this->entries('orrery:snapshot'), 'the snapshot command is not scheduled any more');
        self::assertArrayNotHasKey('orrery:snapshot', Artisan::all(), 'the snapshot command is gone');
    }

    /**
     * Rejects: an ignored `orrery.schedule` key.
     */
    public function test_schedule_false_registers_no_package_events(): void
    {
        $this->app['config']->set('orrery.schedule', false);

        // A fresh schedule: the afterResolving callback runs again with the key false.
        $this->app->forgetInstance(Schedule::class);
        $events = $this->app->make(Schedule::class)->events();

        $package = array_filter($events, static fn (Event $event): bool => str_contains((string) $event->command, 'orrery:'));
        self::assertSame([], array_values(array_map(static fn (Event $event): string => (string) $event->command, $package)), 'the package scheduled commands with orrery.schedule false');

        // Control: with the key true, a fresh schedule has the tick.
        $this->app['config']->set('orrery.schedule', true);
        $this->app->forgetInstance(Schedule::class);
        $this->scheduled('orrery:tick');

        // An empty ORRERY_SCHEDULE= means the default.
        $this->app['config']->set('orrery.schedule', '');
        $this->app->forgetInstance(Schedule::class);
        $this->scheduled('orrery:tick');
    }

    private function scheduled(string $command): Event
    {
        $events = $this->entries($command);

        self::assertCount(1, $events, "one schedule entry for {$command}");

        return $events[0];
    }

    /**
     * @return list<Event>
     */
    private function entries(string $command): array
    {
        return array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            static fn (Event $event): bool => str_contains((string) $event->command, $command),
        ));
    }
}
