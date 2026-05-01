<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAdminPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        $admin = $request->attributes->get('admin_user');
        $permissions = array_values(array_filter(array_map('trim', $permissions)));

        if (! $admin || empty($permissions) || ! $admin->hasAnyAdminPermission($permissions)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            abort(403, '你没有权限执行此操作。');
        }

        return $next($request);
    }
}
