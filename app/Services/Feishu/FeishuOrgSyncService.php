<?php

namespace App\Services\Feishu;

use App\Models\Department;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Organization / member sync domain service extracted from FeishuService.
 *
 * Walks the Feishu Open API department / user tree, then persists a snapshot
 * into the local departments / users / user_identities tables in a single
 * DB transaction. This is the largest single live cluster in the old god
 * class (~870 LOC).
 *
 * Behavior contract (preserved verbatim from FeishuService):
 * - Only runs when feishu config is enabled and a tenant token is obtainable.
 * - Detects department id_type (open_department_id / department_id) by format.
 * - Persists under a transaction; rolls back on failure.
 */
class FeishuOrgSyncService
{
    public function __construct(
        private readonly FeishuTransport $transport,
    ) {
    }

    public function syncOrganizationAndMembers(): array
    {
        $start = microtime(true);

        $config = $this->transport->readConfig();
        if (! $config['enabled']) {
            return [
                'ok' => false,
                'message' => '飞书配置缺失，请先在系统配置中填写 App ID 和 App Secret。',
            ];
        }

        $token = $this->transport->tenantToken($config['app_id'], $config['app_secret']);
        if (! $token) {
            return [
                'ok' => false,
                'message' => '获取飞书 tenant_access_token 失败，请检查飞书配置和网络连通性。',
            ];
        }

        try {
            $departmentIds = $this->fetchScopeDepartmentIds($token);
            $snapshot = $this->fetchDepartmentsSnapshot($token, $departmentIds);
            $stats = $this->persistOrganizationSnapshot($snapshot['departments'], $snapshot['users']);
        } catch (Throwable $e) {
            Log::warning('feishu.sync.failed', [
                'message' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => '飞书组织同步失败：'.$e->getMessage(),
            ];
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);

        return array_merge($stats, [
            'ok' => true,
            'message' => sprintf(
                '飞书同步完成：部门 %d 个（新增 %d，更新 %d），成员 %d 个（新增 %d，更新 %d，停用 %d）。',
                (int) $stats['departments_synced'],
                (int) $stats['departments_created'],
                (int) $stats['departments_updated'],
                (int) $stats['users_synced'],
                (int) $stats['users_created'],
                (int) $stats['users_updated'],
                (int) $stats['users_deactivated']
            ),
            'duration_ms' => $durationMs,
            'finished_at' => now()->toDateTimeString(),
        ]);
    }

    private function fetchScopeDepartmentIds(string $token): array
    {
        $body = [];
        try {
            $body = $this->transport->requestJson('get', 'contact/v3/scopes?user_id_type=open_id&department_id_type=open_department_id', [
                'headers' => $this->transport->authHeaders($token),
            ]);
            if ((int) Arr::get($body, 'code', -1) !== 0) {
                $body = $this->transport->requestJson('get', 'contact/v3/scopes', [
                    'headers' => $this->transport->authHeaders($token),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('feishu.sync.scopes_exception', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        if ((int) Arr::get($body, 'code', -1) !== 0) {
            Log::warning('feishu.sync.scopes_failed', [
                'response' => $body,
            ]);

            return [];
        }

        $data = (array) Arr::get($body, 'data', []);

        $candidates = array_merge(
            (array) Arr::get($body, 'data.authed_departments', []),
            (array) Arr::get($body, 'data.department_ids', []),
            (array) Arr::get($body, 'data.departments', [])
        );

        $ids = [];
        foreach ($candidates as $candidate) {
            if (is_string($candidate)) {
                $id = trim($candidate);
            } else {
                $id = trim((string) (
                    Arr::get($candidate, 'open_department_id')
                    ?: Arr::get($candidate, 'department_id')
                    ?: Arr::get($candidate, 'id')
                ));
            }

            if ($id === '') {
                continue;
            }

            $ids[$id] = true;
        }

        if (! empty($ids)) {
            return array_keys($ids);
        }

        return [];
    }

    private function fetchDepartmentIdsFromUsers(string $token, array $userCandidates): array
    {
        $departmentIds = [];
        $userIds = [];

        foreach ($userCandidates as $candidate) {
            if (is_string($candidate)) {
                $userId = trim($candidate);
            } else {
                $userId = trim((string) (
                    Arr::get($candidate, 'open_id')
                    ?: Arr::get($candidate, 'user_id')
                    ?: Arr::get($candidate, 'id')
                ));
            }

            if ($userId === '') {
                continue;
            }

            $userIds[$userId] = true;
        }

        foreach (array_keys($userIds) as $userId) {
            foreach (['open_id', 'user_id'] as $idType) {
                try {
                    $body = $this->transport->requestJson(
                        'get',
                        'contact/v3/users/'.rawurlencode($userId).'?user_id_type='.$idType.'&department_id_type=open_department_id',
                        ['headers' => $this->transport->authHeaders($token)]
                    );
                } catch (Throwable $e) {
                    continue;
                }

                if ((int) Arr::get($body, 'code', -1) !== 0) {
                    continue;
                }

                $deps = (array) Arr::get($body, 'data.user.department_ids', []);
                foreach ($deps as $dep) {
                    $depId = trim((string) $dep);
                    if ($depId !== '') {
                        $departmentIds[$depId] = true;
                    }
                }

                if (! empty($deps)) {
                    break;
                }
            }
        }

        return array_keys($departmentIds);
    }

    private function fetchDepartmentsSnapshot(string $token, array $rootDepartmentIds): array
    {
        $seeds = array_values(array_filter(array_map(static fn ($id) => trim((string) $id), $rootDepartmentIds)));

        $queue = [];
        foreach ($seeds as $seed) {
            $queue[] = [
                'department_id' => $seed,
                'id_type' => $this->detectDepartmentIdType($seed),
            ];
        }

        $visited = [];
        $departments = [];
        $users = [];
        $maxDepth = 1500;

        while (! empty($queue) && count($visited) < $maxDepth) {
            $node = array_shift($queue);
            $rawId = trim((string) ($node['department_id'] ?? ''));
            $idType = trim((string) ($node['id_type'] ?? 'open_department_id'));
            if ($rawId === '') {
                continue;
            }

            $visitKey = $idType.'::'.$rawId;
            if (isset($visited[$visitKey])) {
                continue;
            }
            $visited[$visitKey] = true;

            $detail = $this->fetchDepartmentDetailWithType($token, $rawId, $idType);
            if ($detail === null) {
                continue;
            }

            $departmentId = $this->extractDepartmentId($detail);
            if ($departmentId === '') {
                continue;
            }

            $parentDepartmentId = trim((string) (
                Arr::get($detail, 'parent_department_id')
                ?: Arr::get($detail, 'open_parent_department_id')
                ?: Arr::get($detail, 'parent_id')
            ));

            if ($parentDepartmentId !== '' && $parentDepartmentId === $departmentId) {
                $parentDepartmentId = '';
            }

            $departmentName = trim((string) Arr::get($detail, 'name', ''));
            if ($departmentName === '') {
                $departmentName = '未命名部门（'.substr($departmentId, -6).'）';
            }

            $departments[$departmentId] = [
                'feishu_department_id' => $departmentId,
                'name' => $departmentName,
                'parent_feishu_department_id' => $parentDepartmentId !== '' ? $parentDepartmentId : null,
            ];

            $children = $this->fetchDepartmentChildrenWithType($token, $rawId, $idType);
            foreach ($children as $child) {
                $childId = $this->extractDepartmentId($child);
                if ($childId === '') {
                    continue;
                }

                $childName = trim((string) Arr::get($child, 'name', ''));
                if ($childName === '') {
                    $childName = '未命名部门（'.substr($childId, -6).'）';
                }

                if (! isset($departments[$childId])) {
                    $departments[$childId] = [
                        'feishu_department_id' => $childId,
                        'name' => $childName,
                        'parent_feishu_department_id' => $departmentId,
                    ];
                } elseif (! $departments[$childId]['parent_feishu_department_id']) {
                    $departments[$childId]['parent_feishu_department_id'] = $departmentId;
                }

                $queue[] = [
                    'department_id' => $childId,
                    'id_type' => $this->detectDepartmentIdType($childId),
                ];
            }

            $departmentUsers = $this->fetchUsersByDepartment($token, $rawId, $idType);
            foreach ($departmentUsers as $item) {
                $openId = trim((string) (Arr::get($item, 'open_id') ?: Arr::get($item, 'user_id')));
                if ($openId === '') {
                    continue;
                }

                $departmentIds = $this->normalizeDepartmentIdList((array) Arr::get($item, 'department_ids', []));
                if (empty($departmentIds)) {
                    $departmentIds = [$departmentId];
                }

                $name = trim((string) Arr::get($item, 'name', ''));
                if ($name === '') {
                    $name = trim((string) Arr::get($item, 'en_name', Arr::get($item, 'nickname', '')));
                }

                $status = (array) Arr::get($item, 'status', []);
                $isActive = ! (bool) ($status['is_resigned'] ?? false);
                if (array_key_exists('is_activated', $status)) {
                    $isActive = $isActive && (bool) $status['is_activated'];
                }

                $users[$openId] = array_merge($users[$openId] ?? [], [
                    'open_id' => $openId,
                    'union_id' => trim((string) Arr::get($item, 'union_id', Arr::get($users[$openId] ?? [], 'union_id', ''))),
                    'user_id' => trim((string) Arr::get($item, 'user_id', Arr::get($users[$openId] ?? [], 'user_id', ''))),
                    'name' => $name !== '' ? $name : (string) Arr::get($users[$openId] ?? [], 'name', ''),
                    'email' => trim((string) Arr::get($item, 'email', Arr::get($users[$openId] ?? [], 'email', ''))),
                    'job_title' => trim((string) Arr::get($item, 'job_title', Arr::get($users[$openId] ?? [], 'job_title', ''))),
                    'mobile' => trim((string) Arr::get($item, 'mobile', Arr::get($users[$openId] ?? [], 'mobile', ''))),
                    'department_ids' => array_values(array_unique(array_merge(
                        (array) Arr::get($users[$openId] ?? [], 'department_ids', []),
                        $departmentIds
                    ))),
                    'is_active' => $isActive,
                    'avatar' => trim((string) Arr::get($item, 'avatar.avatar_240', Arr::get($users[$openId] ?? [], 'avatar', ''))),
                    'raw' => $item,
                ]);
            }
        }

        $scopeUsers = $this->fetchUsersFromScope($token);
        foreach ($scopeUsers as $item) {
            $openId = trim((string) ($item['open_id'] ?? ''));
            if ($openId === '') {
                continue;
            }
            if (! isset($users[$openId])) {
                $users[$openId] = $item;
                continue;
            }

            $users[$openId] = array_merge($users[$openId], $item, [
                'department_ids' => array_values(array_unique(array_merge(
                    (array) ($users[$openId]['department_ids'] ?? []),
                    (array) ($item['department_ids'] ?? [])
                ))),
            ]);
        }

        if (empty($departments) && ! empty($users)) {
            foreach ($users as $item) {
                foreach ($this->normalizeDepartmentIdList((array) ($item['department_ids'] ?? [])) as $departmentId) {
                    if (! isset($departments[$departmentId])) {
                        $departments[$departmentId] = [
                            'feishu_department_id' => $departmentId,
                            'name' => $departmentId === '0' ? '根部门' : ('部门 '.$departmentId),
                            'parent_feishu_department_id' => null,
                        ];
                    }
                }
            }
        }

        if (empty($departments) && empty($users)) {
            throw new \RuntimeException('未获取到飞书部门和成员数据，请确认应用已开通通讯录读取权限。');
        }

        return [
            'departments' => array_values($departments),
            'users' => array_values($users),
        ];
    }

    private function fetchDepartmentDetailWithType(string $token, string $departmentId, string $idType): ?array
    {
        try {
            $body = $this->transport->requestJson('get', 'contact/v3/departments/'.rawurlencode($departmentId).'?department_id_type='.$idType, [
                'headers' => $this->transport->authHeaders($token),
            ]);
        } catch (Throwable $e) {
            Log::warning('feishu.sync.department_detail_exception', [
                'department_id' => $departmentId,
                'id_type' => $idType,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if ((int) Arr::get($body, 'code', -1) !== 0) {
            return null;
        }

        $department = Arr::get($body, 'data.department');

        return is_array($department) ? $department : null;
    }

    private function fetchDepartmentChildrenWithType(string $token, string $departmentId, string $idType): array
    {
        $items = [];
        $pageToken = '';
        $guard = 0;

        do {
            $query = http_build_query([
                'department_id_type' => $idType,
                'page_size' => 50,
                'page_token' => $pageToken !== '' ? $pageToken : null,
            ]);

            try {
                $body = $this->transport->requestJson('get', 'contact/v3/departments/'.rawurlencode($departmentId).'/children?'.$query, [
                    'headers' => $this->transport->authHeaders($token),
                ]);
            } catch (Throwable $e) {
                Log::warning('feishu.sync.department_children_exception', [
                    'department_id' => $departmentId,
                    'id_type' => $idType,
                    'message' => $e->getMessage(),
                ]);
                break;
            }

            if ((int) Arr::get($body, 'code', -1) !== 0) {
                break;
            }

            $batch = Arr::get($body, 'data.items', []);
            if (is_array($batch)) {
                foreach ($batch as $row) {
                    if (is_array($row)) {
                        $items[] = $row;
                    }
                }
            }

            $pageToken = trim((string) Arr::get($body, 'data.page_token', ''));
            $hasMore = (bool) Arr::get($body, 'data.has_more', false);
            $guard++;
        } while ($hasMore && $pageToken !== '' && $guard < 200);

        return $items;
    }

    private function fetchUsersByDepartment(string $token, string $departmentId, string $idType): array
    {
        $items = [];
        $pageToken = '';
        $guard = 0;

        do {
            $query = http_build_query([
                'department_id' => $departmentId,
                'department_id_type' => $idType,
                'user_id_type' => 'open_id',
                'page_size' => 50,
                'page_token' => $pageToken !== '' ? $pageToken : null,
            ]);

            try {
                $body = $this->transport->requestJson('get', 'contact/v3/users/find_by_department?'.$query, [
                    'headers' => $this->transport->authHeaders($token),
                ]);
            } catch (Throwable $e) {
                Log::warning('feishu.sync.users_by_department_exception', [
                    'department_id' => $departmentId,
                    'id_type' => $idType,
                    'message' => $e->getMessage(),
                ]);
                break;
            }

            if ((int) Arr::get($body, 'code', -1) !== 0) {
                break;
            }

            $batch = Arr::get($body, 'data.items', []);
            if (is_array($batch)) {
                foreach ($batch as $row) {
                    if (is_array($row)) {
                        $items[] = $row;
                    }
                }
            }

            $pageToken = trim((string) Arr::get($body, 'data.page_token', ''));
            $hasMore = (bool) Arr::get($body, 'data.has_more', false);
            $guard++;
        } while ($hasMore && $pageToken !== '' && $guard < 200);

        return $items;
    }

    private function fetchUsersFromScope(string $token): array
    {
        $body = [];
        try {
            $body = $this->transport->requestJson('get', 'contact/v3/scopes?user_id_type=open_id&department_id_type=open_department_id', [
                'headers' => $this->transport->authHeaders($token),
            ]);
            if ((int) Arr::get($body, 'code', -1) !== 0) {
                $body = $this->transport->requestJson('get', 'contact/v3/scopes', [
                    'headers' => $this->transport->authHeaders($token),
                ]);
            }
        } catch (Throwable $e) {
            return [];
        }

        if ((int) Arr::get($body, 'code', -1) !== 0) {
            return [];
        }

        $candidates = array_merge(
            (array) Arr::get($body, 'data.user_ids', []),
            (array) Arr::get($body, 'data.authed_users', [])
        );

        $ids = [];
        foreach ($candidates as $candidate) {
            if (is_string($candidate)) {
                $id = trim($candidate);
            } else {
                $id = trim((string) (
                    Arr::get($candidate, 'open_id')
                    ?: Arr::get($candidate, 'user_id')
                    ?: Arr::get($candidate, 'id')
                ));
            }

            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        $users = [];
        foreach (array_keys($ids) as $id) {
            $profile = $this->fetchUserProfileByFlexibleId($token, $id);
            if ($profile === null) {
                continue;
            }

            $openId = trim((string) (Arr::get($profile, 'open_id') ?: Arr::get($profile, 'user_id')));
            if ($openId === '') {
                continue;
            }

            $name = trim((string) Arr::get($profile, 'name', Arr::get($profile, 'en_name', Arr::get($profile, 'nickname', ''))));
            $status = (array) Arr::get($profile, 'status', []);
            $isActive = ! (bool) ($status['is_resigned'] ?? false);
            if (array_key_exists('is_activated', $status)) {
                $isActive = $isActive && (bool) $status['is_activated'];
            }

            $users[$openId] = [
                'open_id' => $openId,
                'union_id' => trim((string) Arr::get($profile, 'union_id', '')),
                'user_id' => trim((string) Arr::get($profile, 'user_id', '')),
                'name' => $name,
                'email' => trim((string) Arr::get($profile, 'email', '')),
                'job_title' => trim((string) Arr::get($profile, 'job_title', '')),
                'mobile' => trim((string) Arr::get($profile, 'mobile', '')),
                'department_ids' => $this->normalizeDepartmentIdList((array) Arr::get($profile, 'department_ids', [])),
                'is_active' => $isActive,
                'avatar' => trim((string) Arr::get($profile, 'avatar.avatar_240', '')),
                'raw' => $profile,
            ];
        }

        return array_values($users);
    }

    private function fetchUserProfileByFlexibleId(string $token, string $rawUserId): ?array
    {
        $rawUserId = trim($rawUserId);
        if ($rawUserId === '') {
            return null;
        }

        foreach (['open_id', 'user_id'] as $idType) {
            try {
                $body = $this->transport->requestJson(
                    'get',
                    'contact/v3/users/'.rawurlencode($rawUserId).'?user_id_type='.$idType.'&department_id_type=open_department_id',
                    ['headers' => $this->transport->authHeaders($token)]
                );
            } catch (Throwable $e) {
                continue;
            }

            if ((int) Arr::get($body, 'code', -1) !== 0) {
                continue;
            }

            $user = Arr::get($body, 'data.user');
            if (is_array($user)) {
                return $user;
            }
        }

        return null;
    }

    private function detectDepartmentIdType(string $departmentId): string
    {
        $departmentId = trim($departmentId);
        if ($departmentId === '') {
            return 'open_department_id';
        }

        if (str_starts_with($departmentId, 'od-')) {
            return 'open_department_id';
        }

        if (preg_match('/^\d+$/', $departmentId) === 1) {
            return 'department_id';
        }

        return 'open_department_id';
    }

    private function extractDepartmentId(array $department): string
    {
        $id = trim((string) (
            Arr::get($department, 'open_department_id')
            ?: Arr::get($department, 'department_id')
            ?: Arr::get($department, 'id')
        ));

        return $id;
    }

    private function normalizeDepartmentIdList(array $departmentIds): array
    {
        $list = [];
        foreach ($departmentIds as $item) {
            $id = trim((string) $item);
            if ($id === '') {
                continue;
            }
            $list[$id] = true;
        }

        return array_keys($list);
    }

    private function persistOrganizationSnapshot(array $departments, array $users): array
    {
        return DB::transaction(function () use ($departments, $users): array {
            $departmentMap = [];
            $departmentsCreated = 0;
            $departmentsUpdated = 0;

            foreach ($departments as $item) {
                $feishuDepartmentId = trim((string) ($item['feishu_department_id'] ?? ''));
                if ($feishuDepartmentId === '') {
                    continue;
                }

                $name = trim((string) ($item['name'] ?? ''));
                if ($name === '') {
                    $name = '未命名部门（'.substr($feishuDepartmentId, -6).'）';
                }

                $department = Department::query()
                    ->where('feishu_department_id', $feishuDepartmentId)
                    ->first();

                if (! $department) {
                    $department = new Department();
                    $department->feishu_department_id = $feishuDepartmentId;
                    $departmentsCreated++;
                }

                if ((string) $department->name !== $name) {
                    $department->name = $name;
                    if ($department->exists) {
                        $departmentsUpdated++;
                    }
                }

                $department->save();
                $departmentMap[$feishuDepartmentId] = $department;
            }

            foreach ($departments as $item) {
                $feishuDepartmentId = trim((string) ($item['feishu_department_id'] ?? ''));
                if ($feishuDepartmentId === '' || ! isset($departmentMap[$feishuDepartmentId])) {
                    continue;
                }

                $parentFeishuDepartmentId = trim((string) ($item['parent_feishu_department_id'] ?? ''));
                $parentId = $parentFeishuDepartmentId !== '' && isset($departmentMap[$parentFeishuDepartmentId])
                    ? (int) $departmentMap[$parentFeishuDepartmentId]->id
                    : null;

                $department = $departmentMap[$feishuDepartmentId];
                if ((int) ($department->parent_id ?? 0) !== (int) ($parentId ?? 0)) {
                    $department->parent_id = $parentId;
                    $department->save();
                }
            }

            $usersCreated = 0;
            $usersUpdated = 0;
            $usersDeactivated = 0;
            $syncedOpenIds = [];

            foreach ($users as $item) {
                $openId = trim((string) ($item['open_id'] ?? ''));
                if ($openId === '') {
                    continue;
                }

                $syncedOpenIds[$openId] = true;

                $departmentIds = $this->normalizeDepartmentIdList((array) ($item['department_ids'] ?? []));
                $localDepartmentId = null;
                foreach ($departmentIds as $departmentId) {
                    if (isset($departmentMap[$departmentId])) {
                        $localDepartmentId = (int) $departmentMap[$departmentId]->id;
                        break;
                    }
                }

                $name = trim((string) ($item['name'] ?? ''));
                if ($name === '') {
                    $name = '椋炰功鐢ㄦ埛'.substr($openId, -6);
                }

                $unionId = trim((string) ($item['union_id'] ?? ''));
                $email = $this->ensureUniqueEmail(
                    trim((string) ($item['email'] ?? '')),
                    $openId,
                    null
                );

                $identity = UserIdentity::query()
                    ->where('provider', 'feishu')
                    ->where('provider_user_id', $openId)
                    ->first();

                $user = null;
                if ($identity) {
                    $user = $identity->user;
                }
                if (! $user) {
                    $user = User::query()->where('feishu_open_id', $openId)->first();
                }
                if (! $user && $unionId !== '') {
                    $user = User::query()->where('feishu_union_id', $unionId)->first();
                }

                $isNew = false;
                if (! $user) {
                    $user = new User();
                    $user->password = bcrypt(Str::random(40));
                    $isNew = true;
                }

                $email = $this->ensureUniqueEmail($email, $openId, $user->exists ? (int) $user->id : null);

                $isActive = (bool) ($item['is_active'] ?? true);

                $beforeActive = (bool) ($user->exists ? $user->is_active : true);
                $user->name = $name;
                $user->email = $email;
                $user->title = trim((string) ($item['job_title'] ?? '')) ?: null;
                $user->department_id = $localDepartmentId;
                $user->feishu_open_id = $openId;
                $user->feishu_union_id = $unionId !== '' ? $unionId : null;
                $user->is_active = $isActive;

                $isDirty = $isNew || $user->isDirty();
                $user->save();

                if ($isNew) {
                    $usersCreated++;
                } elseif ($isDirty) {
                    $usersUpdated++;
                }

                if ($beforeActive && ! $isActive) {
                    $usersDeactivated++;
                }

                $identityExtra = [
                    'name' => $name,
                    'display_name' => $name,
                    'open_id' => $openId,
                    'union_id' => $unionId,
                    'user_id' => trim((string) ($item['user_id'] ?? '')),
                    'job_title' => trim((string) ($item['job_title'] ?? '')),
                    'email' => trim((string) ($item['email'] ?? '')),
                    'mobile' => trim((string) ($item['mobile'] ?? '')),
                    'avatar' => trim((string) ($item['avatar'] ?? '')),
                ];

                UserIdentity::query()->updateOrCreate(
                    [
                        'provider' => 'feishu',
                        'provider_user_id' => $openId,
                    ],
                    [
                        'user_id' => $user->id,
                        'extra' => $identityExtra,
                    ]
                );
            }

            if (! empty($syncedOpenIds)) {
                $bulkDeactivated = User::query()
                    ->whereNotNull('feishu_open_id')
                    ->whereNotIn('feishu_open_id', array_keys($syncedOpenIds))
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
                $usersDeactivated += (int) $bulkDeactivated;
            }

            return [
                'departments_synced' => count($departmentMap),
                'departments_created' => $departmentsCreated,
                'departments_updated' => $departmentsUpdated,
                'users_synced' => count($syncedOpenIds),
                'users_created' => $usersCreated,
                'users_updated' => $usersUpdated,
                'users_deactivated' => $usersDeactivated,
            ];
        });
    }

    private function ensureUniqueEmail(string $email, string $openId, ?int $exceptUserId): string
    {
        $candidate = strtolower(trim($email));
        if ($candidate === '' || ! filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            $candidate = 'feishu_'.substr(md5($openId), 0, 12).'@mifrog.local';
        }

        [$local, $domain] = array_pad(explode('@', $candidate, 2), 2, 'mifrog.local');
        $baseLocal = preg_replace('/[^a-z0-9._+-]/', '', strtolower($local));
        if (! is_string($baseLocal) || $baseLocal === '') {
            $baseLocal = 'feishu_'.substr(md5($openId), 0, 12);
        }
        $domain = trim($domain) !== '' ? strtolower($domain) : 'mifrog.local';

        $result = $baseLocal.'@'.$domain;
        $index = 0;

        while (true) {
            $exists = User::query()
                ->where('email', $result)
                ->when($exceptUserId !== null, fn ($query) => $query->where('id', '!=', $exceptUserId))
                ->exists();

            if (! $exists) {
                return $result;
            }

            $index++;
            $suffix = '.'.$index;
            $maxBaseLength = max(1, 64 - strlen($suffix));
            $result = substr($baseLocal, 0, $maxBaseLength).$suffix.'@'.$domain;
        }
    }

}
