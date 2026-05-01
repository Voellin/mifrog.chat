<?php

namespace App\Services;

use App\Models\Skill;
use App\Models\SkillAssignment;
use App\Models\User;
use App\Exceptions\Skill\SkillConfigException;
use App\Exceptions\Skill\SkillNotFoundException;
use App\Exceptions\Skill\SkillPathException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class SkillStorageService
{
    private const SKILLS_DIR = 'app/skills';
    private const MAX_EDITABLE_FILE_BYTES = 1024 * 1024;

    public function ensureSkillDirectory(string $skillKey): string
    {
        $dir = storage_path(self::SKILLS_DIR.'/'.$skillKey);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $scriptsDir = $dir.'/scripts';
        if (! File::isDirectory($scriptsDir)) {
            File::makeDirectory($scriptsDir, 0755, true);
        }

        return $dir;
    }

    public function writeSkillMarkdownByKey(string $skillKey, string $markdown): string
    {
        $dir = $this->ensureSkillDirectory($skillKey);
        File::put($dir.'/skill.md', $markdown);

        return $dir;
    }

    public function writeSkillMarkdown(Skill $skill, string $markdown): void
    {
        $dir = $this->ensureSkillDirectory($skill->skill_key);
        File::put($dir.'/skill.md', $markdown);

        if ($skill->storage_path !== $dir) {
            $skill->storage_path = $dir;
            $skill->save();
        }
    }

    public function readSkillMarkdown(Skill $skill): string
    {
        $path = rtrim((string) $skill->storage_path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'skill.md';
        if ($skill->storage_path === '' || ! File::exists($path)) {
            $path = $this->ensureSkillDirectory($skill->skill_key).'/skill.md';
            if (! File::exists($path)) {
                return '';
            }
        }

        return (string) File::get($path);
    }

    /**
     * Parse YAML front matter from skill.md and sync metadata to DB.
     *
     * @return array{front_matter: array<string,mixed>, body: string}
     */
    public function syncSkillMetadataFromMarkdown(Skill $skill): array
    {
        $markdown = $this->readSkillMarkdown($skill);
        [$frontMatter, $body] = $this->parseFrontMatter($markdown);
        if ($frontMatter === []) {
            return ['front_matter' => [], 'body' => $body];
        }

        $meta = is_array($skill->meta) ? $skill->meta : [];
        $dirty = false;

        $manifestName = trim((string) ($frontMatter['name'] ?? ''));
        if ($manifestName !== '' && $manifestName !== (string) $skill->name) {
            $skill->name = $manifestName;
            $dirty = true;
        }

        if (array_key_exists('description', $frontMatter)) {
            $manifestDesc = trim((string) ($frontMatter['description'] ?? ''));
            $dbDesc = trim((string) ($skill->description ?? ''));
            if ($manifestDesc !== $dbDesc) {
                $skill->description = $manifestDesc !== '' ? $manifestDesc : null;
                $dirty = true;
            }
        }

        if (array_key_exists('task_kinds', $frontMatter)) {
            $taskKinds = $this->normalizeCapabilityList($frontMatter['task_kinds'] ?? []);
            $meta['task_kinds'] = $taskKinds;
            $dirty = true;
        }

        if (array_key_exists('executor', $frontMatter)) {
            $meta['executor'] = strtolower(trim((string) ($frontMatter['executor'] ?? '')));
            $dirty = true;
        }

        // Sandbox configuration fields
        if (array_key_exists('sandbox_interpreter', $frontMatter)) {
            $meta['sandbox_interpreter'] = strtolower(trim((string) ($frontMatter['sandbox_interpreter'] ?? 'bash')));
            $dirty = true;
        }

        if (array_key_exists('sandbox_script', $frontMatter)) {
            $meta['sandbox_script'] = trim((string) ($frontMatter['sandbox_script'] ?? 'entry'));
            $dirty = true;
        }

        if (array_key_exists('sandbox_env', $frontMatter)) {
            $meta['sandbox_env'] = is_array($frontMatter['sandbox_env']) ? $frontMatter['sandbox_env'] : [];
            $dirty = true;
        }

        if (array_key_exists('sandbox_timeout', $frontMatter)) {
            $meta['sandbox_timeout'] = max(5, min(120, (int) ($frontMatter['sandbox_timeout'] ?? 30)));
            $dirty = true;
        }

        // http_api configuration fields
        if (array_key_exists('api_url', $frontMatter)) {
            $meta['api_url'] = trim((string) ($frontMatter['api_url'] ?? ''));
            $dirty = true;
        }

        if (array_key_exists('api_method', $frontMatter)) {
            $m = strtoupper(trim((string) ($frontMatter['api_method'] ?? 'POST')));
            $meta['api_method'] = in_array($m, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], true) ? $m : 'POST';
            $dirty = true;
        }

        if (array_key_exists('api_headers', $frontMatter)) {
            $meta['api_headers'] = is_array($frontMatter['api_headers']) ? $frontMatter['api_headers'] : [];
            $dirty = true;
        }

        if (array_key_exists('api_body_template', $frontMatter)) {
            $meta['api_body_template'] = (string) ($frontMatter['api_body_template'] ?? '');
            $dirty = true;
        }

        if (array_key_exists('api_timeout', $frontMatter)) {
            $defaultTimeout = (int) config('mifrog.skills.http_api.default_timeout', 10);
            $maxTimeout = (int) config('mifrog.skills.http_api.max_timeout', 60);
            $timeout = (int) ($frontMatter['api_timeout'] ?? $defaultTimeout);
            $meta['api_timeout'] = max(1, min($maxTimeout, $timeout));
            $dirty = true;
        }

        if (array_key_exists('api_token', $frontMatter)) {
            $meta['api_token'] = (string) ($frontMatter['api_token'] ?? '');
            $dirty = true;
        }

        if (array_key_exists('api_params', $frontMatter)) {
            $meta['api_params'] = $this->normalizeApiParams($frontMatter['api_params'] ?? []);
            $dirty = true;
        }

        if (array_key_exists('response_visible_fields', $frontMatter)) {
            $fields = $frontMatter['response_visible_fields'] ?? [];
            if (is_string($fields)) {
                $fields = preg_split('/[\s,]+/u', trim($fields), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
            $meta['response_visible_fields'] = array_values(array_filter(array_map(
                fn ($v) => trim((string) $v),
                is_array($fields) ? $fields : []
            ), fn ($v) => $v !== ''));
            $dirty = true;
        }

        if (array_key_exists('required_capabilities', $frontMatter)) {
            $requiredCapabilities = $this->normalizeCapabilityList($frontMatter['required_capabilities'] ?? []);
            if ($requiredCapabilities === []) {
                unset($meta['required_capabilities']);
                $meta['custom_required_capabilities'] = false;
            } else {
                $meta['required_capabilities'] = $requiredCapabilities;
                $meta['custom_required_capabilities'] = true;
            }
            $dirty = true;
        }

        if ($dirty) {
            // Prevent transient view-only attribute from being flushed to SQL update.
            if (array_key_exists('skill_md', $skill->getAttributes())) {
                unset($skill->skill_md);
            }
            $skill->meta = $meta;
            $skill->save();
        }

        return ['front_matter' => $frontMatter, 'body' => $body];
    }

    /**
     * @return array<int,array{path:string,size:int,updated_at:string,editable:bool}>
     */
    public function listSkillFiles(Skill $skill): array
    {
        $dir = $this->ensureSkillDirectory($skill->skill_key);
        $files = [];
        foreach (File::allFiles($dir) as $file) {
            $absolutePath = $file->getPathname();
            $relativePath = $this->toRelativePath($dir, $absolutePath);
            if ($relativePath === 'skill.md') {
                continue;
            }

            $size = (int) $file->getSize();
            $files[] = [
                'path' => $relativePath,
                'size' => $size,
                'updated_at' => date('Y-m-d H:i:s', (int) $file->getMTime()),
                'editable' => $this->isEditableTextFile($relativePath, $size),
            ];
        }

        usort($files, fn ($a, $b) => strcmp((string) $a['path'], (string) $b['path']));

        return $files;
    }

    public function readSkillFile(Skill $skill, string $relativePath): string
    {
        $absolutePath = $this->resolveSkillFilePath($skill, $relativePath, false);
        if (! File::exists($absolutePath) || File::isDirectory($absolutePath)) {
            throw new SkillNotFoundException('鏂囦欢涓嶅瓨鍦細'.$relativePath);
        }

        $size = (int) File::size($absolutePath);
        if (! $this->isEditableTextFile($relativePath, $size)) {
            throw new SkillConfigException('该文件类型或大小不支持在线编辑。');
        }

        return (string) File::get($absolutePath);
    }

    public function writeSkillFile(Skill $skill, string $relativePath, string $content): void
    {
        $absolutePath = $this->resolveSkillFilePath($skill, $relativePath, true);
        $dir = dirname($absolutePath);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::put($absolutePath, $content);
    }

    public function deleteSkillFile(Skill $skill, string $relativePath): void
    {
        $normalized = $this->normalizeRelativePath($relativePath);
        if ($normalized === 'skill.md') {
            throw new SkillPathException('skill.md 不能在此删除。');
        }

        $absolutePath = $this->resolveSkillFilePath($skill, $relativePath, false);
        if (File::isDirectory($absolutePath)) {
            File::deleteDirectory($absolutePath);

            return;
        }

        if (File::exists($absolutePath)) {
            File::delete($absolutePath);
        }
    }

    /**
     * @return array{path:string,filename:string}
     */
    public function resolveDownloadFile(Skill $skill, string $relativePath): array
    {
        $normalized = $this->normalizeRelativePath($relativePath);
        $absolutePath = $this->resolveSkillFilePath($skill, $normalized, false);
        if (! File::exists($absolutePath) || File::isDirectory($absolutePath)) {
            throw new SkillNotFoundException('文件不存在：'.$normalized);
        }

        return [
            'path' => $absolutePath,
            'filename' => basename($normalized),
        ];
    }

    public function syncAssignments(Skill $skill, array $departmentIds, array $userIds): void
    {
        $departmentIds = $this->sanitizeIdArray($departmentIds);
        $userIds = $this->sanitizeIdArray($userIds);

        DB::transaction(function () use ($skill, $departmentIds, $userIds): void {
            SkillAssignment::query()
                ->where('skill_id', $skill->id)
                ->delete();

            $rows = [];
            $now = now();

            foreach ($departmentIds as $departmentId) {
                $rows[] = [
                    'skill_id' => $skill->id,
                    'department_id' => $departmentId,
                    'user_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach ($userIds as $userId) {
                $rows[] = [
                    'skill_id' => $skill->id,
                    'department_id' => null,
                    'user_id' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (! empty($rows)) {
                SkillAssignment::query()->insert($rows);
            }
        });
    }

    public function allowedSkillsForUser(User $user): Collection
    {
        $skillIds = SkillAssignment::query()
            ->where(function ($query) use ($user): void {
                $query->where('user_id', $user->id);
                if ($user->department_id) {
                    $query->orWhere('department_id', $user->department_id);
                }
            })
            ->pluck('skill_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if (empty($skillIds)) {
            return collect();
        }

        return Skill::query()
            ->whereIn('id', $skillIds)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('meta->platform_managed')
                    ->orWhere('meta->platform_managed', false);
            })
            ->where(function ($query): void {
                $query->whereNull('meta->integration_skill')
                    ->orWhere('meta->integration_skill', false);
            })
            ->orderBy('name')
            ->get();
    }

    private function sanitizeIdArray(array $ids): array
    {
        return collect($ids)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{0: array<string,mixed>, 1: string}
     */
    private function parseFrontMatter(string $markdown): array
    {
        $markdown = str_replace("\r\n", "\n", $markdown);
        if (! preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $markdown, $matches)) {
            return [[], $markdown];
        }

        $yamlText = (string) ($matches[1] ?? '');
        $body = (string) ($matches[2] ?? '');

        try {
            $parsed = Yaml::parse($yamlText);
        } catch (Throwable) {
            return [[], $markdown];
        }

        if (! is_array($parsed)) {
            return [[], $markdown];
        }

        return [$parsed, $body];
    }

    /**
     * @param  mixed  $value
     * @return array<int,string>
     */
    private function normalizeCapabilityList(mixed $value): array
    {
        if (is_string($value)) {
            $parts = preg_split('/[\s,]+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (is_array($value)) {
            $parts = array_map(fn ($item) => (string) $item, $value);
        } else {
            $parts = [];
        }

        $set = [];
        foreach ($parts as $part) {
            $normalized = strtolower(trim((string) $part));
            if ($normalized !== '') {
                $set[$normalized] = true;
            }
        }

        return array_keys($set);
    }

    /**
     * Normalize http_api param list entries to a consistent shape:
     * [{name, api_key, description, required, type}].
     *
     * @param  mixed  $value
     * @return array<int,array<string,mixed>>
     */
    private function normalizeApiParams(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $apiKey = trim((string) ($entry['api_key'] ?? ''));
            if ($apiKey === '') {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? $apiKey));
            $desc = trim((string) ($entry['description'] ?? ''));
            $required = (bool) ($entry['required'] ?? false);
            $type = strtolower(trim((string) ($entry['type'] ?? 'string')));
            if (! in_array($type, ['string', 'integer', 'number', 'boolean'], true)) {
                $type = 'string';
            }
            $out[] = [
                'name' => $name,
                'api_key' => $apiKey,
                'description' => $desc,
                'required' => $required,
                'type' => $type,
            ];
        }
        return $out;
    }

    private function normalizeRelativePath(string $relativePath): string
    {
        $path = str_replace('\\', '/', trim($relativePath));
        $path = trim($path, '/');
        if ($path === '') {
            throw new SkillPathException('文件路径不能为空。');
        }

        if (str_contains($path, '../') || str_contains($path, '/..') || str_starts_with($path, '..')) {
            throw new SkillPathException('非法路径。');
        }

        if (! preg_match('/^[A-Za-z0-9._\\/-]+$/', $path)) {
            throw new SkillPathException('路径仅支持字母、数字、点、下划线、中划线和斜杠。');
        }

        return $path;
    }

    private function resolveSkillFilePath(Skill $skill, string $relativePath, bool $createDirectories): string
    {
        $baseDir = $this->ensureSkillDirectory($skill->skill_key);
        $normalized = $this->normalizeRelativePath($relativePath);
        $absolute = $baseDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        $resolvedBase = realpath($baseDir) ?: $baseDir;
        $targetDir = dirname($absolute);
        if ($createDirectories && ! File::isDirectory($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }
        $resolvedTargetDir = realpath($targetDir) ?: $targetDir;
        if (! str_starts_with($resolvedTargetDir, $resolvedBase)) {
            throw new SkillPathException('非法路径。');
        }

        return $absolute;
    }

    private function toRelativePath(string $baseDir, string $absolutePath): string
    {
        $base = rtrim(str_replace('\\', '/', $baseDir), '/');
        $path = str_replace('\\', '/', $absolutePath);
        $relative = ltrim(substr($path, strlen($base)), '/');

        return $relative;
    }

    private function isEditableTextFile(string $relativePath, int $size): bool
    {
        if ($size > self::MAX_EDITABLE_FILE_BYTES) {
            return false;
        }

        $path = strtolower($relativePath);
        $editableExts = [
            'md', 'txt', 'json', 'yaml', 'yml', 'py', 'php', 'js', 'ts', 'sh', 'sql', 'ini', 'conf',
            'html', 'css', 'xml', 'csv', 'toml', 'env',
        ];

        $ext = pathinfo($path, PATHINFO_EXTENSION);
        if ($ext === '') {
            return false;
        }

        return in_array($ext, $editableExts, true);
    }
}
