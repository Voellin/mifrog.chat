<?php

namespace App\Services;

use App\Models\Run;
use App\Models\UserIdentity;

/**
 * Tool-calling path executor.
 *
 * Shared action_key -> task service dispatch lives in LarkTaskActionRegistry;
 * this class only keeps the tool-calling-specific branches (skill.load,
 * skill.execute_sandbox, skill.execute_api, request_authorization) which
 * are NOT part of the Lark CLI planner path and therefore stay local.
 *
 * P1.2 refactor (2026-04-21): constructor dropped from 14 → 2 deps.
 */
class ToolCallExecutorService
{
    public function __construct(
        private readonly LarkTaskActionRegistry $registry,
        private readonly SkillRuntimeService $skillRuntimeService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<int, array<string, mixed>>  $rawMessages
     * @return array<string, mixed>|null
     */
    public function execute(Run $run, array $params, string $actionKey, array $rawMessages = []): ?array
    {
        if ($this->registry->has($actionKey)) {
            return $this->registry->dispatch($actionKey, $run, $params, $rawMessages);
        }

        return match ($actionKey) {
            'skill.load' => $this->handleLoadSkill($run, $params),
            'skill.execute_sandbox' => $this->handleExecuteSandboxSkill($run, $params),
            'skill.execute_api' => $this->handleExecuteApiSkill($run, $params),
            'request_authorization' => $this->handleRequestAuthorization($run, $params),
            default => null,
        };
    }

    /**
     * Handle the load_skill tool call — returns the skill.md body to the LLM as a tool result.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function handleLoadSkill(Run $run, array $params): array
    {
        $skillKey = trim((string) ($params['skill_key'] ?? ''));
        if ($skillKey === '') {
            return [
                'status' => 'error',
                'handled' => true,
                'answer' => 'load_skill 需要参数 skill_key。',
                'task_kind' => 'skill_load',
            ];
        }

        try {
            $body = $this->skillRuntimeService->loadSkillBody($run->user, $skillKey);
        } catch (\DomainException $e) {
            return [
                'status' => 'error',
                'handled' => true,
                'answer' => $e->getMessage(),
                'task_kind' => 'skill_load',
            ];
        }

        return [
            'status' => 'success',
            'handled' => true,
            'answer' => "<skill_md skill_key=\"{$skillKey}\">\n" . $body . "\n</skill_md>",
            'task_kind' => 'skill_load',
            'skill_key' => $skillKey,
        ];
    }

    /**
     * Handle the execute_sandbox_skill tool call — runs a sandbox skill and returns its output.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function handleExecuteSandboxSkill(Run $run, array $params): array
    {
        $skillKey = trim((string) ($params['skill_key'] ?? ''));
        $request = trim((string) ($params['request'] ?? ''));
        if ($skillKey === '' || $request === '') {
            return [
                'status' => 'error',
                'handled' => true,
                'answer' => 'execute_sandbox_skill 需要参数 skill_key 和 request。',
                'task_kind' => 'skill_execute_sandbox',
            ];
        }

        try {
            $result = $this->skillRuntimeService->executeSandboxByKey(
                $run->user,
                $skillKey,
                $request,
                (string) $run->id
            );
        } catch (\DomainException $e) {
            return [
                'status' => 'error',
                'handled' => true,
                'answer' => $e->getMessage(),
                'task_kind' => 'skill_execute_sandbox',
            ];
        }

        return [
            'status' => $result['exit_code'] === 0 && ! $result['timed_out'] ? 'success' : 'error',
            'handled' => true,
            'answer' => $result['answer'],
            'task_kind' => 'skill_execute_sandbox',
            'skill_key' => $skillKey,
            'exit_code' => $result['exit_code'],
            'timed_out' => $result['timed_out'],
        ];
    }

    /**
     * Handle the execute_api_skill tool call — makes an HTTP call to the configured
     * internal API endpoint and returns the (filtered) response to the LLM.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function handleExecuteApiSkill(Run $run, array $params): array
    {
        $skillKey = trim((string) ($params['skill_key'] ?? ''));
        $request = trim((string) ($params['request'] ?? ''));
        if ($skillKey === '') {
            return [
                'status' => 'error',
                'handled' => true,
                'answer' => 'execute_api_skill 需要参数 skill_key。',
                'task_kind' => 'skill_execute_api',
            ];
        }

        try {
            $result = $this->skillRuntimeService->executeApiByKey(
                $run->user,
                $skillKey,
                $request,
                (string) $run->id
            );
        } catch (\DomainException $e) {
            return [
                'status' => 'error',
                'handled' => true,
                'answer' => $e->getMessage(),
                'task_kind' => 'skill_execute_api',
            ];
        }

        return [
            'status' => $result['exit_code'] === 0 && ! $result['timed_out'] ? 'success' : 'error',
            'handled' => true,
            'answer' => $result['answer'],
            'task_kind' => 'skill_execute_api',
            'skill_key' => $skillKey,
            'exit_code' => $result['exit_code'],
            'timed_out' => $result['timed_out'],
            'http_status' => $result['http_status'] ?? 0,
        ];
    }

    /**
     * Handle request_authorization tool call.
     *
     * Short-circuits when the user already has a valid Feishu token or CLI keychain,
     * telling the LLM to proceed with the actual operation instead of re-triggering
     * the device flow authorization card.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function handleRequestAuthorization(Run $run, array $params): array
    {
        $identity = UserIdentity::query()
            ->where('user_id', $run->user_id)
            ->where('provider', 'feishu')
            ->first();

        if ($identity) {
            $extra = is_array($identity->extra) ? $identity->extra : [];
            $hasCliKeychain = (bool) ($extra['cli_keychain_populated'] ?? false);
            $hasAccessToken = trim((string) ($extra['user_access_token'] ?? '')) !== '';

            if ($hasCliKeychain || $hasAccessToken) {
                \Illuminate\Support\Facades\Log::info('[ToolCallExecutor] request_authorization short-circuited: auth already available', [
                    'run_id' => $run->id,
                    'cli_keychain' => $hasCliKeychain,
                    'has_token' => $hasAccessToken,
                ]);

                return [
                    'status' => 'success',
                    'handled' => true,
                    'answer' => '飞书授权已就绪。请立即执行用户原本请求的操作（如 calendar_create / docs_read / sheets_read 等），不要再次调用 request_authorization。如果之前有工具因授权被阻塞，请带上原参数重试。',
                    'task_kind' => 'request_authorization',
                ];
            }
        }

        return [
            'status' => 'authorize',
            'handled' => true,
            'answer' => trim((string) ($params['reason'] ?? 'Feishu authorization is required to continue.')),
            'missing_capabilities' => array_values(array_filter(array_map('trim', (array) ($params['missing_scopes'] ?? ['feishu.oauth.user_token'])))),
            'task_kind' => 'request_authorization',
        ];
    }
}
