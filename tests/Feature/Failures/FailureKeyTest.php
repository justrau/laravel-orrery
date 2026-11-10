<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Failures;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Justrau\Orrery\Failures\GroupKey;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Lifecycle\Observation;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Failures\Jobs\MessageJob;
use Justrau\Orrery\Tests\Feature\Retry\RetryTestCase;
use Justrau\Orrery\Tests\Support\Fixtures\Lifecycle\BuildsObservations;
use RuntimeException;

/**
 * The stored failure key: JobStore writes
 * orrery_jobs.failure_key with every failure, and the group reads use only
 * the stored key (the fallback scan went with the provider
 * adoption, the only writer of rows without a key).
 */
final class FailureKeyTest extends RetryTestCase
{
    use BuildsObservations;

    protected function setUp(): void
    {
        parent::setUp();

        MessageJob::$message = 'MessageJob fails.';
        $this->app['config']->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');
        Orrery::auth(fn () => true);
        Orrery::operateUsing(fn () => true);
    }

    protected function tearDown(): void
    {
        MessageJob::$message = 'MessageJob fails.';

        parent::tearDown();
    }

    /**
     * A first failure, a second failure with another message, and an
     * insert-path failure with no prior row.
     *
     * Rejects: a key from the unsanitised message, a key without the job
     * name, a key that keeps the first message, and no key at all.
     */
    public function test_failure_key_matches_the_group_key_of_the_stored_columns(): void
    {
        MessageJob::$message = "First message\nwith a second line";
        $this->app['queue']->connection('database')->push(new MessageJob);
        $this->workAndLogFailures();

        $uuid = (string) $this->mirror()->table('orrery_jobs')->value('uuid');
        $first = $this->rowOrFail($uuid);
        self::assertSame('failed', $first->status);
        $firstKey = GroupKey::of(RuntimeException::class, 'First message', MessageJob::class);
        self::assertSame(self::stored($first), $first->failure_key);
        self::assertSame($firstKey, $first->failure_key);
        self::assertNotSame(GroupKey::of(RuntimeException::class, 'First message', null), $first->failure_key, 'the key has the job name');

        // A native retry, and a second failure with another message.
        MessageJob::$message = 'Second message';
        self::assertSame(0, Artisan::call('queue:retry', ['id' => ['all']]));
        $this->workAndLogFailures();

        $second = $this->rowOrFail($uuid);
        self::assertSame(['failed', 2], [$second->status, (int) $second->cycle]);
        self::assertSame(self::stored($second), $second->failure_key);
        self::assertSame(GroupKey::of(RuntimeException::class, 'Second message', MessageJob::class), $second->failure_key);
        self::assertNotSame($firstKey, $second->failure_key, 'the key follows the latest failure');

        // A failure without a prior row (the insert path): the name comes from the set.
        $inserted = strtolower((string) Str::uuid());
        $raw = "raw \0 message with a NUL\nsecond line";

        $outcome = $this->app->make(JobStore::class)->observe(self::observation('fail', $inserted, ['jobId' => 10, 'attempt' => 1, 'exception' => new RuntimeException($raw)]));

        self::assertSame('failWithoutRow', $outcome->rule);
        $insertedRow = $this->rowOrFail($inserted);
        self::assertNotSame($raw, $insertedRow->exception_message, 'the stored message is sanitised');
        self::assertSame(self::stored($insertedRow), $insertedRow->failure_key);
        self::assertSame(GroupKey::of(RuntimeException::class, $insertedRow->exception_message, self::JOB_CLASS), $insertedRow->failure_key);
        self::assertNotSame(GroupKey::of(RuntimeException::class, $raw, self::JOB_CLASS), $insertedRow->failure_key, 'not the raw message');
    }

    /**
     * A package retry whose first attempt fails before the publisher
     * finalises: JobStore::adopt() finalises the retry and applies the
     * failure in one transaction (the AdoptionTest scenario "failure
     * only").
     *
     * Rejects: an adoption path that keeps the key of the earlier failure.
     */
    public function test_an_adopted_retry_failure_has_the_key_of_its_new_message(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $before = $this->rowOrFail($uuid);
        self::assertSame(self::stored($before), $before->failure_key);
        $seen = null;

        Hooks::$callback = function (string $point) use (&$seen): void {
            if ($point !== RetryService::AFTER_COMMIT || $seen !== null) {
                return;
            }

            $published = $this->onlyJobsRow();
            $observation = Observation::fromPayload(Observation::FAIL, $published['payload'], 'database', 'default', [
                'jobId' => $published['id'],
                'attempt' => 1,
                'exception' => new RuntimeException('the adopted attempt failed'),
            ]);
            self::assertNotNull($observation);
            $seen = $this->app->make(JobStore::class)->observe($observation)->rule;
        };

        $result = $this->service()->retry($uuid, $occurrence, 'dashboard');
        Hooks::$callback = null;

        self::assertSame([RetryResult::ACCEPTED, 'adoptRetryFailure'], [$result->result, $seen], 'the failure adopted the claim');
        $row = $this->rowOrFail($uuid);
        self::assertSame(['failed', 2, 'the adopted attempt failed'], [$row->status, (int) $row->cycle, $row->exception_message]);
        self::assertSame(GroupKey::of(RuntimeException::class, 'the adopted attempt failed', $row->name), $row->failure_key);
        self::assertNotSame($before->failure_key, $row->failure_key);
    }

