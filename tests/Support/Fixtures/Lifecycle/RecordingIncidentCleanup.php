<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Lifecycle;

use Illuminate\Database\Connection;
use Justrau\Orrery\Lifecycle\CycleSummaries;
use Justrau\Orrery\Lifecycle\IncidentCleanup;
use Justrau\Orrery\Storage\PackageTransaction;

/**
 * Records what IncidentCleanup::beforeMergedCycleDelete() sees: the
 * incidents of the merged cycle inside the freeze transaction.
 */
final class RecordingIncidentCleanup extends IncidentCleanup
{
    /** @var list<array{uuid: string, cycle: int, at: float, incidents: int, in_transaction: bool, connection: ?string, row_cycle: int, summary_frozen: bool}> */
    public static array $calls = [];

    protected function beforeMergedCycleDelete(Connection $tx, string $uuid, int $cycle, float $at): void
    {
        self::$calls[] = [
            'uuid' => $uuid,
            'cycle' => $cycle,
            'at' => $at,
            'incidents' => $tx->table(self::TABLE)->where('job_uuid', $uuid)->where('cycle', $cycle)->count(),
            'in_transaction' => PackageTransaction::isOpen($tx),
            'connection' => $tx->getName(),
            'row_cycle' => (int) $tx->table('orrery_jobs')->where('uuid', $uuid)->value('cycle'),
            'summary_frozen' => $tx->table(CycleSummaries::TABLE)->where('job_uuid', $uuid)->where('cycle', $cycle + 50)->exists(),
        ];
    }
}
