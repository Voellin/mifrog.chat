<?php

namespace App\Services;

use App\Models\Run;
use App\Models\UserIdentity;
use App\Support\FeishuScopeCatalog;

class FeishuTokenService
{
    public function __construct(
        private readonly FeishuService $feishuService,
        private readonly FeishuScopeCatalog $scopeCatalog,
    ) {
    }

    /**
     * Resolve user identity, ensure valid access token, check required scope.
     *
     * Returns [accessToken, identity, error].
     * On success:  ['tok_xxx', UserIdentity, null]
     * On failure:  ['', null|UserIdentity, ['status'=>'blocked', ...]]
     */
    public function resolveUserToken(Run $run, string $requiredScope, string $scopeLabel = ''): array
    {
        $identity = UserIdentity::query()
            ->where('user_id', $run->user_id)
            ->where('provider', 'feishu')
            ->first();

        if (! $identity) {
            return ['', null, [
                'status' => 'blocked',
                'message' => '未找到你的飞书授权身份，请先完成授权后再试。',
                'missing' => ['feishu.oauth.user_token'],
            ]];
        }

        $extra = is_array($identity->extra) ? $identity->extra : [];

        // CLI manages its own token lifecycle via keychain. If keychain is populated,
        // we don't need a raw PHP access token — the CLI handles refresh internally.
        $cliKeychainPopulated = (bool) ($extra['cli_keychain_populated'] ?? false);

        [$accessToken, $extra, $tokenState] = $this->ensureUserAccessToken($extra);

        if ($extra !== (array) $identity->extra) {
            $identity->extra = $extra;
            $identity->save();
        }

        // If raw token is unavailable but CLI keychain has the token, use sentinel
        if ($accessToken === '' && $cliKeychainPopulated) {
            $accessToken = '__cli_keychain__';
        }

        if ($accessToken === '') {
            return ['', $identity, [
                'status' => 'blocked',
                'message' => '还没有可用的飞书用户授权，请先重新授权后再试。',
                'missing' => ['feishu.oauth.user_token'],
                'token_state' => $tokenState,
            ]];
        }

        if ($requiredScope !== '') {
            $rawScope = (string) ($extra['user_token_scope'] ?? '');

            // If CLI keychain is populated but scope string is empty, recover from CLI auth status
            if ($rawScope === '' && $cliKeychainPopulated) {
                try {
                    $cliClient = app(\App\Services\FeishuCliClient::class);
                    $authStatus = $cliClient->readAuthStatus((string) ($identity->provider_user_id ?? ($extra['open_id'] ?? '')));
                    if (is_array($authStatus) && isset($authStatus['scope'])) {
                        $rawScope = trim((string) $authStatus['scope']);
                        // Persist so we don't have to shell out next time
                        $extra['user_token_scope'] = $rawScope;
                        $identity->extra = $extra;
                        $identity->save();
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            $scopeList = $this->scopeCatalog->parseScopeString($rawScope);
            if (! $this->scopeCatalog->hasScope($scopeList, $requiredScope)) {
                $label = $scopeLabel !== '' ? $scopeLabel : $requiredScope;
                return ['', $identity, [
                    'status' => 'blocked',
                    'message' => '当前授权缺少"' . $label . '"权限，请按提示重新授权后我会自动继续。',
                    'missing' => ['feishu.scope.' . $requiredScope],
                ]];
            }
        }

        return [$accessToken, $identity, null];
    }

    /**
     * Core token refresh logic — single source of truth.
     *
     * @return array{0: string, 1: array, 2: string}  [accessToken, updatedExtra, state]
     */
    public function ensureUserAccessToken(array $extra): array
    {
        $accessToken = trim((string) ($extra['user_access_token'] ?? ''));
        $refreshToken = trim((string) ($extra['user_refresh_token'] ?? ''));
        $expiresAt = (int) ($extra['user_token_expires_at'] ?? 0);

        if ($accessToken !== '' && ($expiresAt === 0 || $expiresAt > (time() + 60))) {
            return [$accessToken, $extra, 'cached'];
        }

        if ($refreshToken === '') {
            return ['', $extra, 'missing'];
        }

        $refreshed = $this->feishuService->refreshUserAccessToken($refreshToken);
        if (($refreshed['ok'] ?? false) !== true) {
            return ['', $extra, 'refresh_failed'];
        }

        $nowTs = time();
        $extra['user_access_token'] = (string) ($refreshed['access_token'] ?? '');
        $extra['user_refresh_token'] = (string) ($refreshed['refresh_token'] ?? $refreshToken);
        $extra['user_token_expires_at'] = $nowTs + (int) ($refreshed['expires_in'] ?? 0) - 120;
        $extra['user_refresh_expires_at'] = $nowTs + (int) ($refreshed['refresh_expires_in'] ?? 0) - 300;
        $extra['user_token_scope'] = (string) ($refreshed['scope'] ?? ($extra['user_token_scope'] ?? ''));

        return [trim((string) ($extra['user_access_token'] ?? '')), $extra, 'refreshed'];
    }
}
