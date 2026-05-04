<?php

namespace App\Jobs;

use App\Models\Run;
use App\Models\UserIdentity;
use App\Services\FeishuCliClient;
use App\Services\FeishuService;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Poll Feishu device flow until the user completes authorization.
 *
 * Primary: Uses lark-cli auth login --device-code to complete the flow.
 * This ensures the CLI stores the user token in its own keychain, which is
 * required for subsequent --as user API calls. Each attempt has a 20s timeout.
 *
 * Fallback: If CLI polling fails, tries HTTP API polling.
 */
class PollCliDeviceFlowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 45;
    public int $tries = 40;
    public array $backoff = [3, 5, 5, 10, 10, 10, 15, 15, 15, 15];

    public function __construct(
        private int $runId,
        private string $deviceCode,
        private string $openId,
        private array $requestedScopes = [],
        private ?string $feishuChatId = null,
    ) {}

    public function handle(
        FeishuCliClient $cliClient,
        FeishuService $feishuService,
    ): void {
        Log::info('[PollCliDeviceFlow] Polling', [
            'run_id' => $this->runId,
            'open_id' => $this->openId,
            'attempt' => $this->attempts(),
        ]);

        // ── Strategy: CLI-first, HTTP-fallback ──
        // 1. Try CLI auth login (populates keychain for future --as user calls)
        // 2. If CLI succeeds, also try HTTP to get raw token for UserIdentity
        // 3. If CLI is not available, fall back to HTTP-only polling

        $accessToken = '';
        $refreshToken = '';
        $expiresIn = 7200;
        $refreshExpiresIn = 2592000;
        $scopeString = '';
        $cliAuthOk = false;

        // ── Step 1: Try CLI-based auth (primary — populates keychain) ──
        if ($cliClient->isEnabled() && $cliClient->isAvailable()) {
            $cliResult = $cliClient->pollDeviceFlowCompletion($this->deviceCode, 20, $this->openId);

            if (($cliResult['ok'] ?? false) === true) {
                $cliAuthOk = true;
                Log::info('[PollCliDeviceFlow] CLI auth succeeded — keychain populated', [
                    'run_id' => $this->runId,
                    'cli_output_keys' => array_keys($cliResult['cli_output'] ?? []),
                ]);

                // Try to extract token info from CLI output
                $cliOutput = $cliResult['cli_output'] ?? [];
                $accessToken = trim((string) ($cliOutput['access_token'] ?? ''));
                $refreshToken = trim((string) ($cliOutput['refresh_token'] ?? ''));
                $scopeString = trim((string) ($cliOutput['scope'] ?? ''));
                if (isset($cliOutput['expires_in'])) {
                    $expiresIn = (int) $cliOutput['expires_in'];
                }

                // CLI output often lacks token details — read from `auth status` as fallback
                if ($scopeString === '') {
                    try {
                        $statusResult = $cliClient->readAuthStatus($this->openId);
                        if (is_array($statusResult)) {
                            $scopeString = trim((string) ($statusResult['scope'] ?? ''));
                            if ($accessToken === '' && isset($statusResult['expiresAt'])) {
                                $expiresIn = max(0, (int) strtotime($statusResult['expiresAt']) - time());
                            }
                            Log::info('[PollCliDeviceFlow] Scope recovered from CLI auth status', [
                                'scope_len' => strlen($scopeString),
                                'user' => $statusResult['userName'] ?? 'unknown',
                            ]);
                        }
                    } catch (Throwable $e) {
                        Log::warning('[PollCliDeviceFlow] Failed to read CLI auth status: ' . $e->getMessage());
                    }
                }
            } else {
                $timedOut = $cliResult['timed_out'] ?? false;
                $error = $cliResult['error'] ?? 'unknown';

                if ($timedOut) {
                    Log::info('[PollCliDeviceFlow] CLI poll timed out (user not yet authorized), will retry', [
                        'run_id' => $this->runId,
                        'attempt' => $this->attempts(),
                    ]);
                    if ($this->attempts() >= $this->tries) {
                        $this->handleAuthorizationTimeout($feishuService);
                        return;
                    }
                    $this->release($this->resolveBackoffSeconds());
                    return;
                }

                // Check if permanent failure
                $isPending = str_contains(strtolower($error), 'pending') || str_contains(strtolower($error), 'slow_down');
                if ($isPending) {
                    if ($this->attempts() >= $this->tries) {
                        $this->handleAuthorizationTimeout($feishuService);
                        return;
                    }
                    $this->release($this->resolveBackoffSeconds());
                    return;
                }

                Log::warning('[PollCliDeviceFlow] CLI auth failed, trying HTTP fallback', [
                    'run_id' => $this->runId,
                    'error' => $error,
                ]);
            }
        }

        // ── Step 2: HTTP poll for raw token (if CLI didn't give us one, or as fallback) ──
        if ($accessToken === '') {
            $tokenResult = $this->pollTokenEndpoint();

            if (($tokenResult['ok'] ?? false) !== true) {
                $errorType = trim((string) ($tokenResult['error_type'] ?? ''));

                if ($errorType === 'authorization_pending' || $errorType === 'slow_down') {
                    if ($cliAuthOk) {
                        // CLI succeeded but HTTP says pending/slow_down — wait and retry once
                        // The device code was consumed by CLI; HTTP may lag behind.
                        Log::info('[PollCliDeviceFlow] HTTP pending but CLI succeeded, retrying after delay');
                        sleep(5);
                        $retryResult = $this->pollTokenEndpoint();
                        if (($retryResult['ok'] ?? false) === true) {
                            $accessToken = trim((string) ($retryResult['access_token'] ?? ''));
                            $refreshToken = trim((string) ($retryResult['refresh_token'] ?? ''));
                            $expiresIn = (int) ($retryResult['expires_in'] ?? 7200);
                            $refreshExpiresIn = (int) ($retryResult['refresh_expires_in'] ?? 2592000);
                            $scopeString = trim((string) ($retryResult['scope'] ?? $scopeString));
                            Log::info('[PollCliDeviceFlow] HTTP retry succeeded, raw token obtained');
                        } else {
                            Log::info('[PollCliDeviceFlow] HTTP retry still pending, proceeding with CLI keychain only');
                        }
                    } else {
                        if ($this->attempts() >= $this->tries) {
                            $this->handleAuthorizationTimeout($feishuService);
                            return;
                        }
                        $this->release($this->resolveBackoffSeconds());
                        return;
                    }
                } elseif (! $cliAuthOk) {
                    // Both CLI and HTTP failed permanently
                    Log::warning('[PollCliDeviceFlow] Permanent failure', [
                        'run_id' => $this->runId,
                        'error_type' => $errorType,
                    ]);
                    $this->handleAuthorizationFailure($feishuService, '授权失败，请重新发起授权后再试。');
                    return;
                }
                // If CLI succeeded but HTTP failed (device code consumed), that's OK
            } else {
                $accessToken = trim((string) ($tokenResult['access_token'] ?? ''));
                $refreshToken = trim((string) ($tokenResult['refresh_token'] ?? ''));
                $expiresIn = (int) ($tokenResult['expires_in'] ?? 7200);
                $refreshExpiresIn = (int) ($tokenResult['refresh_expires_in'] ?? 2592000);
                $scopeString = trim((string) ($tokenResult['scope'] ?? ''));
            }
        }

        if (! $cliAuthOk && $accessToken === '') {
            Log::warning('[PollCliDeviceFlow] No token obtained from either CLI or HTTP');
            $this->handleAuthorizationFailure($feishuService, '授权失败，请重新发起授权后再试。');
            return;
        }

        Log::info('[PollCliDeviceFlow] Auth completed', [
            'run_id' => $this->runId,
            'has_access_token' => $accessToken !== '',
            'cli_auth_ok' => $cliAuthOk,
            'scope' => $scopeString,
        ]);

        // Get user info from the access token (if we have one)
        $authedOpenId = $this->openId;
        if ($accessToken !== '') {
            try {
                $userInfo = $feishuService->getUserInfoByUserAccessToken($accessToken);
                if (($userInfo['ok'] ?? false) === true) {
                    $authedOpenId = trim((string) ($userInfo['open_id'] ?? $this->openId));
                }
            } catch (Throwable $e) {
                Log::warning('[PollCliDeviceFlow] Failed to get user info: ' . $e->getMessage());
            }
        }

        $lookupOpenId = $authedOpenId !== '' ? $authedOpenId : $this->openId;
        if ($lookupOpenId === '') {
            Log::warning('[PollCliDeviceFlow] No open_id available');
            return;
        }

        // Save to UserIdentity
        $identity = UserIdentity::query()
            ->where('provider', 'feishu')
            ->where('provider_user_id', $lookupOpenId)
            ->first();

        if (! $identity) {
            Log::warning('[PollCliDeviceFlow] UserIdentity not found', ['open_id' => $lookupOpenId]);
            return;
        }

        $nowTs = time();
        $extra = is_array($identity->extra) ? $identity->extra : [];
        if ($accessToken !== '') {
            $extra['user_access_token'] = $accessToken;
            $extra['user_refresh_token'] = $refreshToken;
            $extra['user_token_expires_at'] = $expiresIn > 0 ? ($nowTs + $expiresIn - 120) : null;
            $extra['user_refresh_expires_at'] = $refreshExpiresIn > 0 ? ($nowTs + $refreshExpiresIn - 300) : null;
        }
        if ($scopeString !== '') {
            $extra['user_token_scope'] = $scopeString;
        }
        $extra['user_token_scope_missing'] = [];
        $extra['user_token_authed_at'] = $nowTs;
        $extra['user_token_via'] = $cliAuthOk ? 'cli_device_flow_native' : 'cli_device_flow_http';
        $extra['cli_keychain_populated'] = $cliAuthOk;
        if ($authedOpenId !== '') {
            $extra['user_token_open_id'] = $authedOpenId;
        }
        if (! isset($extra['opportunity_last_scan_at'])) {
            $extra['opportunity_last_scan_at'] = max(0, $nowTs - 60);
        }

        $identity->extra = $extra;
        $identity->save();

        Log::info('[PollCliDeviceFlow] Token saved to UserIdentity', [
            'run_id' => $this->runId,
            'open_id' => $lookupOpenId,
            'scope' => $scopeString,
            'cli_keychain' => $cliAuthOk,
        ]);

        // Register in CLI token context
        if ($accessToken !== '' && $cliClient->isEnabled()) {
            $cliClient->rememberTokenContext($accessToken, [
                'open_id' => $lookupOpenId,
                'user_name' => (string) ($identity->user?->name ?? $lookupOpenId),
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at_ms' => ($nowTs + max(0, $expiresIn)) * 1000,
                'refresh_expires_at_ms' => ($nowTs + max(0, $refreshExpiresIn)) * 1000,
                'scope' => $scopeString,
                'granted_at_ms' => $nowTs * 1000,
            ]);
        }

        // Notify user and auto-resume the pending run
        $this->notifyAndResume($identity, $feishuService);
    }

    /**
     * Call Feishu token endpoint directly via HTTP.
     * Returns immediately — no blocking, no CLI process.
     *
     * @return array{ok:bool, access_token?:string, refresh_token?:string, expires_in?:int, scope?:string, error_type?:string, error?:string}
     */
    private function pollTokenEndpoint(): array
    {
        // Read app credentials from config/env
        $appId = trim((string) (config('feishu_cli.app_id') ?: env('FEISHU_APP_ID', '')));
        $appSecret = trim((string) (config('feishu_cli.app_secret') ?: env('FEISHU_APP_SECRET', '')));

        // Fallback: read from the CLI config file directly
        if ($appId === '' || $appSecret === '') {
            $configPath = storage_path('app/feishu_cli/bot/config.json');
            if (file_exists($configPath)) {
                $config = json_decode(file_get_contents($configPath), true);
                $app = $config['apps'][0] ?? [];
                $appId = $appId ?: trim((string) ($app['appId'] ?? ''));
                $appSecret = $appSecret ?: trim((string) ($app['appSecret'] ?? ''));
            }
        }

        if ($appId === '' || $appSecret === '') {
            return ['ok' => false, 'error_type' => 'config_error', 'error' => 'missing app credentials'];
        }

        try {
            $client = new Client(['timeout' => 10, 'connect_timeout' => 5]);
            $response = $client->post('https://open.feishu.cn/open-apis/authen/v2/oauth/token', [
                'json' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
                    'device_code' => $this->deviceCode,
                    'client_id' => $appId,
                    'client_secret' => $appSecret,
                ],
                'http_errors' => false,
            ]);

            $body = json_decode($response->getBody()->getContents(), true) ?? [];
            $statusCode = $response->getStatusCode();

            Log::debug('[PollCliDeviceFlow] Token endpoint response', [
                'status' => $statusCode,
                'body_keys' => array_keys($body),
                'error' => $body['error'] ?? null,
            ]);

            // Success: got a token
            if ($statusCode === 200 && isset($body['access_token'])) {
                return array_merge($body, ['ok' => true]);
            }

            // Pending or error
            $error = trim((string) ($body['error'] ?? ''));
            $errorDesc = trim((string) ($body['error_description'] ?? $body['message'] ?? ''));

            return [
                'ok' => false,
                'error_type' => $error ?: 'http_'.$statusCode,
                'error' => $errorDesc ?: 'token_exchange_failed',
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error_type' => 'network_error',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function notifyAndResume(UserIdentity $identity, FeishuService $feishuService): void
    {
        $user = $identity->user;
        if (! $user) {
            return;
        }

        // Invalidate the auth-state cache so the resumed run's first LLM turn
        // probes UserIdentity.extra fresh and sees the keychain/token we just
        // wrote, instead of a 30-second-old false.
        Cache::forget('mifrog:auth_state:user:' . $user->id);

        $authCardMsgId = Cache::pull('feishu_auth_card_msgid_user_' . $user->id) ?: '';

        $pending = Cache::pull('feishu_oauth_resume_user_' . $user->id);
        if (is_array($pending)) {
            $content = trim((string) ($pending['content'] ?? ''));
            $channelConversationId = trim((string) ($pending['channel_conversation_id'] ?? ''));
            $feishuChatId = trim((string) ($pending['feishu_chat_id'] ?? ''));
            $primaryCardMsgId = trim((string) ($pending['primary_card_message_id'] ?? ''));

            if ($content !== '') {
                try {
                    $runFactory = app(\App\Services\RunFactoryService::class);
                    $run = $runFactory->createRun($user, $content, [
                        'channel' => 'feishu',
                        'channel_conversation_id' => $channelConversationId,
                        'feishu_chat_id' => $feishuChatId,
                        'source_message_id' => '',
                        'oauth_resumed' => true,
                    ]);

                    // Keep updating the first task card after authorization.
                    if ($primaryCardMsgId !== '' && $run) {
                        $run->feishu_message_id = $primaryCardMsgId;
                        $run->save();
                        Log::info('[PollCliDeviceFlow] Resumed run reuses original card', [
                            'new_run_id' => $run->id,
                            'original_card_msgid' => $primaryCardMsgId,
                        ]);
                    }

                    Log::info('[PollCliDeviceFlow] Resumed run', ['new_run_id' => $run->id]);
                } catch (Throwable $e) {
                    Log::warning('[PollCliDeviceFlow] Failed to resume run: ' . $e->getMessage());
                }

                if ($authCardMsgId !== '') {
                    $feishuService->dismissAuthorizationCard($authCardMsgId, true, '授权成功，任务已回到原任务卡片继续执行。');
                }

                // Don't send separate notification — the card update from the resumed run is enough
                return;
            }
        }

        if ($authCardMsgId !== '') {
            $feishuService->dismissAuthorizationCard($authCardMsgId, true, '授权成功。');
        }

        // Only send text notification if there was no run to resume
        $openId = trim((string) ($identity->provider_user_id ?: $user->feishu_open_id ?: ''));
        $chatId = $this->feishuChatId ?: '';

        if ($openId !== '') {
            $feishuService->pushTextToOpenId($openId, '授权已完成！如需继续之前的任务，请再发一次。');
        } elseif ($chatId !== '') {
            $feishuService->pushTextToChat($chatId, '授权已完成！如需继续之前的任务，请再发一次。');
        }
    }

    private function handleAuthorizationFailure(FeishuService $feishuService, string $message): void
    {
        $message = trim($message);
        if ($message === '') {
            $message = '授权失败，请重新发起授权后再试。';
        }

        $identity = UserIdentity::query()
            ->where('provider', 'feishu')
            ->where('provider_user_id', $this->openId)
            ->first();

        if (! $identity || ! $identity->user) {
            return;
        }

        $user = $identity->user;
        $authCardMsgId = Cache::pull('feishu_auth_card_msgid_user_' . $user->id) ?: '';
        $pending = Cache::pull('feishu_oauth_resume_user_' . $user->id);

        if (is_array($pending)) {
            $runId = (int) ($pending['run_id'] ?? 0);
            $primaryCardMsgId = trim((string) ($pending['primary_card_message_id'] ?? ''));
            if ($runId > 0) {
                $run = Run::query()->find($runId);
                if ($run) {
                    if ($primaryCardMsgId !== '' && trim((string) ($run->feishu_message_id ?? '')) === '') {
                        $run->feishu_message_id = $primaryCardMsgId;
                        $run->save();
                    }
                    $feishuService->syncRunCard($run, 'error', $message);
                    $feishuService->sendHybridDeltaText($run, 'error', $message);
                }
            }
        }

        if ($authCardMsgId !== '') {
            $feishuService->dismissAuthorizationCard($authCardMsgId, false, $message);
        }
    }

    /**
     * Compute the next release-delay seconds based on current attempt and the $backoff schedule.
     *
     * Mirrors Laravel's default behavior when backoff array is shorter than $tries:
     * use the next index if available, otherwise fall back to the last element.
     */
    private function resolveBackoffSeconds(): int
    {
        if ($this->backoff === []) {
            return 15;
        }
        $idx = max(0, $this->attempts() - 1);
        return (int) ($this->backoff[$idx] ?? end($this->backoff));
    }

    /**
     * Called when all tries have been exhausted because the user never completed
     * the device-flow authorization in time. Unlike handleAuthorizationFailure,
     * this is not a system error — the device_code simply expired while we waited.
     *
     * Cleans up pending cache entries (same as failure path) and notifies the user
     * that the authorization window closed. The Run card is updated to an error
     * state with a timeout-specific message so the user knows to re-send.
     */
    private function handleAuthorizationTimeout(FeishuService $feishuService): void
    {
        $message = '授权窗口已超时，请在飞书中重新发送消息，我会再次为你生成授权链接。';

        Log::info('[PollCliDeviceFlow] Authorization window timed out', [
            'run_id' => $this->runId,
            'open_id' => $this->openId,
            'attempts' => $this->attempts(),
            'max_tries' => $this->tries,
        ]);

        $identity = UserIdentity::query()
            ->where('provider', 'feishu')
            ->where('provider_user_id', $this->openId)
            ->first();

        if (! $identity || ! $identity->user) {
            return;
        }

        $user = $identity->user;
        $authCardMsgId = Cache::pull('feishu_auth_card_msgid_user_' . $user->id) ?: '';
        $pending = Cache::pull('feishu_oauth_resume_user_' . $user->id);

        if (is_array($pending)) {
            $runId = (int) ($pending['run_id'] ?? 0);
            $primaryCardMsgId = trim((string) ($pending['primary_card_message_id'] ?? ''));
            if ($runId > 0) {
                $run = Run::query()->find($runId);
                if ($run) {
                    if ($primaryCardMsgId !== '' && trim((string) ($run->feishu_message_id ?? '')) === '') {
                        $run->feishu_message_id = $primaryCardMsgId;
                        $run->save();
                    }
                    $feishuService->syncRunCard($run, 'error', $message);
                    $feishuService->sendHybridDeltaText($run, 'error', $message);
                }
            }
        }

        if ($authCardMsgId !== '') {
            $feishuService->dismissAuthorizationCard($authCardMsgId, false, $message);
        }
    }
}
