<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AdminPermission;
use App\Models\AdminUser;
use App\Services\AdminPermissionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminAccountController extends Controller
{
    public function __construct(private readonly AdminPermissionRegistry $permissions)
    {
    }

    public function index(): View
    {
        $this->permissions->syncToDatabase();

        return view('admin.accounts', [
            'adminAccounts' => AdminUser::query()
                ->with('permissions')
                ->orderByDesc('is_super_admin')
                ->orderByDesc('is_active')
                ->orderBy('username')
                ->get(),
        ]);
    }

    public function create(): View
    {
        $this->permissions->syncToDatabase();

        return view('admin.account_create', [
            'permissionGroups' => $this->permissions->groupedDefinitions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('admin_users', 'username')],
            'display_name' => ['required', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'is_super_admin' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:191'],
        ]);

        $isSuperAdmin = $request->boolean('is_super_admin');
        $permissionKeys = $this->permissions->normalizeKeys($data['permissions'] ?? []);

        DB::transaction(function () use ($data, $isSuperAdmin, $permissionKeys): void {
            $admin = AdminUser::query()->create([
                'username' => $data['username'],
                'display_name' => $data['display_name'],
                'email' => $data['email'] ?? null,
                'password' => Hash::make($data['password']),
                'is_active' => true,
                'is_super_admin' => $isSuperAdmin,
            ]);

            $this->syncAccountPermissions($admin, $isSuperAdmin ? [] : $permissionKeys);
        });

        \App\Services\AdminOperationLogger::log($request, 'admin_accounts.create', sprintf('新增后台账号「%s」(username=%s)', (string) $admin->display_name, (string) $admin->username), ['target_type' => 'admin_user', 'target_id' => $admin->id, 'is_super_admin' => $isSuperAdmin]);

        return redirect('/admin/accounts')->with('status', '后台账号已创建。');
    }

    public function edit(AdminUser $adminUser): View
    {
        $this->permissions->syncToDatabase();
        $adminUser->load('permissions');

        return view('admin.account_edit', [
            'account' => $adminUser,
            'permissionGroups' => $this->permissions->groupedDefinitions(),
            'selectedPermissionKeys' => $adminUser->permissions->pluck('permission_key')->all(),
        ]);
    }

    public function update(Request $request, AdminUser $adminUser): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('admin_users', 'username')->ignore($adminUser->id)],
            'display_name' => ['required', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'is_super_admin' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:191'],
        ]);

        $currentAdmin = $request->attributes->get('admin_user');
        $isSelf = $currentAdmin && (int) $currentAdmin->id === (int) $adminUser->id;
        $isActive = $request->boolean('is_active');
        $isSuperAdmin = $request->boolean('is_super_admin');

        if ($isSelf && ! $isActive) {
            return back()->withErrors(['is_active' => '不能停用当前登录的后台账号。'])->withInput();
        }

        if ($isSelf && ! $isSuperAdmin && $adminUser->is_super_admin) {
            return back()->withErrors(['is_super_admin' => '不能取消自己的超级管理员权限。'])->withInput();
        }

        if (! $isSuperAdmin && $this->isLastSuperAdmin($adminUser)) {
            return back()->withErrors(['is_super_admin' => '至少需要保留一个超级管理员。'])->withInput();
        }

        $permissionKeys = $this->permissions->normalizeKeys($data['permissions'] ?? []);

        DB::transaction(function () use ($adminUser, $data, $isActive, $isSuperAdmin, $permissionKeys): void {
            $adminUser->update([
                'username' => $data['username'],
                'display_name' => $data['display_name'],
                'email' => $data['email'] ?? null,
                'is_active' => $isActive,
                'is_super_admin' => $isSuperAdmin,
            ]);

            $this->syncAccountPermissions($adminUser, $isSuperAdmin ? [] : $permissionKeys);
        });

        \App\Services\AdminOperationLogger::log($request, 'admin_accounts.update', sprintf('编辑后台账号 #%d「%s」', $adminUser->id, (string) $adminUser->display_name), ['target_type' => 'admin_user', 'target_id' => $adminUser->id, 'is_super_admin' => $isSuperAdmin, 'is_active' => $isActive]);

        return redirect('/admin/accounts')->with('status', '后台账号已更新。');
    }

    public function updatePassword(Request $request, AdminUser $adminUser): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $adminUser->update(['password' => Hash::make($data['password'])]);

        \App\Services\AdminOperationLogger::log($request, 'admin_accounts.password', sprintf('重置后台账号 #%d「%s」密码', $adminUser->id, (string) $adminUser->display_name), ['target_type' => 'admin_user', 'target_id' => $adminUser->id]);

        return back()->with('status', '后台账号密码已重置。');
    }

    public function toggleActive(Request $request, AdminUser $adminUser): RedirectResponse
    {
        $currentAdmin = $request->attributes->get('admin_user');
        if ($currentAdmin && (int) $currentAdmin->id === (int) $adminUser->id) {
            return back()->withErrors(['account' => '不能停用当前登录的后台账号。']);
        }

        if ($adminUser->is_active && $this->isLastSuperAdmin($adminUser)) {
            return back()->withErrors(['account' => '至少需要保留一个启用的超级管理员。']);
        }

        $adminUser->update(['is_active' => ! $adminUser->is_active]);

        \App\Services\AdminOperationLogger::log($request, 'admin_accounts.toggle_active', sprintf('切换后台账号 #%d「%s」启用状态：%s', $adminUser->id, (string) $adminUser->display_name, $adminUser->is_active ? '启用' : '停用'), ['target_type' => 'admin_user', 'target_id' => $adminUser->id, 'is_active' => (bool) $adminUser->is_active]);

        return back()->with('status', $adminUser->is_active ? '后台账号已启用。' : '后台账号已停用。');
    }

    private function syncAccountPermissions(AdminUser $admin, array $permissionKeys): void
    {
        $permissionIds = AdminPermission::query()
            ->whereIn('permission_key', $permissionKeys)
            ->pluck('id')
            ->all();

        $admin->permissions()->sync($permissionIds);
    }

    private function isLastSuperAdmin(AdminUser $adminUser): bool
    {
        if (! $adminUser->is_super_admin) {
            return false;
        }

        return AdminUser::query()
            ->where('is_super_admin', true)
            ->where('is_active', true)
            ->where('id', '!=', $adminUser->id)
            ->doesntExist();
    }
}
