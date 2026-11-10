<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Support\Carbon;
use Justrau\Orrery\Markers\Markers;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\ControllerTest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET /api/markers?since=.
 */
final class MarkersControllerTest extends ControllerTest
{
    private const NOW = 1_790_000_000;

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
     * Known limit: it would pass an endpoint that orders by id when the ids follow the times (they do not here).
     */
    public function test_the_markers_since_the_bound_newest_first(): void
    {
        $this->marker('deploy', self::NOW - 30, 'v2', 'orrery:deployed');
        $this->marker('restart', self::NOW - 600, 'Restart requested', 'queue:restart');
        $this->marker('deploy', self::NOW - 300.25, null, 'orrery:deployed');
        $this->marker('restart', self::NOW - 3600, 'Restart requested', 'queue:restart');

        $response = $this->getJson('/orrery/api/markers?since='.(self::NOW - 600))->assertOk();

        self::assertSame((float) self::NOW, $response->json('as_of'));
        self::assertSame((float) (self::NOW - 600), $response->json('since'));
        self::assertFalse($response->json('truncated'));
        self::assertSame(
            [
                ['kind' => 'deploy', 'at' => (float) (self::NOW - 30), 'label' => 'v2', 'source' => 'orrery:deployed'],
                ['kind' => 'deploy', 'at' => self::NOW - 300.25, 'label' => null, 'source' => 'orrery:deployed'],
                ['kind' => 'restart', 'at' => (float) (self::NOW - 600), 'label' => 'Restart requested', 'source' => 'queue:restart'],
            ],
            array_map(static fn (array $marker): array => array_diff_key($marker, ['id' => true]), (array) $response->json('markers')),
        );
        self::assertIsInt($response->json('markers.0.id'));
    }

    /**
     * Known limit: it would pass a default of 7 days plus a few seconds.
     */
    public function test_without_since_the_range_is_the_last_7_days(): void
    {
        $this->marker('deploy', self::NOW - Markers::RETENTION_SECONDS + 10, null, 'orrery:deployed');
        $this->marker('deploy', self::NOW - Markers::RETENTION_SECONDS - 10, null, 'orrery:deployed');

        $response = $this->getJson('/orrery/api/markers')->assertOk();

        self::assertSame((float) (self::NOW - Markers::RETENTION_SECONDS), $response->json('since'));
        self::assertSame([(float) (self::NOW - Markers::RETENTION_SECONDS + 10)], array_column((array) $response->json('markers'), 'at'));
    }

    /**
     * Known limit: it would pass a clamp to 7 days plus a fraction of a second.
     */
    public function test_a_since_below_the_7_day_floor_is_raised_to_it(): void
    {
        $this->marker('deploy', self::NOW - Markers::RETENTION_SECONDS - 0.5, null, 'orrery:deployed');
        $this->marker('deploy', self::NOW - 60, null, 'orrery:deployed');

        $response = $this->getJson('/orrery/api/markers?since='.(self::NOW - Markers::RETENTION_SECONDS - 1))->assertOk();

        self::assertSame((float) (self::NOW - Markers::RETENTION_SECONDS), $response->json('since'), 'the bound actually used');
        self::assertSame([(float) (self::NOW - 60)], array_column((array) $response->json('markers'), 'at'));
    }

    /**
     * Known limit: it would pass a limit of 200 that reads 200 rows and guesses truncation from a count query.
     */
    public function test_at_most_200_markers_with_truncated(): void
    {
        $rows = [];

        for ($i = 0; $i < 201; $i++) {
            $rows[] = ['kind' => 'deploy', 'at' => Clock::toDecimal(self::NOW - $i), 'label' => null, 'source' => 'orrery:deployed'];
        }

        $this->mirror()->table('orrery_markers')->insert($rows);

        $response = $this->getJson('/orrery/api/markers?since='.(self::NOW - 3600))->assertOk();

        self::assertCount(200, (array) $response->json('markers'));
        self::assertTrue($response->json('truncated'));
        self::assertSame((float) (self::NOW - 199), $response->json('markers.199.at'), 'the newest 200');
    }

    /**
     * Known limit: it would pass a check that accepts "1e9" if it were numeric text (it is not tested).
     */
    #[DataProvider('invalidSince')]
    public function test_an_invalid_since_gives_422(string $query): void
    {
        $this->getJson('/orrery/api/markers?'.$query)
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_parameter');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSince(): array
    {
        return [
            'text' => ['since=yesterday'],
            'negative' => ['since=-5'],
            'array' => ['since[]=1'],
        ];
    }

    private function marker(string $kind, float $at, ?string $label, string $source): void
    {
        $this->mirror()->table('orrery_markers')->insert(['kind' => $kind, 'at' => Clock::toDecimal($at), 'label' => $label, 'source' => $source]);
    }
}
