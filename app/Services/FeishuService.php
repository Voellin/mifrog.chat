<?php

namespace App\Services;

use App\Models\Run;
use App\Services\Feishu\FeishuOAuthService;
use App\Services\Feishu\FeishuOrgSyncService;
use App\Services\Feishu\FeishuPushService;
use App\Services\Feishu\FeishuResourceService;
use App\Services\Feishu\FeishuTransport;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Feishu facade + run-card UI surface.
 *
 * After Commit 12 this class:
 * - Retains all Run UI methods (syncRunCard / addThinkingReaction /
 *   clearThinkingReaction / sendHybridDeltaText) + their extensive private
 *   helpers (buildRunCard, humanize*, state caches, etc.)
 * - Retains resolveUserNameByOpenId (small lookup, no clean domain home)
 * - Delegates OAuth / Push / Resource / OrgSync to domain services under
 *   App\Services\Feishu\*
 * - Exposes readConfig() via delegation — preserves the ~15-call external
 *   contract surface touched by task services and commands
 *
 * Ground rule: external signatures are byte-for-byte identical to the pre-
 * split implementation. Test mocks on FeishuService continue to work.
 */
class FeishuService
{
    private const REACTION_DISABLED_CACHE_KEY = 'feishu_reaction_disabled';
    private const CARD_STATE_CACHE_PREFIX = 'feishu_run_card_state_';
    private const REACTION_STATE_CACHE_PREFIX = 'feishu_run_reaction_state_';
    private const TEXT_STATE_CACHE_PREFIX = 'feishu_run_text_state_';
    private const CARD_STATE_TTL_SECONDS = 86400;
    private const REACTION_STATE_TTL_SECONDS = 1800;
    private const TOOL_LOG_REFRESH_INTERVAL_SECONDS = 3;
    private const TASK_TRACE_LOG_INTERVAL_SECONDS = 4;
    private const TASK_TRACE_MAX_MESSAGES = 14;
    private const DEFAULT_THINKING_EMOJI = 'THINKING';

    public function __construct(
        private readonly FeishuTransport $transport,
        private readonly FeishuOAuthService $oauth,
        private readonly FeishuPushService $push,
        private readonly FeishuResourceService $resources,
        private readonly FeishuOrgSyncService $orgSync,
    ) {
    }

    // ================================================================
    // Facade delegations — preserve external contract (unchanged signatures)
    // ================================================================

    /** @return array{app_id:string, app_secret:string, enabled:bool} */
    public function readConfig(): array
    {
        return $this->transport->readConfig();
    }

    // --- OAuth ---

    public function buildOauthAuthorizeUrl(string $redirectUri, string $state, ?array $scopes = null): ?string
    {
        return $this->oauth->buildOauthAuthorizeUrl($redirectUri, $state, $scopes);
    }

    public function startDeviceFlowAuth(array $capabilities = [], string $userKey = ''): array
    {
        return $this->oauth->startDeviceFlowAuth($capabilities, $userKey);
    }

    /** @return string[] */
    public function requiredOauthScopes(): array
    {
        return $this->oauth->requiredOauthScopes();
    }

    /** @return array<string,mixed> */
    public function exchangeUserAccessTokenByCode(string $code, ?string $redirectUri = null): array
    {
        return $this->oauth->exchangeUserAccessTokenByCode($code, $redirectUri);
    }

    /** @return array<string,mixed> */
    public function refreshUserAccessToken(string $refreshToken): array
    {
        return $this->oauth->refreshUserAccessToken($refreshToken);
    }

    /** @return array<string,mixed> */
    public function getUserInfoByUserAccessToken(string $userAccessToken): array
    {
        return $this->oauth->getUserInfoByUserAccessToken($userAccessToken);
    }

    // --- Push ---

    public function pushTextToChat(string $chatId, string $text): bool
    {
        return $this->push->pushTextToChat($chatId, $text);
    }

