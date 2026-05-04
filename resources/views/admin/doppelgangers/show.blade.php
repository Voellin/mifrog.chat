@extends('admin.layout')

@php
    $statusMap = [
        'active'             => ['活跃',     'pro-badge-success'],
        'pending'            => ['待激活',   'pro-badge'],
        'sample_extracting'  => ['提取样本中', 'pro-badge-warning'],
        'paused'             => ['已暂停',   'pro-badge-warning'],
        'expired'            => ['已过期',   'pro-badge-danger'],
        'revoked'            => ['已撤销',   'pro-badge-danger'],
    ];
    $accessMap = [
        'read_only'    => ['只读',   '#0369a1', '#e0f2fe'],   // L1
        'use_voice'    => ['起草',   '#92560f', '#fff4e0'],   // L2
        'use_workflow' => ['工作流', '#5b21b6', '#ede9fe'],   // L3
        'full'         => ['完整',   '#0a5a3e', '#e8f5ee'],   // 全部
    ];
    $sampleTypeLabels = [
        'voice'      => ['语气样本', '✍️'],
        'workflow'   => ['工作流',   '⚙️'],
        'decision'   => ['决策',     '◆'],
        'preference' => ['偏好',     '☆'],
    ];
    [$statusLabel, $statusClass] = $statusMap[$dop->status] ?? [$dop->status, 'pro-badge'];
    $days = $dop->daysUntilExpiry();
@endphp

@section('title', '数字分身详情 #' . $dop->id)
@section('header-title', $dop->display_name)
@section('header-subtitle', '源员工：' . ($dop->sourceUser?->name ?? '#' . $dop->source_user_id))
@section('page-title', $dop->display_name)
@section('page-desc', '管理分身的激活/暂停/续期、查看样本概况，以及授权接班人调阅')

@section('header-actions')
    <div class="pro-inline-actions">
        @if($dop->status === 'active')
            <a class="pro-btn pro-btn-primary" href="{{ route('admin.doppelgangers.chat', $dop->id) }}">进入对话页 →</a>
        @endif
        <a class="pro-btn" href="{{ route('admin.doppelgangers.index') }}">返回列表</a>
    </div>
@endsection

@push('head')
<style>
.dop-show-wrap > .pro-card { margin-bottom: 16px; }
.dop-show-wrap > .pro-card:last-child { margin-bottom: 0; }

