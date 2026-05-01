<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuotaPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'period',
        'token_limit',
        'department_id',
        'user_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}

