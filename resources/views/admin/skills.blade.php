@extends('admin.layout')

@section('title', '米蛙管理后台 - 技能管理')
@section('page-title', '技能管理')
@section('page-desc', '管理 Skill 生命周期、分配范围与调用活跃度')

@section('header-actions')
    @adminCan('skills.create')
        <a class="pro-btn pro-btn-primary" href="/admin/skills/create">新增 Skill</a>
    @endadminCan
@endsection

@push('head')
<style>
    /* Remove hover effects on pro-card in this page */
    .pro-card:hover {
        transform: none !important;
        box-shadow: var(--pro-shadow-xs) !important;
        border-color: rgba(17, 35, 45, 0.09) !important;
    }
    /* Force table to fit container — no horizontal scroll */
    .skill-table {
        overflow-x: hidden !important;
    }
    .skill-table table {
        min-width: 0 !important;
        width: 100% !important;
        table-layout: fixed !important;
    }
    /* Column widths — fixed cols + description takes remaining space */
    .skill-table th:nth-child(1),
    .skill-table td:nth-child(1) { width: 120px; }
    .skill-table th:nth-child(2),
    .skill-table td:nth-child(2) { width: 140px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .skill-table th:nth-child(3),
    .skill-table td:nth-child(3) { /* auto — takes remaining space */ overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .skill-table th:nth-child(4),
    .skill-table td:nth-child(4) { width: 82px; white-space: nowrap; }
    .skill-table th:nth-child(5),
    .skill-table td:nth-child(5) { width: 60px; text-align: center; }
    .skill-table th:nth-child(6),
    .skill-table td:nth-child(6) { width: 60px; text-align: center; }
    .skill-table th:nth-child(7),
    .skill-table td:nth-child(7) { width: 100px; white-space: nowrap; }
    .skill-table th:nth-child(8),
    .skill-table td:nth-child(8) { width: 62px; white-space: nowrap; text-align: center; }
    /* Disable the sticky action column shadow from layout.js */
    .skill-table td.pro-col-action,
    .skill-table th.pro-col-action {
        position: static !important;
        border-left: none !important;
        box-shadow: none !important;
    }

    /* Toggle button styles */
    .skill-toggle {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
        border: 1px solid;
        cursor: pointer;
        transition: all 0.15s ease;
        background: #fff;
        line-height: 1.4;
    }
    .skill-toggle-on {
        color: var(--pro-error);
        border-color: #efc0ca;
    }
    .skill-toggle-on:hover {
        background: #fff2f5;
    }
    .skill-toggle-off {
        color: var(--pro-success);
        border-color: #b9e8cd;
    }
    .skill-toggle-off:hover {
        background: #ebf9f1;
    }
    .skill-toggle:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
</style>
@endpush

@section('content')
    @php
        $filters = is_array($filters ?? null) ? $filters : [];
        $statusFilter = $filters['status'] ?? 'all';
        $assignedFilter = $filters['assigned'] ?? 'all';
        $invokedFilter = $filters['invoked'] ?? 'all';
    @endphp

    <div class="pro-alert pro-alert-warning">
        飞书中可通过 <code>/skill_key</code> 调用技能，例如 <code>/weekly_report 生成本周销售总结</code>。仅已分配到该技能的用户或部门可触发执行。
    </div>

    <div class="pro-card" style="margin-bottom:12px;">
        <form method="get" action="/admin/skills" class="pro-row pro-row-3">
            <div class="pro-field">
                <label>状态</label>
                <select name="status">
                    <option value="all" {{ $statusFilter === 'all' ? 'selected' : '' }}>全部</option>
                    <option value="active" {{ $statusFilter === 'active' ? 'selected' : '' }}>启用</option>
                    <option value="inactive" {{ $statusFilter === 'inactive' ? 'selected' : '' }}>停用</option>
                </select>
            </div>

            <div class="pro-field">
                <label>分配情况</label>
                <select name="assigned">
                    <option value="all" {{ $assignedFilter === 'all' ? 'selected' : '' }}>全部</option>
                    <option value="assigned" {{ $assignedFilter === 'assigned' ? 'selected' : '' }}>已分配</option>
                    <option value="unassigned" {{ $assignedFilter === 'unassigned' ? 'selected' : '' }}>未分配</option>
                </select>
            </div>

            <div class="pro-field">
                <label>调用活跃度</label>
                <select name="invoked">
                    <option value="all" {{ $invokedFilter === 'all' ? 'selected' : '' }}>全部</option>
                    <option value="recent_7d" {{ $invokedFilter === 'recent_7d' ? 'selected' : '' }}>近 7 天有调用</option>
                    <option value="recent_30d" {{ $invokedFilter === 'recent_30d' ? 'selected' : '' }}>近 30 天有调用</option>
                    <option value="never" {{ $invokedFilter === 'never' ? 'selected' : '' }}>从未调用</option>
                </select>
            </div>

            <div class="pro-inline-actions" style="grid-column: 1 / -1; justify-content:flex-end;">
                <a class="pro-btn" href="/admin/skills">重置</a>
                <button class="pro-btn pro-btn-primary" type="submit">筛选</button>
            </div>
        </form>
    </div>

    <div class="pro-card">
        <h3 class="pro-card-title">技能列表</h3>
        <div class="pro-table-wrap skill-table">
            <table>
                <thead>
                <tr>
                    <th>技能名称</th>
                    <th>命令字</th>
                    <th>技能说明</th>
                    <th>状态</th>
                    <th>分配部门</th>
                    <th>分配用户</th>
                    <th>最近调用</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                @forelse($skills as $skill)
                    <tr data-skill-id="{{ $skill->id }}">
                        <td>
                            <a href="/admin/skills/{{ $skill->id }}"><strong>{{ $skill->name }}</strong></a>
                        </td>
                        <td>
                            <span class="pro-dir-name">/{{ $skill->skill_key }}</span>
                        </td>
                        <td>{{ $skill->description ?: '（未填写）' }}</td>
                        <td>
                            <span class="pro-tag skill-status-tag {{ $skill->is_active ? 'pro-tag-success' : '' }}">
                                {{ $skill->is_active ? '启用' : '停用' }}
                            </span>
                            @if(($skill->meta['executor'] ?? 'llm') === 'sandbox')
                                <span class="pro-tag" style="background:#e0f2fe;color:#0369a1;">沙箱</span>
                            @endif
                        </td>
                        <td>{{ (int) ($skill->department_count ?? 0) }}</td>
                        <td>{{ (int) ($skill->user_count ?? 0) }}</td>
                        <td>
                            @if(! empty($skill->last_invoked_at))
                                <div>{{ \Carbon\Carbon::parse($skill->last_invoked_at)->format('m-d H:i') }}</div>
                                <div class="pro-muted">近30天 {{ (int) ($skill->invoke_count_30 ?? 0) }} 次</div>
                            @else
                                <span class="pro-muted">暂无</span>
                            @endif
                        </td>
                        <td>
                            @adminCan('skills.status')
                                @if($skill->is_active)
                                    <button class="skill-toggle skill-toggle-on" onclick="toggleSkill({{ $skill->id }}, 0, this)">停用</button>
                                @else
                                    <button class="skill-toggle skill-toggle-off" onclick="toggleSkill({{ $skill->id }}, 1, this)">启用</button>
                                @endif
                            @else
                                <span class="pro-muted">无权限</span>
                            @endadminCan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="pro-muted" style="text-align:center;">
                            当前还没有技能，点击右上角"新增 Skill"开始创建。
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('scripts')
<script>
function toggleSkill(skillId, newActive, btn) {
    if (btn.disabled) return;
    btn.disabled = true;
    btn.textContent = '处理中…';

    fetch('/admin/skills/' + skillId + '/status', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ is_active: newActive }),
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok) {
            var row = btn.closest('tr');
            var tag = row.querySelector('.skill-status-tag');
            if (newActive) {
                tag.className = 'pro-tag skill-status-tag pro-tag-success';
                tag.textContent = '启用';
                btn.className = 'skill-toggle skill-toggle-on';
                btn.textContent = '停用';
                btn.onclick = function() { toggleSkill(skillId, 0, btn); };
            } else {
                tag.className = 'pro-tag skill-status-tag';
                tag.textContent = '停用';
                btn.className = 'skill-toggle skill-toggle-off';
                btn.textContent = '启用';
                btn.onclick = function() { toggleSkill(skillId, 1, btn); };
            }
        } else {
            alert(data.message || '操作失败');
        }
        btn.disabled = false;
    })
    .catch(function(err) {
        alert('网络错误，请重试');
        btn.disabled = false;
        btn.textContent = newActive ? '启用' : '停用';
    });
}
</script>
@endpush
