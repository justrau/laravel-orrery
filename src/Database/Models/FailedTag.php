<?php

namespace Justrau\Orrery\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Justrau\Orrery\Database\Models\Concerns\UsesMirrorSession;

/**
 * A tag of a failed job. The primary key is (job_uuid, tag), so the model has no id.
 *
 * @property string $tag
 * @property string $job_uuid
 * @property float $finished_at
 */
class FailedTag extends Model
{
    use UsesMirrorSession;

    protected $table = 'orrery_failed_tags';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'finished_at' => 'float',
    ];
}
