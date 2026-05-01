@extends('admin.layout')

@section('title', '米蛙管理后台 - 用户管理')
@section('header-title', '用户管理')
@section('header-subtitle', '飞书组织架构与成员同步')
@section('page-title', '用户管理')
@section('page-desc', '查看飞书组织架构、成员信息与用户快捷入口')

@section('header-actions')
    <div class="pro-inline-actions">
        @adminCan('users.sync')
            <form method="post" action="/admin/users/sync" style="margin:0;" id="sync-form">
                @csrf
                <button class="pro-btn pro-btn-outline" type="submit" id="sync-btn">立即从飞书同步</button>
            </form>
        @endadminCan
    </div>
@endsection

@section('content')
    <div class="pro-kpi-grid">
        <div class="pro-card"><div class="pro-kpi-label">部门总数</div><div class="pro-kpi-value">{{ number_format((int) $stats['department_total']) }}</div></div>
        <div class="pro-card"><div class="pro-kpi-label">成员总数</div><div class="pro-kpi-value">{{ number_format((int) $stats['user_total']) }}</div></div>
        <div class="pro-card"><div class="pro-kpi-label">飞书成员数</div><div class="pro-kpi-value">{{ number_format((int) $stats['feishu_user_total']) }}</div></div>
        <div class="pro-card"><div class="pro-kpi-label">启用成员数</div><div class="pro-kpi-value">{{ number_format((int) $stats['active_user_total']) }}</div></div>
    </div>

    <div class="pro-card no-card-hover" style="margin-top:12px;">
        <h3 class="pro-card-title">同步状态</h3>
        @if(is_array($syncStatus) && !empty($syncStatus))
            <div class="pro-card-subtitle">
                最近同步：{{ $syncStatus['finished_at'] ?? '-' }}<br>
                结果：{{ ($syncStatus['ok'] ?? false) ? '成功' : '失败' }}，{{ $syncStatus['message'] ?? '-' }}<br>
                耗时：{{ number_format(((int)($syncStatus['duration_ms'] ?? 0)) / 1000, 2) }} 秒
            </div>
        @else
            <div class="pro-card-subtitle">尚未执行过飞书同步。</div>
        @endif
    </div>

    {{-- ── Filter bar: narrower fields + smaller button ── --}}
    <div class="pro-card no-card-hover" style="margin-top:12px;">
        <form method="get" action="/admin/users" style="display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap;">
            <div class="pro-field" style="flex:0 1 220px; min-width:160px;">
                <label>按部门筛选</label>
                <select name="department_id" style="padding-right:32px;">
                    <option value="0">全部部门</option>
                    @foreach($allDepartmentRows ?? $departmentRows as $row)
                        <option value="{{ $row['id'] }}" {{ $selectedDepartmentId === (int) $row['id'] ? 'selected' : '' }}>
                            {{ str_repeat('—', (int) $row['depth']) }}{{ $row['name'] }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="pro-field" style="flex:0 1 220px; min-width:160px;">
                <label>关键词（姓名 / 职位）</label>
                <input type="text" name="keyword" value="{{ $keyword }}" placeholder="输入关键词搜索成员">
            </div>
            <div class="pro-field" style="flex:0 0 auto;">
                <button class="pro-btn pro-btn-primary" type="submit" style="padding:8px 28px;">查询</button>
            </div>
        </form>
    </div>

    {{-- ── Organization tree (35%) + Member list (65%) ── --}}
    <div style="display:grid; grid-template-columns:1fr 3.5fr; gap:12px; margin-top:12px; min-width:0;">
        {{-- LEFT: Organization tree --}}
        <div class="pro-card no-card-hover" style="min-width:0;">
            <h3 class="pro-card-title">组织架构</h3>
            @if(empty($departmentRows))
                <div class="pro-empty">还没有部门数据，请先点击"立即从飞书同步"。</div>
            @else
                <div class="dept-tree">
                    @foreach(($keyword !== "" ? $departmentRows : ($allDepartmentRows ?? $departmentRows)) as $row)
                        @php
                            $isActive = $selectedDepartmentId === (int) $row['id'];
                            $memberCount = (int) ($departmentUserCount[$row['id']] ?? 0);
                        @endphp
                        <a href="javascript:void(0)"
                           class="dept-tree-item {{ $isActive ? 'active' : '' }}"
                           data-dept-id="{{ $row['id'] }}"
                           style="padding-left:{{ 16 + (int) $row['depth'] * 20 }}px;">
                            <span class="dept-tree-icon">
                                <svg viewBox="0 0 20 20" width="16" height="16" fill="none">
                                    <path d="M4 4h5v5H4V4Zm7 0h5v5h-5V4ZM4 11h5v5H4v-5Zm7 0h5v5h-5v-5Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                                </svg>
                            </span>
                            <span class="dept-tree-name">{{ $row['name'] }}</span>
                            <span class="dept-tree-count">{{ $memberCount }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- RIGHT: Member list --}}
        <div class="pro-card no-card-hover" style="min-width:0;" id="member-list">
            @include('admin.users-member-list')
        </div>
    </div>

