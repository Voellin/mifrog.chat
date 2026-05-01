@extends('admin.layout')

@section('title', '米蛙管理后台 - 仪表盘')
@section('header-title', '仪表盘')
@section('header-subtitle', '运行状态、稳定性与配额总览')
@section('page-title', '管理后台仪表盘')
@section('page-desc', '关键运营指标、队列执行表现、Token 用量分布')



@section('content')
    <div class="pro-kpi-grid" style="grid-template-columns: repeat(3, 1fr);">
        <div class="pro-card"><div class="pro-kpi-label">部门数</div><div class="pro-kpi-value">{{ $stats['departments'] }}</div></div>
        <div class="pro-card"><div class="pro-kpi-label">成员数</div><div class="pro-kpi-value">{{ $stats['users'] }}</div></div>
        <div class="pro-card"><div class="pro-kpi-label">本月 Token 消耗</div><div class="pro-kpi-value small" style="color: #f59e0b;">{{ number_format((int) $stats['token_used_this_month']) }}</div></div>
        <div class="pro-card"><div class="pro-kpi-label">任务总数</div><div class="pro-kpi-value">{{ $stats['runs_total'] }}</div></div>
        <div class="pro-card"><div class="pro-kpi-label">成功率</div><div class="pro-kpi-value small">{{ number_format((float) $stats['success_rate'], 2) }}%</div></div>
        <div class="pro-card"><div class="pro-kpi-label">任务平均耗时（秒）</div><div class="pro-kpi-value small">{{ number_format((float) $stats['avg_latency_seconds'], 2) }}</div></div>
    </div>

    <div class="pro-grid pro-grid-2 no-card-hover-zone" style="margin-top:12px;">
        <div class="pro-card">
            <h3 class="pro-card-title">近 14 天任务量与平均耗时</h3>
            <div class="pro-card-subtitle">左轴：任务数；右轴：秒</div>
            <canvas id="runsTrend" class="pro-chart"></canvas>
        </div>

        <div class="pro-card">
            <h3 class="pro-card-title">任务状态分布</h3>
            <div class="pro-card-subtitle">按状态统计任务执行情况</div>
            <div class="pro-pie-wrap">
                <canvas id="statusPie"></canvas>
            </div>
        </div>

        <div class="pro-card">
            <h3 class="pro-card-title">近 14 天 Token 使用量</h3>
            <div class="pro-card-subtitle">统计来源：quota_usage_ledgers</div>
            <canvas id="tokenTrend" class="pro-chart"></canvas>
        </div>

        <div class="pro-card">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">
                <h3 class="pro-card-title" style="margin-bottom:0;">本月部门 Token 使用 Top</h3>
                <div id="deptLevelTabs" style="display:flex; gap:6px;">
                    <button class="dept-level-btn active" data-level="1">一级部门</button>
                    <button class="dept-level-btn" data-level="2">二级部门</button>
                    <button class="dept-level-btn" data-level="3">三级部门</button>
                </div>
            </div>
            <div class="pro-card-subtitle">快速识别高负载部门</div>
            <canvas id="deptTop" class="pro-chart"></canvas>
        </div>
    </div>

    <div class="pro-card no-card-hover" style="margin-top:12px;">
        <h3 class="pro-card-title">单用户 Token 消耗明细</h3>
        <div class="pro-card-subtitle">优先展示飞书姓名；若未同步姓名，展示“飞书用户 ID”</div>
        <div class="pro-table-wrap">
            <table>
                <thead>
                <tr>
                    <th style="width:72px;">用户ID</th>
                    <th>姓名</th>
                    <th>部门</th>
                    <th class="pro-text-right">本月 Token</th>
                    <th class="pro-text-right">累计 Token</th>
                    <th class="pro-text-right">最后消耗时间</th>
                </tr>
                </thead>
                <tbody>
                @forelse($userTokenUsage as $row)
                    <tr>
                        <td>{{ $row->id }}</td>
                        <td>{{ $row->display_name ?: $row->name }}</td>
                        <td>{{ $row->department_name ?: '未分配' }}</td>
                        <td class="pro-text-right">{{ number_format((int) $row->monthly_tokens) }}</td>
                        <td class="pro-text-right">{{ number_format((int) $row->total_tokens) }}</td>
                        <td class="pro-text-right">{{ $row->last_used_at ? \Carbon\Carbon::parse($row->last_used_at)->format('Y-m-d H:i') : '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="pro-muted" style="text-align:center;">暂无用户数据。</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('head')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
.no-card-hover,
.no-card-hover-zone .pro-card {
    transition: none !important;
}
.no-card-hover:hover,
.no-card-hover-zone .pro-card:hover {
    transform: none !important;
    box-shadow: var(--pro-shadow-xs) !important;
    border-color: rgba(17, 35, 45, 0.09) !important;
}
.dept-level-btn {
    padding: 3px 10px;
    font-size: 12px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    background: #fff;
    color: #6b7280;
    cursor: pointer;
    transition: all .15s;
    line-height: 1.5;
}
.dept-level-btn:hover {
    border-color: #0f9d6f;
    color: #0f9d6f;
}
.dept-level-btn.active {
    background: #0f9d6f;
    border-color: #0f9d6f;
    color: #fff;
}
</style>
@endpush

@push('scripts')
<script>
    const chartsData = @json($charts);
    const gridColor = 'rgba(148, 163, 184, 0.28)';
    const commonTicks = { color: '#475569', font: { size: 11 } };

    new Chart(document.getElementById('runsTrend'), {
        type: 'line',
        data: {
            labels: chartsData.runs_trend.labels,
            datasets: [
                { label: '任务数', data: chartsData.runs_trend.runs, borderColor: '#1677ff', backgroundColor: 'rgba(22,119,255,.18)', borderWidth: 2, tension: 0, yAxisID: 'y', fill: true },
                { label: '平均耗时（秒）', data: chartsData.runs_trend.avg_latency, borderColor: '#fa8c16', backgroundColor: 'rgba(250,140,22,.14)', borderWidth: 2, tension: 0, yAxisID: 'y1' }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: { beginAtZero: true, ticks: commonTicks, grid: { color: gridColor } },
                y1: { beginAtZero: true, position: 'right', ticks: commonTicks, grid: { drawOnChartArea: false } },
                x: { ticks: commonTicks, grid: { color: gridColor } }
            },
            plugins: { legend: { labels: { color: '#334155', usePointStyle: true, pointStyle: 'rectRounded', padding: 20, font: { size: 12 } } } }
        }
    });

    new Chart(document.getElementById('statusPie'), {
        type: 'doughnut',
        data: {
            labels: chartsData.status_distribution.labels,
            datasets: [{ data: chartsData.status_distribution.values, backgroundColor: ['#64748b', '#1677ff', '#faad14', '#d97706', '#52c41a', '#f5222d'], borderColor: '#ffffff', borderWidth: 2 }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { position: 'bottom', labels: { color: '#334155' } } }
        }
    });

    new Chart(document.getElementById('tokenTrend'), {
        type: 'bar',
        data: { labels: chartsData.token_trend.labels, datasets: [{ label: 'Token', data: chartsData.token_trend.values, backgroundColor: 'rgba(82,196,26,.76)', borderRadius: 6, borderSkipped: false }] },
        options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, scales: { y: { beginAtZero: true, ticks: commonTicks, grid: { color: gridColor } }, x: { ticks: commonTicks, grid: { display: false } } }, plugins: { legend: { display: false } } }
    });

    const deptUsageData = chartsData.department_usage;
    const deptTopChart = new Chart(document.getElementById('deptTop'), {
        type: 'bar',
        data: { labels: deptUsageData.level_1.labels, datasets: [{ label: 'Token', data: deptUsageData.level_1.values, backgroundColor: 'rgba(22,119,255,.72)', borderRadius: 6, borderSkipped: false }] },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { y: { ticks: commonTicks, grid: { display: false } }, x: { beginAtZero: true, ticks: commonTicks, grid: { color: gridColor } } }, plugins: { legend: { display: false } } }
    });

    document.querySelectorAll('.dept-level-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.dept-level-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            const level = this.dataset.level;
            const levelData = deptUsageData['level_' + level];
            deptTopChart.data.labels = levelData.labels;
            deptTopChart.data.datasets[0].data = levelData.values;
            deptTopChart.update();
        });
    });
</script>
<style>
.no-card-hover,
.no-card-hover-zone .pro-card {
    transition: none !important;
}
.no-card-hover:hover,
.no-card-hover-zone .pro-card:hover {
    transform: none !important;
    box-shadow: var(--pro-shadow-xs) !important;
    border-color: rgba(17, 35, 45, 0.09) !important;
}
</style>
@endpush
