<?php

namespace App\Services\Feishu;

use App\Models\Setting;
use App\Services\FeishuCliClient;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Low-level Feishu transport layer extracted from FeishuService.
 *
 * Responsibilities:
 * - Read Feishu app credentials from Settings (readConfig)
 * - Obtain tenant / app access tokens (with CLI short-circuit + HTTP fallback)
 * - Perform Guzzle-based HTTP requests against open.feishu.cn/open-apis/,
 *   with automatic CLI routing for bot API calls
 * - Low-level card / message send/update/delete primitives shared across
 *   domain services (OAuth, Push, OrgSync, Resource) AND the FeishuService
 *   run-card surface
 *
 * Behavior contract (preserved verbatim from the previous inline implementation):
 * - CLI is consulted only for bot API calls. User-token calls always go via Guzzle.
 * - CLI errors fall back to HTTP with a real tenant token acquired via HTTP.
 * - Token caches: tenant / app token caches keyed by md5(appId|appSecret),
 *   TTL = response.expire − 120s (min 60).
 */
class FeishuTransport
{
    public const FEISHU_OPEN_API_BASE = 'https://open.feishu.cn/open-apis/';
    public const CLI_BOT_TOKEN = '__MIFROG_CLI_BOT__';

    private const TOKEN_CACHE_PREFIX = 'feishu_tenant_token_';
    private const APP_TOKEN_CACHE_PREFIX = 'feishu_app_token_';

    public function __construct(
        private readonly FeishuCliClient $feishuCliClient,
    ) {
    }

    /**
     * @return array{app_id:string, app_secret:string, enabled:bool}
     */
    public function readConfig(): array
    {
        try {
            $config = Setting::read('feishu', []);
        } catch (Throwable $e) {
            Log::warning('feishu.read_config_failed', [
                'message' => $e->getMessage(),
            ]);
            $config = [];
        }

        $appId = trim((string) Arr::get($config, 'app_id', env('FEISHU_APP_ID', '')));
        $appSecret = trim((string) Arr::get($config, 'app_secret', env('FEISHU_APP_SECRET', '')));

        return [
            'app_id' => $appId,
            'app_secret' => $appSecret,
            'enabled' => $appId !== '' && $appSecret !== '',
        ];
    }

