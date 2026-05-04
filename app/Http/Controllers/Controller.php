<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected function authorizeAdminPermission(Request $request, string $permission): void
    {
        $admin = $request->attributes->get('admin_user');
        abort_unless($admin && $admin->hasAdminPermission($permission), 403, '你没有权限执行此操作。');
    }
}
