<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'setting_key',
        'setting_value',
    ];

    /**
     * Sensitive keys whose values are stored encrypted at rest.
     * Non-sensitive keys use plain JSON for easy debugging.
     */
    private const ENCRYPTED_KEYS = [
        'feishu',
        'llm_keys',
        'admin_credentials',
    ];

    // Intentionally no $casts for setting_value.
    // The custom accessor/mutator below handle JSON encoding + at-rest encryption
    // for ENCRYPTED_KEYS. Adding an 'array' cast here conflicts with the mutator:
    // Eloquent's originalIsEquivalent() would json_decode both old and new encrypted
    // blobs to null, judge them equal, and silently skip the UPDATE. (2026-04-21 fix.)

    protected static function booted(): void
    {
        static::saving(function (Setting $setting) {
            // Encryption is handled transparently via the accessor/mutator below.
        });
    }

    public function getSettingValueAttribute($value)
    {
        if ($value === null) {
            return null;
        }

        if (in_array($this->setting_key, self::ENCRYPTED_KEYS, true)) {
            try {
                $decrypted = decrypt($value);
                return is_array($decrypted) ? $decrypted : json_decode($decrypted, true);
            } catch (\Throwable) {
                // Fallback: value was stored before encryption was enabled
                $decoded = json_decode($value, true);
                return is_array($decoded) ? $decoded : $value;
            }
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $value;
    }

    public function setSettingValueAttribute($value): void
    {
        if (in_array($this->setting_key, self::ENCRYPTED_KEYS, true)) {
            $this->attributes['setting_value'] = encrypt(is_array($value) ? json_encode($value) : $value);
        } else {
            $this->attributes['setting_value'] = is_array($value) ? json_encode($value) : $value;
        }
    }

    public static function read(string $key, mixed $default = null): mixed
    {
        $row = self::query()->where('setting_key', $key)->first();
        if (! $row) {
            return $default;
        }

        return $row->setting_value ?? $default;
    }

    public static function write(string $key, mixed $value): void
    {
        self::query()->updateOrCreate(
            ['setting_key' => $key],
            ['setting_value' => $value]
        );
    }
}
