<?php

namespace App\Modules\ProactiveReminder\Analyzers;

use App\Modules\ProactiveReminder\DTO\ActivityBatch;
use App\Modules\ProactiveReminder\DTO\AnalyzerResult;
use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use App\Services\LlmGatewayService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates a structured weekly work report (周报) that mirrors the Feishu
 * wiki 周报模板 schema (汇报周期/当前阶段/下一里程碑/整体规划/本周项目进展/
 * 附参考/本周总结(GoodCase+BadCase)/下周推进计划/风险项).
 *
 * Used by WeeklySummaryKernel, NOT by ProactiveReminderKernel/DailySummaryKernel.
 *
 * Key differences from DailySummaryAnalyzer:
 *   - "本周" prefix instead of "昨日"
 *   - Digest covers sheets/bitables/mails buckets added in the 2026-04-20 expansion
 *   - Output schema injected into the system prompt comes directly from the wiki template;
 *     the LLM is instructed to fill each field from evidence in the digest, and to write
 *     "暂无" when a field has no supporting data (instead of fabricating).
 */
class WeeklySummaryAnalyzer
{
    public function __construct(
        private readonly LlmGatewayService $llmGatewayService,
    ) {
    }

    public function analyze(ActivityBatch $batch, ReminderScanRequest $request): AnalyzerResult
    {
        $digest = $this->buildDigest($batch, $request);
        if (trim($digest) === '') {
            return AnalyzerResult::skip('no_activity');
        }

        $messages = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt($request->userName, $request)],
            ['role' => 'user', 'content' => $digest],
        ];

        try {
            $response = $this->llmGatewayService->chat($messages);
            $content = trim((string) ($response['content'] ?? ''));

            Log::debug('[WeeklySummary] analyzer_response', [
                'user_id' => $request->userId,
                'response_length' => mb_strlen($content),
                'input_tokens' => $response['input_tokens'] ?? 0,
                'output_tokens' => $response['output_tokens'] ?? 0,
            ]);

            if ($content === '' || mb_strtoupper($content, 'UTF-8') === 'NULL') {
                return AnalyzerResult::skip('llm_said_null');
            }

            return new AnalyzerResult(true, $content, 'weekly_summary_generated');
        } catch (Throwable $e) {
            Log::error('[WeeklySummary] analyzer_failed', [
                'user_id' => $request->userId,
                'error' => $e->getMessage(),
            ]);
            return AnalyzerResult::skip('llm_error: ' . $e->getMessage());
        }
    }

    private function buildSystemPrompt(string $userName, ReminderScanRequest $request): string
    {
        $sinceStr = $request->since->format('Y/m/d');
        $untilStr = $request->until->format('Y/m/d');

        return <<<PROMPT
你是 {$userName} 的工作助理。现在是周一早晨，请根据下面提供的上周（{$sinceStr}~{$untilStr}）全量工作动态，生成一份正式的项目周报。

输出格式（严格遵守，不要输出 markdown 表头，直接用下面的中文小节标题；每节独占一行空行分隔）：

汇报周期：{$sinceStr}~{$untilStr}
当前阶段：（基于工作动态中出现的项目/里程碑/进展，用一句话概括目前所处的阶段，例如"需求评审""方案设计""开发联调""上线灰度"。如确实无法判断，写"暂无"。）
下一里程碑：（从工作动态中提取最近一次提到的里程碑/交付日期；若无明确线索写"暂无"。）

整体规划
（如动态中出现长期规划/路线图描述，用 2~4 行概括；否则写"暂无"。）

本周项目进展
（用无序列表，每条格式为：重点工作 / 跟进人 / 关键进展 / 备注说明。按重要性排序，归并同类项，不要逐条罗列原始消息。跟进人默认为 {$userName} 或动态中明确的负责人。）

附参考
（把动态中出现的会议纪要、关键文档、飞书文档/表格/多维表格链接作为要点列出；无则写"暂无"。）

本周总结

GoodCase
（3~5 条成果型亮点；必须有证据支撑。）

BadCase
（1~4 条需要改进/踩坑/滞后项；说明影响和下一步处理。）

下周推进计划
（3~6 条，按优先级排序，每条写清楚做什么、预期产出。）

风险项
（列出会影响下周推进的外部阻塞、依赖、人员变动等；无则写"暂无"。）

规则：
1. 用自然、正式的中文，像一个靠谱的项目助理在周会汇报
2. 总字数控制在 600~1200 字
3. 不要解释你的推理过程，不要写"根据以下信息"之类的引子
4. 任何字段如果没有证据支撑，直接写"暂无"，严禁虚构
5. 不要逐条复述原始消息，要归纳提炼
6. 如果整份动态只够写 2~3 行，也按模板输出，空字段写"暂无"
PROMPT;
    }

    private function buildDigest(ActivityBatch $batch, ReminderScanRequest $request): string
    {
        $parts = [];

        // Note: the reporting period is injected into the system prompt; we don't
        // add it to the digest so an empty batch correctly produces an empty digest
        // and short-circuits via the trim(empty) check above.

        if ($batch->calendar() !== []) {
            $lines = ['【本周日程】'];
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
            $lines = ['【本周消息摘要】'];
            // Group by chat to avoid noise
            $byChat = [];
            foreach ($batch->messages() as $msg) {
                $chatName = trim((string) ($msg['chat_name'] ?? '未知会话'));
                $byChat[$chatName][] = $msg;
            }
            foreach ($byChat as $chatName => $msgs) {
                $count = count($msgs);
                $senders = array_unique(array_filter(array_map(fn($m) => trim((string) ($m['sender'] ?? '')), $msgs)));
                $senderText = implode('、', array_slice($senders, 0, 5));
                // Show up to 3 message previews per chat to give the LLM real context
                $previews = [];
                foreach (array_slice($msgs, 0, 3) as $m) {
                    $preview = mb_substr(trim((string) ($m['text'] ?? '')), 0, 120);
                    if ($preview !== '') {
                        $previews[] = '· ' . $preview;
                    }
                }
                $previewBlock = $previews === [] ? '' : "\n  " . implode("\n  ", $previews);
                $lines[] = "- {$chatName}（{$count}条，参与者：{$senderText}）:{$previewBlock}";
            }
            $parts[] = implode("\n", $lines);
        }

        if ($batch->documents() !== []) {
            $lines = ['【本周文档活动】'];
            foreach ($batch->documents() as $doc) {
                $line = '- ' . trim((string) ($doc['title'] ?? '未命名文档'));
                if (!empty($doc['owner'])) {
                    $line .= ' | 所有者: ' . $doc['owner'];
                }
                if (!empty($doc['updated_at'])) {
                    $line .= ' | 更新: ' . $doc['updated_at'];
                }
                if (!empty($doc['url'])) {
                    $line .= ' | ' . $doc['url'];
                }
                $preview = trim((string) ($doc['preview'] ?? ''));
                if ($preview !== '') {
                    $line .= "\n  正文片段: " . mb_substr($preview, 0, 1500);
                }
                $lines[] = $line;
            }
            $parts[] = implode("\n", $lines);
        }

        if ($batch->sheets() !== []) {
            $lines = ['【本周飞书表格】'];
            foreach ($batch->sheets() as $sheet) {
                $line = '- ' . trim((string) ($sheet['title'] ?? $sheet['name'] ?? '未命名表格'));
                if (!empty($sheet['owner'])) {
                    $line .= ' | 所有者: ' . $sheet['owner'];
                }
                if (!empty($sheet['modified_time'])) {
                    $line .= ' | 更新: ' . $sheet['modified_time'];
                }
                if (!empty($sheet['url'])) {
                    $line .= ' | ' . $sheet['url'];
                }
                $lines[] = $line;
            }
            $parts[] = implode("\n", $lines);
        }

        if ($batch->bitables() !== []) {
            $lines = ['【本周多维表格】'];
            foreach ($batch->bitables() as $bitable) {
                $line = '- ' . trim((string) ($bitable['title'] ?? $bitable['name'] ?? '未命名多维表格'));
                if (!empty($bitable['owner'])) {
                    $line .= ' | 所有者: ' . $bitable['owner'];
                }
                if (!empty($bitable['modified_time'])) {
                    $line .= ' | 更新: ' . $bitable['modified_time'];
                }
                if (!empty($bitable['url'])) {
                    $line .= ' | ' . $bitable['url'];
                }
                $lines[] = $line;
            }
            $parts[] = implode("\n", $lines);
        }

        if ($batch->mails() !== []) {
            $lines = ['【本周邮件】'];
            foreach ($batch->mails() as $mail) {
                $line = '- ' . trim((string) ($mail['subject'] ?? '(无主题)'));
                if (!empty($mail['from'])) {
                    $line .= ' | 发件人: ' . $mail['from'];
                }
                if (!empty($mail['received_at'])) {
                    $line .= ' | ' . $mail['received_at'];
                }
                $preview = trim((string) ($mail['preview'] ?? $mail['snippet'] ?? ''));
                if ($preview !== '') {
                    $line .= "\n  正文片段: " . mb_substr($preview, 0, 400);
                }
                $lines[] = $line;
            }
            $parts[] = implode("\n", $lines);
        }

        if ($batch->meetings() !== []) {
            $lines = ['【本周会议】'];
            foreach ($batch->meetings() as $meeting) {
                $line = '- ' . trim((string) ($meeting['topic'] ?? '未命名会议'));
                if (!empty($meeting['start_time'])) {
                    $line .= ' | ' . $meeting['start_time'];
                }
                if (!empty($meeting['notes'])) {
                    $line .= "\n  纪要片段: " . mb_substr((string) $meeting['notes'], 0, 400);
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
                $lines[] = '本周已完成:';
                $lines = array_merge($lines, $completed);
            }
            if ($pending !== []) {
                $lines[] = '进行中/待完成:';
                $lines = array_merge($lines, $pending);
            }
            $parts[] = implode("\n", $lines);
        }

        return implode("\n\n", $parts);
    }
}
