<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Projection;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Justrau\Orrery\Failures\GroupKey;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;

/**
 * The failure groups' text fields go through the job projection:
 * `message`, `latest_trace` and the member rows; an invalid
 * pattern answers 500; /api/health names only the invalid rule's index.
 */
final class FailureGroupsRedactionTest extends ControllerTest
{
    private const NOW = 1_789_999_200.0;

    private int $rows = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromFormat('U.u', '1789999200.000000'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: the stored message or trace returned unredacted in the list
     * or the detail.
     */
    public function test_messages_and_traces_are_redacted(): void
    {
        $message = 'Login failed password=canary-group-message for bob';
        $this->failed($message, "RuntimeException: {$message}\n#0 Bearer canary-group-trace\n#1 mysql://root:canary-trace-url@db");
        $key = GroupKey::of('RuntimeException', $message, 'App\\Jobs\\Sync');

        $list = $this->failureGroups();
        $detail = $this->failureGroups($key);

        foreach ([$list, $detail] as $response) {
            $response->assertOk();
            self::assertStringNotContainsString('canary', (string) $response->getContent());
        }

        self::assertSame('Login failed password=[redacted] for bob', $list->json('groups.0.message'));
        self::assertSame($key, $list->json('groups.0.key'), 'The key stays the stored key.');
        self::assertSame('Login failed password=[redacted] for bob', $detail->json('group.message'));
        self::assertStringContainsString('#0 Bearer [redacted]', (string) $detail->json('latest_trace'));
        self::assertSame('App\\Jobs\\Sync', $detail->json('jobs.0.name'), 'The job name is not redacted.');
    }

    /**
     * A first line longer than the scan's head whose secret straddles the
     * 500-character cut and needs text past the head to match.
     *
     * Rejects: redaction of the scanned head only (the URL password's "@"
     * is past the head, so the head has no match).
     */
    public function test_a_long_first_line_is_redacted_whole(): void
    {
        $message = str_repeat('a', 440).' mysql://root:canary-long-'.str_repeat('p', 700).'@db rest';
        $this->failed($message, 'RuntimeException');
        $key = GroupKey::of('RuntimeException', $message, 'App\\Jobs\\Sync');

        $list = $this->failureGroups()->assertOk();
        $detail = $this->failureGroups($key)->assertOk();

        self::assertStringNotContainsString('canary-long', (string) $list->getContent());
        self::assertStringNotContainsString('canary-long', (string) $detail->getContent());
        self::assertSame(str_repeat('a', 440).' mysql://[redacted]@db rest', $list->json('groups.0.message'), 'The whole line is redacted, then cut.');
    }

    /**
     * A configured pattern whose match spans lines.
     *
     * Rejects: redaction of the first line alone (the pattern needs the
     * closing brace on the second line).
     */
    public function test_the_whole_message_is_redacted_before_its_first_line_is_cut(): void
    {
        $this->app['config']->set('orrery.redact.patterns', ['~SECRET\{[^}]*\}~']);

        // A short message (the scanned head is the whole message), and one whose closing brace is past the head.
        foreach (["Sign failed SECRET{canary-multiline\nrest}", "Sign failed SECRET{canary-long-multiline\n".str_repeat('r', 1_100).'}'] as $message) {
            $this->failed($message, 'RuntimeException');
            $key = GroupKey::of('RuntimeException', $message, 'App\\Jobs\\Sync');

            $list = $this->failureGroups()->assertOk();
            $detail = $this->failureGroups($key)->assertOk();

            self::assertSame(['Sign failed [redacted]'], array_values(array_unique(array_column($list->json('groups'), 'message'))));
            self::assertSame('Sign failed [redacted]', $detail->json('group.message'));
            self::assertStringNotContainsString('canary', (string) $list->getContent().$detail->getContent());
        }
    }

    /**
     * Rejects: an oversized trace loaded (no size CASE in the SQL) or cut to
     * a prefix.
     */
    public function test_an_oversized_trace_is_omitted_whole(): void
    {
        $trace = 'RuntimeException: Boom '.str_repeat('t', 70_000);
        $this->failed('Boom', $trace);
        $statements = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $detail = $this->failureGroups(GroupKey::of('RuntimeException', 'Boom', 'App\\Jobs\\Sync'))->assertOk();

        self::assertSame(['omitted' => 'too_large', 'bytes' => strlen($trace)], $detail->json('latest_trace'));
        self::assertNotEmpty(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'CASE WHEN exception_bytes <= 65536 THEN exception END')), 'The trace is read with the size CASE.');
    }

    /**
     * Rejects: job data answered while a pattern is invalid, or an error
     * that shows the pattern text.
     */
    public function test_an_invalid_pattern_answers_500_and_health_names_its_index(): void
    {
        $this->failed('Login failed password=canary-config', 'RuntimeException');
        $this->app['config']->set('orrery.redact.patterns', ['~ok~', 'canary-pattern(']);

        foreach ([$this->failureGroups(), $this->failureGroups(GroupKey::of('RuntimeException', 'Login failed password=canary-config', 'App\\Jobs\\Sync'))] as $response) {
            $response->assertStatus(500);
            self::assertSame('redaction_config_invalid', $response->json('error'));
            self::assertSame('orrery.redact.patterns.1', $response->json('field'));
            self::assertStringNotContainsString('canary', (string) $response->getContent());
        }

        $health = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk();
        self::assertSame(['valid' => false, 'errors' => [['field' => 'orrery.redact.patterns.1']]], $health->json('redaction'));
        self::assertStringNotContainsString('canary', (string) $health->getContent());
    }

    /**
     * Rejects: a health response without the redaction state.
     */
    public function test_health_reports_a_valid_configuration(): void
    {
        self::assertSame(['valid' => true, 'errors' => []], $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk()->json('redaction'));
    }

    /**
     * The dashboard reads with `cache.serializable_classes => false` (N-8):
     * the package caches only arrays of scalars and unserializes no job
     * data.
     *
     * Rejects: a dashboard read that needs to unserialize a class.
     */
    public function test_the_reads_work_without_serializable_classes(): void
    {
        $this->app['config']->set('cache.serializable_classes', false);
        $this->failed('Boom', 'RuntimeException: Boom');

        $this->failureGroups()->assertOk();
        $this->failureGroups(GroupKey::of('RuntimeException', 'Boom', 'App\\Jobs\\Sync'))->assertOk();
        $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk();
        $this->actingAs(new User)->getJson('/orrery/api/workload')->assertOk();
    }

    private function failureGroups(?string $key = null): TestResponse
    {
        $query = http_build_query(['from' => (string) (self::NOW - 3600), 'to' => (string) self::NOW]);

        return $this->actingAs(new User)->getJson('/orrery/api/failures/groups'.($key === null ? '' : '/'.$key).'?'.$query);
    }

    private function failed(string $message, string $trace): void
    {
        $this->rows++;
        $uuid = sprintf('00000000-0000-4000-8000-%012d', $this->rows);
        $failedAt = Clock::toDecimal(self::NOW - 10);

        $this->mirror()->table('orrery_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'name' => 'App\\Jobs\\Sync',
            'type' => 'job',
            'status' => 'failed',
            'payload' => '{}',
            'payload_bytes' => 2,
            'occurrence_id' => 1000 + $this->rows,
            'exception_class' => 'RuntimeException',
            'exception_message' => $message,
            'exception' => $trace,
            'exception_bytes' => strlen($trace),
            'failure_key' => GroupKey::of('RuntimeException', $message, 'App\\Jobs\\Sync'),
            'attempts' => 1,
            'pushed_at' => $failedAt,
            'finished_at' => $failedAt,
            'failed_at' => $failedAt,
            'updated_at' => $failedAt,
        ]);
    }
}
