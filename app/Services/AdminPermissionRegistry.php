<?php

namespace App\Services;

use App\Models\AdminPermission;
use Illuminate\Support\Collection;

class AdminPermissionRegistry
{
    public static function definitions(): array
    {
        return [
            ['key' => 'dashboard.view', 'group' => '仪表盘', 'label' => '查看仪表盘', 'description' => '查看运行指标、任务趋势和 Token 消耗。', 'sort_order' => 10],
            ['key' => 'users.view', 'group' => '用户管理', 'label' => '查看用户', 'description' => '查看成员列表、成员详情和基础数据。', 'sort_order' => 100],
            ['key' => 'users.sync', 'group' => '用户管理', 'label' => '同步飞书通讯录', 'description' => '手动触发飞书部门与成员同步。', 'sort_order' => 120],
            ['key' => 'users.toggle_active', 'group' => '用户管理', 'label' => '启用/停用用户', 'description' => '切换业务用户的启用状态。', 'sort_order' => 130],
            ['key' => 'skills.view', 'group' => '技能管理', 'label' => '查看技能', 'description' => '查看技能列表、详情、调用记录和文件清单。', 'sort_order' => 200],
            ['key' => 'skills.create', 'group' => '技能管理', 'label' => '新增技能', 'description' => '创建新的 Skill。', 'sort_order' => 210],
            ['key' => 'skills.update', 'group' => '技能管理', 'label' => '编辑技能', 'description' => '编辑技能名称、说明、状态和 skill.md。', 'sort_order' => 220],
            ['key' => 'skills.assign', 'group' => '技能管理', 'label' => '分配技能', 'description' => '配置技能可用部门和用户。', 'sort_order' => 230],
            ['key' => 'skills.status', 'group' => '技能管理', 'label' => '启用/停用技能', 'description' => '切换技能启用状态。', 'sort_order' => 240],
            ['key' => 'skills.files.manage', 'group' => '技能管理', 'label' => '管理技能文件', 'description' => '新建、编辑、删除技能附属文件。', 'sort_order' => 250],
            ['key' => 'skills.files.download', 'group' => '技能管理', 'label' => '下载技能文件', 'description' => '下载技能附属文件。', 'sort_order' => 260],
            ['key' => 'skills.delete', 'group' => '技能管理', 'label' => '删除技能', 'description' => '软删除技能（标记 deleted_at；可由 DBA 恢复）。高危操作。', 'sort_order' => 270],
            ['key' => 'memory.view', 'group' => '记忆中心', 'label' => '查看记忆', 'description' => '查看用户记忆、召回和记忆健康信息。', 'sort_order' => 300],
            ['key' => 'memory.repair', 'group' => '记忆中心', 'label' => '重新整理记忆', 'description' => '触发长期记忆整理。', 'sort_order' => 310],
            ['key' => 'memory.cleanup', 'group' => '记忆中心', 'label' => '清理过期记忆', 'description' => '按 TTL 规则标记过期近期事项。', 'sort_order' => 320],
            ['key' => 'audits.view', 'group' => '审计中心', 'label' => '查看审计', 'description' => '查看审计策略、日志与统计。', 'sort_order' => 400],
            ['key' => 'audits.export', 'group' => '审计中心', 'label' => '导出审计日志', 'description' => '导出审计日志 CSV。', 'sort_order' => 410],
            ['key' => 'audits.policies.manage', 'group' => '审计中心', 'label' => '管理审计策略', 'description' => '创建和修改审计策略。', 'sort_order' => 420],
            ['key' => 'audits.policies.delete', 'group' => '审计中心', 'label' => '删除审计策略', 'description' => '软删除审计策略（DB 行保留 deleted_at）。需要二次输入策略名确认。', 'sort_order' => 425],
            ['key' => 'settings.view', 'group' => '系统配置', 'label' => '查看系统配置', 'description' => '查看渠道、模型、企业和配额配置。', 'sort_order' => 500],
            ['key' => 'settings.channel.update', 'group' => '系统配置', 'label' => '保存渠道配置', 'description' => '修改飞书渠道配置。', 'sort_order' => 510],
            ['key' => 'settings.channel.test', 'group' => '系统配置', 'label' => '测试渠道连接', 'description' => '发起飞书连接测试。', 'sort_order' => 520],
            ['key' => 'settings.model.update', 'group' => '系统配置', 'label' => '保存模型配置', 'description' => '修改模型网关、API Key 和模型映射。', 'sort_order' => 530],
            ['key' => 'settings.model.test', 'group' => '系统配置', 'label' => '测试模型连接', 'description' => '发起模型连通性测试。', 'sort_order' => 540],
            ['key' => 'settings.enterprise.update', 'group' => '系统配置', 'label' => '保存企业配置', 'description' => '修改企业名称、Logo 和记忆窗口。', 'sort_order' => 550],
            ['key' => 'settings.quota.manage', 'group' => '系统配置', 'label' => '管理 Token 配额', 'description' => '修改总池、默认值和部门/用户配额。', 'sort_order' => 560],
            ['key' => 'admin_accounts.view', 'group' => '后台账号', 'label' => '查看后台账号', 'description' => '查看后台账号列表和权限配置。', 'sort_order' => 600],
            ['key' => 'admin_accounts.create', 'group' => '后台账号', 'label' => '新增后台账号', 'description' => '创建新的后台登录账号。', 'sort_order' => 610],
            ['key' => 'admin_accounts.update', 'group' => '后台账号', 'label' => '编辑后台账号', 'description' => '修改后台账号资料、超级管理员状态和权限。', 'sort_order' => 620],
            ['key' => 'admin_accounts.password', 'group' => '后台账号', 'label' => '重置后台账号密码', 'description' => '为其他后台账号设置新密码。', 'sort_order' => 630],
            ['key' => 'admin_accounts.toggle_active', 'group' => '后台账号', 'label' => '启用/停用后台账号', 'description' => '切换后台账号启用状态。', 'sort_order' => 640],
            ['key' => 'ops_log.view', 'group' => '系统配置', 'label' => '查看操作日志', 'description' => '查看后台所有用户的高危/写操作历史。', 'sort_order' => 570],
        ];
    }

    public function syncToDatabase(): void
    {
        foreach (self::definitions() as $definition) {
            AdminPermission::query()->updateOrCreate(
                ['permission_key' => $definition['key']],
                [
                    'group_name' => $definition['group'],
                    'label' => $definition['label'],
                    'description' => $definition['description'] ?? null,
                    'sort_order' => (int) ($definition['sort_order'] ?? 0),
                ]
            );
        }
    }

    public function groupedDefinitions(): array
    {
        return collect(self::definitions())
            ->sortBy('sort_order')
            ->groupBy('group')
            ->map(fn (Collection $items): array => $items->values()->all())
            ->all();
    }

    public function normalizeKeys(array $keys): array
    {
        $valid = array_flip($this->validKeys());

        return collect($keys)
            ->map(fn ($key): string => trim((string) $key))
            ->filter(fn (string $key): bool => isset($valid[$key]))
            ->unique()
            ->values()
            ->all();
    }

    public function validKeys(): array
    {
        return array_map(fn (array $definition): string => $definition['key'], self::definitions());
    }
}