@endsection

@push('scripts')
<script>
(function() {
    const form = document.getElementById('sync-form');
    const btn = document.getElementById('sync-btn');
    if (!form || !btn) return;

    form.addEventListener('submit', function() {
        btn.disabled = true;
        btn.textContent = '正在同步飞书通讯录…';
        btn.style.opacity = '0.7';
        btn.style.cursor = 'wait';

        var banner = document.createElement('div');
        banner.className = 'pro-alert pro-alert-success';
        banner.id = 'sync-loading-banner';
        banner.innerHTML = '<div style="display:flex;align-items:center;gap:8px;"><svg width="18" height="18" viewBox="0 0 24 24" style="animation:spin 1s linear infinite;"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="31 31" stroke-linecap="round"/></svg><span>正在从飞书同步组织架构和成员信息，请稍候…</span></div>';

        var content = document.querySelector('.pro-content');
        var firstChild = content?.querySelector('.pro-kpi-grid, .pro-card, .pro-alert');
        if (content && firstChild) {
            content.insertBefore(banner, firstChild);
        } else if (content) {
            content.prepend(banner);
        }
    });
})();

// ── AJAX department switching ──
(function() {
    var memberList = document.getElementById('member-list');
    if (!memberList) return;

    document.querySelector('.dept-tree')?.addEventListener('click', function(e) {
        var item = e.target.closest('.dept-tree-item');
        if (!item) return;
        e.preventDefault();

        var deptId = item.dataset.deptId;
        var keyword = document.querySelector('input[name="keyword"]')?.value || '';

        // Update active state immediately
        document.querySelectorAll('.dept-tree-item').forEach(function(el) {
            el.classList.remove('active');
        });
        item.classList.add('active');

        // Build URL
        var params = new URLSearchParams();
        if (deptId && deptId !== '0') params.set('department_id', deptId);
        if (keyword) params.set('keyword', keyword);
        var url = '/admin/users' + (params.toString() ? '?' + params.toString() : '');

        // Update browser URL without reload
        history.pushState({deptId: deptId, keyword: keyword}, '', url);

        // Fetch member list via AJAX
        memberList.style.opacity = '0.5';
        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            memberList.innerHTML = data.html;
            memberList.style.opacity = '1';
        })
        .catch(function() {
            memberList.style.opacity = '1';
        });
    });

    // Handle browser back/forward
    window.addEventListener('popstate', function(e) {
        var params = new URLSearchParams(window.location.search);
        var deptId = params.get('department_id') || '0';
        var keyword = params.get('keyword') || '';
        var url = window.location.pathname + window.location.search;

        // Update active state
        document.querySelectorAll('.dept-tree-item').forEach(function(el) {
            el.classList.toggle('active', el.dataset.deptId === deptId);
        });

        memberList.style.opacity = '0.5';
        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            memberList.innerHTML = data.html;
            memberList.style.opacity = '1';
        })
        .catch(function() {
            memberList.style.opacity = '1';
        });
    });
})();
</script>
<style>
@keyframes spin { to { transform: rotate(360deg); } }

.no-card-hover {
    transition: none !important;
}
.no-card-hover:hover {
    transform: none !important;
    box-shadow: var(--pro-shadow-xs) !important;
    border-color: rgba(17, 35, 45, 0.09) !important;
}

/* ── Department tree ── */
.dept-tree {
    display: flex;
    flex-direction: column;
    gap: 1px;
    max-height: 520px;
    overflow-y: auto;
}

.dept-tree-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-radius: 8px;
    color: var(--pro-text);
    font-size: 13px;
    text-decoration: none;
    transition: background 0.15s;
    cursor: pointer;
}

.dept-tree-item:hover {
    background: #f3f7ff;
    color: #1554c0;
}

.dept-tree-item.active {
    background: var(--pro-primary-soft, #eaf4ff);
    color: #1554c0;
    font-weight: 600;
}

.dept-tree-icon {
    flex: 0 0 16px;
    color: #8a98ad;
    display: inline-flex;
}

.dept-tree-item.active .dept-tree-icon {
    color: #1554c0;
}

.dept-tree-name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.dept-tree-count {
    flex: 0 0 auto;
    font-size: 12px;
    color: var(--pro-text-secondary);
    background: #f0f4f8;
    border-radius: 10px;
    padding: 1px 8px;
    min-width: 24px;
    text-align: center;
}

.dept-tree-item.active .dept-tree-count {
    background: #d8e7ff;
    color: #1554c0;
}

/* Responsive: stack on narrow screens */
@media (max-width: 960px) {
    div[style*="grid-template-columns:35fr 65fr"] {
        grid-template-columns: 1fr !important;
    }
}
</style>
@endpush

