<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Skill;
use App\Models\User;
use App\Services\SkillStorageService;
use Illuminate\Http\Request;

class SkillController extends Controller
{
    private SkillStorageService $skillStorageService;

    public function __construct(SkillStorageService $skillStorageService)
    {
        $this->skillStorageService = $skillStorageService;
    }

    public function index()
    {
        $skills = Skill::query()
            ->with('assignments')
            ->where(function ($query): void {
                $query->whereNull('meta->platform_managed')
                    ->orWhere('meta->platform_managed', false);
            })
            ->where(function ($query): void {
                $query->whereNull('meta->integration_skill')
                    ->orWhere('meta->integration_skill', false);
            })
            ->latest('id')
            ->get()
            ->map(function (Skill $skill): Skill {
                $skill->setAttribute('skill_md', $this->skillStorageService->readSkillMarkdown($skill));

                return $skill;
            });

        return response()->json([
            'skills' => $skills,
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'feishu_open_id', 'department_id']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'skill_key' => 'required|string|alpha_dash|max:120|unique:skills,skill_key',
            'description' => 'nullable|string',
            'skill_md' => 'required|string',
            'is_active' => 'nullable|boolean',
            'department_ids' => 'nullable|array',
            'department_ids.*' => 'integer|exists:departments,id',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer|exists:users,id',
        ]);

        $base = $this->skillStorageService->writeSkillMarkdownByKey($data['skill_key'], (string) $data['skill_md']);

        $skill = Skill::query()->create([
            'name' => $data['name'],
            'skill_key' => $data['skill_key'],
            'storage_path' => $base,
            'description' => $data['description'] ?? null,
            'meta' => [],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
        $this->skillStorageService->syncSkillMetadataFromMarkdown($skill);

        $this->skillStorageService->syncAssignments(
            $skill,
            $data['department_ids'] ?? [],
            $data['user_ids'] ?? []
        );

        $skill->load('assignments');
        $skill->setAttribute('skill_md', $this->skillStorageService->readSkillMarkdown($skill));

        return response()->json($skill, 201);
    }

    public function update(Request $request, Skill $skill)
    {
        abort_if($this->isPlatformSkill($skill), 404);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'skill_md' => 'required|string',
            'is_active' => 'nullable|boolean',
            'department_ids' => 'nullable|array',
            'department_ids.*' => 'integer|exists:departments,id',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer|exists:users,id',
        ]);

        $skill->update([
            'name' => trim((string) $data['name']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        $this->skillStorageService->writeSkillMarkdown($skill, (string) $data['skill_md']);
        $this->skillStorageService->syncSkillMetadataFromMarkdown($skill);
        $this->skillStorageService->syncAssignments(
            $skill,
            $data['department_ids'] ?? [],
            $data['user_ids'] ?? []
        );

        $skill->load('assignments');
        $skill->setAttribute('skill_md', $this->skillStorageService->readSkillMarkdown($skill));

        return response()->json($skill);
    }

    public function assign(Request $request, Skill $skill)
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

        return response()->json(
            $skill->load('assignments')->assignments
        );
    }

    private function isPlatformSkill(Skill $skill): bool
    {
        $meta = is_array($skill->meta) ? $skill->meta : [];

        return (bool) ($meta['platform_managed'] ?? false) || (bool) ($meta['integration_skill'] ?? false);
    }

}
