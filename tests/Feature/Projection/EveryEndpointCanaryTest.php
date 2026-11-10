<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Projection;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Failures\GroupKey;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Monitoring\MonitoringVersion;
use Justrau\Orrery\Monitoring\StuckSignals;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Projection\Jobs\BatchableCanaryJob;
use Justrau\Orrery\Tests\Feature\Projection\Jobs\CanaryJob;
use Justrau\Orrery\Tests\Feature\Retry\RetryTestCase;

/**
 * The exposure check of every endpoint:
 * no route of the
 * package sends a canary or raw serialized PHP: every GET answer
 * and every write answer.
 *
 * The fixtures are real: a failed CanaryJob (canaries in its command's
 * properties, an array, a header record, a URL password, a large string, a
 * top-level payload key, Laravel's visible and hidden Context, and the
 * exception message and trace), a pending one, a batch with a canary in its
 * name and options and a failed CanaryJob member, a tag on the failed job,
 * and a bulk run that retried the member. The test walks the router's
 * routes of every method under the package path and requests each with the
 * parameters of these rows (the writes after the reads, so a write changes
 * no read fixture); a route without a request below fails the test, so a
 * new route is checked once it is added.
 */
final class EveryEndpointCanaryTest extends RetryTestCase
{
    private const CANARIES = [
        'canary-public-password', 'canary-protected-apikey', 'canary-private-secret', 'canary-array-token',
        'canary-header-value', 'canary-url-password', 'canary-large-end', 'canary-top-level',
        'canary-context-visible', 'canary-context-hidden', 'canary-exception-message',
        'canary-batch-name', 'canary-option-key',
    ];

    /**
     * Routes ("METHOD uri") that this test does not request, with the
     * reason. Empty: the SPA page and the API catch-all are requested too.
     */
    private const EXCLUDED = [];

    private const TAG = 'orders';

