<?php

namespace App\Services;

use App\Models\AdminOperationLog;
use Illuminate\Http\Request;
use Throwable;

/**
 * 后台操作日志记录器：在 admin controller 的写/删动作里调用，
 * 记录"谁在什么时候做了什么"，方便追溯。
 *
 * 设计哲学：
 *  - 失败必须不能影响主操作（吞异常 + 留 PHP error_log 痕迹）
 *  - 谁的字段：admin_user 来自 request->attributes（EnsureAdminSession 注入）
 *  - 没登录场景（极少）也照样记，admin_user_id=null
 */
class AdminOperationLogger
{
    public static function log(Request $request, string $action, string $summary = '', array $context = []): void
    {
        try {
            $admin = $request->attributes->get('admin_user');
            $targetType = $context['target_type'] ?? null;
            $targetId = isset($context['target_id']) ? (int) $context['target_id'] : null;
            unset($context['target_type'], $context['target_id']);

            AdminOperationLog::query()->create([
                'admin_user_id' => $admin?->id,
                'admin_username' => $admin?->username,
                'action' => $action,
                'summary' => trim($summary) !== '' ? mb_substr($summary, 0, 500, 'UTF-8') : null,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'context' => $context !== [] ? $context : null,
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500, 'UTF-8'),
            ]);
        } catch (Throwable $e) {
            error_log('[AdminOperationLogger] failed to log action='.$action.': '.$e->getMessage());
        }
    }
}
