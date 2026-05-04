<?php

namespace App\Modules\Doppelganger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoppelgangerSample extends Model
{
    public const TYPE_VOICE = 'voice';
    public const TYPE_WORKFLOW = 'workflow';
    public const TYPE_DECISION = 'decision';
    public const TYPE_PREFERENCE = 'preference';

    public const TYPES = [
        self::TYPE_VOICE,
        self::TYPE_WORKFLOW,
        self::TYPE_DECISION,
        self::TYPE_PREFERENCE,
    ];

    protected $table = 'doppelganger_samples';

    protected $fillable = [
        'doppelganger_id',
        'sample_type',
        'context_summary',
        'content',
        'score',
        'meta',
    ];

    protected $casts = [
        'score' => 'decimal:4',
        'meta' => 'array',
    ];

    public function doppelganger(): BelongsTo
    {
        return $this->belongsTo(Doppelganger::class);
    }
}
