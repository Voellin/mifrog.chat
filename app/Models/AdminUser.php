<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\QueryException;

class AdminUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'username',
        'display_name',
        'email',
        'password',
        'otp_secret',
        'is_active',
        'is_super_admin',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'otp_secret',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_super_admin' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(AdminPermission::class, 'admin_user_permissions')->withTimestamps();
    }

    public function hasAdminPermission(string $permission): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        try {
            if ($this->relationLoaded('permissions')) {
                return $this->permissions->contains('permission_key', $permission);
            }

            return $this->permissions()
                ->where('permission_key', $permission)
                ->exists();
        } catch (QueryException) {
            // During first deploy before the permission migration runs, keep the existing admin usable.
            return true;
        }
    }

    public function hasAnyAdminPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasAdminPermission((string) $permission)) {
                return true;
            }
        }

        return false;
    }
}
