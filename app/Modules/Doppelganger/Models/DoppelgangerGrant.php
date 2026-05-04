<?php

namespace App\Modules\Doppelganger\Models;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoppelgangerGrant extends Model
{
    public const ACCESS_READ_ONLY = 'read_only';
    public const ACCESS_USE_VOICE = 'use_voice';
    public const ACCESS_USE_WORKFLOW = 'use_workflow';
    public const ACCESS_FULL = 'full';

    public const ACCESS_LEVELS = [
        self::ACCESS_READ_ONLY,
        self::ACCESS_USE_VOICE,
        self::ACCESS_USE_WORKFLOW,
        self::ACCESS_FULL,
    ];

    protected $table = 'doppelganger_grants';

    protected $fillable = [
        'doppelganger_id',
        'grantee_user_id',
        'access_level',
        'granted_by_admin_id',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function doppelganger(): BelongsTo
    {
        return $this->belongsTo(Doppelganger::class);
    }

    public function grantee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantee_user_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'granted_by_admin_id');
    }

    public function isActive(): bool
    {
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }
        return true;
    }

    public function canUseVoice(): bool
    {
        return in_array($this->access_level, [self::ACCESS_USE_VOICE, self::ACCESS_FULL], true);
    }

    public function canUseWorkflow(): bool
    {
        return in_array($this->access_level, [self::ACCESS_USE_WORKFLOW, self::ACCESS_FULL], true);
    }
}
