<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Projection\Jobs;

use Illuminate\Database\Eloquent\Model;

/**
 * A model without a table: SerializesModels stores it as a ModelIdentifier
 * from its key alone.
 */
class CanaryUser extends Model
{
    protected $guarded = [];

    public static function withKey(int $id): self
    {
        $user = new self;
        $user->setAttribute('id', $id);
        $user->exists = true;

        return $user;
    }
}
