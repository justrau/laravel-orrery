<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Bulk\Fixtures;

use Justrau\Orrery\Queue\BulkResolver;

/**
 * A BulkResolver whose floor read runs a statement that the server rejects,
 * on the session that the resolver reads the jobs table with. On Postgres,
 * such an error aborts the transaction of the session that ran it.
 */
class ServerErrorFloorResolver extends BulkResolver
{
    public int $floorReads = 0;

    public function floor(string $connection, string $queue): int
    {
        $this->floorReads++;
        $this->readSession($connection)->select('select id from orrery_b11_no_such_table');

        return parent::floor($connection, $queue);
    }
}