/* KPI status badge alignment */
.dop-kpi-badge { display: inline-flex; align-items: center; gap: 6px; }
.dop-kpi-badge .pro-badge { font-size: 13px; padding: 4px 10px; }
.dop-kpi-expire { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
.dop-kpi-days {
    display: inline-block; padding: 1px 7px; border-radius: 999px;
    font-size: 11px; background: #f1f3f5; color: #6b7a83; font-weight: 500;
}
.dop-kpi-days.warn { background: #fff4e0; color: #92560f; }
.dop-kpi-days.over { background: #fde8e8; color: #b32424; }

/* Sample chips */
.dop-sample-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 10px;
    margin-top: 4px;
}
.dop-sample-chip {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 14px;
    background: #fafbfc;
    border: 1px solid #e4e7eb;
    border-radius: var(--pro-radius-sm, 8px);
}
.dop-sample-chip .glyph {
    width: 32px; height: 32px;
    border-radius: 8px;
    background: #e8f5ee;
    color: #0a5a3e;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 16px; font-weight: 600;
    flex: 0 0 auto;
}
.dop-sample-chip .label { font-size: 12px; color: #6b7a83; }
.dop-sample-chip .count { font-size: 18px; font-weight: 600; color: #11232d; line-height: 1.1; }
.dop-sample-empty {
    padding: 24px; text-align: center;
    color: #94a3b8; font-size: 13px;
    background: #fafbfc;
    border: 1px dashed #e4e7eb;
    border-radius: var(--pro-radius-sm, 8px);
}

/* Control buttons row */
.dop-control-row {
    display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
}
.dop-extend-form {
    display: inline-flex; gap: 6px; align-items: center;
    padding: 4px 8px;
    background: #fafbfc;
    border: 1px solid #e4e7eb;
    border-radius: var(--pro-radius-sm, 8px);
}
.dop-extend-form select {
    padding: 4px 8px; font-size: 12px; border: 1px solid var(--pro-border, #e4e7eb); border-radius: 6px;
    background: var(--pro-surface, #fff); color: var(--pro-text, #11232d);
}

/* Grant table */
.dop-grant-table { width: 100%; table-layout: fixed; }
.dop-grant-table .col-grantee { width: 200px; }
.dop-grant-table .col-level   { width: 130px; }
.dop-grant-table .col-expire  { width: 130px; white-space: nowrap; }
.dop-grant-table .col-by      { width: 120px; }
.dop-grant-table .col-action  { width: 90px; }

.dop-access-badge {
    display: inline-block;
    padding: 2px 9px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 500;
}

/* Grant form */
.dop-grant-form { margin-bottom: 18px; }
</style>
@endpush

@section('content')
<div class="dop-show-wrap">

    {{-- ── KPI ── --}}
    <div class="pro-kpi-grid" style="margin-bottom:16px;">
        <div class="pro-card">
            <div class="pro-kpi-label">状态</div>
            <div class="pro-kpi-value dop-kpi-badge"><span class="pro-badge {{ $statusClass }}">{{ $statusLabel }}</span></div>
        </div>
        <div class="pro-card">
            <div class="pro-kpi-label">到期时间</div>
            <div class="pro-kpi-value dop-kpi-expire">
                <span style="font-variant-numeric:tabular-nums;">{{ $dop->expires_at?->format('Y-m-d') ?? '—' }}</span>
                @if($days !== null)
                    @if($days < 0)
                        <span class="dop-kpi-days over">已过期</span>
                    @elseif($days <= 30)
                        <span class="dop-kpi-days warn">剩 {{ $days }} 天</span>
                    @else
                        <span class="dop-kpi-days">剩 {{ $days }} 天</span>
                    @endif
                @endif
            </div>
        </div>
        <div class="pro-card">
            <div class="pro-kpi-label">累计调用</div>
            <div class="pro-kpi-value">{{ number_format($invocationsCount) }}</div>
        </div>
        <div class="pro-card">
            <div class="pro-kpi-label">授权人数</div>
            <div class="pro-kpi-value">{{ $dop->grants->count() }}</div>
        </div>
    </div>

    {{-- ── 分身控制 ── --}}
    <div class="pro-card no-card-hover">
        <h3 class="pro-card-title">分身控制</h3>
        <div class="pro-card-subtitle">激活会触发一次性历史样本抽取；撤销不可逆，请谨慎。</div>

        <div class="dop-control-row">
            @if($dop->status === 'pending')
                <form method="post" action="{{ route('admin.doppelgangers.activate', $dop->id) }}" style="display:inline;">@csrf
                    <button type="submit" class="pro-btn pro-btn-primary">激活（开始提取样本）</button>
                </form>
            @endif

            @if($dop->status === 'active')
                <form method="post" action="{{ route('admin.doppelgangers.pause', $dop->id) }}" style="display:inline;">@csrf
                    <button type="submit" class="pro-btn pro-btn-outline">暂停</button>
                </form>
            @endif

            @if($dop->status === 'paused')
                <form method="post" action="{{ route('admin.doppelgangers.resume', $dop->id) }}" style="display:inline;">@csrf
                    <button type="submit" class="pro-btn pro-btn-primary">恢复</button>
                </form>
            @endif

            @if(in_array($dop->status, ['active','paused','expired']))
                <form method="post" action="{{ route('admin.doppelgangers.extend', $dop->id) }}" class="dop-extend-form">@csrf
                    <span style="font-size:12px;color:#6b7a83;">续期</span>
                    <select name="months">
                        <option value="6">+6 个月</option>
                        <option value="12" selected>+12 个月</option>
                        <option value="24">+24 个月</option>
                    </select>
                    <button type="submit" class="pro-btn pro-btn-sm pro-btn-outline">应用</button>
                </form>
            @endif

            @if(! in_array($dop->status, ['revoked']))
                <form method="post" action="{{ route('admin.doppelgangers.revoke', $dop->id) }}" style="display:inline; margin-left:auto;" onsubmit="return confirm('确定撤销？这是不可逆操作，撤销后该分身的所有授权将立即失效。')">@csrf
                    <input type="hidden" name="reason" value="管理员手动撤销">
                    <button type="submit" class="pro-btn pro-btn-danger">撤销</button>
                </form>
            @endif
        </div>
    </div>

    {{-- ── 样本提取概况 ── --}}
    <div class="pro-card no-card-hover">
        <h3 class="pro-card-title">样本提取概况</h3>
        <div class="pro-card-subtitle">激活后系统会一次性抽取源员工的语气、工作流、决策、偏好样本，作为后续 RAG / few-shot 的语料。</div>

        @if(empty($samplesSummary))
            <div class="dop-sample-empty">
                @if($dop->status === 'pending')
                    暂无样本——请先「激活」触发提取。
                @elseif($dop->status === 'sample_extracting')
                    样本抽取进行中，请稍候片刻刷新本页。
                @else
                    暂无样本。如已激活但仍空，请检查 <code>doppelganger:tick</code> 任务是否在跑。
                @endif
            </div>
        @else
            <div class="dop-sample-grid">
                @foreach($sampleTypeLabels as $key => [$label, $glyph])
                    @php $count = $samplesSummary[$key] ?? 0; @endphp
                    <div class="dop-sample-chip">
                        <span class="glyph">{{ $glyph }}</span>
                        <div>
                            <div class="label">{{ $label }}</div>
                            <div class="count">{{ number_format($count) }}<span style="font-size:11px;color:#94a3b8;font-weight:400;"> 条</span></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ── 授权管理 ── --}}
    <div class="pro-card no-card-hover">
        <h3 class="pro-card-title">授权管理</h3>
        <div class="pro-card-subtitle">将本分身授权给接班人；不同访问层级对应不同能力（只读 = 知识问答 / 起草 = 模仿语气写草稿 / 工作流 = 触发模板任务 / 完整 = 三者皆可）。</div>

        <form method="post" action="{{ route('admin.doppelgangers.grant', $dop->id) }}" class="pro-grid dop-grant-form">@csrf
            <div class="pro-row pro-row-3">
                <div class="pro-field">
                    <label>授权给 *</label>
                    <select name="grantee_user_id" required>
                        <option value="">— 选择接班人 —</option>
                        @foreach($allUsers as $u)
                            <option value="{{ $u->id }}">{{ $u->name }} (#{{ $u->id }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="pro-field">
                    <label>访问层级</label>
                    <select name="access_level">
                        <option value="read_only">只读（Level 1 · 知识问答）</option>
                        <option value="use_voice">起草（Level 2 · 模仿语气）</option>
                        <option value="use_workflow">工作流（Level 3 · 模板任务）</option>
                        <option value="full" selected>完整（三者皆可）</option>
                    </select>
                </div>
                <div class="pro-field">
                    <label>授权天数</label>
                    <input type="number" name="expires_days" value="180" min="1" max="365">
                    <div class="pro-help">最长 365 天，可续。</div>
                </div>
            </div>
            <div class="pro-inline-actions" style="justify-content:flex-end;">
                <button type="submit" class="pro-btn pro-btn-primary">授权</button>
            </div>
        </form>

        <table class="pro-table dop-grant-table">
            <thead>
                <tr>
                    <th class="col-grantee">接班人</th>
                    <th class="col-level">层级</th>
                    <th class="col-expire">到期</th>
                    <th class="col-by">授权人</th>
                    <th class="col-action">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($dop->grants as $g)
                    @php
                        [$lvLabel, $lvFg, $lvBg] = $accessMap[$g->access_level] ?? [$g->access_level, '#6b7a83', '#f1f3f5'];
                    @endphp
                    <tr>
                        <td class="col-grantee">{{ $g->grantee->name ?? '?' }} <span style="color:#94a3b8;font-size:11px;">#{{ $g->grantee_user_id }}</span></td>
                        <td class="col-level"><span class="dop-access-badge" style="color:{{ $lvFg }};background:{{ $lvBg }};">{{ $lvLabel }}</span></td>
                        <td class="col-expire" style="font-variant-numeric:tabular-nums;">{{ $g->expires_at?->format('Y-m-d') ?? '永久' }}</td>
                        <td class="col-by" style="color:#6b7a83;">{{ $g->granted_by_admin_id ? '管理员 #' . $g->granted_by_admin_id : '—' }}</td>
                        <td class="col-action">
                            <form method="post" action="{{ route('admin.doppelgangers.grant.revoke', [$dop->id, $g->id]) }}" style="display:inline;" onsubmit="return confirm('撤销该授权？')">@csrf
                                <button type="submit" class="pro-btn-link" style="color:#ef4444;">撤销</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="text-align:center;color:#8a8f98;padding:24px;">尚无授权——使用上方表单添加第一个接班人。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

</div>
@endsection
