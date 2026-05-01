<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditPolicyTerm extends Model
{
    use HasFactory;

    protected $fillable = [
        'policy_id',
        'term',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AuditPolicy::class, 'policy_id');
    }
}

