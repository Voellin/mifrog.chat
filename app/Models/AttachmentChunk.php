<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttachmentChunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'attachment_id',
        'user_id',
        'run_id',
        'chunk_index',
        'content',
        'summary',
        'keywords',
        'token_estimate',
        'embedding_source_text',
        'embedding_vector',
        'embedding_model',
        'meta',
    ];

    protected $casts = [
        'keywords' => 'array',
        'embedding_vector' => 'array',
        'meta' => 'array',
    ];

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}

