<?php

namespace Justrau\Orrery\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Justrau\Orrery\Database\Models\Concerns\UsesMirrorSession;

/**
 * @property string $tag
 * @property float $created_at
 */
class MonitoredTag extends Model
{
    use UsesMirrorSession;

    protected $table = 'orrery_monitored_tags';

    protected $primaryKey = 'tag';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'float',
    ];
}
