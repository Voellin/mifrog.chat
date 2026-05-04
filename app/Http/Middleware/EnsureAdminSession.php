<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;

class EnsureAdminSession
{
    public function handle(Request $request, Closure $next)
    {
        $adminId = (int) $request->session()->get('admin_user_id');
        if (! $adminId) {
            return redirect('/admin/login');
        }

        $admin = AdminUser::query()->where('id', $adminId)->where('is_active', true)->first();
        if (! $admin) {
            $request->session()->forget('admin_user_id');

            return redirect('/admin/login');
        }

        $request->attributes->set('admin_user', $admin);

        return $next($request);
    }
}

