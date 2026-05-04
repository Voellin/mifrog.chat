<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MemorySnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'run_id',
        'memory_type',
        'summary',
        'file_path',
    ];
}

