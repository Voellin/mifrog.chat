<?php

namespace App\Services;

use App\Models\AuditPolicy;
use App\Models\AuditPolicyTerm;
use App\Models\Run;
use App\Models\RunAuditRecord;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;

class AuditService
{
    public const STAGE_INPUT = 'input';
    public const STAGE_OUTPUT = 'output';

    public const ACTION_ALLOW = 'allow';
    public const ACTION_MASK = 'mask';
    public const ACTION_BLOCK = 'block';

    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_DEPARTMENT = 'department';

    private const DEFAULT_BLOCKED_MESSAGE = '内容触发企业合规策略，已被拦截。';

    public function listPolicies(): Collection
    {
        return AuditPolicy::query()
            ->with([
                'department:id,name',
                'terms' => fn ($query) => $query->where('is_active', true)->orderBy('id'),
            ])
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();
    }

    public function createPolicy(array $data): AuditPolicy
    {
        return $this->persistPolicy(new AuditPolicy(), $data);
    }

    public function updatePolicy(AuditPolicy $policy, array $data): AuditPolicy
    {
        return $this->persistPolicy($policy, $data);
    }

    public function auditInput(Run $run, string $content): array
    {
        $evaluation = $this->evaluate($run, $content, self::STAGE_INPUT);
        $hit = ! empty($evaluation['matched_terms']);
        $action = (string) ($evaluation['action'] ?? self::ACTION_ALLOW);
        $decision = $action === self::ACTION_BLOCK ? 'blocked' : 'pass';

        $this->record(
            $run,
            self::STAGE_INPUT,
            $content,
            $hit,
            $evaluation['matched_terms'],
            $evaluation['matched_policy_ids'],
            $evaluation['matched_policy_names'],
            $action,
            $decision,
            ['matched_policies' => $evaluation['matched_policies']]
        );

        if ($hit && $action === self::ACTION_BLOCK) {
            return [
                'blocked' => true,
                'message' => (string) ($evaluation['blocked_message'] ?? self::DEFAULT_BLOCKED_MESSAGE),
                'matched_terms' => (array) ($evaluation['matched_terms'] ?? []),
                'matched_policy_ids' => (array) ($evaluation['matched_policy_ids'] ?? []),
            ];
        }

        return [
            'blocked' => false,
            'message' => null,
            'matched_terms' => (array) ($evaluation['matched_terms'] ?? []),
            'matched_policy_ids' => (array) ($evaluation['matched_policy_ids'] ?? []),
        ];
    }

    public function auditOutput(Run $run, string $content): array
    {
        $evaluation = $this->evaluate($run, $content, self::STAGE_OUTPUT);
        $hit = ! empty($evaluation['matched_terms']);
        $action = (string) ($evaluation['action'] ?? self::ACTION_ALLOW);

        $decision = 'pass';
        $finalContent = $content;

        if ($hit && $action === self::ACTION_MASK) {
            $finalContent = $this->maskTerms($content, (array) ($evaluation['matched_terms'] ?? []));
            $decision = 'masked';
        }

        if ($hit && $action === self::ACTION_BLOCK) {
            $finalContent = (string) ($evaluation['blocked_message'] ?? self::DEFAULT_BLOCKED_MESSAGE);
            $decision = 'blocked';
        }

        $this->record(
            $run,
            self::STAGE_OUTPUT,
            $content,
            $hit,
            $evaluation['matched_terms'],
            $evaluation['matched_policy_ids'],
            $evaluation['matched_policy_names'],
            $action,
            $decision,
            ['matched_policies' => $evaluation['matched_policies']]
        );

        return [
            'content' => $finalContent,
            'blocked' => $hit && $action === self::ACTION_BLOCK,
            'masked' => $hit && $action === self::ACTION_MASK,
            'matched_terms' => (array) ($evaluation['matched_terms'] ?? []),
            'matched_policy_ids' => (array) ($evaluation['matched_policy_ids'] ?? []),
            'action' => $action,
        ];
    }

