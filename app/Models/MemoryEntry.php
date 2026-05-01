<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemoryEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'run_id',
        'layer',
        'session_key',
        'source_file',
        'source_date',
        'title',
        'summary',
        'content',
        'tags',
        'keywords',
        'embedding_source_text',
        'embedding_vector',
        'embedding_model',
        'content_hash',
        'expired_at',
        'expire_reason',
    ];

    protected $casts = [
        'source_date' => 'date',
        'expired_at' => 'datetime',
        'tags' => 'array',
        'keywords' => 'array',
        'embedding_vector' => 'array',
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