    public function pushTextToOpenId(string $openId, string $text): bool
    {
        return $this->push->pushTextToOpenId($openId, $text);
    }

    /**
     * 向 chat 发送 markdown 富文本（schema 2.0 interactive card 内嵌 markdown 元素）。
     * 失败返回 false——caller 可降级到 pushTextToChat。
     */
    public function pushMarkdownToChat(string $chatId, string $markdown): bool
    {
        return $this->push->pushInteractiveMarkdownToChat($chatId, $markdown);
    }

    /** @param string[] $scopes */
    public function pushAuthorizationCard(Run $run, string $oauthUrl, array $scopes = []): bool
    {
        return $this->push->pushAuthorizationCard($run, $oauthUrl, $scopes);
    }

    public function dismissAuthorizationCard(string $messageId, bool $authorized, ?string $detail = null): void
    {
        $this->push->dismissAuthorizationCard($messageId, $authorized, $detail);
    }

    // --- Resource ---

    /**
     * @param  array<string,mixed>  $resource
     * @return array{path:string, mime_type:string, size:int, file_name:string}
     */
    public function downloadMessageResource(array $resource): array
    {
        return $this->resources->downloadMessageResource($resource);
    }

    // --- Org Sync ---

    /** @return array<string,mixed> */
    public function syncOrganizationAndMembers(): array
    {
        return $this->orgSync->syncOrganizationAndMembers();
    }

    // ================================================================
    // Run UI surface + private helpers (retained in-place)
    // ================================================================

    public function syncRunCard(Run $run, string $eventType, ?string $message, array $payload = []): void
    {
        if (! $run->feishu_chat_id) {
            return;
        }
        if (! $this->shouldUseCardForRun($run)) {
            return;
        }

        $config = $this->transport->readConfig();
        if (! $config['enabled']) {
            return;
        }

        $token = $this->transport->tenantToken($config['app_id'], $config['app_secret']);
        if (! $token) {
            return;
        }

        $state = $this->loadCardState((int) $run->id);
        $state = $this->applyEventToState($state, $eventType, $message, $payload);

        if ($eventType === 'tool_log' && ! $this->shouldRefreshToolLogCard($state)) {
            $this->saveCardState((int) $run->id, $state);

            return;
        }

        $card = $this->buildRunCard($state);
        $messageId = trim((string) $run->feishu_message_id);

        if ($messageId !== '') {
            $updated = $this->transport->updateInteractiveCard($token, $messageId, $card);
            if (! $updated) {
                $messageId = (string) ($this->transport->sendInteractiveCard($token, (string) $run->feishu_chat_id, $card) ?? '');
            }
        } else {
            $messageId = (string) ($this->transport->sendInteractiveCard($token, (string) $run->feishu_chat_id, $card) ?? '');
        }

        if ($messageId !== '' && $messageId !== (string) $run->feishu_message_id) {
            $run->feishu_message_id = $messageId;
            $run->save();
        }

        $state['last_card_update_at'] = time();
        $this->saveCardState((int) $run->id, $state);
    }

