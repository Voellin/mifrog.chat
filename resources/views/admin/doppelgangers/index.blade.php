@extends('admin.layout')

@section('title', '米蛙管理后台 - 数字分身')
@section('header-title', '数字分身')
@section('header-subtitle', '基于离职员工历史数据生成的知识 + 风格代理')
@section('page-title', '数字分身')
@section('page-desc', '管理已启动的数字分身、查看使用统计、续期和撤销')

@section('header-actions')
    <div class="pro-inline-actions">
        <a class="pro-btn pro-btn-primary" href="{{ route('admin.doppelgangers.create') }}">+ 新建数字分身</a>
        <a class="pro-btn pro-btn-outline" href="{{ route('admin.doppelgangers.my') }}">我可调阅的分身</a>
    </div>
@endsection


@push('head')
<style>
.dop-index-table { table-layout: fixed; width: 100%; }
.dop-index-table th, .dop-index-table td { vertical-align: middle; }
.dop-col-id      { width: 60px; }
.dop-col-name    { width: 200px; }
.dop-col-source  { width: 220px; }
.dop-col-status  { width: 90px; }
.dop-col-expire  { width: 200px; white-space: nowrap; }
.dop-col-enabled { width: 110px; white-space: nowrap; }
.dop-col-action  { width: 70px; }

.dop-id { font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; color: #4d6470; font-size: 13px; }
.dop-truncate { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dop-source-id { font-size: 11px; color: #94a3b8; margin-top: 2px; }

.dop-date { color: #11232d; font-variant-numeric: tabular-nums; }
.dop-date-empty { color: #c0c8ce; }
.dop-days {
    display: inline-block;
    margin-left: 6px;
    padding: 1px 7px;
    border-radius: 999px;
    font-size: 11px;
    background: #f1f3f5;
    color: #6b7a83;
    font-weight: 500;
}
.dop-days-warn { background: #fff4e0; color: #92560f; }
.dop-days-over { background: #fde8e8; color: #b32424; }
</style>
@endpush

@section('content')
    <div class="pro-card">
        <table class="pro-table dop-index-table">
            <thead>
                <tr>
                    <th class="dop-col-id">ID</th>
                    <th class="dop-col-name">分身名</th>
                    <th class="dop-col-source">源员工</th>
                    <th class="dop-col-status">状态</th>
                    <th class="dop-col-expire">到期</th>
                    <th class="dop-col-enabled">激活</th>
                    <th class="dop-col-action">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $dop)
                    <tr>
                        <td class="dop-col-id"><span class="dop-id">#{{ $dop->id }}</span></td>
                        <td class="dop-col-name">
                            <strong class="dop-truncate" title="{{ $dop->display_name }}">{{ $dop->display_name }}</strong>
                        </td>
                        <td class="dop-col-source">
                            <div class="dop-truncate" title="{{ $dop->sourceUser?->name ?? '?' }}">{{ $dop->sourceUser?->name ?? '?' }}</div>
                            <div class="dop-source-id">用户 #{{ $dop->source_user_id }}</div>
                        </td>
                        <td class="dop-col-status">
                            @switch($dop->status)
                                @case('active')<span class="pro-badge pro-badge-success">活跃</span>@break
                                @case('pending')<span class="pro-badge">待激活</span>@break
                                @case('sample_extracting')<span class="pro-badge pro-badge-warning">提取中</span>@break
                                @case('paused')<span class="pro-badge pro-badge-warning">已暂停</span>@break
                                @case('expired')<span class="pro-badge pro-badge-danger">已过期</span>@break
                                @case('revoked')<span class="pro-badge pro-badge-danger">已撤销</span>@break
                                @default <span class="pro-badge">{{ $dop->status }}</span>
                            @endswitch
                        </td>
                        <td class="dop-col-expire">
                            @if($dop->expires_at)
                                <span class="dop-date">{{ $dop->expires_at->format('Y-m-d') }}</span>
                                @php $days = $dop->daysUntilExpiry(); @endphp
                                @if($days !== null)
                                    @if($days < 0)
                                        <span class="dop-days dop-days-over">已过期</span>
                                    @elseif($days <= 30)
                                        <span class="dop-days dop-days-warn">剩 {{ $days }} 天</span>
                                    @else
                                        <span class="dop-days">剩 {{ $days }} 天</span>
                                    @endif
                                @endif
                            @else <span class="dop-date-empty">—</span> @endif
                        </td>
                        <td class="dop-col-enabled">
                            @if($dop->enabled_at)
                                <span class="dop-date">{{ $dop->enabled_at->format('Y-m-d') }}</span>
                            @else <span class="dop-date-empty">—</span> @endif
                        </td>
                        <td class="dop-col-action"><a href="{{ route('admin.doppelgangers.show', $dop->id) }}" class="pro-btn-link">详情</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center;padding:40px;color:#8a8f98;">暂无数字分身。点击右上角「新建」开始。</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="padding:16px 0;">{{ $rows->links() }}</div>
    </div>
@endsection
