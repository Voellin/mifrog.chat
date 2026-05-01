@extends('admin.layout')

@section('title', '米蛙管理后台 - 新增账号')
@section('page-title', '新增账号')
@section('page-desc', '创建后台登录账号，并为普通账号配置页面与按钮级动作权限')

@section('header-actions')
    <a class="pro-btn" href="/admin/accounts">返回账号配置</a>
@endsection

@push('head')
<style>
.account-create-wrap { max-width: 1080px; }
.admin-perm-groups { display: grid; gap: 10px; }
.admin-perm-group { border: 1px solid #e5eef2; border-radius: 8px; padding: 10px 12px; background: #fbfdfd; }
.admin-perm-title { font-weight: 700; color: #11232d; margin-bottom: 8px; font-size: 13px; }
.admin-perm-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.admin-perm-item { display: flex; gap: 7px; align-items: flex-start; font-size: 12px; color: #334155; line-height: 1.45; }
.admin-perm-item input { margin-top: 2px; }
.admin-perm-item small { display: block; color: #7a8a94; font-size: 11px; margin-top: 1px; }
.admin-super-note { border: 1px solid #d6eadf; background: #f1fbf6; color: #166534; padding: 9px 10px; border-radius: 8px; font-size: 12px; line-height: 1.6; }
@media (max-width: 860px) { .admin-perm-list { grid-template-columns: 1fr; } }
</style>
@endpush

@section('content')
    <div class="pro-card no-card-hover account-create-wrap">
        <h3 class="pro-card-title">新增后台账号</h3>
        <div class="pro-card-subtitle">超级管理员默认拥有全部权限；普通账号按下方动作权限生效。</div>
        <form method="post" action="/admin/accounts" class="pro-grid" id="admin-account-create-form">
            @csrf
            <div class="pro-row pro-row-2">
                <div class="pro-field">
                    <label>登录用户名</label>
                    <input type="text" name="username" value="{{ old('username') }}" required maxlength="50" placeholder="例如 ops_admin">
                </div>
                <div class="pro-field">
                    <label>展示名称</label>
                    <input type="text" name="display_name" value="{{ old('display_name') }}" required maxlength="80" placeholder="例如 运营管理员">
                </div>
                <div class="pro-field">
                    <label>邮箱</label>
                    <input type="email" name="email" value="{{ old('email') }}" placeholder="用于找回密码/验证码">
                </div>
                <div class="pro-field">
                    <label>初始密码</label>
                    <input type="password" name="password" required minlength="8">
                </div>
                <div class="pro-field">
                    <label>确认密码</label>
                    <input type="password" name="password_confirmation" required minlength="8">
                </div>
            </div>

            <label class="pro-check">
                <input type="checkbox" name="is_super_admin" value="1" data-super-toggle="create" {{ old('is_super_admin') ? 'checked' : '' }}>
                设为超级管理员
            </label>
            <div class="admin-super-note">超级管理员不需要勾选下方权限，始终拥有后台全部页面和按钮动作权限。</div>

            <div data-permission-panel="create">
                @include('admin.partials.permission_checkboxes', [
                    'permissionGroups' => $permissionGroups,
                    'selectedPermissionKeys' => old('permissions', []),
                ])
            </div>

            <div class="pro-inline-actions" style="justify-content:flex-end;">
                <a class="pro-btn" href="/admin/accounts">取消</a>
                <button type="submit" class="pro-btn pro-btn-primary">创建账号</button>
            </div>
        </form>
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
