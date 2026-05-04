<?php

namespace App\Services\RunExecution;

use App\Jobs\PollCliDeviceFlowJob;
use App\Models\Run;
use App\Services\FeishuService;
use App\Support\FeishuScopeCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Auth-flow helper extracted from RunExecutionService.
 *
 * Responsibilities:
 * - Resolve OAuth scopes for the authorization card
 * - Cache the user's original request so the run can resume after authorization
 * - Classify whether a platform skill result was blocked due to authorization
 * - Prepare the Feishu Device Flow (start + schedule poll + push card)
 *
 * This class intentionally does NOT call `completeBlocked` — it prepares the
 * auth artifacts and the caller (RunExecutionService::triggerDeviceFlowAndBlock)
 * invokes the completion helper, which keeps persistMemorySafely() call-chain
 * integrity inside RunExecutionService (required by the wiring test).
 */
class RunAuthFlowHelper
{
    public const OAUTH_RESUME_CACHE_PREFIX = 'feishu_oauth_resume_user_';

    public function __construct(
        private readonly FeishuService $feishuService,
        private readonly FeishuScopeCatalog $scopeCatalog,
    ) {
    }

    /**
     * Prepare the authorization artifacts: resolve scopes, cache resume payload,
     * start device flow, dispatch poll job, and push the authorization card.
     *
     * @param  array<int,string>  $missingCapabilities
     * @return array{scopes: array<int,string>, auth_url: string}
     */
    public function prepareDeviceFlow(
        Run $run,
        Collection $messageRows,
        array $missingCapabilities,
        string $taskKind,
        string $oauthUrl = ''
    ): array {
        $missingCapabilities = array_values(array_unique(array_filter(array_map(
            static fn ($item) => trim((string) $item),
            $missingCapabilities
        ))));

        $this->cacheOAuthResumePayload($run, $messageRows, [
            'needs_authorization' => true,
            'missing_capabilities' => $missingCapabilities,
            'task_kind' => $taskKind,
        ]);

        $scopes = $this->collectAuthScopesForCard($missingCapabilities, $oauthUrl);
        $authUrl = trim($oauthUrl);
        $deviceFlowResult = $this->feishuService->startDeviceFlowAuth(
            $missingCapabilities,
            trim((string) ($run->user?->feishu_open_id ?? ''))
        );
        if (($deviceFlowResult['ok'] ?? false) === true) {
            $authUrl = (string) ($deviceFlowResult['verification_url'] ?? $authUrl);
            $deviceCode = (string) ($deviceFlowResult['device_code'] ?? '');
            if ($deviceCode !== '') {
                $openId = trim((string) ($run->user?->feishu_open_id ?? ''));
                PollCliDeviceFlowJob::dispatch(
                    (int) $run->id,
                    $deviceCode,
                    $openId,
                    $scopes,
                    (string) ($run->feishu_chat_id ?? ''),
                )->onQueue('default');
            }
        }

        if ($authUrl !== '') {
            $this->feishuService->pushAuthorizationCard($run, $authUrl, $scopes);
        }

        return [
            'scopes' => $scopes,
            'auth_url' => $authUrl,
        ];
    }

    /**
     * @param  array<int,string>  $missingCapabilities
     * @return array<int,string>
     */
    public function collectAuthScopesForCard(array $missingCapabilities, string $oauthUrl): array
    {
        $scopes = [];

        foreach ($missingCapabilities as $capability) {
            $scope = $this->scopeCatalog->scopeFromCapability((string) $capability);
            if ($scope !== null && $scope !== '') {
                $scopes[] = $scope;
            }
        }

        $oauthUrl = trim($oauthUrl);
        if ($oauthUrl !== '') {
            $query = parse_url($oauthUrl, PHP_URL_QUERY);
            if (is_string($query) && $query !== '') {
                $params = [];
                parse_str($query, $params);
                $scopeText = trim((string) ($params['scopes'] ?? $params['scope'] ?? ''));
                if ($scopeText !== '') {
                    $scopes = array_merge($scopes, $this->scopeCatalog->parseScopeString($scopeText));
                }
            }
        }

        return $this->scopeCatalog->normalizeScopes($scopes);
    }

