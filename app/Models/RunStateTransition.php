<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunStateTransition extends Model
{
    use HasFactory;

    protected $fillable = [
        'run_id',
        'from_status',
        'to_status',
        'reason',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}