    private function persistPolicy(AuditPolicy $policy, array $data): AuditPolicy
    {
        $scopeType = strtolower(trim((string) ($data['scope_type'] ?? self::SCOPE_GLOBAL)));
        if (! in_array($scopeType, [self::SCOPE_GLOBAL, self::SCOPE_DEPARTMENT], true)) {
            $scopeType = self::SCOPE_GLOBAL;
        }

        $departmentId = $scopeType === self::SCOPE_DEPARTMENT ? (int) ($data['department_id'] ?? 0) : 0;
        if ($scopeType === self::SCOPE_DEPARTMENT && $departmentId <= 0) {
            $scopeType = self::SCOPE_GLOBAL;
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $name = $scopeType === self::SCOPE_DEPARTMENT ? '部门审计策略' : '全局审计策略';
        }

        $terms = $this->parseTerms((string) ($data['terms_text'] ?? ''));

        return DB::transaction(function () use ($policy, $scopeType, $departmentId, $name, $data, $terms): AuditPolicy {
            $policy->name = $name;
            $policy->scope_type = $scopeType;
            $policy->department_id = $scopeType === self::SCOPE_DEPARTMENT ? $departmentId : null;
            $policy->priority = max(0, (int) ($data['priority'] ?? 100));
            $policy->input_action = $this->normalizeAction((string) ($data['input_action'] ?? self::ACTION_BLOCK), false);
            $policy->output_action = $this->normalizeAction((string) ($data['output_action'] ?? self::ACTION_MASK), true);
            $policy->blocked_message = $this->normalizeBlockedMessage((string) ($data['blocked_message'] ?? ''));
            $policy->is_active = (bool) ($data['is_active'] ?? false);
            $policy->save();

            AuditPolicyTerm::query()->where('policy_id', $policy->id)->delete();
            if (! empty($terms)) {
                $rows = [];
                foreach ($terms as $term) {
                    $rows[] = [
                        'policy_id' => $policy->id,
                        'term' => $term,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                AuditPolicyTerm::query()->insert($rows);
            }

            return $policy->load([
                'department:id,name',
                'terms' => fn ($query) => $query->where('is_active', true)->orderBy('id'),
            ]);
        });
    }

    private function normalizeAction(string $action, bool $allowMask): string
    {
        $action = strtolower(trim($action));
        $allowed = [self::ACTION_ALLOW, self::ACTION_BLOCK];
        if ($allowMask) {
            $allowed[] = self::ACTION_MASK;
        }

        if (! in_array($action, $allowed, true)) {
            return $allowMask ? self::ACTION_MASK : self::ACTION_BLOCK;
        }

        return $action;
    }

    private function normalizeBlockedMessage(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return self::DEFAULT_BLOCKED_MESSAGE;
        }

        return $message;
    }

    private function evaluate(Run $run, string $content, string $stage): array
    {
        $policies = $this->resolveApplicablePolicies($run);
        if ($policies->isEmpty()) {
            return [
                'action' => self::ACTION_ALLOW,
                'blocked_message' => self::DEFAULT_BLOCKED_MESSAGE,
                'matched_terms' => [],
                'matched_policy_ids' => [],
                'matched_policy_names' => [],
                'matched_policies' => [],
            ];
        }

        $matchedTermsMap = [];
        $matchedPolicyIds = [];
        $matchedPolicyNames = [];
        $matchedPolicies = [];

        $actions = [];
        $blockMessage = self::DEFAULT_BLOCKED_MESSAGE;

        foreach ($policies as $policy) {
            $terms = $policy->terms
                ->filter(fn (AuditPolicyTerm $term) => (bool) $term->is_active)
                ->map(fn (AuditPolicyTerm $term) => (string) $term->term)
                ->values()
                ->all();
            $matchedTerms = $this->matchTerms($content, $terms);
            if (empty($matchedTerms)) {
                continue;
            }

            $action = $stage === self::STAGE_INPUT
                ? $this->normalizeAction((string) $policy->input_action, false)
                : $this->normalizeAction((string) $policy->output_action, true);

            $actions[] = $action;
            if ($action === self::ACTION_BLOCK && trim((string) $policy->blocked_message) !== '') {
                $blockMessage = (string) $policy->blocked_message;
            }

            foreach ($matchedTerms as $term) {
                $matchedTermsMap[$term] = true;
            }

            $matchedPolicyIds[(string) $policy->id] = $policy->id;
            $matchedPolicyNames[(string) $policy->id] = (string) $policy->name;
            $matchedPolicies[] = [
                'policy_id' => $policy->id,
                'policy_name' => $policy->name,
                'scope_type' => $policy->scope_type,
                'department_id' => $policy->department_id,
                'priority' => $policy->priority,
                'action' => $action,
                'matched_terms' => $matchedTerms,
            ];
        }

        if (empty($matchedPolicies)) {
            return [
                'action' => self::ACTION_ALLOW,
                'blocked_message' => self::DEFAULT_BLOCKED_MESSAGE,
                'matched_terms' => [],
                'matched_policy_ids' => [],
                'matched_policy_names' => [],
                'matched_policies' => [],
            ];
        }

        $finalAction = self::ACTION_ALLOW;
        if (in_array(self::ACTION_BLOCK, $actions, true)) {
            $finalAction = self::ACTION_BLOCK;
        } elseif ($stage === self::STAGE_OUTPUT && in_array(self::ACTION_MASK, $actions, true)) {
            $finalAction = self::ACTION_MASK;
        }

        return [
            'action' => $finalAction,
            'blocked_message' => $blockMessage,
            'matched_terms' => array_values(array_keys($matchedTermsMap)),
            'matched_policy_ids' => array_values($matchedPolicyIds),
            'matched_policy_names' => array_values($matchedPolicyNames),
            'matched_policies' => $matchedPolicies,
        ];
    }

    private function resolveApplicablePolicies(Run $run): Collection
    {
        if (! $run->relationLoaded('user')) {
            $run->load('user');
        }

        $departmentId = (int) ($run->user?->department_id ?? 0);
        $query = AuditPolicy::query()
            ->with([
                'terms' => fn ($q) => $q->where('is_active', true)->orderBy('id'),
            ])
            ->where('is_active', true)
            ->where(function ($builder) use ($departmentId): void {
                $builder->where('scope_type', self::SCOPE_GLOBAL);
                if ($departmentId > 0) {
                    $builder->orWhere(function ($q) use ($departmentId): void {
                        $q->where('scope_type', self::SCOPE_DEPARTMENT)
                            ->where('department_id', $departmentId);
                    });
                }
            })
            ->orderByDesc('priority')
            ->orderBy('id');

        $policies = $query->get();
        if ($policies->isNotEmpty()) {
            return $policies;
        }

        // 兜底：如果新策略表为空，尝试沿用旧 settings 配置生成一个全局策略。
        $legacy = $this->normalizeLegacyPolicy(Setting::read('audit_policy', []));
        if ($legacy !== null) {
            $created = $this->createPolicy([
                'name' => '默认全局策略',
                'scope_type' => self::SCOPE_GLOBAL,
                'department_id' => null,
                'priority' => 100,
                'input_action' => $legacy['input_action'],
                'output_action' => $legacy['output_action'],
                'blocked_message' => $legacy['blocked_message'],
                'terms_text' => implode("\n", $legacy['terms']),
                'is_active' => $legacy['enabled'],
            ]);

            return new Collection([$created]);
        }

        return new Collection();
    }

    private function normalizeLegacyPolicy(array $raw): ?array
    {
        $terms = $this->parseTerms(implode("\n", (array) Arr::get($raw, 'terms', [])));
        if (empty($terms)) {
            return null;
        }

        return [
            'enabled' => (bool) Arr::get($raw, 'enabled', true),
            'input_action' => $this->normalizeAction((string) Arr::get($raw, 'input_action', self::ACTION_BLOCK), false),
            'output_action' => $this->normalizeAction((string) Arr::get($raw, 'output_action', self::ACTION_MASK), true),
            'blocked_message' => $this->normalizeBlockedMessage((string) Arr::get($raw, 'blocked_message', '')),
            'terms' => $terms,
        ];
    }

    private function parseTerms(string $input): array
    {
        $normalized = str_replace(["\r\n", "\r", '，', ',', ';', '；', "\t"], "\n", $input);
        $chunks = explode("\n", $normalized);

        $terms = [];
        foreach ($chunks as $chunk) {
            $term = trim($chunk);
            if ($term === '') {
                continue;
            }
            $terms[$term] = true;
        }

        return array_values(array_keys($terms));
    }

    private function matchTerms(string $content, array $terms): array
    {
        $matched = [];
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        foreach ($terms as $term) {
            $needle = trim((string) $term);
            if ($needle === '') {
                continue;
            }

            if ($this->contains($content, $needle)) {
                $matched[$needle] = true;
            }
        }

        return array_values(array_keys($matched));
    }

    private function contains(string $content, string $needle): bool
    {
        if (function_exists('mb_stripos')) {
            return mb_stripos($content, $needle, 0, 'UTF-8') !== false;
        }

        return stripos($content, $needle) !== false;
    }

    private function maskTerms(string $content, array $terms): string
    {
        $result = $content;
        foreach ($terms as $term) {
            $needle = trim((string) $term);
            if ($needle === '') {
                continue;
            }

            $maskLength = function_exists('mb_strlen') ? mb_strlen($needle, 'UTF-8') : strlen($needle);
            $mask = str_repeat('*', max(2, min(12, $maskLength)));
            $pattern = '/'.preg_quote($needle, '/').'/iu';
            $masked = preg_replace($pattern, $mask, $result);
            if (is_string($masked)) {
                $result = $masked;
            }
        }

        return $result;
    }

    private function record(
        Run $run,
        string $stage,
        string $content,
        bool $hit,
        array $matchedTerms,
        array $matchedPolicyIds,
        array $matchedPolicyNames,
        string $action,
        string $decision,
        array $meta = []
    ): void {
        RunAuditRecord::query()->create([
            'run_id' => $run->id,
            'conversation_id' => $run->conversation_id,
            'user_id' => $run->user_id,
            'stage' => $stage,
            'hit' => $hit,
            'matched_terms' => $matchedTerms,
            'matched_policy_ids' => $matchedPolicyIds,
            'matched_policy_names' => $matchedPolicyNames,
            'action' => $action,
            'decision' => $decision,
            'content_excerpt' => $this->excerpt($content),
            'meta' => $meta,
        ]);
    }

    private function excerpt(string $content): string
    {
        $line = preg_replace('/\s+/u', ' ', trim($content));
        $line = is_string($line) ? $line : trim($content);
        if ($line === '') {
            return '';
        }

        $max = 220;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($line, 'UTF-8') > $max
                ? mb_substr($line, 0, $max - 1, 'UTF-8').'…'
                : $line;
        }

        return strlen($line) > $max
            ? substr($line, 0, $max - 3).'...'
            : $line;
    }
}
