<?php

namespace App\Modules\Doppelganger\Services;

use App\Modules\Doppelganger\Models\Doppelganger;
use App\Modules\Doppelganger\Models\DoppelgangerSample;
use App\Services\LlmGatewayService;

/**
 * Level 2：用预提取的 voice + preference 样本做 few-shot prompt，
 *          按该员工的语气生成回复草稿（强制水印「数字分身草稿」）。
 */
class VoiceService
{
    public const WATERMARK = "\n\n---\n⚠️ 此草稿由「{name}」的数字分身基于历史数据生成，仅供参考。请审核后再发送。";

    public function __construct(
        private readonly LlmGatewayService $llm,
    ) {}

    /**
     * @return array{draft: string, samples_used: int, token_input: int, token_output: int}
     */
    public function draft(Doppelganger $dop, string $situation): array
    {
        // 1. 拉 voice 样本（按相关性 + 时间倒序，取前 N 条）
        $voiceSamples = DoppelgangerSample::query()
            ->where('doppelganger_id', $dop->id)
            ->where('sample_type', DoppelgangerSample::TYPE_VOICE)
            ->orderByDesc('score')
            ->orderByDesc('id')
            ->limit(8)
            ->get(['content', 'context_summary']);

        // 2. 拉偏好样本
        $prefs = DoppelgangerSample::query()
            ->where('doppelganger_id', $dop->id)
            ->where('sample_type', DoppelgangerSample::TYPE_PREFERENCE)
            ->limit(15)
            ->get(['content', 'context_summary']);

        $sourceName = $dop->sourceUser?->name ?? '该员工';

        // 3. 拼 few-shot prompt
        $voiceBlock = $voiceSamples->map(fn($s) => '- 「' . $s->content . '」')->implode("\n");
        $prefBlock = $prefs->map(fn($s) => "- [{$s->context_summary}] {$s->content}")->implode("\n");

        $messages = [
            ['role' => 'system', 'content' =>
                "你是一位文风模仿助手。下面是 {$sourceName} 的语气样本和个人偏好。\n请用 ta 的语气、措辞习惯、决策偏好，起草一份回复。\n\n" .
                "【{$sourceName} 的历史发言样本】\n{$voiceBlock}\n\n" .
                "【{$sourceName} 的个人偏好/习惯】\n{$prefBlock}\n\n" .
                "请只输出草稿正文，不要任何前言后语。如果情境不合适或资料不足，回复「【资料不足，无法准确模仿】」。"
            ],
            ['role' => 'user', 'content' => "【需要回复的情境】\n{$situation}"],
        ];

        $response = $this->llm->chatWithTools($messages, []);
        $rawDraft = trim((string) ($response['content'] ?? ''));

        // 4. 强制加水印
        $watermark = str_replace('{name}', $sourceName, self::WATERMARK);
        $finalDraft = $rawDraft . $watermark;

        return [
            'draft' => $finalDraft,
            'samples_used' => $voiceSamples->count() + $prefs->count(),
            'token_input' => (int) ($response['input_tokens'] ?? 0),
            'token_output' => (int) ($response['output_tokens'] ?? 0),
        ];
    }
}
