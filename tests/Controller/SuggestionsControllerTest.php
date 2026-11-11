<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Justrau\Orrery\Http\Suggestions;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\ControllerTest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET /api/suggestions?field=class|tag&q= (the Jobs filter bar's
 * suggestions): bounded reads of the newest jobs, the job samples and the
 * monitored tags, matched in PHP.
 */
final class SuggestionsControllerTest extends ControllerTest
{
    private const T = 1_790_000_000;

    /** The request time: 12 s into the minute 1_790_000_100. */
    private const NOW = 1_790_000_112.0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: a class list that is not distinct, that ranks a match inside the name above one at the start of the
     * name or of its short name, that drops the newest-first order within a rank, that matches case-sensitively, or
     * that misses the classes of the samples (a class whose rows were trimmed).
     * Still passes: a ranking that also matches at the start of a namespace segment (not required).
     */
    public function test_class_suggestions_rank_start_matches_first_then_the_newest(): void
    {
        $this->job('App\\Jobs\\ResendInvoice', self::T + 1);
        $this->job('App\\Jobs\\SendInvoice', self::T + 2);
        $this->job('App\\Jobs\\SendInvoice', self::T + 3);
        $this->job('App\\Jobs\\Unrelated', self::T + 4);
        $this->job('Send\\Mailer', self::T + 5);
        $this->sample('App\\Reports\\SendDigest', 1_790_000_040);
        $this->sample('App\\Jobs\\SendInvoice', 1_790_000_100);

        $response = $this->getJson('/orrery/api/suggestions?field=class&q=SEND')->assertOk();

        self::assertSame('class', $response->json('field'));
        self::assertSame('SEND', $response->json('q'));
        self::assertSame(
            ['Send\\Mailer', 'App\\Jobs\\SendInvoice', 'App\\Reports\\SendDigest', 'App\\Jobs\\ResendInvoice'],
            array_column($response->json('values'), 'value'),
        );
        self::assertFalse($response->json('truncated'));
        self::assertFalse($response->json('limited'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertSame('30', $response->headers->getCacheControlDirective('max-age'));
    }

    /**
     * Rejects: a query that treats % or _ as SQL wildcards (a LIKE without escaping): `%` and `_` match only names
     * that contain them.
     * Still passes: a matcher that also ignores accents (not used).
     */
    public function test_like_wildcards_match_only_themselves(): void
    {
        $this->job('App\\Jobs\\Plain', self::T + 1);
        $this->job('App\\Jobs\\With_Underscore', self::T + 2);
        $this->job('App\\Jobs\\Hundred%', self::T + 3);

        self::assertSame(['App\\Jobs\\With_Underscore'], array_column($this->getJson('/orrery/api/suggestions?field=class&q=_')->assertOk()->json('values'), 'value'));
        self::assertSame(['App\\Jobs\\Hundred%'], array_column($this->getJson('/orrery/api/suggestions?field=class&q=%25')->assertOk()->json('values'), 'value'));
    }

    /**
     * Rejects: an empty query that answers nothing (the list opens before any typing), or more than MAX_VALUES
     * values without `truncated`.
     * Still passes: a list that orders the extra values in another way (they are cut).
     */
    public function test_an_empty_query_gives_the_newest_values_up_to_the_cap(): void
    {
        for ($n = 1; $n <= Suggestions::MAX_VALUES + 3; $n++) {
            $this->job(sprintf('App\\Jobs\\Job%02d', $n), self::T + $n);
        }

        $response = $this->getJson('/orrery/api/suggestions?field=class')->assertOk();
        $values = array_column($response->json('values'), 'value');

        self::assertCount(Suggestions::MAX_VALUES, $values);
        self::assertSame(sprintf('App\\Jobs\\Job%02d', Suggestions::MAX_VALUES + 3), $values[0], 'the newest first');
        self::assertTrue($response->json('truncated'));
        self::assertSame('', $response->json('q'));
    }

    /**
     * Rejects: an unbounded scan of the jobs (a class seen only before the newest JOB_SCAN jobs is still found), or
     * a bounded scan that does not say `limited` (the list may then miss older values).
     * Still passes: a bound slightly above JOB_SCAN (the test inserts five rows past it).
     */
    public function test_the_job_scan_is_bounded_and_says_so(): void
    {
        $rows = [];

        for ($n = 1; $n <= 5; $n++) {
            $rows[] = $this->jobRow('App\\Jobs\\Ancient', self::T - 1000 + $n);
        }

        for ($n = 1; $n <= Suggestions::JOB_SCAN; $n++) {
            $rows[] = $this->jobRow('App\\Jobs\\Recent', self::T + $n);
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $this->mirror()->table('orrery_jobs')->insert($chunk);
        }

        $response = $this->getJson('/orrery/api/suggestions?field=class&q=ancient')->assertOk();

        self::assertSame([], $response->json('values'));
        self::assertTrue($response->json('limited'));
    }

    /**
     * Rejects: a sample read grouped by the name (MySQL's default collation merges `Sync` and `SYNC` into one
     * class), or a window other than the current minute and the SAMPLE_MINUTES - 1 before it.
     * Still passes: a window that also reads a future minute (none is stored).
     */
    public function test_class_samples_are_byte_exact_and_read_from_the_window(): void
    {
        $current = 1_790_000_100;
        $first = $current - (Suggestions::SAMPLE_MINUTES - 1) * 60;

        $this->sample('App\\Jobs\\Sync', $current);
        $this->sample('App\\Jobs\\SYNC', $first);
        $this->sample('App\\Jobs\\Syncer', $first - 60);

        $response = $this->getJson('/orrery/api/suggestions?field=class&q=sync')->assertOk();

        self::assertSame(['App\\Jobs\\Sync', 'App\\Jobs\\SYNC'], array_column($response->json('values'), 'value'), 'the first minute of the window is read, the one before it is not');
        self::assertFalse($response->json('limited'));
    }

    /**
     * Rejects: a class cap that says `limited` at exactly SAMPLE_CLASSES classes, or not at one more.
     * Still passes: a cap that reads the classes in another order (only the flag is checked).
     */
    public function test_the_class_cap_says_limited_only_past_it(): void
    {
        $rows = [];

        for ($n = 0; $n < Suggestions::SAMPLE_CLASSES; $n++) {
            $rows[] = $this->sampleRow(sprintf('App\\Jobs\\Many%03d', $n), 1_790_000_040);
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $this->mirror()->table(SampleCounts::TABLE)->insert($chunk);
        }

        self::assertFalse($this->getJson('/orrery/api/suggestions?field=class&q=many')->assertOk()->json('limited'));

        $this->sample('App\\Jobs\\ManyMore', 1_790_000_040);

        self::assertTrue($this->getJson('/orrery/api/suggestions?field=class&q=many')->assertOk()->json('limited'));
    }

    /**
     * Rejects: a sample read without a row cap (a class seen only in the oldest rows of a full window is still
     * found), or a cap that does not say `limited`.
     * Still passes: a cap a little above SAMPLE_ROWS (the newer minutes hold 60 rows more than the cap).
     */
    public function test_the_sample_read_stops_at_its_row_cap(): void
    {
        $current = 1_790_000_100;
        $first = $current - (Suggestions::SAMPLE_MINUTES - 1) * 60;
        $perMinute = (int) ceil((Suggestions::SAMPLE_ROWS + 60) / (Suggestions::SAMPLE_MINUTES - 1));
        $rows = [];

        for ($minute = $first + 60; $minute <= $current; $minute += 60) {
            for ($n = 0; $n < $perMinute; $n++) {
                $rows[] = $this->sampleRow(sprintf('App\\Jobs\\Busy%03d', $n), $minute);
            }
        }

        $rows[] = $this->sampleRow('App\\Jobs\\Ancient', $first);

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->mirror()->table(SampleCounts::TABLE)->insert($chunk);
        }

        $response = $this->getJson('/orrery/api/suggestions?field=class&q=ancient')->assertOk();

        self::assertSame([], $response->json('values'));
        self::assertTrue($response->json('limited'));
    }

    /**
     * Rejects: the tags of a job past the TAG_JOB_SCAN newest (the extra row that detects the bound), or a hit
     * bound without `limited`.
     * Still passes: a bound slightly above TAG_JOB_SCAN (one row past it is tested).
     */
    public function test_the_tag_scan_reads_only_the_newest_jobs(): void
    {
        $oldest = null;
        $rows = [];

        for ($n = 0; $n <= Suggestions::TAG_JOB_SCAN; $n++) {
            $row = $this->jobRow('App\\Jobs\\T', self::T + $n);
            $oldest ??= $row['uuid'];
            $rows[] = $row;
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $this->mirror()->table('orrery_jobs')->insert($chunk);
        }

        $this->tag('old:tag', $oldest, self::T);
        $this->tag('new:tag', $rows[Suggestions::TAG_JOB_SCAN]['uuid'], self::T + Suggestions::TAG_JOB_SCAN);

        $response = $this->getJson('/orrery/api/suggestions?field=tag')->assertOk();

        self::assertSame([['value' => 'new:tag', 'monitored' => false]], $response->json('values'));
        self::assertTrue($response->json('limited'));
    }

    /**
     * Rejects: a tag list without the monitored tags, monitored tags not first, the tags of the newest jobs
     * missing, a duplicate, or a monitored flag that is wrong.
     * Still passes: monitored tags in another order among themselves (byte order of the tag index is used).
     */
    public function test_tag_suggestions_put_monitored_tags_first_then_the_newest_jobs_tags(): void
    {
        $this->mirror()->table('orrery_monitored_tags')->insert([
            ['tag' => 'tenant:7', 'created_at' => Clock::toDecimal(self::T)],
            ['tag' => 'region:eu', 'created_at' => Clock::toDecimal(self::T)],
        ]);
        $old = $this->job('App\\Jobs\\A', self::T + 1);
        $new = $this->job('App\\Jobs\\B', self::T + 2);
        $this->tag('tenant:3', $old, self::T + 1);
        $this->tag('tenant:9', $new, self::T + 2);
        $this->tag('tenant:7', $new, self::T + 2);

        $values = $this->getJson('/orrery/api/suggestions?field=tag&q=tenant')->assertOk()->json('values');

        self::assertSame([
            ['value' => 'tenant:7', 'monitored' => true],
            ['value' => 'tenant:9', 'monitored' => false],
            ['value' => 'tenant:3', 'monitored' => false],
        ], $values);
    }

    /**
     * Rejects: a field or query that is not checked (an array, NUL, invalid UTF-8, a text past MAX_QUERY), or a
     * 500 for one of them.
     * Still passes: a message with other words (the SPA decides on `error` and `field`).
     */
    #[DataProvider('invalidQueries')]
    public function test_an_invalid_parameter_gives_422(string $query, string $field): void
    {
        $this->getJson('/orrery/api/suggestions?'.$query)
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_parameter')
            ->assertJsonPath('field', $field);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidQueries(): array
    {
        return [
            'no field' => ['q=a', 'field'],
            'another field' => ['field=queue&q=a', 'field'],
            'an array field' => ['field[]=class', 'field'],
            'an array query' => ['field=class&q[]=a', 'q'],
            'a NUL byte' => ['field=class&q=a%00b', 'q'],
            'invalid UTF-8' => ['field=tag&q=%FF', 'q'],
            'too long' => ['field=class&q='.str_repeat('a', Suggestions::MAX_QUERY + 1), 'q'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jobRow(string $name, float $pushedAt): array
    {
        return [
            'uuid' => strtolower((string) Str::uuid()),
            'connection' => 'database',
            'queue' => 'default',
            'name' => $name,
            'status' => 'completed',
            'payload' => '{}',
            'payload_bytes' => 2,
            'pushed_at' => Clock::toDecimal($pushedAt),
            'updated_at' => Clock::toDecimal($pushedAt),
        ];
    }

    /** Inserts a job row and returns its uuid. */
    private function job(string $name, float $pushedAt): string
    {
        $row = $this->jobRow($name, $pushedAt);
        $this->mirror()->table('orrery_jobs')->insert($row);

        return $row['uuid'];
    }

    private function tag(string $tag, string $uuid, float $pushedAt): void
    {
        $this->mirror()->table('orrery_job_tags')->insert(['tag' => $tag, 'job_uuid' => $uuid, 'pushed_at' => Clock::toDecimal($pushedAt)]);
    }

    private function sample(string $name, int $minute): void
    {
        $this->mirror()->table(SampleCounts::TABLE)->insert($this->sampleRow($name, $minute));
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleRow(string $name, int $minute): array
    {
        return [
            'scope' => 'job', 'series' => SampleCounts::series('job', $name), 'connection' => null, 'queue' => null, 'name' => $name, 'minute' => $minute,
            'completed' => 1, 'failed' => 0, 'runtime_ms_sum' => 0, 'runtime_count' => 0, 'pushed' => null,
        ];
    }
}
