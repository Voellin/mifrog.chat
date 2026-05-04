<?php

namespace App\Modules\Doppelganger\Services;

use App\Modules\Doppelganger\Models\Doppelganger;
use App\Modules\Doppelganger\Models\DoppelgangerWorkflow;
use Illuminate\Support\Collection;

/**
 * Level 3：工作流模板 —— 列表 / 详情 / 手动触发。
 *
 * V1 版本只做"提醒接班人"，不主动执行。
 */
class WorkflowService
{
    public function listForDoppelganger(Doppelganger $dop): Collection
    {
        return DoppelgangerWorkflow::query()
            ->where('doppelganger_id', $dop->id)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * 给接班人生成"如果是 X，他通常会怎么做这类任务"的提醒文本
     */
    public function previewWorkflow(DoppelgangerWorkflow $wf): array
    {
        $dop = $wf->doppelganger;
        $sourceName = $dop->sourceUser?->name ?? '前同事';

        $body = "## 🔄 工作流提醒：{$wf->workflow_name}\n\n";
        $body .= "**关于**：{$sourceName} 过去的工作模式中识别到这一类周期性任务。\n\n";

        if ($wf->template_content) {
            $body .= "**参考模板**：\n{$wf->template_content}\n\n";
        }
        if ($wf->sample_excerpt) {
            $body .= "**历史片段**：\n{$wf->sample_excerpt}\n\n";
        }

        $body .= "---\n💡 这是基于历史数据的提醒。请你根据当前情况判断是否要继续做这件事，以及怎么做。";

        return [
            'name' => $wf->workflow_name,
            'body' => $body,
            'meta' => $wf->meta,
        ];
    }

    /**
     * cron 调用：扫描所有 active doppelganger 的 active workflow，
     *           到期的推送提醒（V1 版只记录 last_pushed_at，UI 上可看到提醒列表）
     */
    public function tickPush(): int
    {
        // V1 简化：不实际推送到飞书，只更新 last_pushed_at（让 admin UI 看见"今天有 X 条新提醒"）
        $count = 0;
        DoppelgangerWorkflow::query()
            ->whereHas('doppelganger', fn($q) => $q->where('status', Doppelganger::STATUS_ACTIVE))
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('last_pushed_at')
                  ->orWhere('last_pushed_at', '<', now()->subDay());
            })
            ->chunkById(50, function ($batch) use (&$count) {
                foreach ($batch as $wf) {
                    $wf->update(['last_pushed_at' => now()]);
                    $count++;
                }
            });
        return $count;
    }
}
