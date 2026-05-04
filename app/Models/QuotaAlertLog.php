<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuotaAlertLog extends Model
{
    protected $table = 'quota_alert_logs';

    protected $fillable = [
        'user_id',
        'period_key',
        'level',
        'notified_at',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
    ];
}
