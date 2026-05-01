<?php

namespace App\Modules\ProactiveReminder\Analyzers;

use App\Modules\ProactiveReminder\DTO\ActivityBatch;
use App\Modules\ProactiveReminder\DTO\AnalyzerResult;
use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use App\Services\LlmGatewayService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Distill recently scanned activity into a compact L2 episodic memory entry.
 * Output is for archival (written to the user's L2 memory), NOT for messaging.
 */
class ActivityArchiveAnalyzer
{
    public function __construct(
        private readonly LlmGatewayService $llmGatewayService,
    ) {
    }

    public function analyze(ActivityBatch $batch, ReminderScanRequest $request): AnalyzerResult
    {
        $digest = $this->buildDigest($batch);
        if (trim($digest) === '') {
            return AnalyzerResult::skip('no_activity');
        }

        $messages = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt($request->userName)],
            ['role' => 'user', 'content' => $digest],
        ];

        try {
            $response = $this->llmGatewayService->chat($messages);
            $content = trim((string) ($response['content'] ?? ''));

            Log::debug('[ActivityArchive] analyzer_response', [
                'user_id' => $request->userId,
                'response_length' => mb_strlen($content),
                'input_tokens' => $response['input_tokens'] ?? 0,
                'output_tokens' => $response['output_tokens'] ?? 0,
            ]);

            if ($content === '' || mb_strtoupper($content, 'UTF-8') === 'NULL') {
                return AnalyzerResult::skip('llm_said_null');
            }

            return new AnalyzerResult(true, $content, 'archive_generated');
        } catch (Throwable $e) {
            Log::error('[ActivityArchive] analyzer_failed', [
                'user_id' => $request->userId,
                'error' => $e->getMessage(),
            ]);
            return AnalyzerResult::skip('llm_error: ' . $e->getMessage());
        }
    }

    private function buildSystemPrompt(string $userName): string
    {
        return <<<PROMPT
你是 {$userName} 的工作记忆助手。下面是过去 2 小时系统扫描到的飞书活动（日历/邮件/文档/聊天/任务/会议/表格/多维表）。

请把这些活动浓缩成一条简短的"情节归档"，将来需要回想"那段时间发生了什么"时可以快速 retrieve。

输出格式（严格遵守）：

【时段】今天 H:M~H:M
【关键活动】
- 用 1~3 个 bullet 列出真正值得记的事（合并同类项；忽略噪音；忽略机器人自身的活动）
【涉及】（可选）
- 列出涉及的人/项目/文档名

规则：
1. 只记可以用来"回想"的有用信息，不要照抄原始数据
2. 总字数 80~250 字，宁可短不要凑字数
3. 不需要"建议"或"提醒"段落
4. 如果这段时间没有实质活动，直接输出 NULL（不要写解释）
PROMPT;
    }

    private function buildDigest(ActivityBatch $batch): string
    {
        $sections = [];

        $cal = $batch->calendar();
        if (! empty($cal)) {
            $sections[] = "【日历】" . count($cal) . " 项：" . $this->summarize($cal, 'summary', 5);
        }

        $messages = $batch->messages();
        if (! empty($messages)) {
            $sections[] = "【聊天】" . count($messages) . " 条：" . $this->summarize($messages, 'preview', 10, 80);
        }

        $docs = $batch->documents();
        if (! empty($docs)) {
            $sections[] = "【文档】" . count($docs) . " 个：" . $this->summarize($docs, 'title', 5);
        }

        $meetings = $batch->meetings();
        if (! empty($meetings)) {
            $sections[] = "【会议】" . count($meetings) . " 项：" . $this->summarize($meetings, 'topic', 5);
        }

        $sheets = $batch->sheets();
        if (! empty($sheets)) {
            $sections[] = "【表格】" . count($sheets) . " 个：" . $this->summarize($sheets, 'title', 5);
        }

        $bitables = $batch->bitables();
        if (! empty($bitables)) {
            $sections[] = "【多维表】" . count($bitables) . " 项：" . $this->summarize($bitables, 'title', 5);
        }

        $mails = $batch->mails();
        if (! empty($mails)) {
            $sections[] = "【邮件】" . count($mails) . " 封：" . $this->summarize($mails, 'subject', 5);
        }

        $tasks = array_filter($batch->items(), fn ($it) => $it->type === 'task');
        if (! empty($tasks)) {
            $sections[] = "【任务】" . count($tasks) . " 个";
        }

        if (empty($sections)) {
            return '';
        }

        return implode("\n", $sections);
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function summarize(array $rows, string $key, int $limit, int $maxChars = 40): string
    {
        $items = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $val = trim((string) ($row[$key] ?? ''));
            if ($val !== '') {
                $items[] = mb_substr($val, 0, $maxChars);
            }
        }
        return implode('; ', $items);
    }
}
