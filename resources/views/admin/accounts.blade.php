@extends('admin.layout')

@section('title', '米蛙管理后台 - 账号配置')
@section('page-title', '账号配置')
@section('page-desc', '管理后台登录账号，并为每个账号配置页面与按钮级动作权限')

@section('header-actions')
    @adminCan('admin_accounts.create')
        <a class="pro-btn pro-btn-primary" href="/admin/accounts/create">新增账号</a>
    @endadminCan
@endsection

@push('head')
<style>
.admin-perm-chip { display: inline-flex; align-items: center; padding: 2px 7px; border-radius: 4px; background: #eef7f3; color: #0f766e; font-size: 11px; margin: 2px 4px 2px 0; }
.account-actions { display: flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap; }
</style>
@endpush

@section('content')
    <div class="pro-card no-card-hover">
        <h3 class="pro-card-title">账号列表</h3>
        <div class="pro-card-subtitle">超级管理员拥有全部权限；普通账号只拥有已勾选的动作权限。</div>
        <div class="pro-table-wrap">
            <table>
                <thead>
                <tr>
                    <th>账号</th>
                    <th>状态</th>
                    <th>权限</th>
                    <th style="width:170px;">操作</th>
                </tr>
                </thead>
                <tbody>
                @forelse($adminAccounts as $account)
                    <tr>
                        <td>
                            <strong>{{ $account->display_name }}</strong>
                            <div class="pro-muted">{{ $account->username }} · {{ $account->email ?: '未设置邮箱' }}</div>
                            <div class="pro-muted">最后登录：{{ $account->last_login_at ? $account->last_login_at->format('Y-m-d H:i') : '-' }}</div>
                        </td>
                        <td>
                            <span class="pro-tag {{ $account->is_active ? 'pro-tag-success' : '' }}">{{ $account->is_active ? '启用' : '停用' }}</span>
                            @if($account->is_super_admin)
                                <span class="pro-tag pro-tag-info">超级管理员</span>
                            @endif
                        </td>
                        <td>
                            @if($account->is_super_admin)
                                <span class="admin-perm-chip">全部权限</span>
                            @elseif($account->permissions->isEmpty())
                                <span class="pro-muted">未配置权限</span>
                            @else
                                @foreach($account->permissions->sortBy('sort_order')->take(6) as $permission)
                                    <span class="admin-perm-chip">{{ $permission->label }}</span>
                                @endforeach
                                @if($account->permissions->count() > 6)
                                    <span class="pro-muted">等 {{ $account->permissions->count() }} 项</span>
                                @endif
                            @endif
                        </td>
                        <td>
                            <div class="account-actions">
                                @adminCan('admin_accounts.update')
                                    <a class="pro-btn pro-btn-sm" href="/admin/accounts/{{ $account->id }}/edit">编辑</a>
                                @endadminCan
                                @adminCan('admin_accounts.toggle_active')
                                    <form method="post" action="/admin/accounts/{{ $account->id }}/toggle-active" style="margin:0;" onsubmit="return confirm('确认{{ $account->is_active ? '停用' : '启用' }}这个后台账号？');">
                                        @csrf
                                        <button type="submit" class="pro-btn pro-btn-sm {{ $account->is_active ? 'pro-btn-outline' : 'pro-btn-primary' }}">{{ $account->is_active ? '停用' : '启用' }}</button>
                                    </form>
                                @endadminCan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="pro-muted" style="text-align:center;">暂无后台账号。</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