    /** @var array<string, array{0: string, 1: string}> the write answers of the fixtures (the bulk freeze and run), by route, with their URIs */
    private array $posted = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');
        Orrery::auth(static fn (): bool => true);
        Orrery::operateUsing(static fn (): bool => true);
    }

    /**
     * Rejects: an endpoint of any method that sends a canary of a job, a
     * batch, a failure or a bulk run in its raw body (for example a list
     * that returns the stored payload, a detail without redaction, a retry,
     * forget or resolve answer that echoes the job), raw serialized PHP, a
     * bulk freeze preview or run answer that sends either, and
     * a route of any method that this walk skips (a new route without a
     * request here).
     * Still passes: a canary in a position that the fixtures do not fill
     * (for example a job's tags), and an endpoint that leaks only for a
     * parameter value, a body or a result other than the ones requested
     * (the resolve answers only for an operation that is already done).
     */
    public function test_no_endpoint_sends_a_canary(): void
    {
        $fixtures = $this->seedCanaries();
        $reads = $this->requests($fixtures);
        $writes = $this->writes($fixtures);
        $covered = [...array_keys($reads), ...array_keys($writes), ...array_keys($this->posted)];
        $routes = $this->routes();

        self::assertSame([], array_values(array_diff($routes, $covered, array_keys(self::EXCLUDED))), 'A route without a canary request: add it to requests() or writes().');
        self::assertSame([], array_values(array_diff($covered, $routes)), 'A canary request for a route that does not exist.');

        $problems = [];

        // The write answers of the fixtures that show job data: the bulk freeze preview and the run answer.
        self::assertCount(2, $this->posted);

        foreach ($this->posted as $route => [$uri, $raw]) {
            $problems = [...$problems, ...self::leaks($uri, $route, $raw)];
        }

        foreach ($reads as $route => $uris) {
            foreach ($uris as [$uri, $status]) {
                $problems = [...$problems, ...$this->check($route, 'GET', $uri, [], $status)];
            }
        }

        foreach ($writes as $route => $calls) {
            foreach ($calls as [$uri, $body, $status]) {
                $problems = [...$problems, ...$this->check($route, explode(' ', $route, 2)[0], $uri, $body, $status)];
            }
        }

        self::assertSame([], $problems);
    }

    /**
     * One request: its status and its leaks.
     *
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private function check(string $route, string $method, string $uri, array $body, int $status): array
    {
        $response = $this->json($method, $uri, $body, ['Accept' => 'application/json']);
        $raw = (string) $response->getContent();
        $problems = [];

        if ($response->getStatusCode() !== $status) {
            $problems[] = "{$method} {$uri}: status {$response->getStatusCode()}, expected {$status}: ".substr($raw, 0, 300);
        }

        return [...$problems, ...self::leaks($uri, $route, $raw)];
    }

    /**
     * The canaries and the serialized PHP in one answer.
     *
     * @return list<string>
     */
    private static function leaks(string $uri, string $route, string $raw): array
    {
        $problems = [];

        foreach (self::CANARIES as $canary) {
            if (str_contains($raw, $canary)) {
                $problems[] = "{$uri} ({$route}): {$canary}";
            }
        }

        if (preg_match('/[sOC]:\d+:\\\\?"/', $raw) === 1) {
            $problems[] = "{$uri} ({$route}): serialized PHP";
        }

        return $problems;
    }

    /**
     * The fixture rows, and their ids.
     *
     * @return array{failed: string, occurrence: int, pending: string, member: string, batch: string, key: string, entry: int, ref: string, operation: string}
     */
    private function seedCanaries(): array
    {
        $this->app['config']->set('cache.serializable_classes', false);
        Queue::createPayloadUsing(static fn (): array => ['secret_token' => 'canary-top-level']);
        Context::add('api_token', 'canary-context-visible');
        Context::addHidden('internal', 'canary-context-hidden');

        [$failed] = $this->failWith(new CanaryJob(null, true));
        $row = $this->rowOrFail($failed);
        self::assertStringContainsString('canary-context-hidden', (string) $this->mirror()->table(JobStore::TABLE)->where('uuid', $failed)->value('payload'), 'the stored payload holds the canaries');

        // A tag on the failed job, monitored.
        $at = Clock::toDecimal(Clock::now());
        $this->mirror()->table('orrery_job_tags')->insert(['tag' => self::TAG, 'job_uuid' => $failed, 'pushed_at' => $at]);
        $this->mirror()->table('orrery_failed_tags')->insert(['tag' => self::TAG, 'job_uuid' => $failed, 'finished_at' => $at]);
        $this->app->make(MonitoringVersion::class)->monitor(self::TAG);

        // A batch with a canary in its name and options, and its failed member.
        $batch = Bus::batch([new BatchableCanaryJob(null, true)])
            ->name('Nightly password=canary-batch-name')
            ->onConnection('database')
            ->onQueue('default')
            ->allowFailures()
            ->withOption('api_key', 'canary-option-key')
            ->dispatch();
        $this->workAndLogFailures();
        $member = (string) $this->mirror()->table(JobStore::TABLE)->where('uuid', '!=', $failed)->where('status', 'failed')->value('uuid');
        self::assertNotSame('', $member, 'the batch member failed');
        $this->mirror()->table(JobStore::TABLE)->where('uuid', $member)->whereNull('batch_id')->update(['batch_id' => (string) $batch->id]);

        // A bulk run that retries the member: its run row, operations and results.
        $frozen = $this->postJson('/orrery/api/bulk/freeze', ['action' => 'retry', 'source' => ['kind' => 'batch', 'batch_id' => (string) $batch->id]])->assertOk();
        $this->posted['POST orrery/api/bulk/freeze'] = ['/orrery/api/bulk/freeze', (string) $frozen->getContent()];
        $run = $this->postJson('/orrery/api/bulk/run', ['token' => $frozen->json('frozen.token'), 'after' => null, 'confirmed_total' => null])->assertOk();
        $this->posted['POST orrery/api/bulk/run'] = ['/orrery/api/bulk/run', (string) $run->getContent()];

        // A pending CanaryJob that no worker took.
        $id = $this->app['queue']->connection('database')->push(new CanaryJob);
        $pending = self::uuidOf((string) $this->app['db']->connection()->table('jobs')->where('id', $id)->value('payload'));

        $entry = (int) $this->mirror()->table('orrery_job_history')->where('job_uuid', $failed)->where('event', 'failed')->orderByDesc('id')->value('id');
        self::assertGreaterThan(0, $entry);

        return [
            'failed' => $failed,
            'occurrence' => (int) $row->occurrence_id,
            'pending' => $pending,
            'member' => $member,
            'batch' => (string) $batch->id,
            'key' => GroupKey::of((string) $row->exception_class, (string) $row->exception_message, (string) $row->name),
            'entry' => $entry,
            'ref' => (string) $frozen->json('frozen.ref'),
            'operation' => (string) $this->mirror()->table('orrery_operations')->where('job_uuid', $member)->value('id'),
        ];
    }

    /**
     * The requests of each GET route ("GET uri", the URI as the router
     * lists it): the concrete URI and the expected status.
     *
     * @param  array{failed: string, occurrence: int, pending: string, member: string, batch: string, key: string, entry: int, ref: string, operation: string}  $f
     * @return array<string, list<array{0: string, 1: int}>>
     */
    private function requests(array $f): array
    {
        $api = '/orrery/api';
        $now = Clock::now();
        $window = 'from='.(int) ($now - 86400).'&to='.(int) ($now + 60);
        $ok = static fn (string ...$uris): array => array_map(static fn (string $uri): array => [$uri, 200], $uris);

        $lists = [];

        foreach (['pending', 'running', 'completed', 'silenced', 'failed', 'retrying', 'unknown'] as $status) {
            $lists[] = $api.'/jobs?status='.$status;
        }

        array_push(
            $lists,
            $api.'/jobs?status=all&tag='.self::TAG,
            $api.'/jobs?status=failed&tag='.self::TAG,
            $api.'/jobs?status=pending&batch='.$f['batch'],
            $api.'/jobs?status=failed&batch='.$f['batch'],
            $api.'/jobs?status=failed&q='.$f['failed'],
            $api.'/jobs?status=pending&q=Canary',
            $api.'/jobs?status=failed&class='.rawurlencode(CanaryJob::class).'&connection=database&queue=default',
        );

        return [
            'GET orrery/api/workload' => $ok($api.'/workload'),
            'GET orrery/api/workers' => $ok($api.'/workers'),
            'GET orrery/api/health' => $ok($api.'/health'),
            'GET orrery/api/counts' => $ok($api.'/counts'),
            'GET orrery/api/control' => $ok($api.'/control'),
            'GET orrery/api/tags' => $ok($api.'/tags', $api.'/tags?tag='.self::TAG),
            'GET orrery/api/bulk' => $ok($api.'/bulk'),
            'GET orrery/api/bulk/{ref}' => $ok($api.'/bulk/'.$f['ref']),
            'GET orrery/api/jobs' => $ok(...$lists),
            'GET orrery/api/jobs/stuck' => $ok(...array_map(static fn (string $signal): string => $api.'/jobs/stuck?signal='.$signal, StuckSignals::SIGNALS)),
            'GET orrery/api/jobs/{uuid}' => $ok($api.'/jobs/'.$f['failed'], $api.'/jobs/'.$f['pending'], $api.'/jobs/'.$f['member']),
            'GET orrery/api/jobs/{uuid}/history' => $ok($api.'/jobs/'.$f['failed'].'/history', $api.'/jobs/'.$f['member'].'/history'),
            'GET orrery/api/jobs/{uuid}/history/{id}' => $ok($api.'/jobs/'.$f['failed'].'/history/'.$f['entry']),
            'GET orrery/api/batches' => $ok($api.'/batches', $api.'/batches?query=Nightly'),
            'GET orrery/api/batches/{id}' => $ok($api.'/batches/'.$f['batch']),
            'GET orrery/api/throughput' => $ok($api.'/throughput'),
            'GET orrery/api/markers' => $ok($api.'/markers'),
            'GET orrery/api/queues/{connection}/{queue}/history' => $ok($api.'/queues/database/default/history'),
            'GET orrery/api/settings/retention' => $ok($api.'/settings/retention'),
            'GET orrery/api/classes' => $ok($api.'/classes'),
            'GET orrery/api/classes/series' => $ok($api.'/classes/series?name='.rawurlencode(CanaryJob::class)),
            'GET orrery/api/failures/groups' => $ok($api.'/failures/groups?'.$window),
            'GET orrery/api/failures/groups/{key}' => $ok($api.'/failures/groups/'.$f['key'].'?'.$window),
            'GET orrery/api' => [[$api, 404]],
            'GET orrery/api/{any}' => [[$api.'/jobs/'.$f['failed'].'/payload', 404]],
            'GET orrery/{view?}' => $ok('/orrery/jobs/'.$f['failed'], '/orrery/batches/'.$f['batch']),
        ];
    }

    /**
     * The requests of each write route ("METHOD uri"), run in this order
     * after the reads: the concrete URI, the JSON body and the expected
     * status. The bulk freeze and run are requested by the fixtures.
     *
     * @param  array{failed: string, occurrence: int, pending: string, member: string, batch: string, key: string, entry: int, ref: string, operation: string}  $f
     * @return array<string, list<array{0: string, 1: array<string, mixed>, 2: int}>>
     */
    private function writes(array $f): array
    {
        $api = '/orrery/api';
        self::assertNotSame('', $f['operation'], 'the bulk run left an operation to resolve');

        return [
            'POST orrery/api/jobs/{uuid}/retry' => [[$api.'/jobs/'.$f['failed'].'/retry', ['occurrence_id' => $f['occurrence']], 200]],
            'POST orrery/api/jobs/{uuid}/forget' => [[$api.'/jobs/'.$f['failed'].'/forget', ['occurrence_id' => $f['occurrence']], 200]],
            'POST orrery/api/operations/{id}/resolve' => [[$api.'/operations/'.$f['operation'].'/resolve', [], 200]],
            'POST orrery/api/control' => [[$api.'/control', ['action' => 'pause'], 200], [$api.'/control', ['action' => 'continue'], 200]],
            'POST orrery/api/tags/monitored' => [[$api.'/tags/monitored', ['tag' => self::TAG.'-new'], 200]],
            'DELETE orrery/api/tags/monitored' => [[$api.'/tags/monitored?tag='.self::TAG.'-new', [], 200]],
            'DELETE orrery/api/tags/monitored/{tag}' => [[$api.'/tags/monitored/'.self::TAG, [], 200]],
        ];
    }

    /**
     * The router's routes under the package path, as "METHOD uri" (HEAD,
     * which the router adds to every GET route, left out).
     *
     * @return list<string>
     */
    private function routes(): array
    {
        $path = trim((string) config('orrery.path'), '/');
        $routes = [];

        /** @var Route $route */
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            if ($route->uri() !== $path && ! str_starts_with($route->uri(), $path.'/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $routes[] = $method.' '.$route->uri();
                }
            }
        }

        sort($routes);

        return array_values(array_unique($routes));
    }
}
