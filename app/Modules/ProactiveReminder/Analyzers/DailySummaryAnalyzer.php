<?php

namespace App\Modules\ProactiveReminder\Analyzers;

use App\Modules\ProactiveReminder\DTO\ActivityBatch;
use App\Modules\ProactiveReminder\DTO\AnalyzerResult;
use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use App\Services\LlmGatewayService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates a structured daily work summary with today's agenda.
 * Used by DailySummaryKernel, NOT by ProactiveReminderKernel.
 */
class DailySummaryAnalyzer
{
    public function __construct(
        private readonly LlmGatewayService $llmGatewayService,
    ) {
    }

    public function analyze(ActivityBatch $batch, ReminderScanRequest $request, array $todayCalendar = []): AnalyzerResult
    {
        $digest = $this->buildDigest($batch, $todayCalendar);
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

            Log::debug('[DailySummary] analyzer_response', [
                'user_id' => $request->userId,
                'response_length' => mb_strlen($content),
                'input_tokens' => $response['input_tokens'] ?? 0,
                'output_tokens' => $response['output_tokens'] ?? 0,
            ]);

            if ($content === '' || mb_strtoupper($content, 'UTF-8') === 'NULL') {
                return AnalyzerResult::skip('llm_said_null');
            }

            return new AnalyzerResult(true, $content, 'daily_summary_generated');
        } catch (Throwable $e) {
            Log::error('[DailySummary] analyzer_failed', [
                'user_id' => $request->userId,
                'error' => $e->getMessage(),
            ]);
            return AnalyzerResult::skip('llm_error: ' . $e->getMessage());
        }
    }

    private function buildSystemPrompt(string $userName): string
    {
        return <<<PROMPT
你是 {$userName} 的工作助理。现在是早晨，请根据下面提供的昨日工作动态，生成一份简洁的每日工作总结。

输出格式（严格遵守）：

📋 昨日工作总结
（按重要性排列，每项一行。归纳合并同类项，不要逐条罗列原始数据。重点突出成果、决策和待跟进事项。）

⏰ 今日日程
（列出今天的日程和待办任务。如果没有，写"暂无日程安排"。）

💡 建议关注
（如果有需要跟进、可能遗漏、或值得注意的事项，简短提醒 1~3 条。如果没有特别需要关注的，省略此段。）

规则：
1. 用自然的中文，像一个靠谱的助理在晨会时简报
2. 总字数控制在 300~600 字
3. 不要解释你的推理过程
4. 如果某个类别没有数据，直接跳过不要提及
5. 不要虚构任何信息
PROMPT;
    }

    private function buildDigest(ActivityBatch $batch, array $todayCalendar): string
    {
        $parts = [];

        if ($batch->calendar() !== []) {
            $lines = ['【昨日日程】'];
            foreach ($batch->calendar() as $event) {
                $line = '- ' . trim((string) ($event['summary'] ?? '未命名日程'));
                if (!empty($event['start_time'])) {
                    $line .= ' | ' . $event['start_time'];
                }
                if (!empty($event['organizer'])) {
                    $line .= ' | 组织者: ' . $event['organizer'];
                }
                $lines[] = $line;
            }
            $parts[] = implode("\n", $lines);
        }

        if ($batch->messages() !== []) {
            $lines = ['【昨日消息摘要】'];
            // Group by chat to avoid noise
            $byChat = [];
            foreach ($batch->messages() as $msg) {
                $chatName = trim((string) ($msg['chat_name'] ?? '未知会话'));
                $byChat[$chatName][] = $msg;
            }
            foreach ($byChat as $chatName => $msgs) {
                $count = count($msgs);
                $senders = array_unique(array_filter(array_map(fn($m) => trim((string) ($m['sender'] ?? '')), $msgs)));
                $senderText = implode('、', array_slice($senders, 0, 3));
                $preview = mb_substr(trim((string) ($msgs[0]['text'] ?? '')), 0, 80);
                $lines[] = "- {$chatName}（{$count}条，参与者：{$senderText}）：{$preview}...";
            }
            $parts[] = implode("\n", $lines);
        }

        if ($batch->documents() !== []) {
            $lines = ['【昨日文档活动】'];
            foreach ($batch->documents() as $doc) {
                $line = '- ' . trim((string) ($doc['title'] ?? '未命名文档'));
                if (!empty($doc['owner'])) {
                    $line .= ' | 所有者: ' . $doc['owner'];
                }
                if (!empty($doc['updated_at'])) {
                    $line .= ' | 更新: ' . $doc['updated_at'];
                }
                $preview = trim((string) ($doc['preview'] ?? ''));
                if ($preview !== '') {
                    $line .= "\n  正文片段: " . mb_substr($preview, 0, 1500);
                }
                $lines[] = $line;
            }
            $parts[] = implode("\n", $lines);
        }

        if ($batch->meetings() !== []) {
            $lines = ['【昨日会议】'];
            foreach ($batch->meetings() as $meeting) {
                $line = '- ' . trim((string) ($meeting['topic'] ?? '未命名会议'));
                if (!empty($meeting['start_time'])) {
                    $line .= ' | ' . $meeting['start_time'];
                }
                if (!empty($meeting['notes'])) {
                    $line .= "\n  纪要片段: " . mb_substr((string) $meeting['notes'], 0, 200);
                }
                $lines[] = $line;
            }
            $parts[] = implode("\n", $lines);
        }

        // Tasks from the batch (via TaskActivitySource)
        $tasks = array_filter($batch->items(), fn($item) => $item->type === 'task');
        if ($tasks !== []) {
            $lines = ['【任务】'];
            $completed = [];
            $pending = [];
            foreach ($tasks as $item) {
                $p = $item->payload;
                if (($p['is_completed'] ?? false)) {
                    $completed[] = '- ✅ ' . $item->title;
                } else {
                    $dueText = !empty($p['due']) ? '（截止: ' . $p['due'] . '）' : '';
                    $pending[] = '- ⏳ ' . $item->title . $dueText;
                }
            }
            if ($completed !== []) {
                $lines[] = '已完成:';
                $lines = array_merge($lines, $completed);
            }
            if ($pending !== []) {
                $lines[] = '进行中/待完成:';
                $lines = array_merge($lines, $pending);
            }
            $parts[] = implode("\n", $lines);
        }

        // Today's calendar (collected separately for "today's agenda")
        if ($todayCalendar !== []) {
            $lines = ['【今日日程预告】'];
            foreach ($todayCalendar as $event) {
                $line = '- ' . trim((string) ($event['summary'] ?? '未命名日程'));
                if (!empty($event['start_time'])) {
                    $line .= ' | ' . $event['start_time'];
                }
                $lines[] = $line;
            }
            $parts[] = implode("\n", $lines);
        }

        return implode("\n\n", $parts);
    }
}
