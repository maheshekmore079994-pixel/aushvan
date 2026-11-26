<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\RoleName;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'store_id', 'name', 'username', 'mobile', 'email', 'password', 'pin', 'locale', 'preferences',
    'is_pharmacist', 'pharmacist_reg_no', 'is_active', 'last_login_at',
])]
#[Hidden(['password', 'pin', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, SoftDeletes;

    protected string $guard_name = 'web';

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'pin' => 'hashed',
            'is_pharmacist' => 'boolean',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'preferences' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function isOwner(): bool
    {
        return $this->hasRole(RoleName::Owner->value);
    }

    /**
     * Permission names this user effectively holds (owners hold all).
     *
     * @return list<string>
     */
    public function effectivePermissions(): array
    {
        return $this->isOwner()
            ? Permission::all()
            : $this->getAllPermissions()->pluck('name')->values()->all();
    }
}
