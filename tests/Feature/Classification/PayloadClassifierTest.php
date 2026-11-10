<?php

namespace Justrau\Orrery\Tests\Feature\Classification;

use Closure;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Database\Models\Signal;
use Justrau\Orrery\DatabaseQueue;
use Justrau\Orrery\JobPayload;
use Justrau\Orrery\Observation\PayloadClassifier;
use Justrau\Orrery\Registration\Modules\ObservationModule;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tags;
use Justrau\Orrery\Tests\Feature\Classification\Jobs\ClassifiedJob;
use Justrau\Orrery\Tests\Feature\Classification\Jobs\EventAwareJob;
use Justrau\Orrery\Tests\Feature\Classification\Jobs\NestingEvent;
use Justrau\Orrery\Tests\Feature\Classification\Jobs\NestingJob;
use Justrau\Orrery\Tests\Feature\Classification\Jobs\NestingListener;
use Justrau\Orrery\Tests\Feature\Classification\Jobs\ScalarTagsEvent;
use Justrau\Orrery\Tests\Feature\Classification\Jobs\ScalarTagsListener;
use Justrau\Orrery\Tests\Feature\Classification\Jobs\SilencedTaggedJob;
use Justrau\Orrery\Tests\Feature\Classification\Jobs\ThrowingTagsJob;
use Justrau\Orrery\Tests\Feature\Classification\Jobs\ThrowingTagsListener;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\PackageSessionHelpers;
use Justrau\Orrery\Tests\TestCase;
use ReflectionMethod;

class PayloadClassifierTest extends TestCase
{
    use PackageSessionHelpers;

    /** The payload keys that the payload callback adds. */
    private const PACKAGE_KEYS = ['type', 'tags', 'tagsTruncated', 'silenced', 'pushedAt', 'orreryGen'];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('logging.default', 'null');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: a missing key, or a key read from another job.
     */
    public function test_object_job_gets_type_tags_silenced_pushed_at_and_generation(): void
    {
        $this->assertInstanceOf(DatabaseQueue::class, Queue::connection('database'));

        config([
            'orrery.silenced' => [BasicJob::class],
            'orrery.silenced_tags' => ['vip'],
        ]);

        $queued = [];
        Event::listen(JobQueued::class, static function (JobQueued $event) use (&$queued): void {
            $queued[$event->id] = $event->payload;
        });

        Carbon::setTestNow(Carbon::createFromFormat('U.u', '1790000000.123456'));
        $plain = Queue::push(new ClassifiedJob([' b', 'a', 'b ', 7]));

        Carbon::setTestNow(Carbon::createFromFormat('U.u', '1790000001.000001'));
        $silencedByContract = Queue::push(new SilencedTaggedJob);
        $silencedByTag = Queue::push(new ClassifiedJob(['vip', 'other']));
        $silencedByClass = Queue::push(new BasicJob);

        config(['orrery.tags.max_per_job' => 2]);
        $truncated = Queue::push(new ClassifiedJob(['1', '2', '3']));

        $plainPayload = $this->payload($plain);
        $this->assertSame('job', $plainPayload['type']);
        $this->assertSame(['b', 'a', '7'], $plainPayload['tags']);
        $this->assertFalse($plainPayload['tagsTruncated']);
        $this->assertFalse($plainPayload['silenced']);
        $this->assertSame('1790000000.123456', $plainPayload['pushedAt']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $plainPayload['orreryGen']);

        $contract = $this->payload($silencedByContract);
        $this->assertSame(['tenant:42'], $contract['tags']);
        $this->assertTrue($contract['silenced']);
        $this->assertSame('1790000001.000001', $contract['pushedAt']);

        $byTag = $this->payload($silencedByTag);
        $this->assertSame(['vip', 'other'], $byTag['tags']);
        $this->assertTrue($byTag['silenced']);

        $byClass = $this->payload($silencedByClass);
        $this->assertSame([], $byClass['tags']);
        $this->assertTrue($byClass['silenced']);

        $cut = $this->payload($truncated);
        $this->assertSame(['1', '2'], $cut['tags']);
        $this->assertTrue($cut['tagsTruncated']);
        $this->assertFalse($cut['silenced']);

        $generations = array_map(fn (int $id): string => $this->payload($id)['orreryGen'], [$plain, $silencedByContract, $silencedByTag, $silencedByClass, $truncated]);
        $this->assertCount(5, array_unique($generations), 'each job has its own generation');

        foreach ([$plain, $silencedByContract, $silencedByTag, $silencedByClass, $truncated] as $id) {
            $this->assertSame(self::PACKAGE_KEYS, array_values(array_intersect(array_keys($this->payload($id)), self::PACKAGE_KEYS)));
            // JobQueued carries the same string that Laravel inserted.
            $this->assertSame(DB::table('jobs')->where('id', $id)->value('payload'), $queued[$id] ?? null);
        }
    }

