<?php

namespace App\Services;

use App\Models\QuotaPolicy;
use App\Models\QuotaUsageLedger;
use App\Models\Run;
use App\Models\Setting;
use App\Models\User;
use DomainException;

class QuotaService
{
    /**
     * 校验用户是否还能继续消耗 $plannedTokens。
     *
     * C 方案（叠加）：两条约束独立校验，任一超限即抛 DomainException。
     *   1) per-user 上限：user policy > dept policy > default_monthly_quota_tokens Setting
     *      used 按对应 scope (user/department) 汇总。
     *   2) 全局池 (QuotaPolicy user_id=NULL AND department_id=NULL)：used 按全表汇总。
     *
     * 任一层 limit<=0 视为不启用该层（保持历史行为）。
     */
    public function assertWithinQuota(User $user, int $plannedTokens): void
    {
        $period = $this->periodKey();

        // Layer 1: per-user limit (user > dept > default setting)
        $perUser = $this->resolvePerUserLimit($user);
        if ($perUser['limit'] > 0) {
            $used = $this->sumUsage($perUser['scope'], $perUser['scope_id'], $period);
            if (($used + $plannedTokens) > $perUser['limit']) {
                throw new DomainException('当前用户 token 配额已用尽，请联系管理员分配更多额度。');
            }
        }

        // Layer 2: global pool (org-wide total cap)
        $poolLimit = $this->resolveGlobalPoolLimit();
        if ($poolLimit > 0) {
            $poolUsed = (int) QuotaUsageLedger::query()
                ->where('period_key', $period)
                ->sum('used_tokens');
            if (($poolUsed + $plannedTokens) > $poolLimit) {
                throw new DomainException('组织本月 Token 总池已用尽，请联系管理员增加总量。');
            }
        }
    }

    public function consume(Run $run, int $tokens): void
    {
        QuotaUsageLedger::query()->create([
            'user_id' => $run->user_id,
            'department_id' => $run->user?->department_id,
            'run_id' => $run->id,
            'used_tokens' => max(0, $tokens),
            'period_key' => $this->periodKey(),
        ]);
    }

    /**
     * 解析 per-user 维度的有效上限与统计 scope。
     * 返回 ['limit' => int, 'scope' => 'user'|'department', 'scope_id' => int]
     */
    public function resolvePerUserLimit(User $user): array
    {
        $userPolicy = QuotaPolicy::query()
            ->where('is_active', true)
            ->where('period', 'monthly')
            ->where('user_id', $user->id)
            ->first();
        if ($userPolicy) {
            return [
                'limit' => (int) $userPolicy->token_limit,
                'scope' => 'user',
                'scope_id' => (int) $user->id,
                'source' => 'user_policy',
            ];
        }

        if ($user->department_id) {
            $deptPolicy = QuotaPolicy::query()
                ->where('is_active', true)
                ->where('period', 'monthly')
                ->where('department_id', $user->department_id)
                ->whereNull('user_id')
                ->first();
            if ($deptPolicy) {
                return [
                    'limit' => (int) $deptPolicy->token_limit,
                    'scope' => 'department',
                    'scope_id' => (int) $user->department_id,
                    'source' => 'department_policy',
                ];
            }
        }

        $default = (int) Setting::read('default_monthly_quota_tokens', 0);
        return [
            'limit' => $default,
            'scope' => 'user',
            'scope_id' => (int) $user->id,
            'source' => 'default_setting',
        ];
    }

    /**
     * 解析全局池（组织总池）限额。无配置或未激活返回 0。
     */
    public function resolveGlobalPoolLimit(): int
    {
        $pool = QuotaPolicy::query()
            ->whereNull('user_id')
            ->whereNull('department_id')
            ->where('is_active', true)
            ->where('period', 'monthly')
            ->first();
        return $pool ? (int) $pool->token_limit : 0;
    }

    private function sumUsage(string $scope, int $scopeId, string $period): int
    {
        $query = QuotaUsageLedger::query()->where('period_key', $period);
        if ($scope === 'user') {
            $query->where('user_id', $scopeId);
        } elseif ($scope === 'department') {
            $query->where('department_id', $scopeId);
        }
        return (int) $query->sum('used_tokens');
    }

    private function periodKey(): string
    {
        return now()->format('Y-m');
    }
}
