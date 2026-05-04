<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\QuotaUsageLedger;
use App\Models\Run;
use App\Models\User;

class DashboardController extends Controller
{
    public function index()
    {
        $runsTotal = Run::query()->count();
        $runsSuccess = Run::query()->where('status', Run::STATUS_SUCCESS)->count();

        return response()->json([
            'users_total' => User::query()->count(),
            'runs_total' => $runsTotal,
            'runs_today' => Run::query()->whereDate('created_at', today())->count(),
            'runs_failed' => Run::query()->where('status', Run::STATUS_FAILED)->count(),
            'runs_needs_input' => Run::query()->where('status', Run::STATUS_NEEDS_INPUT)->count(),
            'success_rate' => $runsTotal > 0 ? round(($runsSuccess / $runsTotal) * 100, 2) : 0,
            'token_used_this_month' => (int) QuotaUsageLedger::query()
                ->where('period_key', now()->format('Y-m'))
                ->sum('used_tokens'),
            'trend_7d' => $this->trend7d(),
        ]);
    }

    private function trend7d(): array
    {
        $from = now()->subDays(6)->toDateString();
        $map = Run::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->whereDate('created_at', '>=', $from)
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('total', 'day')
            ->all();

        $labels = [];
        $values = [];

        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $labels[] = now()->subDays($i)->format('m-d');
            $values[] = (int) ($map[$day] ?? 0);
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }
}
