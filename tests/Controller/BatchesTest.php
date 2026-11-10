<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository as LaravelDatabaseBatchRepository;
use Illuminate\Bus\DynamoBatchRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Justrau\Orrery\Database\Repositories\DatabaseBatchRepository;
use Justrau\Orrery\Exceptions\WatchedOperationFailed;
use Justrau\Orrery\Http\Controllers\BatchesController;
use Justrau\Orrery\Http\Lists\InvalidListRequest;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Controller\Fakes\AuthorizationJob;
use Justrau\Orrery\Tests\Controller\Fakes\InvokableCanary;
use Justrau\Orrery\Tests\Controller\Fakes\StatementTimeout;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\Controller\Fakes\WakeupSpy;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Support\PackageStatements;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;

/**
 * GET /api/batches and GET /api/batches/{id}:
 * the data-only batch reader, the projection
 * (JobProjection::batch()), the `before_id` pager and the search, the
 * member counts per mirror status, and the unknown and missing cases.
 */
final class BatchesTest extends ControllerTest
{
    private const MISSING_TABLE = 'orrery_d8_15_missing_batches';

    private const T = 1_790_000_000;

    /** The class that the sentinel autoloader must never be asked for. */
    private const SENTINEL_CLASS = 'Orrery\\Canary\\NeverLoaded';

    /**
     * Rejects: the old camelCase shape (`totalJobs`, `createdAt`), a
     * `failed_job_ids` column in the response, string times, a progress that
     * counts the failed jobs as unprocessed, and an index without
     * `available`.
     */
    public function test_the_index_projects_each_batch(): void
    {
        $this->insertBatch('b-0001', 'Nightly import', ['total_jobs' => 8, 'pending_jobs' => 3, 'failed_jobs' => 1, 'failed_job_ids' => json_encode(['f0000000-0000-4000-8000-000000000001']), 'finished_at' => self::T + 60]);

        $response = $this->read('/orrery/api/batches')->assertOk();

        self::assertTrue($response->json('available'));
        self::assertSame([[
            'id' => 'b-0001',
            'name' => 'Nightly import',
            'total_jobs' => 8,
            'pending_jobs' => 3,
            'processed_jobs' => 5,
            'failed_jobs' => 1,
            'progress' => 63,
            'created_at' => (float) self::T,
            'cancelled_at' => null,
            'finished_at' => (float) (self::T + 60),
            'options' => ['connection' => 'database', 'queue' => 'emails', 'allowFailures' => true],
            'running' => false,
        ]], $response->json('batches'));
        self::assertStringContainsString('"created_at":1790000000.0', (string) $response->getContent(), 'times are numbers with JSON_PRESERVE_ZERO_FRACTION');
        self::assertStringNotContainsString('failed_job_ids', (string) $response->getContent());
    }

    /**
     * Laravel keeps a failed job pending and keeps counting a failure after
     * its retry succeeded, so an `allowFailures()` batch whose pending jobs
     * do not pass its failures may or may not still run a job: the mirror
     * decides it.
     *
     * Rejects: such a batch answered as unknown (the sidebar's count turns
     * "—") or as running without an active member; a pending, reserved or
     * retrying member that is not seen; a member of another batch that
     * counts; and a finished or cancelled batch read as running.
     * Still passes: a rule that reads the mirror also for a batch whose
     * pending jobs pass its failures.
     */
    public function test_the_index_decides_whether_each_batch_runs(): void
    {
        $this->insertBatch('b-0001', 'More pending', ['pending_jobs' => 3, 'failed_jobs' => 1]);
        $this->insertBatch('b-0002', 'Failures only', ['pending_jobs' => 2, 'failed_jobs' => 2]);
        $this->member(1, 'b-0002', 'failed');
        $this->member(2, 'b-0002', 'failed');
        $this->member(3, 'b-0002', 'completed');
        $this->member(4, 'b-0099', 'pending');
        $this->insertBatch('b-0003', 'Queued', ['pending_jobs' => 2, 'failed_jobs' => 2]);
        $this->member(5, 'b-0003', 'failed');
        $this->member(6, 'b-0003', 'pending');
        $this->insertBatch('b-0004', 'Reserved', ['pending_jobs' => 1, 'failed_jobs' => 3]);
        $this->member(7, 'b-0004', 'reserved');
        $this->insertBatch('b-0005', 'Retrying', ['pending_jobs' => 1, 'failed_jobs' => 1]);
        $this->member(8, 'b-0005', 'retrying');
        $this->insertBatch('b-0006', 'Finished', ['pending_jobs' => 0, 'failed_jobs' => 0, 'finished_at' => self::T + 60]);
        $this->insertBatch('b-0007', 'Cancelled', ['pending_jobs' => 2, 'failed_jobs' => 0, 'cancelled_at' => self::T + 60]);
        $this->member(9, 'b-0007', 'pending');

        $running = array_column((array) $this->read('/orrery/api/batches')->assertOk()->json('batches'), 'running', 'id');

        self::assertSame([
            'b-0007' => false,
            'b-0006' => false,
            'b-0005' => true,
            'b-0004' => true,
            'b-0003' => true,
            'b-0002' => false,
            'b-0001' => true,
        ], $running);
    }

