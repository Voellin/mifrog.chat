<?php

namespace App\Modules\Doppelganger\Services;

use App\Models\AttachmentChunk;
use App\Models\MemoryEntry;
use App\Modules\Doppelganger\Models\Doppelganger;
use App\Services\LlmGatewayService;
use Illuminate\Support\Collection;

/**
 * Level 1：知识检索 + LLM 总结。基于该员工的 attachment_chunks + memory_entries 做 RAG。
 *
 * 注意：复用现有 chunk / memory 数据，但 user_id 锁死为 source_user_id（接班人不会污染）。
 */
class KnowledgeService
{
    public function __construct(
        private readonly LlmGatewayService $llm,
    ) {}

    /**
     * 接班人提问 → 检索分身的语料 → LLM 总结回答 + 引用源
     *
     * @return array{answer: string, sources: array, token_input: int, token_output: int}
     */
    public function ask(Doppelganger $dop, string $question): array
    {
        $userId = $dop->source_user_id;

        // 1. 检索 attachment_chunks（按用户关键词粗排 + take 8）
        $keywords = $this->extractKeywords($question);
        $chunks = $this->retrieveChunks($userId, $keywords);
        $entries = $this->retrieveMemoryEntries($userId, $keywords);

        // 2. 拼 prompt（用编号资料块，让 LLM 在文中以 [1]/[2] 引用）
        $context = $this->buildContext($chunks, $entries);

        $sourceName = $dop->sourceUser?->name ?? "前同事 #{$userId}";
        $totalBlocks = $chunks->count() + $entries->count();
        $systemPrompt = "你是一位严谨的知识检索助手，正在帮调阅人查阅「{$sourceName}」的工作历史。\n\n"
            . "# 输出要求（金字塔结构 + Markdown 格式，必须严格遵守）\n\n"
            . "1. **结论先行**：第一段用 1-2 句话直接给出核心结论，关键词用 `**加粗**` 强调。如果资料里有明确结论，直接陈述；如果没有，直接说\"该员工的资料中未找到关于此事的明确结论\"。\n\n"
            . "2. **关键论据**：用 markdown 列表（`- ` 开头）列 2-3 条关键论据，每条 1-2 句话。每条论据末尾必须用 [1] [2] 这样的编号标注引用的资料块（编号见下方资料）。\n\n"
            . "3. **必要细节**（可选）：若问题确实需要更多上下文细节，在最后用一段话补充，同样要标注引用编号。\n\n"
            . "# 强约束\n"
            . "- 输出为 markdown 格式：**加粗** / *斜体* / `- ` 列表 / `\n\n` 段落分隔均会被飞书富文本卡片渲染。\n"
            . "- 只基于下方编号资料回答，**严禁编造**。资料里没有的信息直说\"未找到相关资料\"。\n"
            . "- 不要复述问题；不要写\"根据资料显示\"这种废话——直接给结论。\n"
            . "- 不要超过 4 段，不要超过 250 字（不含引用编号）。\n"
            . "- 引用编号必须真实对应——不要凭空写 [5] 但资料只有 4 块。";
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "【问题】{$question}\n\n【「{$sourceName}」的相关资料（共 {$totalBlocks} 块，已编号）】\n{$context}"],
        ];

        // 3. 调 LLM
        $response = $this->llm->chatWithTools($messages, []);
        $answer = (string) ($response['content'] ?? '');

        return [
            'answer' => $answer,
            'sources' => $this->formatSources($chunks, $entries),
            'token_input' => (int) ($response['input_tokens'] ?? 0),
            'token_output' => (int) ($response['output_tokens'] ?? 0),
        ];
    }

    private function extractKeywords(string $text): array
    {
        // 简化：用空格 + 标点切分，去掉过短的词
        $tokens = preg_split('/[\s,，。？?！!；;：:、\(\)（）]+/u', $text);
        $tokens = array_filter($tokens, fn($t) => mb_strlen(trim($t)) >= 2);
        return array_values(array_unique(array_map('mb_strtolower', $tokens)));
    }

    private function retrieveChunks(int $userId, array $keywords): Collection
    {
        $rows = AttachmentChunk::query()
            ->join('attachments', 'attachment_chunks.attachment_id', '=', 'attachments.id')
            ->where('attachment_chunks.user_id', $userId)
            ->where('attachments.parse_status', 'ready')
            ->select([
                'attachment_chunks.id',
                'attachment_chunks.attachment_id',
                'attachment_chunks.content',
                'attachments.file_name as attachment_file_name',
            ])
            ->latest('attachment_chunks.id')
            ->limit(2000)
            ->get();

        return $rows->map(function ($row) use ($keywords) {
            $score = 0.05;
            $hay = mb_strtolower((string) $row->content, 'UTF-8');
            foreach ($keywords as $kw) {
                if (mb_strpos($hay, $kw) !== false) $score += 1.0;
            }
            $row->score = $score;
            return $row;
        })->sortByDesc('score')->take(8)->values();
    }

    private function retrieveMemoryEntries(int $userId, array $keywords): Collection
    {
        $rows = MemoryEntry::query()
            ->where('user_id', $userId)
            ->whereNull('expired_at')
            ->latest('id')
            ->limit(500)
            ->get(['id', 'layer', 'title', 'content', 'source_date']);

        return $rows->map(function ($row) use ($keywords) {
            $score = 0.05;
            $hay = mb_strtolower((string) $row->content . ' ' . (string) $row->title, 'UTF-8');
            foreach ($keywords as $kw) {
                if (mb_strpos($hay, $kw) !== false) $score += 1.0;
            }
            $row->score = $score;
            return $row;
        })->sortByDesc('score')->take(5)->values();
    }

    private function buildContext(Collection $chunks, Collection $entries): string
    {
        $lines = [];
        $i = 1;
        foreach ($chunks as $chunk) {
            $lines[] = "[{$i}] 文件「{$chunk->attachment_file_name}」：" . mb_substr((string) $chunk->content, 0, 800);
            $i++;
        }
        foreach ($entries as $entry) {
            $tag = '记忆 ' . $entry->layer;
            if ($entry->source_date) {
                $tag .= ' · ' . $entry->source_date;
            }
            $title = $entry->title ? "「{$entry->title}」" : '';
            $lines[] = "[{$i}] {$tag}{$title}：" . mb_substr((string) $entry->content, 0, 600);
            $i++;
        }
        return implode("\n\n", $lines) ?: '（未找到相关资料）';
    }

    private function formatSources(Collection $chunks, Collection $entries): array
    {
        $sources = [];
        foreach ($chunks as $chunk) {
            $sources[] = ['type' => 'attachment', 'id' => $chunk->attachment_id, 'name' => $chunk->attachment_file_name];
        }
        foreach ($entries as $entry) {
            $sources[] = ['type' => 'memory', 'layer' => $entry->layer, 'title' => $entry->title, 'date' => $entry->source_date];
        }
        return $sources;
    }
}
