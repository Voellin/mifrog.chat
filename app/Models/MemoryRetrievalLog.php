<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemoryRetrievalLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'run_id',
        'query_text',
        'retrieved_l3_fact_ids',
        'retrieved_l2_entry_ids',
        'meta',
    ];

    protected $casts = [
        'retrieved_l3_fact_ids' => 'array',
        'retrieved_l2_entry_ids' => 'array',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}

