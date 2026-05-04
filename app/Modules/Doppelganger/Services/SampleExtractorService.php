<?php

namespace App\Modules\Doppelganger\Services;

use App\Models\AttachmentChunk;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MemoryEntry;
use App\Models\MemoryFact;
use App\Modules\Doppelganger\Models\Doppelganger;
use App\Modules\Doppelganger\Models\DoppelgangerSample;
use App\Modules\Doppelganger\Models\DoppelgangerWorkflow;
use App\Services\LlmGatewayService;
use Illuminate\Support\Facades\Log;

/**
 * 离线一次性样本提取 —— 在 Doppelganger 激活时调用。
 *
 * 提取策略（按 Level 分组）：
 *   Level 1 知识：复用 attachment_chunks + memory_entries 的现有数据，无需另存（运行时直接 RAG）
 *   Level 2 风格：从 messages（用户发出的）+ memory_facts (preference 类) 提炼语气样本
 *   Level 3 工作流：从 conversations + attachments + messages 的时间分布识别周期性活动
 */
class SampleExtractorService
{
    public function __construct(
        private readonly LlmGatewayService $llm,
    ) {}

    public function extractAll(Doppelganger $dop): void
    {
        $userId = $dop->source_user_id;

        Log::info('[SampleExtractor] start', ['doppelganger_id' => $dop->id, 'user_id' => $userId]);

        // 清理可能的旧样本（如果是 reactivate）
        DoppelgangerSample::where('doppelganger_id', $dop->id)->delete();
        DoppelgangerWorkflow::where('doppelganger_id', $dop->id)->delete();

        $voiceCount = $this->extractVoiceSamples($dop, $userId);
        $workflowCount = $this->extractWorkflows($dop, $userId);
        $preferenceCount = $this->extractPreferences($dop, $userId);

        $dop->update([
            'meta' => array_merge((array) $dop->meta, [
                'samples_summary' => [
                    'voice' => $voiceCount,
                    'workflow' => $workflowCount,
                    'preference' => $preferenceCount,
                    'extracted_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        Log::info('[SampleExtractor] done', [
            'doppelganger_id' => $dop->id,
            'voice' => $voiceCount,
            'workflow' => $workflowCount,
            'preference' => $preferenceCount,
        ]);
    }

    /**
     * Level 2 风格样本：从该员工历史发送的 messages 中挑选有代表性的 N 条
     */
    private function extractVoiceSamples(Doppelganger $dop, int $userId): int
    {
        $messages = Message::query()
            ->where('user_id', $userId)
            ->where('role', 'user') // 该员工说的话
            ->whereNotNull('content')
            ->where(\DB::raw('CHAR_LENGTH(content)'), '>', 10) // 太短的不要
            ->orderByDesc('created_at')
            ->limit(200) // 取最近 200 条
            ->get(['id', 'content', 'created_at']);

        $count = 0;
        foreach ($messages as $msg) {
            DoppelgangerSample::create([
                'doppelganger_id' => $dop->id,
                'sample_type' => DoppelgangerSample::TYPE_VOICE,
                'context_summary' => mb_substr($msg->content, 0, 100),
                'content' => $msg->content,
                'score' => $this->scoreVoiceSample($msg->content),
                'meta' => ['source' => 'messages', 'message_id' => $msg->id, 'sent_at' => $msg->created_at?->toIso8601String()],
            ]);
            $count++;
        }
        return $count;
    }

    /**
     * Level 3 工作流：识别周期性活动模式
     *
     * V1 简单版：扫描 conversations.topic + messages 的时间分布，找重复主题
     * V2 可加入：calendar 重复事件、定时发出的特定格式消息（周报、日报等）
     */
    private function extractWorkflows(Doppelganger $dop, int $userId): int
    {
        $convs = Conversation::query()
            ->where('user_id', $userId)
            ->whereNotNull('topic')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id', 'topic', 'created_at']);

        // 按主题聚合，出现 ≥3 次的视为"周期性"
        $topicCounts = [];
        foreach ($convs as $conv) {
            $topic = trim((string) $conv->topic);
            if ($topic === '') continue;
            // 简化：用前 10 字做聚合 key
            $key = mb_substr($topic, 0, 10);
            $topicCounts[$key] = ($topicCounts[$key] ?? 0) + 1;
        }

        $count = 0;
        foreach ($topicCounts as $topicKey => $cnt) {
            if ($cnt < 3) continue; // 至少出现 3 次才算周期性

            DoppelgangerWorkflow::create([
                'doppelganger_id' => $dop->id,
                'workflow_name' => $topicKey . ' 类工作（共 ' . $cnt . ' 次记录）',
                'trigger_type' => DoppelgangerWorkflow::TRIGGER_MANUAL, // V1 不自动触发，仅记录
                'trigger_spec' => null,
                'template_content' => null,
                'sample_excerpt' => '过去出现次数：' . $cnt,
                'is_active' => true,
                'meta' => ['detected_topic_prefix' => $topicKey, 'occurrence_count' => $cnt],
            ]);
            $count++;
        }
        return $count;
    }

    /**
     * 偏好样本：从 memory_facts (preference 类) 直接复制
     */
    private function extractPreferences(Doppelganger $dop, int $userId): int
    {
        $facts = MemoryFact::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->limit(50)
            ->get(['id', 'category', 'fact']);

        $count = 0;
        foreach ($facts as $fact) {
            DoppelgangerSample::create([
                'doppelganger_id' => $dop->id,
                'sample_type' => DoppelgangerSample::TYPE_PREFERENCE,
                'context_summary' => $fact->category,
                'content' => $fact->fact,
                'score' => 1.0,
                'meta' => ['source' => 'memory_facts', 'fact_id' => $fact->id, 'category' => $fact->category],
            ]);
            $count++;
        }
        return $count;
    }

    /**
     * 给一条 voice sample 打分：长度 + 信息密度 启发式
     */
    private function scoreVoiceSample(string $content): float
    {
        $len = mb_strlen($content);
        if ($len < 20) return 0.2;
        if ($len < 60) return 0.5;
        if ($len < 200) return 0.8;
        return 0.6; // 太长可能是粘贴的，不是个人风格
    }
}
