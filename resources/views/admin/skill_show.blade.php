@extends('admin.layout')

@section('title', '米蛙管理后台 - 技能详情')
@section('page-title', '技能详情')
@section('page-desc', '只读概览技能配置、调用表现与资产，需要改动时打开编辑抽屉')

@section('header-actions')
    <a href="/admin/skills" class="pro-btn">返回技能列表</a>
@endsection

@push('head')
<style>
    /* ── Page scoped tokens ── */
    .sd-chip {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 8px;
        font-size: 12px;
        border-radius: 999px;
        border: 1px solid rgba(17, 35, 45, 0.08);
        background: #f4f6f8;
        color: #4d6470;
        font-weight: 500;
    }
    .sd-chip-success { background:#e8f5ee; border-color:#bfe2cd; color:#106a3f; }
    .sd-chip-muted   { background:#f4f4f5; border-color:#e4e4e7; color:#6b7280; }
    .sd-chip-info    { background:#e0f2fe; border-color:#bae6fd; color:#0369a1; }
    .sd-chip-warn    { background:#fef3c7; border-color:#fde68a; color:#92400e; }

    /* ── Header card ── */
    .sd-header {
        background: linear-gradient(135deg, #ffffff 0%, #f8fcfa 100%);
        border: 1px solid rgba(17, 35, 45, 0.09);
        border-radius: 14px;
        box-shadow: var(--pro-shadow-xs);
        padding: 18px 20px;
        margin-bottom: 14px;
    }
    .sd-header-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
    }
    .sd-header-title-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .sd-header-name {
        font-size: 20px;
        font-weight: 700;
        color: #1a3340;
        margin: 0;
    }
    .sd-header-slug {
        font-family: Consolas, 'Courier New', monospace;
        font-size: 13px;
        color: #4d6470;
        background: #f4f6f8;
        padding: 2px 8px;
        border-radius: 6px;
    }
    .sd-header-desc {
        color: #4d6470;
        font-size: 13px;
        margin: 8px 0 10px 0;
        line-height: 1.6;
    }
    .sd-header-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 14px;
        font-size: 12px;
        color: #6b7a83;
    }
    .sd-header-meta span {
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .sd-header-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    /* ── Stats row ── */
    .sd-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
        margin-bottom: 14px;
    }
    .sd-stat-card {
        background: #fff;
        border: 1px solid rgba(17, 35, 45, 0.09);
        border-radius: 12px;
        box-shadow: var(--pro-shadow-xs);
        padding: 14px 16px;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .sd-stat-label {
        font-size: 12px;
        color: #6b7a83;
        font-weight: 500;
    }
    .sd-stat-value {
        font-size: 22px;
        font-weight: 700;
        color: #1a3340;
        line-height: 1.2;
    }
    .sd-stat-value small {
        font-size: 12px;
        color: #6b7a83;
        font-weight: 400;
        margin-left: 4px;
    }
    .sd-stat-spark {
        height: 36px;
        margin-top: 2px;
    }
    .sd-stat-spark svg { display: block; width: 100%; height: 100%; }

    /* ── Two column body ── */
    .sd-body {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 360px;
        gap: 14px;
        margin-bottom: 14px;
        align-items: start;
    }
    /* 防止网格子项被其内容的 min-content 撑爆 */
    .sd-body-left, .sd-body-right { min-width: 0; }
    .sd-section-title {
        font-size: 13px;
        font-weight: 600;
        color: #4d6470;
        margin: 0 0 10px;
        padding-bottom: 8px;
        border-bottom: 1px solid rgba(17, 35, 45, 0.06);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .sd-md-preview {
        background: #fafbfc;
        border: 1px solid rgba(17, 35, 45, 0.06);
        border-radius: 8px;
        padding: 12px 14px;
        font-family: Consolas, 'Courier New', monospace;
        font-size: 12px;
        line-height: 1.55;
        color: #334654;
        max-height: 380px;
        overflow: auto;
        white-space: pre-wrap;
        word-break: break-word;
    }
    .sd-config-row {
        display: flex;
        justify-content: space-between;
        padding: 6px 0;
        font-size: 13px;
        border-bottom: 1px dashed rgba(17, 35, 45, 0.06);
    }
    .sd-config-row:last-child { border-bottom: none; }
    .sd-config-row .k { color: #6b7a83; }
    .sd-config-row .v {
        color: #1a3340;
        font-weight: 500;
        text-align: right;
        max-width: 60%;
        word-break: break-all;
    }
    .sd-assign-list {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    .sd-assign-list li {
        padding: 3px 9px;
        background: #f4f6f8;
        border: 1px solid rgba(17, 35, 45, 0.06);
        border-radius: 999px;
        font-size: 12px;
        color: #4d6470;
    }
    .sd-empty { color: #98a3aa; font-size: 12px; padding: 6px 0; }

    /* ── Files section (collapsible) ── */
    .sd-files-head {
        cursor: pointer;
        user-select: none;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding: 2px 0;
    }
    .sd-files-head .arrow {
        transition: transform 0.18s ease;
        font-size: 12px;
        color: #6b7a83;
    }
    .sd-files-head[data-open="true"] .arrow { transform: rotate(90deg); }
    .sd-files-body { display: none; margin-top: 12px; }
    .sd-files-body[data-open="true"] { display: block; }

    /* ── Invocations table ── */
    .sd-invoc-table th,
    .sd-invoc-table td { white-space: nowrap; }
    .sd-invoc-table th:nth-child(6),
    .sd-invoc-table td:nth-child(6) {
        white-space: normal;
        word-break: break-all;
    }
    .sd-empty-invoc {
        text-align: center;
        padding: 28px 0;
        color: #9aa7b0;
        font-size: 13px;
    }

    /* ── Drawer ── */
    .sd-drawer-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(12, 20, 28, 0.36);
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.18s ease;
        z-index: 80;
    }
    .sd-drawer-backdrop[data-open="true"] { opacity: 1; pointer-events: auto; }
    .sd-drawer {
        position: fixed;
        top: 0;
        right: 0;
        height: 100vh;
        width: min(720px, 94vw);
        background: #fff;
        box-shadow: -12px 0 36px rgba(12, 20, 28, 0.12);
        transform: translateX(100%);
        transition: transform 0.22s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 90;
        display: flex;
        flex-direction: column;
    }
    .sd-drawer[data-open="true"] { transform: translateX(0); }
    .sd-drawer-head {
        padding: 14px 18px;
        border-bottom: 1px solid rgba(17, 35, 45, 0.08);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .sd-drawer-head h3 { margin: 0; font-size: 15px; color: #1a3340; }
    .sd-drawer-close {
        background: none;
        border: none;
        font-size: 20px;
        line-height: 1;
        color: #6b7a83;
        cursor: pointer;
        padding: 4px 8px;
        border-radius: 6px;
    }
    .sd-drawer-close:hover { background: #f4f6f8; }
    .sd-drawer-body {
        flex: 1;
        overflow: auto;
        padding: 18px;
    }
    .sd-drawer-foot {
        padding: 12px 18px;
        border-top: 1px solid rgba(17, 35, 45, 0.08);
        background: #fafbfc;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
    }

    .sd-btn-ghost[disabled] {
        opacity: 0.55;
        cursor: not-allowed;
    }


    /* ── Switch group (pill container + iOS toggle) ── */
    .sd-switch-group {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 6px 12px 6px 14px;
        background: #f4f6f8;
        border: 1px solid rgba(17, 35, 45, 0.08);
        border-radius: 8px;
        font-size: 13px;
        color: #4d6470;
        font-weight: 500;
        cursor: pointer;
        user-select: none;
        transition: background 0.15s, border-color 0.15s;
    }
    .sd-switch-group.on {
        background: #eaf8f1;
        border-color: #c7ecdb;
        color: #0a5a42;
    }
    .sd-switch {
        position: relative;
        width: 34px;
        height: 20px;
        background: #c9d0d6;
        border-radius: 999px;
        flex-shrink: 0;
        transition: background 0.15s;
    }
    .sd-switch::after {
        content: '';
        position: absolute;
        top: 2px;
        left: 2px;
        width: 16px;
        height: 16px;
        background: #fff;
        border-radius: 50%;
        box-shadow: 0 1px 2px rgba(12, 20, 28, 0.2);
        transition: transform 0.15s;
    }
    .sd-switch-group.on .sd-switch { background: #0f9d6f; }
    .sd-switch-group.on .sd-switch::after { transform: translateX(14px); }

    /* ── Modern file table ── */
    .sd-files-card-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }
    .sd-files-card-head .head-right {
        display: flex;
        align-items: center;
        gap: 10px;
        color: #8a9198;
        font-size: 12px;
    }
    .sd-file-table {
        width: 100%;
        /* 覆盖全局 table min-width: max(100%, 6列宽)，我们的 4 列表需要自由收缩 */
        min-width: 0;
        table-layout: fixed;
        border-collapse: collapse;
        font-size: 13px;
        margin-top: 12px;
    }
    .sd-file-table td:first-child {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .sd-file-table th {
        text-align: left;
        font-weight: 500;
        color: #6b7a83;
        font-size: 12px;
        padding: 10px 14px;
        background: #f7f8f9;
        border-bottom: 1px solid rgba(17, 35, 45, 0.08);
    }
    .sd-file-table td {
        padding: 10px 14px;
        border-bottom: 1px solid rgba(17, 35, 45, 0.06);
        vertical-align: middle;
    }
    .sd-file-table tr:last-child td { border-bottom: none; }
    .sd-file-table tr:hover td { background: #fafbfc; }
    .sd-file-name {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-family: Consolas, 'Courier New', monospace;
        font-size: 12.5px;
        color: #1a3340;
    }
    .sd-file-name .ficon {
        display: inline-block;
        width: 14px;
        text-align: center;
        color: #8a9198;
        flex-shrink: 0;
    }
    .sd-file-badge {
        display: inline-block;
        font-size: 10px;
        background: #d6f2e2;
        color: #0a5a42;
        padding: 1px 6px;
        border-radius: 3px;
        font-family: inherit;
        font-weight: 500;
        margin-left: 4px;
    }
    .sd-file-size, .sd-file-time {
        color: #8a9198;
        font-variant-numeric: tabular-nums;
        font-size: 12px;
    }
    .sd-file-actions {
        text-align: right;
        white-space: nowrap;
    }
    .sd-icon-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 6px;
        border: 1px solid transparent;
        background: transparent;
        color: #4d6470;
        cursor: pointer;
        font-size: 13px;
        line-height: 1;
        padding: 0;
        margin-left: 2px;
        transition: background 0.12s, color 0.12s;
    }
    .sd-icon-btn:hover { background: #f0f3f5; color: #1a3340; }
    .sd-icon-btn.danger:hover { background: #fbe8e8; color: #b91c1c; }
    .sd-icon-btn[disabled] { opacity: 0.4; cursor: not-allowed; }

    /* ── File modal ── */
    .sd-modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(12, 20, 28, 0.42);
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.18s ease;
        z-index: 100;
    }
    .sd-modal-backdrop[data-open="true"] { opacity: 1; pointer-events: auto; }
    .sd-modal {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) scale(0.96);
        width: min(720px, 92vw);
        max-height: 84vh;
        background: #fff;
        border: 1px solid rgba(17, 35, 45, 0.12);
        border-radius: 12px;
        box-shadow: 0 16px 48px rgba(12, 20, 28, 0.18);
        z-index: 110;
        display: none;
        flex-direction: column;
        opacity: 0;
        transition: opacity 0.18s ease, transform 0.18s ease;
    }
    .sd-modal[data-open="true"] {
        display: flex;
        opacity: 1;
        transform: translate(-50%, -50%) scale(1);
    }
    .sd-modal-head {
        padding: 14px 18px;
        border-bottom: 1px solid rgba(17, 35, 45, 0.08);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }
    .sd-modal-head h3 { margin: 0; font-size: 14px; color: #1a3340; }
    .sd-modal-head .sub {
        font-size: 12px;
        color: #8a9198;
        font-family: Consolas, 'Courier New', monospace;
        margin-top: 2px;
    }
    .sd-modal-body {
        padding: 18px;
        overflow: auto;
        flex: 1;
    }
    .sd-modal-foot {
        padding: 12px 18px;
        border-top: 1px solid rgba(17, 35, 45, 0.08);
        background: #fafbfc;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
    }
    .sd-modal-foot .foot-msg { font-size: 12px; color: #8a9198; }
    .sd-modal-tabs {
        display: flex;
        gap: 0;
        border-bottom: 1px solid rgba(17, 35, 45, 0.08);
        padding: 0 18px;
    }
    .sd-modal-tab {
        padding: 10px 14px;
        font-size: 13px;
        color: #6b7a83;
        cursor: pointer;
        border-bottom: 2px solid transparent;
        margin-bottom: -1px;
        font-weight: 500;
        background: transparent;
        border-left: none;
        border-right: none;
        border-top: none;
        font-family: inherit;
    }
    .sd-modal-tab:hover { color: #1a3340; }
    .sd-modal-tab[data-active="true"] { color: #0a5a42; border-bottom-color: #0f9d6f; }
    .sd-modal-tab-panel { display: none; }
    .sd-modal-tab-panel[data-active="true"] { display: block; }

    @media (max-width: 1220px) {
        .sd-body { grid-template-columns: 1fr; }
        .sd-stats { grid-template-columns: repeat(3, 1fr); }
    }
    @media (max-width: 720px) {
        .sd-stats { grid-template-columns: 1fr; }
    }

    /* ── Assign card ── */
    .sd-assign-card .sd-section-title {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .sd-assign-reach {
        font-size: 12px;
        color: #6b7a83;
        margin-left: auto;
    }
    .sd-assign-reach strong { color: #1a3340; font-weight: 600; }
    .sd-assign-block { margin-bottom: 12px; }
    .sd-assign-block:last-child { margin-bottom: 0; }
    .sd-assign-block-label {
        font-size: 11px;
        color: #6b7a83;
        margin-bottom: 6px;
        font-weight: 500;
    }
    .sd-assign-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    .sd-chip-dept { background: #e0f2fe; border-color: #bae6fd; color: #0369a1; }
    .sd-chip-user { background: #f4f6f8; border-color: #e4e7eb; color: #334155; }
    .sd-chip-icon { font-size: 10px; opacity: 0.7; }
    .sd-chip-avatar {
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background: #0f9d6f;
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        font-weight: 600;
    }
    .sd-chip-avatar-muted { background: #94a3b8; }
    .sd-chip-count { margin-left: 2px; font-size: 11px; opacity: 0.7; }
    .sd-chip-from { margin-left: 4px; font-size: 10px; color: #94a3b8; }
    .sd-chip-inherited { opacity: 0.85; }
    .sd-assign-hint {
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px dashed rgba(17, 35, 45, 0.08);
        font-size: 12px;
        color: #6b7a83;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    /* ── Assign popover ── */
    .sd-popover-backdrop {
        position: fixed;
        inset: 0;
        background: transparent;
        z-index: 1200;
        display: none;
    }
    .sd-popover-backdrop[data-open="true"] { display: block; }
    .sd-popover {
        position: absolute;
        width: 360px;
        max-height: 480px;
        background: #ffffff;
        border: 1px solid rgba(17, 35, 45, 0.12);
        border-radius: 10px;
        box-shadow: 0 10px 30px rgba(17, 35, 45, 0.12);
        z-index: 1201;
        display: none;
        flex-direction: column;
        overflow: hidden;
    }
    .sd-popover[data-open="true"] { display: flex; }
    .sd-popover-search {
        padding: 10px 12px;
        border-bottom: 1px solid rgba(17, 35, 45, 0.08);
        display: flex;
        align-items: center;
        gap: 8px;
        background: #fafbfc;
    }
    .sd-popover-search-icon { color: #94a3b8; font-size: 14px; }
    .sd-popover-search input {
        border: 0;
        outline: 0;
        flex: 1;
        font-size: 13px;
        background: transparent;
    }
    .sd-popover-list { flex: 1; overflow-y: auto; padding: 4px 0; }
    .sd-popover-section-title {
        padding: 8px 12px 4px;
        font-size: 11px;
        color: #94a3b8;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .sd-popover-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 12px;
        cursor: pointer;
        font-size: 13px;
        color: #334155;
        user-select: none;
    }
    .sd-popover-item:hover { background: #f4f6f8; }
    .sd-popover-item.is-selected { background: #e8f5ee; }
    .sd-popover-item.is-inherited { opacity: 0.7; cursor: default; }
    .sd-popover-item.is-inherited:hover { background: transparent; }
    .sd-popover-check {
        width: 14px;
        height: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #0f9d6f;
        font-size: 12px;
        font-weight: 700;
    }
    .sd-popover-avatar {
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background: #0f9d6f;
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        font-weight: 600;
    }
    .sd-popover-item-dept .sd-popover-avatar {
        background: #e0f2fe;
        color: #0369a1;
        font-size: 11px;
    }
    .sd-popover-name { flex: 1; }
    .sd-popover-meta { font-size: 11px; color: #94a3b8; }
    .sd-popover-meta-info { color: #0369a1; }
    .sd-popover-empty { padding: 24px; text-align: center; color: #94a3b8; font-size: 12px; }
    .sd-popover-foot {
        padding: 10px 12px;
        border-top: 1px solid rgba(17, 35, 45, 0.08);
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #fafbfc;
    }
    .sd-popover-counter { font-size: 11px; color: #6b7a83; }
</style>
@endpush

@section('content')
    @php
        $assignedDeptIds = $skill->assignments->pluck('department_id')->filter()->map(fn($id) => (int) $id)->unique()->values()->all();
        $assignedUserIds = $skill->assignments->pluck('user_id')->filter()->map(fn($id) => (int) $id)->unique()->values()->all();

        // 继承：用户的 department_id 若在 assignedDeptIds 中，则视为通过部门继承获得访问权
        $assignedDeptIdSet = array_flip($assignedDeptIds);
        $inheritedUserIds = [];
        foreach ($users as $u) {
            if ($u->department_id && isset($assignedDeptIdSet[(int) $u->department_id])) {
                $inheritedUserIds[] = (int) $u->id;
            }
        }
        $inheritedUserIdSet = array_flip($inheritedUserIds);

        $assignedDepts = collect($departments)->filter(fn($d) => in_array((int) $d->id, $assignedDeptIds, true))->values();
        $assignedUsers = collect($users)->filter(fn($u) => in_array((int) $u->id, $assignedUserIds, true))->values();
        $directUsers = $assignedUsers->filter(fn($u) => ! isset($inheritedUserIdSet[(int) $u->id]))->values();
        $inheritedUsers = collect($users)->filter(fn($u) => isset($inheritedUserIdSet[(int) $u->id]))->values();
        $totalReach = collect([...$directUsers->pluck('id')->all(), ...$inheritedUsers->pluck('id')->all()])
            ->map(fn($id) => (int) $id)->unique()->count();

        // Popover 数据（JSON 注入到 JS 运行时）
        $popoverData = [
            'departments' => collect($departments)->map(fn($d) => [
                'id' => (int) $d->id,
                'name' => (string) $d->name,
                'count' => (int) ($d->users_count ?? 0),
            ])->values()->all(),
            'users' => collect($users)->map(fn($u) => [
                'id' => (int) $u->id,
                'name' => (string) $u->display_name,
                'dept' => $u->department_id ? (int) $u->department_id : null,
                'feishu' => (string) ($u->feishu_open_id ?? ''),
            ])->values()->all(),
            'assigned' => [
                'departments' => $assignedDeptIds,
                'users' => $assignedUserIds,
            ],
        ];

        $executor = $frontMatter['executor'] ?? ($skill->meta['executor'] ?? 'llm');
        $taskKinds = (array) ($frontMatter['task_kinds'] ?? []);
        $caps = (array) ($frontMatter['required_capabilities'] ?? []);
        $interpreter = $frontMatter['sandbox_interpreter'] ?? null;
        $timeout = $frontMatter['sandbox_timeout'] ?? ($frontMatter['api_timeout'] ?? null);
        $apiMethod = $frontMatter['api_method'] ?? null;
        $apiUrl = $frontMatter['api_url'] ?? null;

        $executorLabel = [
            'llm' => '提示词 (Prompt)',
            'sandbox' => '脚本 (Sandbox)',
            'http_api' => '内部 API',
        ][$executor] ?? $executor;

        $stats = $stats ?? [
            'invoke_30d' => 0,
            'success_rate' => null,
            'avg_duration_s' => null,
            'spark_7d' => [0,0,0,0,0,0,0],
            'spark_labels' => [],
        ];
        $spark = $stats['spark_7d'] ?? [0,0,0,0,0,0,0];
        $sparkMax = max(1, max($spark));
    @endphp

    {{-- ====== Header card ====== --}}
    <div class="sd-header">
        <div class="sd-header-row">
            <div style="min-width:0; flex:1;">
                <div class="sd-header-title-row">
                    <h2 class="sd-header-name">{{ $skill->name }}</h2>
                    <span class="sd-header-slug">/{{ $skill->skill_key }}</span>
                    <span class="sd-chip {{ $skill->is_active ? 'sd-chip-success' : 'sd-chip-muted' }}">
                        {{ $skill->is_active ? '● 已启用' : '○ 已停用' }}
                    </span>
                    <span class="sd-chip sd-chip-info">{{ $executorLabel }}</span>
                </div>
                @if($skill->description)
                    <div class="sd-header-desc">{{ $skill->description }}</div>
                @endif
                <div class="sd-header-meta">
                    <span title="Skill ID">ID #{{ $skill->id }}</span>
                    <span title="创建时间">创建于 {{ $skill->created_at?->format('Y-m-d') ?? '-' }}</span>
                    <span title="最后修改">最后修改 {{ $skill->updated_at?->diffForHumans() ?? '-' }}</span>
                    <span title="存储路径" style="font-family:Consolas, 'Courier New', monospace;">{{ $skill->storage_path }}</span>
                </div>
            </div>
            <div class="sd-header-actions">
                @adminCan('skills.status')
                    <form method="post" action="/admin/skills/{{ $skill->id }}/status" style="margin:0;" id="sd-status-form">
                        @csrf
                        <input type="hidden" name="is_active" value="{{ $skill->is_active ? 0 : 1 }}">
                        <button type="submit" class="sd-switch-group {{ $skill->is_active ? 'on' : '' }}" title="点击切换启用状态" style="border:none; font:inherit;">
                            <span>{{ $skill->is_active ? '已启用' : '已停用' }}</span>
                            <span class="sd-switch"></span>
                        </button>
                    </form>
                @endadminCan
                <button type="button" class="pro-btn sd-btn-ghost" disabled title="即将推出：在管理后台直接试跑技能并查看调用效果">▶ 试跑</button>
                @adminCan('skills.update')
                    <button type="button" class="pro-btn" id="sd-open-edit">编辑</button>
                @endadminCan
                @adminCan('skills.delete')
                    <button type="button" class="pro-btn pro-btn-danger" id="sd-open-delete">删除</button>
                @endadminCan
            </div>
        </div>
    </div>

    {{-- ====== Stats row ====== --}}
    <div class="sd-stats">
        <div class="sd-stat-card">
            <div class="sd-stat-label">近 30 天调用</div>
            <div class="sd-stat-value">{{ number_format($stats['invoke_30d'] ?? 0) }}<small>次</small></div>
            <div class="sd-stat-spark" title="近 7 天每日调用趋势">
                <svg viewBox="0 0 100 36" preserveAspectRatio="none">
                    @php
                        $points = [];
                        $n = max(1, count($spark) - 1);
                        foreach ($spark as $i => $v) {
                            $x = $n > 0 ? ($i / $n) * 100 : 0;
                            $y = 34 - (($v / $sparkMax) * 30);
                            $points[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
                        }
                        $polyPoints = implode(' ', $points);
                        $areaPoints = '0,36 ' . $polyPoints . ' 100,36';
                    @endphp
                    <polygon points="{{ $areaPoints }}" fill="rgba(15, 157, 111, 0.12)" />
                    <polyline points="{{ $polyPoints }}" fill="none" stroke="#0f9d6f" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" />
                </svg>
            </div>
        </div>
        <div class="sd-stat-card">
            <div class="sd-stat-label">成功率（30 天）</div>
            <div class="sd-stat-value">
                @if($stats['success_rate'] === null)
                    <span style="color:#98a3aa; font-size:16px;">暂无数据</span>
                @else
                    {{ number_format($stats['success_rate'] * 100, 1) }}<small>%</small>
                @endif
            </div>
            <div class="sd-stat-label" style="color:#98a3aa;">
                @if(($stats['runs_total'] ?? 0) > 0)
                    成功 {{ $stats['runs_success'] ?? 0 }} / 总 {{ $stats['runs_total'] }}
                @else
                    暂无关联的 Run 记录
                @endif
            </div>
        </div>
        <div class="sd-stat-card">
            <div class="sd-stat-label">平均耗时（30 天）</div>
            <div class="sd-stat-value">
                @if($stats['avg_duration_s'] === null)
                    <span style="color:#98a3aa; font-size:16px;">暂无数据</span>
                @else
                    {{ $stats['avg_duration_s'] < 10 ? number_format($stats['avg_duration_s'], 1) : number_format($stats['avg_duration_s'], 0) }}<small>秒</small>
                @endif
            </div>
            <div class="sd-stat-label" style="color:#98a3aa;">从 run.started_at 到 finished_at</div>
        </div>
    </div>

    {{-- ====== Two column body ====== --}}
    <div class="sd-body">
        {{-- Left: skill.md + files --}}
        <div class="sd-body-left">
            <div class="pro-card" style="margin-bottom:14px;">
                <h4 class="sd-section-title">
                    <span>skill.md 内容预览</span>
                    @adminCan('skills.update')
                    <button type="button" class="pro-btn pro-btn-sm" id="sd-edit-md">编辑</button>
                    @endadminCan
                </h4>
                <div class="sd-md-preview">{{ $skill->skill_md }}</div>
            </div>

            <div class="pro-card">
                <div class="sd-files-card-head">
                    <h4 class="sd-section-title" style="margin:0;">
                        <span>技能文件 <span class="pro-muted" style="font-weight:400; font-size:12px; margin-left:6px;">{{ count($customFiles) }} 个</span></span>
                    </h4>
                    <div class="head-right">
                        @adminCan('skills.files.manage')
                            <button type="button" class="pro-btn pro-btn-sm" id="sd-new-file-btn">+ 新建</button>
                        @endadminCan
                    </div>
                </div>

                @if(! empty($fileError))
                    <div class="pro-alert pro-alert-warning" style="margin:10px 0 0;">{{ $fileError }}</div>
                @endif
                @error('file_error')
                    <div class="pro-alert pro-alert-warning" style="margin:10px 0 0;">{{ $message }}</div>
                @enderror

                <table class="sd-file-table">
                    <thead>
                    <tr>
                        <th>文件</th>
                        <th style="width:80px;">大小</th>
                        <th style="width:160px;">更新时间</th>
                        <th style="width:100px; text-align:right;">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($customFiles as $file)
                        @php
                            $isPrimary = in_array(basename((string) $file['path']), ['skill.md', 'entry.py', 'prompt.md'], true);
                            $isEditable = ! empty($file['editable']);
                        @endphp
                        <tr data-file-path="{{ $file['path'] }}">
                            <td title="{{ $file['path'] }}">
                                <span class="sd-file-name">
                                    <span class="ficon">📄</span>
                                    {{ $file['path'] }}
                                    @if($isPrimary)
                                        <span class="sd-file-badge">入口</span>
                                    @endif
                                </span>
                            </td>
                            <td class="sd-file-size">{{ number_format((int) ($file['size'] ?? 0)) }} B</td>
                            <td class="sd-file-time">{{ $file['updated_at'] ?? '-' }}</td>
                            <td class="sd-file-actions">
                                @if($isEditable)
                                    @adminCan('skills.files.manage')
                                        <button type="button" class="sd-icon-btn" title="编辑" onclick="window.__openEditFileModal('{{ addslashes((string) $file['path']) }}')">✎</button>
                                    @endadminCan
                                @else
                                    <button type="button" class="sd-icon-btn" title="不可编辑" disabled>✎</button>
                                @endif
                                @adminCan('skills.files.download')
                                    <a href="/admin/skills/{{ $skill->id }}/files/download?file_path={{ urlencode($file['path']) }}" class="sd-icon-btn" title="下载">⬇</a>
                                @endadminCan
                                @if(! $isPrimary)
                                    @adminCan('skills.files.manage')
                                    <form method="post" action="/admin/skills/{{ $skill->id }}/files/delete" style="display:inline; margin:0;">
                                        @csrf
                                        <input type="hidden" name="file_path" value="{{ $file['path'] }}">
                                        <button type="submit" class="sd-icon-btn danger" title="删除"
                                            onclick="return confirm('确认删除 {{ $file['path'] }}？');">🗑</button>
                                    </form>
                                    @endadminCan
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="pro-muted" style="text-align:center; padding:18px;">暂无自定义文件</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Right: config + assignments --}}
        <div class="sd-body-right">
            <div class="pro-card" style="margin-bottom:14px;">
                <h4 class="sd-section-title"><span>执行配置</span></h4>
                <div class="sd-config-row">
                    <span class="k">执行器</span><span class="v">{{ $executorLabel }}</span>
                </div>
                @if($executor === 'sandbox' && $interpreter)
                    <div class="sd-config-row"><span class="k">解释器</span><span class="v">{{ $interpreter }}</span></div>
                @endif
                @if($executor === 'http_api' && $apiMethod)
                    <div class="sd-config-row"><span class="k">HTTP Method</span><span class="v">{{ $apiMethod }}</span></div>
                @endif
                @if($executor === 'http_api' && $apiUrl)
                    <div class="sd-config-row"><span class="k">API URL</span><span class="v" style="font-family:Consolas, 'Courier New', monospace; font-size:11px;">{{ \Illuminate\Support\Str::limit($apiUrl, 60) }}</span></div>
                @endif
                @if($timeout)
                    <div class="sd-config-row"><span class="k">超时</span><span class="v">{{ $timeout }} 秒</span></div>
                @endif
                @if(! empty($taskKinds))
                    <div class="sd-config-row">
                        <span class="k">任务类型</span>
                        <span class="v">
                            @foreach($taskKinds as $tk)
                                <span class="sd-chip sd-chip-muted" style="margin-left:2px;">{{ $tk }}</span>
                            @endforeach
                        </span>
                    </div>
                @endif
                @if(! empty($caps))
                    <div class="sd-config-row">
                        <span class="k">能力要求</span>
                        <span class="v">
                            @foreach($caps as $cap)
                                <span class="sd-chip sd-chip-muted" style="margin-left:2px;">{{ $cap }}</span>
                            @endforeach
                        </span>
                    </div>
                @endif
            </div>

            <div class="pro-card sd-assign-card">
                <h4 class="sd-section-title">
                    <span>分配</span>
                    <span class="sd-assign-reach">可访问 <strong>{{ $totalReach }}</strong> 人</span>
                    @adminCan('skills.assign')
                        <button type="button" class="pro-btn pro-btn-sm" id="sd-edit-assign">修改</button>
                    @endadminCan
                </h4>

                <div class="sd-assign-block">
                    <div class="sd-assign-block-label">部门 · {{ $assignedDepts->count() }}</div>
                    @if($assignedDepts->isEmpty())
                        <div class="sd-empty">未分配给任何部门</div>
                    @else
                        <div class="sd-assign-chips">
                            @foreach($assignedDepts as $d)
                                <span class="sd-chip sd-chip-dept" title="共 {{ $d->users_count ?? 0 }} 人">
                                    <span class="sd-chip-icon">▦</span>
                                    {{ $d->name }}
                                    <span class="sd-chip-count">{{ $d->users_count ?? 0 }}</span>
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="sd-assign-block">
                    <div class="sd-assign-block-label">用户 · {{ $directUsers->count() + $inheritedUsers->count() }}</div>
                    @if($directUsers->isEmpty() && $inheritedUsers->isEmpty())
                        <div class="sd-empty">未直接分配给任何用户</div>
                    @else
                        <div class="sd-assign-chips">
                            @foreach($directUsers as $u)
                                <span class="sd-chip sd-chip-user">
                                    <span class="sd-chip-avatar">{{ mb_substr($u->display_name, 0, 1) }}</span>
                                    {{ $u->display_name }}
                                </span>
                            @endforeach
                            @foreach($inheritedUsers as $u)
                                @php $dept = collect($departments)->firstWhere('id', $u->department_id); @endphp
                                <span class="sd-chip sd-chip-user sd-chip-inherited" title="通过部门「{{ $dept->name ?? '' }}」继承">
                                    <span class="sd-chip-avatar sd-chip-avatar-muted">{{ mb_substr($u->display_name, 0, 1) }}</span>
                                    {{ $u->display_name }}
                                    <span class="sd-chip-from">来自 {{ $dept->name ?? '' }}</span>
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>

                @if($inheritedUsers->isNotEmpty())
                    <div class="sd-assign-hint">
                        <span class="sd-chip sd-chip-info" style="padding:1px 6px;">继承</span>
                        有 {{ $inheritedUsers->count() }} 位用户通过部门分配自动获得访问权
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ====== Invocations ====== --}}
    <div class="pro-card">
        <h4 class="sd-section-title"><span>调用记录（最近 30 条）</span></h4>
        @if($recentInvocations->isEmpty())
            <div class="sd-empty-invoc">暂无调用记录</div>
        @else
            <div class="pro-table-wrap sd-invoc-table">
                <table>
                    <thead>
                    <tr>
                        <th style="width:70px;">Run ID</th>
                        <th style="width:110px;">用户</th>
                        <th style="width:80px;">意图</th>
                        <th style="width:80px;">状态</th>
                        <th style="width:90px;">匹配方式</th>
                        <th>事件内容</th>
                        <th style="width:150px;">时间</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($recentInvocations as $item)
                        <tr>
                            <td>{{ $item->run_id ?: '-' }}</td>
                            <td>{{ $item->user_display_name }}</td>
                            <td>{{ $item->run?->intent_type ?: '-' }}</td>
                            <td>
                                @php $st = $item->run?->status; @endphp
                                @if($st === 'success')
                                    <span class="sd-chip sd-chip-success">成功</span>
                                @elseif($st === 'failed')
                                    <span class="sd-chip sd-chip-warn">失败</span>
                                @elseif($st)
                                    <span class="sd-chip sd-chip-muted">{{ $st }}</span>
                                @else
                                    <span class="pro-muted">-</span>
                                @endif
                            </td>
                            <td>{{ $item->match_type ?: '-' }}</td>
                            <td>{{ $item->message ?: '-' }}</td>
                            <td>{{ $item->created_at }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ====== Edit Drawer ====== --}}
    <div class="sd-drawer-backdrop" id="sd-drawer-backdrop" data-open="false"></div>
    <aside class="sd-drawer" id="sd-drawer" data-open="false" aria-hidden="true">
        <div class="sd-drawer-head">
            <h3>编辑技能 · {{ $skill->name }}</h3>
            <button type="button" class="sd-drawer-close" id="sd-drawer-close" aria-label="关闭">×</button>
        </div>
        <form method="post" action="/admin/skills/{{ $skill->id }}" id="sd-edit-form" style="display:contents;">
            @csrf
            <div class="sd-drawer-body">
                <div class="pro-row pro-row-2" style="gap:12px; margin-bottom:14px;">
                    <div class="pro-field">
                        <label>技能名称</label>
                        <input type="text" name="name" value="{{ old('name', $skill->name) }}" required>
                    </div>
                    <div class="pro-field">
                        <label>状态</label>
                        <label class="pro-check" style="padding-top:8px;">
                            <input type="checkbox" name="is_active" value="1" {{ old('is_active', $skill->is_active) ? 'checked' : '' }}> 启用技能
                        </label>
                    </div>
                </div>

                <div class="pro-field" style="margin-bottom:14px;">
                    <label>技能说明</label>
                    <input type="text" name="description" value="{{ old('description', $skill->description) }}">
                </div>

                <div class="pro-field" style="margin-bottom:14px;">
                    <label>skill.md</label>
                    <textarea name="skill_md" required style="min-height:280px; font-family:Consolas, 'Courier New', monospace; font-size:13px;">{{ old('skill_md', $skill->skill_md) }}</textarea>
                    <div class="pro-help">
                        建议声明 <code>name</code>、<code>description</code>、<code>required_capabilities</code>、<code>task_kinds</code>、<code>executor</code>。
                        若 <code>executor: sandbox</code>，还可配置 <code>sandbox_interpreter</code>、<code>sandbox_script</code>、<code>sandbox_timeout</code>。
                    </div>
                </div>

                <div class="pro-help" style="color:#6b7a83;">
                    分配（部门 / 用户）请在详情页的"分配"卡片里通过"修改"按钮单独编辑。
                </div>
            </div>
            <div class="sd-drawer-foot">
                <span class="pro-muted" style="font-size:12px;">Esc 关闭抽屉 · ⌘/Ctrl + S 保存</span>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="pro-btn" id="sd-drawer-cancel">取消</button>
                    <button type="submit" class="pro-btn pro-btn-primary">保存修改</button>
                </div>
            </div>
        </form>
    </aside>

    <script>
        (function () {
            var backdrop = document.getElementById('sd-drawer-backdrop');
            var drawer = document.getElementById('sd-drawer');
            var form = document.getElementById('sd-edit-form');

            function openDrawer() {
                drawer.setAttribute('data-open', 'true');
                drawer.setAttribute('aria-hidden', 'false');
                backdrop.setAttribute('data-open', 'true');
                document.body.style.overflow = 'hidden';
            }
            function closeDrawer() {
                drawer.setAttribute('data-open', 'false');
                drawer.setAttribute('aria-hidden', 'true');
                backdrop.setAttribute('data-open', 'false');
                document.body.style.overflow = '';
            }

            var openEditBtn = document.getElementById('sd-open-edit');
            if (openEditBtn) { openEditBtn.addEventListener('click', openDrawer); }
            var editMdBtn = document.getElementById('sd-edit-md');
            if (editMdBtn) {
                editMdBtn.addEventListener('click', function () {
                    openDrawer();
                    setTimeout(function () {
                        var ta = form.querySelector('textarea[name="skill_md"]');
                        if (ta) { ta.focus(); }
                    }, 220);
                });
            }
            var editAssignBtn = document.getElementById('sd-edit-assign');
            if (editAssignBtn) {
                editAssignBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    if (window.__openAssignPopover) {
                        window.__openAssignPopover(editAssignBtn);
                    }
                });
            }
            document.getElementById('sd-drawer-close').addEventListener('click', closeDrawer);
            document.getElementById('sd-drawer-cancel').addEventListener('click', closeDrawer);
            backdrop.addEventListener('click', closeDrawer);

            document.addEventListener('keydown', function (e) {
                if (drawer.getAttribute('data-open') !== 'true') return;
                if (e.key === 'Escape') { closeDrawer(); }
                if ((e.metaKey || e.ctrlKey) && e.key === 's') {
                    e.preventDefault();
                    form.submit();
                }
            });

            // Files panel collapse/expand
            var filesHead = document.getElementById('sd-files-toggle');
            var filesBody = document.getElementById('sd-files-panel');
            function setFiles(open) {
                filesHead.setAttribute('data-open', open ? 'true' : 'false');
                filesBody.setAttribute('data-open', open ? 'true' : 'false');
            }
            filesHead.addEventListener('click', function () {
                var cur = filesHead.getAttribute('data-open') === 'true';
                setFiles(!cur);
            });

            // Auto-expand files panel when we navigated with ?file=xxx or had file_error
            @if($editingFile || ! empty($fileError))
                setFiles(true);
            @endif

            // Auto-open drawer if server returned validation errors
            @if ($errors->any() && ! $errors->has('file_error'))
                openDrawer();
            @endif
        })();
    </script>
    {{-- ====== Assign Popover ====== --}}
    <form method="post" action="/admin/skills/{{ $skill->id }}/assign" id="sd-assign-form" style="display:none;">
        @csrf
        <div id="sd-assign-hidden-inputs"></div>
    </form>
    <div class="sd-popover-backdrop" id="sd-assign-backdrop" data-open="false"></div>
    <div class="sd-popover" id="sd-assign-popover" data-open="false" role="dialog" aria-hidden="true">
        <div class="sd-popover-search">
            <span class="sd-popover-search-icon">⌕</span>
            <input type="text" id="sd-assign-search" placeholder="搜索部门或用户">
        </div>
        <div class="sd-popover-list" id="sd-assign-list"></div>
        <div class="sd-popover-foot">
            <span class="sd-popover-counter" id="sd-assign-counter">0 部门 · 0 用户</span>
            <div style="display:flex; gap:6px;">
                <button type="button" class="pro-btn pro-btn-sm" id="sd-assign-cancel">取消</button>
                <button type="button" class="pro-btn pro-btn-sm pro-btn-primary" id="sd-assign-apply">应用</button>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var DATA = @json($popoverData);
            var backdrop = document.getElementById('sd-assign-backdrop');
            var popover = document.getElementById('sd-assign-popover');
            var searchInput = document.getElementById('sd-assign-search');
            var listEl = document.getElementById('sd-assign-list');
            var counterEl = document.getElementById('sd-assign-counter');
            var form = document.getElementById('sd-assign-form');
            var hiddenEl = document.getElementById('sd-assign-hidden-inputs');

            var selectedDepts = new Set((DATA.assigned.departments || []).map(Number));
            var selectedUsers = new Set((DATA.assigned.users || []).map(Number));

            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, function (c) {
                    return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
                });
            }

            function render() {
                var q = (searchInput.value || '').trim().toLowerCase();
                var depts = DATA.departments.filter(function (d) { return !q || d.name.toLowerCase().indexOf(q) !== -1; });
                var users = DATA.users.filter(function (u) {
                    if (!q) return true;
                    return u.name.toLowerCase().indexOf(q) !== -1 || (u.feishu && u.feishu.toLowerCase().indexOf(q) !== -1);
                });

                var html = '';
                if (depts.length) {
                    html += '<div class="sd-popover-section-title">部门</div>';
                    depts.forEach(function (d) {
                        var checked = selectedDepts.has(d.id);
                        html += '<div class="sd-popover-item sd-popover-item-dept' + (checked ? ' is-selected' : '') + '" data-type="dept" data-id="' + d.id + '">'
                            + '<span class="sd-popover-check">' + (checked ? '✓' : '') + '</span>'
                            + '<span class="sd-popover-avatar">▦</span>'
                            + '<span class="sd-popover-name">' + escapeHtml(d.name) + '</span>'
                            + '<span class="sd-popover-meta">' + d.count + ' 人</span>'
                            + '</div>';
                    });
                }
                if (users.length) {
                    html += '<div class="sd-popover-section-title">用户</div>';
                    users.forEach(function (u) {
                        var inherited = u.dept !== null && selectedDepts.has(u.dept);
                        var checked = selectedUsers.has(u.id) || inherited;
                        html += '<div class="sd-popover-item' + (checked ? ' is-selected' : '') + (inherited ? ' is-inherited' : '') + '" data-type="user" data-id="' + u.id + '" data-inherited="' + (inherited ? '1' : '0') + '" title="' + (inherited ? '通过部门继承，无法单独取消' : '') + '">'
                            + '<span class="sd-popover-check">' + (checked ? '✓' : '') + '</span>'
                            + '<span class="sd-popover-avatar">' + escapeHtml((u.name || '?').substring(0, 1)) + '</span>'
                            + '<span class="sd-popover-name">' + escapeHtml(u.name) + '</span>'
                            + (inherited ? '<span class="sd-popover-meta sd-popover-meta-info">继承</span>' : '')
                            + '</div>';
                    });
                }
                if (!depts.length && !users.length) {
                    html += '<div class="sd-popover-empty">无匹配结果</div>';
                }
                listEl.innerHTML = html;
                counterEl.textContent = selectedDepts.size + ' 部门 · ' + selectedUsers.size + ' 用户';
            }

            listEl.addEventListener('click', function (e) {
                var el = e.target.closest('.sd-popover-item');
                if (!el) return;
                var type = el.getAttribute('data-type');
                var id = Number(el.getAttribute('data-id'));
                if (type === 'dept') {
                    if (selectedDepts.has(id)) selectedDepts.delete(id); else selectedDepts.add(id);
                } else if (type === 'user') {
                    if (el.getAttribute('data-inherited') === '1') return;
                    if (selectedUsers.has(id)) selectedUsers.delete(id); else selectedUsers.add(id);
                }
                render();
            });

            searchInput.addEventListener('input', render);

            function positionPopover(anchor) {
                var rect = anchor.getBoundingClientRect();
                var popW = 360;
                var left = Math.max(16, Math.min(window.innerWidth - popW - 16, rect.right - popW));
                var top = rect.bottom + 6 + window.scrollY;
                popover.style.top = top + 'px';
                popover.style.left = left + 'px';
            }

            function openPopover(anchor) {
                selectedDepts = new Set((DATA.assigned.departments || []).map(Number));
                selectedUsers = new Set((DATA.assigned.users || []).map(Number));
                searchInput.value = '';
                render();
                positionPopover(anchor);
                popover.setAttribute('data-open', 'true');
                popover.setAttribute('aria-hidden', 'false');
                backdrop.setAttribute('data-open', 'true');
                setTimeout(function () { searchInput.focus(); }, 50);
            }
            function closePopover() {
                popover.setAttribute('data-open', 'false');
                popover.setAttribute('aria-hidden', 'true');
                backdrop.setAttribute('data-open', 'false');
            }

            window.__openAssignPopover = openPopover;

            backdrop.addEventListener('click', closePopover);
            document.getElementById('sd-assign-cancel').addEventListener('click', closePopover);
            document.getElementById('sd-assign-apply').addEventListener('click', function () {
                var html = '';
                selectedDepts.forEach(function (id) {
                    html += '<input type="hidden" name="department_ids[]" value="' + id + '">';
                });
                selectedUsers.forEach(function (id) {
                    html += '<input type="hidden" name="user_ids[]" value="' + id + '">';
                });
                hiddenEl.innerHTML = html;
                form.submit();
            });

            document.addEventListener('keydown', function (e) {
                if (popover.getAttribute('data-open') !== 'true') return;
                if (e.key === 'Escape') { closePopover(); }
            });
        })();
    </script>

    {{-- ====== File modals (new + edit) ====== --}}
    <div class="sd-modal-backdrop" id="sd-file-modal-backdrop" data-open="false"></div>

    {{-- New file modal: two tabs (text / upload) --}}
    <div class="sd-modal" id="sd-new-file-modal" data-open="false" role="dialog" aria-hidden="true">
        <div class="sd-modal-head">
            <div>
                <h3>新建技能文件</h3>
                <div class="sub">{{ $skill->storage_path }}</div>
            </div>
            <button type="button" class="sd-drawer-close" data-close-modal="new">×</button>
        </div>
        <div class="sd-modal-tabs">
            <button type="button" class="sd-modal-tab" data-tab="text" data-active="true">新建文本</button>
            <button type="button" class="sd-modal-tab" data-tab="upload" data-active="false">上传文件</button>
        </div>

        <form method="post" action="/admin/skills/{{ $skill->id }}/files/save" id="sd-new-text-form" class="sd-modal-tab-panel" data-tab="text" data-active="true">
            @csrf
            <div class="sd-modal-body">
                <div class="pro-field" style="margin-bottom:12px;">
                    <label style="font-size:12px; color:#4d6470; margin-bottom:6px; display:block;">文件路径</label>
                    <input type="text" name="file_path" placeholder="例如 scripts/get_user.py 或 assets/prompt.txt" required>
                </div>
                <div class="pro-field">
                    <label style="font-size:12px; color:#4d6470; margin-bottom:6px; display:block;">内容</label>
                    <textarea name="file_content" style="min-height:240px; width:100%; font-family:Consolas, 'Courier New', monospace; font-size:12.5px; line-height:1.6;"></textarea>
                </div>
            </div>
            <div class="sd-modal-foot">
                <span class="foot-msg">文本文件直接在这里创建或覆盖</span>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="pro-btn" data-close-modal="new">取消</button>
                    <button type="submit" class="pro-btn pro-btn-primary">保存</button>
                </div>
            </div>
        </form>

        <form method="post" action="/admin/skills/{{ $skill->id }}/files/save" enctype="multipart/form-data" class="sd-modal-tab-panel" data-tab="upload" data-active="false">
            @csrf
            <div class="sd-modal-body">
                <div class="pro-field" style="margin-bottom:12px;">
                    <label style="font-size:12px; color:#4d6470; margin-bottom:6px; display:block;">目录（可选）</label>
                    <input type="text" name="upload_dir" placeholder="例如 assets/">
                </div>
                <div class="pro-field">
                    <label style="font-size:12px; color:#4d6470; margin-bottom:6px; display:block;">本地文件</label>
                    <input type="file" name="upload_file" required>
                </div>
            </div>
            <div class="sd-modal-foot">
                <span class="foot-msg">文件名沿用本地名称，可附上目录前缀</span>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="pro-btn" data-close-modal="new">取消</button>
                    <button type="submit" class="pro-btn pro-btn-primary">上传</button>
                </div>
            </div>
        </form>
    </div>

    {{-- Edit file modal --}}
    <div class="sd-modal" id="sd-edit-file-modal" data-open="false" role="dialog" aria-hidden="true">
        <form method="post" action="/admin/skills/{{ $skill->id }}/files/save" id="sd-edit-file-form">
            @csrf
            <input type="hidden" name="file_path" id="sd-edit-file-path" value="">
            <div class="sd-modal-head">
                <div>
                    <h3>编辑文件</h3>
                    <div class="sub" id="sd-edit-file-label">—</div>
                </div>
                <button type="button" class="sd-drawer-close" data-close-modal="edit">×</button>
            </div>
            <div class="sd-modal-body">
                <textarea name="file_content" id="sd-edit-file-content"
                    style="min-height:360px; width:100%; font-family:Consolas, 'Courier New', monospace; font-size:12.5px; line-height:1.6;"></textarea>
            </div>
            <div class="sd-modal-foot">
                <span class="foot-msg" id="sd-edit-file-chars">0 字符</span>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="pro-btn" data-close-modal="edit">取消</button>
                    <button type="submit" class="pro-btn pro-btn-primary">保存</button>
                </div>
            </div>
        </form>
    </div>

    <script>
        (function () {
            var fileContents = @json($fileContents ?? (object) []);
            var backdrop = document.getElementById('sd-file-modal-backdrop');
            var newModal = document.getElementById('sd-new-file-modal');
            var editModal = document.getElementById('sd-edit-file-modal');

            function openModal(modal) {
                backdrop.setAttribute('data-open', 'true');
                modal.setAttribute('data-open', 'true');
                modal.setAttribute('aria-hidden', 'false');
            }
            function closeModals() {
                backdrop.setAttribute('data-open', 'false');
                newModal.setAttribute('data-open', 'false');
                newModal.setAttribute('aria-hidden', 'true');
                editModal.setAttribute('data-open', 'false');
                editModal.setAttribute('aria-hidden', 'true');
            }
            backdrop.addEventListener('click', closeModals);
            document.querySelectorAll('[data-close-modal]').forEach(function (btn) {
                btn.addEventListener('click', closeModals);
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' &&
                    (newModal.getAttribute('data-open') === 'true' || editModal.getAttribute('data-open') === 'true')) {
                    closeModals();
                }
            });

            // New file modal
            document.getElementById('sd-new-file-btn').addEventListener('click', function () {
                // Reset both tabs
                document.querySelectorAll('#sd-new-file-modal .sd-modal-tab').forEach(function (t) {
                    t.setAttribute('data-active', t.getAttribute('data-tab') === 'text' ? 'true' : 'false');
                });
                document.querySelectorAll('#sd-new-file-modal .sd-modal-tab-panel').forEach(function (p) {
                    p.setAttribute('data-active', p.getAttribute('data-tab') === 'text' ? 'true' : 'false');
                });
                var form = document.getElementById('sd-new-text-form');
                if (form) form.reset();
                openModal(newModal);
            });
            document.querySelectorAll('#sd-new-file-modal .sd-modal-tab').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    var target = tab.getAttribute('data-tab');
                    document.querySelectorAll('#sd-new-file-modal .sd-modal-tab').forEach(function (t) {
                        t.setAttribute('data-active', t.getAttribute('data-tab') === target ? 'true' : 'false');
                    });
                    document.querySelectorAll('#sd-new-file-modal .sd-modal-tab-panel').forEach(function (p) {
                        p.setAttribute('data-active', p.getAttribute('data-tab') === target ? 'true' : 'false');
                    });
                });
            });

            // Edit file modal
            var editPath = document.getElementById('sd-edit-file-path');
            var editLabel = document.getElementById('sd-edit-file-label');
            var editContent = document.getElementById('sd-edit-file-content');
            var editChars = document.getElementById('sd-edit-file-chars');

            window.__openEditFileModal = function (path) {
                var content = Object.prototype.hasOwnProperty.call(fileContents, path) ? fileContents[path] : null;
                editPath.value = path;
                editLabel.textContent = path;
                if (content === null) {
                    editContent.value = '';
                    editChars.textContent = '无法预加载，保存会覆盖原文件';
                    editContent.placeholder = '无法读取内容，写入后会覆盖文件';
                } else {
                    editContent.value = content;
                    editChars.textContent = content.length + ' 字符';
                    editContent.placeholder = '';
                }
                openModal(editModal);
            };

            editContent.addEventListener('input', function () {
                editChars.textContent = editContent.value.length + ' 字符';
            });

            // Back-compat: if URL has ?file=xxx from old edit link, auto-open the edit modal
            var params = new URLSearchParams(window.location.search);
            var pre = params.get('file');
            if (pre && Object.prototype.hasOwnProperty.call(fileContents, pre)) {
                window.__openEditFileModal(pre);
            }
        })();
    </script>

{{-- 删除技能 modal（GitHub 风格：输入技能名才能确认）--}}
@adminCan('skills.delete')
<div class="sd-delete-modal" id="sd-delete-modal" hidden>
    <div class="sd-delete-modal-backdrop" data-sd-delete-close></div>
    <div class="sd-delete-modal-card" role="dialog" aria-modal="true" aria-labelledby="sd-delete-title">
        <h3 id="sd-delete-title" class="sd-delete-title">删除技能</h3>
        <p class="sd-delete-warn">为确认这一操作，请在下方输入完整技能名 <strong>{{ $skill->name }}</strong>：</p>
        <form method="post" action="/admin/skills/{{ $skill->id }}/delete" id="sd-delete-form">
            @csrf
            <input type="text" name="confirm_name" id="sd-delete-input" autocomplete="off" placeholder="输入技能名以确认" class="sd-delete-input">
            @if($errors->has('confirm_name'))
                <div class="sd-delete-error">{{ $errors->first('confirm_name') }}</div>
            @endif
            <div class="sd-delete-actions">
                <button type="button" class="pro-btn" data-sd-delete-close>取消</button>
                <button type="submit" class="pro-btn pro-btn-danger" id="sd-delete-submit" disabled>确认删除</button>
            </div>
        </form>
    </div>
</div>
<style>
.sd-delete-modal { position: fixed; inset: 0; z-index: 1100; display: flex; align-items: center; justify-content: center; }
.sd-delete-modal[hidden] { display: none; }
.sd-delete-modal-backdrop { position: absolute; inset: 0; background: rgba(15, 23, 42, 0.45); backdrop-filter: blur(2px); }
.sd-delete-modal-card { position: relative; width: min(480px, calc(100% - 32px)); background: #fff; border-radius: 12px; box-shadow: 0 20px 50px rgba(0,0,0,0.18); padding: 22px 24px; }
.sd-delete-title { margin: 0 0 12px; font-size: 18px; font-weight: 800; color: #b91c1c; }
.sd-delete-warn { font-size: 13px; color: #475569; line-height: 1.6; margin: 0 0 12px; }
.sd-delete-warn strong { color: #111; background: #fef2f2; padding: 1px 6px; border-radius: 4px; font-weight: 800; }
.sd-delete-input { width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 8px; padding: 0 12px; font-size: 14px; }
.sd-delete-input:focus { outline: 2px solid #ef4444; outline-offset: -1px; border-color: #ef4444; }
.sd-delete-error { color: #b91c1c; font-size: 12px; margin-top: 6px; }
.sd-delete-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }
.sd-delete-modal .pro-btn-danger { background: #dc2626 !important; color: #fff !important; border-color: #dc2626 !important; }
.sd-delete-modal .pro-btn-danger:hover:not(:disabled) { background: #b91c1c !important; border-color: #b91c1c !important; color: #fff !important; }
.sd-delete-modal .pro-btn-danger:disabled { background: #ef9b9b !important; border-color: #ef9b9b !important; color: #fff !important; cursor: not-allowed; opacity: 1; }
</style>
<script>
(function () {
    const expectedName = @json($skill->name);
    const modal = document.getElementById('sd-delete-modal');
    const openBtn = document.getElementById('sd-open-delete');
    const input = document.getElementById('sd-delete-input');
    const submit = document.getElementById('sd-delete-submit');
    if (!modal || !openBtn) return;

    function open() {
        modal.hidden = false;
        input.value = '';
        submit.disabled = true;
        setTimeout(() => input.focus(), 50);
    }
    function close() { modal.hidden = true; }

    openBtn.addEventListener('click', open);
    document.querySelectorAll('[data-sd-delete-close]').forEach(el => el.addEventListener('click', close));
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) close(); });
    input.addEventListener('input', () => {
        submit.disabled = input.value.trim() !== expectedName.trim();
    });
})();
</script>
@endadminCan

@endsection
                                                                                                    