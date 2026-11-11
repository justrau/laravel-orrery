<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Support\Carbon;
use Justrau\Orrery\Monitoring\BlindGaps;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;

/**
 * GET /api/health `samples`: the per-minute counts frontier
 * M and its lag, and its unknown states; and `blind_gaps`.
 */
final class HealthSamplesTest extends ControllerTest
{
    private const B = 1_790_000_040;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: a lag of 0 (healthy) before the first count, for a value that is not M, or for an M after now, and a lag that is not now - M.
     */
    public function test_samples_report_the_frontier_and_its_lag(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(self::B + 725.5, 'UTC'));

        self::assertSame(['state' => 'none', 'counted_through' => null, 'lag_seconds' => null], $this->samples());

        $this->app->make(SampleCounts::class)->run();
        self::assertSame(['state' => 'ok', 'counted_through' => self::B + 720, 'lag_seconds' => 5.5], $this->samples());

        Carbon::setTestNow(Carbon::createFromTimestamp(self::B + 720 + 900.0, 'UTC'));
        self::assertSame(['state' => 'ok', 'counted_through' => self::B + 720, 'lag_seconds' => 900.0], $this->samples(), 'The lag grows while the counts stall.');

        // M after now + 60 s (a clock that moved back): the lag is unknown, never 0.
        $this->mirror()->table('orrery_settings')->where('key', SampleCounts::COUNTED_THROUGH)->update(['value' => (string) (self::B + 720 + 900 + 120)]);
        self::assertSame(['state' => 'unknown', 'counted_through' => self::B + 720 + 900 + 120, 'lag_seconds' => null], $this->samples());

        $this->mirror()->table('orrery_settings')->where('key', SampleCounts::COUNTED_THROUGH)->update(['value' => 'garbage']);
        self::assertSame(['state' => 'unreadable', 'counted_through' => null, 'lag_seconds' => null], $this->samples());
    }

    /**
     * Rejects: a failed read reported as `none` or as a lag.
     */
    public function test_a_failed_read_is_unreadable(): void
    {
        Watcher::failOperationsForTesting(['health:samples']);

        self::assertSame(['state' => 'unreadable', 'counted_through' => null, 'lag_seconds' => null], $this->samples());
    }

    /**
     * Rejects: a health response without the recorded blind gaps, in the wrong order, unbounded, or `[]` for a failed read.
     */
    public function test_blind_gaps_list_the_newest_recorded_gaps(): void
    {
        self::assertSame([], $this->health('blind_gaps'));

        $gaps = [];

        for ($i = 0; $i < BlindGaps::RECENT + 2; $i++) {
            $gaps[] = [self::B + 1000.0 * $i, self::B + 1000.0 * $i + 400.5];
        }

        $this->mirror()->table('orrery_settings')->insert(['key' => BlindGaps::KEY, 'value' => json_encode(['checked_through' => self::B + 20000.0, 'gaps' => $gaps], JSON_PRESERVE_ZERO_FRACTION)]);
        $listed = $this->health('blind_gaps');

        self::assertCount(BlindGaps::RECENT, $listed);
        self::assertSame(['from' => self::B + 11000.0, 'to' => self::B + 11400.5], $listed[0], 'Newest first.');
        self::assertSame(['from' => self::B + 2000.0, 'to' => self::B + 2400.5], $listed[BlindGaps::RECENT - 1]);

        Watcher::failOperationsForTesting(['health:blind_gaps']);
        self::assertNull($this->health('blind_gaps'), 'A failed read is unknown, never "no gap".');
    }

    private function health(string $key): mixed
    {
        return $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk()->json($key);
    }

    /**
     * @return array<string, mixed>
     */
    private function samples(): array
    {
        $samples = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk()->json('samples');
        self::assertIsArray($samples);

        return $samples;
    }
}
