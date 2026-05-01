<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attachment extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_DOWNLOADING = 'downloading';
    public const STATUS_PARSING = 'parsing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'conversation_id',
        'message_id',
        'run_id',
        'source_channel',
        'source_message_id',
        'attachment_type',
        'file_key',
        'file_name',
        'file_ext',
        'mime_type',
        'file_size',
        'storage_path',
        'content_hash',
        'parse_status',
        'parse_error',
        'parsed_at',
        'meta',
    ];

    protected $casts = [
        'parsed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(AttachmentChunk::class);
    }
}

