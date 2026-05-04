<?php

namespace App\Modules\Doppelganger\Services;

use App\Modules\Doppelganger\Models\Doppelganger;
use Illuminate\Support\Collection;

/**
 * 把分身调用结果格式化为飞书 markdown（schema 2.0 interactive card 渲染）。
 *
 * 设计要点：
 *  - 输出 markdown 文本，飞书侧用 markdown 元素渲染（**加粗**、列表、分隔线）
 *  - 任何回复末尾强制带"分身水印"，与正文用 `---` 分隔线 + 空行隔开
 *  - 用户在飞书 IM 看到的最后一段永远是"⚠️ 来自 ... 的数字分身（请甄别...）"
 *  - 即使飞书侧不渲染 markdown（fall back to plain text），原文也可读
 */
class DoppelgangerReplyFormatter
{
    /**
     * Level 1：知识问答回复
     */
    public function ask(Doppelganger $dop, string $answer, array $sources): string
    {
        $body = trim($answer) !== '' ? trim($answer) : '_（分身未给出回答）_';

        if (! empty($sources)) {
            $body .= "\n\n**来源**\n";
            $i = 1;
            foreach ($sources as $s) {
                $body .= "\n[" . $i . '] ' . $this->formatSource($s);
                $i++;
            }
        }

        return $body . $this->watermark($dop);
    }

    /**
     * Level 2：起草回复（VoiceService 已经在 draft 文本里加过自己的 watermark，
     * 这里把那段去掉换成统一的 watermark，避免重复）
     */
    public function draft(Doppelganger $dop, string $draft): string
    {
        // VoiceService::WATERMARK 用 \n\n--- 开头，去掉它
        $clean = preg_replace('/\n\n---\n⚠️.*$/us', '', $draft);
        $clean = trim((string) $clean);
        if ($clean === '') {
            $clean = '_（分身未生成草稿，可能是资料不足）_';
        }
        return "**✍️ 起草草稿**\n\n" . $clean . $this->watermark($dop);
    }

    /**
     * Level 3：单个 workflow 详情
     */
    public function workflow(Doppelganger $dop, array $preview): string
    {
        $body = $preview['body'] ?? '_（无内容）_';
        return $body . $this->watermark($dop);
    }

    /**
     * Level 3：列出全部可用 workflow（用户没指定具体 workflow 时）
     */
    public function workflowList(Doppelganger $dop, Collection $workflows): string
    {
        $body = "**📋 「{$dop->display_name}」的可用工作流**\n";
        foreach ($workflows as $i => $w) {
            $idx = $i + 1;
            $body .= "\n{$idx}. **{$w->workflow_name}**";
            if ($w->trigger_type) {
                $body .= "  _（触发：{$w->trigger_type}）_";
            }
        }
        $body .= "\n\n💡 用 `~{$dop->display_name}：run <工作流名>` 查看具体内容。";
        return $body . $this->watermark($dop);
    }

    /**
     * 错误/提示回复（也带尾注——让用户清楚这是分身系统返回的）
     */
    public function error(string $msg): string
    {
        return $msg . "\n\n---\n_— 数字分身助手 —_";
    }

    private function formatSource(array $s): string
    {
        $type = $s['type'] ?? 'unknown';
        if ($type === 'attachment') {
            return '文件「' . ($s['name'] ?? '?') . '」';
        }
        if ($type === 'memory') {
            $parts = [];
            if (! empty($s['layer'])) $parts[] = $s['layer'];
            if (! empty($s['date']))  $parts[] = $s['date'];
            $tag = $parts ? '（' . implode(' · ', $parts) . '）' : '';
            return '记忆' . $tag . '「' . ($s['title'] ?? '') . '」';
        }
        return json_encode($s, JSON_UNESCAPED_UNICODE);
    }

    private function watermark(Doppelganger $dop): string
    {
        $name = $dop->sourceUser?->name ?? $dop->display_name ?? '该员工';
        return "\n\n---\n\n⚠️ 来自「**{$name}**」的数字分身（基于历史飞书数据生成，可能不反映 ta 当前观点，请甄别后再做判断）";
    }
}
