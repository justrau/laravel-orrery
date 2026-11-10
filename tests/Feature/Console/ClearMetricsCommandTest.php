<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Console;

use Illuminate\Support\Carbon;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Support\Fixtures\Metrics\SeedsSamples;
use Justrau\Orrery\Tests\TestCase;

/**
 * orrery:clear-metrics over orrery_samples.
 * SampleCountsTest has the readers after a clear.
 */
final class ClearMetricsCommandTest extends TestCase
{
    use SeedsSamples;

    private const B = 1_790_000_040;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: a clear that deletes the queue state (the 24 h history and the over_limit_since
     * carry), that deletes the invalid intervals, that keeps M (so the cleared span reads as
     * covered zeros), or that keeps a total or job row.
     */
    public function test_clear_deletes_the_counts_and_keeps_the_queue_state_and_the_invalid_minutes(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(self::B + 30.0, 'UTC'));
        $this->seedCompletion('job', 'App\\Jobs\\Mail', 100.0, self::B - 120);
        $this->seedCompletion('queue', 'database:default', 100.0, self::B - 120);
        $this->mirror()->table(SampleCounts::TABLE)->where('scope', 'queue')->update(['ready' => 7, 'workers' => 2, 'oldest_age' => '12.000000', 'over_limit' => true, 'over_limit_since' => self::B - 300, 'over_limit_since_censored' => true]);
        $this->coverMinutes(self::B - 300, self::B);
        // A trim past the bound destroyed rows of the current minute: the clear must keep that interval.
        $this->mirror()->table('orrery_settings')->insert(['key' => SampleCounts::INVALID, 'value' => json_encode(['version' => 3, 'intervals' => [[self::B - 3600, self::B - 3540], [self::B, self::B + 60]]])]);

        $this->artisan('orrery:clear-metrics')->assertExitCode(0);

        self::assertSame(0, $this->mirror()->table(SampleCounts::TABLE)->whereIn('scope', ['total', 'job'])->count(), 'no total and no job row');
        $queue = $this->mirror()->table(SampleCounts::TABLE)->where('scope', 'queue')->first();
        self::assertNotNull($queue);
        self::assertSame([0, 0, 0, 0], [(int) $queue->completed, (int) $queue->failed, (int) $queue->runtime_ms_sum, (int) $queue->runtime_count], 'the counts go');
        self::assertSame([7, 2, 12.0, true, self::B - 300, true], [(int) $queue->ready, (int) $queue->workers, (float) $queue->oldest_age, (bool) $queue->over_limit, (int) $queue->over_limit_since, (bool) $queue->over_limit_since_censored], 'the queue state stays');
        self::assertNull($this->mirror()->table('orrery_settings')->where('key', SampleCounts::COUNTED_THROUGH)->value('value'), 'M is gone');
        self::assertNull($this->mirror()->table('orrery_settings')->where('key', SampleCounts::COUNTED_AT)->value('value'));

        $invalid = json_decode((string) $this->mirror()->table('orrery_settings')->where('key', SampleCounts::INVALID)->value('value'), true);
        self::assertSame(4, $invalid['version'], 'the clear raised the version');
        self::assertSame([[SampleCounts::minuteOf(Clock::now() - 24 * 3600), self::B + 60]], $invalid['intervals'], 'the cleared span (now - 24 h to the current minute) merged with the kept intervals, the current minute included');
    }
}
