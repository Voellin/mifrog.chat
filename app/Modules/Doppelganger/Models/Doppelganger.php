<?php

namespace App\Modules\Doppelganger\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Doppelganger extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SAMPLE_EXTRACTING = 'sample_extracting';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SAMPLE_EXTRACTING,
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_EXPIRED,
        self::STATUS_REVOKED,
    ];

    protected $table = 'doppelgangers';

    protected $fillable = [
        'source_user_id',
        'display_name',
        'status',
        'consent_signed_at',
        'consent_doc_path',
        'enabled_at',
        'expires_at',
        'service_fee_paid_until',
        'samples_extracted_at',
        'meta',
    ];

    protected $casts = [
        'consent_signed_at' => 'datetime',
        'enabled_at' => 'datetime',
        'expires_at' => 'datetime',
        'service_fee_paid_until' => 'datetime',
        'samples_extracted_at' => 'datetime',
        'meta' => 'array',
    ];

    public function sourceUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'source_user_id');
    }

    public function grants(): HasMany
    {
        return $this->hasMany(DoppelgangerGrant::class);
    }

    public function invocations(): HasMany
    {
        return $this->hasMany(DoppelgangerInvocation::class);
    }

    public function samples(): HasMany
    {
        return $this->hasMany(DoppelgangerSample::class);
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(DoppelgangerWorkflow::class);
    }

    public function isActive(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }
        return true;
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function daysUntilExpiry(): ?int
    {
        if (! $this->expires_at) {
            return null;
        }
        return (int) now()->diffInDays($this->expires_at, false);
    }
}
