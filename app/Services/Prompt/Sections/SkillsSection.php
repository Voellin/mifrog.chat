<?php

namespace App\Services\Prompt\Sections;

/**
 * Renders the <skills> block — the per-user skill catalog and usage rules.
 *
 * Expects $context['skill_catalog'] to be pre-computed by the caller
 * (see SkillRuntimeService::buildSkillCatalog(User)). Pure data-in, string-out
 * so the Section stays testable without DB or auth state.
 *
 * Catalog entries are shaped as:
 *   - skill_key (string)
 *   - name (string)
 *   - description (string)
 *   - task_kinds (array<string>)
 *   - executor (string: llm|sandbox|http_api)
 *   - api_params (array, only for http_api): [{name, api_key, description, required, type}]
 */
class SkillsSection
{
    /**
     * @param  array{skill_catalog?: array<int, array<string, mixed>>}  $context
     */
    public function render(array $context = []): string
    {
        $catalog = $context['skill_catalog'] ?? [];
        if (! is_array($catalog) || empty($catalog)) {
            return '';
        }

        $lines = ['<skills>', 'Available skills — use them when they fit the user request better than the base tools:'];

        foreach ($catalog as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $skillKey = trim((string) ($entry['skill_key'] ?? ''));
            if ($skillKey === '') {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            $description = trim((string) ($entry['description'] ?? ''));
            $executor = strtolower(trim((string) ($entry['executor'] ?? 'llm')));
            $taskKinds = array_values(array_filter(array_map(
                fn ($v) => trim((string) $v),
                (array) ($entry['task_kinds'] ?? [])
            ), fn ($v) => $v !== ''));

            $tag = match ($executor) {
                'sandbox' => 'sandbox',
                'http_api' => 'api',
                default => 'instruction',
            };

            $line = '- /' . $skillKey;
            if ($name !== '') {
                $line .= ' — ' . $name;
            }
            $line .= ' [' . $tag . ']';
            if ($description !== '') {
                $line .= '：' . $description;
            }
            if (! empty($taskKinds)) {
                $line .= '（适用：' . implode(', ', $taskKinds) . '）';
            }
            $lines[] = $line;

            // For http_api skills, inline the parameter schema so the LLM
            // knows exactly what to extract from the user request without a
            // round-trip through load_skill.
            if ($executor === 'http_api') {
                $params = $entry['api_params'] ?? [];
                if (is_array($params) && ! empty($params)) {
                    foreach ($params as $param) {
                        if (! is_array($param)) {
                            continue;
                        }
                        $pName = trim((string) ($param['name'] ?? ''));
                        $pKey = trim((string) ($param['api_key'] ?? ''));
                        if ($pName === '' && $pKey === '') {
                            continue;
                        }
                        $required = (bool) ($param['required'] ?? false);
                        $marker = $required ? '必填' : '可选';
                        $pDesc = trim((string) ($param['description'] ?? ''));
                        $displayName = $pName !== '' ? $pName : $pKey;
                        $lines[] = '    · ' . $displayName . ' (' . $marker . ')' . ($pDesc !== '' ? '：' . $pDesc : '');
                    }
                }
            }
        }

        $lines[] = '';
        $lines[] = 'How to use skills:';
        $lines[] = '1. Identify the skill that best matches the user request by name / description / task keywords.';
        $lines[] = '2. Call `load_skill` with its skill_key to read the full skill.md instruction.';
        $lines[] = '3. For `[sandbox]` skills, after loading, call `execute_sandbox_skill` with the same skill_key plus a concrete `request` derived from the user input.';
        $lines[] = '4. For `[api]` skills, call `execute_api_skill` with the skill_key plus a `request` that is a JSON object mapping each listed parameter (by its name) to the value extracted from the user request. If the user has not provided a required parameter, ask for it before calling.';
        $lines[] = '5. For `[instruction]` skills, follow the loaded skill.md yourself using the available base tools.';
        $lines[] = '6. If no skill fits, proceed with the base tools or a direct reply. Do not force-fit an unrelated skill.';
        $lines[] = '</skills>';

        return implode("\n", $lines);
    }
}
