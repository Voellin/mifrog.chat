<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\QuotaPolicy;
use App\Models\QuotaUsageLedger;
use App\Models\Run;
use App\Models\Setting;
use App\Models\Skill;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\FeishuService;
use App\Services\MemoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AdminUserController extends Controller
{
    public function toggleActive(\Illuminate\Http\Request $request, User $user): RedirectResponse
    {
        $before = (bool) $user->is_active;
        $user->is_active = ! $before;
        $user->save();

        \App\Services\AdminOperationLogger::log($request, 'users.toggle_active', sprintf('用户 #%d「%s」启用状态：%s → %s', $user->id, (string) $user->name, $before ? 'true' : 'false', $user->is_active ? 'true' : 'false'), ['target_type' => 'user', 'target_id' => $user->id, 'before_active' => $before, 'after_active' => (bool) $user->is_active]);

        return redirect()->back()->with('success', $user->is_active ? '已启用' : '已停用');
    }

    private FeishuService $feishuService;
    private MemoryService $memoryService;
    private AttachmentService $attachmentService;

    public function __construct(
        FeishuService $feishuService,
        MemoryService $memoryService,
        AttachmentService $attachmentService
    )
    {
        $this->feishuService = $feishuService;
        $this->memoryService = $memoryService;
        $this->attachmentService = $attachmentService;
    }

    public function index(Request $request)
    {
        $keyword = trim((string) $request->query('keyword', ''));
        $departmentId = (int) $request->query('department_id', 0);

        $departments = Department::query()
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id', 'feishu_department_id']);

        $allDepartmentRows = $this->flattenDepartments($departments);

        // Full department user counts (unfiltered)
        $departmentUserCount = User::query()
            ->selectRaw('department_id, COUNT(*) as total')
            ->groupBy('department_id')
            ->pluck('total', 'department_id');

        $usersQuery = User::query()
            ->with([
                'department:id,name',
                'identities' => function ($query): void {
                    $query->where('provider', 'feishu');
                },
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($departmentId > 0) {
            $usersQuery->where('department_id', $departmentId);
        }

        if ($keyword !== '') {
            $usersQuery->where(function ($query) use ($keyword): void {
                $query->where('name', 'like', '%'.$keyword.'%')
                    ->orWhere('email', 'like', '%'.$keyword.'%')
                    ->orWhere('feishu_open_id', 'like', '%'.$keyword.'%')
                    ->orWhere('title', 'like', '%'.$keyword.'%');
            });
        }

        $users = $usersQuery->paginate(30)->withQueryString();
        $users->setCollection(
            $users->getCollection()->map(function (User $user): User {
                $this->decorateUserDisplay($user);

                return $user;
            })
        );

        // When keyword or department is selected, also filter org tree
        $departmentRows = $allDepartmentRows;
        $filteredDeptUserCount = $departmentUserCount;

        $isFiltering = $keyword !== '';
        if ($isFiltering) {
            // Build a filtered user count per department matching the same filters
            $filteredCountQuery = User::query()->selectRaw('department_id, COUNT(*) as total')->groupBy('department_id');
            if ($departmentId > 0) {
                $filteredCountQuery->where('department_id', $departmentId);
            }
            if ($keyword !== '') {
                $filteredCountQuery->where(function ($query) use ($keyword): void {
                    $query->where('name', 'like', '%'.$keyword.'%')
                        ->orWhere('email', 'like', '%'.$keyword.'%')
                        ->orWhere('feishu_open_id', 'like', '%'.$keyword.'%')
                        ->orWhere('title', 'like', '%'.$keyword.'%');
                });
            }
            $filteredDeptUserCount = $filteredCountQuery->pluck('total', 'department_id');

            // Visible departments: those with matching users + their ancestors
            $matchedDeptIds = $filteredDeptUserCount->keys()->toArray();
            $deptById = $departments->keyBy('id');
            $visibleIds = [];
            foreach ($matchedDeptIds as $id) {
                $current = (int) $id;
                while ($current > 0 && ! isset($visibleIds[$current])) {
                    $visibleIds[$current] = true;
                    $parentId = (int) ($deptById[$current]->parent_id ?? 0);
                    $current = $parentId;
                }
            }

            $departmentRows = array_values(array_filter($allDepartmentRows, function ($row) use ($visibleIds) {
                return isset($visibleIds[$row['id']]);
            }));
        }

        $syncStatus = Setting::read('feishu_sync_status', []);

        $viewData = [
            'departments' => $departments,
            'departmentRows' => $departmentRows,
            'allDepartmentRows' => $allDepartmentRows,
            'departmentUserCount' => $isFiltering ? $filteredDeptUserCount : $departmentUserCount,
            'users' => $users,
            'keyword' => $keyword,
            'selectedDepartmentId' => $departmentId,
            'stats' => [
                'department_total' => $departments->count(),
                'user_total' => User::query()->count(),
                'feishu_user_total' => User::query()->whereNotNull('feishu_open_id')->count(),
                'active_user_total' => User::query()->where('is_active', true)->count(),
            ],
            'syncStatus' => $syncStatus,
        ];

        // AJAX: return only the member-list partial HTML
        if ($request->ajax()) {
            return response()->json([
                'html' => view('admin.users-member-list', $viewData)->render(),
            ]);
        }

        return view('admin.users', $viewData);
    }

    public function show(User $user)
    {
        $user->load([
            'department:id,name',
            'identities' => function ($query): void {
                $query->where('provider', 'feishu');
            },
        ]);
        $this->decorateUserDisplay($user);

        $periodKey = now()->format('Y-m');
        $monthlyUsage = (int) QuotaUsageLedger::query()
            ->where('user_id', $user->id)
            ->where('period_key', $periodKey)
            ->sum('used_tokens');
        $totalUsage = (int) QuotaUsageLedger::query()
            ->where('user_id', $user->id)
            ->sum('used_tokens');

        $monthStart = now()->startOfMonth()->toDateString();
        $monthlyRuns = Run::query()
            ->where('user_id', $user->id)
            ->whereDate('created_at', '>=', $monthStart)
            ->count();
        $monthlyInputTokens = (int) Run::query()
            ->where('user_id', $user->id)
            ->whereDate('created_at', '>=', $monthStart)
            ->sum('input_tokens');
        $monthlyOutputTokens = (int) Run::query()
            ->where('user_id', $user->id)
            ->whereDate('created_at', '>=', $monthStart)
            ->sum('output_tokens');

        $userPolicy = QuotaPolicy::query()
            ->where('is_active', true)
            ->where('period', 'monthly')
            ->where('user_id', $user->id)
            ->first();
        $deptPolicy = null;
        if ($user->department_id) {
            $deptPolicy = QuotaPolicy::query()
                ->where('is_active', true)
                ->where('period', 'monthly')
                ->where('department_id', $user->department_id)
                ->whereNull('user_id')
                ->first();
        }
        $defaultMonthlyLimit = (int) Setting::read('default_monthly_quota_tokens', 0);
        $effectiveLimit = $userPolicy
            ? (int) $userPolicy->token_limit
            : ($deptPolicy ? (int) $deptPolicy->token_limit : $defaultMonthlyLimit);

        $skills = $this->fetchUserAvailableSkills($user);
        $memory = $this->memoryService->getUserMemoryViewData((int) $user->id, null, null);
        $knowledge = $this->attachmentService->getUserKnowledgeOverview((int) $user->id);
        // 系统自动归档（过去 7 天的 L2 entries with tag source:proactive_collect）
        $proactiveArchives = \App\Models\MemoryEntry::query()
            ->where('user_id', $user->id)
            ->where('layer', 'L2')
            ->whereJsonContains('tags', 'source:proactive_collect')
            ->where('created_at', '>=', now()->subDays(7))
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $recentRuns = Run::query()
            ->where('user_id', $user->id)
            ->with([
                'stateTransitions' => function ($query): void {
                    $query->orderBy('id');
                },
                'events' => function ($query): void {
                    $query->orderByDesc('id')->limit(1);
                },
            ])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $l3Preview = trim((string) ($memory['l3_content'] ?? ''));
        if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($l3Preview, 'UTF-8') > 600) {
            $l3Preview = mb_substr($l3Preview, 0, 600, 'UTF-8').'...';
        } elseif (strlen($l3Preview) > 600) {
            $l3Preview = substr($l3Preview, 0, 600).'...';
        }

        return view('admin.user_show', [
            'user' => $user,
            'proactiveArchives' => $proactiveArchives,
            'identityExtra' => $user->getAttribute('identity_extra') ?: [],
            'tokenStats' => [
                'period_key' => $periodKey,
                'monthly_usage' => $monthlyUsage,
                'total_usage' => $totalUsage,
                'monthly_runs' => $monthlyRuns,
                'monthly_input_tokens' => $monthlyInputTokens,
                'monthly_output_tokens' => $monthlyOutputTokens,
                'effective_limit' => $effectiveLimit,
                'remaining' => $effectiveLimit > 0 ? max(0, $effectiveLimit - $monthlyUsage) : null,
                'user_policy_limit' => $userPolicy ? (int) $userPolicy->token_limit : null,
                'department_policy_limit' => $deptPolicy ? (int) $deptPolicy->token_limit : null,
                'default_limit' => $defaultMonthlyLimit,
            ],
            'memoryStats' => [
                'sessions_count' => count((array) ($memory['sessions'] ?? [])),
                'l2_files_count' => count((array) ($memory['l2_files'] ?? [])),
                'l3_facts_count' => count((array) ($memory['l3_facts'] ?? [])),
                'recent_entries_count' => count((array) ($memory['recent_entries'] ?? [])),
                'latest_session' => Arr::first((array) ($memory['sessions'] ?? [])),
                'latest_l2' => Arr::first((array) ($memory['l2_files'] ?? [])),
                'l3_preview' => $l3Preview,
            ],
            'skills' => $skills,
            'knowledgeStats' => $knowledge,
            'recentRuns' => $recentRuns,
        ]);
    }



    public function syncFromFeishu(Request $request): RedirectResponse
    {
        $result = $this->feishuService->syncOrganizationAndMembers();

        Setting::write('feishu_sync_status', [
            'ok' => (bool) ($result['ok'] ?? false),
            'message' => (string) ($result['message'] ?? ''),
            'finished_at' => $result['finished_at'] ?? now()->toDateTimeString(),
            'duration_ms' => (int) ($result['duration_ms'] ?? 0),
            'departments_synced' => (int) ($result['departments_synced'] ?? 0),
            'users_synced' => (int) ($result['users_synced'] ?? 0),
            'departments_created' => (int) ($result['departments_created'] ?? 0),
            'departments_updated' => (int) ($result['departments_updated'] ?? 0),
            'users_created' => (int) ($result['users_created'] ?? 0),
            'users_updated' => (int) ($result['users_updated'] ?? 0),
            'users_deactivated' => (int) ($result['users_deactivated'] ?? 0),
        ]);

        if (! (bool) ($result['ok'] ?? false)) {
            return redirect('/admin/users')->with('error', (string) ($result['message'] ?? '飞书同步失败'));
        }

        \App\Services\AdminOperationLogger::log($request, 'users.sync', '触发飞书通讯录同步', ['target_type' => 'system']);
        return redirect('/admin/users')->with('status', (string) ($result['message'] ?? '飞书同步完成'));
    }

    private function flattenDepartments(Collection $departments): array
    {
        $byParent = [];
        foreach ($departments as $department) {
            $parentId = (int) ($department->parent_id ?? 0);
            if (! isset($byParent[$parentId])) {
                $byParent[$parentId] = [];
            }
            $byParent[$parentId][] = $department;
        }

        foreach ($byParent as $parentId => $items) {
            usort($items, fn ($a, $b) => strcmp((string) $a->name, (string) $b->name));
            $byParent[$parentId] = $items;
        }

        $rows = [];
        $visited = [];

        $walker = function (int $parentId, int $depth) use (&$walker, &$rows, &$visited, $byParent): void {
            $children = $byParent[$parentId] ?? [];
            foreach ($children as $department) {
                if (isset($visited[$department->id])) {
                    continue;
                }
                $visited[$department->id] = true;

                $rows[] = [
                    'id' => $department->id,
                    'name' => (string) $department->name,
                    'depth' => $depth,
                    'feishu_department_id' => (string) ($department->feishu_department_id ?? ''),
                ];

                $walker((int) $department->id, $depth + 1);
            }
        };

        $walker(0, 0);

        if (count($rows) < $departments->count()) {
            foreach ($departments as $department) {
                if (isset($visited[$department->id])) {
                    continue;
                }
                $rows[] = [
                    'id' => $department->id,
                    'name' => (string) $department->name,
                    'depth' => 0,
                    'feishu_department_id' => (string) ($department->feishu_department_id ?? ''),
                ];
            }
        }

        return $rows;
    }

    private function decorateUserDisplay(User $user): void
    {
        $identity = $user->identities->first();
        $extra = is_array($identity?->extra) ? $identity->extra : [];
        $identityName = trim((string) ($extra['name'] ?? $extra['display_name'] ?? ''));
        $fallbackName = trim((string) $user->name);

        if ($identityName !== '') {
            $displayName = $identityName;
        } elseif ($fallbackName !== '' && ! str_starts_with($fallbackName, 'feishu_')) {
            $displayName = $fallbackName;
        } else {
            $displayName = '飞书用户'.$user->id;
        }

        $user->setAttribute('display_name', $displayName);
        $user->setAttribute('identity_extra', $extra);
    }

    private function resolveUserOpenId(User $user): string
    {
        $openId = trim((string) ($user->feishu_open_id ?? ''));
        if ($openId !== '') {
            return $openId;
        }

        $identity = $user->identities->first();
        if ($identity) {
            $providerUserId = trim((string) ($identity->provider_user_id ?? ''));
            if ($providerUserId !== '') {
                return $providerUserId;
            }

            $extra = is_array($identity->extra) ? $identity->extra : [];
            $extraOpenId = trim((string) ($extra['open_id'] ?? ''));
            if ($extraOpenId !== '') {
                return $extraOpenId;
            }
        }

        return '';
    }






    private function limitText(string $text, int $max): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') <= $max) {
                return $text;
            }

            return mb_substr($text, 0, $max - 1, 'UTF-8').'…';
        }

        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max - 3).'...';
    }

    private function fetchUserAvailableSkills(User $user)
    {
        return Skill::query()
            ->where('is_active', true)
            ->with(['assignments' => function ($query) use ($user): void {
                $query->where(function ($q) use ($user): void {
                    $q->where('user_id', $user->id);
                    if ($user->department_id) {
                        $q->orWhere('department_id', $user->department_id);
                    }
                });
            }])
            ->whereHas('assignments', function ($query) use ($user): void {
                $query->where(function ($q) use ($user): void {
                    $q->where('user_id', $user->id);
                    if ($user->department_id) {
                        $q->orWhere('department_id', $user->department_id);
                    }
                });
            })
            ->orderBy('name')
            ->get()
            ->map(function (Skill $skill) use ($user): Skill {
                $fromUser = $skill->assignments->contains(fn ($a) => (int) $a->user_id === (int) $user->id);
                $fromDept = $user->department_id
                    ? $skill->assignments->contains(fn ($a) => (int) $a->department_id === (int) $user->department_id)
                    : false;

                $skill->setAttribute('scope_user', $fromUser);
                $skill->setAttribute('scope_department', $fromDept);

                return $skill;
            })
            ->values();
    }
}
