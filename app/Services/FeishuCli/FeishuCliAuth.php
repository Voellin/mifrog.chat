<?php

namespace App\Services\FeishuCli;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Auth / token context layer of FeishuCliClient (P1.4 Strangler split).
 * Owns: token→user_context cache, CLI auth status verification,
 * user scope key resolution.
 */
class FeishuCliAuth
{
    private const TOKEN_MAP_CACHE_PREFIX = 'feishu_cli_token_map_';
    private const TOKEN_MAP_TTL_SECONDS = 86400;

    public function __construct(
        private readonly FeishuCliBinary $binary,
        private readonly FeishuCliHome $home,
    ) {}

    /**
     * @param  array<string,mixed>  $context
     */
    public function rememberTokenContext(string $accessToken, array $context): void
    {
        $token = trim($accessToken);
        if ($token === '') {
            return;
        }

        $cacheKey = $this->tokenMapCacheKey($token);
        Cache::put($cacheKey, $context, now()->addSeconds(self::TOKEN_MAP_TTL_SECONDS));
    }

    /**
     * @param  array<string,mixed>  $feishuConfig
     */
    public function hasVerifiedUserAuth(array $feishuConfig, string $userKey): bool
    {
        $normalizedUserKey = $this->home->normalizeUserScopeKey($userKey);
        if ($normalizedUserKey === '') {
            return false;
        }

        try {
            $this->home->ensureCliHome($feishuConfig, $normalizedUserKey);
        } catch (Throwable) {
            return false;
        }

        // Two-shot read: first attempt, then one retry after 500ms if the
        // first attempt returned null / invalid. Guards against transient CLI
        // output right after a token refresh where the state hasn't fully
        // settled (observed 2026-04-21 daily-summary: CLI returned status=200
        // in the auth log but PHP saw an unverified JSON on the first shot).
        if ($this->evaluateAuthStatus($this->readAuthStatus($normalizedUserKey), $normalizedUserKey)) {
            return true;
        }

        usleep(500_000);
        return $this->evaluateAuthStatus($this->readAuthStatus($normalizedUserKey), $normalizedUserKey);
    }

    /**
     * @param  array<string,mixed>|null  $status
     */
    private function evaluateAuthStatus(?array $status, string $normalizedUserKey): bool
    {
        if (! is_array($status)) {
            return false;
        }

        $statusUser = $this->home->normalizeUserScopeKey((string) ($status['userOpenId'] ?? ''));
        $verified = (bool) ($status['verified'] ?? false);
        $tokenStatus = strtolower(trim((string) ($status['tokenStatus'] ?? '')));

        return $verified
            && $statusUser === $normalizedUserKey
            && in_array($tokenStatus, ['', 'valid'], true);
    }

    public function resolveUserScopeKey(string $accessToken, string $userKey = ''): string
    {
        $normalizedUserKey = $this->home->normalizeUserScopeKey($userKey);
        if ($normalizedUserKey !== '') {
            return $normalizedUserKey;
        }

        $token = trim($accessToken);
        if ($token === '' || $token === '__cli_keychain__') {
            return '';
        }

        $context = Cache::get($this->tokenMapCacheKey($token));
        if (! is_array($context)) {
            return '';
        }

        return $this->home->normalizeUserScopeKey((string) ($context['open_id'] ?? ''));
    }

    // ─── Token context resolution ────────────────────────────────

    private function tokenMapCacheKey(string $accessToken): string
    {
        return self::TOKEN_MAP_CACHE_PREFIX . sha1($accessToken);
    }

    /**
     * Read the current CLI auth status (scope, user, expiry).
     *
     * @return array<string,mixed>|null  Parsed JSON from `lark-cli auth status`, or null on failure.
     */
    public function readAuthStatus(string $userKey = ''): ?array
    {
        $cliHome = $this->home->normalizeUserScopeKey($userKey) !== ''
            ? $this->home->userCliHomePath($userKey)
            : $this->home->cliHomePath();
        return $this->home->readAuthStatusFromHome($cliHome);
    }
}