    /**
     * Cache the user's latest request so we can resume execution after they
     * complete authorization in a separate browser/device tab.
     *
     * @param  array<string,mixed>  $capabilityCheck
     */
    public function cacheOAuthResumePayload(Run $run, Collection $messageRows, array $capabilityCheck): void
    {
        if (($capabilityCheck['needs_authorization'] ?? false) !== true) {
            return;
        }

        $latestUserText = '';
        foreach ($messageRows->reverse() as $row) {
            if ((string) ($row->role ?? '') !== 'user') {
                continue;
            }
            $latestUserText = trim((string) ($row->content ?? ''));
            if ($latestUserText !== '') {
                break;
            }
        }

        if ($latestUserText === '') {
            return;
        }

        Cache::put(
            self::OAUTH_RESUME_CACHE_PREFIX.$run->user_id,
            [
                'content' => $latestUserText,
                'run_id' => (int) $run->id,
                'task_kind' => (string) ($capabilityCheck['task_kind'] ?? ''),
                'missing_capabilities' => array_values((array) ($capabilityCheck['missing_capabilities'] ?? [])),
                'channel_conversation_id' => (string) ($run->conversation?->channel_conversation_id ?? ''),
                'feishu_chat_id' => (string) ($run->feishu_chat_id ?? ''),
                'primary_card_message_id' => (string) ($run->feishu_message_id ?? ''),
                'cached_at' => time(),
            ],
            now()->addMinutes(30)
        );
    }

    /**
     * @param  array<string,mixed>  $result
     */
    public function isAuthorizationBlockedResult(array $result): bool
    {
        $missing = array_values((array) ($result['missing_capabilities'] ?? $result['missing'] ?? []));
        foreach ($missing as $item) {
            $cap = strtolower(trim((string) $item));
            if ($cap === 'feishu.oauth.user_token' || str_starts_with($cap, 'feishu.scope.')) {
                return true;
            }
        }

        // Check nested error from CLI for auth-type errors
        $error = $result['error'] ?? null;
        if (is_array($error)) {
            $errorType = strtolower(trim((string) ($error['type'] ?? ($error['error']['type'] ?? ''))));
            if ($errorType === 'auth' || $errorType === 'token' || $errorType === 'permission') {
                return true;
            }

            $errorMessage = trim((string) ($error['message'] ?? ($error['msg'] ?? ($error['error']['message'] ?? ''))));
            if ($this->looksLikeAuthorizationErrorText($errorMessage)) {
                return true;
            }
        }

        if (is_string($error) && $this->looksLikeAuthorizationErrorText($error)) {
            return true;
        }

        $raw = $result['raw'] ?? null;
        if (is_array($raw)) {
            $rawErrorType = strtolower(trim((string) ($raw['error_type'] ?? '')));
            if ($rawErrorType === 'auth' || $rawErrorType === 'token' || $rawErrorType === 'permission' || $rawErrorType === 'config') {
                return true;
            }

            $rawMessage = trim((string) ($raw['msg'] ?? ''));
            if ($this->looksLikeAuthorizationErrorText($rawMessage)) {
                return true;
            }
        }

        return false;
    }

    public function looksLikeAuthorizationErrorText(string $text): bool
    {
        $text = strtolower(trim($text));
        if ($text === '') {
            return false;
        }

        return str_contains($text, '[auth]')
            || str_contains($text, 'required scope:')
            || str_contains($text, 'not logged in')
            || str_contains($text, 'login required')
            || str_contains($text, 'authorization required')
            || str_contains($text, 'authorization is required')
            || str_contains($text, 'unauthorized')
            || str_contains($text, 'invalid access token')
            || str_contains($text, 'access token expired')
            || str_contains($text, '授权')
            || str_contains($text, '未登录')
            || str_contains($text, '缺少scope');
    }
}
