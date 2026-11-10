<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Lifecycle;

use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Tests\Support\Fixtures\Lifecycle\BuildsObservations;
use Justrau\Orrery\Tests\TestCase;
use RuntimeException;

/**
 * Exception text with a NUL byte or invalid UTF-8 (plan, Review Focus 3).
 * Postgres refuses both in a text column, so the stored text is sanitised.
 */
final class JobStoreFailureTextTest extends TestCase
{
    use BuildsObservations;

    /**
     * Rejects: storing the raw text, which Postgres refuses.
     */
    public function test_nul_and_invalid_utf8_exception_text_is_recorded(): void
    {
        $uuid = '5f0c6a0e-8a4b-4f0a-9c3e-1a2b3c4d5e6f';
        $message = "binary body \0 with a NUL and \xC3\x28 invalid UTF-8";
        $exception = new BinaryContextException($message);

        $store = $this->app->make(JobStore::class);
        $store->observe(self::observation('push', $uuid, ['jobId' => 10]));
        $store->observe(self::observation('reserve', $uuid, ['jobId' => 10, 'attempt' => 1]));

        $outcome = $store->observe(self::observation('fail', $uuid, ['jobId' => 10, 'attempt' => 1, 'exception' => $exception]));

        self::assertSame('fail', $outcome->rule);
        self::assertTrue($outcome->applied);

        $row = $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->first();
        self::assertSame('failed', $row->status);

        foreach (['exception', 'exception_message', 'context'] as $column) {
            self::assertIsString($row->{$column});
            self::assertTrue(mb_check_encoding($row->{$column}, 'UTF-8'), "{$column} is valid UTF-8");
            self::assertStringNotContainsString("\0", $row->{$column}, "{$column} has no NUL byte");
        }

        self::assertStringContainsString('binary body \\0 with a NUL', $row->exception_message, 'the NUL byte is written as the two characters \\0');
        self::assertStringContainsString('binary body \\0 with a NUL', $row->exception);
        self::assertSame(BinaryContextException::class, $row->exception_class);
        self::assertSame(strlen($row->exception), (int) $row->exception_bytes);
        self::assertSame(strlen($row->context), (int) $row->context_bytes);

        $history = $this->mirror()->table('orrery_job_history')->where('job_uuid', $uuid)->where('event', 'failed')->first();
        self::assertNotNull($history, 'the failed history row exists');
        self::assertSame($row->exception, $history->exception);
        self::assertSame((int) $history->id, (int) $row->occurrence_id);
        self::assertSame(strlen($history->exception), (int) $history->exception_bytes);
    }

    /**
     * Rejects: a message longer than MySQL's TEXT column, or a cut inside a multibyte character.
     */
    public function test_long_message_is_cut_on_a_character_boundary(): void
    {
        $uuid = '6f0c6a0e-8a4b-4f0a-9c3e-1a2b3c4d5e6f';
        $message = str_repeat('ž', 40000); // 80,000 bytes

        $store = $this->app->make(JobStore::class);
        $store->observe(self::observation('fail', $uuid, ['jobId' => 10, 'attempt' => 1, 'exception' => new RuntimeException($message)]));

        $stored = (string) $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->value('exception_message');

        self::assertLessThanOrEqual(60000, strlen($stored));
        self::assertGreaterThan(59990, strlen($stored));
        self::assertTrue(mb_check_encoding($stored, 'UTF-8'));
    }
}

/**
 * An exception whose context() carries a NUL byte and invalid UTF-8.
 */
final class BinaryContextException extends RuntimeException
{
    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return ['body' => "raw \0 \xFF bytes"];
    }
}
