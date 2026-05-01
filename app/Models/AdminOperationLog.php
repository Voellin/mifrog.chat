<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminOperationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'admin_user_id',
        'admin_username',
        'action',
        'summary',
        'target_type',
        'target_id',
        'context',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }
}
