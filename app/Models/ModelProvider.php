<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_key',
        'name',
        'base_url',
        'default_model',
        'models',
        'defaults',
        'is_active',
        'sort_order',
        'last_test_status',
        'last_test_at',
        'last_test_message',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'models' => 'array',
        'defaults' => 'array',
        'sort_order' => 'integer',
        'last_test_at' => 'datetime',
    ];

    public function keys(): HasMany
    {
        return $this->hasMany(ModelKey::class, 'provider_id');
    }
}

