<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class RunAccessTokenService
{
    private const CACHE_PREFIX = 'run_stream_access_token_';
    private const TTL_SECONDS = 86400;

    public function issue(int $runId): string
    {
        $token = Str::random(48);
        Cache::put($this->cacheKey($runId), $token, now()->addSeconds(self::TTL_SECONDS));

        return $token;
    }

    public function validate(int $runId, string $provided): bool
    {
        $provided = trim($provided);
        if ($provided === '') {
            return false;
        }

        $expected = (string) Cache::get($this->cacheKey($runId), '');
        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    private function cacheKey(int $runId): string
    {
        return self::CACHE_PREFIX.$runId;
    }
}