    public function addThinkingReaction(Run $run, string $sourceMessageId): void
    {
        if (! $run->feishu_chat_id || trim($sourceMessageId) === '') {
            return;
        }

        if (Cache::get(self::REACTION_DISABLED_CACHE_KEY, false)) {
            return;
        }

        $config = $this->transport->readConfig();
        if (! $config['enabled']) {
            return;
        }

        $token = $this->transport->tenantToken($config['app_id'], $config['app_secret']);
        if (! $token) {
            return;
        }

        $reactionId = null;
        $emojiUsed = null;
        $emojiCandidates = [
            self::DEFAULT_THINKING_EMOJI,
            'OnIt',
            'THUMBSUP',
        ];

        foreach ($emojiCandidates as $emojiType) {
            try {
                $body = $this->transport->requestJson('post', 'im/v1/messages/'.trim($sourceMessageId).'/reactions', [
                    'headers' => $this->transport->authHeaders($token),
                    'json' => [
                        'reaction_type' => [
                            'emoji_type' => $emojiType,
                        ],
                    ],
                ]);
            } catch (Throwable $e) {
                Log::warning('feishu.reaction.add_exception', [
                    'message_id' => $sourceMessageId,
                    'emoji_type' => $emojiType,
                    'message' => $e->getMessage(),
                ]);
                if (str_contains($e->getMessage(), '231001') || str_contains(strtolower($e->getMessage()), 'reaction type is invalid')) {
                    Cache::put(self::REACTION_DISABLED_CACHE_KEY, true, now()->addHours(12));
                    break;
                }
                continue;
            }

            if ((int) Arr::get($body, 'code', -1) !== 0) {
                continue;
            }

            $reactionId = trim((string) Arr::get($body, 'data.reaction_id', ''));
            $emojiUsed = $emojiType;
            break;
        }

        if ($reactionId === null && $emojiUsed === null) {
            return;
        }

        Cache::put(
            $this->reactionStateKey((int) $run->id),
            [
                'message_id' => trim($sourceMessageId),
                'reaction_id' => $reactionId,
                'emoji_type' => $emojiUsed,
            ],
            now()->addSeconds(self::REACTION_STATE_TTL_SECONDS)
        );
    }

