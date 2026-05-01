<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditPolicy;
use App\Models\Department;
use App\Models\RunAuditRecord;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminAuditController extends Controller
{
    private AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    public function index(Request $request)
    {
        $stage = trim((string) $request->query('stage', ''));
        $hit = trim((string) $request->query('hit', ''));
        $decision = trim((string) $request->query('decision', ''));
        $policyId = (int) $request->query('policy_id', 0);
        [$range, $startAt, $endAt, $startDate, $endDate] = $this->resolveTimeRange($request);

        $policies = $this->auditService->listPolicies();
        $departments = Department::query()->orderBy('name')->get(['id', 'name']);

        $recordsQuery = RunAuditRecord::query()
            ->with(['user:id,name,department_id', 'run:id,status,intent_type'])
            ->when(in_array($stage, ['input', 'output'], true), fn ($query) => $query->where('stage', $stage))
            ->when(in_array($hit, ['0', '1'], true), fn ($query) => $query->where('hit', $hit === '1'))
            ->when($decision !== '', fn ($query) => $query->where('decision', $decision))
            ->when($policyId > 0, function ($query) use ($policyId): void {
                $query->where(function ($q) use ($policyId): void {
                    $q->whereJsonContains('matched_policy_ids', $policyId)
                        ->orWhereJsonContains('matched_policy_ids', (string) $policyId);
                });
            });
        $this->applyTimeRangeFilter($recordsQuery, $startAt, $endAt);

        $records = $recordsQuery
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        [$departmentHitRanking, $userHitRanking] = $this->buildHitRankings($stage, $hit, $decision, $policyId, $startAt, $endAt);

        $todayBattle = $this->buildTodayBattle($policies);

        return view('admin.audits', [
            'policies' => $policies,
            'departments' => $departments,
            'summary' => [
                'policy_total' => $policies->count(),
                'active_policy_total' => $policies->where('is_active', true)->count(),
                'department_policy_total' => $policies->where('scope_type', AuditService::SCOPE_DEPARTMENT)->count(),
                'global_policy_total' => $policies->where('scope_type', AuditService::SCOPE_GLOBAL)->count(),
            ],
            'records' => $records,
            'departmentHitRanking' => $departmentHitRanking,
            'userHitRanking' => $userHitRanking,
            'todayBattle' => $todayBattle,
            'filters' => [
                'stage' => $stage,
                'hit' => $hit,
                'decision' => $decision,
                'policy_id' => $policyId,
                'range' => $range,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }

    /**
     * 首屏 "今日战报" 数据:
     *   - today_hit_total: 今日 hit=1 条数
     *   - today_blocked / today_masked / today_pass_on_hit: 今日各处理类型条数
     *   - last_hit_at: 最近一次命中时间
     *   - top_policies: 今日触发最多的策略 Top 3
     *   - recent_hits: 最近 5 条命中记录
     */
    private function buildTodayBattle(Collection $policies): array
    {
        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();

        $todayQuery = RunAuditRecord::query()
            ->where('hit', true)
            ->where('created_at', '>=', $todayStart)
            ->where('created_at', '<=', $todayEnd);

        $todayHitTotal = (clone $todayQuery)->count();
        $todayBlocked = (clone $todayQuery)->where('decision', 'blocked')->count();
        $todayMasked = (clone $todayQuery)->where('decision', 'masked')->count();

        $lastHit = RunAuditRecord::query()
            ->where('hit', true)
            ->orderByDesc('id')
            ->first(['id', 'created_at', 'decision']);

        $rawHitsToday = (clone $todayQuery)
            ->orderByDesc('id')
            ->limit(2000)
            ->get(['matched_policy_ids', 'matched_policy_names']);

        $policyHitCounts = [];
        $policyNameMap = [];
        foreach ($rawHitsToday as $rec) {
            $ids = is_array($rec->matched_policy_ids) ? $rec->matched_policy_ids : [];
            $names = is_array($rec->matched_policy_names) ? $rec->matched_policy_names : [];
            foreach ($ids as $i => $pid) {
                $pid = (int) $pid;
                if ($pid <= 0) {
                    continue;
                }
                $policyHitCounts[$pid] = ($policyHitCounts[$pid] ?? 0) + 1;
                if (!isset($policyNameMap[$pid]) && isset($names[$i])) {
                    $policyNameMap[$pid] = (string) $names[$i];
                }
            }
        }
        arsort($policyHitCounts);
        $topPolicyIds = array_slice(array_keys($policyHitCounts), 0, 3);

        $policyById = $policies->keyBy('id');
        $topPolicies = [];
        foreach ($topPolicyIds as $pid) {
            $name = $policyNameMap[$pid] ?? ($policyById->get($pid)?->name ?? '策略#'.$pid);
            $topPolicies[] = [
                'id' => $pid,
                'name' => $name,
                'hit_count' => (int) ($policyHitCounts[$pid] ?? 0),
            ];
        }

        $recentHits = RunAuditRecord::query()
            ->with(['user:id,name'])
            ->where('hit', true)
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'user_id', 'stage', 'decision', 'matched_terms', 'matched_policy_names', 'content_excerpt', 'created_at']);

        return [
            'today_hit_total' => (int) $todayHitTotal,
            'today_blocked' => (int) $todayBlocked,
            'today_masked' => (int) $todayMasked,
            'today_pass_on_hit' => (int) max(0, $todayHitTotal - $todayBlocked - $todayMasked),
            'last_hit_at' => $lastHit?->created_at,
            'last_hit_decision' => $lastHit?->decision,
            'top_policies' => $topPolicies,
            'recent_hits' => $recentHits,
        ];
    }

    public function export(Request $request): StreamedResponse
    {
        $stage = trim((string) $request->query('stage', ''));
        $hit = trim((string) $request->query('hit', ''));
        $decision = trim((string) $request->query('decision', ''));
        $policyId = (int) $request->query('policy_id', 0);
        [$range, $startAt, $endAt] = $this->resolveTimeRange($request);

        $recordsQuery = RunAuditRecord::query()
            ->with(['user:id,name,department_id', 'run:id,status,intent_type'])
            ->when(in_array($stage, ['input', 'output'], true), fn ($query) => $query->where('stage', $stage))
            ->when(in_array($hit, ['0', '1'], true), fn ($query) => $query->where('hit', $hit === '1'))
            ->when($decision !== '', fn ($query) => $query->where('decision', $decision))
            ->when($policyId > 0, function ($query) use ($policyId): void {
                $query->where(function ($q) use ($policyId): void {
                    $q->whereJsonContains('matched_policy_ids', $policyId)
                        ->orWhereJsonContains('matched_policy_ids', (string) $policyId);
                });
            })
            ->orderByDesc('id');

        $this->applyTimeRangeFilter($recordsQuery, $startAt, $endAt);

        $filename = 'audit_logs_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($recordsQuery): void {
            $stream = fopen('php://output', 'w');
            if (! $stream) {
                return;
            }

            fputs($stream, "\xEF\xBB\xBF");
            fputcsv($stream, [
                'ID', 'RunID', '用户ID', '用户名', '阶段', '是否命中',
                '动作', '结果', '命中策略', '命中词', '内容摘要', '时间',
            ]);

            $recordsQuery->chunk(500, function ($rows) use ($stream): void {
                foreach ($rows as $record) {
                    $policyNames = is_array($record->matched_policy_names) ? implode(' | ', $record->matched_policy_names) : '';
                    $matchedTerms = is_array($record->matched_terms) ? implode(' | ', $record->matched_terms) : '';
                    $userName = trim((string) ($record->user?->name ?? ''));
                    if ($userName === '') {
                        $userName = '用户#'.(int) $record->user_id;
                    }

                    fputcsv($stream, [
                        (int) $record->id,
                        (int) ($record->run_id ?? 0),
                        (int) ($record->user_id ?? 0),
                        $userName,
                        (string) $record->stage,
                        $record->hit ? '命中' : '未命中',
                        (string) ($record->action ?? ''),
                        (string) ($record->decision ?? ''),
                        $policyNames,
                        $matchedTerms,
                        (string) ($record->content_excerpt ?? ''),
                        optional($record->created_at)->toDateTimeString(),
                    ]);
                }
            });

            fclose($stream);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function storePolicy(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'scope_type' => 'required|string|in:global,department',
            'department_id' => 'nullable|integer|exists:departments,id',
            'priority' => 'nullable|integer|min:0|max:100000',
            'is_active' => 'nullable|boolean',
            'terms_text' => 'nullable|string|max:30000',
            'input_action' => 'required|string|in:allow,block',
            'output_action' => 'required|string|in:allow,mask,block',
            'blocked_message' => 'nullable|string|max:2000',
        ]);

        if (($data['scope_type'] ?? '') === AuditService::SCOPE_DEPARTMENT && (int) ($data['department_id'] ?? 0) <= 0) {
            return redirect('/admin/audits')->with('error', '部门策略必须选择部门。');
        }

        $this->auditService->createPolicy($data);

        \App\Services\AdminOperationLogger::log($request, 'audits.policies.create', '新建审计策略', ['target_type' => 'audit_policy']);
        return redirect('/admin/audits')->with('status', '审计策略已创建。');
    }

    public function updatePolicy(Request $request, AuditPolicy $policy)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'scope_type' => 'required|string|in:global,department',
            'department_id' => 'nullable|integer|exists:departments,id',
            'priority' => 'nullable|integer|min:0|max:100000',
            'is_active' => 'nullable|boolean',
            'terms_text' => 'nullable|string|max:30000',
            'input_action' => 'required|string|in:allow,block',
            'output_action' => 'required|string|in:allow,mask,block',
            'blocked_message' => 'nullable|string|max:2000',
        ]);

        if (($data['scope_type'] ?? '') === AuditService::SCOPE_DEPARTMENT && (int) ($data['department_id'] ?? 0) <= 0) {
            return redirect('/admin/audits')->with('error', '部门策略必须选择部门。');
        }

        $this->auditService->updatePolicy($policy, $data);

        \App\Services\AdminOperationLogger::log($request, 'audits.policies.update', sprintf('编辑审计策略 #%d', $policy->id), ['target_type' => 'audit_policy', 'target_id' => $policy->id]);
        return redirect('/admin/audits#policy-'.$policy->id)->with('status', '审计策略已更新。');
    }

    /**
     * Q2: 软删除审计策略 — 参考 Skill::destroy，需要二次确认（输入策略名一致）。
     * 不真正删除：SoftDeletes 仅设 deleted_at；audit_policy_terms 不连带处理（保留以便恢复）。
     */
    public function destroyPolicy(Request $request, AuditPolicy $policy)
    {
        $data = $request->validate([
            'confirm_name' => ['required', 'string'],
        ]);

        if (trim((string) $data['confirm_name']) !== trim((string) $policy->name)) {
            return back()->withErrors([
                'confirm_name' => '输入的策略名称与待删除策略不一致，已取消。',
            ]);
        }

        $policyId = (int) $policy->id;
        $policyName = (string) $policy->name;
        $scopeType = (string) $policy->scope_type;

        $policy->delete(); // SoftDeletes：仅置 deleted_at；audit_policy_terms 不连带

        \App\Services\AdminOperationLogger::log(
            $request,
            'audits.policies.destroy',
            sprintf('删除审计策略 #%d「%s」(scope=%s)', $policyId, $policyName, $scopeType),
            [
                'target_type' => 'audit_policy',
                'target_id' => $policyId,
                'policy_name' => $policyName,
                'scope_type' => $scopeType,
            ]
        );

        return redirect('/admin/audits?_tab=policy')->with('status', sprintf('审计策略「%s」已软删除。', $policyName));
    }

    /**
     * @return array{0: Collection<int, object>, 1: Collection<int, object>}
     */
    private function buildHitRankings(string $stage, string $hit, string $decision, int $policyId, ?Carbon $startAt, ?Carbon $endAt): array
    {
        if ($hit === '0') {
            return [collect(), collect()];
        }

        $baseQuery = RunAuditRecord::query()
            ->where('hit', true)
            ->when(in_array($stage, ['input', 'output'], true), fn ($query) => $query->where('stage', $stage))
            ->when($decision !== '', fn ($query) => $query->where('decision', $decision))
            ->when($policyId > 0, function ($query) use ($policyId): void {
                $query->where(function ($q) use ($policyId): void {
                    $q->whereJsonContains('matched_policy_ids', $policyId)
                        ->orWhereJsonContains('matched_policy_ids', (string) $policyId);
                });
            });
        $this->applyTimeRangeFilter($baseQuery, $startAt, $endAt);

        $departmentHitRanking = (clone $baseQuery)
            ->leftJoin('users', 'users.id', '=', 'run_audit_records.user_id')
            ->leftJoin('departments', 'departments.id', '=', 'users.department_id')
            ->selectRaw('users.department_id as department_id, MAX(departments.name) as department_name, COUNT(*) as hit_count')
            ->groupBy('users.department_id')
            ->orderByDesc('hit_count')
            ->limit(15)
            ->get()
            ->map(function ($row) {
                $row->department_name = trim((string) ($row->department_name ?? '')) !== ''
                    ? (string) $row->department_name
                    : '未分配部门';
                return $row;
            })
            ->values();

        $userHitRanking = (clone $baseQuery)
            ->leftJoin('users', 'users.id', '=', 'run_audit_records.user_id')
            ->leftJoin('departments', 'departments.id', '=', 'users.department_id')
            ->selectRaw('run_audit_records.user_id, MAX(users.name) as user_name, MAX(users.feishu_open_id) as feishu_open_id, MAX(departments.name) as department_name, COUNT(*) as hit_count')
            ->groupBy('run_audit_records.user_id')
            ->orderByDesc('hit_count')
            ->limit(20)
            ->get()
            ->map(function ($row) {
                $resolvedName = trim((string) ($row->user_name ?? ''));
                $openId = trim((string) ($row->feishu_open_id ?? ''));
                if ($resolvedName === '') {
                    $resolvedName = $openId !== '' ? '飞书用户('.$openId.')' : '用户#'.$row->user_id;
                }
                $row->user_name = $resolvedName;
                $row->department_name = trim((string) ($row->department_name ?? '')) !== ''
                    ? (string) $row->department_name
                    : '未分配部门';
                return $row;
            })
            ->values();

        return [$departmentHitRanking, $userHitRanking];
    }

    private function resolveTimeRange(Request $request): array
    {
        $range = strtolower(trim((string) $request->query('range', '30d')));
        if (! in_array($range, ['today', '7d', '30d', 'custom', 'all'], true)) {
            $range = '30d';
        }

        $startDate = trim((string) $request->query('start_date', ''));
        $endDate = trim((string) $request->query('end_date', ''));

        $startAt = null;
        $endAt = null;

        if ($range === 'today') {
            $startAt = now()->startOfDay();
            $endAt = now()->endOfDay();
            $startDate = $startAt->toDateString();
            $endDate = $endAt->toDateString();
        } elseif ($range === '7d') {
            $startAt = now()->subDays(6)->startOfDay();
            $endAt = now()->endOfDay();
            $startDate = $startAt->toDateString();
            $endDate = $endAt->toDateString();
        } elseif ($range === '30d') {
            $startAt = now()->subDays(29)->startOfDay();
            $endAt = now()->endOfDay();
            $startDate = $startAt->toDateString();
            $endDate = $endAt->toDateString();
        } elseif ($range === 'custom') {
            try {
                if ($startDate !== '') {
                    $startAt = Carbon::parse($startDate)->startOfDay();
                }
                if ($endDate !== '') {
                    $endAt = Carbon::parse($endDate)->endOfDay();
                }
            } catch (\Throwable) {
                $startAt = null;
                $endAt = null;
            }
        }

        return [$range, $startAt, $endAt, $startDate, $endDate];
    }

    private function applyTimeRangeFilter($query, ?Carbon $startAt, ?Carbon $endAt): void
    {
        if ($startAt !== null) {
            $query->where('run_audit_records.created_at', '>=', $startAt->toDateTimeString());
        }

        if ($endAt !== null) {
            $query->where('run_audit_records.created_at', '<=', $endAt->toDateTimeString());
        }
    }
}
