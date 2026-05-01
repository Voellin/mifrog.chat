<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProactiveActivitySnapshot extends Model
{
    protected $fillable = [
        'user_id',
        'scan_window_minutes',
        'calendar_data',
        'messages_data',
        'documents_data',
        'meetings_data',
        'calendar_count',
        'messages_count',
        'documents_count',
        'meetings_count',
        'has_activity',
        'llm_should_notify',
        'llm_reasoning',
        'llm_message',
        'activity_fingerprint',
        'notification_sent',
        'notification_sent_at',
        'notification_message_hash',
        'notification_channel',
        'skip_reason',
        'notification_error',
        'scanned_at',
    ];

    protected $casts = [
        'calendar_data'  => 'array',
        'messages_data'  => 'array',
        'documents_data' => 'array',
        'meetings_data'  => 'array',
        'has_activity'   => 'boolean',
        'llm_should_notify' => 'boolean',
        'notification_sent' => 'boolean',
        'notification_sent_at' => 'datetime',
        'scanned_at'     => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
