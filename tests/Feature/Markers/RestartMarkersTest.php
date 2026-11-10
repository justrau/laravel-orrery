<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Markers;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Justrau\Orrery\Exceptions\WatchedOperationFailed;
use Justrau\Orrery\Markers\Markers;
use Justrau\Orrery\Markers\Phases\RestartMarkerPhase;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\TestCase;
use RuntimeException;

/**
 * The restart markers (the tick phase `restart_markers`), the
 * deploy markers of `orrery:deployed`, and the marker retention.
 */
final class RestartMarkersTest extends TestCase
{
    private const NOW = 1_790_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->at(self::NOW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Known limit: it would pass a phase that compares the value with the newest marker instead of the checkpoint while no marker is pruned.
     */
    public function test_a_changed_key_gives_one_marker_and_a_repeated_tick_gives_none(): void
    {
        $this->artisan('queue:restart')->assertExitCode(0);
        $this->at(self::NOW + 30);
        $this->artisan('orrery:tick')->assertExitCode(0);

        self::assertSame([
            ['kind' => 'restart', 'at' => (float) self::NOW, 'label' => 'Restart requested', 'source' => 'queue:restart'],
        ], $this->markers(), 'the marker is at the value\'s own time');
        self::assertSame(Clock::toDecimal(self::NOW), $this->checkpoint());

        $this->at(self::NOW + 90);
        $this->artisan('orrery:tick')->assertExitCode(0);
        self::assertCount(1, $this->markers(), 'an unchanged key gives no marker');

        $this->at(self::NOW + 120);
        $this->artisan('queue:restart')->assertExitCode(0);
        $this->at(self::NOW + 150);
        $this->artisan('orrery:tick')->assertExitCode(0);

        self::assertSame([(float) self::NOW, (float) (self::NOW + 120)], array_column($this->markers(), 'at'));
        self::assertSame(Clock::toDecimal(self::NOW + 120), $this->checkpoint());
    }

    /**
     * Known limit: it would pass a first-observation window of 8 days only for values older than 8 days.
     */
    public function test_the_first_observation_of_an_old_value_sets_the_checkpoint_without_a_marker(): void
    {
        $old = self::NOW - Markers::RETENTION_SECONDS - 1;

        // Directly: the marker retention of a tick would hide an insert of this old marker.
        self::assertFalse($this->app->make(Markers::class)->observeRestart($old), 'no marker for an old first value');
        self::assertSame([], $this->markers());
        $this->mirror()->table('orrery_settings')->where('key', Markers::CHECKPOINT_KEY)->delete();

        $this->cache()->forever(Markers::CACHE_KEY, $old);
        $this->artisan('orrery:tick')->assertExitCode(0);

        self::assertSame([], $this->markers());
        self::assertSame(Clock::toDecimal($old), $this->checkpoint());

        $this->cache()->forever(Markers::CACHE_KEY, self::NOW - 10);
        $this->artisan('orrery:tick')->assertExitCode(0);
        self::assertSame([(float) (self::NOW - 10)], array_column($this->markers(), 'at'), 'the next change gives a marker');
    }

    /**
     * Known limit: it would pass a first observation that inserts a marker for a value exactly 7 days old (the boundary is not tested).
     */
    public function test_the_first_observation_of_a_recent_value_gives_a_marker(): void
    {
        $this->cache()->forever(Markers::CACHE_KEY, self::NOW - 3600);

        $this->artisan('orrery:tick')->assertExitCode(0);

        self::assertSame([(float) (self::NOW - 3600)], array_column($this->markers(), 'at'));
    }

    /**
     * Known limit: it would pass a phase that treats an older value as a change only when it is at least 60 s older.
     */
    public function test_an_older_value_than_the_checkpoint_is_a_restart(): void
    {
        $this->cache()->forever(Markers::CACHE_KEY, self::NOW - 60);
        $this->artisan('orrery:tick')->assertExitCode(0);

        // Laravel's Worker restarts on any change of the value (!=), also a value that moved backwards.
        $this->cache()->forever(Markers::CACHE_KEY, self::NOW - 600);
        $this->artisan('orrery:tick')->assertExitCode(0);

        self::assertSame([(float) (self::NOW - 600), (float) (self::NOW - 60)], array_column($this->markers(), 'at'));
        self::assertSame(Clock::toDecimal(self::NOW - 600), $this->checkpoint(), 'the checkpoint takes the new value');
    }

    /**
     * Known limit: it would pass a clamp to now that uses the tick's start instead of the transaction's time (both are the test clock here).
     */
    public function test_a_value_in_the_future_gives_a_marker_at_now(): void
    {
        $this->cache()->forever(Markers::CACHE_KEY, self::NOW + 3600);
        $this->artisan('orrery:tick')->assertExitCode(0);

        self::assertSame([(float) self::NOW], array_column($this->markers(), 'at'), 'never a marker in the future');
        self::assertSame(Clock::toDecimal(self::NOW + 3600), $this->checkpoint(), 'the checkpoint keeps the value itself');

        $this->at(self::NOW + 60);
        $this->artisan('orrery:tick')->assertExitCode(0);
        self::assertCount(1, $this->markers(), 'the same future value gives no second marker');
    }

    /**
     * Known limit: it would pass a phase that clears the checkpoint on a missing key when the same value never returns.
     */
    public function test_a_missing_or_cleared_key_changes_nothing(): void
    {
        $this->artisan('orrery:tick')->assertExitCode(0);
        self::assertNull($this->checkpoint(), 'no key: no checkpoint');

        $this->cache()->forever(Markers::CACHE_KEY, self::NOW - 60);
        $this->artisan('orrery:tick')->assertExitCode(0);
        $this->cache()->forget(Markers::CACHE_KEY);
        $this->artisan('orrery:tick')->assertExitCode(0);

        self::assertSame(Clock::toDecimal(self::NOW - 60), $this->checkpoint(), 'a cleared key keeps the checkpoint');

        $this->cache()->forever(Markers::CACHE_KEY, self::NOW - 60);
        $this->artisan('orrery:tick')->assertExitCode(0);
        self::assertCount(1, $this->markers(), 'the same value after a clear gives no second marker');
    }

    /**
     * Known limit: it would pass a phase that reports the error twice.
     */
    public function test_a_cache_error_is_reported_and_the_phase_skips(): void
    {
        Exceptions::fake();
        $this->app->instance('cache.store', new Repository(new class extends ArrayStore
        {
            public function get($key): mixed
            {
                throw new RuntimeException('The cache store is down.');
            }
        }));

        $this->mirror()->table('orrery_markers')->insert(['kind' => 'deploy', 'at' => Clock::toDecimal(self::NOW - Markers::RETENTION_SECONDS - 5), 'label' => null, 'source' => 'orrery:deployed']);

        $this->artisan('orrery:tick')->assertExitCode(0);

        Exceptions::assertReported(static fn (WatchedOperationFailed $error): bool => $error->operation === RestartMarkerPhase::CACHE_OPERATION && $error->causeClass === RuntimeException::class && str_contains($error->getMessage(), 'The cache store is down.'));
        self::assertSame(
            [RestartMarkerPhase::CACHE_OPERATION],
            $this->mirror()->table('orrery_health_events')->pluck('last_error_operation')->all(),
            'the health events record the cache error',
        );
        self::assertNull($this->checkpoint());
        self::assertSame([], $this->markers(), 'the later marker retention still ran');
    }

    /**
     * Known limit: it would pass a retention that deletes markers older than 7 days plus one tick.
     */
    public function test_marker_retention_keeps_7_days(): void
    {
        foreach ([Markers::RETENTION_SECONDS + 1, Markers::RETENTION_SECONDS - 1, 60] as $age) {
            $this->mirror()->table('orrery_markers')->insert(['kind' => 'deploy', 'at' => Clock::toDecimal(self::NOW - $age), 'label' => null, 'source' => 'orrery:deployed']);
        }

        $this->artisan('orrery:tick')->assertExitCode(0);

        self::assertSame([(float) (self::NOW - Markers::RETENTION_SECONDS + 1), (float) (self::NOW - 60)], array_column($this->markers(), 'at'));
    }

    /**
     * Known limit: it would pass a prune that deletes 500 ids per statement only when fewer than 1,000 markers are old.
     */
    public function test_marker_retention_binds_at_most_999_values_per_statement(): void
    {
        $rows = [];

        for ($i = 0; $i < 1200; $i++) {
            $rows[] = ['kind' => 'deploy', 'at' => Clock::toDecimal(self::NOW - Markers::RETENTION_SECONDS - 10 - $i), 'label' => null, 'source' => 'orrery:deployed'];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            $this->mirror()->table('orrery_markers')->insert($chunk);
        }

        $largest = 0;
        Event::listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$largest): void {
            $largest = max($largest, count($query->bindings));
        });

