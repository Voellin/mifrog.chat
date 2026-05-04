@extends('admin.layout')

@section('title', '米蛙管理后台 - 操作日志')
@section('page-title', '操作日志')
@section('page-desc', '后台所有写/危险操作的审计记录。无论身份，写操作必留痕。')

@push('head')
<style>
.ops-filter { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; padding: 14px 16px; }
.ops-filter .pro-field { flex: 0 1 240px; min-width: 180px; margin-bottom: 0; }
.ops-table { width: 100%; border-collapse: collapse; }
.ops-table th, .ops-table td { padding: 10px 12px; border-bottom: 1px solid #eef0f2; font-size: 13px; vertical-align: top; text-align: left; }
.ops-table thead th { background: #f5faf8; color: #4f6570; font-weight: 600; font-size: 12px; letter-spacing: 0.02em; vertical-align: middle; padding-top: 12px; padding-bottom: 12px; }
.ops-action { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 6px; background: #eef2ff; color: #4338ca; font-family: ui-monospace, monospace; font-size: 11px; font-weight: 700; }
.ops-actor { font-weight: 700; color: #11232d; }
.ops-actor small { display: block; font-weight: 400; color: #94a3b8; font-size: 11px; }
.ops-context { font-family: ui-monospace, monospace; font-size: 11px; color: #475569; max-width: 360px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; cursor: help; }
.ops-summary { color: #1f2937; max-width: 420px; }
.ops-time { color: #64748b; white-space: nowrap; font-variant-numeric: tabular-nums; font-size: 12px; }
.ops-empty { padding: 28px; text-align: center; color: #94a3b8; }
</style>
@endpush

@section('content')
<div class="pro-card no-card-hover">
    <h3 class="pro-card-title">操作日志</h3>
    <div class="pro-card-subtitle">所有 admin 写操作（创建/修改/删除/启停/分配）会在此留痕。SoftDelete / 高危删除务必通过此处追溯。</div>

    <form method="get" action="/admin/operation-logs" class="ops-filter">
        <div class="pro-field">
            <label>操作者</label>
            <select name="actor_id">
                <option value="0">全部账号</option>
                @foreach($actors as $a)
                    <option value="{{ $a->id }}" {{ (int)$selectedActorId === (int)$a->id ? 'selected' : '' }}>{{ $a->display_name }} ({{ $a->username }})</option>
                @endforeach
            </select>
        </div>
        <div class="pro-field">
            <label>动作前缀</label>
            <input type="text" name="action" value="{{ $selectedAction }}" placeholder="例如 skills. / settings.">
        </div>
        <div class="pro-field" style="flex:0 0 auto;">
            <button class="pro-btn pro-btn-primary" type="submit" style="padding:8px 28px;">查询</button>
        </div>
    </form>
</div>

<div class="pro-card no-card-hover" style="margin-top:12px;">
    <h3 class="pro-card-title">最近 {{ $logs->total() }} 条 · 当前第 {{ $logs->currentPage() }} / {{ $logs->lastPage() }} 页</h3>
    <div class="pro-table-wrap">
        <table class="ops-table">
            <thead>
                <tr>
                    <th style="width:140px;">时间</th>
                    <th style="width:160px;">操作者</th>
                    <th style="width:160px;">动作</th>
                    <th>说明</th>
                    <th style="width:240px;">上下文 / IP</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                    <tr>
                        <td class="ops-time">{{ $log->created_at?->timezone('Asia/Shanghai')?->format('Y-m-d H:i:s') }}</td>
                        <td>
                            <div class="ops-actor">
                                {{ $log->adminUser?->display_name ?? $log->admin_username ?? '(系统)' }}
                                <small>{{ $log->admin_username ?? '-' }}</small>
                            </div>
                        </td>
                        <td><span class="ops-action">{{ $log->action }}</span></td>
                        <td class="ops-summary">{{ $log->summary ?? '-' }}</td>
                        <td>
                            <div class="ops-context" title="{{ json_encode($log->context, JSON_UNESCAPED_UNICODE) }}">
                                @if($log->target_type)<strong>{{ $log->target_type }}#{{ $log->target_id }}</strong> · @endif
                                {{ $log->ip ?? '-' }}
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="ops-empty">暂无操作日志。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $logs->links('vendor.pagination.pro') }}
</div>
@endsection
