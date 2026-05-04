<?php

namespace App\Modules\Doppelganger\Services;

use App\Models\User;
use App\Modules\Doppelganger\Models\Doppelganger;
use App\Modules\Doppelganger\Models\DoppelgangerGrant;
use App\Modules\Doppelganger\Models\DoppelgangerInvocation;

/**
 * 飞书消息入口的"~B：xxx" 调用解析与分发。
 *
 * 设计要点：
 *  - 完全独立于 admin web 入口（DoppelgangerInvocationController），
 *    那条链路继续给 admin 调试用，鉴权按 admin 身份；这条链路按真实业务用户身份。
 *  - 仿 QuickReplyService 的契约：attempt() 返回 null 表示不是本类消息，
 *    让 caller 继续走原有 Run pipeline。
 *  - 鉴权严格按 doppelganger_grants.grantee_user_id == $user->id；
 *    无 super 用户概念（飞书入口都是普通业务用户）。
 *  - 全部分发到现有的 KnowledgeService / VoiceService / WorkflowService，
 *    本类不重复写 LLM 调用。
 */
class DoppelgangerInvocationService
{
    /** 触发前缀正则：~Name: 或 ~Name：（中英冒号都吃，名字里不能含冒号或空白） */
    private const PREFIX_RE = '/^\s*~([^\s:：]+)[:：]\s*(.*)$/u';

    /** 子命令前缀（query 内部，大小写不敏感，后跟空格或行尾）：write → Level 2，run → Level 3 */
    private const SUBCMD_DRAFT_KEYWORD = 'write';
    private const SUBCMD_WORKFLOW_KEYWORD = 'run';

    public function __construct(
        private readonly KnowledgeService $knowledge,
        private readonly VoiceService $voice,
        private readonly WorkflowService $workflowService,
        private readonly DoppelgangerReplyFormatter $formatter,
    ) {}

    /**
     * 检测并处理 ~B: 消息。
     *
     * @return string|null 已处理则返回回复文本；不是 ~B: 消息则返回 null（caller 继续 Run pipeline）
     */
    public function attempt(string $text, User $user): ?string
    {
        $parsed = $this->parsePrefix($text);
        if ($parsed === null) {
            return null;  // 不是 ~B: 消息，让 Run pipeline 接管
        }

        [$rawName, $payload] = $parsed;

        // payload 为空：用户只发了"~B：" 没带内容
        if (trim($payload) === '') {
            return $this->formatter->error("请在 `~{$rawName}：` 后面跟你的问题或指令。\n例如：`~{$rawName}：那个项目当时怎么处理的？`");
        }

        // 解析名字 → 候选分身
        $candidates = $this->resolveDoppelganger($rawName);
        if ($candidates->isEmpty()) {
            return $this->formatter->error("未找到名为「{$rawName}」的活跃数字分身。\n请确认名字拼写，或联系管理员检查该分身是否已激活。");
        }
        if ($candidates->count() > 1) {
            $list = $candidates->map(fn($d) => '- `~' . ($d->display_name ?? $d->sourceUser?->name) . '#' . $d->id . '：` ' . ($d->sourceUser?->name ?? '?') . '（部门 #' . ($d->sourceUser?->department_id ?? '-') . '）')->implode("\n");
            return $this->formatter->error("找到多个名为「{$rawName}」的数字分身，请用 `#ID` 指定：\n{$list}");
        }

        $dop = $candidates->first();

        // 鉴权
        $grant = DoppelgangerGrant::query()
            ->where('doppelganger_id', $dop->id)
            ->where('grantee_user_id', $user->id)
            ->first();

        if (! $grant || ! $grant->isActive()) {
            return $this->formatter->error("你尚未被授权调阅「{$rawName}」的数字分身。\n请联系管理员在管理后台为你添加授权。");
        }

        // 子命令分发
        $level = $this->detectLevel($payload);

        // 层级权限校验
        if ($level === DoppelgangerInvocation::LEVEL_VOICE && ! $grant->canUseVoice()) {
            return $this->formatter->error("你的授权层级（" . $this->levelLabel($grant->access_level) . "）不允许 `write`（起草）。\n如需起草草稿，请联系管理员升级到「起草」或「完整」。");
        }
        if ($level === DoppelgangerInvocation::LEVEL_WORKFLOW && ! $grant->canUseWorkflow()) {
            return $this->formatter->error("你的授权层级（" . $this->levelLabel($grant->access_level) . "）不允许 `run`（工作流）。\n如需触发工作流，请联系管理员升级到「工作流」或「完整」。");
        }

        // 分发
        return match ($level) {
            DoppelgangerInvocation::LEVEL_VOICE    => $this->handleDraft($dop, $user, $payload),
            DoppelgangerInvocation::LEVEL_WORKFLOW => $this->handleWorkflow($dop, $user, $payload),
            default                                 => $this->handleAsk($dop, $user, $payload),
        };
    }

