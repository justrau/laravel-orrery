<?php

namespace Justrau\Orrery\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Justrau\Orrery\Database\Models\Concerns\UsesMirrorSession;

/**
 * A tag of a job. The primary key is (tag, job_uuid), so the model has no id.
 *
 * @property string $tag
 * @property string $job_uuid
 * @property float $pushed_at
 */
class JobTag extends Model
{
    use UsesMirrorSession;

    protected $table = 'orrery_job_tags';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'pushed_at' => 'float',
    ];
}
