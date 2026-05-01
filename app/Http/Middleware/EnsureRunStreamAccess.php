<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Services\RunAccessTokenService;
use Closure;
use Illuminate\Http\Request;

class EnsureRunStreamAccess
{
    public function __construct(private readonly RunAccessTokenService $runAccessTokenService)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $adminHeaderToken = trim((string) $request->header('X-Admin-Token', ''));
        $expectedAdminToken = (string) Setting::read('admin_api_token', env('ADMIN_API_TOKEN', ''));

        if ($expectedAdminToken !== '' && $adminHeaderToken !== '' && hash_equals($expectedAdminToken, $adminHeaderToken)) {
            return $next($request);
        }

        $run = $request->route('run');
        $runId = is_object($run) ? (int) ($run->id ?? 0) : (int) $run;
        if ($runId <= 0) {
            return response()->json(['message' => 'Invalid run id'], 400);
        }

        $streamToken = trim((string) $request->header('X-Run-Token', $request->query('stream_token', '')));
        if (! $this->runAccessTokenService->validate($runId, $streamToken)) {
            return response()->json(['message' => 'Forbidden stream access'], 403);
        }

        return $next($request);
    }
}

