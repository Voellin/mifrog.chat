<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\QuotaAlertLog;
use App\Models\QuotaPolicy;
use App\Models\QuotaUsageLedger;
use App\Models\User;
use App\Services\FeishuService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckLowQuota extends Command
{
    protected $signature = 'quota:check-low';
    protected $description = 'Check token quota usage and notify users via Feishu when remaining < 10% (idempotent per period/level)';

    public function handle(FeishuService $feishuService): int
    {
        $period = now()->format('Y-m');

        $globalPool = QuotaPolicy::query()
            ->whereNull('department_id')
            ->whereNull('user_id')
            ->where('is_active', true)
            ->first();

        if (! $globalPool || $globalPool->token_limit <= 0) {
            $this->info('No global token pool configured. Skipping.');
            return self::SUCCESS;
        }

        $notifiedCount = 0;

        // 1. Users with personal allocations
        $userPolicies = QuotaPolicy::query()
            ->whereNotNull('user_id')
            ->where('is_active', true)
            ->where('token_limit', '>', 0)
            ->get();

        $usersWithPolicy = [];

        foreach ($userPolicies as $policy) {
            $usersWithPolicy[$policy->user_id] = true;

            $used = (int) QuotaUsageLedger::query()
                ->where('user_id', $policy->user_id)
                ->where('period_key', $period)
                ->sum('used_tokens');

            $remaining = $policy->token_limit - $used;
            $threshold = $policy->token_limit * 0.10;

            if ($remaining <= $threshold && $remaining >= 0) {
                $user = User::find($policy->user_id);
                if ($user && $user->feishu_open_id) {
                    if (! $this->claimNotification($user->id, $period, 'user')) {
                        continue; // already notified this period at this level
                    }
                    $pct = $policy->token_limit > 0
                        ? round(($remaining / $policy->token_limit) * 100, 1)
                        : 0;
                    $text = "Token 配额提醒\n\n"
                        . "你好 {$user->name}，你本月的 Token 配额剩余不足 10%。\n"
                        . "已分配：" . number_format($policy->token_limit) . "\n"
                        . "已使用：" . number_format($used) . "\n"
                        . "剩余：" . number_format($remaining) . "（{$pct}%）\n\n"
                        . "如需增加配额，请联系管理员。";
                    $feishuService->pushTextToOpenId($user->feishu_open_id, $text);
                    $notifiedCount++;
                    Log::info('[QuotaCheck] Notified user (personal quota)', [
                        'user_id' => $user->id, 'remaining' => $remaining, 'limit' => $policy->token_limit,
                    ]);
                }
            }
        }

        // 2. Departments with allocations
        $deptPolicies = QuotaPolicy::query()
            ->whereNotNull('department_id')
            ->whereNull('user_id')
            ->where('is_active', true)
            ->where('token_limit', '>', 0)
            ->get();

        $usersInAllocatedDepts = [];

        foreach ($deptPolicies as $policy) {
            $used = (int) QuotaUsageLedger::query()
                ->where('department_id', $policy->department_id)
                ->where('period_key', $period)
                ->sum('used_tokens');

            $remaining = $policy->token_limit - $used;
            $threshold = $policy->token_limit * 0.10;

            if ($remaining <= $threshold && $remaining >= 0) {
                $dept = Department::find($policy->department_id);
                $deptUsers = User::query()
                    ->where('department_id', $policy->department_id)
                    ->where('is_active', true)
                    ->get();

                foreach ($deptUsers as $user) {
                    $usersInAllocatedDepts[$user->id] = true;
                    if (isset($usersWithPolicy[$user->id])) continue;
                    if ($user->feishu_open_id) {
                        if (! $this->claimNotification($user->id, $period, 'department')) {
                            continue;
                        }
                        $pct = $policy->token_limit > 0
                            ? round(($remaining / $policy->token_limit) * 100, 1)
                            : 0;
                        $deptName = $dept?->name ?? '你所在的部门';
                        $text = "部门 Token 配额提醒\n\n"
                            . "你好 {$user->name}，{$deptName}本月的 Token 配额剩余不足 10%。\n"
                            . "部门配额：" . number_format($policy->token_limit) . "\n"
                            . "已使用：" . number_format($used) . "\n"
                            . "剩余：" . number_format($remaining) . "（{$pct}%）\n\n"
                            . "如需增加配额，请联系管理员。";
                        $feishuService->pushTextToOpenId($user->feishu_open_id, $text);
                        $notifiedCount++;
                    }
                }
            }
        }

        // 3. Shared pool users (no personal or dept allocation)
        $totalUsed = (int) QuotaUsageLedger::query()
            ->where('period_key', $period)
            ->sum('used_tokens');

        $poolRemaining = $globalPool->token_limit - $totalUsed;
        $poolThreshold = $globalPool->token_limit * 0.10;

        if ($poolRemaining <= $poolThreshold && $poolRemaining >= 0) {
            $sharedUsers = User::query()
                ->where('is_active', true)
                ->whereNotNull('feishu_open_id')
                ->get()
                ->filter(function ($user) use ($usersWithPolicy, $usersInAllocatedDepts) {
                    return ! isset($usersWithPolicy[$user->id]) && ! isset($usersInAllocatedDepts[$user->id]);
                });

            $pct = $globalPool->token_limit > 0
                ? round(($poolRemaining / $globalPool->token_limit) * 100, 1)
                : 0;

            $poolNotified = 0;
            foreach ($sharedUsers as $user) {
                if (! $this->claimNotification($user->id, $period, 'global')) {
                    continue;
                }
                $text = "组织 Token 总量提醒\n\n"
                    . "你好 {$user->name}，组织本月的 Token 总量剩余不足 10%。\n"
                    . "总量：" . number_format($globalPool->token_limit) . "\n"
                    . "已使用：" . number_format($totalUsed) . "\n"
                    . "剩余：" . number_format($poolRemaining) . "（{$pct}%）\n\n"
                    . "如需增加配额，请联系管理员。";
                $feishuService->pushTextToOpenId($user->feishu_open_id, $text);
                $notifiedCount++;
                $poolNotified++;
            }

            if ($poolNotified > 0) {
                Log::info('[QuotaCheck] Notified shared-pool users', [
                    'count' => $poolNotified, 'pool_remaining' => $poolRemaining,
                ]);
            }
        }

        $this->info("Quota check completed. Notified {$notifiedCount} user(s).");
        return self::SUCCESS;
    }

    /**
     * 尝试声明一次 "本周期+该用户+该 level" 的提醒名额。
     * 利用 UNIQUE(user_id, period_key, level) + insertOrIgnore 做幂等：
     *  - 成功插入 (affected=1) 表示之前没发过，可以发；
     *  - 受 UNIQUE 冲突而忽略 (affected=0) 表示本周期本 level 已发过，本次跳过。
     */
    private function claimNotification(int $userId, string $period, string $level): bool
    {
        $now = now();
        $inserted = QuotaAlertLog::query()->insertOrIgnore([[
            'user_id' => $userId,
            'period_key' => $period,
            'level' => $level,
            'notified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]]);
        return $inserted > 0;
    }
}
