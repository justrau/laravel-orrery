<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Observation;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Str;
use Justrau\Orrery\DatabaseQueue;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\SiblingFileUnavailable;
use Justrau\Orrery\Tests\Feature\Hooks\EndToEndTestCase;
use Justrau\Orrery\Tests\Support\FreshSchema;
use stdClass;

/**
 * The generation stamp of pushRaw(): decode, set the
 * top-level `orreryGen`, remove a stale top-level `orreryRetry`, encode. It
 * never depends on the watcher, it never touches a nested member, and on
 * any failure it publishes the payload unchanged. The host always gets the
 * parent's return value.
 */
#[FreshSchema]
final class PayloadStampTest extends EndToEndTestCase
{
    private const OLD = 'cccccccccccccccccccccccccccccccc';

    protected function setUp(): void
    {
        parent::setUp();

        PayloadStampHandler::$data = [];
        FailingStampQueue::$encodes = 0;
    }

    protected function tearDown(): void
    {
        PayloadStampHandler::$data = [];
        FailingStampQueue::$encodes = 0;

        parent::tearDown();
    }

    /**
     * Rejects: the stamp inside Watcher::run(), which returns the payload
     * unchanged while the breaker is open (a native republish of an old
     * package retry would then keep its generation and its retry token).
     */
    public function test_open_breaker_still_stamps_a_new_generation(): void
    {
        Watcher::instance()->run('test:lost', static function (): never {
            throw new SiblingFileUnavailable('test.sqlite', 'the test session is lost');
        });
        self::assertTrue(Watcher::instance()->breakerOpen());

        $id = $this->hooks()->pushRaw($this->payload(['orreryRetry' => ['cycle' => 2, 'token' => 'stale-token']]));

        $row = $this->onlyQJob();
        self::assertSame((int) $row->id, (int) $id, "The host gets the parent's return value.");
        $published = json_decode((string) $row->payload, true);
        self::assertIsArray($published);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) ($published['orreryGen'] ?? ''), 'A new generation.');
        self::assertNotSame(self::OLD, $published['orreryGen']);
        self::assertArrayNotHasKey('orreryRetry', $published, 'No stale retry token.');
    }

    /**
     * Rejects: any rewrite outside the top-level members, for example a key
     * set by a byte match (which also rewrites a nested `orreryGen` or a key
     * spelled `x\"orreryGen`).
     */
    public function test_nested_members_reach_the_handler_unchanged(): void
    {
        $data = [
            'orreryGen' => 'nested generation',
            'orreryRetry' => ['cycle' => 9, 'token' => 'nested token'],
            'x"orreryGen' => 'quoted key',
            'list' => [1, 2.5, 3.0, 'x\\y', 'é', null, true],
            'object' => new stdClass,
        ];
        $raw = $this->payload(['data' => $data]);
        $expected = json_decode($raw, true)['data'];

        $id = $this->hooks()->pushRaw($raw);

        $row = $this->onlyQJob();
        self::assertSame((int) $row->id, (int) $id);
        $published = json_decode((string) $row->payload);
        self::assertInstanceOf(stdClass::class, $published);
        self::assertNotSame(self::OLD, $published->orreryGen, 'The top-level generation is new.');
        self::assertSame(json_encode(json_decode($raw)->data), json_encode($published->data), 'The data member is unchanged, the empty object included.');

        $this->workHooks();

        self::assertCount(1, PayloadStampHandler::$data, 'The handler ran once.');
        self::assertSame($expected, PayloadStampHandler::$data[0], 'The handler gets the data unchanged.');
    }

    /**
     * Rejects: a failed stamp that throws into the host or publishes a
     * changed payload.
     */
    public function test_failed_encode_publishes_the_payload_unchanged(): void
    {
        $queue = new FailingStampQueue($this->q(), 'jobs', 'default', 90);
        $queue->setContainer($this->app);
        $queue->setConnectionName(self::QUEUE);
        $raw = $this->payload(['orreryRetry' => ['cycle' => 2, 'token' => 'kept']]);

        $id = $queue->pushRaw($raw);

        $row = $this->onlyQJob();
        self::assertSame((int) $row->id, (int) $id, "The host gets the parent's return value.");
        self::assertSame($raw, (string) $row->payload, 'The payload is published byte for byte.');
        self::assertSame(1, FailingStampQueue::$encodes, 'The encode ran and failed.');

        $mirrored = $this->rowOrFail(self::uuidOf($raw));
        self::assertSame(self::OLD, $mirrored->cycle_gen, 'The republish observation carries the published generation.');
    }

    /**
     * Rejects: a stamp that decodes an integer beyond PHP's int64 into a float
     * and publishes it changed (`18446744073709551615` becomes
     * `1.8446744073709552e+19`), against "nothing else of the payload
     * changes". It would still pass a stamp that keeps such a
     * payload unchanged also when the big number is inside a string; the
     * second part asserts that a digit run in a string is stamped.
     */
    public function test_an_integer_beyond_int64_publishes_the_payload_unchanged(): void
    {
        $raw = substr($this->payload(), 0, -1).',"data":{"id":18446744073709551615}}';

        $this->hooks()->pushRaw($raw);

        self::assertSame($raw, (string) $this->onlyQJob()->payload, 'The payload is published byte for byte.');

        $this->q()->table('jobs')->delete();
        $text = $this->payload(['data' => ['id' => '18446744073709551615']]);
        $this->hooks()->pushRaw($text);
        $published = json_decode((string) $this->onlyQJob()->payload, true);
        self::assertIsArray($published);
        self::assertNotSame(self::OLD, $published['orreryGen'] ?? null, 'A digit run inside a string kept the stamp off.');
        self::assertSame('18446744073709551615', $published['data']['id']);
    }

    /**
     * A raw payload of another producer with the generation OLD.
     *
     * @param  array<string, mixed>  $extra
     */
    private function payload(array $extra = []): string
    {
        return (string) json_encode([
            'uuid' => strtolower((string) Str::uuid()),
            'job' => PayloadStampHandler::class.'@handle',
            'attempts' => 0,
            'orreryGen' => self::OLD,
        ] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function onlyQJob(): object
    {
        $jobs = $this->qJobs();
        self::assertCount(1, $jobs, 'One jobs row.');

        return $jobs[0];
    }
}

/**
 * The handler of the stamp tests: it records the data that it receives.
 */
final class PayloadStampHandler
{
    /** @var list<mixed> */
    public static array $data = [];

    public function handle(Job $job, mixed $data = null): void
    {
        self::$data[] = $data;
        $job->delete();
    }
}

/**
 * A swapped queue whose stamp encode fails.
 */
final class FailingStampQueue extends DatabaseQueue
{
    public static int $encodes = 0;

    protected static function encodeStamped(stdClass $payload): string|false
    {
        self::$encodes++;

        return false;
    }
}