    /**
     * Rejects: calling tags() on a string job.
     */
    public function test_string_job_gets_only_type_silenced_and_generation(): void
    {
        Carbon::setTestNow(Carbon::createFromFormat('U.u', '1790000002.500000'));

        $id = Queue::push('App\\Handlers\\ImportHandler@handle', ['file' => 'a.csv']);

        $payload = $this->payload($id);
        $this->assertSame('job', $payload['type']);
        $this->assertSame('1790000002.500000', $payload['pushedAt']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $payload['orreryGen']);
        $this->assertFalse($payload['silenced']);
        $this->assertArrayNotHasKey('tags', $payload);
        $this->assertArrayNotHasKey('tagsTruncated', $payload);
        $this->assertSame(['file' => 'a.csv'], $payload['data']);
    }

    /**
     * Rejects: a prefix, class or is_a() match of the handler string, which silences an unlisted handler or loads a class.
     */
    public function test_string_job_is_silenced_only_by_its_exact_handler_string(): void
    {
        config(['orrery.silenced' => ['App\\Handlers\\Listed@handle', 'App\\Handlers\\Prefix', SilencedTaggedJob::class]]);

        $requested = [];
        $spy = static function (string $class) use (&$requested): void {
            if (str_starts_with($class, 'App\\Handlers') || str_contains($class, '@')) {
                $requested[] = $class;
            }
        };
        spl_autoload_register($spy, prepend: true);

        try {
            $listed = Queue::push('App\\Handlers\\Listed@handle');
            $prefix = Queue::push('App\\Handlers\\Prefix@handle');
            $unlisted = Queue::push('App\\Handlers\\Other@handle');
            // A listed class with the Silenced contract, used as a handler string.
            $contract = Queue::push(SilencedTaggedJob::class.'@handle');
        } finally {
            spl_autoload_unregister($spy);
        }

        $this->assertTrue($this->payload($listed)['silenced'], 'the listed handler string is not silenced');
        $this->assertFalse($this->payload($prefix)['silenced'], 'a listed class name that is only a prefix of the handler silenced it');
        $this->assertFalse($this->payload($unlisted)['silenced']);
        $this->assertFalse($this->payload($contract)['silenced'], 'the handler string was matched as a class');
        $this->assertSame([], $requested, 'the classification loaded a class for a string job');
    }

    /**
     * Rejects: a throw that reaches dispatch().
     */
    public function test_throwing_tags_method_gives_no_package_keys_and_the_job_is_queued(): void
    {
        ThrowingTagsJob::dispatch();

        $row = DB::table('jobs')->sole();
        $payload = json_decode($row->payload, true);

        $this->assertSame(ThrowingTagsJob::class, $payload['displayName']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $payload['uuid']);
        $this->assertSame([], array_values(array_intersect(array_keys($payload), self::PACKAGE_KEYS)));

        // A listener whose tags() throws while Tags keeps its event leaves
        // no event behind for the next job.
        $listener = new CallQueuedListener(ThrowingTagsListener::class, 'handle', [new NestingEvent]);

        try {
            Tags::for($listener);
            $this->fail('the tags() of the listener must throw');
        } catch (\RuntimeException $error) {
            $this->assertSame('tags() of ThrowingTagsListener failed', $error->getMessage());
        }

        $this->assertNull($this->tagsEvent(), 'Tags kept the event of the failed listener');

        $next = Queue::push(new EventAwareJob);
        $this->assertSame(['inner-of-listener', 'event:none'], $this->payload($next)['tags']);
    }

