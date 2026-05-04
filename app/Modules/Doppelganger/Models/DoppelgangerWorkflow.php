<?php

namespace App\Modules\Doppelganger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoppelgangerWorkflow extends Model
{
    public const TRIGGER_CRON = 'cron';
    public const TRIGGER_EVENT = 'event';
    public const TRIGGER_MANUAL = 'manual';

    protected $table = 'doppelganger_workflows';

    protected $fillable = [
        'doppelganger_id',
        'workflow_name',
        'trigger_type',
        'trigger_spec',
        'template_content',
        'sample_excerpt',
        'is_active',
        'last_pushed_at',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_pushed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function doppelganger(): BelongsTo
    {
        return $this->belongsTo(Doppelganger::class);
    }
}
