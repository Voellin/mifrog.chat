@extends('admin.layout')

@section('title', '米蛙管理后台 - 审计中心')
@section('page-title', '审计中心')
@section('page-desc', '监督机器人对话不泄密，集中查看策略、命中记录和风险趋势。')

@push('head')
<style>
.audit-center {
    color: var(--pro-text);
}
.audit-center,
.audit-center * {
    box-sizing: border-box;
}
.audit-hero {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: flex-start;
    margin-bottom: 26px;
}
.audit-title h1 {
    margin: 0;
    color: #0f172a;
    font-size: 26px;
    line-height: 1.2;
    letter-spacing: 0;
}
.audit-title p {
    margin: 8px 0 0;
    color: var(--pro-text-secondary);
    font-size: 13px;
    line-height: 1.6;
}
.audit-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    justify-content: flex-end;
}
.audit-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 34px;
    border: 1px solid var(--pro-border-strong);
    border-radius: 8px;
    background: #fff;
    color: #2b241d;
    padding: 0 13px;
    font-size: 13px;
    font-weight: 800;
    line-height: 1;
    text-decoration: none;
    cursor: pointer;
}
.audit-btn-primary {
    border-color: var(--pro-primary);
    background: var(--pro-primary);
    color: #fff;
}
.audit-btn-danger {
    border-color: #fecaca;
    background: #fff1f2;
    color: #b91c1c;
}
.audit-btn:hover {
    text-decoration: none;
    filter: brightness(0.98);
}
.audit-kpis {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    overflow: hidden;
    border: 1px solid var(--pro-border-strong);
    border-radius: 12px;
    background: var(--pro-surface);
    box-shadow: 0 10px 28px rgba(15, 23, 42, 0.04);
    margin-bottom: 16px;
}
.audit-kpi {
    min-width: 0;
    border-right: 1px solid var(--pro-border);
    padding: 17px 18px;
}
.audit-kpi:last-child {
    border-right: 0;
}
.audit-kpi-label {
    color: #6f665c;
    font-size: 12px;
    line-height: 1.2;
}
.audit-kpi-value {
    display: flex;
    gap: 8px;
    align-items: baseline;
    margin-top: 9px;
    color: #020617;
    font-size: 26px;
    font-weight: 900;
    line-height: 1;
}
.audit-kpi-value.danger {
    color: var(--pro-error);
}
.audit-kpi-value.warn {
    color: var(--pro-warning);
}
.audit-kpi-delta {
    color: var(--pro-error);
    font-size: 12px;
    font-weight: 800;
}
.audit-kpi-hint {
    margin-top: 8px;
    color: var(--pro-text-secondary);
    font-size: 12px;
    line-height: 1.45;
}
.audit-panel {
    overflow: hidden;
    border: 1px solid var(--pro-border-strong);
    border-radius: 12px;
    background: var(--pro-surface);
    box-shadow: 0 10px 28px rgba(15, 23, 42, 0.04);
}
/* Q1-fix: 审计中心 tab 视觉对齐到 .model-tab 的标准实现 */
.audit-tabs {
    display: flex;
    align-items: stretch;
    gap: 0;
    overflow-x: auto;
    border-bottom: 1px solid var(--pro-border);
    padding: 0;
}
.audit-tab {
    border: 0;
    border-radius: 0;
    background: transparent;
    color: var(--pro-text-secondary);
    padding: 13px 18px;
    font-size: 13px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border-bottom: 2px solid transparent;
    white-space: nowrap;
    cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
    outline: none;
}
.audit-tab:hover {
    background: var(--pro-surface-soft);
    color: var(--pro-text);
}
.audit-tab:focus-visible {
    outline: 2px solid var(--pro-primary);
    outline-offset: -2px;
}
.audit-tab.active {
    color: var(--pro-primary-hover);
    border-bottom-color: var(--pro-primary);
    background: var(--pro-surface);
}
.audit-tab-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    border-radius: 999px;
    background: var(--pro-surface-soft);
    color: var(--pro-text-secondary);
    padding: 0 7px;
    font-size: 11px;
    font-variant-numeric: tabular-nums;
    transition: background 0.15s ease, color 0.15s ease;
}
.audit-tab.active .audit-tab-count {
    color: var(--pro-primary-hover);
    background: var(--pro-primary-soft);
}
.audit-tab-panel {
    display: none;
    padding: 22px 24px;
}
.audit-tab-panel.active {
    display: block;
}
.audit-section-head {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: center;
    margin-bottom: 12px;
}
.audit-section-head h2,
.audit-section-head h3 {
    margin: 0;
    color: #111827;
    font-size: 15px;
    line-height: 1.3;
}
.audit-section-head p {
    margin: 4px 0 0;
    color: var(--pro-text-secondary);
    font-size: 12px;
    line-height: 1.5;
}
.audit-link-btn {
    border: 0;
    background: transparent;
    color: var(--pro-primary-hover);
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
    white-space: nowrap;
}
.audit-table-wrap {
    width: 100%;
    overflow: auto;
}
.audit-table {
    width: 100%;
    min-width: 860px;
    border-collapse: collapse;
}
.audit-table th,
.audit-table td {
    border-bottom: 1px solid var(--pro-border);
    padding: 12px 14px;
    color: #211d19;
    font-size: 13px;
    line-height: 1.5;
    text-align: left;
    vertical-align: top;
}
.audit-table th {
    background: #f6f5f1;
    color: #6f665c;
    font-size: 12px;
    font-weight: 800;
    white-space: nowrap;
}
.audit-table tbody tr:hover td {
    background: #fcfbf8;
}
.audit-time {
    color: #6f665c;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 12px;
    white-space: nowrap;
}
.audit-stage {
    display: inline-flex;
    align-items: center;
    height: 22px;
    border-radius: 999px;
    padding: 0 8px;
    font-size: 12px;
    font-weight: 900;
}
.audit-stage.input {
    background: #fff4cf;
    color: #b45309;
}
.audit-stage.output {
    background: #e0f2fe;
    color: #0369a1;
}
.audit-decision {
    display: inline-flex;
    align-items: center;
    height: 22px;
    border-radius: 5px;
    padding: 0 7px;
    font-size: 11px;
    font-weight: 900;
    white-space: nowrap;
}
.audit-decision.blocked {
    background: #fee2e2;
    color: #b91c1c;
}
.audit-decision.masked {
    background: #fff1c2;
    color: #a16207;
}
.audit-decision.pass {
    background: #dcfce7;
    color: #15803d;
}
.audit-decision.nohit {
    background: #f1f5f9;
    color: #64748b;
}
.audit-term {
    display: inline-flex;
    align-items: center;
    min-height: 21px;
    border-radius: 4px;
    background: #d8fae8;
    color: #007a57;
    padding: 0 6px;
    margin: 2px 4px 2px 0;
    font-size: 11px;
    font-weight: 800;
}
.audit-policy-name {
    font-weight: 900;
}
.audit-content {
    max-width: 520px;
    overflow-wrap: anywhere;
}
.audit-empty {
    border: 1px dashed #d8d0c7;
    border-radius: 8px;
    background: #fffdfa;
    padding: 16px;
    color: #7c7368;
    font-size: 13px;
    line-height: 1.6;
}
.audit-overview-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.05fr) minmax(300px, 0.95fr);
    gap: 16px;
    margin-top: 18px;
}
.audit-card {
    border: 1px solid var(--pro-border);
    border-radius: 10px;
    background: #fffdfa;
    padding: 16px;
}
.audit-card h3 {
    margin: 0 0 5px;
    color: #111827;
    font-size: 15px;
}
.audit-card p {
    margin: 0 0 12px;
    color: var(--pro-text-secondary);
    font-size: 12px;
    line-height: 1.55;
}
.audit-rank-list {
    display: grid;
    gap: 9px;
}
.audit-rank-item {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 12px;
    align-items: center;
    border-bottom: 1px dashed var(--pro-border);
    padding-bottom: 9px;
    color: #211d19;
    font-size: 13px;
}
.audit-rank-item:last-child {
    border-bottom: 0;
    padding-bottom: 0;
}
.audit-rank-item strong {
    color: var(--pro-primary-hover);
}
.audit-create-box {
    border: 1px solid var(--pro-border);
    border-radius: 10px;
    background: #fffdfa;
    margin-bottom: 16px;
}
.audit-create-box summary {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: center;
    padding: 14px 16px;
    cursor: pointer;
    color: #111827;
    font-size: 14px;
    font-weight: 900;
}
.audit-create-body {
    border-top: 1px solid var(--pro-border);
    padding: 16px;
}
.audit-template-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 14px;
}
.audit-template {
    display: grid;
    gap: 5px;
    border: 1px solid var(--pro-border-strong);
    border-radius: 9px;
    background: #fff;
    padding: 12px;
    cursor: pointer;
}
.audit-template:hover {
    border-color: var(--pro-primary);
}
.audit-template strong {
    color: #111827;
    font-size: 13px;
}
.audit-template span {
    color: var(--pro-text-secondary);
    font-size: 12px;
    line-height: 1.5;
}
.audit-form-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}
.audit-form-grid.two {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}
.audit-field label {
    display: block;
    margin-bottom: 5px;
    color: #5f574f;
    font-size: 12px;
    font-weight: 800;
}
.audit-field small {
    color: #8a8176;
    font-weight: 500;
}
.audit-field input,
.audit-field select,
.audit-field textarea {
    width: 100%;
    min-width: 0;
    border: 1px solid #d8d0c7;
    border-radius: 8px;
    background: #fff;
    color: #111827;
    padding: 0 10px;
    font-size: 13px;
}
.audit-field input,
.audit-field select {
    height: 36px;
}
.audit-field textarea {
    min-height: 76px;
    padding: 9px 10px;
    resize: vertical;
}
.audit-form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    align-items: center;
    margin-top: 12px;
}
.audit-check {
    display: inline-flex;
    gap: 6px;
    align-items: center;
    margin-right: auto;
    color: #3f3933;
    font-size: 13px;
}
.audit-policy-list {
    display: grid;
    gap: 10px;
}
.audit-policy-card {
    border: 1px solid var(--pro-border);
    border-radius: 10px;
    background: #fffdfa;
    overflow: hidden;
}
.audit-policy-summary {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 12px;
    align-items: center;
    padding: 14px 16px;
    cursor: pointer;
}
.audit-policy-summary:hover {
    background: #fcfbf7;
}
.audit-policy-title {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    min-width: 0;
}
.audit-policy-title h4 {
    margin: 0;
    color: #111827;
    font-size: 14px;
}
.audit-pill {
    display: inline-flex;
    align-items: center;
    height: 22px;
    border-radius: 999px;
    background: #f1f0eb;
    color: #4f463d;
    padding: 0 8px;
    font-size: 11px;
    font-weight: 900;
}
.audit-pill.ok {
    background: #dcfce7;
    color: #15803d;
}
.audit-pill.off {
    background: #f1f5f9;
    color: #64748b;
}
.audit-policy-body {
    display: none;
    border-top: 1px solid var(--pro-border);
    padding: 16px;
}
.audit-policy-card.open .audit-policy-body {
    display: block;
}
.audit-filter-row {
    display: grid;
    grid-template-columns: repeat(5, minmax(110px, 1fr));
    gap: 10px;
    align-items: end;
    margin-bottom: 14px;
}
.audit-filter-actions {
    display: flex;
    gap: 8px;
    align-items: end;
}
.audit-pager {
    margin-top: 12px;
}
.audit-pager .pagination {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    justify-content: flex-end;
    align-items: center;
    margin: 0;
    padding: 0;
    list-style: none;
}
.audit-pager .page-item {
    display: inline-flex;
}
.audit-pager .pagination a,
.audit-pager .pagination span {
    min-width: 28px;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--pro-border-strong);
    border-radius: 6px;
    padding: 0 8px;
    color: #2d2925;
    font-size: 12px;
    text-decoration: none;
    background: #fff;
    line-height: 1;
}
.audit-pager .pagination a:hover {
    border-color: var(--pro-primary);
    color: var(--pro-primary);
}
.audit-pager .pagination .disabled span {
    color: #94a3b8;
    background: #f6f8fa;
    cursor: not-allowed;
}
.audit-pager .pagination .active span {
    border-color: var(--pro-primary);
    background: var(--pro-primary);
    color: #fff;
}
.audit-stats-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}
.audit-is-hidden {
    display: none !important;
}
@media (max-width: 1100px) {
    .audit-kpis {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .audit-kpi:nth-child(2) {
        border-right: 0;
    }
    .audit-kpi:nth-child(-n + 2) {
        border-bottom: 1px solid var(--pro-border);
    }
    .audit-overview-grid,
    .audit-stats-grid {
        grid-template-columns: 1fr;
    }
    .audit-filter-row {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 760px) {
    .audit-hero {
        display: grid;
    }
    .audit-actions {
        justify-content: flex-start;
    }
    .audit-kpis,
    .audit-template-grid,
    .audit-form-grid,
    .audit-form-grid.two,
    .audit-filter-row {
        grid-template-columns: 1fr;
    }
    .audit-kpi {
        border-right: 0 !important;
        border-bottom: 1px solid var(--pro-border);
    }
    .audit-kpi:last-child {
        border-bottom: 0;
    }
    .audit-tab-panel {
        padding: 16px;
    }
}
</style>
@endpush

@section('content')
@php
    $relTime = function ($carbon) {
        if (! $carbon) {
            return null;
        }
        try {
            $c = $carbon instanceof \Carbon\Carbon ? $carbon : \Carbon\Carbon::parse($carbon);
            return $c->diffForHumans(['parts' => 1, 'short' => false]);
        } catch (\Throwable $e) {
            return null;
        }
    };
    $fmtDate = function ($carbon, string $format = 'Y-m-d H:i') {
        if (! $carbon) {
            return '-';
        }
        try {
            $c = $carbon instanceof \Carbon\Carbon ? $carbon : \Carbon\Carbon::parse($carbon);
            return $c->timezone('Asia/Shanghai')->format($format);
        } catch (\Throwable $e) {
            return '-';
        }
    };
    $todayBattle = $todayBattle ?? [
        'today_hit_total' => 0,
        'today_blocked' => 0,
        'today_masked' => 0,
        'today_pass_on_hit' => 0,
        'last_hit_at' => null,
        'last_hit_decision' => null,
        'top_policies' => [],
        'recent_hits' => collect(),
    ];
    $policyTotal = (int) ($summary['policy_total'] ?? 0);
    $activePolicyTotal = (int) ($summary['active_policy_total'] ?? 0);
    $inactivePolicyTotal = max(0, $policyTotal - $activePolicyTotal);
    $healthIssues = ($policyTotal === 0 ? 1 : 0) + $inactivePolicyTotal + ((int) ($summary['department_policy_total'] ?? 0) === 0 ? 1 : 0);
    $healthScore = $policyTotal > 0 ? max(0, min(100, 82 + $activePolicyTotal * 4 - $inactivePolicyTotal * 8)) : 0;
    $healthHint = $healthIssues > 0 ? $healthIssues.' 项可优化' : '状态良好';
    $recentOverview = collect($todayBattle['recent_hits'] ?? [])->take(8);
    $decisionInfo = function (?string $decision, bool $hit = true): array {
        if (! $hit) {
            return ['未命中', 'nohit'];
        }
        return [
            'blocked' => ['已挡下', 'blocked'],
            'masked' => ['已打码', 'masked'],
            'pass' => ['放行', 'pass'],
        ][$decision ?: ''] ?? [$decision ?: '-', 'nohit'];
    };
    $stageInfo = function (?string $stage): array {
        return $stage === 'input' ? ['提问', 'input'] : ['回复', 'output'];
    };
    $displayUserName = function ($record): string {
        $name = trim((string) ($record->user?->name ?? ''));
        return $name !== '' ? $name : '用户#'.(int) ($record->user_id ?? 0);
    };
    $activeTab = request()->query('_tab', '');
    if ($activeTab === '') {
        $activeTab = (request()->has('stage') || request()->has('hit') || request()->has('decision') || request()->has('policy_id')) ? 'log' : 'overview';
    }
@endphp

<div class="audit-center">
    <section class="audit-hero">
        <div class="audit-title">
            <h1>审计中心</h1>
            <p>监督机器人对话不泄密。当前生效 {{ $activePolicyTotal }} 条策略，今日已处理 {{ (int) $todayBattle['today_hit_total'] }} 条命中记录。</p>
        </div>
        <div class="audit-actions">
            @adminCan('audits.export')
                <a class="audit-btn" href="/admin/audits/export?range=7d">导出 7 天报告</a>
            @endadminCan
            @adminCan('audits.policies.manage')
                <button type="button" class="audit-btn audit-btn-primary" data-open-create>新建策略</button>
            @endadminCan
        </div>
    </section>

    <section class="audit-kpis" aria-label="审计概览">
        <div class="audit-kpi">
            <div class="audit-kpi-label">今天共命中</div>
            <div class="audit-kpi-value">{{ (int) $todayBattle['today_hit_total'] }}</div>
            <div class="audit-kpi-hint">触发审计策略的消息条数</div>
        </div>
        <div class="audit-kpi">
            <div class="audit-kpi-label">被直接挡下</div>
            <div class="audit-kpi-value danger">{{ (int) $todayBattle['today_blocked'] }}</div>
            <div class="audit-kpi-hint">根本没发出去 / 没回复</div>
        </div>
        <div class="audit-kpi">
            <div class="audit-kpi-label">自动打码</div>
            <div class="audit-kpi-value warn">{{ (int) $todayBattle['today_masked'] }}</div>
            <div class="audit-kpi-hint">敏感部分替换成 ****</div>
        </div>
        <div class="audit-kpi">
            <div class="audit-kpi-label">策略健康度</div>
            <div class="audit-kpi-value">{{ $healthScore }} / 100</div>
            <div class="audit-kpi-hint">{{ $healthHint }}</div>
        </div>
    </section>

    <section class="audit-panel">
        <nav class="audit-tabs" aria-label="审计中心标签页">
            <button class="audit-tab {{ $activeTab === 'overview' ? 'active' : '' }}" type="button" data-tab="overview">总览</button>
            <button class="audit-tab {{ $activeTab === 'policy' ? 'active' : '' }}" type="button" data-tab="policy">策略 <span class="audit-tab-count">{{ $policyTotal }}</span></button>
            <button class="audit-tab {{ $activeTab === 'log' ? 'active' : '' }}" type="button" data-tab="log">审计日志</button>
            <button class="audit-tab {{ $activeTab === 'stats' ? 'active' : '' }}" type="button" data-tab="stats">统计分析</button>
        </nav>

        <div class="audit-tab-panel {{ $activeTab === 'overview' ? 'active' : '' }}" id="audit-panel-overview">
            <div class="audit-section-head">
                <div>
                    <h2>最近命中明细</h2>
                    <p>优先展示最近被策略命中的提问和回复，便于快速判断是否需要调整规则。</p>
                </div>
                <button type="button" class="audit-link-btn" data-switch-tab="log">转到完整日志 →</button>
            </div>

            <div class="audit-table-wrap">
                <table class="audit-table">
                    <thead>
                        <tr>
                            <th style="width:130px;">时间</th>
                            <th style="width:82px;">阶段</th>
                            <th style="width:190px;">命中策略 / 词</th>
                            <th>用户 / 内容</th>
                            <th style="width:86px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentOverview as $record)
                            @php
                                [$stageLabel, $stageClass] = $stageInfo($record->stage);
                                $policyNames = is_array($record->matched_policy_names) ? $record->matched_policy_names : [];
                                $matchedTerms = is_array($record->matched_terms) ? $record->matched_terms : [];
                                $userName = $displayUserName($record);
                            @endphp
                            <tr>
                                <td><span class="audit-time">{{ $fmtDate($record->created_at, 'Y-m-d H:i') }}</span></td>
                                <td><span class="audit-stage {{ $stageClass }}">{{ $stageLabel }}</span></td>
                                <td>
                                    <div class="audit-policy-name">{{ !empty($policyNames) ? implode(' / ', array_slice($policyNames, 0, 2)) : '-' }}</div>
                                    @foreach(array_slice($matchedTerms, 0, 3) as $term)
                                        <span class="audit-term">{{ $term }}</span>
                                    @endforeach
                                </td>
                                <td class="audit-content"><strong>{{ $userName }}</strong> · {{ $record->content_excerpt ?: '-' }}</td>
                                <td><button type="button" class="audit-link-btn" data-switch-tab="log">查看</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><div class="audit-empty">最近没有命中记录。</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="audit-overview-grid">
                <div class="audit-card">
                    <h3>今天命中最多的策略</h3>
                    <p>这些规则今天被踩到的次数最多，优先关注误杀和漏放。</p>
                    <div class="audit-rank-list">
                        @forelse(($todayBattle['top_policies'] ?? []) as $item)
                            <div class="audit-rank-item">
                                <span>{{ $item['name'] ?? '策略' }}</span>
                                <strong>{{ (int) ($item['hit_count'] ?? 0) }} 次</strong>
                            </div>
                        @empty
                            <div class="audit-empty">今天还没有策略命中。</div>
                        @endforelse
                    </div>
                </div>
                <div class="audit-card">
                    <h3>策略配置概况</h3>
                    <p>从全局和部门两个层面看当前审计覆盖情况。</p>
                    <div class="audit-rank-list">
                        <div class="audit-rank-item"><span>全部策略</span><strong>{{ $policyTotal }}</strong></div>
                        <div class="audit-rank-item"><span>启用策略</span><strong>{{ $activePolicyTotal }}</strong></div>
                        <div class="audit-rank-item"><span>全局策略</span><strong>{{ (int) ($summary['global_policy_total'] ?? 0) }}</strong></div>
                        <div class="audit-rank-item"><span>部门策略</span><strong>{{ (int) ($summary['department_policy_total'] ?? 0) }}</strong></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="audit-tab-panel {{ $activeTab === 'policy' ? 'active' : '' }}" id="audit-panel-policy">
            @adminCan('audits.policies.manage')
                <details class="audit-create-box" id="audit-create-box">
                    <summary>
                        <span>新建策略</span>
                        <span class="audit-pill">从模板开始，或自定义</span>
                    </summary>
                    <div class="audit-create-body">
                        <div class="audit-template-grid">
                            <button class="audit-template" type="button" data-template="idcard">
                                <strong>身份证 / 手机号</strong>
                                <span>包含身份证号、手机号、银行卡号，输出自动打码，输入可阻断。</span>
                            </button>
                            <button class="audit-template" type="button" data-template="finance">
                                <strong>财务 / 合同金额</strong>
                                <span>工资、合同金额、客户回款等关键词，适合配给财务部门。</span>
                            </button>
                            <button class="audit-template" type="button" data-template="blank">
                                <strong>自定义策略</strong>
                                <span>从空表单开始，自己写规则名、作用范围和敏感词。</span>
                            </button>
                        </div>

                        <form method="post" action="/admin/audits/policies" id="audit-create-form">
                            @csrf
                            <div class="audit-form-grid">
                                <div class="audit-field">
                                    <label>策略名称</label>
                                    <input type="text" name="name" id="audit-create-name" required placeholder="例如：财务部门客户信息保护">
                                </div>
                                <div class="audit-field">
                                    <label>作用范围 <small>全局或指定部门</small></label>
                                    <select name="scope_type" id="audit-create-scope" data-scope-select required>
                                        <option value="global">全局（所有人）</option>
                                        <option value="department">指定部门</option>
                                    </select>
                                </div>
                                <div class="audit-field" data-scope-department style="display:none;">
                                    <label>目标部门</label>
                                    <select name="department_id" id="audit-create-dept">
                                        <option value="">请选择部门</option>
                                        @foreach($departments as $department)
                                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="audit-form-grid" style="margin-top:12px;">
                                <div class="audit-field">
                                    <label>用户提问时</label>
                                    <select name="input_action" id="audit-create-input" required>
                                        <option value="allow">放行</option>
                                        <option value="block" selected>直接挡掉</option>
                                    </select>
                                </div>
                                <div class="audit-field">
                                    <label>机器人回复时</label>
                                    <select name="output_action" id="audit-create-output" required>
                                        <option value="allow">仅记下来</option>
                                        <option value="mask" selected>自动打码</option>
                                        <option value="block">整段不回复</option>
                                    </select>
                                </div>
                                <div class="audit-field">
                                    <label>优先级 <small>数字越大越优先</small></label>
                                    <input type="number" name="priority" id="audit-create-priority" value="100" min="0" max="100000">
                                </div>
                            </div>
                            <div class="audit-form-grid two" style="margin-top:12px;">
                                <div class="audit-field">
                                    <label>被挡下来时提示语</label>
                                    <input type="text" name="blocked_message" id="audit-create-message" value="内容触发企业合规策略，已被拦截。">
                                </div>
                                <div class="audit-field">
                                    <label>敏感词列表 <small>每行一个，或逗号分隔</small></label>
                                    <textarea name="terms_text" id="audit-create-terms" placeholder="例如：&#10;身份证号&#10;银行卡号"></textarea>
                                </div>
                            </div>
                            <div class="audit-form-actions">
                                <label class="audit-check"><input type="checkbox" name="is_active" value="1" checked> 创建后立即启用</label>
                                <button type="submit" class="audit-btn audit-btn-primary">创建策略</button>
                            </div>
                        </form>
                    </div>
                </details>
            @else
                <div class="audit-empty" style="margin-bottom:16px;">当前账号没有创建审计策略权限。</div>
            @endadminCan

            <div class="audit-section-head">
                <div>
                    <h3>已配置策略</h3>
                    <p>点开策略可以查看和编辑规则、作用范围、命中后的处理动作。</p>
                </div>
                <span class="audit-pill">{{ $activePolicyTotal }} 启用 / {{ $policyTotal }} 全部</span>
            </div>

            <div class="audit-policy-list">
                @forelse($policies as $policy)
                    @php $termsText = $policy->terms->pluck('term')->filter()->implode("\n"); @endphp
                    <article class="audit-policy-card" id="policy-{{ $policy->id }}">
                        <div class="audit-policy-summary" data-toggle-policy>
                            <div class="audit-policy-title">
                                <h4>{{ $policy->name }}</h4>
                                <span class="audit-pill {{ $policy->is_active ? 'ok' : 'off' }}">{{ $policy->is_active ? '启用' : '停用' }}</span>
                                <span class="audit-pill">{{ $policy->scope_type === 'department' ? '部门' : '全局' }}</span>
                                <span class="audit-pill">{{ $policy->terms->count() }} 个敏感词</span>
                            </div>
                            <span class="audit-pill">展开编辑</span>
                        </div>
                        <div class="audit-policy-body">
                            @adminCan('audits.policies.manage')
                                <form method="post" action="/admin/audits/policies/{{ $policy->id }}">
                                    @csrf
                                    <div class="audit-form-grid">
                                        <div class="audit-field">
                                            <label>策略名称</label>
                                            <input type="text" name="name" value="{{ $policy->name }}" required>
                                        </div>
                                        <div class="audit-field">
                                            <label>作用范围</label>
                                            <select name="scope_type" data-scope-select required>
                                                <option value="global" {{ $policy->scope_type === 'global' ? 'selected' : '' }}>全局（所有人）</option>
                                                <option value="department" {{ $policy->scope_type === 'department' ? 'selected' : '' }}>指定部门</option>
                                            </select>
                                        </div>
                                        <div class="audit-field" data-scope-department style="{{ $policy->scope_type === 'department' ? '' : 'display:none;' }}">
                                            <label>目标部门</label>
                                            <select name="department_id">
                                                <option value="">请选择</option>
                                                @foreach($departments as $department)
                                                    <option value="{{ $department->id }}" {{ (int) $policy->department_id === (int) $department->id ? 'selected' : '' }}>{{ $department->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                    <div class="audit-form-grid" style="margin-top:12px;">
                                        <div class="audit-field">
                                            <label>用户提问时</label>
                                            <select name="input_action" required>
                                                <option value="allow" {{ $policy->input_action === 'allow' ? 'selected' : '' }}>放行</option>
                                                <option value="block" {{ $policy->input_action === 'block' ? 'selected' : '' }}>直接挡掉</option>
                                            </select>
                                        </div>
                                        <div class="audit-field">
                                            <label>机器人回复时</label>
                                            <select name="output_action" required>
                                                <option value="allow" {{ $policy->output_action === 'allow' ? 'selected' : '' }}>仅记下来</option>
                                                <option value="mask" {{ $policy->output_action === 'mask' ? 'selected' : '' }}>自动打码</option>
                                                <option value="block" {{ $policy->output_action === 'block' ? 'selected' : '' }}>整段不回复</option>
                                            </select>
                                        </div>
                                        <div class="audit-field">
                                            <label>优先级</label>
                                            <input type="number" name="priority" value="{{ (int) $policy->priority }}" min="0" max="100000">
                                        </div>
                                    </div>
                                    <div class="audit-form-grid two" style="margin-top:12px;">
                                        <div class="audit-field">
                                            <label>被挡下来时提示语</label>
                                            <input type="text" name="blocked_message" value="{{ $policy->blocked_message }}">
                                        </div>
                                        <div class="audit-field">
                                            <label>敏感词列表</label>
                                            <textarea name="terms_text">{{ $termsText }}</textarea>
                                        </div>
                                    </div>
                                    <div class="audit-form-actions">
                                        <label class="audit-check"><input type="checkbox" name="is_active" value="1" {{ $policy->is_active ? 'checked' : '' }}> 启用策略</label>
                                        <button type="submit" class="audit-btn audit-btn-primary">保存策略</button>
                                        @adminCan('audits.policies.delete')
                                            <button type="button" class="audit-btn audit-btn-danger" data-open-policy-delete data-policy-id="{{ $policy->id }}" data-policy-name="{{ $policy->name }}">删除策略</button>
                                        @endadminCan
                                    </div>
                                </form>
                            @else
                                <div class="audit-empty">当前账号没有修改审计策略权限。</div>
                            @endadminCan
                        </div>
                    </article>
                @empty
                    <div class="audit-empty">还没有任何审计策略。可以用上面的场景模板快速创建一条。</div>
                @endforelse
            </div>
        </div>

        <div class="audit-tab-panel {{ $activeTab === 'log' ? 'active' : '' }}" id="audit-panel-log">
            <form method="get" action="/admin/audits" class="audit-filter-row">
                <input type="hidden" name="_tab" value="log">
                <div class="audit-field">
                    <label>阶段</label>
                    <select name="stage">
                        <option value="">全部</option>
                        <option value="input" {{ ($filters['stage'] ?? '') === 'input' ? 'selected' : '' }}>用户提问</option>
                        <option value="output" {{ ($filters['stage'] ?? '') === 'output' ? 'selected' : '' }}>机器人回复</option>
                    </select>
                </div>
                <div class="audit-field">
                    <label>是否命中</label>
                    <select name="hit">
                        <option value="">全部</option>
                        <option value="1" {{ ($filters['hit'] ?? '') === '1' ? 'selected' : '' }}>命中</option>
                        <option value="0" {{ ($filters['hit'] ?? '') === '0' ? 'selected' : '' }}>未命中</option>
                    </select>
                </div>
                <div class="audit-field">
                    <label>处理结果</label>
                    <select name="decision">
                        <option value="">全部</option>
                        <option value="pass" {{ ($filters['decision'] ?? '') === 'pass' ? 'selected' : '' }}>放行</option>
                        <option value="masked" {{ ($filters['decision'] ?? '') === 'masked' ? 'selected' : '' }}>已打码</option>
                        <option value="blocked" {{ ($filters['decision'] ?? '') === 'blocked' ? 'selected' : '' }}>已挡下</option>
                    </select>
                </div>
                <div class="audit-field">
                    <label>策略</label>
                    <select name="policy_id">
                        <option value="0">全部策略</option>
                        @foreach($policies as $policy)
                            <option value="{{ $policy->id }}" {{ (int) ($filters['policy_id'] ?? 0) === (int) $policy->id ? 'selected' : '' }}>{{ $policy->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="audit-field">
                    <label>时间</label>
                    <select name="range" id="audit-range">
                        <option value="today" {{ ($filters['range'] ?? '') === 'today' ? 'selected' : '' }}>今天</option>
                        <option value="7d" {{ ($filters['range'] ?? '') === '7d' ? 'selected' : '' }}>近 7 天</option>
                        <option value="30d" {{ ($filters['range'] ?? '') === '30d' ? 'selected' : '' }}>近 30 天</option>
                        <option value="all" {{ ($filters['range'] ?? '') === 'all' ? 'selected' : '' }}>全部</option>
                        <option value="custom" {{ ($filters['range'] ?? '') === 'custom' ? 'selected' : '' }}>自定义</option>
                    </select>
                </div>
                <div class="audit-field audit-custom-range">
                    <label>开始</label>
                    <input type="date" name="start_date" value="{{ $filters['start_date'] ?? '' }}">
                </div>
                <div class="audit-field audit-custom-range">
                    <label>结束</label>
                    <input type="date" name="end_date" value="{{ $filters['end_date'] ?? '' }}">
                </div>
                <div class="audit-filter-actions">
                    <button type="submit" class="audit-btn">筛选</button>
                    @adminCan('audits.export')
                        <a class="audit-btn" href="{{ '/admin/audits/export?'.http_build_query(['stage' => $filters['stage'] ?? '', 'hit' => $filters['hit'] ?? '', 'decision' => $filters['decision'] ?? '', 'policy_id' => $filters['policy_id'] ?? 0, 'range' => $filters['range'] ?? '30d', 'start_date' => $filters['start_date'] ?? '', 'end_date' => $filters['end_date'] ?? '']) }}">导出 CSV</a>
                    @endadminCan
                </div>
            </form>

            <div class="audit-table-wrap">
                <table class="audit-table">
                    <thead>
                        <tr>
                            <th style="width:130px;">时间</th>
                            <th style="width:110px;">用户</th>
                            <th style="width:82px;">阶段</th>
                            <th style="width:90px;">结果</th>
                            <th style="width:160px;">命中策略</th>
                            <th style="width:130px;">命中词</th>
                            <th>内容摘要</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($records as $record)
                            @php
                                [$stageLabel, $stageClass] = $stageInfo($record->stage);
                                [$decisionLabel, $decisionClass] = $decisionInfo($record->decision, (bool) $record->hit);
                                $policyNames = is_array($record->matched_policy_names) ? $record->matched_policy_names : [];
                                $matchedTerms = is_array($record->matched_terms) ? $record->matched_terms : [];
                                $userName = $displayUserName($record);
                            @endphp
                            <tr>
                                <td><span class="audit-time">{{ $fmtDate($record->created_at, 'Y-m-d H:i:s') }}</span></td>
                                <td title="ID:{{ $record->user_id }} / Run:{{ $record->run_id ?: '-' }}">{{ $userName }}</td>
                                <td><span class="audit-stage {{ $stageClass }}">{{ $stageLabel }}</span></td>
                                <td><span class="audit-decision {{ $decisionClass }}">{{ $decisionLabel }}</span></td>
                                <td>{{ !empty($policyNames) ? implode('、', $policyNames) : '-' }}</td>
                                <td>
                                    @forelse(array_slice($matchedTerms, 0, 5) as $term)
                                        <span class="audit-term">{{ $term }}</span>
                                    @empty
                                        -
                                    @endforelse
                                </td>
                                <td class="audit-content">{{ $record->content_excerpt ?: '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><div class="audit-empty">暂无审计日志。</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="audit-pager">{{ $records->onEachSide(1)->links('pagination::bootstrap-4') }}</div>
        </div>

        <div class="audit-tab-panel {{ $activeTab === 'stats' ? 'active' : '' }}" id="audit-panel-stats">
            <div class="audit-section-head">
                <div>
                    <h3>统计分析</h3>
                    <p>基于当前筛选条件统计命中次数，按部门和个人两个维度展示。</p>
                </div>
            </div>
            <div class="audit-stats-grid">
                <div class="audit-card">
                    <h3>命中次数排行（部门）</h3>
                    <p>哪些部门踩到审计策略最多。</p>
                    <div class="audit-table-wrap">
                        <table class="audit-table" style="min-width:0;">
                            <thead><tr><th style="width:70px;">排名</th><th>部门</th><th style="width:110px;">命中次数</th></tr></thead>
                            <tbody>
                                @forelse(($departmentHitRanking ?? collect()) as $index => $item)
                                    <tr><td>#{{ $index + 1 }}</td><td>{{ $item->department_name }}</td><td>{{ (int) ($item->hit_count ?? 0) }}</td></tr>
                                @empty
                                    <tr><td colspan="3">暂无命中记录</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="audit-card">
                    <h3>命中次数排行（个人）</h3>
                    <p>哪些同事个人踩到审计策略最多。</p>
                    <div class="audit-table-wrap">
                        <table class="audit-table" style="min-width:0;">
                            <thead><tr><th style="width:70px;">排名</th><th>用户</th><th>部门</th><th style="width:110px;">命中次数</th></tr></thead>
                            <tbody>
                                @forelse(($userHitRanking ?? collect()) as $index => $item)
                                    <tr><td>#{{ $index + 1 }}</td><td>{{ $item->user_name }}</td><td>{{ $item->department_name }}</td><td>{{ (int) ($item->hit_count ?? 0) }}</td></tr>
                                @empty
                                    <tr><td colspan="4">暂无命中记录</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<script>
(function () {
    const tabs = Array.from(document.querySelectorAll('.audit-tab'));
    const panels = Array.from(document.querySelectorAll('.audit-tab-panel'));

    function switchTab(key) {
        tabs.forEach((tab) => tab.classList.toggle('active', tab.dataset.tab === key));
        panels.forEach((panel) => panel.classList.toggle('active', panel.id === 'audit-panel-' + key));
    }

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => switchTab(tab.dataset.tab));
    });

    document.querySelectorAll('[data-switch-tab]').forEach((button) => {
        button.addEventListener('click', () => switchTab(button.getAttribute('data-switch-tab')));
    });

    document.querySelectorAll('[data-open-create]').forEach((button) => {
        button.addEventListener('click', () => {
            switchTab('policy');
            const box = document.getElementById('audit-create-box');
            if (box) {
                box.open = true;
                box.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    const templates = {
        idcard: {
            name: '身份证/手机号/银行卡保护',
            scope: 'global',
            input: 'block',
            output: 'mask',
            priority: 200,
            message: '该消息包含身份证/手机号等敏感信息，已被合规策略拦截。',
            terms: ['身份证号', '身份证', '手机号', '银行卡号', '银行账号']
        },
        finance: {
            name: '财务合同金额保护',
            scope: 'department',
            input: 'block',
            output: 'block',
            priority: 150,
            message: '内容涉及合同金额/薪资，已被财务合规策略拦截。',
            terms: ['工资', '薪资', '合同金额', '年薪', '回款金额', '客户回款']
        },
        blank: {
            name: '',
            scope: 'global',
            input: 'block',
            output: 'mask',
            priority: 100,
            message: '内容触发企业合规策略，已被拦截。',
            terms: []
        }
    };

    document.querySelectorAll('[data-template]').forEach((button) => {
        button.addEventListener('click', () => {
            const data = templates[button.dataset.template];
            if (!data) return;
            const set = (id, value) => {
                const el = document.getElementById(id);
                if (el) el.value = value;
            };
            set('audit-create-name', data.name);
            set('audit-create-scope', data.scope);
            set('audit-create-input', data.input);
            set('audit-create-output', data.output);
            set('audit-create-priority', data.priority);
            set('audit-create-message', data.message);
            set('audit-create-terms', data.terms.join('\n'));
            document.getElementById('audit-create-scope')?.dispatchEvent(new Event('change'));
            document.getElementById('audit-create-name')?.focus();
        });
    });

    document.querySelectorAll('[data-toggle-policy]').forEach((summary) => {
        summary.addEventListener('click', () => {
            summary.closest('.audit-policy-card')?.classList.toggle('open');
        });
    });

    document.querySelectorAll('form').forEach((form) => {
        const scope = form.querySelector('[data-scope-select]');
        const dept = form.querySelector('[data-scope-department]');
        if (!scope || !dept) return;
        const sync = () => {
            const isDepartment = scope.value === 'department';
            dept.style.display = isDepartment ? '' : 'none';
            const select = dept.querySelector('select[name="department_id"]');
            if (select) select.required = isDepartment;
        };
        scope.addEventListener('change', sync);
        sync();
    });

    const range = document.getElementById('audit-range');
    const customRanges = Array.from(document.querySelectorAll('.audit-custom-range'));
    const syncRange = () => {
        const visible = range && range.value === 'custom';
        customRanges.forEach((el) => el.classList.toggle('audit-is-hidden', !visible));
    };
    range?.addEventListener('change', syncRange);
    syncRange();

    if (location.hash) {
        const target = document.querySelector(location.hash);
        if (target && target.classList.contains('audit-policy-card')) {
            switchTab('policy');
            target.classList.add('open');
            setTimeout(() => target.scrollIntoView({ behavior: 'smooth', block: 'start' }), 80);
        }
    }
})();
</script>

{{-- Q2: 删除审计策略 modal（GitHub 风格：必须输入策略名才能确认） --}}
@adminCan('audits.policies.delete')
<div class="ap-delete-modal" id="ap-delete-modal" hidden>
    <div class="ap-delete-modal-backdrop" data-ap-delete-close></div>
    <div class="ap-delete-modal-card" role="dialog" aria-modal="true" aria-labelledby="ap-delete-title">
        <h3 id="ap-delete-title" class="ap-delete-title">删除审计策略</h3>
        <p class="ap-delete-warn">为确认这一操作，请在下方输入完整策略名 <strong id="ap-delete-name-hint"></strong>：</p>
        <form method="post" action="" id="ap-delete-form">
            @csrf
            <input type="text" name="confirm_name" id="ap-delete-input" autocomplete="off" placeholder="输入策略名以确认" class="ap-delete-input">
            @if($errors->has('confirm_name'))
                <div class="ap-delete-error">{{ $errors->first('confirm_name') }}</div>
            @endif
            <div class="ap-delete-actions">
                <button type="button" class="audit-btn" data-ap-delete-close>取消</button>
                <button type="submit" class="audit-btn audit-btn-danger" id="ap-delete-submit" disabled>确认删除</button>
            </div>
        </form>
    </div>
</div>
<style>
.ap-delete-modal { position: fixed; inset: 0; z-index: 1100; display: flex; align-items: center; justify-content: center; }
.ap-delete-modal[hidden] { display: none; }
.ap-delete-modal-backdrop { position: absolute; inset: 0; background: rgba(15, 23, 42, 0.45); backdrop-filter: blur(2px); }
.ap-delete-modal-card { position: relative; width: min(480px, calc(100% - 32px)); background: #fff; border-radius: 12px; box-shadow: 0 20px 50px rgba(0,0,0,0.18); padding: 22px 24px; }
.ap-delete-title { margin: 0 0 12px; font-size: 18px; font-weight: 800; color: #b91c1c; }
.ap-delete-warn { font-size: 13px; color: #475569; line-height: 1.6; margin: 0 0 12px; }
.ap-delete-warn strong { color: #111; background: #fef2f2; padding: 1px 6px; border-radius: 4px; font-weight: 800; }
.ap-delete-input { width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 8px; padding: 0 12px; font-size: 14px; }
.ap-delete-input:focus { outline: 2px solid #ef4444; outline-offset: -1px; border-color: #ef4444; }
.ap-delete-error { color: #b91c1c; font-size: 12px; margin-top: 6px; }
.ap-delete-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }
.audit-btn-danger { background: #dc2626; color: #fff; border-color: #dc2626; }
.audit-btn-danger:hover { background: #b91c1c; border-color: #b91c1c; }
.audit-btn-danger:disabled { background: #fca5a5; border-color: #fca5a5; color: #fff; cursor: not-allowed; opacity: 0.7; }
</style>
<script>
(function () {
    const modal = document.getElementById('ap-delete-modal');
    const form = document.getElementById('ap-delete-form');
    const input = document.getElementById('ap-delete-input');
    const submit = document.getElementById('ap-delete-submit');
    const nameHint = document.getElementById('ap-delete-name-hint');
    if (!modal || !form) return;

    let expectedName = '';

    function open(policyId, policyName) {
        expectedName = (policyName || '').trim();
        nameHint.textContent = expectedName;
        form.action = '/admin/audits/policies/' + encodeURIComponent(policyId) + '/delete';
        input.value = '';
        submit.disabled = true;
        modal.hidden = false;
        setTimeout(() => input.focus(), 50);
    }
    function close() { modal.hidden = true; }

    document.querySelectorAll('[data-open-policy-delete]').forEach((btn) => {
        btn.addEventListener('click', () => {
            open(btn.getAttribute('data-policy-id'), btn.getAttribute('data-policy-name'));
        });
    });
    document.querySelectorAll('[data-ap-delete-close]').forEach(el => el.addEventListener('click', close));
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) close(); });
    input.addEventListener('input', () => {
        submit.disabled = input.value.trim() !== expectedName;
    });
})();
</script>
@endadminCan
@endsection
