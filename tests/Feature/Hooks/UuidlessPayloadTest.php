<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Hooks;

use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Tests\Support\Fixtures\RawHandler;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A payload without a canonical-UUID `uuid` and without `id` is not
 * mirrored, and the job still runs.
 * Rewrites of RawPayloadProbeTest and RawPayloadControlProbeTest (F1e).
 */
final class UuidlessPayloadTest extends HooksTestCase
{
    /**
     * Rejects: a hook that needs a uuid to run the job, or a mirror row keyed by a made-up uuid.
     *
     * @param  array<string, mixed>  $identity
     */
    #[DataProvider('payloads')]
    public function test_job_runs_once_and_no_mirror_row_exists(array $identity): void
    {
        $payload = (string) json_encode(['job' => RawHandler::class.'@handle', 'data' => [], 'attempts' => 0] + $identity);

        $this->queue()->pushRaw($payload);

        self::assertSame(1, DB::table('jobs')->count());
        self::assertSame(0, $this->mirrorCount(), 'No mirror row before the worker runs.');

        $this->work();

        self::assertSame(1, RawHandler::$runs, 'The job ran once.');
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, DB::table('failed_jobs')->count());
        self::assertSame(0, $this->mirrorCount(), 'No mirror row after the job ran.');
        self::assertSame(0, self::unverified(), 'Nothing was armed, so nothing is counted.');
        self::assertSame(0, self::dropped());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function payloads(): array
    {
        return [
            'no uuid and no id' => [[]],
            'a uuid that is not canonical' => [['uuid' => 'not-a-uuid', 'id' => 42]],
            'swap off, no uuid and no id' => [[]],
        ];
    }
}