    /**
     * Rejects: a batch whose running state needs the mirror answered as not
     * running (or the whole index failing) when the mirror read fails: it is
     * unknown (null), and the failure is reported.
     */
    public function test_a_failed_member_read_leaves_the_running_state_unknown(): void
    {
        Exceptions::fake();
        $this->insertBatch('b-0001', 'More pending', ['pending_jobs' => 3, 'failed_jobs' => 1]);
        $this->insertBatch('b-0002', 'Failures only', ['pending_jobs' => 2, 'failed_jobs' => 2]);
        $this->failMirrorCounts();

        $running = array_column((array) $this->read('/orrery/api/batches')->assertOk()->json('batches'), 'running', 'id');

        self::assertSame(['b-0002' => null, 'b-0001' => true], $running);
        Exceptions::assertReported(static fn (WatchedOperationFailed $reported): bool => $reported->operation === 'http:batch_running');
    }

    /**
     * Rejects: a page larger than 50, an order other than id desc, and a
     * `before_id` that is ignored or inclusive.
     */
    public function test_the_index_pages_with_before_id(): void
    {
        for ($n = 1; $n <= 51; $n++) {
            $this->insertBatch(sprintf('b-%04d', $n), "Batch {$n}");
        }

        $first = $this->read('/orrery/api/batches')->assertOk();
        $ids = array_column((array) $first->json('batches'), 'id');

        self::assertCount(50, $ids);
        self::assertSame('b-0051', $ids[0]);
        self::assertSame('b-0002', $ids[49]);

        $second = $this->read('/orrery/api/batches?before_id=b-0002')->assertOk();
        self::assertSame(['b-0001'], array_column((array) $second->json('batches'), 'id'));
    }

