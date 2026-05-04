@extends('admin.layout')

@section('title', '我可调阅的数字分身')
@section('header-title', '可调阅的数字分身')
@section('header-subtitle', '查看你被授权访问的前同事 / 离职员工的数字分身')
@section('page-title', '可调阅的数字分身')

@section('content')
    @if($rows->count() === 0)
        <div class="pro-card" style="text-align:center;padding:60px 24px;">
            <div style="font-size:48px;margin-bottom:16px;">🪞</div>
            <h3 style="margin:0 0 8px;">暂无可调阅的数字分身</h3>
            <p style="color:#8a8f98;margin:0;">请联系管理员授权你访问相关同事的数字分身。</p>
        </div>
    @else
        <div class="pro-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;">
            @foreach($rows as $dop)
                <a href="{{ route('admin.doppelgangers.chat', $dop->id) }}" class="pro-card" style="text-decoration:none;color:inherit;display:block;padding:20px;transition:all .15s;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                        <div style="width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,#5e6ad2,#828fff);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;">
                            {{ mb_substr($dop->sourceUser?->name ?? 'U', 0, 1) }}
                        </div>
                        <div>
                            <div style="font-weight:600;font-size:16px;">{{ $dop->display_name }}</div>
                            <div style="font-size:12px;color:#8a8f98;">源员工：{{ $dop->sourceUser?->name ?? '#'.$dop->source_user_id }}</div>
                        </div>
                    </div>
                    <div style="font-size:13px;color:#62666d;display:flex;justify-content:space-between;">
                        <span>到期：{{ $dop->expires_at?->format('Y-m-d') ?? '永久' }}</span>
                        <span style="color:#5e6ad2;">→ 进入对话</span>
                    </div>
                </a>
            @endforeach
        </div>
        <div style="padding:16px 0;">{{ $rows->links() }}</div>
    @endif
@endsection
