<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Justrau\Orrery\Retry\RetryPayload;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Tests\Support\MirrorSession;
use Justrau\Orrery\Tests\TestCase;
use PDOException;
use RuntimeException;

/**
 * The payload of a package retry (RetryPayload), as Laravel's RetryCommand
 * builds it.
 */
final class RetryPayloadTest extends TestCase
{
    /**
     * Rejects: a payload build that fails when the job's model row is gone; Laravel's queue:retry keeps the
     * payload then (RetryCommand::refreshRetryUntil() catches ModelNotFoundException), so a retry does too.
     */
    public function test_a_gone_model_keeps_the_payload(): void
    {
        $raw = json_encode([
            'uuid' => '7f1e2a40-1c3b-4d5e-8f60-718293a4b5c6',
            'attempts' => 3,
            'retryUntil' => 1700000000,
            'data' => ['commandName' => GoneModelJob::class, 'command' => serialize(new GoneModelJob)],
        ], JSON_THROW_ON_ERROR);

        $built = json_decode($this->app->make(RetryPayload::class)->build($raw, 2, 'op-1'), true);

        self::assertIsArray($built);
        self::assertSame(0, $built['attempts']);
        self::assertSame(1700000000, $built['retryUntil'], 'retryUntil stays as it was.');
        self::assertSame(['cycle' => 2, 'operation' => 'op-1'], $built['orreryRetry']);
        self::assertSame('op-1', $built['orreryGen']);
    }

    /**
     * Rejects: a payload build inside the watcher: while the mirror breaker is open (also after
     * another PHP-FPM request opened it), the watcher skipped the build, and an operator's retry answered
     * `failed / prepare_failed` for a payload that is fine.
     * Still passes: a build that skips the watcher only while the breaker is open.
     */
    public function test_an_open_breaker_does_not_refuse_the_payload(): void
    {
        $watcher = $this->app->make(Watcher::class);
        $watcher->run('test:lost', static function (): never {
            throw new QueryException(MirrorSession::name(), 'select 1', [], new class extends PDOException
            {
                public function __construct()
                {
                    parent::__construct('SQLSTATE[08006]: connection failure');
                    $this->code = '08006';
                    $this->errorInfo = ['08006', 7, 'connection failure'];
                }
            });
        });
        self::assertTrue($watcher->breakerOpen());

        $raw = json_encode(['uuid' => '7f1e2a40-1c3b-4d5e-8f60-718293a4b5c8', 'attempts' => 2], JSON_THROW_ON_ERROR);
        $built = json_decode($this->app->make(RetryPayload::class)->build($raw, 2, 'op-3'), true);

        self::assertIsArray($built);
        self::assertSame(0, $built['attempts']);
        self::assertSame('op-3', $built['orreryGen']);
    }

    /**
     * Rejects: a catch that swallows every error of the job instance: any other error still refuses the payload.
     */
    public function test_another_unserialize_error_still_refuses(): void
    {
        $raw = json_encode([
            'uuid' => '7f1e2a40-1c3b-4d5e-8f60-718293a4b5c7',
            'data' => ['commandName' => BrokenJob::class, 'command' => serialize(new BrokenJob)],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the retry payload cannot be prepared');

        $this->app->make(RetryPayload::class)->build($raw, 2, 'op-2');
    }
}

final class GoneModelJob
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw (new ModelNotFoundException)->setModel('App\\Models\\Order', [48213]);
    }

    public function retryUntil(): int
    {
        return 1;
    }
}

final class BrokenJob
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new RuntimeException('broken');
    }
}
