<?php

namespace App\Services\Feishu;

use App\Services\FeishuCliClient;
use App\Support\FeishuScopeCatalog;
use Illuminate\Support\Arr;
use Throwable;

/**
 * OAuth + user-token domain service extracted from FeishuService.
 *
 * Covers:
 * - OAuth authorize URL construction
 * - CLI Device Flow startup
 * - Auth code / refresh-token exchange with parsing
 * - User-info lookup via user_access_token
 *
 * Behavior contract (preserved verbatim from FeishuService):
 * - If feishu config is missing, all token endpoints return ok=false with
 *   a diagnostic 'error' key.
 * - CLI token context is remembered on user_info success when CLI is enabled
 *   (unchanged expiry assumptions: 1h access, 24h refresh).
 * - Scope normalization goes through FeishuScopeCatalog.
 */
class FeishuOAuthService
{
    public function __construct(
        private readonly FeishuTransport $transport,
        private readonly FeishuScopeCatalog $scopeCatalog,
        private readonly FeishuCliClient $feishuCliClient,
    ) {
    }

    public function buildOauthAuthorizeUrl(string $redirectUri, string $state, ?array $scopes = null): ?string
    {
        $config = $this->transport->readConfig();
        if (! $config['enabled']) {
            return null;
        }

        $scopeList = [];
        foreach (($scopes ?? $this->scopeCatalog->requiredOauthScopes()) as $item) {
            $normalized = $this->normalizeOauthScope((string) $item);
            if ($normalized !== '') {
                $scopeList[$normalized] = true;
            }
        }
        if ($scopeList === []) {
            foreach ($this->scopeCatalog->requiredOauthScopes() as $item) {
                $normalized = $this->normalizeOauthScope((string) $item);
                if ($normalized !== '') {
                    $scopeList[$normalized] = true;
                }
            }
        }

        $scope = implode(' ', array_keys($scopeList));
        $query = http_build_query([
            'client_id' => $config['app_id'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $scope,
            'state' => $state,
        ]);

        return 'https://accounts.feishu.cn/open-apis/authen/v1/authorize?'.$query;
    }

    /**
     * Start a CLI Device Flow authorization and return the verification URL.
     *
     * @param  string[]  $capabilities  Missing capabilities (used to determine --domain)
     * @return array{ok:bool, verification_url?:string, device_code?:string, expires_in?:int, error?:string}
     */
    public function startDeviceFlowAuth(array $capabilities = [], string $userKey = ''): array
    {
        if (! $this->feishuCliClient->isEnabled()) {
            return ['ok' => false, 'error' => 'cli_not_enabled'];
        }

        $config = $this->transport->readConfig();
        if (! $config['enabled']) {
            return ['ok' => false, 'error' => 'feishu_not_enabled'];
        }

        $domains = $this->feishuCliClient->capabilitiesToDomains($capabilities);
        if (empty($domains)) {
            $domains = ['docs', 'drive', 'im'];
        }

        return $this->feishuCliClient->initiateDeviceFlow($config, $domains, $userKey);
    }

    /**
     * @return string[]
     */
    public function requiredOauthScopes(): array
    {
        return $this->scopeCatalog->requiredOauthScopes();
    }

    /**
     * @return array<string,mixed>
     */
    public function exchangeUserAccessTokenByCode(string $code, ?string $redirectUri = null): array
    {
        $code = trim($code);
        if ($code === '') {
            return ['ok' => false, 'error' => 'empty_code'];
        }

        $config = $this->transport->readConfig();
        if (! $config['enabled']) {
            return ['ok' => false, 'error' => 'feishu_config_missing'];
        }

        $redirectUri = trim((string) $redirectUri);
        if ($redirectUri === '') {
            $redirectUri = rtrim((string) config('app.url', 'https://mifrog.chat'), '/').'/feishu/oauth/callback';
        }

        try {
            $body = $this->transport->requestJson('post', 'authen/v2/oauth/token', [
                'json' => [
                    'grant_type' => 'authorization_code',
                    'client_id' => $config['app_id'],
                    'client_secret' => $config['app_secret'],
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                ],
                'http_errors' => false,
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return $this->parseOauthTokenResponse($body, 'exchange_failed');
    }

    /**
     * @return array<string,mixed>
     */
    public function refreshUserAccessToken(string $refreshToken): array
    {
        $refreshToken = trim($refreshToken);
        if ($refreshToken === '') {
            return ['ok' => false, 'error' => 'empty_refresh_token'];
        }

        $config = $this->transport->readConfig();
        if (! $config['enabled']) {
            return ['ok' => false, 'error' => 'feishu_config_missing'];
        }

        try {
            $body = $this->transport->requestJson('post', 'authen/v2/oauth/token', [
                'json' => [
                    'grant_type' => 'refresh_token',
                    'client_id' => $config['app_id'],
                    'client_secret' => $config['app_secret'],
                    'refresh_token' => $refreshToken,
                ],
                'http_errors' => false,
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $accessToken = trim((string) Arr::get($body, 'data.access_token', Arr::get($body, 'access_token', '')));
        if ($accessToken === '') {
            $apiCode = (int) Arr::get($body, 'code', -1);
            $oauthError = trim((string) Arr::get($body, 'error', ''));
            $oauthDescription = trim((string) Arr::get($body, 'error_description', ''));
            return [
                'ok' => false,
                'code' => $apiCode,
                'oauth_error' => $oauthError,
                'error' => $oauthDescription !== '' ? $oauthDescription : trim((string) Arr::get($body, 'msg', 'refresh_failed')),
                'response' => $body,
            ];
        }

        $data = is_array(Arr::get($body, 'data')) ? (array) Arr::get($body, 'data') : $body;

        return [
            'ok' => true,
            'access_token' => $accessToken,
            'refresh_token' => trim((string) Arr::get($data, 'refresh_token', '')),
            'expires_in' => (int) Arr::get($data, 'expires_in', 0),
            'refresh_expires_in' => (int) Arr::get($data, 'refresh_expires_in', 0),
            'scope' => trim((string) Arr::get($data, 'scope', '')),
            'response' => $body,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getUserInfoByUserAccessToken(string $userAccessToken): array
    {
        $userAccessToken = trim($userAccessToken);
        if ($userAccessToken === '') {
            return ['ok' => false, 'error' => 'empty_user_access_token'];
        }

        try {
            $body = $this->transport->requestJson('get', 'authen/v1/user_info', [
                'headers' => $this->transport->authHeaders($userAccessToken),
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $apiCode = (int) Arr::get($body, 'code', -1);
        if ($apiCode !== 0) {
            return [
                'ok' => false,
                'code' => $apiCode,
                'error' => trim((string) Arr::get($body, 'msg', 'user_info_failed')),
                'response' => $body,
            ];
        }

        $data = (array) Arr::get($body, 'data', []);

        if ($this->feishuCliClient->isEnabled()) {
            $nowMs = (int) round(microtime(true) * 1000);
            $this->feishuCliClient->rememberTokenContext($userAccessToken, [
                'open_id' => trim((string) Arr::get($data, 'open_id', '')),
                'user_name' => trim((string) Arr::get($data, 'name', '')),
                'access_token' => $userAccessToken,
                'refresh_token' => '',
                'expires_at_ms' => $nowMs + 3600 * 1000,
                'refresh_expires_at_ms' => $nowMs + 86400 * 1000,
                'scope' => '',
                'granted_at_ms' => $nowMs,
            ]);
        }

        return [
            'ok' => true,
            'open_id' => trim((string) Arr::get($data, 'open_id', '')),
            'union_id' => trim((string) Arr::get($data, 'union_id', '')),
            'user_id' => trim((string) Arr::get($data, 'user_id', '')),
            'name' => trim((string) Arr::get($data, 'name', '')),
            'response' => $body,
        ];
    }

    /**
     * @param  mixed  $body
     * @return array<string,mixed>
     */
    private function parseOauthTokenResponse($body, string $fallbackError): array
    {
        if (! is_array($body)) {
            return [
                'ok' => false,
                'code' => -1,
                'error' => $fallbackError,
                'response' => $body,
            ];
        }

        $accessToken = trim((string) Arr::get($body, 'data.access_token', Arr::get($body, 'access_token', '')));
        if ($accessToken === '') {
            $apiCode = (int) Arr::get($body, 'code', -1);
            $oauthError = trim((string) Arr::get($body, 'error', ''));
            $oauthDescription = trim((string) Arr::get($body, 'error_description', ''));

            return [
                'ok' => false,
                'code' => $apiCode,
                'oauth_error' => $oauthError,
                'error' => $oauthDescription !== '' ? $oauthDescription : trim((string) Arr::get($body, 'msg', $fallbackError)),
                'response' => $body,
            ];
        }

        $data = is_array(Arr::get($body, 'data')) ? (array) Arr::get($body, 'data') : $body;

        return [
            'ok' => true,
            'access_token' => $accessToken,
            'refresh_token' => trim((string) Arr::get($data, 'refresh_token', '')),
            'expires_in' => (int) Arr::get($data, 'expires_in', 0),
            'refresh_expires_in' => (int) Arr::get($data, 'refresh_expires_in', 0),
            'scope' => trim((string) Arr::get($data, 'scope', '')),
            'response' => $body,
        ];
    }

    private function normalizeOauthScope(string $scope): string
    {
        return $this->scopeCatalog->normalizeScope($scope);
    }
}
