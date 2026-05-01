<?php

namespace App\Services;

use App\Models\MemoryEntry;
use App\Models\MemoryFact;
use App\Models\MemoryRetrievalLog;
use App\Models\MemorySnapshot;
use App\Models\Run;
use App\Models\Setting;
use App\Models\User;
use App\Services\Memory\MemoryKeywordExtractor;
use App\Services\Memory\MemoryLayerPolicy;
use App\Services\Memory\MemoryRecallScorer;
use App\Services\Memory\MemoryTextSanitizer;
use App\Services\Prompt\ContextSanitizer;
use App\Support\MessageTextExtractor;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Throwable;

class MemoryService
{
    private const BASE_DIR = 'app/user_data';
    private const CONTEXT_LIMIT_DEFAULT = 12000;
    private const TRIGGER_RATIO_DEFAULT = 0.82;
    private const KEEP_TAIL_DEFAULT = 10;
    private const L2_RECALL_THRESHOLD = 1.35;
    private const L2_PROMPT_THRESHOLD = 1.55;
    private const L2_PROMPT_LIMIT = 2;

    public function __construct(
        private readonly LlmGatewayService $llmGatewayService,
        private readonly MemoryKeywordExtractor $keywordExtractor,
        private readonly MemoryTextSanitizer $textSanitizer,
        private readonly MemoryLayerPolicy $layerPolicy,
        private readonly MemoryRecallScorer $recallScorer,
    ) {
    }

    /** Optional ContextSanitizer injected lazily (setter avoids ctor breakage). */
    private ?ContextSanitizer $contextSanitizer = null;

    /**
     * Laravel container hook: the framework calls this if the method is typed,
     * but we also expose it for tests to inject a mock.
     */
    public function setContextSanitizer(ContextSanitizer $sanitizer): void
    {
        $this->contextSanitizer = $sanitizer;
    }

