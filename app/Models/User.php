<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles, HasUuids, TwoFactorAuthenticatable;

    protected $attributes = ['active' => true];

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'active' => 'boolean', 'two_factor_confirmed_at' => 'datetime'];
    }

    public function requiresTwoFactor(): bool
    {
        return false;
    }
}