    public function clearThinkingReaction(Run $run): void
    {
        $state = Cache::get($this->reactionStateKey((int) $run->id), null);
        if (! is_array($state)) {
            return;
        }

        Cache::forget($this->reactionStateKey((int) $run->id));

        $messageId = trim((string) ($state['message_id'] ?? ''));
        $reactionId = trim((string) ($state['reaction_id'] ?? ''));
        if ($messageId === '' || $reactionId === '') {
            return;
        }

        $config = $this->transport->readConfig();
        if (! $config['enabled']) {
            return;
        }

        $token = $this->transport->tenantToken($config['app_id'], $config['app_secret']);
        if (! $token) {
            return;
        }

        try {
            $body = $this->transport->requestJson('delete', 'im/v1/messages/'.$messageId.'/reactions/'.$reactionId, [
                'headers' => $this->transport->authHeaders($token),
            ]);

            if ((int) Arr::get($body, 'code', -1) !== 0) {
                Log::warning('feishu.reaction.delete_failed', [
                    'message_id' => $messageId,
                    'reaction_id' => $reactionId,
                    'response' => $body,
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('feishu.reaction.delete_exception', [
                'message_id' => $messageId,
                'reaction_id' => $reactionId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function sendHybridDeltaText(Run $run, string $eventType, ?string $message): void
    {
        if (! $run->feishu_chat_id) {
            return;
        }
        if (! in_array($eventType, ['thinking', 'tool_start', 'tool_log', 'tool_end', 'final', 'error', 'waiting_auth', 'clarify'], true)) {
            return;
        }
        $isTaskTraceEvent = in_array($eventType, ['thinking', 'tool_start', 'tool_log', 'tool_end'], true);
        $isTaskRun = strtolower(trim((string) ($run->intent_type ?? ''))) === Run::INTENT_TASK
            || strtolower(trim((string) ($run->interaction_mode ?? ''))) === Run::INTERACTION_CARD;
        if ($isTaskTraceEvent && ! $isTaskRun) {
            return;
        }
        $config = $this->transport->readConfig();
        if (! $config['enabled']) {
            return;
        }
        $token = $this->transport->tenantToken($config['app_id'], $config['app_secret']);
        if (! $token) {
            return;
        }
        // Always send final/error text summary even when using cards
        // so the user gets a natural language completion message
        if ($isTaskTraceEvent) {
            $text = $this->formatTaskTraceText($eventType, $message);
            if ($text === '') {
                return;
            }
            $state = $this->loadTextState((int) $run->id);
            if (! $this->shouldSendTaskTraceText($state, $eventType, $text)) {
                return;
            }
            if (! $this->transport->sendTextMessage($token, (string) $run->feishu_chat_id, $this->limitText($text, 1200))) {
                return;
            }
            $state['trace_count'] = (int) ($state['trace_count'] ?? 0) + 1;
            $state['last_trace_at'] = time();
            $state['last_trace_event'] = $eventType;
            $state['last_trace_text'] = $text;
            $this->saveTextState((int) $run->id, $state);
            return;
        }
        $text = match ($eventType) {
            'error' => trim((string) $message) ?: '抱歉，任务执行失败，请稍后重试。',
            'waiting_auth' => trim((string) $message) ?: '任务需要授权后才能继续，请完成授权。',
            'clarify' => trim((string) $message) ?: '还需要你补充一些信息，我才能继续执行。',
            default => trim((string) $message),
        };
        if ($text === '') {
            return;
        }
        $this->transport->sendTextMessage($token, (string) $run->feishu_chat_id, $this->limitText($text, 3500));
    }
    public function resolveUserNameByOpenId(string $openId): ?string
    {
        $openId = trim($openId);
        if ($openId === '') {
            return null;
        }

        $config = $this->transport->readConfig();
        if (! $config['enabled']) {
            return null;
        }

        $token = $this->transport->tenantToken($config['app_id'], $config['app_secret']);
        if (! $token) {
            return null;
        }

        try {
            $body = $this->transport->requestJson('get', 'contact/v3/users/'.rawurlencode($openId).'?user_id_type=open_id', [
                'headers' => $this->transport->authHeaders($token),
            ]);
        } catch (Throwable $e) {
            Log::warning('feishu.user.profile_exception', [
                'open_id' => $openId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if ((int) Arr::get($body, 'code', -1) !== 0) {
            return null;
        }

        $name = trim((string) Arr::get($body, 'data.user.name', ''));

        return $name !== '' ? $name : null;
    }

    private function shouldUseCardForRun(Run $run): bool
    {
        $mode = strtolower(trim((string) ($run->interaction_mode ?? '')));
        if ($mode === Run::INTERACTION_CARD) {
            return true;
        }

        $intent = strtolower(trim((string) ($run->intent_type ?? '')));

        return $intent === Run::INTENT_TASK;
    }

    private function loadCardState(int $runId): array
    {
        $raw = Cache::get($this->cardStateKey($runId), []);
        $state = is_array($raw) ? $raw : [];

        return array_merge([
            'status' => 'queued',
            'current_line' => '收到任务，已进入队列。',
            'steps' => [],
            'live_log' => '',
            'final_answer' => '任务已接收，执行完成后会在这里给出正式答复。',
            'last_card_update_at' => 0,
        ], $state);
    }

    private function saveCardState(int $runId, array $state): void
    {
        Cache::put(
            $this->cardStateKey($runId),
            $state,
            now()->addSeconds(self::CARD_STATE_TTL_SECONDS)
        );
    }

    private function cardStateKey(int $runId): string
    {
        return self::CARD_STATE_CACHE_PREFIX.$runId;
    }

    private function reactionStateKey(int $runId): string
    {
        return self::REACTION_STATE_CACHE_PREFIX.$runId;
    }

    private function textStateKey(int $runId): string
    {
        return self::TEXT_STATE_CACHE_PREFIX.$runId;
    }

    private function loadTextState(int $runId): array
    {
        $raw = Cache::get($this->textStateKey($runId), []);
        $state = is_array($raw) ? $raw : [];

        return array_merge([
            'trace_count' => 0,
            'last_trace_at' => 0,
            'last_trace_event' => '',
            'last_trace_text' => '',
        ], $state);
    }

    private function saveTextState(int $runId, array $state): void
    {
        Cache::put(
            $this->textStateKey($runId),
            $state,
            now()->addSeconds(self::CARD_STATE_TTL_SECONDS)
        );
    }

    private function shouldSendTaskTraceText(array $state, string $eventType, string $text): bool
    {
        $traceCount = (int) ($state['trace_count'] ?? 0);
        if ($traceCount >= self::TASK_TRACE_MAX_MESSAGES) {
            return false;
        }

        $lastAt = (int) ($state['last_trace_at'] ?? 0);
        $lastEvent = (string) ($state['last_trace_event'] ?? '');
        $lastText = (string) ($state['last_trace_text'] ?? '');
        $now = time();

        if ($eventType === 'tool_log' && ($now - $lastAt) < self::TASK_TRACE_LOG_INTERVAL_SECONDS) {
            return false;
        }

        if ($lastEvent === $eventType && $lastText === $text && ($now - $lastAt) < 10) {
            return false;
        }

        return true;
    }

    private function formatTaskTraceText(string $eventType, ?string $message): string
    {
        $plain = $this->oneLine((string) ($message ?? ''));
        if ($plain === '') {
            return '';
        }

        $prefix = match ($eventType) {
            'thinking' => '[过程] ',
            'tool_start' => '[开始] ',
            'tool_log' => '[尝试] ',
            'tool_end' => '[收敛] ',
            'waiting_auth' => '[授权] ',
            default => '[过程] ',
        };

        return $prefix.$this->limitText($plain, 900);
    }

    private const ACTION_LABELS = [
        'docs.read'        => '文档读取',
        'docs.create'      => '文档创建',
        'sheets.read'      => '表格读取',
        'sheets.create'    => '表格创建',
        'sheets.write'     => '表格写入',
        'sheets.append'    => '表格追加',
        'calendar.create'  => '日程创建',
        'calendar.agenda'  => '日程查询',
        'calendar.attendees.add' => '添加参会人',
        'tasks.create'     => '任务创建',
        'contact.lookup'   => '联系人查询',
        'approval.manage'  => '审批管理',
        'base.manage'      => '多维表格',
        'meeting.manage'   => '会议管理',
        'minutes.manage'   => '妙记/笔记',
        'mail.manage'      => '邮件管理',
        'wiki.manage'      => '知识库',
        'drive.manage'     => '云文档',
        'chat.history_read' => '聊天记录读取',
        'request_authorization' => '权限检查',
    ];

    private function applyEventToState(array $state, string $eventType, ?string $message, array $payload = []): array
    {
        $eventType = strtolower(trim($eventType));

        // Guard: once a card reaches a terminal state, only 'final' or 'error' can change it.
        $currentStatus = (string) ($state['status'] ?? '');
        if (in_array($currentStatus, ['success', 'failed', 'needs_input'], true) && ! in_array($eventType, ['final', 'error', 'clarify'], true)) {
            return $state;
        }

        switch ($eventType) {
            case 'thinking':
                $humanMsg = $this->humanizeThinking($message);
                // Only append thinking as a step if card hasn't started running yet.
                // Once tools are executing, thinking steps are redundant noise.
                if (($state['status'] ?? '') !== 'running') {
                    $state['status'] = 'queued';
                    $this->appendStep($state, $humanMsg);
                }
                $state['current_line'] = $humanMsg;
                break;

            case 'intent':
                $state['status'] = 'running';
                $prevLine = trim((string) ($state['current_line'] ?? ''));
                if ($prevLine !== '') {
                    $this->appendStep($state, $prevLine);
                }
                $state['current_line'] = $this->humanizeIntent($payload);
                break;

            case 'tool_start':
                $state['status'] = 'running';
                $prevLine = trim((string) ($state['current_line'] ?? ''));
                if ($prevLine !== '') {
                    $this->appendStep($state, $prevLine);
                }
                $state['current_line'] = $this->humanizeToolStart($message, $payload);
                break;

            case 'tool_log':
                $state['status'] = 'running';
                $prevLine = trim((string) ($state['current_line'] ?? ''));
                if ($prevLine !== '') {
                    $this->appendStep($state, $prevLine);
                }
                $state['current_line'] = $this->humanizeToolLog($message, $payload);
                $state['live_log'] = '';
                break;

            case 'tool_end':
                $state['status'] = 'running';
                $prevLine = trim((string) ($state['current_line'] ?? ''));
                if ($prevLine !== '') {
                    $this->appendStep($state, $prevLine);
                }
                $state['current_line'] = $this->humanizeToolEnd($message, $payload);
                $state['live_log'] = '';
                break;

            case 'final':
                $state['status'] = 'success';
                $state['current_line'] = '任务已完成。';
                $state['live_log'] = '';
                $state['final_answer'] = $this->finalAnswerText($message);
                $this->appendStep($state, '已输出最终答复');
                break;

            case 'error':
                $state['status'] = 'failed';
                $state['current_line'] = '任务执行失败。';
                $state['live_log'] = '';
                $state['final_answer'] = '抱歉，任务执行失败：'.$this->oneLine($message ?: '请稍后重试');
                $this->appendStep($state, '执行失败');
                break;

            case 'waiting_auth':
                $state['status'] = 'waiting_auth';
                $state['current_line'] = '任务暂停，等待授权后继续。';
                $state['live_log'] = '';
                $state['final_answer'] = $this->finalAnswerText($message ?: '请先完成授权，完成后我会自动继续任务。');
                $this->appendStep($state, '等待授权');
                break;

            case 'clarify':
                $state['status'] = 'needs_input';
                $state['current_line'] = '还需要你补充一些信息。';
                $state['live_log'] = '';
                $state['final_answer'] = $this->finalAnswerText($message ?: '请补充一些关键信息，我才能继续执行。');
                $this->appendStep($state, '等待补充信息');
                break;
        }

        return $state;
    }

    private function humanizeThinking(?string $message): string
    {
        $msg = trim((string) $message);
        $map = [
            'Tool calling is planning the next step.' => '正在规划执行步骤',
            'Question mode detected. Preparing memory context.' => '识别为问答，正在准备上下文',
            'Task mode detected. Starting execution.' => '识别为任务，正在准备执行',
            'Loaded attachment knowledge context.' => '已加载附件知识',
        ];
        if (isset($map[$msg])) {
            return $map[$msg];
        }
        // If already Chinese, use as-is
        if ($msg !== '' && preg_match('/[\x{4e00}-\x{9fff}]/u', $msg) === 1) {
            return $msg;
        }
        return $msg !== '' ? $msg : '正在理解你的需求';
    }

    private function humanizeIntent(array $payload): string
    {
        $intent = (string) ($payload['intent'] ?? '');
        $actionKey = (string) ($payload['work_action'] ?? '');
        $taskKind = (string) ($payload['task_kind'] ?? '');

        if ($intent === 'question' || $intent === '') {
            return '识别为问答请求，正在生成回复';
        }

        $actionLabel = self::ACTION_LABELS[$actionKey] ?? '';
        if ($actionLabel !== '') {
            return '识别任务：' . $actionLabel;
        }

        if ($taskKind !== '' && $taskKind !== 'none') {
            return '识别任务类型：' . $taskKind;
        }

        return '已识别任务，准备执行';
    }

    private function humanizeToolStart(?string $message, array $payload): string
    {
        $actionKey = (string) ($payload['work_action'] ?? '');
        $actionLabel = self::ACTION_LABELS[$actionKey] ?? '';
        if ($actionLabel !== '') {
            return '正在执行：' . $actionLabel;
        }

        $msg = trim((string) $message);
        $map = [
            'Starting work action execution.' => '正在调用平台能力',
            'Starting platform skill execution.' => '正在调用平台能力',
            'Execution context prepared.' => '执行上下文已就绪',
            'Retrying work action execution.' => '正在重试执行',
        ];
        if (isset($map[$msg])) {
            return $map[$msg];
        }
        if ($msg !== '' && preg_match('/[\x{4e00}-\x{9fff}]/u', $msg) === 1) {
            return $msg;
        }
        return '正在调用平台能力';
    }

    private function humanizeToolLog(?string $message, array $payload): string
    {
        // Agent loop tool completion: show specific action label + status
        $actionKey = (string) ($payload['work_action'] ?? '');
        $status = (string) ($payload['status'] ?? '');
        $actionLabel = self::ACTION_LABELS[$actionKey] ?? '';
        if ($actionLabel !== '' && $status !== '') {
            return $actionLabel . ($status === 'success' ? '已完成' : '执行结束（' . $status . '）');
        }

        $msg = trim((string) $message);
        if ($msg === '') {
            return '正在处理中';
        }

        // Translate known English patterns
        if (str_starts_with($msg, 'Matched skill /')) {
            $skillKey = trim(str_replace('Matched skill /', '', $msg));
            $skillLabel = self::ACTION_LABELS[str_replace('_', '.', $skillKey)] ?? $skillKey;
            return '匹配技能：' . $skillLabel;
        }
        if ($msg === 'Memory context ready.') {
            return '上下文记忆已就绪';
        }
        if (str_starts_with($msg, 'Initial work action did not fit')) {
            return '首次执行结果不匹配，正在重试';
        }

        // If already Chinese, use directly
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $msg) === 1) {
            return $msg;
        }

        return $msg;
    }

    private function humanizeToolEnd(?string $message, array $payload): string
    {
        $actionKey = (string) ($payload['work_action'] ?? '');
        $actionLabel = self::ACTION_LABELS[$actionKey] ?? '';

        // Check tool_trace for summary
        $toolTrace = (array) ($payload['tool_trace'] ?? []);
        if ($toolTrace !== []) {
            $lastTool = end($toolTrace);
            $traceAction = (string) ($lastTool['action_key'] ?? '');
            $traceLabel = self::ACTION_LABELS[$traceAction] ?? '';
            if ($traceLabel !== '') {
                return $traceLabel . '执行完成，正在整理答复';
            }
        }

        if ($actionLabel !== '') {
            return $actionLabel . '执行完成，正在整理答复';
        }

        $msg = trim((string) $message);
        $map = [
            'Agent loop execution completed.' => '工具调用完成，正在整理答复',
            'Platform skill execution completed.' => '平台能力执行完成，正在整理答复',
            'Task execution completed.' => '任务执行完成，正在整理答复',
        ];
        return $map[$msg] ?? '正在整理最终答复';
    }

    private function shouldRefreshToolLogCard(array $state): bool
    {
        $last = (int) ($state['last_card_update_at'] ?? 0);

        return (time() - $last) >= self::TOOL_LOG_REFRESH_INTERVAL_SECONDS;
    }

    private function appendStep(array &$state, string $step): void
    {
        $line = trim($step);
        if ($line === '') {
            return;
        }

        $steps = array_values(array_filter((array) ($state['steps'] ?? []), fn ($item) => is_string($item) && trim($item) !== ''));
        $latest = end($steps);
        if ($latest === $line) {
            $state['steps'] = $steps;

            return;
        }

        $steps[] = $line;
        $state['steps'] = array_slice($steps, -8);
    }

    private function finalAnswerText(?string $message): string
    {
        $answer = trim((string) $message);
        if ($answer === '') {
            return '任务已完成，但本次没有可展示的文本结果。';
        }

        return $this->limitText($answer, 12000);
    }

    private function buildRunCard(array $state): array
    {
        $history = array_slice((array) ($state['steps'] ?? []), -6);
        $processElements = [];

        // Show current active step at top (bold)
        $currentLine = trim((string) ($state['current_line'] ?? '正在处理请求。'));
        $processElements[] = [
            'tag' => 'markdown',
            'content' => $this->escapeMarkdown($currentLine),
            'text_align' => 'left',
            'text_size' => 'notation',
            'margin' => '0px 0px 0px 0px',
        ];

        // Show completed steps below in grey (oldest first)
        foreach ($history as $line) {
            $processElements[] = [
                'tag' => 'markdown',
                'content' => "<font color='grey-400'>✓ ".$this->escapeMarkdown($line).'</font>',
                'text_align' => 'left',
                'text_size' => 'notation',
                'margin' => '0px 0px 0px 0px',
            ];
        }

        return [
            'schema' => '2.0',
            'config' => [
                'update_multi' => true,
                'style' => [
                    'color' => [
                        'color_wopa31g1sb8' => [
                            'light_mode' => 'rgba(242, 242, 242, 1)',
                            'dark_mode' => 'rgba(26, 25, 25, 1)',
                        ],
                        'color_qemhkcj1tee' => [
                            'light_mode' => 'rgba(242, 242, 242, 1)',
                            'dark_mode' => 'rgba(26, 25, 25, 1)',
                        ],
                    ],
                ],
            ],
            'body' => [
                'direction' => 'vertical',
                'elements' => [
                    [
                        'tag' => 'interactive_container',
                        'width' => 'fill',
                        'height' => 'auto',
                        'corner_radius' => '8px',
                        'elements' => [
                            [
                                'tag' => 'column_set',
                                'horizontal_spacing' => '8px',
                                'horizontal_align' => 'left',
                                'columns' => [
                                    [
                                        'tag' => 'column',
                                        'width' => 'weighted',
                                        'elements' => $processElements,
                                        'vertical_spacing' => '8px',
                                        'horizontal_align' => 'left',
                                        'vertical_align' => 'top',
                                        'weight' => 1,
                                    ],
                                ],
                            ],
                        ],
                        'has_border' => false,
                        'background_style' => 'color_qemhkcj1tee',
                        'padding' => '8px 8px 8px 8px',
                        'direction' => 'vertical',
                        'margin' => '0px 0px 0px 0px',
                    ],
                ],
            ],
            'header' => [
                'title' => [
                    'tag' => 'plain_text',
                    'content' => '任务执行过程',
                ],
                'subtitle' => [
                    'tag' => 'plain_text',
                    'content' => $this->statusLabel((string) ($state['status'] ?? 'running')),
                ],
                'template' => $this->headerTemplate((string) ($state['status'] ?? 'running')),
                'padding' => '12px 8px 12px 8px',
            ],
        ];
    }

    private function statusLabel(string $status): string
    {
        if ($status === 'needs_input') {
            return '等待补充信息';
        }

        return match ($status) {
            'queued' => '排队中',
            'running' => '执行中',
            'waiting_auth' => '等待授权',
            'success' => '已完成',
            'failed' => '失败',
            default => '执行中',
        };
    }

    private function headerTemplate(string $status): string
    {
        if ($status === 'needs_input') {
            return 'orange';
        }

        return match ($status) {
            'success' => 'green',
            'failed' => 'red',
            'waiting_auth' => 'orange',
            default => 'blue',
        };
    }

    private function oneLine(string $text): string
    {
        $line = preg_replace('/\s+/u', ' ', trim($text));

        return is_string($line) ? $line : trim($text);
    }

    private function limitText(string $text, int $maxChars): string
    {
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') <= $maxChars) {
                return $text;
            }

            return mb_substr($text, 0, $maxChars - 3, 'UTF-8').'...';
        }

        if (strlen($text) <= $maxChars) {
            return $text;
        }

        return substr($text, 0, $maxChars - 3).'...';
    }

    private function escapeMarkdown(string $text): string
    {
        return str_replace(
            ['&', '<', '>'],
            ['&amp;', '&lt;', '&gt;'],
            $text
        );
    }
}
