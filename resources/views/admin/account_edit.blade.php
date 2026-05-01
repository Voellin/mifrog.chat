@extends('admin.layout')

@section('title', '米蛙管理后台 - 编辑后台账号')
@section('page-title', '编辑后台账号')
@section('page-desc', '修改账号资料、启用状态、超级管理员状态和按钮级动作权限')

@section('header-actions')
    <a class="pro-btn" href="/admin/accounts">返回账号列表</a>
@endsection

@push('head')
<style>
.admin-account-edit { display: grid; grid-template-columns: 1.2fr .8fr; gap: 12px; align-items: start; }
.admin-perm-groups { display: grid; gap: 10px; }
.admin-perm-group { border: 1px solid #e5eef2; border-radius: 8px; padding: 10px 12px; background: #fbfdfd; }
.admin-perm-title { font-weight: 700; color: #11232d; margin-bottom: 8px; font-size: 13px; }
.admin-perm-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.admin-perm-item { display: flex; gap: 7px; align-items: flex-start; font-size: 12px; color: #334155; line-height: 1.45; }
.admin-perm-item input { margin-top: 2px; }
.admin-perm-item small { display: block; color: #7a8a94; font-size: 11px; margin-top: 1px; }
.admin-super-note { border: 1px solid #d6eadf; background: #f1fbf6; color: #166534; padding: 9px 10px; border-radius: 8px; font-size: 12px; line-height: 1.6; }
@media (max-width: 980px) { .admin-account-edit { grid-template-columns: 1fr; } .admin-perm-list { grid-template-columns: 1fr; } }
</style>
@endpush

@section('content')
<div class="admin-account-edit">
    <div class="pro-card no-card-hover">
        <h3 class="pro-card-title">账号与权限</h3>
        <form method="post" action="/admin/accounts/{{ $account->id }}" class="pro-grid">
            @csrf
            <div class="pro-row pro-row-2">
                <div class="pro-field"><label>登录用户名</label><input type="text" name="username" value="{{ old('username', $account->username) }}" required maxlength="50"></div>
                <div class="pro-field"><label>展示名称</label><input type="text" name="display_name" value="{{ old('display_name', $account->display_name) }}" required maxlength="80"></div>
                <div class="pro-field" style="grid-column:1 / -1;"><label>邮箱</label><input type="email" name="email" value="{{ old('email', $account->email) }}"></div>
            </div>

            <div style="display:flex; gap:18px; flex-wrap:wrap;">
                <label class="pro-check"><input type="checkbox" name="is_active" value="1" {{ old('is_active', $account->is_active) ? 'checked' : '' }}> 启用账号</label>
                <label class="pro-check"><input type="checkbox" name="is_super_admin" value="1" data-super-toggle="edit" {{ old('is_super_admin', $account->is_super_admin) ? 'checked' : '' }}> 超级管理员</label>
            </div>
            <div class="admin-super-note">超级管理员拥有全部后台权限；普通账号只拥有下方勾选的页面与按钮动作权限。</div>

            <div data-permission-panel="edit">
                @include('admin.partials.permission_checkboxes', ['permissionGroups' => $permissionGroups, 'selectedPermissionKeys' => old('permissions', $selectedPermissionKeys)])
            </div>

            <div class="pro-inline-actions" style="justify-content:flex-end;"><button type="submit" class="pro-btn pro-btn-primary">保存账号与权限</button></div>
        </form>
    </div>

    <div class="pro-card no-card-hover">
        <h3 class="pro-card-title">重置密码</h3>
        @adminCan('admin_accounts.password')
            <form method="post" action="/admin/accounts/{{ $account->id }}/password" class="pro-grid">
                @csrf
                <div class="pro-field"><label>新密码</label><input type="password" name="password" required minlength="8"></div>
                <div class="pro-field"><label>确认新密码</label><input type="password" name="password_confirmation" required minlength="8"></div>
                <div class="pro-inline-actions" style="justify-content:flex-end;"><button type="submit" class="pro-btn pro-btn-primary">重置密码</button></div>
            </form>
        @else
            <div class="pro-muted">当前账号没有重置后台账号密码权限。</div>
        @endadminCan
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    document.querySelectorAll('[data-super-toggle]').forEach(function (checkbox) {
        const key = checkbox.getAttribute('data-super-toggle');
        const panel = document.querySelector('[data-permission-panel="' + key + '"]');
        const apply = function () {
            if (!panel) return;
            panel.style.opacity = checkbox.checked ? '0.45' : '1';
            panel.querySelectorAll('input[type="checkbox"]').forEach(function (item) { item.disabled = checkbox.checked; });
        };
        checkbox.addEventListener('change', apply);
        apply();
    });
})();
</script>
@endpush