        $this->artisan('orrery:tick')->assertExitCode(0);

        self::assertSame([], $this->markers());
        self::assertLessThanOrEqual(999, $largest);
    }

    /**
     * Known limit: it would pass a command that drops the label entirely when it has control characters.
     */
    public function test_deployed_records_a_deploy_marker_with_a_clean_label(): void
    {
        $this->artisan('orrery:deployed', ['--label' => "v1.2\x00\n".str_repeat('é', 300)])->assertExitCode(0);
        $this->at(self::NOW + 1);
        $this->artisan('orrery:deployed')->assertExitCode(0);

        $markers = $this->markers();
        self::assertSame(['deploy', 'deploy'], array_column($markers, 'kind'));
        self::assertSame(['orrery:deployed', 'orrery:deployed'], array_column($markers, 'source'));
        self::assertSame([(float) self::NOW, (float) (self::NOW + 1)], array_column($markers, 'at'));
        self::assertSame('v1.2 '.str_repeat('é', 186), $markers[0]['label'], 'control characters become one space, at most 191 characters');
        self::assertNull($markers[1]['label']);
    }

    private function at(float $time): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp($time, 'UTC'));
    }

    private function cache(): Repository
    {
        /** @var Repository $cache */
        $cache = $this->app->make('cache.store');

        return $cache;
    }

    private function checkpoint(): ?string
    {
        $value = $this->mirror()->table('orrery_settings')->where('key', Markers::CHECKPOINT_KEY)->value('value');

        return $value === null ? null : Clock::toDecimal((float) $value);
    }

    /**
     * @return list<array{kind: string, at: float, label: ?string, source: string}>
     */
    private function markers(): array
    {
        return $this->mirror()->table('orrery_markers')->orderBy('at')->orderBy('id')->get()
            ->map(static fn (object $row): array => [
                'kind' => (string) $row->kind,
                'at' => (float) $row->at,
                'label' => $row->label === null ? null : (string) $row->label,
                'source' => (string) $row->source,
            ])
            ->values()
            ->all();
    }
}