    /**
     * Rejects: the fallback scan (full message re-reads within the byte
     * budget) for keyed rows.
     */
    public function test_long_single_line_messages_keep_the_full_count(): void
    {
        $now = Clock::now();
        // About 54 KB on one line, as a QueryException of a bulk insert has (cut
        // below the 60,000 bytes that ExceptionText stores and MySQL's TEXT holds). 300 rows pass the 8 MiB budget.
        $message = 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry (Connection: mysql, SQL: insert into `items` values '.str_repeat('(1, \'x\'), ', 5_400).')';
        self::assertGreaterThan(8_388_608, 300 * strlen($message), 'the old fallback budget (8 MiB) could not read every row');
        $key = GroupKey::of('Illuminate\\Database\\QueryException', $message, 'App\\Jobs\\ImportChunk');

        for ($i = 0; $i < 300; $i++) {
            $this->mirror()->table('orrery_jobs')->insert([
                'uuid' => (string) Str::uuid(),
                'connection' => 'database',
                'queue' => 'imports',
                'name' => 'App\\Jobs\\ImportChunk',
                'status' => 'failed',
                'payload' => '{}',
                'payload_bytes' => 2,
                'pushed_at' => Clock::toDecimal($now - 65 - $i),
                'finished_at' => Clock::toDecimal($now - 60 - $i),
                'failed_at' => Clock::toDecimal($now - 60 - $i),
                'occurrence_id' => $i + 1,
                'attempts' => 1,
                'exception_class' => 'Illuminate\\Database\\QueryException',
                'exception_message' => $message,
                'failure_key' => $key,
                'exception' => $message."\n#0 trace",
                'updated_at' => Clock::toDecimal($now - 60 - $i),
            ]);
        }

        $full = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$full): void {
            if (str_contains($query->sql, 'exception_message') && ! str_contains($query->sql, 'substr')) {
                $full[] = count($query->bindings);
            }
        });

        $query = '?from='.(int) ($now - 3600).'&to='.(int) $now;
        $body = $this->getJson('/orrery/api/failures/groups'.$query)->assertOk()->json();
        $detail = $this->getJson('/orrery/api/failures/groups/'.$key.$query)->assertOk()->json();

        self::assertSame([false, 1, $key], [$body['truncated'], $body['total'], $body['groups'][0]['key']]);
        self::assertSame([300, false, true], [$body['groups'][0]['count'], $body['groups'][0]['count_capped'], $body['groups'][0]['exact']]);
        self::assertSame([300, true, 200], [$detail['group']['count'], $detail['complete'], count($detail['selection'])]);
        // Only the shown rows read their whole first line, for its redaction: one row per shown group.
        self::assertSame([1, 1], $full, 'no scan reads the whole exception_message; the list and the detail each read their one shown row');
    }

    /**
     * The bulk run with a groups source reads its members and
     * checks each one again by the stored key; before the UI rebuild the group
     * operation (POST /api/failures/retry) did the same.
     *
     * Rejects: a freeze that selects by a key computed from the exception
     * columns, and a re-check (between the page's member read and the
     * member's classification) that computes the key instead of reading the
     * stored one: a, whose columns still give the frozen key but whose
     * stored key changed, would be retried.
     */
    public function test_operation_recheck_uses_the_stored_key(): void
    {
        $this->app['config']->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');
        [$a] = $this->failedJob();
        [$b] = $this->failedJob();
        $computed = self::stored($this->rowOrFail($a));
        self::assertSame($computed, $this->rowOrFail($a)->failure_key);
        self::assertSame($computed, $this->rowOrFail($b)->failure_key);
        $other = str_repeat('e', 64);
        $third = str_repeat('f', 64);

        $now = Clock::now();
        $source = static fn (array $keys): array => ['kind' => 'groups', 'queue' => null, 'from' => (int) ($now - 3600), 'to' => (int) ($now + 60), 'keys' => $keys];
        $freeze = fn (array $keys) => $this->postJson('/orrery/api/bulk/freeze', ['action' => 'retry', 'source' => $source($keys)])->assertOk();

        // The freeze selects by the stored key.
        $this->mirror()->table('orrery_jobs')->where('uuid', $a)->update(['failure_key' => $other]);
        self::assertSame(1, $freeze([$computed])->json('frozen.total.count'), 'only b has the computed key stored');
        self::assertSame(1, $freeze([$other])->json('frozen.total.count'), 'a is a member of the stored key only');

        // The re-check reads the stored key: both are members of the computed key (one segment, one read),
        // and a's stored key changes while b (the newer member) is claimed; a's columns still give the frozen key.
        $this->mirror()->table('orrery_jobs')->where('uuid', $a)->update(['failure_key' => $computed]);
        $frozen = $freeze([$computed]);
        self::assertSame(2, $frozen->json('frozen.total.count'));
        $changed = false;
        $this->onHook(function (string $point) use (&$changed, $a, $third): void {
            if ($point === RetryService::AFTER_CLAIM && ! $changed) {
                $changed = true;
                $this->mirror()->table('orrery_jobs')->where('uuid', $a)->update(['failure_key' => $third]);
            }
        });

        $results = $this->postJson('/orrery/api/bulk/run', ['token' => $frozen->json('frozen.token'), 'after' => null, 'confirmed_total' => null])->assertOk()->json('results');
        $outcomes = array_column(array_map(static fn (array $item): array => ['id' => $item['id'], 'outcome' => [$item['outcome'], $item['code']]], (array) $results), 'outcome', 'id');

        self::assertTrue($changed);
        self::assertSame(['not_member', 'other_group'], $outcomes[$a] ?? null, 'the stored key decides the membership');
        self::assertSame('accepted', $outcomes[$b][0] ?? null);
        self::assertSame('failed', $this->rowOrFail($a)->status, 'a was not retried');
    }

    /**
     * The group key of a row's stored exception columns and name.
     */
    private static function stored(object $row): string
    {
        return GroupKey::of($row->exception_class, $row->exception_message, $row->name);
    }
}