    /**
     * Rejects: package keys in a redis payload.
     */
    public function test_non_database_connection_is_skipped(): void
    {
        config([
            'queue.connections.redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default'],
            'queue.connections.sync' => ['driver' => 'sync'],
        ]);

        $redis = new RedisQueue($this->app['redis'], 'default');
        $redis->setConnectionName('redis');
        $redis->setContainer($this->app);

        $create = new ReflectionMethod($redis, 'createPayload');
        $payload = json_decode($create->invoke($redis, new ClassifiedJob(['x']), 'default'), true);

        $this->assertSame(ClassifiedJob::class, $payload['displayName']);
        $this->assertSame([], array_values(array_intersect(array_keys($payload), self::PACKAGE_KEYS)));

        $classify = new PayloadClassifier;
        $object = ['uuid' => 'u', 'data' => ['command' => new ClassifiedJob(['x'])]];

        $this->assertSame([], $classify('redis', 'default', $object));
        $this->assertSame([], $classify('sync', 'default', $object));
        $this->assertSame([], $classify('missing', 'default', $object));
        $this->assertSame(['x'], $classify('database', 'default', $object)['tags']);
        // A null connection is the default queue connection (database here).
        $this->assertSame(['x'], $classify(null, 'default', $object)['tags']);
    }

    /**
     * Rejects: shared static state between the outer and the inner classification.
     */
    public function test_nested_dispatch_during_classification_is_classified_on_its_own(): void
    {
        // A job whose tags() dispatches another job.
        Queue::push(new NestingJob);

        // A queued listener whose tags() dispatches a job while Tags keeps the
        // listener's event.
        Event::listen(NestingEvent::class, NestingListener::class);
        event(new NestingEvent);

        $payloads = DB::table('jobs')->orderBy('id')->pluck('payload')
            ->map(static fn (string $payload): array => json_decode($payload, true))
            ->all();

        $this->assertCount(4, $payloads);

        // The inner job is inserted first: its push runs inside the outer's classification.
        $this->assertSame(ClassifiedJob::class, $payloads[0]['displayName']);
        $this->assertSame(['inner-of-job'], $payloads[0]['tags']);
        $this->assertSame(NestingJob::class, $payloads[1]['displayName']);
        $this->assertSame(['outer-job'], $payloads[1]['tags']);

        $this->assertSame(EventAwareJob::class, $payloads[2]['displayName']);
        $this->assertSame(['inner-of-listener', 'event:none'], $payloads[2]['tags'], 'the inner job got the event of the outer listener');
        $this->assertSame(NestingListener::class, $payloads[3]['displayName']);
        $this->assertSame('event', $payloads[3]['type']);
        $this->assertSame(['listener-tag', 'event-tag'], $payloads[3]['tags']);

        $generations = array_column($payloads, 'orreryGen');
        $this->assertCount(4, array_unique($generations));

        $this->assertNull($this->tagsEvent(), 'Tags kept an event after the classification');
    }

    /**
     * Rejects: json_encode() without JSON_UNESCAPED_UNICODE.
     */
    public function test_package_json_is_unescaped_unicode(): void
    {
        $tags = ['klientas:Žemaitė', 'город:Москва'];

        $pushed = Queue::push(new ClassifiedJob($tags));
        $raw = DB::table('jobs')->where('id', $pushed)->value('payload');
        $this->assertStringContainsString('"klientas:Žemaitė"', $raw);
        $this->assertStringContainsString('"город:Москва"', $raw);

        // The pushRaw() stamp re-encodes the payload.
        $republished = Queue::pushRaw($raw);
        $restamped = DB::table('jobs')->where('id', $republished)->value('payload');
        $this->assertStringContainsString('"klientas:Žemaitė"', $restamped);
        $this->assertStringNotContainsString('\u', $restamped);

        // JobPayload::set() encodes the same way.
        $set = (new JobPayload('{"uuid":"u"}'))->set(['tags' => $tags]);
        $this->assertSame('{"uuid":"u","tags":["klientas:Žemaitė","город:Москва"]}', $set->value);
    }

    /**
     * Rejects: a loose unique() before the normalizer, which merges '01' and '1' and drops a valid tag after true.
     */
    public function test_tags_are_deduplicated_by_their_normalized_string_only(): void
    {
        config(['orrery.silenced_tags' => ['1']]);
        $numeric = Queue::push(new ClassifiedJob(['01', '1']));

        config(['orrery.silenced_tags' => ['vip']]);
        $boolean = Queue::push(new ClassifiedJob([true, 'vip']));

        config(['orrery.silenced_tags' => [], 'orrery.tags.max_per_job' => 1]);
        $limited = Queue::push(new ClassifiedJob(['01', '1']));
        $duplicates = Queue::push(new ClassifiedJob(['01', ' 01', '01 ']));

        $payload = $this->payload($numeric);
        $this->assertSame(['01', '1'], $payload['tags']);
        $this->assertFalse($payload['tagsTruncated']);
        $this->assertTrue($payload['silenced'], "silenced_tags ['1'] does not match the tag '1'");

        $payload = $this->payload($boolean);
        $this->assertSame(['vip'], $payload['tags']);
        $this->assertTrue($payload['silenced'], "silenced_tags ['vip'] does not match after an invalid boolean");

        $payload = $this->payload($limited);
        $this->assertSame(['01'], $payload['tags']);
        $this->assertTrue($payload['tagsTruncated'], 'a second distinct tag existed');

        $payload = $this->payload($duplicates);
        $this->assertSame(['01'], $payload['tags']);
        $this->assertFalse($payload['tagsTruncated'], 'equal normalized tags are one tag');
    }

    /**
     * Rejects: a loose unique() over the models of a job. It
     * compares the models with ==, which walks their loaded relations: two
     * equal instances with a relation cycle end the dispatch with a fatal
     * error on PHP 8.3. The tag strings are deduplicated after it, by the
     * normalizer.
     */
    public function test_the_models_of_a_job_are_not_compared(): void
    {
        $first = new Signal;
        $first->id = 7;
        $second = new Signal;
        $second->id = 7;
        $job = new class($first, $second)
        {
            public function __construct(public Signal $first, public Signal $second) {}
        };

        $this->assertSame([Signal::class.':7', Signal::class.':7'], Tags::for($job));
    }

    /**
     * Rejects: a loose unique() in the listener and event aggregation of Tags.
     */
    public function test_listener_and_event_tags_are_deduplicated_by_their_normalized_string_only(): void
    {
        Event::listen(ScalarTagsEvent::class, ScalarTagsListener::class);

        config(['orrery.silenced_tags' => ['1']]);
        event(new ScalarTagsEvent);

        config(['orrery.silenced_tags' => [], 'orrery.tags.max_per_job' => 2]);
        event(new ScalarTagsEvent);

        $ids = DB::table('jobs')->orderBy('id')->pluck('id')->all();
        $this->assertCount(2, $ids);

        $payload = $this->payload($ids[0]);
        $this->assertSame('event', $payload['type']);
        $this->assertSame(['01', '1', 'vip'], $payload['tags']);
        $this->assertFalse($payload['tagsTruncated']);
        $this->assertTrue($payload['silenced'], "silenced_tags ['1'] does not match the event tag '1'");

        $payload = $this->payload($ids[1]);
        $this->assertSame(['01', '1'], $payload['tags']);
        $this->assertTrue($payload['tagsTruncated']);
        $this->assertFalse($payload['silenced']);
    }

    /**
     * Rejects: the driver check inside the watcher, which opens the package mirror session for a redis or sync dispatch.
     */
    public function test_non_database_dispatches_open_no_package_session(): void
    {
        // The package storage is a MySQL connection that nothing can reach.
        // Every attempt to open a package session calls the PDO factory,
        // which counts it and throws before any network connection.
        config([
            'database.connections.orrery_b5_unreachable' => [
                'driver' => 'mysql', 'url' => null, 'host' => '127.0.0.1', 'port' => 1,
                'database' => 'orrery_b5_unreachable', 'username' => 'nobody', 'password' => '',
                'prefix' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            ],
            'queue.connections.redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default'],
            'queue.connections.sync' => ['driver' => 'sync'],
        ]);
        $this->usePackageConnection('orrery_b5_unreachable');
        PackageSession::releaseAll();

        $attempts = [];
        PackageSession::usePdoFactoryForTesting(static function (string $session, array $config) use (&$attempts): ?\PDO {
            if (($config['port'] ?? null) !== 1) {
                return null;
            }

            $attempts[] = $session;

            throw new \RuntimeException("the test refuses the package session {$session}");
        });

        $this->beforeApplicationDestroyed(function (): void {
            PackageSession::usePdoFactoryForTesting(null);
            PackageSession::releaseAll();
        });

        $redis = new RedisQueue($this->app['redis'], 'default');
        $redis->setConnectionName('redis');
        $redis->setContainer($this->app);
        (new ReflectionMethod($redis, 'createPayload'))->invoke($redis, new ClassifiedJob(['x']), 'default');
        (new ReflectionMethod($redis, 'createPayload'))->invoke($redis, 'App\\Handlers\\Raw@handle', 'default');

        Queue::connection('sync')->push(new ClassifiedJob(['sync']));

        $opened = static fn (): array => array_values(array_filter(
            PackageSession::sessionsForTesting(),
            static fn (array $entry): bool => $entry['name'] === 'orrery_b5_unreachable',
        ));

        $this->assertSame([], $attempts, 'a non-database dispatch connected a package session');
        $this->assertSame([], $opened(), 'a non-database dispatch opened a package session');
        $this->assertFalse(Watcher::instance()->breakerOpen());

        // The control: the callback of a database connection enters the
        // watcher, which opens the package storage session.
        // Laravel's ContextServiceProvider registers a payload callback too.
        $callbacks = array_values(array_filter(
            (new \ReflectionProperty(\Illuminate\Queue\Queue::class, 'createPayloadCallbacks'))->getValue(),
            static fn (callable $callback): bool => $callback instanceof Closure
                && (new \ReflectionFunction($callback))->getClosureScopeClass()?->getName() === ObservationModule::class,
        ));
        $this->assertCount(1, $callbacks, 'the package registers one payload callback');
        $keys = $callbacks[0]('database', 'default', ['uuid' => 'u', 'data' => ['command' => new ClassifiedJob(['db'])]]);

        $this->assertSame(['db'], $keys['tags']);
        // A package session connects at its first statement; the
        // watcher builds it.
        $this->assertNotSame([], $opened(), 'the database callback did not reach the package session');
    }

    /**
     * Rejects: Tags::isolated() without the restore in finally, which leaves the outer classification without its event.
     */
    public function test_outer_tags_state_is_restored_after_a_nested_classification(): void
    {
        $outer = new NestingEvent;
        $this->setTagsEvent($outer);

        $keys = (new PayloadClassifier)('database', 'default', ['data' => ['command' => new EventAwareJob]]);
        $this->assertSame(['inner-of-listener', 'event:none'], $keys['tags'], 'the nested classification saw the outer event');
        $this->assertSame($outer, $this->tagsEvent(), 'the outer event is not restored after the nested classification');

        try {
            (new PayloadClassifier)('database', 'default', ['data' => ['command' => new ThrowingTagsJob]]);
            $this->fail('the tags() of the job must throw');
        } catch (\RuntimeException $error) {
            $this->assertSame('tags() of ThrowingTagsJob failed', $error->getMessage());
        }

        $this->assertSame($outer, $this->tagsEvent(), 'the outer event is not restored after a nested classification threw');

        $this->setTagsEvent(null);
    }

    private function setTagsEvent(?object $event): void
    {
        Closure::bind(static function () use ($event): void {
            Tags::$event = $event;
        }, null, Tags::class)();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int|string $id): array
    {
        $raw = DB::table('jobs')->where('id', $id)->value('payload');
        $this->assertIsString($raw, "no jobs row {$id}");

        return json_decode($raw, true);
    }

    private function tagsEvent(): ?object
    {
        return Closure::bind(static fn (): ?object => Tags::$event, null, Tags::class)();
    }
}
