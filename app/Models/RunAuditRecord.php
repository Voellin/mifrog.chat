<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunAuditRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'run_id',
        'conversation_id',
        'user_id',
        'stage',
        'hit',
        'matched_terms',
        'matched_policy_ids',
        'matched_policy_names',
        'action',
        'decision',
        'content_excerpt',
        'meta',
    ];

    protected $casts = [
        'hit' => 'boolean',
        'matched_terms' => 'array',
        'matched_policy_ids' => 'array',
        'matched_policy_names' => 'array',
        'meta' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
