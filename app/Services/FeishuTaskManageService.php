<?php

namespace App\Services;

use App\Models\Run;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class FeishuTaskManageService
{
    private const REQUIRED_SCOPE_WRITE = 'task:task:write';
    private const DEFAULT_TIMEZONE = 'Asia/Shanghai';

    public function __construct(
        private readonly FeishuService $feishuService,
        private readonly FeishuTokenService $feishuTokenService,
        private readonly FeishuCliClient $feishuCliClient,
    )
    {
    }

    /**
     * OpenClaw executor: receives structured params from LLM, calls lark-cli task +create.
     *
     * Expected $params keys:
     *   - summary: string (task title)
     *   - description: string
     *   - due_time: ISO 8601 string or null
     *   - due_is_all_day: bool
     *   - needs_clarification: bool
     *   - clarification_message: string
     *
     * @param  array<string,mixed>  $params
     */
    public function createTask(Run $run, array $params): array
    {
        // ── Check if LLM extraction failed ──
        if (($params['_extraction_failed'] ?? false) === true) {
            return [
                'status' => 'clarify',
                'message' => '请告诉我任务内容，比如"明天下午3点前完成周报"。',
            ];
        }

        // ── Check if LLM says needs clarification ──
        if (($params['needs_clarification'] ?? false) === true) {
            $msg = trim((string) ($params['clarification_message'] ?? ''));
            return [
                'status' => 'clarify',
                'message' => $msg !== '' ? $msg : '请告诉我具体的任务内容。',
            ];
        }

        // ── Token & scope check ──
        [$accessToken, $identity, $error] = $this->feishuTokenService->resolveUserToken($run, self::REQUIRED_SCOPE_WRITE, '飞书任务写入');
        if ($error !== null) {
            return $error;
        }

        // ── CLI availability check ──
        if (! $this->feishuCliClient->isEnabled() || ! $this->feishuCliClient->isAvailable()) {
            return [
                'status' => 'failed',
                'message' => '飞书 CLI 工具不可用，无法创建任务。',
            ];
        }

        // ── Build CLI command from structured params ──
        $summary = trim((string) ($params['summary'] ?? ''));
        if ($summary === '') {
            $summary = '待办事项';
        }
        $summary = $this->truncateUtf8($summary, 1000);

        $description = trim((string) ($params['description'] ?? ''));
        if ($description === '') {
            $description = '由米蛙创建';
        }
        $description = $this->truncateUtf8($description, 1000);

        $feishuConfig = $this->feishuService->readConfig();

        $cliCmd = [
            'task', '+create',
            '--summary', $summary,
            '--description', $description,
            '--idempotency-key', (string) Str::uuid(),
        ];

        // Parse due time
        $dueDisplay = '';
        $dueTimeStr = trim((string) ($params['due_time'] ?? ''));
        $dueIsAllDay = (bool) ($params['due_is_all_day'] ?? false);

        if ($dueTimeStr !== '' && $dueTimeStr !== 'null') {
            $dueTime = $this->parseIsoTime($dueTimeStr);
            if ($dueTime !== null) {
                // CLI supports ISO 8601, date:YYYY-MM-DD, relative:+2d, ms timestamp
                if ($dueIsAllDay) {
                    $cliCmd[] = '--due';
                    $cliCmd[] = 'date:' . $dueTime->format('Y-m-d');
                } else {
                    $cliCmd[] = '--due';
                    $cliCmd[] = $dueTime->toIso8601String();
                }
                $dueDisplay = $dueTime->format($dueIsAllDay ? 'Y-m-d' : 'Y-m-d H:i');
            }
        }

        // Add assignee
        $assigneeOpenId = trim((string) ($identity->provider_user_id ?: ($run->user?->feishu_open_id ?? '')));
        if ($assigneeOpenId !== '') {
            $cliCmd[] = '--assignee';
            $cliCmd[] = $assigneeOpenId;
        }

        Log::debug('[TaskManage] Creating task via CLI', [
            'run_id' => $run->id,
            'cli_cmd' => $cliCmd,
            'params' => $params,
        ]);

        // ── Execute via lark-cli ──
        try {
            $create = $this->feishuCliClient->runSkillCommand($feishuConfig, $accessToken, $cliCmd);
        } catch (Throwable $e) {
            Log::warning('[TaskManage] CLI create failed', ['error' => $e->getMessage()]);
            $errMsg = $e->getMessage();
            $isAuth = str_contains($errMsg, '"type":"auth"') || str_contains($errMsg, 'auth') || str_contains($errMsg, 'token');
            return [
                'status' => $isAuth ? 'blocked' : 'failed',
                'message' => '飞书任务创建失败：' . $errMsg,
                'missing' => $isAuth ? ['feishu.oauth.user_token'] : [],
            ];
        }

        // ── Check for API-level errors ──
        $cliCode = (int) ($create['code'] ?? 0);
        if ($cliCode !== 0) {
            $errorMsg = trim((string) ($create['msg'] ?? 'task_create_failed'));
            if (in_array($cliCode, [99991672, 99991663, 40003, 230006], true)) {
                return [
                    'status' => 'blocked',
                    'message' => '飞书返回权限不足（' . $errorMsg . '），请确认已发布并授权 scope：' . self::REQUIRED_SCOPE_WRITE,
                    'missing' => ['feishu.scope.' . self::REQUIRED_SCOPE_WRITE],
                    'error' => $create,
                ];
            }

            return [
                'status' => 'failed',
                'message' => '飞书任务创建失败：' . $errorMsg,
                'error' => $create,
            ];
        }

        // ── Build success response ──
        $data = (array) ($create['data'] ?? $create);
        $task = (array) ($data['task'] ?? $data);

        $lines = [];
        $lines[] = '已为你创建飞书任务：' . $summary;
        $lines[] = '负责人：你';
        if ($dueDisplay !== '') {
            $lines[] = '截止时间：' . $dueDisplay;
        }

        $guid = trim((string) ($task['guid'] ?? ($data['task_guid'] ?? '')));
        if ($guid !== '') {
            $lines[] = '任务 ID：' . $guid;
        }

        $url = trim((string) ($task['url'] ?? ($data['url'] ?? '')));
        if ($url === '' && $guid !== '') {
            $url = 'https://applink.feishu.cn/client/todo/detail?guid=' . $guid;
        }
        if ($url !== '') {
            $lines[] = '任务链接：' . $url;
        }

        $lines[] = '需要我继续补充描述、负责人或提醒吗？';

        return [
            'status' => 'created',
            'message' => implode("\n", $lines),
            'task' => $create,
        ];
    }

    private function parseIsoTime(string $timeStr): ?CarbonImmutable
    {
        $timeStr = trim($timeStr);
        if ($timeStr === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($timeStr, self::DEFAULT_TIMEZONE);
        } catch (\Throwable) {
            return null;
        }
    }

    private function truncateUtf8(string $text, int $max): string
    {
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') <= $max
                ? $text
                : mb_substr($text, 0, $max, 'UTF-8');
        }
        return strlen($text) <= $max ? $text : substr($text, 0, $max);
    }
}