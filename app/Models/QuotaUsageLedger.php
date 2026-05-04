<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuotaUsageLedger extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'department_id',
        'run_id',
        'used_tokens',
        'period_key',
    ];
}

