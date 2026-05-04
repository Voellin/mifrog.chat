<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\UserIdentity;
use App\Services\FeishuCliClient;
use App\Services\FeishuService;
use App\Services\RunFactoryService;
use App\Support\FeishuScopeCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class FeishuOauthController extends Controller
{
    private const OAUTH_STATE_CACHE_PREFIX = 'feishu_oauth_state_';
    private const OAUTH_STATE_TTL_SECONDS = 180;
    private const OAUTH_RESUME_CACHE_PREFIX = 'feishu_oauth_resume_user_';

    public function __construct(
        private readonly FeishuService $feishuService,
        private readonly FeishuCliClient $feishuCliClient,
        private readonly RunFactoryService $runFactoryService,
        private readonly FeishuScopeCatalog $scopeCatalog,
    ) {
    }

    public function start(Request $request): RedirectResponse
    {
        $openId = trim((string) $request->query('open_id', ''));
        if ($openId === '') {
            abort(400, 'missing open_id');
        }

        $identity = UserIdentity::query()
            ->where('provider', 'feishu')
            ->where('provider_user_id', $openId)
            ->first();

        if (! $identity) {
            abort(404, 'user not found');
        }

        $requestedScopes = $this->parseScopeInput((string) $request->query('scopes', ''));
        if ($requestedScopes === []) {
            $requestedScopes = $this->normalizeScopes($this->feishuService->requiredOauthScopes());
        }

        $state = Str::random(40);
        Cache::put(
            $this->oauthStateKey($state),
            [
                'identity_id' => (int) $identity->id,
                'open_id' => $openId,
                'requested_scopes' => $requestedScopes,
            ],
            now()->addSeconds(self::OAUTH_STATE_TTL_SECONDS)
        );

        $redirectUri = url('/feishu/oauth/callback');
        $url = $this->feishuService->buildOauthAuthorizeUrl($redirectUri, $state, $requestedScopes);
        if ($url === null) {
            abort(500, 'feishu app config missing');
        }

        return redirect()->away($url);
    }

    public function callback(Request $request)
    {
        $code = trim((string) $request->query('code', ''));
        $state = trim((string) $request->query('state', ''));
        if ($code === '' || $state === '') {
            return response($this->renderHtml('授权失败', '缺少 code 或 state 参数。'), 400);
        }

        $statePayload = Cache::pull($this->oauthStateKey($state));
        if (! is_array($statePayload)) {
            return response($this->renderHtml('授权失败', '授权状态已过期，请重新发起授权。'), 400);
        }

        $identity = UserIdentity::query()->find((int) Arr::get($statePayload, 'identity_id', 0));
        if (! $identity || $identity->provider !== 'feishu') {
            return response($this->renderHtml('授权失败', '未找到待绑定的飞书用户。'), 404);
        }

        $redirectUri = url('/feishu/oauth/callback');
        $tokenResult = $this->feishuService->exchangeUserAccessTokenByCode($code, $redirectUri);
        if (($tokenResult['ok'] ?? false) !== true) {
            $error = trim((string) Arr::get($tokenResult, 'error', 'exchange_failed'));

            return response($this->renderHtml('授权失败', '换取 user_access_token 失败：'.$error), 400);
        }

        $accessToken = trim((string) Arr::get($tokenResult, 'access_token', ''));
        $refreshToken = trim((string) Arr::get($tokenResult, 'refresh_token', ''));
        $expiresIn = (int) Arr::get($tokenResult, 'expires_in', 0);
        $refreshExpiresIn = (int) Arr::get($tokenResult, 'refresh_expires_in', 0);
        $scope = trim((string) Arr::get($tokenResult, 'scope', ''));

        if ($accessToken !== '' && $this->feishuCliClient->isEnabled()) {
            $nowTs = time();
            $this->feishuCliClient->rememberTokenContext($accessToken, [
                'open_id' => (string) ($identity->provider_user_id ?? ''),
                'user_name' => (string) ($identity->user?->name ?? $identity->provider_user_id ?? ''),
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at_ms' => ($nowTs + max(0, $expiresIn)) * 1000,
                'refresh_expires_at_ms' => ($nowTs + max(0, $refreshExpiresIn)) * 1000,
                'scope' => $scope,
                'granted_at_ms' => $nowTs * 1000,
            ]);
        }

        $requiredScopes = $this->normalizeScopes((array) Arr::get($statePayload, 'requested_scopes', []));
        if ($requiredScopes === []) {
            $requiredScopes = $this->normalizeScopes($this->feishuService->requiredOauthScopes());
        }

        $scopeList = $this->normalizeScopes($this->scopeCatalog->parseScopeString($scope));
        $missingScopes = array_values(array_diff($requiredScopes, $scopeList));
        $missingScopes = array_values(array_filter($missingScopes, static fn (string $item) => $item !== 'offline_access'));

        if (! empty($missingScopes)) {
            $missingText = implode('、', $missingScopes);

            return response($this->renderHtml('授权未完成', '已获取 token，但缺少必要权限：'.$missingText.'。请在飞书开放平台发布权限后重新授权。'), 400);
        }

        $userInfo = $accessToken !== ''
            ? $this->feishuService->getUserInfoByUserAccessToken($accessToken)
            : ['ok' => false];
        $authedOpenId = trim((string) Arr::get($userInfo, 'open_id', ''));

        if ($authedOpenId !== '' && $authedOpenId !== (string) $identity->provider_user_id) {
            return response($this->renderHtml('授权失败', '当前授权账号与待绑定账号不一致，请返回飞书重试。'), 400);
        }

        $extra = is_array($identity->extra) ? $identity->extra : [];
        $nowTs = time();
        $extra['user_access_token'] = $accessToken;
        $extra['user_refresh_token'] = $refreshToken;
        $extra['user_token_expires_at'] = $expiresIn > 0 ? ($nowTs + $expiresIn - 120) : null;
        $extra['user_refresh_expires_at'] = $refreshExpiresIn > 0 ? ($nowTs + $refreshExpiresIn - 300) : null;
        $extra['user_token_scope'] = $scope;
        $extra['user_token_scope_missing'] = $missingScopes;
        $extra['user_token_authed_at'] = $nowTs;
        if ($authedOpenId !== '') {
            $extra['user_token_open_id'] = $authedOpenId;
        }
        if (! isset($extra['opportunity_last_scan_at'])) {
            $extra['opportunity_last_scan_at'] = max(0, $nowTs - 60);
        }

        $identity->extra = $extra;
        $identity->save();

        if ($identity->user && $authedOpenId !== '' && trim((string) $identity->user->feishu_open_id) === '') {
            $identity->user->feishu_open_id = $authedOpenId;
            $identity->user->save();
        }

        $resumedRunId = $this->resumePendingRun($identity);

        if ($resumedRunId !== null) {
            return response($this->renderHtml('授权成功', '米蛙已获得权限，并已自动继续你刚才中断的任务。'));
        }

        return response($this->renderHtml('授权成功', '米蛙已获得授权，你可以返回飞书继续使用。'));
    }

    private function resumePendingRun(UserIdentity $identity): ?int
    {
        $user = $identity->user;
        if (! $user) {
            return null;
        }

        $pending = Cache::pull(self::OAUTH_RESUME_CACHE_PREFIX.$user->id);
        if (! is_array($pending)) {
            return null;
        }

        $content = trim((string) ($pending['content'] ?? ''));
        if ($content === '') {
            return null;
        }

        $channelConversationId = trim((string) ($pending['channel_conversation_id'] ?? ''));
        $feishuChatId = trim((string) ($pending['feishu_chat_id'] ?? ''));

        $run = $this->runFactoryService->createRun($user, $content, [
            'channel' => 'feishu',
            'channel_conversation_id' => $channelConversationId,
            'feishu_chat_id' => $feishuChatId,
            'source_message_id' => '',
            'oauth_resumed' => true,
        ]);

        $openId = trim((string) ($identity->provider_user_id ?: $user->feishu_open_id ?: ''));
        if ($openId !== '') {
            $this->feishuService->pushTextToOpenId($openId, '授权已完成，我正在继续你刚才的任务。');
        } elseif ($feishuChatId !== '') {
            $this->feishuService->pushTextToChat($feishuChatId, '授权已完成，我正在继续你刚才的任务。');
        }

        return (int) $run->id;
    }

    private function oauthStateKey(string $state): string
    {
        return self::OAUTH_STATE_CACHE_PREFIX.$state;
    }

    private function parseScopeInput(string $input): array
    {
        if (trim($input) === '') {
            return [];
        }

        $parts = preg_split('/[\s,]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $this->normalizeScopes($parts);
    }

    private function normalizeScopes(array $scopes): array
    {
        $set = [];
        foreach ($scopes as $scope) {
            $normalized = $this->normalizeScope((string) $scope);
            if ($normalized !== '') {
                $set[$normalized] = true;
            }
        }

        return array_keys($set);
    }

    private function normalizeScope(string $scope): string
    {
        return $this->scopeCatalog->normalizeScope($scope);
    }

    private function renderHtml(string $title, string $content): string
    {
        $safeTitle = e($title);
        $safeContent = e($content);

        return <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>{$safeTitle}</title>
  <style>
    body { font-family: -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'PingFang SC','Microsoft YaHei',sans-serif; background:#f5f7fb; margin:0; }
    .wrap { max-width:560px; margin:64px auto; background:#fff; border:1px solid #e9edf5; border-radius:14px; padding:28px; box-shadow:0 8px 24px rgba(15,23,42,.06); }
    h1 { margin:0 0 12px; font-size:22px; color:#0f172a; }
    p { margin:0; color:#334155; line-height:1.7; }
  </style>
</head>
<body>
  <div class="wrap">
    <h1>{$safeTitle}</h1>
    <p>{$safeContent}</p>
  </div>
</body>
</html>
HTML;
    }
}
