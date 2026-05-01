<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Detects platform resource URLs (Feishu, future DingTalk, etc.) in user messages
 * and generates tool-call hints so the LLM agent invokes the correct CLI tool
 * instead of guessing content from conversation history.
 *
 * Design: platform-specific URL patterns + tool mappings live in const arrays.
 * To add DingTalk support later, create DingtalkUrlEnricherService with the
 * same enrichFromMessage() interface and its own URL_TOOL_MAP / URL_PATTERN.
 */
class FeishuUrlEnricherService
{
    private const MAX_URLS_PER_MESSAGE = 3;

    /**
     * URL path segment → tool name + human label.
     * Covers all Feishu resource types that have a corresponding CLI skill.
     */
    private const URL_TOOL_MAP = [
        'docx'      => ['tool' => 'docs_read',      'label' => '文档'],
        'docs'      => ['tool' => 'docs_read',      'label' => '文档'],
        'wiki'      => ['tool' => 'wiki_manage',    'label' => '知识库'],
        'sheets'    => ['tool' => 'sheets_read',    'label' => '表格'],
        'base'      => ['tool' => 'base_manage',    'label' => '多维表格'],
        'drive'     => ['tool' => 'drive_manage',   'label' => '云文档'],
        'file'      => ['tool' => 'drive_manage',   'label' => '文件'],
        'mindnotes' => ['tool' => 'minutes_manage', 'label' => '思维笔记'],
        'slides'    => ['tool' => 'drive_manage',   'label' => '幻灯片'],
    ];

    // Matches Feishu resource URLs: wiki, docx, sheets, base, mindnotes, slides, etc.
    private const FEISHU_URL_PATTERN = '#https?://[a-z0-9]+\.feishu\.cn/(wiki|docx|docs|sheets|base|mindnotes|slides|drive|file)/([A-Za-z0-9_-]+)#';

    /**
     * Scan the latest user message for Feishu URLs and return a system-level
     * tool-call hint to prepend to the conversation.
     *
     * No content is pre-fetched — the hint instructs the LLM to call the
     * correct tool, which handles fetching via CLI internally.
     *
     * @param  int    $userId  The user's ID (unused now, kept for interface compat)
     * @param  string $text    The user's message text
     * @return string|null     Tool-call hint to inject, or null if no URLs found
     */
    public function enrichFromMessage(int $userId, string $text): ?string
    {
        $detected = $this->detectPlatformUrls($text);
        if ($detected === []) {
            return null;
        }

        return $this->buildToolHint($detected);
    }

    /**
     * Detect Feishu resource URLs and resolve each to its tool mapping.
     *
     * @return array<int, array{url: string, type: string, tool: string, label: string}>
     */
    private function detectPlatformUrls(string $text): array
    {
        if (preg_match_all(self::FEISHU_URL_PATTERN, $text, $matches, PREG_SET_ORDER) === false || $matches === []) {
            return [];
        }

        $seen = [];
        $results = [];

        foreach (array_slice($matches, 0, self::MAX_URLS_PER_MESSAGE) as $match) {
            $url = $match[0];
            $type = strtolower($match[1]);

            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $mapping = self::URL_TOOL_MAP[$type] ?? null;
            if ($mapping === null) {
                Log::debug('[FeishuUrlEnricher] Unknown URL type, skipping', ['url' => $url, 'type' => $type]);
                continue;
            }

            $results[] = [
                'url'   => $url,
                'type'  => $type,
                'tool'  => $mapping['tool'],
                'label' => $mapping['label'],
            ];
        }

        return $results;
    }

    /**
     * Build a system-level hint that instructs the LLM to call the right tool
     * for each detected URL. No document content is included.
     *
     * @param  array<int, array{url: string, type: string, tool: string, label: string}> $detected
     */
    private function buildToolHint(array $detected): string
    {
        $lines = ['用户消息中包含以下飞书资源链接，请使用对应工具处理：'];
        $hasWiki = false;

        foreach ($detected as $i => $item) {
            $num = $i + 1;
            if ($item['type'] === 'wiki') {
                $hasWiki = true;
                $lines[] = "{$num}. {$item['label']}: {$item['url']} → 第一步必须调用 wiki_manage(action=\"resolve_url\", url=\"{$item['url']}\")，拿到 raw_data.obj_type 与 raw_data.obj_token 之后，再按 obj_type 决定第二步：docx/doc → docs_read(doc_token=obj_token)；sheet → sheets_read(spreadsheet_token=obj_token)；bitable → base_manage(app_token=obj_token)；file/slides/mindnote → drive_manage(file_token=obj_token)。";
            } else {
                $lines[] = "{$num}. {$item['label']}: {$item['url']} → 请调用 {$item['tool']} 工具";
            }
        }

        $lines[] = '';
        $lines[] = '重要：必须调用上述工具获取真实内容后再回答用户。不要自行猜测或编造内容，不要基于对话历史推断文档内容。';
        if ($hasWiki) {
            $lines[] = '注意：wiki 链接是知识库节点的"包装"，本身不携带文档内容；必须先 resolve_url 把 wiki 节点解析成真实文档 token，再用对应的 read 工具取内容，不能跳过这一步直接调 docs_read。';
        }

        return implode("\n", $lines);
    }
}
