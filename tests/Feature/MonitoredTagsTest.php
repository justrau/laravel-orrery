<?php

namespace Justrau\Orrery\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Monitoring\MonitoringVersion;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Retention\Jobs\TaggedJob;
use Justrau\Orrery\Tests\TestCase;

/**
 * Tags of a push: orrery_job_tags stores every normalised tag of
 * each job, monitored or not, with the job's pushed_at. Monitoring only sets
 * the row's `monitored` flag and its `monitor_version`.
 */
class MonitoredTagsTest extends TestCase
{
    /**
     * Rejects: a push that stores only its monitored tags, or a tagged row without the monitored flag or the version of its insert.
     * Still passes: tag rows whose pushed_at differs from the row's pushed_at by less than a microsecond (the values are compared as stored).
     */
    public function test_a_push_stores_every_tag_and_flags_a_monitored_one(): void
    {
        $this->app->make(MonitoringVersion::class)->monitor('important');

        $uuid = $this->push(new TaggedJob(['orders', 'important']));

        $row = $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->first();
        self::assertNotNull($row);
        self::assertSame([1, 1], [(int) $row->monitored, (int) $row->monitor_version], 'the row is monitored at V = 1');

        $tags = $this->mirror()->table('orrery_job_tags')->orderBy('tag')->get();
        self::assertSame(['important', 'orders'], $tags->pluck('tag')->all(), 'every tag of the job, not only the monitored one');
        self::assertSame([$uuid, $uuid], $tags->pluck('job_uuid')->all());

        foreach ($tags as $tag) {
            self::assertSame((string) $row->pushed_at, (string) $tag->pushed_at, "the pushed_at of the tag {$tag->tag}");
        }
    }

    /**
     * Rejects: the old rule that only monitored tags are stored, or a monitored flag on a row whose tags are not monitored.
     * Still passes: a flag computed from a stale monitored-tag set of the same version (the set does not change here).
     */
    public function test_tags_are_stored_when_none_is_monitored(): void
    {
        $this->app->make(MonitoringVersion::class)->monitor('completely-unrelated-tag-nobody-uses');

        $tagged = $this->push(new TaggedJob(['orders', 'vip']));
        $untagged = $this->push(new BasicJob);

        self::assertSame(
            [['orders', $tagged], ['vip', $tagged]],
            $this->mirror()->table('orrery_job_tags')->orderBy('tag')->get()->map(static fn (object $tag): array => [$tag->tag, $tag->job_uuid])->all(),
            'the tags of the tagged job, and none for the untagged job',
        );

        $row = $this->mirror()->table('orrery_jobs')->where('uuid', $tagged)->first();
        self::assertNotNull($row);
        self::assertSame([0, 1], [(int) $row->monitored, (int) $row->monitor_version], 'a tagged row that is not monitored still stores V');
        self::assertSame(2, $this->mirror()->table('orrery_jobs')->count());
        self::assertSame(0, (int) $this->mirror()->table('orrery_jobs')->where('uuid', $untagged)->value('monitored'));
    }

    private function push(object $job): string
    {
        $id = Queue::push($job);
        $payload = json_decode((string) DB::table('jobs')->where('id', $id)->value('payload'), true, 512, JSON_THROW_ON_ERROR);

        return (string) $payload['uuid'];
    }
}
