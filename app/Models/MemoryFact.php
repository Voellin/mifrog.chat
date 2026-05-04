<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemoryFact extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'source_entry_id',
        'last_run_id',
        'category',
        'fact',
        'fact_hash',
        'priority',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceEntry(): BelongsTo
    {
        return $this->belongsTo(MemoryEntry::class, 'source_entry_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'last_run_id');
    }
}

