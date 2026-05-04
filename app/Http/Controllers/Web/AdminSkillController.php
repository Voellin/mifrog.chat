<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\RunEvent;
use App\Models\Skill;
use App\Models\User;
use App\Services\SkillStorageService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use DomainException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class AdminSkillController extends Controller
{
    private SkillStorageService $skillStorageService;

    public function __construct(
        SkillStorageService $skillStorageService
    )
    {
        $this->skillStorageService = $skillStorageService;
    }

    public function index(Request $request): View
    {
        $this->ensureBuiltinSkillTemplates();

        $status = strtolower(trim((string) $request->query('status', 'all')));
        if (! in_array($status, ['all', 'active', 'inactive'], true)) {
            $status = 'all';
        }

        $assigned = strtolower(trim((string) $request->query('assigned', 'all')));
        if (! in_array($assigned, ['all', 'assigned', 'unassigned'], true)) {
            $assigned = 'all';
        }

        $invoked = strtolower(trim((string) $request->query('invoked', 'all')));
        if (! in_array($invoked, ['all', 'recent_7d', 'recent_30d', 'never'], true)) {
            $invoked = 'all';
        }

        $recent30Start = now()->subDays(30)->toDateTimeString();

        $invocationSub = RunEvent::query()
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.skill_key')) as skill_key")
            ->selectRaw('MAX(created_at) as last_invoked_at')
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as invoke_count_30', [$recent30Start])
            ->where('event_type', 'tool_log')
            ->whereRaw("JSON_EXTRACT(payload, '$.skill_key') IS NOT NULL")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.skill_key')) <> ''")
            ->groupBy('skill_key');

        $skillsQuery = Skill::query()
            ->select('skills.*')
            ->selectRaw('(SELECT COUNT(DISTINCT sa.department_id) FROM skill_assignments sa WHERE sa.skill_id = skills.id AND sa.department_id IS NOT NULL) AS department_count')
            ->selectRaw('(SELECT COUNT(DISTINCT sa.user_id) FROM skill_assignments sa WHERE sa.skill_id = skills.id AND sa.user_id IS NOT NULL) AS user_count')
            ->where(function ($query): void {
                $this->excludePlatformSkills($query);
            })
            ->leftJoinSub($invocationSub, 'inv', function ($join): void {
                $join->on('inv.skill_key', '=', 'skills.skill_key');
            })
            ->addSelect(DB::raw('inv.last_invoked_at'))
            ->addSelect(DB::raw('COALESCE(inv.invoke_count_30, 0) as invoke_count_30'))
            ->latest('skills.id');

        if ($status === 'active') {
            $skillsQuery->where('skills.is_active', true);
        } elseif ($status === 'inactive') {
            $skillsQuery->where('skills.is_active', false);
        }

        if ($assigned === 'assigned') {
            $skillsQuery->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('skill_assignments as sa')
                    ->whereColumn('sa.skill_id', 'skills.id');
            });
        } elseif ($assigned === 'unassigned') {
            $skillsQuery->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('skill_assignments as sa')
                    ->whereColumn('sa.skill_id', 'skills.id');
            });
        }

        if ($invoked === 'recent_7d') {
            $skillsQuery->where('inv.last_invoked_at', '>=', now()->subDays(7)->toDateTimeString());
        } elseif ($invoked === 'recent_30d') {
            $skillsQuery->where('inv.last_invoked_at', '>=', now()->subDays(30)->toDateTimeString());
        } elseif ($invoked === 'never') {
            $skillsQuery->whereNull('inv.last_invoked_at');
        }

        $skills = $skillsQuery->get();

        return view('admin.skills', [
            'skills' => $skills,
            'filters' => [
                'status' => $status,
                'assigned' => $assigned,
                'invoked' => $invoked,
            ],
        ]);
    }

    public function show(Request $request, Skill $skill): View
    {
        abort_if($this->isPlatformSkill($skill), 404);

        $skill->load([
            'assignments.department:id,name',
            'assignments.user:id,name,feishu_open_id',
        ]);
        $frontMatter = $this->skillStorageService->syncSkillMetadataFromMarkdown($skill);
        $skill->setAttribute('skill_md', $this->skillStorageService->readSkillMarkdown($skill));

        $recentInvocations = $this->fetchRecentInvocations($skill);
        $departments = Department::query()
            ->withCount(['users' => function ($q): void {
                $q->where('is_active', true);
            }])
            ->orderBy('name')
            ->get(['id', 'name']);
        $users = $this->buildAssignableUsers();
        $customFiles = $this->skillStorageService->listSkillFiles($skill);
        $selectedFile = trim((string) $request->query('file', ''));
        $editingFile = null;
        $editingFileContent = '';
        $fileError = '';
        if ($selectedFile !== '') {
            try {
                $editingFileContent = $this->skillStorageService->readSkillFile($skill, $selectedFile);
                $editingFile = $selectedFile;
            } catch (Throwable $e) {
                $fileError = $e->getMessage();
            }
        }

        // Preload editable file contents for in-page modal editor (blade: FileEditModal).
        // Skipped for files >128KB to avoid bloating the HTML payload.
        $fileContents = [];
        foreach ($customFiles as $file) {
            if (empty($file['editable'])) {
                continue;
            }
            $size = (int) ($file['size'] ?? 0);
            if ($size > 128 * 1024) {
                continue;
            }
            try {
                $fileContents[(string) $file['path']] = $this->skillStorageService->readSkillFile($skill, (string) $file['path']);
            } catch (Throwable $e) {
                // Silent skip — UI will fall back to ?file= query param mechanism.
            }
        }

        return view('admin.skill_show', [
            'skill' => $skill,
            'recentInvocations' => $recentInvocations,
            'departments' => $departments,
            'users' => $users,
            'customFiles' => $customFiles,
            'fileContents' => $fileContents,
            'editingFile' => $editingFile,
            'editingFileContent' => $editingFileContent,
            'fileError' => $fileError,
            'frontMatter' => (array) ($frontMatter['front_matter'] ?? []),
            'stats' => $this->buildSkillStats($skill),
        ]);
    }

    /**
     * Compute 30-day stats for the skill detail page (invoke count, success rate, avg duration, 7-day spark).
     * Source of truth: run_events (tool_log with payload.skill_key) joined to runs for status/duration.
     *
     * @return array{
     *   invoke_30d:int,
     *   success_rate:float|null,
     *   avg_duration_s:float|null,
     *   runs_total:int,
     *   runs_success:int,
     *   spark_7d:array<int,int>,
     *   spark_labels:array<int,string>
     * }
     */
    private function buildSkillStats(Skill $skill): array
    {
        $key = (string) $skill->skill_key;
        $now = now();
        $start30 = $now->copy()->subDays(30)->toDateTimeString();

        // 30-day invocation count - how many tool_log events mention this skill
        $invoke30d = (int) RunEvent::query()
            ->where('event_type', 'tool_log')
            ->where(function ($q) use ($key) {
                $q->where('payload->skill_key', $key)
                    ->orWhere('message', 'like', '已匹配 Skill /'.$key.'%');
            })
            ->where('created_at', '>=', $start30)
            ->count();

        // Success rate + avg duration - over distinct runs tied to this skill in last 30d
        $runIdsSub = RunEvent::query()
            ->select('run_id')
            ->where('event_type', 'tool_log')
            ->where(function ($q) use ($key) {
                $q->where('payload->skill_key', $key)
                    ->orWhere('message', 'like', '已匹配 Skill /'.$key.'%');
            })
            ->where('created_at', '>=', $start30)
            ->whereNotNull('run_id')
            ->distinct();

        $runAgg = DB::table('runs')
            ->whereIn('id', $runIdsSub)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success")
            ->selectRaw('AVG(CASE WHEN started_at IS NOT NULL AND finished_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, started_at, finished_at) ELSE NULL END) as avg_sec')
            ->first();

        $runsTotal = (int) ($runAgg->total ?? 0);
        $runsSuccess = (int) ($runAgg->success ?? 0);
        $avgSec = $runAgg->avg_sec ?? null;
        $avgDuration = $avgSec === null ? null : (float) $avgSec;
        $successRate = $runsTotal > 0 ? ($runsSuccess / $runsTotal) : null;

        // 7-day spark (last 7 days including today) - per-day invocation count
        $start7 = $now->copy()->subDays(6)->startOfDay();
        $perDay = RunEvent::query()
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->where('event_type', 'tool_log')
            ->where(function ($q) use ($key) {
                $q->where('payload->skill_key', $key)
                    ->orWhere('message', 'like', '已匹配 Skill /'.$key.'%');
            })
            ->where('created_at', '>=', $start7->toDateTimeString())
            ->groupBy('d')
            ->pluck('c', 'd')
            ->all();

        $spark = [];
        $labels = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $start7->copy()->addDays($i)->format('Y-m-d');
            $spark[] = (int) ($perDay[$day] ?? 0);
            $labels[] = $day;
        }

        return [
            'invoke_30d' => $invoke30d,
            'success_rate' => $successRate,
            'avg_duration_s' => $avgDuration,
            'runs_total' => $runsTotal,
            'runs_success' => $runsSuccess,
            'spark_7d' => $spark,
            'spark_labels' => $labels,
        ];
    }

    public function create(): View
    {
        $this->ensureBuiltinSkillTemplates();

        return view('admin.skill_create', [
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'users' => $this->buildAssignableUsers(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $skillType = strtolower(trim((string) $request->input('skill_type', 'prompt')));
        if (! in_array($skillType, ['prompt', 'sandbox', 'http_api'], true)) {
            $skillType = 'prompt';
        }

        $rules = [
            'name' => 'required|string|max:255',
            'skill_key' => 'required|string|alpha_dash|max:120|unique:skills,skill_key',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'department_ids' => 'nullable|array',
            'department_ids.*' => 'integer|exists:departments,id',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer|exists:users,id',
            'skill_type' => 'nullable|string|in:prompt,sandbox,http_api',
        ];

        if ($skillType === 'http_api') {
            $rules = array_merge($rules, [
                'api_url' => 'required|string|max:2048',
                'api_method' => 'nullable|string|in:GET,POST,PUT,PATCH,DELETE,HEAD',
                'api_token' => 'nullable|string|max:4096',
                'api_timeout' => 'nullable|integer|min:1|max:60',
                'api_headers' => 'nullable|string',
                'api_body_template' => 'nullable|string',
                'api_params' => 'nullable|array',
                'api_params.*.name' => 'nullable|string|max:80',
                'api_params.*.api_key' => 'nullable|string|max:80',
                'api_params.*.description' => 'nullable|string|max:500',
                'api_params.*.required' => 'nullable',
                'response_visible_fields' => 'nullable|string',
            ]);
        } else {
            $rules['skill_md'] = 'required|string';
        }

        $data = $request->validate($rules);

        $skillMd = $skillType === 'http_api'
            ? $this->buildHttpApiSkillMarkdown($data)
            : (string) $data['skill_md'];

        $skillDir = $this->skillStorageService->writeSkillMarkdownByKey(
            $data['skill_key'],
            $skillMd
        );

        $skill = Skill::query()->create([
            'name' => trim((string) $data['name']),
            'skill_key' => trim((string) $data['skill_key']),
            'storage_path' => $skillDir,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'meta' => [],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
        $this->skillStorageService->syncSkillMetadataFromMarkdown($skill);

        $this->skillStorageService->syncAssignments(
            $skill,
            $data['department_ids'] ?? [],
            $data['user_ids'] ?? []
        );

        \App\Services\AdminOperationLogger::log($request, 'skills.create', sprintf('创建新技能 #%d「%s」', $skill->id, (string) $skill->name), ['target_type' => 'skill', 'target_id' => $skill->id, 'skill_name' => (string) $skill->name]);
        return redirect('/admin/skills')->with('status', '技能已创建。');
    }

    /**
     * Assemble a skill.md with YAML front-matter from the structured http_api form payload.
     *
     * @param  array<string, mixed>  $data
     */
    private function buildHttpApiSkillMarkdown(array $data): string
    {
        $name = trim((string) ($data['name'] ?? ''));
        $desc = trim((string) ($data['description'] ?? ''));

        $headers = [];
        if (trim((string) ($data['api_headers'] ?? '')) !== '') {
            $decoded = json_decode((string) $data['api_headers'], true);
            if (is_array($decoded)) {
                $headers = $decoded;
            }
        }

        $params = [];
        foreach ((array) ($data['api_params'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $apiKey = trim((string) ($row['api_key'] ?? ''));
            if ($apiKey === '') {
                continue;
            }
            $params[] = [
                'name' => trim((string) ($row['name'] ?? $apiKey)),
                'api_key' => $apiKey,
                'description' => trim((string) ($row['description'] ?? '')),
                'required' => ! empty($row['required']),
            ];
        }

        $visibleFields = [];
        $rawFields = (string) ($data['response_visible_fields'] ?? '');
        if ($rawFields !== '') {
            foreach (preg_split('/[\r\n,]+/', $rawFields) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $visibleFields[] = $line;
                }
            }
        }

        $manifest = [
            'name' => $name,
            'description' => $desc,
            'executor' => 'http_api',
            'task_kinds' => ['internal_api_call'],
            'api_url' => trim((string) $data['api_url']),
            'api_method' => strtoupper(trim((string) ($data['api_method'] ?? 'POST'))),
            'api_timeout' => (int) ($data['api_timeout'] ?? 10),
        ];

        $token = trim((string) ($data['api_token'] ?? ''));
        if ($token !== '') {
            $manifest['api_token'] = $token;
        }
        if (! empty($headers)) {
            $manifest['api_headers'] = $headers;
        }
        if (trim((string) ($data['api_body_template'] ?? '')) !== '') {
            $manifest['api_body_template'] = (string) $data['api_body_template'];
        }
        if (! empty($params)) {
            $manifest['api_params'] = $params;
        }
        if (! empty($visibleFields)) {
            $manifest['response_visible_fields'] = $visibleFields;
        }

        $yaml = trim((string) Yaml::dump($manifest, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));

        $body = "# " . ($name !== '' ? $name : '内部 API 技能') . "\n\n"
              . ($desc !== '' ? $desc . "\n\n" : '')
              . "# 调用方式\n"
              . "此技能由管理员在后台配置 API URL / Token / 参数等。LLM 看到 <skills> catalog 后直接调用 execute_api_skill 即可。\n";

        return "---\n{$yaml}\n---\n\n{$body}";
    }

    public function update(Request $request, Skill $skill): RedirectResponse
    {
        abort_if($this->isPlatformSkill($skill), 404);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'skill_md' => 'required|string',
            'is_active' => 'nullable|boolean',
        ]);

        $skill->name = trim((string) $data['name']);
        $skill->description = trim((string) ($data['description'] ?? '')) ?: null;
        $skill->is_active = (bool) ($data['is_active'] ?? false);
        $skill->save();

        $this->skillStorageService->writeSkillMarkdown($skill, (string) $data['skill_md']);
        $this->skillStorageService->syncSkillMetadataFromMarkdown($skill);

        \App\Services\AdminOperationLogger::log($request, 'skills.update', sprintf('编辑技能 #%d「%s」', $skill->id, (string) $skill->name), ['target_type' => 'skill', 'target_id' => $skill->id]);
        return redirect('/admin/skills/'.$skill->id)->with('status', '技能已更新。');
    }

    public function updateAssignment(Request $request, Skill $skill): RedirectResponse
    {
        abort_if($this->isPlatformSkill($skill), 404);

        $data = $request->validate([
            'department_ids' => 'nullable|array',
            'department_ids.*' => 'integer|exists:departments,id',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer|exists:users,id',
        ]);

        $this->skillStorageService->syncAssignments(
            $skill,
            $data['department_ids'] ?? [],
            $data['user_ids'] ?? []
        );

        \App\Services\AdminOperationLogger::log($request, 'skills.assign', sprintf('修改技能 #%d「%s」的可见范围', $skill->id, (string) $skill->name), ['target_type' => 'skill', 'target_id' => $skill->id]);
        return redirect('/admin/skills/'.$skill->id)->with('status', '分配已更新。');
    }

    public function updateStatus(Request $request, Skill $skill)
    {
        abort_if($this->isPlatformSkill($skill), 404);

        $data = $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $skill->is_active = (bool) $data['is_active'];
        $skill->save();

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'is_active' => $skill->is_active,
                'message' => $skill->is_active ? '技能已启用。' : '技能已禁用。',
            ]);
        }

        // 非 JSON 路径：不塞 flash banner，避免页面顶部出现绿色提示条
        \App\Services\AdminOperationLogger::log($request, 'skills.status', sprintf('切换技能 #%d「%s」启用状态', $skill->id, (string) $skill->name), ['target_type' => 'skill', 'target_id' => $skill->id, 'is_active' => (bool) $skill->is_active]);
        return redirect('/admin/skills/'.$skill->id);
    }

    public function saveFile(Request $request, Skill $skill): RedirectResponse
    {
        abort_if($this->isPlatformSkill($skill), 404);

        $data = $request->validate([
            'file_path' => 'nullable|string|max:260',
            'file_content' => 'nullable|string',
            'upload_file' => 'nullable|file|max:10240',
            'upload_dir' => 'nullable|string|max:260',
        ]);

        $path = trim((string) ($data['file_path'] ?? ''));
        $content = (string) ($data['file_content'] ?? '');
        $upload = $request->file('upload_file');
        if ($upload !== null) {
            $dir = trim((string) ($data['upload_dir'] ?? ''), " /");
            $name = trim((string) $upload->getClientOriginalName());
            $path = $dir !== '' ? ($dir.'/'.$name) : $name;
            $raw = file_get_contents($upload->getRealPath());
            if ($raw === false) {
                return redirect('/admin/skills/'.$skill->id)
                    ->withErrors(['file_error' => '上传文件读取失败。']);
            }
            $content = (string) $raw;
        }

        if ($path === '') {
            return redirect('/admin/skills/'.$skill->id)
                ->withErrors(['file_error' => '文件路径不能为空。']);
        }

        try {
            $this->skillStorageService->writeSkillFile($skill, $path, $content);
            if (strtolower($path) === 'skill.md') {
                $this->skillStorageService->syncSkillMetadataFromMarkdown($skill);
            }
        } catch (DomainException $e) {
            return redirect('/admin/skills/'.$skill->id.'?file='.urlencode($path))
                ->withErrors(['file_error' => $e->getMessage()]);
        }

        \App\Services\AdminOperationLogger::log($request, 'skills.files.save', sprintf('编辑/新建技能 #%d 的附属文件', $skill->id), ['target_type' => 'skill', 'target_id' => $skill->id]);
        return redirect('/admin/skills/'.$skill->id.'?file='.urlencode($path))
            ->with('status', '文件已保存。');
    }

    public function deleteFile(Request $request, Skill $skill): RedirectResponse
    {
        abort_if($this->isPlatformSkill($skill), 404);

        $data = $request->validate([
            'file_path' => 'required|string|max:260',
        ]);
        $path = trim((string) $data['file_path']);

        try {
            $this->skillStorageService->deleteSkillFile($skill, $path);
        } catch (DomainException $e) {
            return redirect('/admin/skills/'.$skill->id)
                ->withErrors(['file_error' => $e->getMessage()]);
        }

        \App\Services\AdminOperationLogger::log($request, 'skills.files.delete', sprintf('删除技能 #%d 的附属文件', $skill->id), ['target_type' => 'skill', 'target_id' => $skill->id]);
        return redirect('/admin/skills/'.$skill->id)->with('status', '文件已删除。');
    }


    public function downloadFile(Request $request, Skill $skill): BinaryFileResponse|RedirectResponse
    {
        abort_if($this->isPlatformSkill($skill), 404);

        $data = $request->validate([
            'file_path' => 'required|string|max:260',
        ]);

        $path = trim((string) $data['file_path']);
        try {
            $file = $this->skillStorageService->resolveDownloadFile($skill, $path);
        } catch (DomainException $e) {
            return redirect('/admin/skills/'.$skill->id)
                ->withErrors(['file_error' => $e->getMessage()]);
        }

        return response()->download((string) $file['path'], (string) $file['filename']);
    }

    private function ensureBuiltinSkillTemplates(): void
    {
        foreach ($this->builtinSkillTemplates() as $template) {
            // 用 withTrashed：哪怕被软删了也算"存在"，不要再 INSERT 撞 unique；
            // 这是尊重管理员的删除意图（删了就别自动重建）。
            $existing = Skill::withTrashed()->where('skill_key', $template['skill_key'])->first();
            if ($existing) {
                continue;
            }

            $dir = $this->skillStorageService->writeSkillMarkdownByKey(
                $template['skill_key'],
                $this->buildSkillMarkdownWithFrontMatter($template)
            );

            Skill::query()->create([
                'name' => $template['name'],
                'skill_key' => $template['skill_key'],
                'storage_path' => $dir,
                'description' => $template['description'],
                'meta' => [
                    'builtin_template' => true,
                    'template_version' => 1,
                ],
                'is_active' => true,
            ]);
        }

    }

    private function buildAssignableUsers()
    {
        return User::query()
            ->with(['identities' => function ($query): void {
                $query->where('provider', 'feishu');
            }])
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'feishu_open_id', 'department_id'])
            ->map(function (User $user): User {
                $identity = $user->identities->first();
                $identityName = trim((string) (is_array($identity?->extra) ? ($identity->extra['name'] ?? '') : ''));
                $name = trim((string) $user->name);

                if ($identityName !== '') {
                    $user->setAttribute('display_name', $identityName);
                } elseif ($name === '' || str_starts_with($name, 'feishu_')) {
                    $user->setAttribute('display_name', '飞书用户'.$user->id);
                } else {
                    $user->setAttribute('display_name', $name);
                }

                return $user;
            });
    }

    /**
     * @param  array<string,mixed>  $template
     */
    private function buildSkillMarkdownWithFrontMatter(array $template): string
    {
        $skillMd = (string) ($template['skill_md'] ?? '');
        $normalized = str_replace("\r\n", "\n", $skillMd);
        if (preg_match('/\A---\n.*?\n---\n/s', $normalized) === 1) {
            return $skillMd;
        }

        $manifest = [
            'name' => (string) ($template['name'] ?? ($template['skill_key'] ?? '')),
            'description' => (string) ($template['description'] ?? ''),
            'required_capabilities' => ['tool.general_reasoning'],
            'task_kinds' => ['general_task'],
            'executor' => 'llm',
        ];
        $yaml = trim((string) Yaml::dump($manifest, 3, 2));

        return "---\n{$yaml}\n---\n\n".$skillMd;
    }

    private function fetchRecentInvocations(Skill $skill)
    {
        return RunEvent::query()
            ->with([
                'run' => function ($query): void {
                    $query->with(['user:id,name,feishu_open_id'])
                        ->select(['id', 'user_id', 'status', 'intent_type', 'created_at']);
                },
            ])
            ->where('event_type', 'tool_log')
            ->where(function ($query) use ($skill): void {
                $query->where('payload->skill_key', $skill->skill_key)
                    ->orWhere('message', 'like', '已匹配 Skill /'.$skill->skill_key.'%');
            })
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(function (RunEvent $event): RunEvent {
                $run = $event->run;
                $userName = trim((string) ($run?->user?->name ?? ''));
                if ($userName === '' && $run?->user_id) {
                    $userName = '用户#'.$run->user_id;
                }
                if ($userName === '') {
                    $userName = '-';
                }

                $event->setAttribute('user_display_name', $userName);
                $event->setAttribute('match_type', (string) (is_array($event->payload) ? ($event->payload['match_type'] ?? '-') : '-'));

                return $event;
            })
            ->values();
    }

    private function builtinSkillTemplates(): array
    {
        return [
            [
                'name' => '会议纪要助手',
                'skill_key' => 'meeting_minutes',
                'description' => '把会议内容整理为结构化纪要、行动项和负责人。',
                'skill_md' => implode("\n", [
                    '# 角色',
                    '你是企业会议纪要助手，负责提炼事实、结论与行动项。',
                    '',
                    '# 输入',
                    '- 会议原始记录（语音转写、聊天记录或手写提纲）',
                    '',
                    '# 输出',
                    '1. 会议主题与时间',
                    '2. 关键结论（最多 8 条）',
                    '3. 行动项（任务、负责人、截止日期、风险）',
                    '4. 待确认问题清单',
                ]),
            ],
            [
                'name' => '周报生成助手',
                'skill_key' => 'weekly_report',
                'description' => '把本周工作输入整理成可直接提交的周报。',
                'skill_md' => implode("\n", [
                    '# 角色',
                    '你是周报生成助手。',
                    '',
                    '# 输出结构',
                    '1. 本周完成',
                    '2. 下周计划',
                    '3. 风险与阻塞',
                    '4. 需要协同支持',
                ]),
            ],
            [
                'name' => '需求拆解助手',
                'skill_key' => 'task_breakdown',
                'description' => '把模糊需求拆成可执行任务清单和排期建议。',
                'skill_md' => implode("\n", [
                    '# 角色',
                    '你是需求拆解助手。',
                    '',
                    '# 输出',
                    '1. 目标澄清（范围内/范围外）',
                    '2. 任务拆解（工作包、优先级、依赖）',
                    '3. 建议里程碑（M1/M2/M3）',
                    '4. 风险与缓解方案',
                ]),
            ],
            [
                'name' => '数据分析问答助手',
                'skill_key' => 'data_analyst',
                'description' => '面向业务指标解释、趋势分析与归因建议。',
                'skill_md' => implode("\n", [
                    '# 角色',
                    '你是业务数据分析助手。',
                    '',
                    '# 输出',
                    '1. 核心结论',
                    '2. 关键指标变化',
                    '3. 可能原因（按证据强弱排序）',
                    '4. 下一步验证建议',
                ]),
            ],
            [
                'name' => '文档润色助手',
                'skill_key' => 'doc_polish',
                'description' => '对内部文档做结构与语言优化，保留原始事实。',
                'skill_md' => implode("\n", [
                    '# 角色',
                    '你是文档润色助手。',
                    '',
                    '# 输出',
                    '1. 优化后的正文',
                    '2. 主要修改点说明（可选）',
                ]),
            ],
            [
                'name' => '交互设计师',
                'skill_key' => 'interaction_designer',
                'description' => '将零散需求沉淀为可落地的高质量交互方案。',
                'skill_md' => implode("\n", [
                    '# 角色',
                    '你是企业级产品与交互设计师。',
                    '',
                    '# 输出结构',
                    '1. 需求摘要',
                    '2. 业务目标与成功指标',
                    '3. 信息架构与页面层级',
                    '4. 关键流程（主流程+异常流程）',
                    '5. 页面交互细则（状态、校验、反馈）',
                    '6. 与研发对接清单（字段、接口、埋点）',
                ]),
            ],
        ];
    }

    private function excludePlatformSkills($query): void
    {
        $query->where(function ($sub): void {
            $sub->whereNull('meta->platform_managed')
                ->orWhere('meta->platform_managed', false);
        })->where(function ($sub): void {
            $sub->whereNull('meta->integration_skill')
                ->orWhere('meta->integration_skill', false);
        });
    }

    private function isPlatformSkill(Skill $skill): bool
    {
        $meta = is_array($skill->meta) ? $skill->meta : [];

        return (bool) ($meta['platform_managed'] ?? false) || (bool) ($meta['integration_skill'] ?? false);
    }

    /**
     * 软删技能：标记 deleted_at（DB 行保留 + 文件目录保留 + assignment 保留），
     * 列表/分配/调用层因 SoftDeletes 默认 scope 自动忽略。
     * 必须前端二次确认（输入技能名匹配）才提交。
     */
    public function destroy(Request $request, Skill $skill): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'confirm_name' => ['required', 'string'],
        ]);

        if (trim((string) $data['confirm_name']) !== trim((string) $skill->name)) {
            return back()->withErrors([
                'confirm_name' => '输入的技能名称与待删除技能不一致，已取消。',
            ]);
        }

        $skillId = (int) $skill->id;
        $skillName = (string) $skill->name;
        $skillKey = (string) $skill->skill_key;

        $skill->delete();  // SoftDeletes：仅置 deleted_at

        // 写操作日志（高危操作必记）
        \App\Services\AdminOperationLogger::log(
            $request,
            'skills.destroy',
            sprintf('删除技能 #%d「%s」(skill_key=%s)', $skillId, $skillName, $skillKey),
            [
                'target_type' => 'skill',
                'target_id' => $skillId,
                'skill_name' => $skillName,
                'skill_key' => $skillKey,
            ]
        );

        return redirect('/admin/skills')->with('status', sprintf('技能「%s」已软删除（DB 行保留、文件保留，可由 DBA 恢复）。', $skillName));
    }

}
