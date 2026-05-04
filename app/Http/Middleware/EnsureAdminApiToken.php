<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;

class EnsureAdminApiToken
{
    public function handle(Request $request, Closure $next)
    {
        $provided = $request->header('X-Admin-Token');
        $expected = (string) Setting::read('admin_api_token', env('ADMIN_API_TOKEN', ''));

        if (! $expected || ! hash_equals($expected, (string) $provided)) {
            return response()->json([
                'message' => 'Unauthorized admin token',
            ], 401);
        }

        return $next($request);
    }
}
