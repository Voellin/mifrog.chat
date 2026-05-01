<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'run_id',
        'event_type',
        'message',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}