    private function contextSanitizer(): ContextSanitizer
    {
        if ($this->contextSanitizer === null) {
            $this->contextSanitizer = function_exists('app')
                ? app(ContextSanitizer::class)
                : new ContextSanitizer();
        }
        return $this->contextSanitizer;
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function prepareMessagesForRun(Run $run, array $messages, array $extra = []): array
    {
        $userId = (int) $run->user_id;
        $sessionKey = $this->sessionKey($run);
        $this->ensureDirs($userId);

        foreach ($messages as $index => $message) {
            $this->appendSessionEvent($run, $sessionKey, 'conversation_message', [
                'index' => $index,
                'role' => $message['role'] ?? 'unknown',
                'content' => $message['content'] ?? '',
            ]);
        }

        $latestUserText = MessageTextExtractor::latestUserText($messages);
        $activeMemory = $this->captureActiveMemory($run, $sessionKey, $latestUserText);
        $context = $this->collectPromptContext($run, $latestUserText);
        $prompts = $this->buildPromptBlocks($context, $extra);

        $working = array_merge($prompts, $messages);
        $before = $this->estimateTokens($working);
        $after = $before;
        $compacted = false;

        $limit = (int) Setting::read('memory.context_token_limit', self::CONTEXT_LIMIT_DEFAULT);
        $limit = max(2000, $limit);
        $trigger = (float) Setting::read('memory.compaction_trigger_ratio', self::TRIGGER_RATIO_DEFAULT);
        $trigger = min(0.95, max(0.5, $trigger));

        if ($before >= (int) floor($limit * $trigger)) {
            $this->flushHook($run, $sessionKey, $messages);
            [$messages, $summary] = $this->compactMessages($messages);
            $working = array_merge($prompts, $messages);
            $after = $this->estimateTokens($working);
            $compacted = true;
            $this->appendSessionEvent($run, $sessionKey, 'context_compaction', [
                'before_tokens' => $before,
                'after_tokens' => $after,
                'summary' => $summary,
            ], true);
        }

        $this->appendSessionEvent($run, $sessionKey, 'context_ready', [
            'token_estimate' => $after,
            'compacted' => $compacted,
            'active_memory' => $activeMemory,
            'guide_files' => array_values(array_filter((array) ($extra['guide_files'] ?? []))),
            'l2_hits' => (int) ($context['l2_hits'] ?? 0),
            'l3_hits' => (int) ($context['l3_hits'] ?? 0),
        ], true);

        return [
            'messages' => $working,
            'session_key' => $sessionKey,
            'compacted' => $compacted,
            'token_estimate' => $after,
            'l2_hits' => (int) ($context['l2_hits'] ?? 0),
            'l3_hits' => (int) ($context['l3_hits'] ?? 0),
            'active_memory' => $activeMemory,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function appendRunEvent(Run $run, string $eventType, ?string $message = null, array $payload = []): void
    {
        $this->appendSessionEvent($run, $this->sessionKey($run), 'run_event', [
            'event_type' => $eventType,
            'message' => $message,
            'payload' => $payload,
        ], in_array($eventType, ['final', 'error', 'tool_start', 'tool_end'], true));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function persist(Run $run, string $answer, array $context = []): void
    {
        $sessionKey = (string) ($context['session_key'] ?? $this->sessionKey($run));
        $this->appendSessionEvent($run, $sessionKey, 'assistant_final', [
            'model' => $context['model'] ?? $run->model,
            'content' => $answer,
        ], true);

        $userText = $this->latestConversationUserText($run);
        $this->storeUserMemorySignals($run, $userText);

        $assistantDecision = $this->layerPolicy->classifyAssistantAnswer($answer, $this->userAliases($run));
        if (($assistantDecision['store_l2'] ?? false) === true) {
            $sanitized = trim((string) ($assistantDecision['sanitized_content'] ?? $this->textSanitizer->summarizeForMemory($answer)));
            if ($sanitized !== '') {
                $entry = $this->appendL2(
                    $run,
                    (string) ($assistantDecision['title'] ?? 'Assistant summary'),
                    $sanitized,
                    (array) ($assistantDecision['tags'] ?? ['source:assistant', 'kind:assistant_summary', 'ttl:5'])
                );

                MemorySnapshot::query()->create([
                    'user_id' => $run->user_id,
                    'run_id' => $run->id,
                    'memory_type' => 'l2',
                    'summary' => 'assistant summary appended',
                    'file_path' => $entry['relative_path'],
                ]);
            }
        }
    }

    public function getMemoryContext(Run $run, string $query = ''): string
    {
        $context = $this->collectPromptContext($run, $query);
        $blocks = $this->buildPromptBlocks($context, []);

        return implode("\n\n", array_values(array_filter(array_map(
            static fn ($block) => trim((string) ($block['content'] ?? '')),
            $blocks
        ))));
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * 系统定时归档：把 proactive scan 蒸馏出来的活动摘要写入 L2 memory。
     * 跟 user/assistant turn 走的 L2 entry 区别仅在 tags（kind:auto_archive）+
     * session_key（auto_archive_<date>），其它流程一致（写文件 + memory_entries）。
     *
     * @return array{entry_id:int, skipped:?string}
     */
    public function archiveProactiveSummary(int $userId, string $title, string $content): array
    {
        $this->ensureDirs($userId);

        $date = now('Asia/Shanghai')->format('Y-m-d');
        $relative = "user_data/{$userId}/memory/l2/{$date}.md";
        $path = storage_path("app/{$relative}");
        $hash = sha1($content);

        // 防重：同一天已有相同 content_hash 的归档，跳过
        $existing = MemoryEntry::query()
            ->where('user_id', $userId)
            ->where('layer', 'L2')
            ->where('content_hash', $hash)
            ->where('source_date', $date)
            ->first();
        if ($existing) {
            return ['entry_id' => (int) $existing->id, 'skipped' => 'duplicate_hash'];
        }

        $tags = ['source:proactive_collect', 'kind:auto_archive', 'ttl:7'];
        $block = "## [".now('Asia/Shanghai')->format('Y-m-d H:i:s')."] {$title}\n"
            ."- 归档来源: 系统自动扫描\n"
            ."- 标签: ".implode(', ', $tags)."\n\n"
            ."{$content}\n\n---\n\n";

        try {
            File::append($path, $block);
        } catch (\Throwable $e) {
            // ensureDirs 应该已经建好父目录；万一 append 失败也不影响 DB 持久化
            \Illuminate\Support\Facades\Log::warning('[MemoryService] archive append failed', [
                'user_id' => $userId, 'path' => $path, 'error' => $e->getMessage(),
            ]);
        }

        $entry = MemoryEntry::query()->create([
            'user_id' => $userId,
            'run_id' => null,
            'layer' => 'L2',
            'session_key' => 'auto_archive_'.$date,
            'source_file' => $relative,
            'source_date' => $date,
            'title' => $title,
            'summary' => $this->truncate($content, 220),
            'content' => $content,
            'tags' => $tags,
            'keywords' => [],
            'embedding_source_text' => $content,
            'embedding_vector' => null,
            'embedding_model' => null,
            'content_hash' => $hash,
            'expired_at' => null,
            'expire_reason' => null,
        ]);

        return ['entry_id' => (int) $entry->id, 'skipped' => null];
    }

    public function distillUserL3Memory(int $userId): array
    {
        return $this->repairUserMemory($userId);
    }

    /**
     * @return array<string, mixed>
     */
    public function cleanupUserMemory(int $userId): array
    {
        $this->ensureDirs($userId);

        $expiredCount = 0;
        $checkedCount = 0;

        MemoryEntry::query()
            ->where('user_id', $userId)
            ->where('layer', 'L2')
            ->whereNull('expired_at')
            ->orderBy('id')
            ->chunkById(200, function (Collection $entries) use (&$checkedCount, &$expiredCount): void {
                foreach ($entries as $entry) {
                    if (! $entry instanceof MemoryEntry) {
                        continue;
                    }

                    $checkedCount++;
                    if (! $this->isEntryExpired($entry)) {
                        continue;
                    }

                    $entry->expired_at = now();
                    $entry->expire_reason = 'ttl_elapsed';
                    $entry->save();
                    $expiredCount++;
                }
            });

        return [
            'user_id' => $userId,
            'checked_count' => $checkedCount,
            'expired_count' => $expiredCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function repairUserMemory(int $userId, bool $promoteRecalledEntries = true): array
    {
        $this->ensureDirs($userId);
        $cleanup = $this->cleanupUserMemory($userId);

        $facts = MemoryFact::query()
            ->where('user_id', $userId)
            ->orderByDesc('priority')
            ->orderByDesc('updated_at')
            ->get();

        $deactivated = 0;
        $updated = 0;
        $reactivated = 0;
        $reasonCounts = [];
        $actionCounts = [];
        $samples = [];

        foreach ($facts as $fact) {
            $meta = is_array($fact->meta) ? $fact->meta : [];
            $review = $this->layerPolicy->reviewFact((string) $fact->fact, (string) $fact->category, [
                'priority' => (int) $fact->priority,
                ...$meta,
            ]);

            if (($review['allow'] ?? false) !== true) {
                $reason = (string) ($review['reason'] ?? 'rejected');
                $policy = $this->rejectionPolicyForReason($reason);
                $action = (string) ($policy['action'] ?? 'soft_deactivated');

                $reasonCounts[$reason] = (int) ($reasonCounts[$reason] ?? 0) + 1;
                $actionCounts[$action] = (int) ($actionCounts[$action] ?? 0) + 1;

                if (count($samples) < 12) {
                    $samples[] = [
                        'id' => (int) $fact->id,
                        'fact' => (string) $fact->fact,
                        'reason' => $reason,
                        'action' => $action,
                    ];
                }

                $nextMeta = $this->mergeFactMeta($meta, [
                    'review_reason' => $reason,
                    'review_action' => $action,
                    'review_bucket' => (string) ($policy['bucket'] ?? 'soft'),
                    'reviewed_at' => now()->toIso8601String(),
                    'soft_deactivated' => $action === 'soft_deactivated',
                    'garbage' => $action === 'garbage',
                ]);

                $wasActive = (bool) $fact->is_active;
                $needsSave = $wasActive || $nextMeta !== $meta;
                if ($needsSave) {
                    $fact->is_active = false;
                    $fact->meta = $nextMeta;
                    $fact->save();
                }

                if ($wasActive) {
                    $deactivated++;
                }

                continue;
            }

            $newCategory = (string) ($review['category'] ?? $fact->category);
            $newPriority = (int) ($review['priority'] ?? $fact->priority);
            // Reactivate 白名单：仅"被规则正常软停用"的 fact 允许复活；
            // legacy_purge / replaced_by_newer / category_unique_constraint / garbage / deactivated 等
            // 都属于"管理员/系统主动判定不该再活"的标记，不应被 reviewFact 自动复活。
            $shouldReactivate = ! $fact->is_active
                && in_array((string) ($meta['review_action'] ?? ''), ['soft_deactivated', 'reactivated'], true);
            $newMeta = $this->mergeFactMeta($meta, [
                'review_reason' => null,
                'review_action' => $shouldReactivate ? 'reactivated' : 'kept',
                'reviewed_at' => now()->toIso8601String(),
                'review_bucket' => 'kept',
                'soft_deactivated' => false,
                'garbage' => false,
            ]);

            if ($shouldReactivate) {
                $fact->is_active = true;
                $reactivated++;
            }

            if ($fact->category !== $newCategory || (int) $fact->priority !== $newPriority || $newMeta !== $meta || $shouldReactivate) {
                $fact->category = $newCategory;
                $fact->priority = $newPriority;
                $fact->meta = $newMeta;
                $fact->save();
                $updated++;
            }
        }

        $promoted = $promoteRecalledEntries ? $this->promoteRecalledEntriesToFacts($userId) : 0;

        // 收尾：执行 L3 类别唯一性约束（同 user 同 category 只留最新 created_at 的 active）
        // 否则 repair 会把之前因唯一性被软停用的 fact 按 reviewFact 重新激活，
        // 导致同 category 出现多条互相矛盾的 active。
        $this->enforceCategoryUniquenessForUser($userId);

        $activeFacts = $this->topDurableFacts($userId, 120);
        $this->writeL3($userId, $activeFacts);

        return [
            'user_id' => $userId,
            'fact_count' => $activeFacts->count(),
            'deactivated_count' => $deactivated,
            'updated_count' => $updated,
            'reactivated_count' => $reactivated,
            'promoted_count' => $promoted,
            'reason_counts' => $reasonCounts,
            'action_counts' => $actionCounts,
            'cleanup' => $cleanup,
            'sample_rejections' => $samples,
            'path' => $this->l3Path($userId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserMemoryViewData(int $userId, ?string $sessionKey = null, ?string $l2Date = null): array
    {
        $this->ensureDirs($userId);
        $user = User::query()->with('department')->find($userId);
        $sessions = $this->listSessions($userId);
        $selectedSession = $sessionKey ?: ($sessions[0]['session_key'] ?? null);
        $sessionEvents = $selectedSession ? $this->readSession($userId, $selectedSession, 200) : [];

        $l2Files = $this->listL2($userId);
        $selectedL2 = $l2Date ?: ($l2Files[0]['date'] ?? null);
        $selectedL2Content = $selectedL2 ? $this->readL2($userId, $selectedL2) : '';
        $activeFacts = MemoryFact::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderByDesc('updated_at')
            ->get();
        $auditRows = $activeFacts
            ->map(function (MemoryFact $fact) {
                $meta = is_array($fact->meta) ? $fact->meta : [];
                $review = $this->layerPolicy->reviewFact((string) $fact->fact, (string) $fact->category, [
                    'priority' => (int) $fact->priority,
                    ...$meta,
                ]);

                return [
                    'fact' => $fact,
                    'review' => $review,
                ];
            })
            ->values();
        $inactiveFacts = MemoryFact::query()
            ->where('user_id', $userId)
            ->where('is_active', false)
            ->orderByDesc('updated_at')
            ->limit(40)
            ->get();
        $retrievalLogs = MemoryRetrievalLog::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(30)
            ->get();
        $factSignals = $activeFacts
            ->filter(function (MemoryFact $fact) {
                $meta = is_array($fact->meta) ? $fact->meta : [];

                return (int) ($meta['recall_count'] ?? 0) > 0 || (int) ($meta['unique_query_count'] ?? 0) > 0;
            })
            ->map(function (MemoryFact $fact) {
                $meta = is_array($fact->meta) ? $fact->meta : [];

                return [
                    'fact' => $fact,
                    'recall_count' => (int) ($meta['recall_count'] ?? 0),
                    'unique_query_count' => (int) ($meta['unique_query_count'] ?? 0),
                    'last_recalled_at' => $meta['last_recalled_at'] ?? null,
                ];
            })
            ->sortByDesc('recall_count')
            ->values();
        $recentEntries = MemoryEntry::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(80)
            ->get();
        $flaggedCount = $auditRows->filter(fn (array $row) => (($row['review']['allow'] ?? false) !== true))->count();

        return [
            'user' => $user,
            'user_context_lines' => $this->buildUserContextLinesForUser($user),
            'sessions' => $sessions,
            'selected_session' => $selectedSession,
            'selected_session_events' => $sessionEvents,
            'l2_files' => $l2Files,
            'selected_l2_date' => $selectedL2,
            'selected_l2_content' => $selectedL2Content,
            'paths' => [
                'sessions_dir' => $this->userDir($userId).'/sessions',
                'l2_dir' => $this->userDir($userId).'/memory/l2',
                'l3_path' => $this->l3Path($userId),
            ],
            'l3_path' => $this->l3Path($userId),
            'l3_content' => $this->readL3($userId),
            'l3_facts' => $activeFacts,
            'l3_audit_rows' => $auditRows,
            'l3_recent_inactive_facts' => $inactiveFacts,
            'l4_logs' => $retrievalLogs,
            'l4_fact_signals' => $factSignals,
            'health' => [
                'active_fact_count' => $activeFacts->count(),
                'flagged_active_fact_count' => $flaggedCount,
                'inactive_fact_count' => MemoryFact::query()->where('user_id', $userId)->where('is_active', false)->count(),
                'retrieval_log_count' => MemoryRetrievalLog::query()->where('user_id', $userId)->count(),
                'expired_l2_count' => MemoryEntry::query()->where('user_id', $userId)->where('layer', 'L2')->whereNotNull('expired_at')->count(),
                'distinct_recent_queries' => $retrievalLogs
                    ->map(fn (MemoryRetrievalLog $log) => trim((string) $log->query_text))
                    ->filter()
                    ->unique()
                    ->count(),
                'l2_file_count' => count($l2Files),
                'session_count' => count($sessions),
            ],
            'recent_entries' => $recentEntries,
            'today_summary' => $this->buildTodaySummary($userId),
        ];
    }

    /**
     * 后台"记忆中心"首屏三卡 + Top 3 recalled 的聚合数据。
     *
     * 只读查询，不写入 memory_facts/memory_entries/memory_retrieval_logs。
     * MEMORY_BOUNDARY 允许后台 UI 改动，前提是不改 Service 已冻结签名、
     * 不改字段语义。此方法是 getUserMemoryViewData 的子集，供统一入口使用。
     *
     * @return array{
     *     active_fact_total:int,
     *     recent_new_by_layer:array<string,int>,
     *     recent_new_total:int,
     *     top_recalled:list<array{id:int,fact:string,category:string,hit_count:int}>,
     *     last_retrieval_at:\Illuminate\Support\Carbon|\Carbon\Carbon|null
     * }
     */
    private function buildTodaySummary(int $userId): array
    {
        $now = Carbon::now();
        $since3d = $now->copy()->subDays(3);
        $since7d = $now->copy()->subDays(7);

        $activeFactTotal = MemoryFact::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->count();

        $recentNewByLayer = MemoryEntry::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', $since3d)
            ->selectRaw('layer, COUNT(*) as cnt')
            ->groupBy('layer')
            ->pluck('cnt', 'layer')
            ->map(fn ($v) => (int) $v)
            ->toArray();

        $logs = MemoryRetrievalLog::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', $since7d)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['retrieved_l3_fact_ids', 'created_at']);

        $lastRetrievalAt = $logs->first()?->created_at;

        $factHitCounts = [];
        foreach ($logs as $log) {
            $ids = is_array($log->retrieved_l3_fact_ids) ? $log->retrieved_l3_fact_ids : [];
            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id <= 0) {
                    continue;
                }
                $factHitCounts[$id] = ($factHitCounts[$id] ?? 0) + 1;
            }
        }
        arsort($factHitCounts);
        $topIds = array_slice(array_keys($factHitCounts), 0, 3);

        $topRecalled = [];
        if (! empty($topIds)) {
            $facts = MemoryFact::query()
                ->whereIn('id', $topIds)
                ->get(['id', 'fact', 'category'])
                ->keyBy('id');
            foreach ($topIds as $fid) {
                $fact = $facts->get($fid);
                if (! $fact) {
                    continue;
                }
                $topRecalled[] = [
                    'id' => (int) $fact->id,
                    'fact' => (string) $fact->fact,
                    'category' => (string) $fact->category,
                    'hit_count' => (int) ($factHitCounts[$fid] ?? 0),
                ];
            }
        }

        return [
            'active_fact_total' => (int) $activeFactTotal,
            'recent_new_by_layer' => $recentNewByLayer,
            'recent_new_total' => array_sum($recentNewByLayer),
            'top_recalled' => $topRecalled,
            'last_retrieval_at' => $lastRetrievalAt,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function flushHook(Run $run, string $sessionKey, array $messages): void
    {
        $transcript = $this->truncate($this->toTranscript($messages), 9000);
        if ($transcript === '') {
            return;
        }

        $content = '';
        try {
            $response = $this->llmGatewayService->chat([
                [
                    'role' => 'system',
                    'content' => 'You are MiFrog memory distiller. Output strict JSON only with the format {"l2":["recent episodic items"],"l3":["durable long-term user facts"]}. l3 may only contain stable user identity, preference, style, constraint, work context, or project anchors. Never put task instructions, one-off operations, tool steps, execution results, urls, ids, tokens, payload strings, or transient details into l3.',
                ],
                [
                    'role' => 'user',
                    'content' => "Distill the conversation below into retrievable recent context (l2) and extremely conservative durable memory (l3).\nConversation:\n".$transcript,
                ],
            ]);
            $content = (string) ($response['content'] ?? '');
        } catch (Throwable) {
            $content = '';
        }

        $json = $this->extractJson($content);
        $decoded = is_string($json) ? json_decode($json, true) : null;
        $l2 = is_array($decoded) ? (array) ($decoded['l2'] ?? []) : [];
        $l3 = is_array($decoded) ? (array) ($decoded['l3'] ?? []) : [];

        if ($l2 === []) {
            $latest = MessageTextExtractor::latestUserText($messages);
            if ($latest !== '') {
                $l2[] = $latest;
            }
        }

        $l2Count = 0;
        foreach ($l2 as $item) {
            $text = trim((string) $item);
            if ($text === '') {
                continue;
            }

            $decision = $this->layerPolicy->classifyUserText($text);
            if (($decision['store_l2'] ?? false) !== true) {
                continue;
            }

            $this->appendL2(
                $run,
                (string) ($decision['title'] ?? 'Recent context'),
                $text,
                array_merge((array) ($decision['tags'] ?? []), ['source:flush_hook'])
            );
            $l2Count++;
        }

        $l3Count = 0;
        foreach ($l3 as $item) {
            $fact = trim((string) $item);
            if ($fact === '') {
                continue;
            }

            $decision = $this->layerPolicy->classifyUserText($fact, false);
            if (($decision['promote_l3'] ?? false) !== true || $this->looksLikeSensitiveMemoryPayload($fact)) {
                continue;
            }

            $review = $this->layerPolicy->reviewFact(
                $fact,
                (string) ($decision['category'] ?? 'constraint'),
                ['priority' => (int) ($decision['priority'] ?? 86)]
            );
            if (($review['allow'] ?? false) !== true) {
                continue;
            }
            $row = $this->storeL3Fact(
                (int) $run->user_id,
                (int) $run->id,
                $fact,
                (string) ($review['category'] ?? 'constraint'),
                (int) ($review['priority'] ?? 86),
                null,
                [
                    'source' => 'flush_hook',
                    'source_role' => 'system',
                ]
            );
            if ($row !== null) {
                $l3Count++;
            }
        }

        $this->appendSessionEvent($run, $sessionKey, 'pre_compaction_flush', [
            'l2_count' => $l2Count,
            'l3_count' => $l3Count,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function captureActiveMemory(Run $run, string $sessionKey, string $userText): array
    {
        if (! preg_match('/^(?:(?:请|帮我|你)?(?:记住|记一下|记下来|记好了?))\s*[，,：:。.、\s]*(.+)$/u', $userText, $matches)) {
            return ['triggered' => false];
        }

        $text = trim((string) ($matches[1] ?? ''));
        if ($text === '') {
            return ['triggered' => false];
        }

        $decision = $this->layerPolicy->classifyUserText($text, true);
        if (($decision['store_l2'] ?? false) !== true) {
            return ['triggered' => false];
        }

        $entry = $this->appendL2(
            $run,
            (string) ($decision['title'] ?? 'Active memory'),
            $text,
            array_merge((array) ($decision['tags'] ?? []), ['source:active_memory'])
        );

        $factId = null;
        if (($decision['promote_l3'] ?? false) === true) {
            $fact = $this->storeL3Fact(
                (int) $run->user_id,
                (int) $run->id,
                $text,
                (string) ($decision['category'] ?? 'preference'),
                (int) ($decision['priority'] ?? 95),
                (int) ($entry['entry_id'] ?? 0),
                [
                    'source' => 'active_memory',
                    'source_role' => 'user',
                ]
            );
            $factId = $fact?->id;
        }

        $this->appendSessionEvent($run, $sessionKey, 'active_memory_write', [
            'l2_entry_id' => $entry['entry_id'] ?? null,
            'l3_fact_id' => $factId,
            'content' => $text,
        ], true);

        return [
            'triggered' => true,
            'l2_entry_id' => $entry['entry_id'] ?? null,
            'l3_fact_id' => $factId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectPromptContext(Run $run, string $query): array
    {
        $baseFacts = $this->topDurableFacts((int) $run->user_id, 6);
        $retrieved = $query !== '' ? $this->retrieveContext($run, $query) : [
            'l3' => collect(),
            'l2' => collect(),
            'query_keywords' => [],
        ];

        $facts = $baseFacts
            ->concat($retrieved['l3'] instanceof Collection ? $retrieved['l3'] : collect($retrieved['l3'] ?? []))
            ->unique(fn (MemoryFact $fact) => (int) $fact->id)
            ->take(8)
            ->values();

        return [
            'user_context_lines' => $this->buildUserContextLines($run),
            'facts' => $facts,
            'entries' => $retrieved['l2'] instanceof Collection ? $retrieved['l2'] : collect($retrieved['l2'] ?? []),
            'query_keywords' => (array) ($retrieved['query_keywords'] ?? []),
            'l2_hits' => (int) ($retrieved['l2'] instanceof Collection ? $retrieved['l2']->count() : count((array) ($retrieved['l2'] ?? []))),
            'l3_hits' => (int) ($retrieved['l3'] instanceof Collection ? $retrieved['l3']->count() : count((array) ($retrieved['l3'] ?? []))),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extra
     * @return array<int, array<string, string>>
     */
    private function buildPromptBlocks(array $context, array $extra = []): array
    {
        $blocks = [];

        $userContextLines = array_values(array_filter((array) ($context['user_context_lines'] ?? [])));
        if ($userContextLines !== []) {
            $blocks[] = [
                'role' => 'system',
                'content' => "[Current user context]\n".implode("\n", $userContextLines),
            ];
        }

        $facts = $context['facts'] instanceof Collection ? $context['facts'] : collect($context['facts'] ?? []);
        if ($facts->isNotEmpty()) {
            $lines = [];
            foreach ($facts as $fact) {
                if (! $fact instanceof MemoryFact) {
                    continue;
                }
                $lines[] = '- ['.$this->factCategoryLabel((string) $fact->category).'] '.$fact->fact;
            }

            if ($lines !== []) {
                $blocks[] = [
                    'role' => 'system',
                    'content' => "[Long-term stable memory]\n".implode("\n", $lines),
                ];
            }
        }

        $entries = $context['entries'] instanceof Collection ? $context['entries'] : collect($context['entries'] ?? []);
        $promptEntries = $entries
            ->filter(fn ($entry) => $entry instanceof MemoryEntry && (float) $entry->getAttribute('score') >= self::L2_PROMPT_THRESHOLD)
            ->take(self::L2_PROMPT_LIMIT);
        if ($promptEntries->isNotEmpty()) {
            $lines = [];
            foreach ($promptEntries as $entry) {
                if (! $entry instanceof MemoryEntry) {
                    continue;
                }

                $summary = trim((string) ($entry->summary ?: $entry->content));
                if ($summary === '') {
                    continue;
                }

                $dateLabel = $entry->source_date?->format('Y-m-d') ?? optional($entry->created_at)->format('Y-m-d');
                $lines[] = '- ['.$dateLabel.'] '.$this->truncate($summary, 160);
            }

            if ($lines !== []) {
                $blocks[] = [
                    'role' => 'system',
                    'content' => "[Related prior context]\n".implode("\n", $lines),
                ];
            }
        }

        $guideFiles = array_values(array_filter((array) ($extra['guide_files'] ?? [])));
        if ($guideFiles !== []) {
            $blocks[] = [
                'role' => 'system',
                'content' => "[Loaded guide files]\n- ".implode("\n- ", $guideFiles),
            ];
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    private function retrieveContext(Run $run, string $query): array
    {
        $queryKeywords = $this->keywords($query);
        if ($queryKeywords === []) {
            return [
                'l3' => collect(),
                'l2' => collect(),
                'query_keywords' => [],
            ];
        }

        $facts = MemoryFact::query()
            ->where('user_id', $run->user_id)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderByDesc('updated_at')
            ->limit(160)
            ->get()
            ->map(function (MemoryFact $fact) use ($query, $queryKeywords) {
                $review = $this->layerPolicy->reviewFact((string) $fact->fact, (string) $fact->category, [
                    'priority' => (int) $fact->priority,
                    ...(is_array($fact->meta) ? $fact->meta : []),
                ]);

                if (($review['allow'] ?? false) !== true) {
                    return null;
                }

                $meta = is_array($fact->meta) ? $fact->meta : [];
                $score = $this->recallScorer->scoreFact(
                    $query,
                    $queryKeywords,
                    (string) $fact->fact,
                    (string) ($review['category'] ?? $fact->category),
                    (int) ($review['priority'] ?? $fact->priority),
                    $meta,
                    (string) $fact->updated_at
                );
                $fact->setAttribute('score', $score);

                return $fact;
            })
            ->filter()
            ->filter(fn (MemoryFact $fact) => (float) $fact->getAttribute('score') >= 0.9)
            ->sortByDesc(fn (MemoryFact $fact) => (float) $fact->getAttribute('score'))
            ->take(10)
            ->values();

        $entries = MemoryEntry::query()
            ->where('user_id', $run->user_id)
            ->where('layer', 'L2')
            ->whereNull('expired_at')
            ->whereDate('created_at', '>=', now()->subDays(365)->toDateString())
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->filter(fn (MemoryEntry $entry) => $this->shouldUseEntryInPrompt($entry))
            ->map(function (MemoryEntry $entry) use ($query, $queryKeywords) {
                $content = trim((string) ($entry->summary ?: $entry->content));
                $score = $this->recallScorer->scoreEntry(
                    $query,
                    $queryKeywords,
                    $content,
                    is_array($entry->keywords) ? $entry->keywords : [],
                    is_array($entry->tags) ? $entry->tags : [],
                    (string) $entry->created_at
                );
                $entry->setAttribute('score', $score);

                return $entry;
            })
            ->filter(fn (MemoryEntry $entry) => (float) $entry->getAttribute('score') >= self::L2_RECALL_THRESHOLD)
            ->sortByDesc(fn (MemoryEntry $entry) => (float) $entry->getAttribute('score'))
            ->take(4)
            ->values();

        $queryHash = sha1(implode('|', $queryKeywords));
        $this->recordFactRecallSignals($facts, $queryHash);

        MemoryRetrievalLog::query()->create([
            'user_id' => $run->user_id,
            'run_id' => $run->id,
            'query_text' => $query,
            'retrieved_l3_fact_ids' => $facts->pluck('id')->all(),
            'retrieved_l2_entry_ids' => $entries->pluck('id')->all(),
            'meta' => [
                'keywords' => $queryKeywords,
                'query_hash' => $queryHash,
                'fact_scores' => $facts->mapWithKeys(fn (MemoryFact $fact) => [(string) $fact->id => (float) $fact->getAttribute('score')])->all(),
                'entry_scores' => $entries->mapWithKeys(fn (MemoryEntry $entry) => [(string) $entry->id => (float) $entry->getAttribute('score')])->all(),
            ],
        ]);

        return [
            'l3' => $facts,
            'l2' => $entries,
            'query_keywords' => $queryKeywords,
        ];
    }

    /**
     * @param  Collection<int, MemoryFact>  $facts
     */
    private function recordFactRecallSignals(Collection $facts, string $queryHash): void
    {
        foreach ($facts as $fact) {
            $meta = is_array($fact->meta) ? $fact->meta : [];
            $queryHashes = array_values(array_unique(array_filter(array_map(
                static fn ($hash) => trim((string) $hash),
                (array) ($meta['query_hashes'] ?? [])
            ))));

            $queryHashes[] = $queryHash;
            $queryHashes = array_slice(array_values(array_unique($queryHashes)), -20);

            $meta['recall_count'] = (int) ($meta['recall_count'] ?? 0) + 1;
            $meta['last_recalled_at'] = now()->toIso8601String();
            $meta['query_hashes'] = $queryHashes;
            $meta['unique_query_count'] = count($queryHashes);
            unset($fact['score']);
            $fact->meta = $meta;
            $fact->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function appendL2(Run $run, string $title, string $content, array $tags): array
    {
        $content = trim($content);
        if ($content === '') {
            return ['entry_id' => null, 'relative_path' => $this->relativePath($this->l2Path((int) $run->user_id, now()->format('Y-m-d')))];
        }

        $date = now()->format('Y-m-d');
        $path = $this->l2Path((int) $run->user_id, $date);
        $relative = $this->relativePath($path);
        $keywords = $this->keywords($content);
        $tags = array_values(array_unique(array_filter(array_map(
            static fn ($tag) => trim((string) $tag),
            $tags
        ))));
        $hash = sha1(mb_strtolower(trim($content), 'UTF-8'));

        $existing = MemoryEntry::query()
            ->where('user_id', $run->user_id)
            ->where('layer', 'L2')
            ->where('content_hash', $hash)
            ->whereNull('expired_at')
            ->where('created_at', '>=', now()->subHours(36))
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            return [
                'entry_id' => (int) $existing->id,
                'relative_path' => (string) $existing->source_file,
            ];
        }

        $block = "## [".now()->format('Y-m-d H:i:s')."] {$title}\n"
            ."- 关联运行: run#{$run->id}\n"
            ."- 标签: ".implode(', ', $tags)."\n"
            ."- 关键词: ".implode(', ', $keywords)."\n"
            ."- 原文:\n{$content}\n\n---\n\n";
        File::append($path, $block);

        $entry = MemoryEntry::query()->create([
            'user_id' => $run->user_id,
            'run_id' => $run->id,
            'layer' => 'L2',
            'session_key' => $this->sessionKey($run),
            'source_file' => $relative,
            'source_date' => $date,
            'title' => $title,
            'summary' => $this->truncate($content, 220),
            'content' => $content,
            'tags' => $tags,
            'keywords' => $keywords,
            'embedding_source_text' => $content,
            'embedding_vector' => null,
            'embedding_model' => null,
            'content_hash' => $hash,
            'expired_at' => null,
            'expire_reason' => null,
        ]);

        return [
            'entry_id' => (int) $entry->id,
            'relative_path' => $relative,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function storeL3Fact(
        int $userId,
        ?int $runId,
        string $fact,
        string $category,
        int $priority,
        ?int $sourceEntryId = null,
        array $meta = []
    ): ?MemoryFact {
        $review = $this->layerPolicy->reviewFact($fact, $category, [
            'priority' => $priority,
            ...$meta,
        ]);
        if (($review['allow'] ?? false) !== true) {
            return null;
        }

        $fact = trim($fact);
        if ($fact === '') {
            return null;
        }

        $hash = sha1(mb_strtolower($fact, 'UTF-8'));
        $category = (string) ($review['category'] ?? $category);
        $priority = (int) ($review['priority'] ?? $priority);

        $row = MemoryFact::query()
            ->where('user_id', $userId)
            ->where('fact_hash', $hash)
            ->first();

        if ($row !== null) {
            $row->priority = max((int) $row->priority, $priority);
            $row->category = $category;
            $row->source_entry_id = $sourceEntryId ?: $row->source_entry_id;
            $row->last_run_id = $runId ?: $row->last_run_id;
            $row->is_active = true;
            $row->meta = $this->mergeFactMeta(is_array($row->meta) ? $row->meta : [], $meta);
            $row->save();
        } else {
            $row = MemoryFact::query()->create([
                'user_id' => $userId,
                'source_entry_id' => $sourceEntryId,
                'last_run_id' => $runId,
                'category' => $category,
                'fact' => $fact,
                'fact_hash' => $hash,
                'priority' => $priority,
                'is_active' => true,
                'meta' => $meta,
            ]);

            MemoryEntry::query()->create([
                'user_id' => $userId,
                'run_id' => $runId,
                'layer' => 'L3',
                'session_key' => null,
                'source_file' => $this->relativePath($this->l3Path($userId)),
                'source_date' => now()->toDateString(),
                'title' => $category,
                'summary' => $this->truncate($fact, 220),
                'content' => $fact,
                'tags' => array_values(array_unique(array_filter([
                    'L3',
                    $category,
                    isset($meta['source_role']) ? 'source:'.$meta['source_role'] : null,
                ]))),
                'keywords' => $this->keywords($fact),
                'embedding_source_text' => $fact,
                'embedding_vector' => null,
                'embedding_model' => null,
                'content_hash' => $hash,
            ]);
        }

        // L3 类别唯一性约束（每 user 每 category 只保留最新 created_at 的 active）
        $this->enforceCategoryUniquenessForUser($userId);

        $this->writeL3($userId, $this->topDurableFacts($userId, 120));

        return $row->fresh();
    }

    /**
     * 按"每 user 每 category 只保留 1 条 active"约束清理。
     * 取每组 created_at 最新的留下，其他软停用并标记 meta.review_reason
     * = 'category_unique_constraint'。
     *
     * 调用点：storeL3Fact 写入后、repairUserMemory 重审后（保证 repair
     * 不会把因唯一性被停用的旧 fact 重新激活成冲突状态）。
     */
    private function enforceCategoryUniquenessForUser(int $userId): void
    {
        // 取每 (user, category) 的最大 created_at
        $latestPerCategory = MemoryFact::query()
            ->selectRaw('category, MAX(created_at) AS max_ct')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->groupBy('category')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->category => (string) $row->max_ct])
            ->all();

        if ($latestPerCategory === []) {
            return;
        }

        $reviewedAt = now()->toIso8601String();
        $stale = MemoryFact::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->get()
            ->filter(function (MemoryFact $fact) use ($latestPerCategory): bool {
                $maxCt = $latestPerCategory[(string) $fact->category] ?? null;
                if ($maxCt === null) {
                    return false;
                }
                // created_at 比组内最大严格小 → 是 stale
                return (string) $fact->created_at < $maxCt;
            });

        foreach ($stale as $fact) {
            $fact->is_active = false;
            $fact->meta = $this->mergeFactMeta(
                is_array($fact->meta) ? $fact->meta : [],
                [
                    'review_action' => 'replaced_by_newer',
                    'review_reason' => 'category_unique_constraint',
                    'reviewed_at' => $reviewedAt,
                    'soft_deactivated' => true,
                ]
            );
            $fact->save();
        }
    }

    /**
     * @param  Collection<int, MemoryFact>  $facts
     */
    private function writeL3(int $userId, Collection $facts): void
    {
        $this->ensureDirs($userId);
        $path = $this->l3Path($userId);
        $old = File::exists($path) ? (string) File::get($path) : '';

        $curated = $facts
            ->filter(function (MemoryFact $fact) {
                $review = $this->layerPolicy->reviewFact((string) $fact->fact, (string) $fact->category, [
                    'priority' => (int) $fact->priority,
                    ...(is_array($fact->meta) ? $fact->meta : []),
                ]);

                return ($review['allow'] ?? false) === true;
            })
            ->sortByDesc('priority')
            ->sortByDesc('updated_at')
            ->values();

        $lines = ['# Long-Term Memory (L3)', '', 'Updated At: '.now()->format('Y-m-d H:i:s'), ''];
        foreach ($curated->groupBy(fn (MemoryFact $fact) => (string) $fact->category) as $category => $rows) {
            $lines[] = '## '.$this->factCategoryLabel($category);
            foreach ($rows as $row) {
                $lines[] = '- '.$row->fact;
            }
            $lines[] = '';
        }
        $new = implode("\n", $lines);

        if ($old !== '' && trim($old) !== trim($new)) {
            $version = $this->userDir($userId).DIRECTORY_SEPARATOR.'memory'.DIRECTORY_SEPARATOR.'l3'.DIRECTORY_SEPARATOR.'versions'.DIRECTORY_SEPARATOR.now()->format('Ymd_His').'.md';
            File::put($version, $old);
        }

        File::put($path, $new);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array{0: array<int, array<string, string>>, 1: string}
     */
    private function compactMessages(array $messages): array
    {
        $keep = (int) Setting::read('memory.keep_tail_messages', self::KEEP_TAIL_DEFAULT);
        $keep = max(6, $keep);
        if (count($messages) <= $keep + 2) {
            return [$messages, ''];
        }

        $head = array_slice($messages, 0, -$keep);
        $tail = array_slice($messages, -$keep);
        $summary = '';

        try {
            $response = $this->llmGatewayService->chat([
                ['role' => 'system', 'content' => 'Summarize the earlier conversation into key facts, constraints, decisions, and pending actions. Avoid filler.'],
                ['role' => 'user', 'content' => $this->truncate($this->toTranscript($head), 8500)],
            ]);
            $summary = trim((string) ($response['content'] ?? ''));
        } catch (Throwable) {
            $summary = '';
        }

        if ($summary === '') {
            $summary = $this->truncate($this->toTranscript($head), 1200);
        }

        return [[
            ['role' => 'system', 'content' => "[Compressed history summary]\n".$summary],
            ...$tail,
        ], $summary];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function appendSessionEvent(Run $run, string $sessionKey, string $eventType, array $payload, bool $index = false): void
    {
        $this->ensureDirs((int) $run->user_id);
        $path = $this->sessionPath((int) $run->user_id, $sessionKey);
        File::append($path, json_encode([
            'ts' => now()->toIso8601String(),
            'user_id' => $run->user_id,
            'conversation_id' => $run->conversation_id,
            'run_id' => $run->id,
            'event_type' => $eventType,
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE).PHP_EOL);

        if (! $index) {
            return;
        }

        $content = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $summarySource = $payload['message'] ?? ($payload['content'] ?? ($payload['summary'] ?? $eventType));
        $summary = $this->truncate((string) $summarySource, 220);

        MemoryEntry::query()->create([
            'user_id' => $run->user_id,
            'run_id' => $run->id,
            'layer' => 'L1',
            'session_key' => $sessionKey,
            'source_file' => $this->relativePath($path),
            'source_date' => now()->toDateString(),
            'title' => $eventType,
            'summary' => $summary,
            'content' => is_string($content) ? $content : '',
            'tags' => ['L1', $eventType],
            'keywords' => $this->keywords($summary),
            'embedding_source_text' => $summary,
            'embedding_vector' => null,
            'embedding_model' => null,
            'content_hash' => sha1(mb_strtolower(trim((string) $summary), 'UTF-8')),
        ]);
    }

    private function ensureDirs(int $userId): void
    {
        $base = $this->userDir($userId);
        foreach ([
            $base,
            $base.'/sessions',
            $base.'/memory',
            $base.'/memory/l2',
            $base.'/memory/l3',
            $base.'/memory/l3/versions',
        ] as $dir) {
            if (! File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listSessions(int $userId): array
    {
        $dir = $this->userDir($userId).'/sessions';
        if (! File::isDirectory($dir)) {
            return [];
        }

        return collect(File::files($dir))
            ->filter(fn ($file) => str_ends_with($file->getFilename(), '.jsonl'))
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->map(fn ($file) => [
                'session_key' => pathinfo($file->getFilename(), PATHINFO_FILENAME),
                'path' => $file->getPathname(),
                'size' => $file->getSize(),
                'updated_at' => \Carbon\CarbonImmutable::createFromTimestamp($file->getMTime())->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readSession(int $userId, string $sessionKey, int $max): array
    {
        $path = $this->sessionPath($userId, $sessionKey);
        if (! File::exists($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $tail = array_slice($lines, -$max);
        $rows = [];
        foreach ($tail as $line) {
            $row = json_decode((string) $line, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listL2(int $userId): array
    {
        $dir = $this->userDir($userId).'/memory/l2';
        if (! File::isDirectory($dir)) {
            return [];
        }

        return collect(File::files($dir))
            ->filter(fn ($file) => preg_match('/^\d{4}-\d{2}-\d{2}\.md$/', $file->getFilename()) === 1)
            ->sortByDesc(fn ($file) => $file->getFilename())
            ->map(fn ($file) => [
                'date' => str_replace('.md', '', $file->getFilename()),
                'path' => $file->getPathname(),
                'size' => $file->getSize(),
                'updated_at' => \Carbon\CarbonImmutable::createFromTimestamp($file->getMTime())->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
            ])
            ->values()
            ->all();
    }

    private function readL2(int $userId, string $date): string
    {
        $path = $this->l2Path($userId, $date);

        return File::exists($path) ? (string) File::get($path) : '';
    }

    private function readL3(int $userId): string
    {
        $path = $this->l3Path($userId);

        return File::exists($path) ? (string) File::get($path) : '';
    }

    private function extractJson(string $text): ?string
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        return substr($text, $start, $end - $start + 1);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function toTranscript(array $messages): string
    {
        $lines = [];
        foreach ($messages as $message) {
            $role = strtoupper((string) ($message['role'] ?? 'unknown'));
            $content = trim((string) ($message['content'] ?? ''));
            if ($content !== '') {
                $lines[] = $role.': '.$content;
            }
        }

        return implode("\n", $lines);
    }

    private function estimateTokens(array|string $payload): int
    {
        $text = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : $payload;

        return max(1, (int) ceil(strlen((string) $text) / 4));
    }

    private function truncate(string $text, int $max): string
    {
        if ($text === '') {
            return '';
        }

        if (mb_strlen($text, 'UTF-8') <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1, 'UTF-8').'...';
    }

    /**
     * @return array<int, string>
     */
    private function keywords(string $text): array
    {
        return $this->keywordExtractor->extract($text, 24);
    }

    private function userDir(int $userId): string
    {
        return storage_path(self::BASE_DIR.'/'.$userId);
    }

    private function sessionPath(int $userId, string $sessionKey): string
    {
        return $this->userDir($userId).'/sessions/'.$sessionKey.'.jsonl';
    }

    private function l2Path(int $userId, string $date): string
    {
        return $this->userDir($userId).'/memory/l2/'.$date.'.md';
    }

    private function l3Path(int $userId): string
    {
        return $this->userDir($userId).'/memory/l3/memory.md';
    }

    private function relativePath(string $absolutePath): string
    {
        $root = storage_path();
        if (str_starts_with($absolutePath, $root)) {
            return 'storage/'.ltrim(str_replace('\\', '/', substr($absolutePath, strlen($root))), '/');
        }

        return $absolutePath;
    }

    private function sessionKey(Run $run): string
    {
        return 'conv_'.$run->conversation_id;
    }

    /**
     * @return array<int, string>
     */
    private function buildUserContextLines(Run $run): array
    {
        return $this->buildUserContextLinesForUser($this->resolveUser($run));
    }

    /**
     * @return array<int, string>
     */
    private function buildUserContextLinesForUser(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $lines = ['- User name: '.$user->name];
        if (trim((string) $user->title) !== '') {
            $lines[] = '- User title: '.trim((string) $user->title);
        }

        $department = $user->relationLoaded('department') ? $user->department : $user->department()->first();
        if ($department !== null && trim((string) $department->name) !== '') {
            $lines[] = '- Department: '.trim((string) $department->name);
        }

        $preferences = is_array($user->preferences) ? $user->preferences : [];
        foreach (array_slice($this->flattenPreferenceLines($preferences), 0, 3) as $line) {
            $lines[] = '- '.$line;
        }

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    private function flattenPreferenceLines(array $preferences): array
    {
        $lines = [];
        foreach ($preferences as $key => $value) {
            if (is_scalar($value)) {
                $line = trim((string) $key).': '.trim((string) $value);
                if ($line !== ': ') {
                    $lines[] = $line;
                }
                continue;
            }

            if (is_array($value) && $value !== []) {
                $items = array_slice(array_map(
                    static fn ($item) => trim((string) $item),
                    array_filter($value, static fn ($item) => is_scalar($item))
                ), 0, 4);
                if ($items !== []) {
                    $lines[] = trim((string) $key).': '.implode(', ', $items);
                }
            }
        }

        return array_values(array_filter($lines));
    }

    /**
     * @return array<int, string>
     */
    private function userAliases(Run $run): array
    {
        $user = $this->resolveUser($run);
        if ($user === null) {
            return [];
        }

        return array_values(array_unique(array_filter([
            trim((string) $user->name),
        ])));
    }

    private function resolveUser(Run $run): ?User
    {
        $user = $run->relationLoaded('user') ? $run->user : null;
        if ($user instanceof User) {
            if (! $user->relationLoaded('department')) {
                $user->loadMissing('department');
            }

            return $user;
        }

        return User::query()->with('department')->find($run->user_id);
    }

    private function latestConversationUserText(Run $run): string
    {
        $conversation = $run->conversation()
            ->with(['messages' => fn ($query) => $query->orderByDesc('id')->limit(12)])
            ->first();

        if ($conversation === null || $conversation->messages === null) {
            return '';
        }

        return (string) MessageTextExtractor::latestUserText(
            $conversation->messages
                ->reverse()
                ->map(fn ($message) => ['role' => $message->role, 'content' => $message->content])
                ->values()
                ->all()
        );
    }

    private function storeUserMemorySignals(Run $run, string $userText): void
    {
        $userText = trim($userText);
        if ($userText === '') {
            return;
        }

        $decision = $this->layerPolicy->classifyUserText($userText, false);
        if (($decision['store_l2'] ?? false) !== true) {
            return;
        }

        $entry = $this->appendL2(
            $run,
            (string) ($decision['title'] ?? 'Recent context'),
            $userText,
            array_merge((array) ($decision['tags'] ?? []), ['source:user_turn'])
        );

        if (($decision['promote_l3'] ?? false) !== true) {
            return;
        }

        $this->storeL3Fact(
            (int) $run->user_id,
            (int) $run->id,
            $userText,
            (string) ($decision['category'] ?? 'preference'),
            (int) ($decision['priority'] ?? 88),
            (int) ($entry['entry_id'] ?? 0),
            [
                'source' => 'user_turn',
                'source_role' => 'user',
            ]
        );
    }

    /**
     * @return Collection<int, MemoryFact>
     */
    private function topDurableFacts(int $userId, int $limit = 12): Collection
    {
        return MemoryFact::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->filter(function (MemoryFact $fact) {
                $review = $this->layerPolicy->reviewFact((string) $fact->fact, (string) $fact->category, [
                    'priority' => (int) $fact->priority,
                    ...(is_array($fact->meta) ? $fact->meta : []),
                ]);

                return ($review['allow'] ?? false) === true;
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeFactMeta(array $existing, array $incoming): array
    {
        $merged = array_merge($existing, $incoming);

        $queryHashes = array_values(array_unique(array_filter(array_map(
            static fn ($hash) => trim((string) $hash),
            array_merge((array) ($existing['query_hashes'] ?? []), (array) ($incoming['query_hashes'] ?? []))
        ))));

        if ($queryHashes !== []) {
            $merged['query_hashes'] = array_slice($queryHashes, -20);
            $merged['unique_query_count'] = count($merged['query_hashes']);
        }

        if (isset($existing['recall_count']) || isset($incoming['recall_count'])) {
            $merged['recall_count'] = max((int) ($existing['recall_count'] ?? 0), (int) ($incoming['recall_count'] ?? 0));
        }

        return $merged;
    }

    private function factCategoryLabel(string $category): string
    {
        return match ($category) {
            'identity' => 'Identity',
            'preference' => 'Preference',
            'constraint' => 'Constraint',
            'style' => 'Style',
            'project_anchor' => 'Project Anchor',
            'work_context' => 'Work Context',
            default => $category,
        };
    }

    private function promoteRecalledEntriesToFacts(int $userId): int
    {
        $logs = MemoryRetrievalLog::query()
            ->where('user_id', $userId)
            ->whereDate('created_at', '>=', now()->subDays(45)->toDateString())
            ->orderByDesc('created_at')
            ->get();

        if ($logs->isEmpty()) {
            return 0;
        }

        $signals = [];
        foreach ($logs as $log) {
            $queryHash = trim((string) Arr::get((array) $log->meta, 'query_hash', ''));
            foreach ((array) $log->retrieved_l2_entry_ids as $entryId) {
                $entryId = (int) $entryId;
                if ($entryId <= 0) {
                    continue;
                }

                $signals[$entryId]['count'] = (int) ($signals[$entryId]['count'] ?? 0) + 1;
                $signals[$entryId]['query_hashes'][$queryHash !== '' ? $queryHash : sha1((string) $log->query_text)] = true;
            }
        }

        if ($signals === []) {
            return 0;
        }

        $entries = MemoryEntry::query()
            ->where('user_id', $userId)
            ->whereNull('expired_at')
            ->whereIn('id', array_keys($signals))
            ->get()
            ->keyBy('id');

        $promoted = 0;
        foreach ($signals as $entryId => $signal) {
            $entry = $entries->get($entryId);
            if (! $entry instanceof MemoryEntry) {
                continue;
            }

            $recallCount = (int) ($signal['count'] ?? 0);
            $uniqueQueries = count((array) ($signal['query_hashes'] ?? []));
            if ($recallCount < 2 || $uniqueQueries < 2) {
                continue;
            }

            $decision = $this->classifyEntryForPromotion($entry);
            if (($decision['promote_l3'] ?? false) !== true) {
                continue;
            }

            $priority = min(95, (int) ($decision['priority'] ?? 76) + ($recallCount * 4) + ($uniqueQueries * 3));
            $fact = $this->storeL3Fact(
                $userId,
                $entry->run_id ? (int) $entry->run_id : null,
                (string) $entry->content,
                (string) ($decision['category'] ?? 'project_anchor'),
                $priority,
                (int) $entry->id,
                [
                    'source' => 'recall_promotion',
                    'source_role' => $this->entrySourceRole($entry),
                    'recall_count' => $recallCount,
                    'unique_query_count' => $uniqueQueries,
                    'query_hashes' => array_keys((array) ($signal['query_hashes'] ?? [])),
                ]
            );

            if ($fact !== null) {
                $promoted++;
            }
        }

        return $promoted;
    }

    /**
     * @return array<string, mixed>
     */
    private function classifyEntryForPromotion(MemoryEntry $entry): array
    {
        $sourceRole = $this->entrySourceRole($entry);
        if ($sourceRole !== 'user' || $this->isEntryExpired($entry)) {
            return ['promote_l3' => false];
        }

        return $this->layerPolicy->classifyUserText((string) $entry->content, in_array('source:active_memory', (array) ($entry->tags ?? []), true));
    }

    private function entrySourceRole(MemoryEntry $entry): string
    {
        foreach ((array) ($entry->tags ?? []) as $tag) {
            if ($tag === 'source:user' || $tag === 'source:user_turn') {
                return 'user';
            }
            if ($tag === 'source:assistant') {
                return 'assistant';
            }
        }

        return 'system';
    }

    private function isEntryExpired(MemoryEntry $entry): bool
    {
        $attributes = $entry->getAttributes();
        $expiredAt = $attributes['expired_at'] ?? null;
        if ($expiredAt instanceof \DateTimeInterface) {
            return true;
        }

        if (is_string($expiredAt) && trim($expiredAt) !== '') {
            return true;
        }

        $ttlDays = $this->ttlDaysFromTags((array) ($entry->tags ?? []));
        if ($ttlDays === null || $ttlDays <= 0) {
            return false;
        }

        $anchor = $attributes['created_at'] ?? null;
        if ($anchor instanceof \DateTimeInterface) {
            $anchor = Carbon::instance($anchor);
        } elseif (is_string($anchor) && trim($anchor) !== '') {
            $anchor = Carbon::parse($anchor);
        } else {
            $anchor = now();
        }

        return $anchor->copy()->addDays($ttlDays)->lte(now());
    }

    /**
     * @param  array<int, string>  $tags
     */
    private function ttlDaysFromTags(array $tags): ?int
    {
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if (preg_match('/^ttl:(\d{1,3})$/', $tag, $matches) === 1) {
                return max(1, (int) $matches[1]);
            }
        }

        return null;
    }

    /**
     * @return array{action: string, bucket: string}
     */
    private function rejectionPolicyForReason(string $reason): array
    {
        $hardReasons = ['empty', 'too_long', 'document_like', 'contains_sensitive_payload', 'contains_url'];
        $softReasons = ['task_instruction', 'not_durable', 'ephemeral_directive', 'question_like', 'transient_detail'];
        $garbageReasons = ['assistant_offer', 'greeting_like', 'clarification_fact', 'assistant_generated_fact', 'assistant_capability'];

        if (in_array($reason, $hardReasons, true)) {
            return ['action' => 'deactivated', 'bucket' => 'hard'];
        }

        if (in_array($reason, $garbageReasons, true)) {
            return ['action' => 'garbage', 'bucket' => 'garbage'];
        }

        if (in_array($reason, $softReasons, true)) {
            return ['action' => 'soft_deactivated', 'bucket' => 'soft'];
        }

        return ['action' => 'soft_deactivated', 'bucket' => 'soft'];
    }

    private function looksLikeSensitiveMemoryPayload(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return true;
        }

        if (preg_match('/https?:\/\/\S+/iu', $text) === 1) {
            return true;
        }

        if (preg_match('/\b(token|app_token|table_id|view_id|record_id|doc_token|sheet_id|space_id|wiki_id|event_id|base_token|tenant_access_token|refresh_token|user_access_token|authorization|bearer)\b/iu', $text) === 1) {
            return true;
        }

        return preg_match('/(?:^|[\s:])([A-Za-z0-9_-]{24,})(?:$|[\s,])/u', $text) === 1;
    }

    private function shouldUseEntryInPrompt(MemoryEntry $entry): bool
    {
        if ($this->entrySourceRole($entry) !== 'user' || $this->isEntryExpired($entry)) {
            return false;
        }

        $content = trim((string) ($entry->summary ?: $entry->content));

        return $content !== '' && ! $this->layerPolicy->isNoiseForPrompt($content);
    }

    /**
     * Build a terse, placeholder-safe summary of the session so the LLM does
     * not need to see every historical message every turn. Cached per-run in
     * storage/app/user_data/{user_id}/memory/session_summary_{run_id}.json so
     * we only re-invoke the LLM when the turn count actually changes.
     *
     * MUST assert user_id > 0 before writing — multi-user deployment invariant.
     *
     * @param  list<array<string,mixed>>  $originalRows  untouched chronological rows
     * @return string|null  summary text, or null on failure (caller falls back)
     */
    public function getOrBuildSessionSummary(int $userId, int $runId, array $originalRows): ?string
    {
        if ($userId <= 0 || $runId <= 0) {
            return null;
        }

        $dir = $this->userDir($userId).'/memory';
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0775, true);
        }

        $path = $dir.'/session_summary_'.$runId.'.json';
        $turnCount = count($originalRows);

        if (is_file($path) && is_readable($path)) {
            $raw = @file_get_contents($path);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)
                    && (int) ($decoded['turn_count'] ?? -1) === $turnCount
                    && isset($decoded['summary']) && is_string($decoded['summary'])
                    && trim($decoded['summary']) !== '') {
                    return (string) $decoded['summary'];
                }
            }
        }

        $transcript = $this->toTranscript($originalRows);
        $transcript = $this->truncate($transcript, 8500);

        try {
            $response = $this->llmGatewayService->chat([
                ['role' => 'system', 'content' => 'Summarize ONLY the conversation messages given below. These messages are all within a recent time window. Output terse plain text, no markdown.\nRules:\n- Do NOT reintroduce tasks, requests, or pending actions from before this window.\n- Do NOT merge unrelated topics into one.\n- Refer to ids, tokens, urls, and full timestamps as "the event", "the document", "recent" instead of reproducing them.\n- If there is no material to summarize, output nothing.'],
                ['role' => 'user', 'content' => $transcript],
            ]);
            $summary = trim((string) ($response['content'] ?? ''));
        } catch (Throwable) {
            return null;
        }

        if ($summary === '') {
            return null;
        }

        // Scrub any prompt-injection residue before persisting to disk.
        $summary = $this->contextSanitizer()->sanitize($summary);
        if ($summary === '') {
            return null;
        }

        $payload = [
            'turn_count' => $turnCount,
            'summary' => $summary,
            'updated_at' => Carbon::now()->toIso8601String(),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (is_string($json)) {
            @file_put_contents($path, $json);
            @chmod($path, 0664);
        }

        return $summary;
    }

}
