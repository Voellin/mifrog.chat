<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AuditPolicy extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_DEPARTMENT = 'department';

    protected $fillable = [
        'name',
        'scope_type',
        'department_id',
        'priority',
        'input_action',
        'output_action',
        'blocked_message',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function terms(): HasMany
    {
        return $this->hasMany(AuditPolicyTerm::class, 'policy_id');
    }
}

