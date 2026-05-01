<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Run extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_WAITING_AUTH = 'waiting_auth';
    public const STATUS_NEEDS_INPUT = 'needs_input';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const INTENT_CHAT = 'chat';
    public const INTENT_QUESTION = 'question';
    public const INTENT_TASK = 'task';
    public const INTERACTION_TEXT = 'text';
    public const INTERACTION_CARD = 'card';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'status',
        'model',
        'intent_type',
        'intent_confidence',
        'intent_meta',
        'interaction_mode',
        'feishu_chat_id',
        'feishu_message_id',
        'input_tokens',
        'output_tokens',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'intent_confidence' => 'float',
        'intent_meta' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(RunEvent::class);
    }

    public function auditRecords(): HasMany
    {
        return $this->hasMany(RunAuditRecord::class);
    }

    public function stateTransitions(): HasMany
    {
        return $this->hasMany(RunStateTransition::class);
    }
}
