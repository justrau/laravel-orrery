<?php

namespace Justrau\Orrery\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Justrau\Orrery\Database\Models\Casts\UtcDateTime;
use Justrau\Orrery\Database\Models\Concerns\UsesMirrorSession;

/**
 * @property int $id
 * @property string $signal
 * @property string $scope
 * @property string $scope_value
 * @property float $issued_at
 * @property Carbon|null $expires_at
 * @property string|null $issued_by
 */
class Signal extends Model
{
    use UsesMirrorSession;

    protected $table = 'orrery_signals';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'issued_at' => 'float',
        // UTC, never the application's time zone.
        'expires_at' => UtcDateTime::class,
    ];
}
