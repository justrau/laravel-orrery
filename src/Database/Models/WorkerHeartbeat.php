<?php

namespace Justrau\Orrery\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Justrau\Orrery\Database\Models\Concerns\UsesMirrorSession;

/**
 * One row per worker process, keyed by worker_id (host:pid:start). The times are epoch seconds.
 *
 * @property string $worker_id
 * @property string $host
 * @property int $pid
 * @property float $started_at
 * @property int|null $ppid
 * @property float|null $parent_started_at
 * @property string $mode
 * @property string $connection
 * @property string $subscribed_queues
 * @property string|null $current_queue
 * @property string $options
 * @property float $last_seen_at
 */
class WorkerHeartbeat extends Model
{
    use UsesMirrorSession;

    protected $table = 'orrery_worker_heartbeats';

    protected $primaryKey = 'worker_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'pid' => 'integer',
        'started_at' => 'float',
        'ppid' => 'integer',
        'parent_started_at' => 'float',
        'last_seen_at' => 'float',
    ];
}