    /**
     * 把消息按 `~Name: payload` 切开。
     *
     * @return array{0:string,1:string}|null [rawName, payload] 或 null（不是本类消息）
     */
    public function parsePrefix(string $text): ?array
    {
        if (! preg_match(self::PREFIX_RE, $text, $m)) {
            return null;
        }
        $rawName = trim($m[1]);
        $payload = (string) $m[2];

        // 名字不能太长（防 ~一段长字符串 误命中）
        if (mb_strlen($rawName) > 30 || mb_strlen($rawName) === 0) {
            return null;
        }
        return [$rawName, $payload];
    }

    /**
     * 名字 → 候选 Doppelganger（status=active）
     */
    public function resolveDoppelganger(string $rawName): \Illuminate\Support\Collection
    {
        // 支持 ~Name#ID： 显式指定
        $explicitId = null;
        if (preg_match('/^(.+?)#(\d+)$/u', $rawName, $m)) {
            $rawName = trim($m[1]);
            $explicitId = (int) $m[2];
        }

        $query = Doppelganger::with('sourceUser')
            ->where('status', Doppelganger::STATUS_ACTIVE);

        if ($explicitId !== null) {
            return $query->where('id', $explicitId)
                ->where(function ($q) use ($rawName) {
                    $q->where('display_name', $rawName)
                      ->orWhereHas('sourceUser', fn($qq) => $qq->where('name', $rawName));
                })
                ->get();
        }

        // 优先精确匹配 display_name
        $exact = (clone $query)->where('display_name', $rawName)->get();
        if ($exact->isNotEmpty()) {
            return $exact;
        }
        // 再精确匹配 sourceUser.name
        $exactByName = (clone $query)->whereHas('sourceUser', fn($q) => $q->where('name', $rawName))->get();
        if ($exactByName->isNotEmpty()) {
            return $exactByName;
        }
        // 最后前缀模糊匹配（display_name 或 sourceUser.name）
        return (clone $query)->where(function ($q) use ($rawName) {
            $q->where('display_name', 'LIKE', $rawName . '%')
              ->orWhereHas('sourceUser', fn($qq) => $qq->where('name', 'LIKE', $rawName . '%'));
        })->limit(5)->get();
    }

    /** 检测 payload 的子命令 → Level（关键字后必须是空白或行尾，避免 'writing'/'runner' 误判） */
    private function detectLevel(string $payload): int
    {
        $trimmed = ltrim($payload);
        if (preg_match('/^' . self::SUBCMD_DRAFT_KEYWORD . '(\\s|$)/i', $trimmed)) {
            return DoppelgangerInvocation::LEVEL_VOICE;
        }
        if (preg_match('/^' . self::SUBCMD_WORKFLOW_KEYWORD . '(\\s|$)/i', $trimmed)) {
            return DoppelgangerInvocation::LEVEL_WORKFLOW;
        }
        return DoppelgangerInvocation::LEVEL_KNOWLEDGE;
    }

    /** 把子命令关键字 + 后续空白剥掉，留下真正的 situation/workflow_name */
    private function stripSubcommand(string $payload, string $keyword): string
    {
        $trimmed = ltrim($payload);
        return trim((string) preg_replace('/^' . preg_quote($keyword, '/') . '\\s*/i', '', $trimmed));
    }

