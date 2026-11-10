<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Justrau\Orrery\Tests\Support\Fixtures\RawHandler;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A raw payload from another producer (Review Focus 5): a `uuid`, a `job`
 * handler and `attempts`, without `displayName`, `maxTries` or `timeout`.
 *
 * Laravel's Job::fire() reads `$payload['data']`, so a payload without a
 * `data` key fails in Laravel itself (an undefined array key), with or
 * without the package. The data set without `data` proves that the store
 * needs neither `displayName` nor `data`: the row exists before the worker
 * and follows Laravel's failure. The data set with an empty `data` proves
 * that the job runs and completes.
 */
final class ForeignPayloadTest extends HooksTestCase
{
    /**
     * Rejects: a store that needs displayName or data.
     *
     * @param  array<string, mixed>  $extra
     */
    #[DataProvider('foreignPayloads')]
    public function test_payload_without_display_name_or_limits_is_mirrored(array $extra, string $outcome): void
    {
        $uuid = strtolower((string) Str::uuid());

        $this->queue()->pushRaw(self::rawPayload($uuid, $extra));

        $row = $this->rowOrFail($uuid);
        self::assertSame('pending', $row->status);
        self::assertSame(RawHandler::class, $row->name, 'The name comes from the job key.');
        self::assertSame('job', $row->type);
        self::assertNull($row->max_tries);
        self::assertNull($row->timeout);
        self::assertNull($row->max_exceptions);
        self::assertNull($row->retry_until);

        $this->workAndLogFailures();

        self::assertSame(0, DB::table('jobs')->count());
        $row = $this->rowOrFail($uuid);
        self::assertSame($outcome, $row->status, 'The row follows the host outcome.');
        self::assertSame(1, (int) $row->attempts);
        self::assertSame($outcome === 'completed' ? 1 : 0, RawHandler::$runs);
        self::assertSame($outcome === 'failed' ? 1 : 0, DB::table('failed_jobs')->count());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function foreignPayloads(): array
    {
        return [
            'without data: Laravel fails the job' => [[], 'failed'],
            'with an empty data: the job runs' => [['data' => []], 'completed'],
        ];
    }
}
