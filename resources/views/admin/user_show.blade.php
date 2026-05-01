@extends('admin.layout')

@section('title', '米蛙管理后台 - 用户详情')
@section('header-title', '用户详情')
@section('header-subtitle', '单用户记忆、Token 配额与可用 Skill')
@section('page-title', '用户详情')
@section('page-desc', '聚合展示用户画像、配额消耗、技能权限与知识库状态')

@section('header-actions')
    <a href="/admin/users" class="pro-btn">返回用户管理</a>
@endsection

@push('head')
<style>
/* ===== 去掉 hover ===== */
.pro-card:hover { transform: none !important; box-shadow: none !important; border-color: var(--pro-border) !important; }
.pro-table-wrap tbody tr:hover td,
.pro-table-wrap tbody tr:hover td.pro-col-action { background: transparent !important; }
.pro-list-item:hover { background: transparent !important; }

/* ===== Ant Design 风格 Tabs ===== */
.ant-tabs { margin-top: 16px; }
.ant-tabs-nav {
    position: relative; display: flex;
    border-bottom: 1px solid #f0f0f0;
    margin-bottom: 0; gap: 0;
}
.ant-tabs-tab {
    position: relative; padding: 12px 20px;
    font-size: 14px; color: rgba(0,0,0,.65);
    background: none; border: none; cursor: pointer;
    transition: color .2s; white-space: nowrap; line-height: 1.5;
}
.ant-tabs-tab:hover { color: #0f766e; }
.ant-tabs-tab-active { color: #0f766e; font-weight: 500; }
.ant-tabs-tab::after {
    content: ''; position: absolute;
    bottom: 0; left: 20px; right: 20px; height: 2px;
    background: transparent; border-radius: 2px 2px 0 0;
    transition: background .2s;
}
.ant-tabs-tab-active::after { background: #0f766e; left: 0; right: 0; }

/* 内容面板 */
.ant-tabs-panel { display: none; padding: 20px 24px 24px; }
.ant-tabs-panel-active { display: block; }

/* 面板内 key-value 表 */
.ant-tabs-panel .pro-table-wrap { overflow-x: hidden; }
.ant-tabs-panel .pro-table-wrap table:not(:has(thead)) { table-layout: fixed; width: 100%; }
.ant-tabs-panel .pro-table-wrap table:not(:has(thead)) th { width: 120px; white-space: nowrap; font-size: 13px; }
.ant-tabs-panel .pro-table-wrap table:not(:has(thead)) td { word-break: break-all; overflow-wrap: break-word; }
/* thead 表 */
.ant-tabs-panel .pro-table-wrap table thead th { font-size: 12px; padding: 8px 10px; white-space: nowrap; }
.ant-tabs-panel .pro-table-wrap table tbody td { font-size: 12px; padding: 8px 10px; }
.ant-tabs-panel .pro-code {
    display: inline-block; max-width: 100%; overflow: hidden;
    text-overflow: ellipsis; white-space: nowrap; vertical-align: bottom;
}

/* ===== 实心操作按钮 ===== */
.detail-action-btn {
    display: inline-block; padding: 5px 16px;
    font-size: 13px; font-weight: 500;
    color: #fff; background: #0f766e;
    border: 1px solid #0f766e; border-radius: 6px;
    text-decoration: none; cursor: pointer;
    transition: background .15s, border-color .15s;
}
.detail-action-btn:hover { background: #0d6560; border-color: #0d6560; color: #fff; }

/* ===== 最近运行：表格区域独立滚动 ===== */
.runs-table-scroll {
    max-height: 320px;
    overflow-y: auto;
    overflow-x: auto;
    border: 1px solid #f0f0f0;
    border-radius: 6px;
}
.runs-table-scroll table thead th {
    position: sticky; top: 0;
    background: #fafafa; z-index: 1;
}
/* 分页器 */
.runs-pager {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 0 0; font-size: 13px; color: #64748b;
}
.runs-pager-btns { display: flex; gap: 6px; }
.runs-pager-btns button {
    padding: 4px 12px; font-size: 12px;
    border: 1px solid #d9d9d9; border-radius: 4px;
    background: #fff; color: #333; cursor: pointer;
    transition: all .15s;
}
.runs-pager-btns button:hover:not(:disabled) { border-color: #0f766e; color: #0f766e; }
.runs-pager-btns button:disabled { opacity: .4; cursor: not-allowed; }
.runs-pager-btns button.pager-active {
    background: #0f766e; color: #fff; border-color: #0f766e;
}

/* ===== 自定义确认弹窗 ===== */
.confirm-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.35);
    z-index: 9999; display: flex; align-items: center; justify-content: center;
    animation: confirmFadeIn .15s ease;
}
@keyframes confirmFadeIn { from { opacity: 0; } to { opacity: 1; } }
.confirm-box {
    background: #fff; border-radius: 12px;
    padding: 28px 32px 20px; min-width: 340px; max-width: 420px;
    box-shadow: 0 8px 30px rgba(0,0,0,.18); text-align: center;
}
.confirm-box .confirm-icon {
    width: 48px; height: 48px; margin: 0 auto 14px;
    border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px;
}
.confirm-box .confirm-icon.warn { background: #fef3c7; color: #d97706; }
.confirm-box .confirm-icon.enable { background: #d1fae5; color: #059669; }
.confirm-box .confirm-title { font-size: 16px; font-weight: 600; color: #1e293b; margin-bottom: 8px; }
.confirm-box .confirm-desc { font-size: 13px; color: #64748b; line-height: 1.5; margin-bottom: 22px; }
.confirm-box .confirm-actions { display: flex; gap: 10px; justify-content: center; }
.confirm-box .confirm-actions button {
    padding: 8px 24px; border-radius: 8px; font-size: 13px;
    font-weight: 500; cursor: pointer; border: none; transition: all .15s;
}
.confirm-box .btn-cancel { background: #f1f5f9; color: #475569; }
.confirm-box .btn-cancel:hover { background: #e2e8f0; }
.confirm-box .btn-confirm-danger { background: #ef4444; color: #fff; }
.confirm-box .btn-confirm-danger:hover { background: #dc2626; }
.confirm-box .btn-confirm-success { background: #059669; color: #fff; }
.confirm-box .btn-confirm-success:hover { background: #047857; }
</style>
@endpush

@section('content')
    {{-- KPI 卡片 --}}
    <div class="pro-kpi-grid" style="grid-template-columns: repeat(3, 1fr);">
        <div class="pro-card"><div class="pro-kpi-label">用户名称</div><div class="pro-kpi-value">{{ $user->display_name }}</div></div>
        <div class="pro-card"><div class="pro-kpi-label">本月 Token 消耗</div><div class="pro-kpi-value">{{ number_format((int) $tokenStats['monthly_usage']) }}</div></div>
        <div class="pro-card"><div class="pro-kpi-label">累计 Token 消耗</div><div class="pro-kpi-value">{{ number_format((int) $tokenStats['total_usage']) }}</div></div>
        <div class="pro-card"><div class="pro-kpi-label">可用 Skill 数量</div><div class="pro-kpi-value">{{ number_format((int) $skills->count()) }}</div></div>
        <div class="pro-card"><div class="pro-kpi-label">知识库文件数</div><div class="pro-kpi-value">{{ number_format((int) ($knowledgeStats['attachments_total'] ?? 0)) }}</div></div>
        <div class="pro-card"><div class="pro-kpi-label">知识库分块数</div><div class="pro-kpi-value">{{ number_format((int) ($knowledgeStats['chunk_total'] ?? 0)) }}</div></div>
    </div>

    {{-- 标签页 --}}
    <div class="ant-tabs">
        <div class="pro-card" style="padding:0;">
            <div class="ant-tabs-nav">
                <button class="ant-tabs-tab ant-tabs-tab-active" data-tab="basic">基础信息</button>
                <button class="ant-tabs-tab" data-tab="token">Token 配额与消耗</button>
                <button class="ant-tabs-tab" data-tab="memory">记忆概览</button>
                <button class="ant-tabs-tab" data-tab="skill">可用 Skill</button>
                <button class="ant-tabs-tab" data-tab="runs">最近运行</button>
                <button class="ant-tabs-tab" data-tab="knowledge">用户知识库</button>
                <button class="ant-tabs-tab" data-tab="archive">系统归档</button>
            </div>

            {{-- ====== 基础信息 ====== --}}
            <div class="ant-tabs-panel ant-tabs-panel-active" id="tab-basic">
                <div class="pro-table-wrap">
                    <table>
                        <tr><th>用户 ID</th><td>{{ $user->id }}</td></tr>
                        <tr><th>姓名</th><td>{{ $user->display_name }}</td></tr>
                        <tr><th>邮箱</th><td>{{ $user->email }}</td></tr>
                        <tr><th>部门</th><td>{{ $user->department->name ?? '未分配' }}</td></tr>
                        <tr><th>职位</th><td>{{ $user->title ?: '-' }}</td></tr>
                        <tr><th>Open ID</th><td><span class="pro-code">{{ $user->feishu_open_id ?: ($identityExtra['open_id'] ?? '-') }}</span></td></tr>
                        <tr><th>Union ID</th><td><span class="pro-code">{{ $user->feishu_union_id ?: ($identityExtra['union_id'] ?? '-') }}</span></td></tr>
                        <tr><th>手机号</th><td>{{ $identityExtra['mobile'] ?? '-' }}</td></tr>
                        <tr><th>状态</th><td>
                            <span class="pro-tag {{ $user->is_active ? 'pro-tag-success' : '' }}">{{ $user->is_active ? '启用' : '停用' }}</span>
                            @adminCan('users.toggle_active')
                                <form method="post" action="/admin/users/{{ $user->id }}/toggle-active" style="display:inline; margin-left:8px;">
                                    @csrf
                                    <button type="submit" class="pro-btn pro-btn-sm {{ $user->is_active ? 'pro-btn-outline' : 'pro-btn-primary' }}" style="padding:3px 10px; font-size:12px;"
                                        data-confirm="{{ $user->is_active ? '停用' : '启用' }}">
                                        {{ $user->is_active ? '停用该用户' : '启用该用户' }}
                                    </button>
                                </form>
                            @endadminCan
                        </td></tr>
                    </table>
                </div>
            </div>

            {{-- ====== Token 配额与消耗 ====== --}}
            <div class="ant-tabs-panel" id="tab-token">
                <div class="pro-table-wrap">
                    <table>
                        <tr><th>统计月份</th><td>{{ $tokenStats['period_key'] }}</td></tr>
                        <tr><th>本月消耗</th><td>{{ number_format((int) $tokenStats['monthly_usage']) }}</td></tr>
                        <tr><th>本月 Input</th><td>{{ number_format((int) $tokenStats['monthly_input_tokens']) }}</td></tr>
                        <tr><th>本月 Output</th><td>{{ number_format((int) $tokenStats['monthly_output_tokens']) }}</td></tr>
                        <tr><th>本月 Run 数</th><td>{{ number_format((int) $tokenStats['monthly_runs']) }}</td></tr>
                        <tr><th>累计消耗</th><td>{{ number_format((int) $tokenStats['total_usage']) }}</td></tr>
                        <tr><th>用户专属配额</th><td>{{ $tokenStats['user_policy_limit'] !== null ? number_format((int) $tokenStats['user_policy_limit']) : '-' }}</td></tr>
                        <tr><th>部门默认配额</th><td>{{ $tokenStats['department_policy_limit'] !== null ? number_format((int) $tokenStats['department_policy_limit']) : '-' }}</td></tr>
                        <tr><th>每人默认月上限</th><td>{{ number_format((int) $tokenStats['default_limit']) }}</td></tr>
                        <tr><th>生效配额</th><td>
                            @if((int) $tokenStats['effective_limit'] > 0)
                                {{ number_format((int) $tokenStats['effective_limit']) }}
                            @else
                                不限制
                            @endif
                        </td></tr>
                        <tr><th>剩余额度</th><td>
                            @if($tokenStats['remaining'] === null)
                                不限制
                            @else
                                {{ number_format((int) $tokenStats['remaining']) }}
                            @endif
                        </td></tr>
                    </table>
                </div>
                <div class="pro-card-subtitle" style="margin-top:10px;">人均配额优先级：用户专属 > 部门默认 > 每人默认月上限。此外，组织总池（每月 Token 总量）独立叠加生效。</div>
            </div>

            {{-- ====== 记忆概览 ====== --}}
            <div class="ant-tabs-panel" id="tab-memory">
                <div class="pro-card-subtitle" style="margin-bottom:10px;">已汇总该用户三层记忆的关键统计，点击可跳转完整记忆中心。</div>
                <div class="pro-table-wrap">
                    <table>
                        <tr><th>L1 会话文件数</th><td>{{ number_format((int) $memoryStats['sessions_count']) }}</td></tr>
                        <tr><th>L2 日志文件数</th><td>{{ number_format((int) $memoryStats['l2_files_count']) }}</td></tr>
                        <tr><th>L3 事实条目数</th><td>{{ number_format((int) $memoryStats['l3_facts_count']) }}</td></tr>
                        <tr><th>索引条目数</th><td>{{ number_format((int) $memoryStats['recent_entries_count']) }}</td></tr>
                        <tr><th>最近会话</th><td>{{ $memoryStats['latest_session']['session_key'] ?? '-' }}</td></tr>
                        <tr><th>最近 L2</th><td>{{ $memoryStats['latest_l2']['date'] ?? '-' }}</td></tr>
                    </table>
                </div>
                <div style="margin-top:14px;">
                    @adminCan('memory.view')
                    <a href="/admin/memory?user_id={{ $user->id }}" class="detail-action-btn">打开记忆中心</a>
                    @endadminCan
                </div>
                @if(trim((string) $memoryStats['l3_preview']) !== '')
                    <div class="pro-list" style="margin-top:14px;">
                        <div class="pro-list-item">
                            <div class="pro-muted">L3 预览</div>
                            <div style="white-space:pre-wrap; line-height:1.6; margin-top:6px; max-height:300px; overflow-y:auto;">{{ $memoryStats['l3_preview'] }}</div>
                        </div>
                    </div>
                @endif
            </div>

            {{-- ====== 可用 Skill ====== --}}
            <div class="ant-tabs-panel" id="tab-skill">
                <div class="pro-card-subtitle" style="margin-bottom:10px;">按"直接分配给用户"与"通过部门继承"合并展示。</div>
                @if($skills->isEmpty())
                    <div class="pro-empty">当前没有为该用户分配可用 Skill。</div>
                @else
                    <div class="pro-list">
                        @foreach($skills as $skill)
                            <div class="pro-list-item">
                                <div style="font-weight:600;">
                                    {{ $skill->name }} <span class="pro-code">/{{ $skill->skill_key }}</span>
                                </div>
                                <div class="pro-muted" style="margin-top:4px;">{{ $skill->description ?: '（无描述）' }}</div>
                                <div class="pro-inline-actions" style="margin-top:6px;">
                                    @if($skill->scope_user)<span class="pro-tag pro-tag-info">直接分配</span>@endif
                                    @if($skill->scope_department)<span class="pro-tag pro-tag-success">部门继承</span>@endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
                <div style="margin-top:14px;">
                    @adminCan('skills.view')
                    <a href="/admin/skills" class="detail-action-btn">技能管理</a>
                    @endadminCan
                </div>
            </div>

            {{-- ====== 最近运行 ====== --}}
            <div class="ant-tabs-panel" id="tab-runs">
                <div class="pro-card-subtitle" style="margin-bottom:10px;">用于排查"卡住 / 待授权 / 失败"链路，展示最近 20 条 Run 的最新状态与迁移记录。</div>
                @if($recentRuns->isEmpty())
                    <div class="pro-empty">暂无运行记录。</div>
                @else
                    <div class="runs-table-scroll" id="runsTableScroll">
                        <table id="runsTable">
                            <thead>
                                <tr>
                                    <th style="width:55px;">ID</th>
                                    <th style="width:95px;">状态</th>
                                    <th style="width:80px;">意图</th>
                                    <th style="width:50px;">交互</th>
                                    <th style="width:70px;">Token</th>
                                    <th style="width:140px;">创建时间</th>
                                    <th>最近迁移</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($recentRuns as $run)
                                    @php
                                        $latestTransition = $run->stateTransitions->last();
                                        $transitionText = $latestTransition
                                            ? (($latestTransition->from_status ?: 'null').' -> '.$latestTransition->to_status.' / '.($latestTransition->reason ?: '-'))
                                            : '-';
                                    @endphp
                                    <tr>
                                        <td>#{{ $run->id }}</td>
                                        <td>
                                            @php
                                                $statusClass = 'pro-tag-info';
                                                if ($run->status === 'success') { $statusClass = 'pro-tag-success'; }
                                                elseif ($run->status === 'failed') { $statusClass = 'pro-tag-error'; }
                                                elseif ($run->status === 'needs_input') { $statusClass = 'pro-tag-warning'; }
                                                elseif ($run->status === 'waiting_auth') { $statusClass = 'pro-tag-warning'; }
                                            @endphp
                                            <span class="pro-tag {{ $statusClass }}">{{ $run->status }}</span>
                                        </td>
                                        <td>{{ $run->intent_type ?: '-' }}</td>
                                        <td>{{ $run->interaction_mode ?: '-' }}</td>
                                        <td>{{ number_format((int) $run->input_tokens + (int) $run->output_tokens) }}</td>
                                        <td style="font-size:11px;">{{ $run->created_at }}</td>
                                        <td style="font-size:11px;" title="{{ $transitionText }}">{{ $transitionText }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="runs-pager" id="runsPager">
                        <span id="runsPageInfo"></span>
                        <div class="runs-pager-btns" id="runsPageBtns"></div>
                    </div>
                @endif
            </div>

            {{-- ====== 用户知识库 ====== --}}
            <div class="ant-tabs-panel" id="tab-archive">
                <div class="pro-card-subtitle" style="margin-bottom:10px;">过去 7 天系统每 2 小时（07:00~21:00 期间）扫描飞书活动后蒸馏的归档摘要。点击展开查看完整内容。</div>
                @if(empty($proactiveArchives) || $proactiveArchives->isEmpty())
                    <div class="pro-empty">过去 7 天暂无系统归档。归档每天 07/09/11/13/15/17/19/21 点触发，每次扫描过去 2 小时的飞书活动；如果该时段无实质活动则不写入。</div>
                @else
                    <div class="pro-list">
                        @foreach($proactiveArchives as $archive)
                            <details class="pro-list-item" style="cursor:pointer;">
                                <summary style="font-weight:600; outline:none;">
                                    {{ $archive->title }}
                                    <span class="pro-code">#{{ $archive->id }}</span>
                                    <span class="pro-muted" style="font-weight:400; margin-left:8px;">{{ $archive->created_at?->timezone('Asia/Shanghai')?->format('m-d H:i') }}</span>
                                </summary>
                                <div class="pro-muted" style="margin-top:6px; line-height:1.7; white-space:pre-wrap;">{{ $archive->content }}</div>
                            </details>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="ant-tabs-panel" id="tab-knowledge">
                <div class="pro-card-subtitle" style="margin-bottom:10px;">展示该用户上传文件解析后的知识库状态与最近文件。</div>
                <div class="pro-table-wrap">
                    <table>
                        <tr><th>文件总数</th><td>{{ number_format((int) ($knowledgeStats['attachments_total'] ?? 0)) }}</td></tr>
                        <tr><th>解析成功</th><td>{{ number_format((int) ($knowledgeStats['attachments_ready'] ?? 0)) }}</td></tr>
                        <tr><th>解析失败</th><td>{{ number_format((int) ($knowledgeStats['attachments_failed'] ?? 0)) }}</td></tr>
                        <tr><th>知识分块</th><td>{{ number_format((int) ($knowledgeStats['chunk_total'] ?? 0)) }}</td></tr>
                    </table>
                </div>
                @php $recentAttachments = $knowledgeStats['recent_attachments'] ?? collect(); @endphp
                @if($recentAttachments->isEmpty())
                    <div class="pro-empty" style="margin-top:10px;">暂无知识库文件。</div>
                @else
                    <div class="pro-list" style="margin-top:10px;">
                        @foreach($recentAttachments as $attachment)
                            <div class="pro-list-item">
                                <div style="font-weight:600;">
                                    {{ $attachment->file_name ?: ('attachment#'.$attachment->id) }}
                                    <span class="pro-code">#{{ $attachment->id }}</span>
                                </div>
                                <div class="pro-muted" style="margin-top:4px;">
                                    类型：{{ $attachment->attachment_type }} /
                                    状态：{{ $attachment->parse_status }} /
                                    大小：{{ $attachment->file_size ? number_format((int) $attachment->file_size) : '-' }} bytes /
                                    上传：{{ $attachment->created_at }}
                                </div>
                                @if(trim((string) $attachment->parse_error) !== '')
                                    <div class="pro-muted" style="margin-top:4px; color:#cf1322;">错误：{{ $attachment->parse_error }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // === Tabs 切换（锁定 scrollTop 防跳动） ===
        const tabs = document.querySelectorAll('.ant-tabs-tab');
        const panels = document.querySelectorAll('.ant-tabs-panel');
        tabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const scrollEl = document.documentElement;
                const savedTop = scrollEl.scrollTop;
                tabs.forEach(t => t.classList.remove('ant-tabs-tab-active'));
                panels.forEach(p => p.classList.remove('ant-tabs-panel-active'));
                tab.classList.add('ant-tabs-tab-active');
                document.getElementById('tab-' + tab.dataset.tab).classList.add('ant-tabs-panel-active');
                scrollEl.scrollTop = savedTop;
            });
        });

        // === 最近运行：前端分页 ===
        const table = document.getElementById('runsTable');
        if (table) {
            const rows = Array.from(table.querySelectorAll('tbody tr'));
            const pageSize = 10;
            const totalPages = Math.ceil(rows.length / pageSize);
            let currentPage = 1;

            function renderPage(page) {
                currentPage = page;
                rows.forEach((r, i) => {
                    r.style.display = (i >= (page-1)*pageSize && i < page*pageSize) ? '' : 'none';
                });
                document.getElementById('runsPageInfo').textContent =
                    '共 ' + rows.length + ' 条，第 ' + page + '/' + totalPages + ' 页';
                const btnsEl = document.getElementById('runsPageBtns');
                btnsEl.innerHTML = '';
                // Prev
                const prev = document.createElement('button');
                prev.textContent = '上一页';
                prev.disabled = page <= 1;
                prev.addEventListener('click', () => renderPage(page - 1));
                btnsEl.appendChild(prev);
                // Page numbers
                for (let i = 1; i <= totalPages; i++) {
                    const btn = document.createElement('button');
                    btn.textContent = i;
                    if (i === page) btn.className = 'pager-active';
                    btn.addEventListener('click', () => renderPage(i));
                    btnsEl.appendChild(btn);
                }
                // Next
                const next = document.createElement('button');
                next.textContent = '下一页';
                next.disabled = page >= totalPages;
                next.addEventListener('click', () => renderPage(page + 1));
                btnsEl.appendChild(next);
                // Scroll to top of table area
                document.getElementById('runsTableScroll').scrollTop = 0;
            }
            renderPage(1);
        }

        // === 自定义确认弹窗 ===
        document.querySelectorAll('[data-confirm]').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const action = this.dataset.confirm;
                const isDisable = action === '停用';
                const form = this.closest('form');
                const overlay = document.createElement('div');
                overlay.className = 'confirm-overlay';
                overlay.innerHTML = `
                    <div class="confirm-box">
                        <div class="confirm-icon ${isDisable ? 'warn' : 'enable'}">${isDisable ? '⚠' : '✓'}</div>
                        <div class="confirm-title">确定${action}该用户？</div>
                        <div class="confirm-desc">${isDisable ? '停用后该用户将无法使用系统功能，可随时重新启用。' : '启用后该用户将恢复正常使用权限。'}</div>
                        <div class="confirm-actions">
                            <button class="btn-cancel" type="button">取消</button>
                            <button class="${isDisable ? 'btn-confirm-danger' : 'btn-confirm-success'}" type="button">确定${action}</button>
                        </div>
                    </div>
                `;
                document.body.appendChild(overlay);
                overlay.querySelector('.btn-cancel').addEventListener('click', () => overlay.remove());
                overlay.addEventListener('click', (ev) => { if (ev.target === overlay) overlay.remove(); });
                overlay.querySelector('.confirm-actions button:last-child').addEventListener('click', () => {
                    overlay.remove();
                    form.submit();
                });
            });
        });
    });
    </script>
@endsection
