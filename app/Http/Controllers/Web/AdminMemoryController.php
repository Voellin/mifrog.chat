<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Services\MemoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Throwable;

class AdminMemoryController extends Controller
{
    private MemoryService $memoryService;

    public function __construct(MemoryService $memoryService)
    {
        $this->memoryService = $memoryService;
    }

    public function index(Request $request)
    {
        $selectedDepartmentId = (int) $request->query('department_id', 0);

        $departments = Department::query()
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id', 'feishu_department_id']);
        $departmentRows = $this->flattenDepartments($departments);

        // Picker 模式：拉全量启用用户。部门过滤由前端 hover 切换，保留 selectedDepartmentId
        // 仅作为 popover 默认高亮列（用户上次访问的部门视图）。
        $users = User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'feishu_open_id', 'department_id']);

        $selectedUserId = (int) ($request->query('user_id') ?: ($users->first()->id ?? 0));
        $selectedSession = trim((string) $request->query('session', '')) ?: null;
        $selectedL2Date = trim((string) $request->query('l2_date', '')) ?: null;

        $l1View = strtolower(trim((string) $request->query('l1_view', 'structured')));
        if (! in_array($l1View, ['structured', 'raw'], true)) {
            $l1View = 'structured';
        }
        $l1SessionPage = max(1, (int) $request->query('l1_session_page', 1));
        $l1Page = max(1, (int) $request->query('l1_page', 1));
        $l2FilePage = max(1, (int) $request->query('l2_file_page', 1));
        $l2Page = max(1, (int) $request->query('l2_page', 1));

        $memory = null;
        $pagination = null;
        if ($selectedUserId > 0) {
            $memory = $this->memoryService->getUserMemoryViewData($selectedUserId, $selectedSession, $selectedL2Date);

            // 切片：BOUNDARY 保护 Service 公开 API 签名不动，分页在 controller 层完成
            $sessions = (array) ($memory['sessions'] ?? []);
            $l2Files = (array) ($memory['l2_files'] ?? []);
            $events = (array) ($memory['selected_session_events'] ?? []);

            // L2 entries：从 recent_entries 按选中日期过滤；与 blade 既有逻辑保持一致
            $recentEntries = collect($memory['recent_entries'] ?? []);
            $l2Entries = $recentEntries
                ->filter(function ($entry) use ($selectedL2Date) {
                    if ((string) ($entry->layer ?? '') !== 'L2') {
                        return false;
                    }
                    if (! $selectedL2Date) {
                        return true;
                    }
                    return optional($entry->source_date ?? null)->format('Y-m-d') === $selectedL2Date;
                })
                ->values()
                ->all();
            if ($l2Entries === []) {
                $l2Entries = $recentEntries->where('layer', 'L2')->values()->all();
            }

            $pagination = [
                'l1_sessions' => $this->paginateArray($sessions, $l1SessionPage, 30),
                'l1_events' => $this->paginateArray($events, $l1Page, 20),
                'l2_files' => $this->paginateArray($l2Files, $l2FilePage, 30),
                'l2_entries' => $this->paginateArray($l2Entries, $l2Page, 20),
            ];
        }

        return view('admin.memory', [
            'users' => $users,
            'departmentRows' => $departmentRows,
            'selectedDepartmentId' => $selectedDepartmentId,
            'selectedUserId' => $selectedUserId,
            'memory' => $memory,
            'pagination' => $pagination,
            'l1View' => $l1View,
        ]);
    }

    /**
     * 简易数组分页：返回 items + 元信息（page / per_page / total / total_pages）。
     * page 越界自动 clamp 到 [1, total_pages]。
     *
     * @param  array<int, mixed>  $items
     * @return array{items: array<int, mixed>, page: int, per_page: int, total: int, total_pages: int}
     */
    private function paginateArray(array $items, int $page, int $perPage): array
    {
        $perPage = max(1, $perPage);
        $total = count($items);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($items, $offset, $perPage),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Flatten the department tree into ordered rows with depth info.
     * Mirrors AdminUserController::flattenDepartments so the memory page
     * shows the same hierarchy in its dropdown.
     *
     * @return array<int, array{id:int,name:string,depth:int,feishu_department_id:string}>
     */
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
                    'id' => (int) $department->id,
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
                    'id' => (int) $department->id,
                    'name' => (string) $department->name,
                    'depth' => 0,
                    'feishu_department_id' => (string) ($department->feishu_department_id ?? ''),
                ];
            }
        }

        return $rows;
    }

    public function repair(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $userId = (int) $data['user_id'];

        try {
            $result = $this->memoryService->repairUserMemory($userId);
        } catch (Throwable $e) {
            report($e);

            return redirect('/admin/memory?user_id='.$userId)
                ->with('error', '记忆修复失败，请查看日志后重试。');
        }

        $summary = sprintf(
            '记忆修复完成：保留 %d 条长期记忆，停用 %d 条污染项，恢复 %d 条，更新 %d 条，回想晋升 %d 条，过期 L2 清理 %d 条。',
            (int) ($result['fact_count'] ?? 0),
            (int) ($result['deactivated_count'] ?? 0),
            (int) ($result['reactivated_count'] ?? 0),
            (int) ($result['updated_count'] ?? 0),
            (int) ($result['promoted_count'] ?? 0),
            (int) (($result['cleanup']['expired_count'] ?? 0)),
        );

        \App\Services\AdminOperationLogger::log($request, 'memory.repair', sprintf('对用户 #%d 触发记忆重新整理（保留 %d、停用 %d、晋升 %d）', $userId, (int) ($result['fact_count'] ?? 0), (int) ($result['deactivated_count'] ?? 0), (int) ($result['promoted_count'] ?? 0)), ['target_type' => 'user', 'target_id' => $userId]);

        return redirect('/admin/memory?user_id='.$userId)
            ->with('status', $summary);
    }

    public function cleanup(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $userId = (int) $data['user_id'];

        try {
            $result = $this->memoryService->cleanupUserMemory($userId);
        } catch (Throwable $e) {
            report($e);

            return redirect('/admin/memory?user_id='.$userId)
                ->with('error', 'L2 过期清理失败，请查看日志后重试。');
        }

        $summary = sprintf(
            'L2 过期清理完成：检查 %d 条，标记过期 %d 条。',
            (int) ($result['checked_count'] ?? 0),
            (int) ($result['expired_count'] ?? 0),
        );

        \App\Services\AdminOperationLogger::log($request, 'memory.cleanup', sprintf('对用户 #%d 触发 L2 过期清理（检查 %d、过期 %d）', $userId, (int) ($result['checked_count'] ?? 0), (int) ($result['expired_count'] ?? 0)), ['target_type' => 'user', 'target_id' => $userId]);

        return redirect('/admin/memory?user_id='.$userId)
            ->with('status', $summary);
    }
}
