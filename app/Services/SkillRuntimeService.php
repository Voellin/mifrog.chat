<?php

namespace App\Services;

use App\Models\Run;
use App\Models\User;
use App\Models\Skill;
use App\Exceptions\Skill\SkillAuthException;
use App\Exceptions\Skill\SkillConfigException;
use App\Exceptions\Skill\SkillInputException;
use App\Exceptions\Skill\SkillNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class SkillRuntimeService
{
    private SkillStorageService $skillStorageService;
    private SkillSandboxService $skillSandboxService;
    private LlmGatewayService $llmGatewayService;
    private SkillApiExecutorService $skillApiExecutorService;

    public function __construct(
        SkillStorageService $skillStorageService,
        SkillSandboxService $skillSandboxService,
        LlmGatewayService $llmGatewayService,
        SkillApiExecutorService $skillApiExecutorService
    )
    {
        $this->skillStorageService = $skillStorageService;
        $this->skillSandboxService = $skillSandboxService;
        $this->llmGatewayService = $llmGatewayService;
        $this->skillApiExecutorService = $skillApiExecutorService;
    }

    public function applySkillContext(Run $run, array $messages): array
    {
        return $this->buildTaskContext($run, $messages);
    }

    public function buildTaskContext(Run $run, array $messages): array
    {
        $latestUserIndex = $this->latestUserMessageIndex($messages);
        if ($latestUserIndex < 0) {
            return $this->emptyResult($messages);
        }

        $latestUserText = trim((string) ($messages[$latestUserIndex]['content'] ?? ''));
        $allowedSkills = $this->skillStorageService->allowedSkillsForUser($run->user);
        $invocation = $this->parseInvocation($latestUserText);

        if ($invocation !== null) {
            return $this->applyExplicitSkill($run, $messages, $latestUserIndex, $invocation, $allowedSkills);
        }

        if ($allowedSkills->isEmpty()) {
            return $this->emptyResult($messages);
        }

        $matched = $this->llmMatchSkill($allowedSkills, $latestUserText);
        if ($matched === null) {
            return $this->emptyResult($messages);
        }

        return $this->applySkill(
            messages: $messages,
            latestUserIndex: $latestUserIndex,
            skill: $matched,
            promptBody: null,
            matchType: 'auto',
            matchScore: 1.0,
            matchReason: 'llm_match'
        );
    }

    private function applyExplicitSkill(
        Run $run,
        array $messages,
        int $latestUserIndex,
        array $invocation,
        Collection $allowedSkills
    ): array {
        $requestedKey = $invocation['skill_key'];
        $promptBody = $invocation['prompt_body'];

        $skill = $allowedSkills->firstWhere('skill_key', $requestedKey);
        if ($skill === null) {
            $allSkill = Skill::query()->where('skill_key', $requestedKey)->first();
            if ($allSkill && $allSkill->is_active) {
                $keys = $allowedSkills->pluck('skill_key')->filter()->values()->all();
                $allowedText = empty($keys) ? '当前账号未分配任何 Skill。' : '可用 Skill: /'.implode(', /', $keys);
                throw new SkillAuthException('你没有权限使用 /'.$requestedKey.'。'.$allowedText);
            }

            throw new SkillNotFoundException('Skill /'.$requestedKey.' 不存在或未启用，请联系管理员。');
        }

        return $this->applySkill(
            messages: $messages,
            latestUserIndex: $latestUserIndex,
            skill: $skill,
            promptBody: $promptBody,
            matchType: 'explicit',
            matchScore: 1.0,
            matchReason: 'explicit_skill_command'
        );
    }

    private function applySkill(
        array $messages,
        int $latestUserIndex,
        Skill $skill,
        ?string $promptBody,
        string $matchType,
        float $matchScore,
        string $matchReason
    ): array {
        $skillMd = trim($this->skillStorageService->readSkillMarkdown($skill));
        if ($skillMd === '') {
            throw new SkillNotFoundException('Skill /'.$skill->skill_key.' 缺少 skill.md，请联系管理员。');
        }

        if ($promptBody !== null) {
            $messages[$latestUserIndex]['content'] = $promptBody !== ''
                ? $promptBody
                : '请按该 Skill 的规范完成任务。';
        }

        array_unshift($messages, [
            'role' => 'system',
            'content' => $this->buildSkillSystemPrompt($skill, $skillMd, $matchType),
        ]);

        return [
            'messages' => $messages,
            'skill' => $skill,
            'guide_files' => [rtrim((string) $skill->storage_path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'skill.md'],
            'match_type' => $matchType,
            'match_score' => round($matchScore, 4),
            'match_reason' => $matchReason,
        ];
    }

    private function matchSkillByText(Collection $allowedSkills, string $text): ?array
    {
        $query = mb_strtolower(trim($text), 'UTF-8');
        if ($query === '') {
            return null;
        }

        $tokens = $this->tokens($query);
        if (empty($tokens)) {
            return null;
        }

        $best = null;
        foreach ($allowedSkills as $skill) {
            $haystack = mb_strtolower(implode("\n", [
                (string) $skill->skill_key,
                (string) $skill->name,
                (string) ($skill->description ?? ''),
                $this->truncate($this->skillStorageService->readSkillMarkdown($skill), 1200),
            ]), 'UTF-8');

            $score = 0.0;
            $reasons = [];

            $skillKey = mb_strtolower((string) $skill->skill_key, 'UTF-8');
            if ($skillKey !== '' && mb_strpos($query, $skillKey) !== false) {
                $score += 0.75;
                $reasons[] = 'query_contains_skill_key';
            }

            $skillName = mb_strtolower((string) $skill->name, 'UTF-8');
            if ($skillName !== '' && mb_strpos($query, $skillName) !== false) {
                $score += 0.55;
                $reasons[] = 'query_contains_skill_name';
            }

            // Reverse match: check if fragments of skill name/description appear in user query.
            // This catches cases like user "服务器状态" matching skill name "服务器状态查询".
            if ($score < 0.55) {
                $reverseScore = $this->reverseFragmentMatch($query, $skillName, mb_strtolower((string) ($skill->description ?? ''), 'UTF-8'));
                if ($reverseScore > 0) {
                    $score += $reverseScore;
                    $reasons[] = 'reverse_fragment_match';
                }
            }

            $tokenHits = 0;
            foreach ($tokens as $token) {
                if (mb_strpos($haystack, $token) !== false) {
                    $tokenHits++;
                }
            }

            // Also check reverse: skill tokens appearing in user query
            $skillTokens = $this->tokens($haystack);
            $reverseHits = 0;
            foreach ($skillTokens as $sToken) {
                if (mb_strpos($query, $sToken) !== false) {
                    $reverseHits++;
                }
            }

            $allHits = $tokenHits + $reverseHits;
            if ($allHits > 0) {
                $score += min(0.8, $allHits * 0.10);
                $reasons[] = 'token_hits:'.$tokenHits.',reverse_hits:'.$reverseHits;
            }

            if ($score < 0.55) {
                continue;
            }

            if ($best === null || $score > $best['score']) {
                $best = [
                    'skill' => $skill,
                    'score' => $score,
                    'reason' => implode(',', $reasons),
                ];
            }
        }

        return $best;
    }

    private function parseInvocation(string $text): ?array
    {
        if (! preg_match('/^\s*\/([A-Za-z0-9_-]+)(?:\s+|$)([\s\S]*)$/u', $text, $matches)) {
            return null;
        }

        return [
            'skill_key' => trim((string) ($matches[1] ?? '')),
            'prompt_body' => trim((string) ($matches[2] ?? '')),
        ];
    }

    private function latestUserMessageIndex(array $messages): int
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if (($messages[$index]['role'] ?? null) === 'user') {
                return $index;
            }
        }

        return -1;
    }

    private function emptyResult(array $messages): array
    {
        return [
            'messages' => $messages,
            'skill' => null,
            'guide_files' => [],
            'match_type' => null,
            'match_score' => null,
            'match_reason' => null,
        ];
    }

    private function buildSkillSystemPrompt(Skill $skill, string $skillMd, string $matchType): string
    {
        $routeHint = $matchType === 'explicit'
            ? '用户通过 /skill_key 显式调用了该 Skill。'
            : '系统自动匹配到该 Skill，请在输出中保持任务边界清晰。';

        return implode("\n\n", [
            "你正在执行企业 Skill：{$skill->name} (/{$skill->skill_key})。",
            $routeHint,
            '严格遵守 skill.md 中的约束、流程和输出格式；如果超出范围，请明确说明并给出替代方案。',
            "<skill_md>\n{$skillMd}\n</skill_md>",
        ]);
    }

    /**
     * Check if meaningful fragments (3+ chars) from skill name/description appear in user query.
     * Returns a score boost (0 ~ 0.6).
     */
    private function reverseFragmentMatch(string $query, string $skillName, string $skillDesc): float
    {
        $score = 0.0;

        // Generate n-grams (3~6 chars) from skill name
        $fragments = $this->chineseNgrams($skillName, 3, 6);
        // Also from description (first 60 chars)
        $descShort = mb_substr($skillDesc, 0, 60, 'UTF-8');
        $fragments = array_merge($fragments, $this->chineseNgrams($descShort, 3, 6));
        $fragments = array_unique($fragments);

        $hits = 0;
        foreach ($fragments as $frag) {
            if (mb_strpos($query, $frag) !== false) {
                $hits++;
            }
        }

        if ($hits > 0) {
            $score = min(0.6, $hits * 0.15);
        }

        return $score;
    }

    /**
     * Generate character n-grams from a Chinese/mixed string.
     *
     * @return array<string>
     */
    private function chineseNgrams(string $text, int $minLen = 3, int $maxLen = 6): array
    {
        $text = trim($text);
        $len = mb_strlen($text, 'UTF-8');
        if ($len < $minLen) {
            return [];
        }

        $grams = [];
        for ($n = $minLen; $n <= min($maxLen, $len); $n++) {
            for ($i = 0; $i <= $len - $n; $i++) {
                $gram = mb_substr($text, $i, $n, 'UTF-8');
                $grams[] = $gram;
            }
        }

        return array_unique($grams);
    }

    private function tokens(string $text): array
    {
        $parts = preg_split('/[\s,，。！？；;:：、\/\\\\\(\)\[\]\{\}\|]+/u', $text) ?: [];

        return collect($parts)
            ->map(fn ($part) => trim((string) $part))
            ->filter(fn ($part) => $part !== '' && mb_strlen($part, 'UTF-8') >= 2)
            ->unique()
            ->take(14)
            ->values()
            ->all();
    }

    private function truncate(string $text, int $max): string
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

    /**
     * Quick check: does a matching Skill exist for this user + text?
     * Returns the Skill if found, null otherwise. Does NOT build full task context.
     */
    public function tryMatchSkill(User $user, string $text): ?Skill
    {
        // Check explicit /skill_key invocation first
        $invocation = $this->parseInvocation($text);
        if ($invocation !== null) {
            $allowed = $this->skillStorageService->allowedSkillsForUser($user);
            return $allowed->firstWhere('skill_key', $invocation['skill_key']);
        }

        $allowed = $this->skillStorageService->allowedSkillsForUser($user);
        if ($allowed->isEmpty()) {
            return null;
        }

        return $this->llmMatchSkill($allowed, $text);
    }

    /**
     * Build a user-facing guidance message when no Skill matches.
     */
    public function buildNoSkillGuidance(User $user, string $text): string
    {
        $allowed = $this->skillStorageService->allowedSkillsForUser($user);
        if ($allowed->isEmpty()) {
            return '当前没有可用的扩展技能来完成此任务。如需扩展能力，请联系管理员在后台配置并分配 Skill。';
        }

        $keys = $allowed->pluck('skill_key')->filter()->take(5)->map(fn ($k) => '/' . $k)->implode('、');
        return '当前没有匹配的技能来完成此任务。你可用的技能有：' . $keys . '。如需新能力，请联系管理员在后台新增 Skill。';
    }

    /**
     * Use LLM to determine the best matching Skill from the candidate set.
     *
     * Sends all candidate skills' name + description + task_kinds to the LLM,
     * asks it to pick the most relevant one or return "none".
     * This replaces the old token-based matchSkillByText approach.
     */
    private function llmMatchSkill(Collection $candidates, string $userText): ?Skill
    {
        if ($candidates->isEmpty() || trim($userText) === '') {
            return null;
        }

        // Build a concise skill catalog for the LLM
        $catalog = [];
        foreach ($candidates as $skill) {
            $meta = is_array($skill->meta) ? $skill->meta : [];
            $taskKinds = implode(', ', (array) ($meta['task_kinds'] ?? []));
            $executor = ($meta['executor'] ?? 'llm');
            $line = "/{$skill->skill_key} — {$skill->name}";
            if ($skill->description) {
                $line .= "：{$skill->description}";
            }
            if ($taskKinds !== '') {
                $line .= "（关键词：{$taskKinds}）";
            }
            if ($executor === 'sandbox') {
                $line .= " [沙箱执行]";
            }
            $catalog[] = $line;
        }

        $catalogText = implode("\n", $catalog);

        $messages = [
            ['role' => 'system', 'content' => implode("\n", [
                '你是一个技能路由器。给定用户的请求和一组可用技能，判断哪个技能最适合处理该请求。',
                '',
                '规则：',
                '1. 如果有明确匹配的技能，返回它的 skill_key（不带斜杠）',
                '2. 如果没有合适的技能，返回 none',
                '3. 只返回一个单词（skill_key 或 none），不要任何解释',
                '4. 匹配时关注语义相关性，而非字面重合',
                '',
                '可用技能：',
                $catalogText,
            ])],
            ['role' => 'user', 'content' => $userText],
        ];

        try {
            $llmResult = $this->llmGatewayService->chatWithCapability($messages, 'text');
            $answer = strtolower(trim((string) ($llmResult['content'] ?? '')));

            // Strip any leading slash or extra whitespace
            $answer = ltrim($answer, '/');
            $answer = preg_replace('/\s.*$/', '', $answer); // take first word only

            if ($answer === '' || $answer === 'none' || $answer === 'null') {
                return null;
            }

            // Find the skill by key
            $matched = $candidates->first(fn ($s) => strtolower($s->skill_key) === $answer);
            return $matched instanceof Skill ? $matched : null;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[SkillRuntime] LLM skill match failed, falling back to none', [
                'error' => $e->getMessage(),
                'user_text' => mb_substr($userText, 0, 100, 'UTF-8'),
            ]);
            return null;
        }
    }

    /**
     * Check if a Skill uses sandbox execution.
     */
    public function isSandboxSkill(Skill $skill): bool
    {
        $meta = is_array($skill->meta) ? $skill->meta : [];
        return strtolower(trim((string) ($meta['executor'] ?? ''))) === 'sandbox';
    }

    /**
     * Get sandbox configuration from skill meta/front-matter.
     *
     * @return array{interpreter: string, script_file: string, env: array, timeout: int}
     */
    public function getSandboxConfig(Skill $skill): array
    {
        $meta = is_array($skill->meta) ? $skill->meta : [];
        $interpreter = strtolower(trim((string) ($meta['sandbox_interpreter'] ?? 'bash')));
        // Whitelist: only bash/sh and python3 are allowed. PHP sandbox is intentionally not supported.
        if (! in_array($interpreter, ['bash', 'sh', 'python', 'python3'], true)) {
            throw new SkillConfigException(
                "Skill /{$skill->skill_key} 的 sandbox_interpreter='{$interpreter}' 不受支持，仅允许 bash/sh/python3。"
            );
        }
        return [
            'interpreter' => $interpreter,
            'script_file' => trim((string) ($meta['sandbox_script'] ?? 'entry')),
            'env' => is_array($meta['sandbox_env'] ?? null) ? $meta['sandbox_env'] : [],
            'timeout' => max(5, min(120, (int) ($meta['sandbox_timeout'] ?? 30))),
        ];
    }

    /**
     * Execute a sandbox Skill: read the script template, use LLM to fill params,
     * run in sandbox, return structured result.
     *
     * @return array{answer: string, exit_code: int, timed_out: bool, model: string, input_tokens: int, output_tokens: int}
     */
    public function executeSandboxSkill(
        Skill $skill,
        User $user,
        string $userText,
        string $runId = ''
    ): array {
        $config = $this->getSandboxConfig($skill);
        $scriptContent = $this->loadSkillScript($skill, $config['script_file'], $config['interpreter']);

        if ($scriptContent === '') {
            return [
                'answer' => 'Skill /' . $skill->skill_key . ' 的脚本文件缺失，请联系管理员检查。',
                'exit_code' => -1,
                'timed_out' => false,
                'model' => 'none',
                'input_tokens' => 0,
                'output_tokens' => 0,
            ];
        }

        // Check if script has placeholders ({{param_name}}) that need LLM extraction
        $hasPlaceholders = preg_match('/\{\{[A-Za-z_][A-Za-z0-9_]*\}\}/', $scriptContent) === 1;
        $model = 'none';
        $inputTokens = 0;
        $outputTokens = 0;

        if ($hasPlaceholders) {
            $prepared = $this->prepareSandboxScript($skill, $scriptContent, $userText);
            $scriptContent = $prepared['script'];
            $model = $prepared['model'];
            $inputTokens = $prepared['input_tokens'];
            $outputTokens = $prepared['output_tokens'];
        }

        // Execute in sandbox
        $sandboxResult = $this->skillSandboxService->execute(
            user: $user,
            script: $scriptContent,
            interpreter: $config['interpreter'],
            env: $config['env'],
            runId: $runId
        );

        // Format the answer
        $answer = $this->formatSandboxResult($skill, $sandboxResult);

        return [
            'answer' => $answer,
            'exit_code' => (int) $sandboxResult['exit_code'],
            'timed_out' => (bool) $sandboxResult['timed_out'],
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ];
    }

    /**
     * Load a script file from the skill's scripts/ directory.
     */
    private function loadSkillScript(Skill $skill, string $scriptFile, string $interpreter): string
    {
        $dir = rtrim((string) $skill->storage_path, DIRECTORY_SEPARATOR);
        if ($dir === '') {
            $dir = storage_path('app/skills/' . $skill->skill_key);
        }

        $ext = match ($interpreter) {
            'python', 'python3' => '.py',
            default => '.sh',
        };

        // Try scripts/{name}{ext} first, then scripts/{name}
        $candidates = [
            $dir . '/scripts/' . $scriptFile . $ext,
            $dir . '/scripts/' . $scriptFile,
            $dir . '/' . $scriptFile . $ext,
            $dir . '/' . $scriptFile,
        ];

        foreach ($candidates as $path) {
            if (File::exists($path) && !File::isDirectory($path)) {
                return (string) File::get($path);
            }
        }

        return '';
    }

    /**
     * Use LLM to extract parameters from user text and fill script placeholders.
     *
     * @return array{script: string, model: string, input_tokens: int, output_tokens: int}
     */
    private function prepareSandboxScript(Skill $skill, string $scriptTemplate, string $userText): array
    {
        // Extract placeholder names
        preg_match_all('/\{\{([A-Za-z_][A-Za-z0-9_]*)\}\}/', $scriptTemplate, $matches);
        $params = array_unique($matches[1]);

        if (empty($params)) {
            return ['script' => $scriptTemplate, 'model' => 'none', 'input_tokens' => 0, 'output_tokens' => 0];
        }

        $skillMd = $this->skillStorageService->readSkillMarkdown($skill);
        $paramList = implode(', ', $params);

        $messages = [
            ['role' => 'system', 'content' => implode("\n", [
                'You are a parameter extractor. Given a user request and a skill description, extract the values for the required parameters.',
                'Return ONLY a JSON object with the parameter names as keys and extracted values as string values.',
                'If a parameter cannot be determined, use an empty string.',
                'Do not include any explanation, only the JSON object.',
                '',
                'Skill: ' . $skill->name . ' (/' . $skill->skill_key . ')',
                'Skill description:',
                $this->truncate($skillMd, 800),
                '',
                'Required parameters: ' . $paramList,
            ])],
            ['role' => 'user', 'content' => $userText],
        ];

        $llmResult = $this->llmGatewayService->chatWithCapability($messages, 'text');
        $content = trim((string) ($llmResult['content'] ?? ''));

        // Parse JSON from LLM response
        $extracted = $this->parseJsonFromLlm($content);

        // Fill placeholders
        $script = $scriptTemplate;
        foreach ($params as $param) {
            $value = (string) ($extracted[$param] ?? '');
            // Escape for shell safety (single-quote wrap)
            $safeValue = str_replace("'", "'\\''", $value);
            $script = str_replace('{{' . $param . '}}', $safeValue, $script);
        }

        return [
            'script' => $script,
            'model' => (string) ($llmResult['model'] ?? 'unknown'),
            'input_tokens' => (int) ($llmResult['input_tokens'] ?? 0),
            'output_tokens' => (int) ($llmResult['output_tokens'] ?? 0),
        ];
    }

    /**
     * Parse JSON from LLM response, handling markdown code blocks.
     */
    private function parseJsonFromLlm(string $content): array
    {
        // Strip markdown code blocks if present
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $content, $m)) {
            $content = trim($m[1]);
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Format sandbox execution result into a user-facing answer.
     */
    private function formatSandboxResult(Skill $skill, array $sandboxResult): string
    {
        $exitCode = (int) $sandboxResult['exit_code'];
        $stdout = trim((string) $sandboxResult['stdout']);
        $stderr = trim((string) $sandboxResult['stderr']);
        $timedOut = (bool) $sandboxResult['timed_out'];

        if ($timedOut) {
            return "技能 /{$skill->skill_key} 执行超时（已超过最大允许时间）。\n\n如有部分输出：\n" . ($stdout !== '' ? $stdout : '（无输出）');
        }

        if ($exitCode !== 0) {
            $msg = "技能 /{$skill->skill_key} 执行完成（退出码：{$exitCode}）。";
            if ($stderr !== '') {
                $msg .= "\n\n错误信息：\n{$stderr}";
            }
            if ($stdout !== '') {
                $msg .= "\n\n输出：\n{$stdout}";
            }
            return $msg;
        }

        // Success
        if ($stdout === '') {
            return "技能 /{$skill->skill_key} 执行成功。（无输出）";
        }

        return $stdout;
    }


    /**
     * Build a concise catalog of skills available to this user.
     * Used by SkillsSection / legacy system prompt to tell the LLM what skills it can load & invoke.
     *
     * @return array<int, array{skill_key: string, name: string, description: string, task_kinds: array<int,string>, executor: string}>
     */
    public function buildSkillCatalog(User $user): array
    {
        $allowed = $this->skillStorageService->allowedSkillsForUser($user);
        if ($allowed->isEmpty()) {
            return [];
        }

        $catalog = [];
        foreach ($allowed as $skill) {
            $meta = is_array($skill->meta) ? $skill->meta : [];
            $taskKinds = array_values(array_filter(array_map(
                fn ($v) => trim((string) $v),
                (array) ($meta['task_kinds'] ?? [])
            ), fn ($v) => $v !== ''));

            $executor = strtolower(trim((string) ($meta['executor'] ?? 'llm')));
            if (! in_array($executor, ['llm', 'sandbox', 'http_api'], true)) {
                $executor = 'llm';
            }

            $entry = [
                'skill_key' => (string) $skill->skill_key,
                'name' => (string) $skill->name,
                'description' => trim((string) ($skill->description ?? '')),
                'task_kinds' => $taskKinds,
                'executor' => $executor,
            ];

            // For http_api skills, attach the parameter schema so the LLM can see
            // what to extract from the user request directly in the catalog — no
            // extra load_skill round-trip needed to call the API correctly.
            if ($executor === 'http_api') {
                $params = is_array($meta['api_params'] ?? null) ? $meta['api_params'] : [];
                $entry['api_params'] = array_values(array_filter(array_map(function ($p) {
                    if (! is_array($p)) {
                        return null;
                    }
                    $apiKey = trim((string) ($p['api_key'] ?? ''));
                    if ($apiKey === '') {
                        return null;
                    }
                    return [
                        'name' => trim((string) ($p['name'] ?? $apiKey)),
                        'api_key' => $apiKey,
                        'description' => trim((string) ($p['description'] ?? '')),
                        'required' => (bool) ($p['required'] ?? false),
                        'type' => (string) ($p['type'] ?? 'string'),
                    ];
                }, $params)));
            }

            $catalog[] = $entry;
        }

        return $catalog;
    }

    /**
     * Load the full skill.md body for a given skill_key, after checking the user has access.
     * Called by the `load_skill` tool — the LLM uses this to pull in a skill's instructions on demand.
     *
     * @throws SkillException when the skill is missing, inactive, or not assigned to the user.
     */
    public function loadSkillBody(User $user, string $skillKey): string
    {
        $skillKey = trim($skillKey);
        if ($skillKey === '') {
            throw new SkillInputException('load_skill 需要一个有效的 skill_key。');
        }

        $skill = $this->resolveAllowedSkill($user, $skillKey);
        $body = trim($this->skillStorageService->readSkillMarkdown($skill));
        if ($body === '') {
            throw new SkillNotFoundException("Skill /{$skill->skill_key} 缺少 skill.md，请联系管理员。");
        }

        return $body;
    }

    /**
     * Execute a sandbox skill by key, after checking the user has access.
     * Called by the `execute_sandbox_skill` tool. Wraps executeSandboxSkill().
     *
     * @return array{answer: string, exit_code: int, timed_out: bool, model: string, input_tokens: int, output_tokens: int}
     *
     * @throws SkillException when the skill is missing, inactive, not assigned, or not a sandbox skill.
     */
    public function executeSandboxByKey(User $user, string $skillKey, string $userText, string $runId = ''): array
    {
        $skill = $this->resolveAllowedSkill($user, trim($skillKey));
        if (! $this->isSandboxSkill($skill)) {
            throw new SkillConfigException("Skill /{$skill->skill_key} 不是沙箱类型，不能通过 execute_sandbox_skill 执行。");
        }

        return $this->executeSandboxSkill($skill, $user, $userText, $runId);
    }

    /**
     * Whether the skill is configured as an http_api executor.
     */
    public function isApiSkill(Skill $skill): bool
    {
        $meta = is_array($skill->meta) ? $skill->meta : [];
        return strtolower(trim((string) ($meta['executor'] ?? ''))) === 'http_api';
    }

    /**
     * Extract the http_api configuration bundle from a skill's meta.
     *
     * @return array{url:string, method:string, headers:array<string,string>, body_template:string, timeout:int, token:string, params:array<int,array<string,mixed>>, visible_fields:array<int,string>}
     */
    public function getApiConfig(Skill $skill): array
    {
        $meta = is_array($skill->meta) ? $skill->meta : [];
        return [
            'url' => (string) ($meta['api_url'] ?? ''),
            'method' => strtoupper(trim((string) ($meta['api_method'] ?? 'POST'))),
            'headers' => is_array($meta['api_headers'] ?? null) ? $meta['api_headers'] : [],
            'body_template' => (string) ($meta['api_body_template'] ?? ''),
            'timeout' => (int) ($meta['api_timeout'] ?? 10),
            'token' => (string) ($meta['api_token'] ?? ''),
            'params' => is_array($meta['api_params'] ?? null) ? $meta['api_params'] : [],
            'visible_fields' => is_array($meta['response_visible_fields'] ?? null) ? $meta['response_visible_fields'] : [],
        ];
    }

    /**
     * Execute an http_api skill by key, after checking the user has access.
     * Called by the `execute_api_skill` tool. The $request is a JSON string the LLM
     * produced — it maps param names (or api_keys) to values extracted from the user.
     *
     * @return array{answer:string, exit_code:int, timed_out:bool, http_status:int, model:string, input_tokens:int, output_tokens:int}
     *
     * @throws SkillException when the skill is missing, inactive, not assigned, or not an http_api skill.
     */
    public function executeApiByKey(User $user, string $skillKey, string $request, string $runId = ''): array
    {
        $skill = $this->resolveAllowedSkill($user, trim($skillKey));
        if (! $this->isApiSkill($skill)) {
            throw new SkillConfigException("Skill /{$skill->skill_key} 不是 http_api 类型，不能通过 execute_api_skill 执行。");
        }

        $apiConfig = $this->getApiConfig($skill);
        if (trim($apiConfig['url']) === '') {
            throw new SkillConfigException("Skill /{$skill->skill_key} 未配置 api_url，请联系管理员。");
        }

        $paramValues = $this->decodeRequestPayload($request);

        return $this->skillApiExecutorService->execute(
            $skill,
            $apiConfig,
            $paramValues,
            $user,
            $runId
        );
    }

    /**
     * Decode the `request` argument from the `execute_api_skill` tool call.
     *
     * If the LLM sent a JSON object → return it as an associative map.
     * Otherwise (plain string / malformed JSON) → wrap the raw value under
     * `__raw_request__` so template rendering can still inject it via {{request}}.
     *
     * @return array<string, mixed>
     */
    private function decodeRequestPayload(string $request): array
    {
        $trimmed = trim($request);
        if ($trimmed === '') {
            return [];
        }
        if ($trimmed[0] === '{') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return ['__raw_request__' => $trimmed];
    }

    /**
     * Resolve a skill_key against the user's allowed skill list.
     * Produces the same error messages as applyExplicitSkill so the UX stays consistent.
     *
     * @throws SkillException
     */
    private function resolveAllowedSkill(User $user, string $skillKey): Skill
    {
        if ($skillKey === '') {
            throw new SkillInputException('skill_key 不能为空。');
        }

        $allowed = $this->skillStorageService->allowedSkillsForUser($user);
        $skill = $allowed->firstWhere('skill_key', $skillKey);
        if ($skill instanceof Skill) {
            return $skill;
        }

        try {
            $fallback = Skill::query()->where('skill_key', $skillKey)->first();
        } catch (\Throwable) {
            $fallback = null;
        }
        if ($fallback && $fallback->is_active) {
            $keys = $allowed->pluck('skill_key')->filter()->values()->all();
            $allowedText = empty($keys) ? '当前账号未分配任何 Skill。' : '可用 Skill: /' . implode(', /', $keys);
            throw new SkillAuthException("你没有权限使用 /{$skillKey}。" . $allowedText);
        }

        throw new SkillNotFoundException("Skill /{$skillKey} 不存在或未启用，请联系管理员。");
    }

}
