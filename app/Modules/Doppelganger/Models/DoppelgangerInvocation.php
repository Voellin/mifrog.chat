<?php

namespace App\Modules\Doppelganger\Models;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoppelgangerInvocation extends Model
{
    public const LEVEL_KNOWLEDGE = 1;
    public const LEVEL_VOICE = 2;
    public const LEVEL_WORKFLOW = 3;

    protected $table = 'doppelganger_invocations';

    protected $fillable = [
        'doppelganger_id',
        'caller_user_id',
        'caller_admin_id',
        'level',
        'query',
        'response_excerpt',
        'token_input',
        'token_output',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function doppelganger(): BelongsTo
    {
        return $this->belongsTo(Doppelganger::class);
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_user_id');
    }

    public function callerAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'caller_admin_id');
    }

    public function totalTokens(): int
    {
        return (int) $this->token_input + (int) $this->token_output;
    }
}
