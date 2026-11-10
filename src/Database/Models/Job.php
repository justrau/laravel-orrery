<?php

namespace Justrau\Orrery\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Justrau\Orrery\Database\Models\Concerns\UsesMirrorSession;

/**
 * One mirror row per job uuid. Every time is epoch seconds, except retry_until
 * (Laravel's integer retryUntil).
 *
 * @property int $id
 * @property string $uuid
 * @property int $cycle
 * @property string|null $cycle_gen
 * @property int|null $cycle_first_job_id
 * @property float|null $cycle_started_at
 * @property string $cycle_source
 * @property int|null $occurrence_id
 * @property int|null $job_id
 * @property int|null $prev_job_id
 * @property bool $job_id_unresolved
 * @property int|null $bulk_floor_id
 * @property int|null $provider_id
 * @property string|null $batch_id
 * @property string $connection
 * @property string $queue
 * @property string $name
 * @property string $type
 * @property string $status
 * @property bool $silenced
 * @property bool $monitored
 * @property int|null $monitor_version
 * @property bool $tags_truncated
 * @property bool $history_truncated
 * @property int $history_discarded
 * @property float|null $retry_requested_at
 * @property string $payload
 * @property int $payload_bytes
 * @property int|null $max_tries
 * @property int|null $retry_until
 * @property int|null $max_exceptions
 * @property int|null $timeout
 * @property bool $fail_on_timeout
 * @property string|null $exception
 * @property int|null $exception_bytes
 * @property string|null $exception_class
 * @property string|null $exception_message
 * @property string|null $context
 * @property int|null $context_bytes
 * @property int $attempts
 * @property int $releases
 * @property int $exceptions
 * @property int $timeouts
 * @property int $reclaims
 * @property int $observed_attempts
 * @property int $late_outcomes
 * @property int $last_outcome_attempt
 * @property int $last_exception_attempt
 * @property int $last_timeout_attempt
 * @property int $observed_gap
 * @property int $retried_count
 * @property bool $retried_count_inferred
 * @property int|null $worker_tries
 * @property int|null $worker_timeout
 * @property int|null $worker_evidence_attempt
 * @property string|null $reserved_by
 * @property float $pushed_at
 * @property float|null $available_at
 * @property float|null $reserved_at
 * @property float|null $finished_at
 * @property float|null $failed_at
 * @property float|null $retried_at
 * @property float|null $activity_at
 * @property float|null $absent_since
 * @property float|null $unknown_since
 * @property string|null $unknown_reason
 * @property float $updated_at
 */
class Job extends Model
{
    use UsesMirrorSession;

    protected $table = 'orrery_jobs';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'cycle' => 'int',
        'cycle_first_job_id' => 'int',
        'cycle_started_at' => 'float',
        'occurrence_id' => 'int',
        'job_id' => 'int',
        'prev_job_id' => 'int',
        'job_id_unresolved' => 'bool',
        'bulk_floor_id' => 'int',
        'provider_id' => 'int',
        'silenced' => 'bool',
        'monitored' => 'bool',
        'monitor_version' => 'int',
        'tags_truncated' => 'bool',
        'history_truncated' => 'bool',
        'history_discarded' => 'int',
        'retry_requested_at' => 'float',
        'payload_bytes' => 'int',
        'max_tries' => 'int',
        'retry_until' => 'int',
        'max_exceptions' => 'int',
        'timeout' => 'int',
        'fail_on_timeout' => 'bool',
        'exception_bytes' => 'int',
        'context_bytes' => 'int',
        'attempts' => 'int',
        'releases' => 'int',
        'exceptions' => 'int',
        'timeouts' => 'int',
        'reclaims' => 'int',
        'observed_attempts' => 'int',
        'late_outcomes' => 'int',
        'last_outcome_attempt' => 'int',
        'last_exception_attempt' => 'int',
        'last_timeout_attempt' => 'int',
        'observed_gap' => 'int',
        'retried_count' => 'int',
        'retried_count_inferred' => 'bool',
        'worker_tries' => 'int',
        'worker_timeout' => 'int',
        'worker_evidence_attempt' => 'int',
        'pushed_at' => 'float',
        'available_at' => 'float',
        'reserved_at' => 'float',
        'finished_at' => 'float',
        'failed_at' => 'float',
        'retried_at' => 'float',
        'activity_at' => 'float',
        'absent_since' => 'float',
        'unknown_since' => 'float',
        'updated_at' => 'float',
    ];
}
