<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\QuotaUsageLedger;
use App\Models\Run;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function index()
    {
        $runsTotal = Run::query()->count();
        $runsSuccess = Run::query()->where('status', Run::STATUS_SUCCESS)->count();
        $runsFailed = Run::query()->where('status', Run::STATUS_FAILED)->count();

        $stats = [
            'users' => User::query()->count(),
            'departments' => Department::query()->count(),
            'conversations' => Conversation::query()->count(),
            'runs_total' => $runsTotal,
            'runs_today' => Run::query()->whereDate('created_at', today())->count(),
            'runs_failed' => $runsFailed,
            'success_rate' => $runsTotal > 0 ? round(($runsSuccess / $runsTotal) * 100, 2) : 0,
            'token_used_this_month' => (int) QuotaUsageLedger::query()
                ->where('period_key', now()->format('Y-m'))
                ->sum('used_tokens'),
            'avg_latency_seconds' => (float) Run::query()
                ->whereNotNull('started_at')
                ->whereNotNull('finished_at')
                ->whereRaw('TIMESTAMPDIFF(SECOND, started_at, finished_at) <= 600')
                ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, started_at, finished_at)) as avg_seconds')
                ->value('avg_seconds'),
        ];

        $charts = [
            'runs_trend' => $this->buildRunsTrend(14),
            'status_distribution' => $this->buildStatusDistribution(),
            'token_trend' => $this->buildTokenTrend(14),
            'department_usage' => $this->buildDepartmentUsageTop(),
        ];

        $userTokenUsage = $this->buildUserTokenUsage();

        return view('admin.dashboard', compact('stats', 'charts', 'userTokenUsage'));
    }

    private function buildRunsTrend(int $days): array
    {
        $from = now()->subDays($days - 1)->toDateString();

        $countMap = Run::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->whereDate('created_at', '>=', $from)
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('total', 'day')
            ->all();

        $latencyMap = Run::query()
            ->selectRaw('DATE(created_at) as day, AVG(TIMESTAMPDIFF(SECOND, started_at, finished_at)) as avg_latency')
            ->whereDate('created_at', '>=', $from)
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->whereRaw('TIMESTAMPDIFF(SECOND, started_at, finished_at) <= 600')
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('avg_latency', 'day')
            ->all();

        $labels = [];
        $runs = [];
        $avgLatency = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $labels[] = now()->subDays($i)->format('m-d');
            $runs[] = (int) ($countMap[$day] ?? 0);
            $avgLatency[] = round((float) ($latencyMap[$day] ?? 0), 2);
        }

        return [
            'labels' => $labels,
            'runs' => $runs,
            'avg_latency' => $avgLatency,
        ];
    }

    private function buildStatusDistribution(): array
    {
        $raw = Run::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        return [
            'labels' => ['Queued', 'Running', 'Needs Input', 'Waiting Auth', 'Success', 'Failed'],
            'values' => [
                (int) ($raw[Run::STATUS_QUEUED] ?? 0),
                (int) ($raw[Run::STATUS_RUNNING] ?? 0),
                (int) ($raw[Run::STATUS_NEEDS_INPUT] ?? 0),
                (int) ($raw[Run::STATUS_WAITING_AUTH] ?? 0),
                (int) ($raw[Run::STATUS_SUCCESS] ?? 0),
                (int) ($raw[Run::STATUS_FAILED] ?? 0),
            ],
        ];
    }

    private function buildTokenTrend(int $days): array
    {
        $from = now()->subDays($days - 1)->toDateString();

        $tokenMap = QuotaUsageLedger::query()
            ->selectRaw('DATE(created_at) as day, SUM(used_tokens) as total_tokens')
            ->whereDate('created_at', '>=', $from)
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('total_tokens', 'day')
            ->all();

        $labels = [];
        $values = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $labels[] = now()->subDays($i)->format('m-d');
            $values[] = (int) ($tokenMap[$day] ?? 0);
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

private function buildDepartmentUsageTop(): array
    {
        $periodKey = now()->format('Y-m');

        // Build depth map: parent_id NULL = depth 1
        $departments = Department::query()->get(['id', 'name', 'parent_id']);
        $depthMap = [];
        foreach ($departments as $dept) {
            $depth = 1;
            $pid = $dept->parent_id;
            while ($pid !== null && $depth < 10) {
                $parent = $departments->firstWhere('id', $pid);
                if (!$parent) break;
                $pid = $parent->parent_id;
                $depth++;
            }
            $depthMap[$dept->id] = ['name' => $dept->name, 'depth' => $depth];
        }

        // Get token usage per department this month
        $usageMap = \App\Models\QuotaUsageLedger::query()
            ->where('period_key', $periodKey)
            ->selectRaw('department_id, SUM(used_tokens) as total_tokens')
            ->groupBy('department_id')
            ->pluck('total_tokens', 'department_id')
            ->all();

        // Build data for each level (1, 2, 3)
        $result = [];
        for ($level = 1; $level <= 3; $level++) {
            $levelDepts = [];
            foreach ($depthMap as $deptId => $info) {
                if ($info['depth'] === $level) {
                    $levelDepts[] = [
                        'name' => $info['name'],
                        'tokens' => (int) ($usageMap[$deptId] ?? 0),
                    ];
                }
            }
            // Sort by tokens desc, take top 10
            usort($levelDepts, fn ($a, $b) => $b['tokens'] <=> $a['tokens']);
            $levelDepts = array_slice($levelDepts, 0, 10);

            if (empty($levelDepts)) {
                $result["level_{$level}"] = ['labels' => ['暂无数据'], 'values' => [0]];
            } else {
                $result["level_{$level}"] = [
                    'labels' => array_map(fn ($d) => $d['name'], $levelDepts),
                    'values' => array_map(fn ($d) => $d['tokens'], $levelDepts),
                ];
            }
        }

        return $result;
    }

    private function buildUserTokenUsage()
    {
        $periodKey = now()->format('Y-m');

        $monthlySub = QuotaUsageLedger::query()
            ->selectRaw('user_id, SUM(used_tokens) as monthly_tokens')
            ->where('period_key', $periodKey)
            ->groupBy('user_id');

        $totalSub = QuotaUsageLedger::query()
            ->selectRaw('user_id, SUM(used_tokens) as total_tokens')
            ->groupBy('user_id');

        $lastUsedSub = QuotaUsageLedger::query()
            ->selectRaw('user_id, MAX(created_at) as last_used_at')
            ->groupBy('user_id');

        $rows = User::query()
            ->leftJoinSub($monthlySub, 'm', function ($join): void {
                $join->on('users.id', '=', 'm.user_id');
            })
            ->leftJoinSub($totalSub, 't', function ($join): void {
                $join->on('users.id', '=', 't.user_id');
            })
            ->leftJoinSub($lastUsedSub, 'lu', function ($join): void {
                $join->on('users.id', '=', 'lu.user_id');
            })
            ->leftJoin('departments', 'users.department_id', '=', 'departments.id')
            ->select([
                'users.id',
                'users.name',
                'departments.name as department_name',
                DB::raw('COALESCE(m.monthly_tokens, 0) as monthly_tokens'),
                DB::raw('COALESCE(t.total_tokens, 0) as total_tokens'),
                'lu.last_used_at',
            ])
            ->orderByDesc('monthly_tokens')
            ->orderBy('users.id')
            ->get();

        // Resolve display_name from encrypted UserIdentity.extra in PHP
        $identityMap = \App\Models\UserIdentity::query()
            ->where('provider', 'feishu')
            ->whereIn('user_id', $rows->pluck('id'))
            ->get()
            ->keyBy('user_id');

        return $rows->map(function ($row) use ($identityMap) {
            $identity = $identityMap->get($row->id);
            $extra = is_array($identity?->extra) ? $identity->extra : [];
            $feishuName = trim((string) ($extra['name'] ?? $extra['display_name'] ?? ''));

            $row->display_name = $feishuName !== ''
                ? $feishuName
                : (str_starts_with((string) $row->name, 'feishu_') ? '飞书用户' . $row->id : $row->name);

            return $row;
        });
    }
}