    private function handleAsk(Doppelganger $dop, User $user, string $payload): string
    {
        $question = trim($payload);
        $result = $this->knowledge->ask($dop, $question);
        $this->logInvocation($dop, $user, DoppelgangerInvocation::LEVEL_KNOWLEDGE, $question, $result['answer'] ?? '', [
            'token_input' => (int) ($result['token_input'] ?? 0),
            'token_output' => (int) ($result['token_output'] ?? 0),
            'meta' => ['sources_count' => count($result['sources'] ?? [])],
        ]);
        return $this->formatter->ask($dop, $result['answer'] ?? '', $result['sources'] ?? []);
    }

    private function handleDraft(Doppelganger $dop, User $user, string $payload): string
    {
        $situation = $this->stripSubcommand($payload, self::SUBCMD_DRAFT_KEYWORD);
        if ($situation === '') {
            return $this->formatter->error("「起草」需要描述情境。\n例如：`~{$dop->display_name}：write 给客户的延期道歉邮件`");
        }
        $result = $this->voice->draft($dop, $situation);
        $this->logInvocation($dop, $user, DoppelgangerInvocation::LEVEL_VOICE, $situation, $result['draft'] ?? '', [
            'token_input' => (int) ($result['token_input'] ?? 0),
            'token_output' => (int) ($result['token_output'] ?? 0),
            'meta' => ['samples_used' => (int) ($result['samples_used'] ?? 0)],
        ]);
        return $this->formatter->draft($dop, $result['draft'] ?? '');
    }

    private function handleWorkflow(Doppelganger $dop, User $user, string $payload): string
    {
        $name = $this->stripSubcommand($payload, self::SUBCMD_WORKFLOW_KEYWORD);
        $workflows = $this->workflowService->listForDoppelganger($dop);

        if ($workflows->isEmpty()) {
            $this->logInvocation($dop, $user, DoppelgangerInvocation::LEVEL_WORKFLOW, $payload, '（该分身暂无可用工作流）', ['meta' => ['workflows_total' => 0]]);
            return $this->formatter->error("「{$dop->display_name}」目前没有任何可用工作流模板。");
        }

        // 若指定名字，找匹配的；否则列出所有可用 workflow 让用户选
        if ($name !== '') {
            $match = $workflows->first(fn($w) => $w->workflow_name === $name)
                ?? $workflows->first(fn($w) => mb_strpos($w->workflow_name, $name) === 0);
            if ($match) {
                $preview = $this->workflowService->previewWorkflow($match);
                $this->logInvocation($dop, $user, DoppelgangerInvocation::LEVEL_WORKFLOW, "查看工作流：{$match->workflow_name}", $preview['body'] ?? '', ['meta' => ['workflow_id' => $match->id]]);
                return $this->formatter->workflow($dop, $preview);
            }
        }

        // 列出可用 workflow（没指定 / 没匹配上）
        $this->logInvocation($dop, $user, DoppelgangerInvocation::LEVEL_WORKFLOW, $payload, '（列出工作流清单）', ['meta' => ['workflows_total' => $workflows->count()]]);
        return $this->formatter->workflowList($dop, $workflows);
    }

    private function logInvocation(Doppelganger $dop, User $user, int $level, string $query, string $excerpt, array $extra = []): void
    {
        DoppelgangerInvocation::create([
            'doppelganger_id' => $dop->id,
            'caller_user_id'  => $user->id,
            'caller_admin_id' => null,
            'level'           => $level,
            'query'           => mb_substr($query, 0, 1000),
            'response_excerpt'=> mb_substr($excerpt, 0, 500),
            'token_input'     => $extra['token_input'] ?? 0,
            'token_output'    => $extra['token_output'] ?? 0,
            'meta'            => $extra['meta'] ?? null,
        ]);
    }

    private function levelLabel(string $accessLevel): string
    {
        return match ($accessLevel) {
            DoppelgangerGrant::ACCESS_READ_ONLY    => '只读',
            DoppelgangerGrant::ACCESS_USE_VOICE    => '起草',
            DoppelgangerGrant::ACCESS_USE_WORKFLOW => '工作流',
            DoppelgangerGrant::ACCESS_FULL         => '完整',
            default                                 => $accessLevel,
        };
    }
}