    public function appAccessToken(string $appId, string $appSecret): ?string
    {
        if ($this->feishuCliClient->isEnabled()) {
            return self::CLI_BOT_TOKEN;
        }

        $cacheKey = self::APP_TOKEN_CACHE_PREFIX.md5($appId.'|'.$appSecret);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $body = $this->requestJson('post', 'auth/v3/app_access_token/internal', [
                'json' => [
                    'app_id' => $appId,
                    'app_secret' => $appSecret,
                ],
            ]);
        } catch (Throwable $e) {
            Log::warning('feishu.app_token.request_failed', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $token = trim((string) Arr::get($body, 'app_access_token', ''));
        $expire = (int) Arr::get($body, 'expire', 7200);

        if ($token === '') {
            Log::warning('feishu.app_token.empty', [
                'response' => $body,
            ]);

            return null;
        }

        Cache::put($cacheKey, $token, now()->addSeconds(max(60, $expire - 120)));

        return $token;
    }

    public function tenantToken(string $appId, string $appSecret): ?string
    {
        if ($this->feishuCliClient->isEnabled()) {
            return self::CLI_BOT_TOKEN;
        }

        $cacheKey = self::TOKEN_CACHE_PREFIX.md5($appId.'|'.$appSecret);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $body = $this->requestJson('post', 'auth/v3/tenant_access_token/internal', [
                'json' => [
                    'app_id' => $appId,
                    'app_secret' => $appSecret,
                ],
            ]);
        } catch (Throwable $e) {
            Log::warning('feishu.token.request_failed', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $token = trim((string) Arr::get($body, 'tenant_access_token', ''));
        $expire = (int) Arr::get($body, 'expire', 7200);

        if ($token === '') {
            Log::warning('feishu.token.empty', [
                'response' => $body,
            ]);

            return null;
        }

        Cache::put($cacheKey, $token, now()->addSeconds(max(60, $expire - 120)));

        return $token;
    }

    /**
     * Obtain a tenant token directly via HTTP, bypassing CLI.
     * Used as a fallback when CLI bot API call fails.
     */
    public function tenantTokenViaHttp(string $appId, string $appSecret): ?string
    {
        $cacheKey = 'feishu_tenant_http_fallback_' . md5($appId . '|' . $appSecret);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->client()->request('post', 'auth/v3/tenant_access_token/internal', [
                'json' => [
                    'app_id' => $appId,
                    'app_secret' => $appSecret,
                ],
            ]);
            $body = json_decode((string) $response->getBody(), true);
            $token = trim((string) Arr::get($body, 'tenant_access_token', ''));
            $expire = (int) Arr::get($body, 'expire', 7200);

            if ($token !== '') {
                Cache::put($cacheKey, $token, now()->addSeconds(max(60, $expire - 120)));
                return $token;
            }
        } catch (\Throwable $e) {
            Log::warning('feishu.tenant_token_http_fallback_failed', [
                'message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function requestJson(string $method, string $uri, array $options = []): array
    {
        $bearer = $this->extractBearerToken((array) ($options['headers'] ?? []));
        $isUserCall = $bearer !== null && $bearer !== '' && $bearer !== self::CLI_BOT_TOKEN;

        // User API calls always go through Guzzle HTTP (reliable bearer token auth).
        // CLI is used only for bot API calls (handles token lifecycle internally).
        if (! $isUserCall && $this->feishuCliClient->isEnabled() && $this->feishuCliClient->isAvailable()) {
            $config = $this->readConfig();
            if ($config['enabled']) {
                try {
                    return $this->feishuCliClient->callBotApi($config, $method, $uri, [
                        'query' => (array) ($options['query'] ?? []),
                        'json' => $options['json'] ?? null,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('feishu.cli.bot_fallback_to_http', [
                        'method' => $method,
                        'uri' => $uri,
                        'error' => $e->getMessage(),
                    ]);

                    // Fallback: obtain a real tenant token and proceed via Guzzle
                    $realToken = $this->tenantTokenViaHttp($config['app_id'], $config['app_secret']);
                    if ($realToken !== null) {
                        $options['headers'] = array_merge(
                            (array) ($options['headers'] ?? []),
                            $this->authHeaders($realToken)
                        );
                    }
                }
            }
        }

        $response = $this->client()->request($method, $uri, $options);
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function client(): Client
    {
        return new Client([
            'base_uri' => self::FEISHU_OPEN_API_BASE,
            'timeout' => 20,
        ]);
    }

    /**
     * @return array<string,string>
     */
    public function authHeaders(string $token): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * @param  array<string,mixed>  $headers
     */
    public function extractBearerToken(array $headers): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== 'authorization') {
                continue;
            }
            $raw = trim((string) $value);
            if ($raw === '') {
                return null;
            }
            if (str_starts_with(strtolower($raw), 'bearer ')) {
                return trim(substr($raw, 7));
            }

            return $raw;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $card
     */
    public function encodeCard(array $card): string
    {
        $encoded = json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '{}';
    }

    public function sendTextMessage(string $token, string $chatId, string $text): bool
    {
        try {
            $body = $this->requestJson('post', 'im/v1/messages?receive_id_type=chat_id', [
                'headers' => $this->authHeaders($token),
                'json' => [
                    'receive_id' => $chatId,
                    'msg_type' => 'text',
                    'content' => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
                ],
            ]);

            if ((int) Arr::get($body, 'code', -1) !== 0) {
                Log::warning('feishu.send_text.failed', [
                    'chat_id' => $chatId,
                    'response' => $body,
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('feishu.send_text.exception', [
                'chat_id' => $chatId,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<string,mixed>  $card
     */
    public function sendInteractiveCard(string $token, string $chatId, array $card): ?string
    {
        try {
            $body = $this->requestJson('post', 'im/v1/messages?receive_id_type=chat_id', [
                'headers' => $this->authHeaders($token),
                'json' => [
                    'receive_id' => $chatId,
                    'msg_type' => 'interactive',
                    'content' => $this->encodeCard($card),
                ],
            ]);

            if ((int) Arr::get($body, 'code', -1) !== 0) {
                Log::warning('feishu.card.send_failed', [
                    'chat_id' => $chatId,
                    'response' => $body,
                ]);

                return null;
            }

            $messageId = trim((string) Arr::get($body, 'data.message_id', ''));

            return $messageId !== '' ? $messageId : null;
        } catch (Throwable $e) {
            Log::warning('feishu.card.send_exception', [
                'chat_id' => $chatId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $card
     */
    public function updateInteractiveCard(string $token, string $messageId, array $card): bool
    {
        try {
            $body = $this->requestJson('patch', 'im/v1/messages/'.$messageId, [
                'headers' => $this->authHeaders($token),
                'json' => [
                    'msg_type' => 'interactive',
                    'content' => $this->encodeCard($card),
                ],
            ]);

            if ((int) Arr::get($body, 'code', -1) !== 0) {
                Log::warning('feishu.card.update_failed', [
                    'message_id' => $messageId,
                    'response' => $body,
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('feishu.card.update_exception', [
                'message_id' => $messageId,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function deleteMessage(string $messageId): bool
    {
        $messageId = trim($messageId);
        if ($messageId === '') {
            return false;
        }

        $config = $this->readConfig();
        if (! $config['enabled']) {
            return false;
        }

        $token = $this->tenantToken($config['app_id'], $config['app_secret']);
        if (! $token) {
            return false;
        }

        try {
            $body = $this->requestJson('delete', 'im/v1/messages/'.$messageId, [
                'headers' => $this->authHeaders($token),
            ]);
            if ((int) Arr::get($body, 'code', -1) !== 0) {
                Log::warning('feishu.message.delete_failed', [
                    'message_id' => $messageId,
                    'response' => $body,
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('feishu.message.delete_exception', [
                'message_id' => $messageId,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
