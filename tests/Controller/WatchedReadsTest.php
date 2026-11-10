<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Support\FreshSchema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The reads of the package storage behind the read endpoints run in the
 * watcher: a failed read answers a JSON 503, never a raw 500 whose
 * QueryException (with its SQL and bindings) reaches the host's handler.
 */
#[FreshSchema]
final class WatchedReadsTest extends ControllerTest
{
    /**
     * Rejects: an endpoint that reads the mirror outside the watcher, so a mirror error answers a 500.
     */
    #[DataProvider('endpoints')]
    public function test_a_failed_mirror_read_answers_503(string $uri): void
    {
        $this->mirrorOutage();

        $this->getJson($uri)->assertStatus(503)->assertJsonPath('error', 'read_failed')->assertJsonStructure(['error', 'message']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function endpoints(): array
    {
        return [
            'throughput' => ['/orrery/api/throughput'],
            'queue history' => ['/orrery/api/queues/database/default/history'],
            'workers' => ['/orrery/api/workers'],
            'control' => ['/orrery/api/control'],
            'markers' => ['/orrery/api/markers'],
            'workload' => ['/orrery/api/workload'],
            'failure groups' => ['/orrery/api/failures/groups?from=1790000000&to=1790003600'],
            'failure group' => ['/orrery/api/failures/groups/'.str_repeat('a', 64).'?from=1790000000&to=1790003600'],
        ];
    }

    /**
     * The failure groups check the redaction configuration before the
     * watched read, so an invalid configuration is still its own answer.
     *
     * Rejects: a check left inside the watched read, where the
     * configuration error becomes a 503 `read_failed`.
     */
    public function test_an_invalid_redaction_configuration_is_not_a_read_failure(): void
    {
        $this->app['config']->set('orrery.redact.patterns', ['/(unclosed/']);

        foreach (['/orrery/api/failures/groups?from=1790000000&to=1790003600', '/orrery/api/failures/groups/'.str_repeat('a', 64).'?from=1790000000&to=1790003600'] as $uri) {
            $this->getJson($uri)->assertStatus(500)->assertJsonPath('error', 'redaction_config_invalid');
        }
    }

    /**
     * Rejects: a control write that fails on the mirror and answers a raw
     * 500, whose error (with its SQL) reaches the host's handler.
     */
    public function test_a_failed_control_write_answers_503(): void
    {
        $this->mirrorOutage();

        $this->postJson('/orrery/api/control', ['action' => 'pause'])
            ->assertStatus(503)
            ->assertJsonPath('error', 'write_failed')
            ->assertJsonStructure(['error', 'message']);
    }

    /**
     * Rejects: a queue pause or continue whose Laravel part happened but whose package record failed, answered
     * with the command line's wording ("Run the command again"), with `paused` missing or wrong, or as
     * `write_failed`.
     * Still passes: a message that changes its words but keeps naming the state of the queue.
     */
    public function test_a_failed_record_after_the_native_change_answers_record_failed(): void
    {
        $this->mirrorOutage();

        $pause = $this->postJson('/orrery/api/control', ['action' => 'pause', 'scope' => 'queue', 'connection' => 'database', 'queue' => 'emails']);
        $pause->assertStatus(503);
        self::assertSame([
            'error' => 'record_failed',
            'paused' => true,
            'message' => "The queue is paused through Laravel's queue pause, but Orrery could not save its record of the pause (who paused it). Reload to see the current state.",
        ], $pause->json());

        $continue = $this->postJson('/orrery/api/control', ['action' => 'continue', 'scope' => 'queue', 'connection' => 'database', 'queue' => 'emails']);
        $continue->assertStatus(503);
        self::assertSame([
            'error' => 'record_failed',
            'paused' => false,
            'message' => "Laravel's queue pause was lifted, but Orrery could not update its record or read the other pauses, so another pause may still hold the queue. Reload to see the current state.",
        ], $continue->json());
    }
}
