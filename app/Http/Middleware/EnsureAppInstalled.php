<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAppInstalled
{
    public function handle(Request $request, Closure $next)
    {
        $lock = storage_path('app/setup.lock');

        if (! file_exists($lock) && ! $request->is('setup') && ! $request->is('setup/*')) {
            return redirect('/setup');
        }

        return $next($request);
    }
}