    /**
     * Rejects: a search whose `%` and `_` act as wildcards (SQLite has no
     * default LIKE escape character), a search that ignores the id, and a
     * search that ignores `before_id`.
     */
    public function test_the_search_matches_the_name_or_the_id_literally(): void
    {
        $this->insertBatch('s-1', '100% done');
        $this->insertBatch('s-2', '1000 done');
        $this->insertBatch('s-3', 'a_b');
        $this->insertBatch('s-4', 'axb');
        $this->insertBatch('zz-9', 'other');

        self::assertSame(['s-1'], $this->ids('/orrery/api/batches?query='.rawurlencode('0%')));
        self::assertSame(['s-3'], $this->ids('/orrery/api/batches?query=a_b'));
        self::assertSame(['zz-9'], $this->ids('/orrery/api/batches?query=zz-'));
        self::assertSame(['s-1'], $this->ids('/orrery/api/batches?query=done&before_id=s-2'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidParameters(): array
    {
        return [
            'query array' => ['/orrery/api/batches?query[]=x', 'query'],
            'before_id array' => ['/orrery/api/batches?before_id[]=x', 'before_id'],
            'query too long' => ['/orrery/api/batches?query='.str_repeat('q', 256), 'query'],
            'before_id too long' => ['/orrery/api/batches?before_id='.str_repeat('b', 256), 'before_id'],
            'query invalid UTF-8' => ['/orrery/api/batches?query=%FF%FE', 'query'],
            'query with NUL' => ['/orrery/api/batches?query=a%00b', 'query'],
            'before_id invalid UTF-8' => ['/orrery/api/batches?before_id=%C3', 'before_id'],
            'before_id with NUL' => ['/orrery/api/batches?before_id=b%00', 'before_id'],
        ];
    }

    /**
     * Rejects: an array or an oversized value read as absent or cast (the
     * legacy rule) on the new endpoint.
     */
    #[DataProvider('invalidParameters')]
    public function test_an_invalid_parameter_gives_422(string $uri, string $field): void
    {
        $this->read($uri)->assertStatus(422)->assertJsonPath('error', 'invalid')->assertJsonPath('field', $field);
    }

    /**
     * Rejects: a missing batch table answered as an empty list without
     * `available: false` (the screen says "Batching is not set up"), as a
     * 503, or as `not_found` on the show.
     */
    public function test_a_missing_batch_table(): void
    {
        $this->app['config']->set('queue.batching.table', self::MISSING_TABLE);
        $this->app->forgetInstance(BatchRepository::class);
        $this->app->forgetInstance(LaravelDatabaseBatchRepository::class);

        $this->read('/orrery/api/batches')->assertOk()->assertExactJson(['batches' => [], 'available' => false]);
        $this->read('/orrery/api/batches?query=abc')->assertOk()->assertExactJson(['batches' => [], 'available' => false]);
        $this->read('/orrery/api/batches/b-0001')->assertNotFound()->assertJsonPath('error', 'batching_unavailable');
    }

    /**
     * Rejects: an unknown batch answered as 200, a 404 without its code, and
     * a batch found by another letter case of its id (MySQL's collation).
     */
    public function test_an_unknown_batch_is_not_found(): void
    {
        $this->insertBatch('b-0001', 'Known');

        $this->read('/orrery/api/batches/b-0002')->assertNotFound()->assertExactJson(['error' => 'not_found', 'message' => 'Not found.']);
        $this->read('/orrery/api/batches/B-0001')->assertNotFound()->assertJsonPath('error', 'not_found');
    }

    /**
     * Rejects: batches read automatically from a host SQLite file that
     * allows no automatic reads (D18), or answered as an empty list or a
     * 404 there (unknown is never empty).
     */
    public function test_d18_gives_unknown_batches(): void
    {
        $this->insertBatch('b-0001', 'Nightly');
        HostFiles::useForTesting(null, static fn (string $connection): ?bool => false);

        $this->read('/orrery/api/batches')->assertOk()->assertExactJson(['batches' => null, 'unknown_reason' => 'sqlite_reads_not_allowed']);
        $this->read('/orrery/api/batches/b-0001')->assertOk()->assertExactJson(['batch' => null, 'unknown_reason' => 'sqlite_reads_not_allowed']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsupportedRepositories(): array
    {
        return ['subclass' => ['subclass'], 'dynamodb' => ['dynamodb']];
    }

    /**
     * Rejects: reading any bound repository other than exactly Laravel's
     * DatabaseBatchRepository: a subclass (it would run on the
     * application's connection) and the DynamoDB repository (it unserializes
     * `options` itself) give `unsupported_repository`, and neither is called.
     * Each case binds its repository before the first request of its own
     * application (Laravel keeps a resolved controller on the route).
     */
    #[DataProvider('unsupportedRepositories')]
    public function test_an_unsupported_repository_gives_unknown_batches(string $kind): void
    {
        $this->insertBatch('b-0001', 'Nightly');
        $calls = $this->bindUnsupported($kind);

        $this->read('/orrery/api/batches')->assertOk()->assertExactJson(['batches' => null, 'unknown_reason' => 'unsupported_repository']);
        $this->read('/orrery/api/batches?query=Night')->assertOk()->assertExactJson(['batches' => null, 'unknown_reason' => 'unsupported_repository']);
        $this->read('/orrery/api/batches/b-0001')->assertOk()->assertExactJson(['batch' => null, 'unknown_reason' => 'unsupported_repository']);
        self::assertSame([], $calls->getArrayCopy(), "{$kind}: the unsupported repository is never read");
    }

    /**
     * Rejects: a
     * viewer read that unserializes `options` (Laravel's repository, or
     * unserialize() in the reader): the sentinel autoloader would be asked
     * for a class of the options, and the spy's __wakeup() would run; a
     * closure's captured canary, an option key that the key rules match,
     * and a batch name with a secret that the pattern rules match, in the
     * raw body.
     */
    public function test_a_viewer_read_never_unserializes_and_redacts(): void
    {
        $canary = 'canary-closure-captured';
        $batch = Bus::batch([new AuthorizationJob])
            ->name('Nightly password=canary-batch-name')
            ->onConnection('database')
            ->onQueue('emails')
            ->then(static function () use ($canary): void {
                throw new RuntimeException($canary);
            })
            ->withOption('api_key', 'canary-option-key')
            ->withOption('spy', new WakeupSpy)
            ->dispatch();
        // An object of a class that no autoloader knows, written beside Laravel's options.
        $this->batchTable()->where('id', $batch->id)->update(['options' => $this->storedOptions(
            substr($this->serializedOptions($batch->id), 0, -1).'s:8:"sentinel";O:'.strlen(self::SENTINEL_CLASS).':"'.self::SENTINEL_CLASS.'":0:{}}',
            countDelta: 1,
        )]);

        // Laravel's dispatch itself reads the batch back (and wakes the spy) before the dashboard reads.
        WakeupSpy::$woken = 0;
        $asked = [];
        $autoloader = static function (string $class) use (&$asked): void {
            $asked[] = $class;
        };
        spl_autoload_register($autoloader, true, true);

        try {
            $index = $this->read('/orrery/api/batches')->assertOk();
            $show = $this->read('/orrery/api/batches/'.$batch->id)->assertOk();
            $search = $this->read('/orrery/api/batches?query=Nightly')->assertOk();
        } finally {
            spl_autoload_unregister($autoloader);
        }

        self::assertNotContains(self::SENTINEL_CLASS, $asked, 'the options were never unserialized');
        self::assertSame(0, WakeupSpy::$woken, 'no __wakeup() ran');

        foreach (['index' => $index, 'show' => $show, 'search' => $search] as $read => $response) {
            $body = (string) $response->getContent();

            foreach (['canary-closure-captured', 'canary-option-key', 'canary-batch-name'] as $secret) {
                self::assertStringNotContainsString($secret, $body, "{$read}: {$secret}");
            }

            self::assertStringNotContainsString('O:', $body, "{$read}: no raw serialized PHP");
        }

        $options = $show->json('batch.options');
        self::assertSame('database', $options['connection']);
        self::assertSame('emails', $options['queue']);
        self::assertSame('[redacted]', $options['api_key']);
        self::assertSame([['closure' => true]], $options['then']);
        self::assertSame(self::SENTINEL_CLASS, $options['sentinel']['class']);
        self::assertStringStartsWith('Nightly ', (string) $show->json('batch.name'));
    }

    /**
     * Rejects: a batch id with invalid UTF-8 or a NUL sent to the database
     * (Postgres refuses both, which would answer 503 instead of 404).
     */
    public function test_an_id_that_no_batch_can_have_is_not_found_without_a_read(): void
    {
        $this->insertBatch('b-0001', 'Nightly');
        $statements = [];
        PackageStatements::beforeEach($this->app, (string) config('queue.batching.database'), static function (string $sql) use (&$statements): void {
            if (str_contains($sql, 'job_batches')) {
                $statements[] = $sql;
            }
        });

        // Symfony refuses a path with invalid UTF-8 (400) before routing; the controller refuses it too.
        $this->read('/orrery/api/batches/%FF%FE')->assertStatus(400);
        $direct = $this->app->make(BatchesController::class)->show("\xFF\xFE");
        self::assertSame(404, $direct->getStatusCode());
        self::assertSame('not_found', $direct->getData(true)['error']);
        $this->read('/orrery/api/batches/b%00')->assertNotFound()->assertJsonPath('error', 'not_found');
        self::assertSame([], $statements);
    }

    /**
     * Rejects: a callback shown with its content. PendingBatch stores a
     * closure as a SerializableClosure, but an invokable object, an
     * `[$object, 'method']` pair and a `'Class@method'` string as they are:
     * every element of every callback list (and a callback value that is
     * not a list) is {"closure": true}, and no canary reaches the body.
     */
    public function test_every_callback_is_a_closure_marker(): void
    {
        $this->insertBatch('b-0001', 'Callbacks', ['options' => $this->storedOptions(serialize([
            'queue' => 'emails',
            'then' => [new InvokableCanary('canary-invokable-note'), [new InvokableCanary('canary-pair-note'), 'handle'], 'App\\CanaryClassAtSecret@handle'],
            'catch' => ['App\\CanaryCatchSecret@handle'],
            'finally' => 'canary-finally-not-a-list',
            'progress' => [],
            'before' => [new InvokableCanary('canary-before-note')],
            'failure' => [[new InvokableCanary('canary-failure-note'), '__invoke']],
        ]))]);

        foreach (['/orrery/api/batches', '/orrery/api/batches/b-0001'] as $uri) {
            $response = $this->read($uri)->assertOk();
            $body = (string) $response->getContent();
            $options = $uri === '/orrery/api/batches' ? $response->json('batches.0.options') : $response->json('batch.options');

            self::assertSame([['closure' => true], ['closure' => true], ['closure' => true]], $options['then'], $uri);
            self::assertSame([['closure' => true]], $options['catch'], $uri);
            self::assertSame([['closure' => true]], $options['finally'], $uri);
            self::assertSame([], $options['progress'], $uri);
            self::assertSame([['closure' => true]], $options['before'], $uri);
            self::assertSame([['closure' => true]], $options['failure'], $uri);
            self::assertSame('emails', $options['queue'], $uri);
            self::assertStringNotContainsString('canary', strtolower($body), $uri);
            self::assertStringNotContainsString('InvokableCanary', $body, $uri);
        }
    }

    /**
     * Rejects: options bounded by characters instead of bytes (SQLite
     * LENGTH() of a text and Postgres LENGTH() count characters, MySQL
     * CHAR_LENGTH() too): 40,000 × "é" is 80,000 bytes and 40,000
     * characters, so a character bound would load it.
     */
    public function test_the_options_bound_counts_bytes(): void
    {
        $this->insertBatch('b-0001', 'Multibyte', ['options' => $this->storedOptions(serialize(['note' => str_repeat('é', 40_000)]))]);
        $statements = [];
        PackageStatements::beforeEach($this->app, (string) config('queue.batching.database'), static function (string $sql) use (&$statements): void {
            if (str_contains($sql, 'job_batches')) {
                $statements[] = strtolower($sql);
            }
        });

        $row = $this->app->make(DatabaseBatchRepository::class)->find('b-0001');

        self::assertNotNull($row);
        self::assertNull($row->options, 'the read leaves the options out');
        self::assertGreaterThan(80_000, $row->options_bytes);
        self::assertCount(1, $statements);
        $expected = match ($this->batchConnection()->getDriverName()) {
            'sqlite' => 'length(cast("options" as blob))',
            'pgsql' => 'octet_length("options")',
            default => 'length(`options`)',
        };
        self::assertStringContainsString($expected, $statements[0]);
        self::assertStringNotContainsString('char_length', $statements[0]);
        self::assertSame('too_large', $this->read('/orrery/api/batches/b-0001')->assertOk()->json('batch.options.omitted'));
    }

    /**
     * Rejects: options over 64 KB loaded and parsed (they are omitted whole),
     * and options that are not a serialized value answered as anything but
     * an unavailable marker.
     */
    public function test_large_and_malformed_options(): void
    {
        $this->insertBatch('b-0001', 'Large', ['options' => $this->storedOptions(serialize(['note' => str_repeat('x', 70_000)]))]);
        $this->insertBatch('b-0002', 'Malformed', ['options' => $this->storedOptions('not serialized at all')]);

        $batches = collect((array) $this->read('/orrery/api/batches')->assertOk()->json('batches'))->keyBy('id');

        self::assertSame('too_large', $batches['b-0001']['options']['omitted']);
        self::assertGreaterThan(65_536, $batches['b-0001']['options']['bytes']);
        self::assertSame(['unavailable' => 'unsupported'], $batches['b-0002']['options']);
    }

    /**
     * Rejects: member counts keyed by mirror status instead of the list's
     * tabs, a completed count that includes the silenced members, a count
     * that differs from its tab's list total (`GET /api/jobs?batch=&status=`),
     * an uncapped count, counts that read another batch, and a count that
     * matches another letter case of the id (MySQL's collation).
     */
    public function test_the_show_counts_the_members_per_tab_as_the_lists_do(): void
    {
        // The list engine's cap is at least a full page and its extra row: 101.
        $this->app['config']->set('orrery.dashboard.list_count_cap', 5);
        $this->insertBatch('b-0001', 'Nightly');
        $n = 0;

        foreach (['pending' => 2, 'reserved' => 1, 'completed' => 3, 'failed' => 102, 'retrying' => 1] as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                $this->member(++$n, 'b-0001', $status);
            }
        }

        $this->member(++$n, 'b-0001', 'completed', silenced: true);
        $this->member(++$n, 'b-0001', 'completed', silenced: true);
        $this->member(++$n, 'b-0002', 'failed');
        // Another letter case of the id on an index-only tab (no PHP check after the count).
        $this->member(++$n, 'B-0001', 'retrying');

        $response = $this->read('/orrery/api/batches/b-0001')->assertOk();

        self::assertSame('b-0001', $response->json('batch.id'));
        self::assertSame([
            'pending' => ['count' => 2, 'at_least' => false],
            'running' => ['count' => 1, 'at_least' => false],
            'completed' => ['count' => 3, 'at_least' => false],
            'silenced' => ['count' => 2, 'at_least' => false],
            'failed' => ['count' => 101, 'at_least' => true],
            'retrying' => ['count' => 1, 'at_least' => false],
            'unknown' => ['count' => 0, 'at_least' => false],
        ], $response->json('members'));

        foreach (BatchesController::MEMBER_TABS as $tab) {
            self::assertSame(
                $this->read('/orrery/api/jobs?'.http_build_query(['status' => $tab, 'batch' => 'b-0001']))->assertOk()->json('total'),
                $response->json("members.{$tab}"),
                "{$tab}: the member count is the list total",
            );
        }
    }

    /**
     * Rejects: a residual member count that is exact although the candidate
     * budget ended (total() passing false instead of the scan's limit), and
     * a shared completed and silenced scan that counts differently from the
     * two lists.
     */
    public function test_a_budget_limited_member_count_is_a_lower_bound(): void
    {
        $this->app['config']->set('orrery.dashboard.search_candidates', 3);
        $this->insertBatch('b-0001', 'Nightly');

        for ($n = 1; $n <= 8; $n++) {
            $this->member($n, 'b-0001', 'completed', silenced: $n % 2 === 0);
        }

        $response = $this->read('/orrery/api/batches/b-0001')->assertOk();

        foreach (['completed', 'silenced'] as $tab) {
            self::assertTrue($response->json("members.{$tab}.at_least"), "{$tab}: the budget ended");
            self::assertSame(
                $this->read('/orrery/api/jobs?'.http_build_query(['status' => $tab, 'batch' => 'b-0001']))->assertOk()->json('total'),
                $response->json("members.{$tab}"),
                "{$tab}: the member count is the list total",
            );
        }
    }

    /**
     * Rejects: a shared completed and silenced scan that stops as soon as
     * one tab passes the cap. 150 silenced members are read first (newest
     * first), so silenced passes the cap of 101 while completed still has
     * members within the budget of 200; each count must equal its list total.
     */
    public function test_a_shared_scan_goes_on_after_one_tab_reaches_the_cap(): void
    {
        $this->app['config']->set('orrery.dashboard.list_count_cap', 101);
        $this->app['config']->set('orrery.dashboard.search_candidates', 200);
        $this->insertBatch('b-0001', 'Nightly');

        for ($n = 1; $n <= 300; $n++) {
            $this->member($n, 'b-0001', 'completed', silenced: $n > 150);
        }

        $response = $this->read('/orrery/api/batches/b-0001')->assertOk();

        self::assertSame(['count' => 101, 'at_least' => true], $response->json('members.silenced'));
        self::assertSame(['count' => 50, 'at_least' => true], $response->json('members.completed'));

        foreach (['completed', 'silenced'] as $tab) {
            self::assertSame(
                $this->read('/orrery/api/jobs?'.http_build_query(['status' => $tab, 'batch' => 'b-0001']))->assertOk()->json('total'),
                $response->json("members.{$tab}"),
                "{$tab}: the member count is the list total",
            );
        }
    }

    /**
     * Rejects: seven failed list requests (and seven reports) for a batch id
     * that no list can name (longer than 255 characters; a SQLite table
     * keeps it): the counts are null with one report, and the batch shows.
     */
    public function test_a_batch_id_longer_than_a_list_accepts_skips_the_counts(): void
    {
        if ($this->batchConnection()->getDriverName() !== 'sqlite') {
            self::markTestSkipped('Only SQLite stores a batch id longer than its string(255) column.');
        }

        Exceptions::fake();
        $id = str_repeat('b', 300);
        $this->insertBatch($id, 'Long');

        $response = $this->read('/orrery/api/batches/'.$id)->assertOk();

        self::assertSame($id, $response->json('batch.id'));
        self::assertSame(array_fill_keys(BatchesController::MEMBER_TABS, ['count' => null, 'at_least' => false]), $response->json('members'));
        Exceptions::assertReportedCount(1);
        // The guard's own report, not a refused list request.
        Exceptions::assertReported(static fn (WatchedOperationFailed $reported): bool => $reported->operation === 'http:batch_members' && $reported->causeClass !== InvalidListRequest::class);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function countFailures(): array
    {
        return ['a data error' => ['data'], 'a statement timeout' => ['timeout']];
    }

    /**
     * Rejects: a failed member count answered as 0 or failing the whole
     * show (4.1: a count that failed is `{count: null, at_least: false}`,
     * and is reported); after a data error the next counts still run; after
     * a timeout the remaining counts are null without a read (a failing
     * mirror costs one statement timeout per request).
     */
    #[DataProvider('countFailures')]
    public function test_a_failed_member_count_is_unknown(string $kind): void
    {
        Exceptions::fake();
        $this->insertBatch('b-0001', 'Nightly');
        $this->member(1, 'b-0001', 'failed');
        // The first member count (pending) fails.
        $failed = false;
        $counts = 0;
        $fail = static function (string $sql) use (&$failed, &$counts, $kind): void {
            if (! str_contains($sql, 'capped_rows')) {
                return;
            }

            $counts++;

            if (! $failed) {
                $failed = true;

                throw new QueryException('orrery', $sql, [], $kind === 'timeout' ? new StatementTimeout : new RuntimeException('Simulated count failure.'));
            }
        };
        $sessions = $this->app->make(PackageSession::class);

        if ($sessions->isInMemoryConnection(null)) {
            // The member counts read the mirror: `testbench_mirror` in
            // separate mode, the default connection in same mode.
            PackageStatements::beforeEach($this->app, $sessions->mirrorName(), static fn (string $sql) => $fail($sql));
        } else {
            $mirror = $sessions->mirrorSessionName();
            PackageStatements::each(static function (string $kind, string $sql, ?string $session) use ($fail, $mirror): void {
                if ($kind === PackageSession::STATEMENT && $session === $mirror) {
                    $fail($sql);
                }
            });
        }

        $response = $this->read('/orrery/api/batches/b-0001')->assertOk();

        self::assertTrue($failed);
        self::assertSame(['count' => null, 'at_least' => false], $response->json('members.pending'));

        if ($kind === 'timeout') {
            self::assertSame(1, $counts, 'no count after the timeout');
            self::assertSame(['count' => null, 'at_least' => false], $response->json('members.failed'));
        } else {
            self::assertSame(['count' => 1, 'at_least' => false], $response->json('members.failed'));
        }

        Exceptions::assertReported(static fn (WatchedOperationFailed $reported): bool => $reported->operation === 'http:batch_members');
    }

    /**
     * Rejects: a batch read error answered as an empty list, a 404 or the
     * legacy body without `error`; a statement timeout answered as
     * `read_failed`.
     *
     * @param  'timeout'|'other'  $kind
     */
    #[DataProvider('readErrors')]
    public function test_a_batch_read_error_gives_503(string $kind, string $error): void
    {
        Exceptions::fake();
        $this->insertBatch('b-0001', 'Nightly');
        $this->failBatchReads($kind);

        $this->read('/orrery/api/batches')->assertStatus(503)->assertJsonPath('error', $error);
        $this->read('/orrery/api/batches?query=Night')->assertStatus(503)->assertJsonPath('error', $error);
        $this->read('/orrery/api/batches/b-0001')->assertStatus(503)->assertJsonPath('error', $error);
        Exceptions::assertReported(static fn (WatchedOperationFailed $reported): bool => $reported->operation === 'http:batch_read');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function readErrors(): array
    {
        return ['timeout' => ['timeout', 'read_timeout'], 'other' => ['other', 'read_failed']];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function batchStates(): array
    {
        return ['a batch' => ['batch'], 'an empty table' => ['empty'], 'a missing table' => ['missing'], 'D18' => ['d18'], 'an unsupported repository' => ['unsupported']];
    }

    /**
     * Rejects: batch data returned while the redaction configuration is
     * invalid, and a state that answers before the check (an empty or
     * missing table, D18, an unsupported repository): the configuration
     * error is the same in every state.
     */
    #[DataProvider('batchStates')]
    public function test_an_invalid_redaction_configuration_answers_500(string $state): void
    {
        match ($state) {
            'batch' => $this->insertBatch('b-0001', 'Nightly'),
            'missing' => $this->app['config']->set('queue.batching.table', self::MISSING_TABLE),
            'd18' => HostFiles::useForTesting(null, static fn (string $connection): ?bool => false),
            'unsupported' => $this->bindUnsupported('dynamodb'),
            default => null,
        };
        $this->app['config']->set('orrery.redact.patterns', ['not a pattern']);

        $this->read('/orrery/api/batches')->assertStatus(500)->assertJsonPath('error', 'redaction_config_invalid');
        $this->read('/orrery/api/batches/b-0001')->assertStatus(500)->assertJsonPath('error', 'redaction_config_invalid');
    }

    /**
     * Rejects: a reader that selects every column (`failed_job_ids` can be
     * large) or the options without the byte bound.
     */
    public function test_the_reader_selects_only_the_named_columns(): void
    {
        $this->insertBatch('b-0001', 'Nightly');
        $statements = [];
        PackageStatements::beforeEach($this->app, (string) config('queue.batching.database'), static function (string $sql) use (&$statements): void {
            if (str_contains($sql, 'job_batches')) {
                $statements[] = strtolower($sql);
            }
        });

        $this->read('/orrery/api/batches')->assertOk();
        $this->read('/orrery/api/batches?query=Night')->assertOk();
        $this->read('/orrery/api/batches/b-0001')->assertOk();

        self::assertCount(3, $statements);

        foreach ($statements as $sql) {
            self::assertStringNotContainsString('*', $sql, $sql);
            self::assertStringNotContainsString('failed_job_ids', $sql, $sql);
            self::assertMatchesRegularExpression('/case when .*options.* <= 65536 then .*options.* end/', $sql, $sql);
        }
    }

    /**
     * Rejects: Postgres options decoded without the base64 rule of Laravel's
     * repository, the rule applied on another engine, and a base64 decode of
     * a text that holds `:` or `;` (a serialized value stored raw).
     */
    public function test_the_options_decode_follows_laravels_base64_rule(): void
    {
        $serialized = serialize(['queue' => 'emails']);

        self::assertSame($serialized, DatabaseBatchRepository::decodeOptions(base64_encode($serialized), 'pgsql'));
        self::assertSame($serialized, DatabaseBatchRepository::decodeOptions($serialized, 'pgsql'));
        self::assertSame(base64_encode($serialized), DatabaseBatchRepository::decodeOptions(base64_encode($serialized), 'mysql'));
        self::assertSame(base64_encode($serialized), DatabaseBatchRepository::decodeOptions(base64_encode($serialized), 'sqlite'));
        self::assertNull(DatabaseBatchRepository::decodeOptions(null, 'pgsql'));
    }

    /**
     * Binds an unsupported batch repository: a subclass of Laravel's
     * database repository that records its calls, or the DynamoDB
     * repository (built without its client).
     *
     * @return \ArrayObject<int, string>
     */
    private function bindUnsupported(string $kind): \ArrayObject
    {
        $calls = new \ArrayObject;
        $repository = $kind === 'dynamodb'
            ? (new ReflectionClass(DynamoBatchRepository::class))->newInstanceWithoutConstructor()
            : new class($this->app->make(BatchFactory::class), $this->app['db']->connection(), 'job_batches', $calls) extends LaravelDatabaseBatchRepository {
                /** @param  \ArrayObject<int, string>  $calls */
                public function __construct(BatchFactory $factory, Connection $connection, string $table, private \ArrayObject $calls)
                {
                    parent::__construct($factory, $connection, $table);
                }

                public function get($limit = 50, $before = null)
                {
                    $this->calls->append('get');

                    return parent::get($limit, $before);
                }

                public function find(string $batchId)
                {
                    $this->calls->append('find');

                    return parent::find($batchId);
                }
            };
        $this->app->instance(BatchRepository::class, $repository);

        return $calls;
    }

    private function read(string $uri): TestResponse
    {
        return $this->actingAs(new User)->getJson($uri);
    }

    /**
     * @return list<string>
     */
    private function ids(string $uri): array
    {
        return array_column((array) $this->read($uri)->assertOk()->json('batches'), 'id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertBatch(string $id, string $name, array $overrides = []): void
    {
        $this->batchTable()->insert(array_merge([
            'id' => $id,
            'name' => $name,
            'total_jobs' => 4,
            'pending_jobs' => 1,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => $this->storedOptions(serialize(['connection' => 'database', 'queue' => 'emails', 'allowFailures' => true])),
            'created_at' => self::T,
            'cancelled_at' => null,
            'finished_at' => null,
        ], $overrides));
    }

    /**
     * The options as Laravel's repository stores them: base64 on Postgres.
     * $countDelta adds entries to the outer array's declared count.
     */
    private function storedOptions(string $serialized, int $countDelta = 0): string
    {
        if ($countDelta !== 0) {
            $serialized = (string) preg_replace_callback('/^a:(\d+):/', static fn (array $m): string => 'a:'.((int) $m[1] + $countDelta).':', $serialized, 1);
        }

        return $this->batchConnection()->getDriverName() === 'pgsql' ? base64_encode($serialized) : $serialized;
    }

    private function serializedOptions(string $id): string
    {
        return (string) DatabaseBatchRepository::decodeOptions((string) $this->batchTable()->where('id', $id)->value('options'), $this->batchConnection()->getDriverName());
    }

    /** Every capped count on the mirror fails with a data error. */
    private function failMirrorCounts(): void
    {
        $fail = static function (string $sql): void {
            if (str_contains($sql, 'capped_rows')) {
                throw new QueryException('orrery', $sql, [], new RuntimeException('Simulated count failure.'));
            }
        };
        $sessions = $this->app->make(PackageSession::class);

        if ($sessions->isInMemoryConnection(null)) {
            PackageStatements::beforeEach($this->app, $sessions->mirrorName(), static fn (string $sql) => $fail($sql));
        } else {
            $mirror = $sessions->mirrorSessionName();
            PackageStatements::each(static function (string $kind, string $sql, ?string $session) use ($fail, $mirror): void {
                if ($kind === PackageSession::STATEMENT && $session === $mirror) {
                    $fail($sql);
                }
            });
        }
    }

    private function member(int $n, string $batch, string $status, bool $silenced = false): void
    {
        $time = Clock::toDecimal(self::T + $n);
        $terminal = in_array($status, ['completed', 'failed', 'retrying', 'unknown'], true);

        $this->mirror()->table('orrery_jobs')->insert([
            'uuid' => sprintf('0b000000-0000-4000-8000-%012d', $n),
            'connection' => 'database',
            'queue' => 'default',
            'name' => 'App\\Jobs\\Member',
            'status' => $status,
            'silenced' => $silenced,
            'payload' => '{}',
            'payload_bytes' => 2,
            'batch_id' => $batch,
            'occurrence_id' => in_array($status, ['failed', 'retrying'], true) ? $n : null,
            'pushed_at' => $time,
            'reserved_at' => $status === 'reserved' ? $time : null,
            'finished_at' => $terminal ? $time : null,
            'failed_at' => in_array($status, ['failed', 'retrying'], true) ? $time : null,
            'updated_at' => $time,
        ]);
    }

    private function batchConnection(): Connection
    {
        return $this->app['db']->connection((string) config('queue.batching.database'));
    }

    private function batchTable(): Builder
    {
        return $this->batchConnection()->table((string) config('queue.batching.table'));
    }

    /**
     * Every batch-table read of the package session fails: with a Postgres
     * statement timeout (57014), or with another error.
     *
     * @param  'timeout'|'other'  $kind
     */
    private function failBatchReads(string $kind): void
    {
        $connection = (string) config('queue.batching.database');

        PackageStatements::beforeEach($this->app, $connection, static function (string $sql, array $bindings) use ($kind, $connection): void {
            if (str_starts_with(strtolower(ltrim($sql)), 'select') && str_contains($sql, 'job_batches')) {
                throw new QueryException(PackageSession::NAME_PREFIX.$connection, $sql, $bindings, $kind === 'timeout' ? new StatementTimeout : new RuntimeException('Simulated read failure.'));
            }
        });
    }
}
