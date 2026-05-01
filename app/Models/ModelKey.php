<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_id',
        'name',
        'api_key',
        'is_active',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
        'is_active' => 'boolean',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ModelProvider::class, 'provider_id');
    }
}
