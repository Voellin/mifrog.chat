<?php

namespace App\Services\Memory;

class MemoryLayerPolicy
{
    private const DURABLE_CATEGORIES = [
        'identity',
        'constraint',
        'preference',
        'style',
        'work_context',
        'project_anchor',
    ];

    public function __construct(
        private readonly MemoryTextSanitizer $textSanitizer,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function classifyUserText(string $text, bool $explicitRemember = false): array
    {
        $text = $this->normalize($text);
        if ($text === '') {
            return $this->noiseDecision('empty');
        }

        if ($this->looksNoiseOnly($text)) {
            return $this->noiseDecision('small_talk');
        }

        if ($this->looksTransientLifeDetail($text) && ! $explicitRemember) {
            return [
                'kind' => 'transient_detail',
                'store_l2' => false,
                'promote_l3' => false,
                'category' => 'transient_detail',
                'priority' => 10,
                'reason' => 'short_lived_detail',
                'title' => 'Transient detail',
                'tags' => ['source:user', 'kind:transient_detail', 'ttl:1'],
            ];
        }

        if ($this->looksHardTaskInstruction($text)) {
            return [
                'kind' => 'episodic_user_context',
                'store_l2' => true,
                'promote_l3' => false,
                'category' => 'episodic_context',
                'priority' => 35,
                'reason' => $explicitRemember ? 'explicit_but_not_durable' : 'episodic_context',
                'title' => $explicitRemember ? 'Transient user memory' : 'Recent context',
                'tags' => ['source:user', 'kind:episodic_context', 'ttl:7'],
            ];
        }

        $category = $this->detectDurableCategory($text, $explicitRemember);
        if ($category !== null && $this->durableBlockReason($text, false) === null) {
            return [
                'kind' => 'durable_user_fact',
                'store_l2' => true,
                'promote_l3' => true,
                'category' => $category,
                'priority' => $this->basePriorityForCategory($category, $explicitRemember),
                'reason' => $explicitRemember ? 'explicit_remember' : 'durable_user_fact',
                'title' => $explicitRemember ? 'Active memory' : 'Durable user fact',
                'tags' => ['source:user', 'kind:durable_user_fact', 'durable:yes', 'category:'.$category],
            ];
        }

        return [
            'kind' => 'episodic_user_context',
            'store_l2' => true,
            'promote_l3' => false,
            'category' => 'episodic_context',
            'priority' => 35,
            'reason' => $explicitRemember ? 'explicit_but_not_durable' : 'episodic_context',
            'title' => $explicitRemember ? 'Transient user memory' : 'Recent context',
            'tags' => ['source:user', 'kind:episodic_context', 'ttl:7'],
        ];
    }

    /**
     * @param  array<int, string>  $userAliases
     * @return array<string, mixed>
     */
    public function classifyAssistantAnswer(string $rawText, array $userAliases = []): array
    {
        $sanitized = $this->textSanitizer->sanitizeAssistantReply($rawText, $userAliases);
        if ($sanitized === '') {
            return $this->noiseDecision('sanitized_empty');
        }

        if ($this->looksAssistantCapabilityText($sanitized)) {
            return $this->noiseDecision('assistant_capability');
        }

        if ($this->looksClarificationOnly($rawText)) {
            return $this->noiseDecision('clarification_only');
        }

        $isExecution = $this->looksExecutionResult($sanitized);

        return [
            'kind' => $isExecution ? 'episodic_result' : 'assistant_summary',
            'store_l2' => true,
            'promote_l3' => false,
            'category' => $isExecution ? 'episodic_result' : 'assistant_summary',
            'priority' => $isExecution ? 45 : 25,
            'reason' => $isExecution ? 'episodic_result' : 'assistant_summary',
            'title' => $isExecution ? 'Execution result summary' : 'Assistant summary',
            'tags' => ['source:assistant', 'kind:'.($isExecution ? 'episodic_result' : 'assistant_summary'), 'ttl:'.($isExecution ? '14' : '5')],
            'sanitized_content' => $sanitized,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function reviewFact(string $fact, string $category, array $meta = []): array
    {
        $fact = $this->normalize($fact);
        if ($fact === '') {
            return ['allow' => false, 'reason' => 'empty'];
        }

        if (mb_strlen($fact, 'UTF-8') > 200) {
            return ['allow' => false, 'reason' => 'too_long'];
        }

        if (substr_count($fact, "\n") >= 2 || str_contains($fact, '## ') || str_contains($fact, '---')) {
            return ['allow' => false, 'reason' => 'document_like'];
        }

        if ($this->textSanitizer->isAssistantOfferLine($fact)) {
            return ['allow' => false, 'reason' => 'assistant_offer'];
        }

        if ($this->textSanitizer->isGreetingLine($fact, [])) {
            return ['allow' => false, 'reason' => 'greeting_like'];
        }

        $blocked = $this->durableBlockReason($fact, true);
        if ($blocked !== null) {
            return ['allow' => false, 'reason' => $blocked];
        }

        $normalizedCategory = $this->normalizeCategory($category, $fact);
        if ($normalizedCategory === null) {
            return ['allow' => false, 'reason' => 'not_durable'];
        }

        $priority = (int) ($meta['priority'] ?? 0);
        if ($priority <= 0) {
            $priority = $this->basePriorityForCategory($normalizedCategory, false);
        }

        return [
            'allow' => true,
            'category' => $normalizedCategory,
            'priority' => min(99, max(60, $priority)),
        ];
    }

    public function isNoiseForPrompt(string $text): bool
    {
        $text = $this->normalize($text);
        if ($text === '') {
            return true;
        }

        if ($this->textSanitizer->isAssistantOfferLine($text)) {
            return true;
        }

        return $this->looksAssistantCapabilityText($text);
    }

    private function normalizeCategory(string $category, string $fact): ?string
    {
        $category = trim(mb_strtolower($category, 'UTF-8'));
        if (in_array($category, self::DURABLE_CATEGORIES, true) && $this->matchesCategory($category, $fact, false)) {
            return $category;
        }

        return $this->detectDurableCategory($fact, false, $category);
    }

    private function detectDurableCategory(string $text, bool $explicitRemember = false, ?string $hintCategory = null): ?string
    {
        $candidates = [];
        $hintCategory = trim(mb_strtolower((string) $hintCategory, 'UTF-8'));
        if (in_array($hintCategory, self::DURABLE_CATEGORIES, true)) {
            $candidates[] = $hintCategory;
        }

        foreach (self::DURABLE_CATEGORIES as $category) {
            if (! in_array($category, $candidates, true)) {
                $candidates[] = $category;
            }
        }

        foreach ($candidates as $category) {
            if ($this->matchesCategory($category, $text, $explicitRemember)) {
                return $category;
            }
        }

        return null;
    }

    private function matchesCategory(string $category, string $text, bool $explicitRemember): bool
    {
        return match ($category) {
            'identity' => $this->looksIdentityFact($text),
            'preference' => $this->looksPreferenceFact($text),
            'constraint' => $this->looksStableConstraint($text, $explicitRemember),
            'style' => $this->looksStyleFact($text, $explicitRemember),
            'project_anchor' => $this->looksProjectAnchor($text, $explicitRemember),
            'work_context' => $this->looksWorkContext($text, $explicitRemember),
            default => false,
        };
    }

    private function durableBlockReason(string $text, bool $fromFact): ?string
    {
        if ($this->looksClarificationFact($text)) {
            return 'clarification_fact';
        }

        if ($this->looksAssistantGeneratedFact($text)) {
            return 'assistant_generated_fact';
        }

        if ($this->looksAssistantCapabilityText($text)) {
            return 'assistant_capability';
        }

        if ($this->containsUrl($text)) {
            return 'contains_url';
        }

        if ($this->containsSensitivePayload($text)) {
            return 'contains_sensitive_payload';
        }

        if ($this->looksTransientLifeDetail($text)) {
            return 'transient_detail';
        }

        if ($this->looksEphemeralDirective($text)) {
            return 'ephemeral_directive';
        }

        if ($this->looksHardTaskInstruction($text)) {
            return 'task_instruction';
        }

        if ($fromFact && preg_match('/[?？]\s*$/u', $text) === 1) {
            return 'question_like';
        }

        return null;
    }

    private function basePriorityForCategory(string $category, bool $explicitRemember): int
    {
        $base = match ($category) {
            'identity' => 96,
            'constraint' => 92,
            'preference' => 88,
            'style' => 86,
            'work_context' => 85,
            'project_anchor' => 82,
            default => 80,
        };

        return $explicitRemember ? min(98, $base + 4) : $base;
    }

    private function looksIdentityFact(string $text): bool
    {
        return $this->containsAnyPhrase($text, ['我叫', '我的名字是', '可以叫我', '称呼我', '以后叫我', '英文名']);
    }

    private function looksPreferenceFact(string $text): bool
    {
        return $this->containsAnyPhrase($text, [
            '偏好', '更喜欢', '我喜欢', '我习惯', '常用', '倾向于', '默认用',
            '请用中文', '中文回复', '用中文回复', '少用表格', '多给例子',
            '先给结论', '直接一点', '简洁一点', '少些套话', '少一点套话',
        ]);
    }

    private function looksStableConstraint(string $text, bool $explicitRemember): bool
    {
        $orderingConstraint = $this->containsAnyPhrase($text, ['先给结论', '先结论后解释', '先给结果', '先说结论'])
            && $this->containsAnyPhrase($text, ['默认', '以后', '今后', '往后', '每次']);

        if ($orderingConstraint && ! $this->looksEphemeralDirective($text)) {
            return true;
        }

        if (! $this->containsAnyPhrase($text, ['不要', '必须', '务必', '禁止', '只能', '优先', '不能', '不可'])) {
            return false;
        }

        if ($this->looksEphemeralDirective($text)) {
            return false;
        }

        return $explicitRemember || $this->hasPersistentCue($text);
    }

    private function looksStyleFact(string $text, bool $explicitRemember): bool
    {
        if ($this->looksEphemeralDirective($text)) {
            return false;
        }

        if (! $this->containsAnyPhrase($text, ['语气', '风格', '格式', '排版', '结构化', '分点', '简洁', '正式', '口语', '短句', '标题', '先给结论', '一句话总结', '先结论后展开', '回答直接一点', '少些铺垫'])) {
            return false;
        }

        return $explicitRemember || $this->hasPersistentCue($text) || $this->looksPreferenceFact($text);
    }

    private function looksProjectAnchor(string $text, bool $explicitRemember): bool
    {
        $projectWords = ['项目', '模块', '系统', '产品', '客户', '主题', '方向'];
        $anchorWords = ['当前', '目前', '长期', '主要', '重点', '核心', '正在推进', '长期推进', '在做', '负责', '维护', '重构', '跟进'];

        if ($this->containsAnyPhrase($text, $projectWords) && $this->containsAnyPhrase($text, $anchorWords)) {
            return true;
        }

        return $explicitRemember && $this->containsAnyPhrase($text, $projectWords);
    }

    private function looksWorkContext(string $text, bool $explicitRemember): bool
    {
        $roleWords = ['部门', '岗位', '职位', '角色', '职责', '负责'];
        $selfWords = ['我在', '所在', '我是', '我的岗位', '我的职位', '我主要'];

        if ($this->containsAnyPhrase($text, $roleWords) && $this->containsAnyPhrase($text, $selfWords)) {
            return true;
        }

        return $explicitRemember && $this->containsAnyPhrase($text, $roleWords);
    }

    private function hasPersistentCue(string $text): bool
    {
        return $this->containsAnyPhrase($text, ['以后', '之后', '默认', '长期', '平时', '今后', '往后', '每次', '固定', '一直']);
    }

    private function containsUrl(string $text): bool
    {
        return preg_match('/https?:\/\/\S+/iu', $text) === 1;
    }

    private function containsSensitivePayload(string $text): bool
    {
        if (preg_match('/\b(token|app_token|table_id|view_id|record_id|doc_token|sheet_id|space_id|wiki_id|event_id|base_token|tenant_access_token|refresh_token|user_access_token|authorization|bearer)\b/iu', $text) === 1) {
            return true;
        }

        return preg_match('/(?:^|[\s:])([A-Za-z0-9_-]{24,})(?:$|[\s,])/u', $text) === 1;
    }

    private function looksHardTaskInstruction(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        $leadingPhrases = ['帮我', '请你', '请', '麻烦', '劳烦', '给我', '替我'];
        $actionWords = ['创建', '新建', '读取', '看看', '分析', '总结', '搜索', '查询', '写入', '追加', '更新', '安排', '添加', '删除', '同步', '导出', '发送', '打开', '提炼', '整理'];
        $objectWords = ['文档', '表格', '会议', '日程', '待办', '审批', '邮件', '纪要', 'Base', 'Wiki', '链接', '联系人', '通讯录', '云盘', 'sheet', 'doc', 'calendar'];

        foreach ($leadingPhrases as $prefix) {
            if (str_starts_with($text, $prefix) && $this->containsAnyPhrase($text, $actionWords)) {
                return true;
            }
        }

        return $this->containsAnyPhrase($text, $actionWords)
            && $this->containsAnyPhrase($text, $objectWords);
    }

    private function looksEphemeralDirective(string $text): bool
    {
        return $this->containsAnyPhrase($text, [
            '你现在', '这一轮', '这次', '当前轮', '本轮', '暂时', '先只用', '先不要', '先别',
            '不要调用任何', '只用一句话回答', '先回复我', '先说结论给我',
        ]);
    }

    private function looksNoiseOnly(string $text): bool
    {
        $trimmed = trim($text, "。.!！？ ");

        return in_array($trimmed, ['你好', '您好', '在吗', '收到', '好的', '嗯', 'ok', 'OK', '谢谢', '辛苦了'], true);
    }

    private function looksTransientLifeDetail(string $text): bool
    {
        return $this->containsAnyPhrase($text, [
            '早饭', '早餐', '午饭', '晚饭', '吃了什么', '喝了什么',
            '刚刚吃', '今天吃', '现在在吃', '刚起床', '刚到公司',
            '今天天气', '下雨了', '有点困', '有点累', '有点饿',
        ]);
    }

    private function looksClarificationOnly(string $text): bool
    {
        return $this->containsAnyPhrase($text, ['请补充', '麻烦你补充', '确认一下', '还需要几个小细节', '请提供', '请先提供'])
            && $this->containsAnyPhrase($text, ['标题', '时间', '链接', 'token', '列名', '内容']);
    }

    private function looksExecutionResult(string $text): bool
    {
        return $this->containsAnyPhrase($text, ['已为你', '已经帮你', '成功创建', '创建好了', '创建完成', '已创建', '已生成', '已更新', '已整理', '已写好']);
    }

    private function looksAssistantCapabilityText(string $text): bool
    {
        return $this->containsAnyPhrase($text, ['能力', '擅长', '可按', '可以帮你', '可为你', '支持'])
            && $this->containsAnyPhrase($text, ['整理', '生成', '分析', '润色', '梳理']);
    }

    private function looksAssistantGeneratedFact(string $text): bool
    {
        if ($this->containsAnyPhrase($text, ['已为你创建', '已经帮你创建', '文档已创建', '如需我继续', '告诉我链接'])) {
            return true;
        }

        if ($this->containsAnyPhrase($text, ['没办法直接访问', '暂未直接配置', '无法直接', '权限', '登录验证'])) {
            return true;
        }

        return preg_match('/^我是/u', $text) === 1
            && $this->containsAnyPhrase($text, ['MiFrog', '助手', '模型', 'Feishu', '飞书']);
    }

    private function looksClarificationFact(string $text): bool
    {
        return $this->containsAnyPhrase($text, ['还缺', '缺少', '请补充', '请先提供', '需要补充', '告诉我'])
            && $this->containsAnyPhrase($text, ['标题', '主题', '时间', '开始时间', '结束时间', '链接', 'token', '列名', '内容']);
    }

    /**
     * @return array<string, mixed>
     */
    private function noiseDecision(string $reason): array
    {
        return [
            'kind' => 'noise',
            'store_l2' => false,
            'promote_l3' => false,
            'category' => 'noise',
            'priority' => 0,
            'reason' => $reason,
            'title' => 'Noise',
            'tags' => ['kind:noise'],
        ];
    }

    private function containsAnyPhrase(string $text, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if ($phrase !== '' && str_contains($text, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
