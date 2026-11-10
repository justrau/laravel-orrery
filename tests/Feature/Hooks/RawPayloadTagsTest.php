<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Justrau\Orrery\Observation\TagNormalizer;
use Justrau\Orrery\Tests\Support\Fixtures\Lifecycle\GivenTagsJob;

/**
 * The stored tags of a job are normalised as the payload classifier
 * normalises them (TagNormalizer), also for a payload that the classifier
 * never saw (pushRaw(), another producer), and a tag with a NUL byte is
 * never stored: Postgres stops a bound text at NUL, so `a\0b` would become
 * the tag row `a`.
 */
final class RawPayloadTagsTest extends HooksTestCase
{
    /**
     * Rejects: a store that keeps a raw payload's untrimmed tag, drops (or
     * keeps whole) an oversized one, or stores a tag with a NUL byte (as
     * `a` on Postgres).
     */
    public function test_a_raw_payload_stores_the_normalised_tags(): void
    {
        $uuid = strtolower((string) Str::uuid());
        $long = str_repeat('x', 300);

        $this->queue()->pushRaw(self::rawPayload($uuid, ['tags' => ['orders ', $long, "a\0b", ' orders', 7]]));

        self::assertSame('pending', $this->rowOrFail($uuid)->status);
        $expected = ['7', 'orders', (string) TagNormalizer::normalize($long)];
        sort($expected, SORT_STRING);
        self::assertSame($expected, $this->tagsOf($uuid));
        self::assertSame(215, strlen($expected[2]), 'the long tag is cut and hashed, not dropped');
    }

    /**
     * Rejects: a classifier that passes a tag with a NUL byte to the store.
     */
    public function test_a_classified_job_with_a_nul_tag_gets_no_tag_row_of_its_prefix(): void
    {
        Queue::connection('database')->push(new GivenTagsJob(["a\0b", 'vip']));

        $uuids = $this->mirror()->table('orrery_jobs')->pluck('uuid')->all();
        self::assertCount(1, $uuids);
        self::assertSame(['vip'], $this->tagsOf((string) $uuids[0]));
        $payload = json_decode((string) DB::table('jobs')->value('payload'), true);
        self::assertIsArray($payload);
        self::assertSame(['vip'], $payload['tags'] ?? null, 'the classifier drops the NUL tag from the payload');
        self::assertSame(0, $this->mirror()->table('orrery_job_tags')->where('tag', 'a')->count());
    }

    /**
     * @return list<string>
     */
    private function tagsOf(string $uuid): array
    {
        $tags = array_map('strval', $this->mirror()->table('orrery_job_tags')->where('job_uuid', $uuid)->pluck('tag')->all());
        sort($tags, SORT_STRING);

        return $tags;
    }
}
