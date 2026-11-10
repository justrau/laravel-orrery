<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Projection;

use Justrau\Orrery\Http\Projection\JobProjection;
use Justrau\Orrery\Tests\Controller\Fakes\InvokableCanary;
use Justrau\Orrery\Tests\TestCase;

/**
 * JobProjection::batch(): the batch fields of
 * the batch endpoints, from the columns of the data-only reader.
 */
final class BatchProjectionTest extends TestCase
{
    /**
     * Rejects: a progress that divides by zero for an empty batch, a
     * progress or processed count invented from an unknown count (null is
     * unknown, never 0), and times sent as strings.
     */
    public function test_counts_progress_and_times(): void
    {
        $empty = $this->project(['total_jobs' => '0', 'pending_jobs' => '0', 'failed_jobs' => '0', 'created_at' => '1790000000', 'finished_at' => '1790000005']);

        self::assertSame(0, $empty['progress']);
        self::assertSame(0, $empty['processed_jobs']);
        self::assertSame(1790000000.0, $empty['created_at']);
        self::assertSame(1790000005.0, $empty['finished_at']);
        self::assertNull($empty['cancelled_at']);

        $partial = $this->project(['total_jobs' => 3, 'pending_jobs' => 2, 'failed_jobs' => 1]);
        self::assertSame(1, $partial['processed_jobs']);
        self::assertSame(33, $partial['progress']);

        $unknown = $this->project(['total_jobs' => null, 'pending_jobs' => 2]);
        self::assertNull($unknown['processed_jobs']);
        self::assertNull($unknown['progress']);
        self::assertNull($unknown['total_jobs']);
    }

    /**
     * Rejects: a batch name returned unredacted (free text that
     * the UI never sends back), a name cut before the redaction (a secret
     * across the cut stays partly visible), and a name longer than 255
     * characters.
     */
    public function test_the_name_is_redacted_whole_then_cut(): void
    {
        $secret = $this->project(['name' => 'Import token=canary-batch-secret now']);
        self::assertStringNotContainsString('canary-batch-secret', (string) $secret['name']);
        self::assertStringStartsWith('Import ', (string) $secret['name']);

        $long = $this->project(['name' => str_repeat('é', 300)]);
        self::assertSame(JobProjection::BATCH_NAME_CHARACTERS, mb_strlen((string) $long['name']));

        self::assertNull($this->project(['name' => null])['name']);

        // A secret across the cut at 255 characters: cut first, its start would stay visible.
        $straddle = $this->project(['name' => str_repeat('a', 230).' https://user:canary-straddle-secret@host/x']);
        self::assertStringNotContainsString('canary', (string) $straddle['name']);
    }

    /**
     * Rejects: options returned as the raw serialized text, as the parser's
     * entry tree (the screen reads `options.queue`), with a closure's
     * content, or loaded over the byte limit.
     */
    public function test_the_options(): void
    {
        $serialized = serialize(['queue' => 'emails', 'allowFailures' => false, 'tags' => ['a', 'b'], 'password' => 'canary-option-password']);
        $options = $this->project(['options' => $serialized, 'options_bytes' => strlen($serialized)])['options'];

        self::assertEquals((object) ['queue' => 'emails', 'allowFailures' => false, 'tags' => ['a', 'b'], 'password' => '[redacted]'], $options);

        $omitted = $this->project(['options' => null, 'options_bytes' => JobProjection::OPTIONS_BYTES + 1])['options'];
        self::assertSame(['omitted' => 'too_large', 'bytes' => JobProjection::OPTIONS_BYTES + 1], $omitted);

        self::assertNull($this->project(['options' => null, 'options_bytes' => null])['options']);
    }

    /**
     * Rejects: callbacks masked only when the options become a plain object:
     * a key that is not valid UTF-8 keeps the parser's entry tree, and the
     * callbacks of that tree must be masked too.
     */
    public function test_callbacks_are_masked_in_every_form(): void
    {
        $serialized = serialize(['then' => [new InvokableCanary('canary-tree-callback')], "\xff" => 1]);
        $options = $this->project(['options' => $serialized, 'options_bytes' => strlen($serialized)])['options'];
        $json = (string) json_encode($options, JSON_INVALID_UTF8_SUBSTITUTE);

        self::assertStringNotContainsString('canary', $json);
        self::assertStringNotContainsString('InvokableCanary', $json);
        self::assertStringContainsString('{"closure":true}', $json);
    }

    /**
     * @param  array<string, mixed>  $columns
     * @return array<string, mixed>
     */
    private function project(array $columns): array
    {
        $row = (object) array_merge([
            'id' => 'b-1', 'name' => 'Batch', 'total_jobs' => 1, 'pending_jobs' => 0, 'failed_jobs' => 0,
            'created_at' => 1790000000, 'cancelled_at' => null, 'finished_at' => null, 'options' => null, 'options_bytes' => null,
        ], $columns);

        return $this->app->make(JobProjection::class)->batch($row);
    }
}
