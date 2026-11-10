<?php

namespace Workbench\App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A user of the Workbench's strict-auth mode (WorkbenchAuth::USERS), in the `users` table of
 * Testbench's default migrations.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 */
class User extends Authenticatable
{
    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}
