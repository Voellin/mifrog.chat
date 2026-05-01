<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Run;
use App\Models\RunEvent;
use App\Routing\Focus\FocusEntityExtractor;
use App\Services\RunExecution\PlatformResultReporter;
use App\Services\RunExecution\RunAuthFlowHelper;
use App\Services\RunExecution\RunHistoryPreparer;
use App\Support\MessageTextExtractor;
use DomainException;
use Illuminate\Support\Collection;
use Throwable;

class RunExecutionService
{
    public function __construct(
        private readonly LlmGatewayService $llmGatewayService,
        private readonly ToolCallingAgentService $toolCallingAgentService,
        private readonly QuotaService $quotaService,
        private readonly MemoryService $memoryService,
        private readonly FeishuService $feishuService,
        private readonly SkillRuntimeService $skillRuntimeService,
        private readonly FocusEntityExtractor $focusEntityExtractor,
        private readonly AttachmentService $attachmentService,
        private readonly AuditService $auditService,
        private readonly RunStateService $runStateService,
        private readonly FeishuUrlEnricherService $feishuUrlEnricherService,
        private readonly SkillSandboxService $skillSandboxService,
        private readonly RunAuthFlowHelper $authFlowHelper,
        private readonly PlatformResultReporter $platformResultReporter,
        private readonly RunHistoryPreparer $historyPreparer,
    ) {
    }

    public function execute(int $runId): void
    {
        $run = Run::query()->with(['conversation.messages', 'user'])->findOrFail($runId);

        // Guard: skip if this run was already completed (e.g. duplicate queue pickup
        // due to retry_after timeout on long-running jobs).
        $currentStatus = strtolower(trim((string) ($run->status ?? '')));
        if (in_array($currentStatus, [Run::STATUS_SUCCESS, Run::STATUS_FAILED, Run::STATUS_WAITING_AUTH, Run::STATUS_NEEDS_INPUT], true)) {
            \Illuminate\Support\Facades\Log::info('[RunExecution] Skipping already-completed run', [
                'run_id' => $runId,
                'status' => $currentStatus,
            ]);
            return;
        }

        $this->runStateService->transition($run, Run::STATUS_RUNNING, 'execution_started');

        try {
            $messageRows = $this->historyPreparer->load($run);
            $rawMessagesOriginal = $messageRows->map(fn ($item) => ['role' => (string) $item->role, 'content' => (string) $item->content, 'meta' => is_array($item->meta) ? $item->meta : []])->values()->all();
            $messages = $this->historyPreparer->compress($run, $rawMessagesOriginal);
            $rawMessages = $messages;

            $inputAudit = $this->auditService->auditInput($run, MessageTextExtractor::latestUserText($rawMessages));
            if (($inputAudit['blocked'] ?? false) === true) {
                $this->completeBlocked(
                    $run,
                    trim((string) ($inputAudit['message'] ?? 'Input blocked by audit policy.')) ?: 'Input blocked by audit policy.',
                    'audit-policy',
                    ['intent' => 'audit_blocked', 'matched_terms' => (array) ($inputAudit['matched_terms'] ?? [])],
                    Run::STATUS_FAILED,
                    'error'
                );

                return;
            }

            $knowledge = $this->attachmentService->prepareKnowledgeContextForRun($run, $messageRows);
            if (is_string($knowledge['prompt'] ?? null) && trim((string) $knowledge['prompt']) !== '') {
                array_unshift($messages, ['role' => 'system', 'content' => (string) $knowledge['prompt']]);
                $this->emit($run, 'thinking', 'Loaded attachment knowledge context.', [
                    'knowledge_hits' => (int) ($knowledge['hits'] ?? 0),
                    'attachment_count' => (int) ($knowledge['attachments'] ?? 0),
                ]);
            }

            // ── Enrich Feishu document URLs ──
            // If user message contains Feishu doc/wiki links, auto-fetch content
            // using user's CLI keychain identity and inject as context
            $latestText = MessageTextExtractor::latestUserText($rawMessages);
            $urlContext = $this->feishuUrlEnricherService->enrichFromMessage((int) $run->user_id, $latestText);
            if ($urlContext !== null) {
                array_unshift($messages, ['role' => 'system', 'content' => $urlContext]);
                $this->emit($run, 'thinking', "\u{5DF2}\u{81EA}\u{52A8}\u{8BFB}\u{53D6}\u{98DE}\u{4E66}\u{6587}\u{6863}\u{5185}\u{5BB9}\u{3002}");
            }

            // Emit early thinking event so card appears BEFORE LLM intent classification
            $this->emit($run, 'thinking', "\u{6B63}\u{5728}\u{7406}\u{89E3}\u{4F60}\u{7684}\u{9700}\u{6C42}\u{3002}");

            if (! $this->executeWithToolCalling($run, $rawMessages, $messages, $messageRows)) {
                $this->completeBlocked(
                    $run,
                    'Tool calling failed to handle the request.',
                    (string) ($run->model ?? 'unknown'),
                    ['fallback' => 'tool_calling_failure'],
                    Run::STATUS_FAILED,
                    'error'
                );
            }
            return;

        } catch (Throwable $e) {
            $this->runStateService->transition($run, Run::STATUS_FAILED, 'execution_exception', [
                'exception' => get_class($e),
            ]);
            $this->emit($run, 'error', $e->getMessage(), ['exception' => get_class($e)]);
            if ($e instanceof DomainException) {
                return;
            }
            throw $e;
        }
    }

    private function executeWithToolCalling(Run $run, array $rawMessages, array $messages, Collection $messageRows): bool
    {
        $this->emit($run, 'thinking', 'Tool calling is planning the next step.');

        // Enable card display during agent loop (intent not yet set at this point).
        $run->interaction_mode = Run::INTERACTION_CARD;
        $run->save();

        $agentProgressCallback = function (string $eventType, string $message, array $payload = []) use ($run) {
            $this->emit($run, $eventType, $message, $payload);
        };

        $result = $this->toolCallingAgentService->handle($run, $rawMessages, $messages, $agentProgressCallback);
        $type = strtolower(trim((string) ($result['type'] ?? '')));

        if ($type === 'error') {
            \Illuminate\Support\Facades\Log::warning('[RunExecution] Tool calling failed', [
                'run_id' => $run->id,
                'error' => (string) ($result['message'] ?? 'unknown'),
            ]);

            // 先把卡片切到 error 终态再 revert mode，否则早期发出去的卡片会 stuck 在
            // "排队中 / LLM is planning the next step" 状态（mode 切回 null 后，
            // 后续 emit 不会再触发 syncRunCard，旧卡片永远不会更新）。
            $this->feishuService->syncRunCard($run, 'error', (string) ($result['message'] ?? 'tool_calling_failed'), ['intent' => 'tool_calling']);

            $run->interaction_mode = null;
            $run->save();
            return false;
        }

        if ($type === 'text_response') {

            // 先把卡片切到 final 终态再 revert mode：早期 ToolCalling 强行开了 card 模式
            // 让用户看到"排队中 / LLM 思考"进度，但 ToolCalling 跑完发现是 question
            // 不需要 task 工具时，要把卡片关掉变成纯文本回复。如果直接 mode=null，
            // 后续 emit('final') 不会再触发卡片更新，早期卡片永远 stuck。
            $this->feishuService->syncRunCard($run, 'final', '已为你准备好回复', ['intent' => 'question']);

            // Not a task — revert card mode so the natural-language reply goes as text.
            $run->interaction_mode = null;
            $run->save();
            $intent = [
                'intent' => Run::INTENT_QUESTION,
                'execution_mode' => 'respond',
                'confidence' => (float) ($result['confidence'] ?? 0.95),
                'reason' => 'tool_calling_text_response',
                'task_kind' => 'none',
                'source' => 'tool_calling',
            ];
            $this->applyIntent($run, $intent);

            $this->emit($run, 'intent', 'Intent detected.', [
                'intent' => Run::INTENT_QUESTION,
                'confidence' => (float) ($intent['confidence'] ?? 0),
                'reason' => (string) ($intent['reason'] ?? ''),
                'execution_mode' => 'respond',
                'task_kind' => 'none',
                'work_action' => '',
                'required_capabilities' => [],
                'source' => 'tool_calling',
            ]);

            $answer = trim((string) ($result['content'] ?? ''));
            $outputAudit = $this->auditService->auditOutput($run, $answer);
            $answer = trim((string) ($outputAudit['content'] ?? $answer));
            $persistMemory = ($outputAudit['blocked'] ?? false) !== true;

            if ($answer === '') {
                $answer = 'I have received your message.';
            }


            $model = trim((string) ($result['model'] ?? 'unknown'));
            $inputTokens = (int) ($result['input_tokens'] ?? 0);
            $outputTokens = (int) ($result['output_tokens'] ?? 0);

            Message::query()->create([
                'conversation_id' => $run->conversation_id,
                'user_id' => null,
                'role' => 'assistant',
                'content' => $answer,
                'meta' => ['run_id' => $run->id, 'model' => $model, 'intent' => Run::INTENT_QUESTION],
            ]);

            $run->model = $model;
            $run->input_tokens = $inputTokens;
            $run->output_tokens = $outputTokens;
            $run->save();
            $this->runStateService->transition($run, Run::STATUS_SUCCESS, 'execution_completed', [
                'intent' => Run::INTENT_QUESTION,
                'model' => $model,
                'source' => 'tool_calling',
            ]);

            $this->quotaService->consume($run, $inputTokens + $outputTokens);

            if ($persistMemory) {
                $this->persistMemorySafely($run, $answer, [
                    'model' => $model,
                ], 'tool_calling_text_response');
            }

            $this->emit($run, 'final', $answer, [
                'model' => $model,
                'intent' => Run::INTENT_QUESTION,
                'source' => 'tool_calling',
            ]);

            return true;
        }

        if ($type === 'agent_task_response') {
            $workAction = is_array($result['work_action'] ?? null) ? $result['work_action'] : [];
            $platformResult = is_array($result['platform_result'] ?? null) ? $result['platform_result'] : [];
            if ($workAction === [] || $platformResult === []) {
                \Illuminate\Support\Facades\Log::warning('[RunExecution] Tool calling returned incomplete agent task response', [
                    'run_id' => $run->id,
                    'has_work_action' => $workAction !== [],
                    'has_platform_result' => $platformResult !== [],
                ]);

                return false;
            }

            $intent = [
                'intent' => Run::INTENT_TASK,
                'execution_mode' => 'execute',
                'confidence' => (float) ($result['confidence'] ?? 0.96),
                'reason' => 'tool_calling_agent_task_response',
                'task_kind' => (string) ($workAction['task_kind'] ?? 'general_task'),
                'required_capabilities' => (array) ($workAction['required_capabilities'] ?? []),
                'source' => 'tool_calling',
                'work_action' => $workAction,
            ];
            $this->applyIntent($run, $intent);

            $this->emit($run, 'intent', 'Intent detected.', [
                'intent' => Run::INTENT_TASK,
                'confidence' => (float) ($intent['confidence'] ?? 0),
                'reason' => (string) ($intent['reason'] ?? ''),
                'execution_mode' => 'execute',
                'task_kind' => (string) ($workAction['task_kind'] ?? 'general_task'),
                'work_action' => (string) ($workAction['action_key'] ?? ''),
                'required_capabilities' => (array) ($workAction['required_capabilities'] ?? []),
                'source' => 'tool_calling',
            ]);

            $focusOutput = $this->extractFocusOutputFromPlatformResult($platformResult);
            if (is_array($focusOutput)) {
                $this->storeRunFocusOutput($run, $focusOutput);
            }

            $answer = trim((string) ($result['content'] ?? ''));
            $outputAudit = $this->auditService->auditOutput($run, $answer);
            $answer = trim((string) ($outputAudit['content'] ?? $answer));
            $persistMemory = ($outputAudit['blocked'] ?? false) !== true;

            if ($answer === '') {
                $answer = trim((string) ($platformResult['answer'] ?? ''));
            }
            if ($answer === '') {
                $answer = 'Task completed.';
            }


            $this->emit($run, 'tool_end', 'Agent loop execution completed.', [
                'work_action' => $workAction['action_key'] ?? null,
                'task_kind' => $workAction['task_kind'] ?? null,
                'source' => 'tool_calling',
                'tool_trace' => (array) ($result['tool_trace'] ?? []),
            ]);

            $this->completeEarly(
                $run,
                $answer,
                (string) ($result['model'] ?? 'tool_calling'),
                array_filter([
                    'source' => 'tool_calling',
                    'persist_memory' => $persistMemory,
                    'focus_output' => $focusOutput,
                    'tool_trace' => (array) ($result['tool_trace'] ?? []),
                ], static fn ($value) => $value !== null),
                (int) ($result['input_tokens'] ?? 0),
                (int) ($result['output_tokens'] ?? 0)
            );

            return true;
        }

        if ($type === 'tool_result') {
            $workAction = is_array($result['work_action'] ?? null) ? $result['work_action'] : [];
            $platformResult = is_array($result['platform_result'] ?? null) ? $result['platform_result'] : [];
            if ($workAction === [] || $platformResult === []) {
                \Illuminate\Support\Facades\Log::warning('[RunExecution] Tool calling returned incomplete tool result', [
                    'run_id' => $run->id,
                    'has_work_action' => $workAction !== [],
                    'has_platform_result' => $platformResult !== [],
                ]);

                return false;
            }

            $intent = [
                'intent' => Run::INTENT_TASK,
                'execution_mode' => 'execute',
                'confidence' => (float) ($result['confidence'] ?? 0.95),
                'reason' => 'tool_calling_tool_result',
                'task_kind' => (string) ($workAction['task_kind'] ?? 'general_task'),
                'required_capabilities' => (array) ($workAction['required_capabilities'] ?? []),
                'source' => 'tool_calling',
                'work_action' => $workAction,
            ];
            $this->applyIntent($run, $intent);

            $this->emit($run, 'intent', 'Intent detected.', [
                'intent' => Run::INTENT_TASK,
                'confidence' => (float) ($intent['confidence'] ?? 0),
                'reason' => (string) ($intent['reason'] ?? ''),
                'execution_mode' => 'execute',
                'task_kind' => (string) ($workAction['task_kind'] ?? 'general_task'),
                'work_action' => (string) ($workAction['action_key'] ?? ''),
                'required_capabilities' => (array) ($workAction['required_capabilities'] ?? []),
                'source' => 'tool_calling',
            ]);

            $this->emit($run, 'tool_start', 'Starting work action execution.', [
                'work_action' => $workAction['action_key'] ?? null,
                'executor' => $workAction['executor'] ?? null,
                'task_kind' => $workAction['task_kind'] ?? null,
                'source' => 'tool_calling',
            ]);

            if (($platformResult['handled'] ?? false) === true) {
                $this->handlePlatformSkillResult($run, $rawMessages, $messageRows, $intent, [
                    'skill_key' => (string) ($workAction['action_key'] ?? ''),
                    'executor' => (string) ($workAction['executor'] ?? ''),
                    'task_kind' => (string) ($workAction['task_kind'] ?? 'general_task'),
                    'task_kinds' => array_values((array) ($workAction['legacy_task_kinds'] ?? [])),
                ], $platformResult);

                return true;
            }

            $answer = trim((string) ($platformResult['answer'] ?? ''));
            $outputAudit = $this->auditService->auditOutput($run, $answer);
            $answer = trim((string) ($outputAudit['content'] ?? $answer));
            if ($answer === '') {
                $answer = 'Task completed.';
            }

            $this->completeEarly(
                $run,
                $answer,
                (string) ($platformResult['model'] ?? ($result['model'] ?? 'tool_calling')),
                [
                    'source' => 'tool_calling',
                    'persist_memory' => false,
                ],
                (int) ($platformResult['input_tokens'] ?? ($result['input_tokens'] ?? 0)),
                (int) ($platformResult['output_tokens'] ?? ($result['output_tokens'] ?? 0))
            );

            return true;
        }

        // ── LLM-driven authorization: agent decided auth is needed ──
        if ($type === 'authorize_request') {
            $workAction = is_array($result['work_action'] ?? null) ? $result['work_action'] : [];
            $platformResult = is_array($result['platform_result'] ?? null) ? $result['platform_result'] : [];
            $missing = array_values(array_unique(array_filter(array_map(
                static fn ($item) => trim((string) $item),
                (array) ($platformResult['missing_capabilities'] ?? ['feishu.oauth.user_token'])
            ))));

            $intent = [
                'intent' => Run::INTENT_TASK,
                'execution_mode' => 'execute',
                'confidence' => 0.95,
                'reason' => 'tool_calling_authorize_request',
                'task_kind' => (string) ($workAction['task_kind'] ?? ($platformResult['task_kind'] ?? 'general_task')),
                'required_capabilities' => $missing,
                'source' => 'tool_calling',
                'work_action' => $workAction,
            ];
            $this->applyIntent($run, $intent);

            $this->emit($run, 'intent', 'Intent detected.', [
                'intent' => Run::INTENT_TASK,
                'confidence' => 0.95,
                'reason' => 'tool_calling_authorize_request',
                'execution_mode' => 'execute',
                'task_kind' => (string) ($workAction['task_kind'] ?? 'general_task'),
                'work_action' => (string) ($workAction['action_key'] ?? ''),
                'required_capabilities' => $missing,
                'source' => 'tool_calling',
            ]);

            $this->triggerDeviceFlowAndBlock(
                $run,
                $messageRows,
                $missing,
                (string) ($workAction['task_kind'] ?? ($platformResult['task_kind'] ?? 'general_task')),
                trim((string) ($platformResult['answer'] ?? '')) !== '' ? (string) $platformResult['answer'] : '\u{9700}\u{8981}\u{98DE}\u{4E66}\u{6388}\u{6743}\u{540E}\u{624D}\u{80FD}\u{7EE7}\u{7EED}\u{FF0C}\u{8BF7}\u{70B9}\u{51FB}\u{4E0A}\u{65B9}\u{94FE}\u{63A5}\u{5B8C}\u{6210}\u{6388}\u{6743}\u{3002}',
                (string) ($result['model'] ?? 'tool_calling'),
                array_filter([
                    'missing_capabilities' => $missing,
                    'tool_trace' => (array) ($result['tool_trace'] ?? []),
                    'source' => 'llm_driven_auth',
                ], static fn ($value) => $value !== [] && $value !== null),
                '',
                (int) ($result['input_tokens'] ?? 0),
                (int) ($result['output_tokens'] ?? 0)
            );

            return true;
        }

        \Illuminate\Support\Facades\Log::warning('[RunExecution] Tool calling returned unexpected result type', [
            'run_id' => $run->id,
            'type' => $type,
        ]);

        return false;
    }

    /**
     * Thin shell: delegate auth preparation to RunAuthFlowHelper, then
     * invoke completeBlocked here so the persistMemorySafely call chain
     * stays inside this class (wiring test invariant).
     */
    private function triggerDeviceFlowAndBlock(
        Run $run,
        Collection $messageRows,
        array $missingCapabilities,
        string $taskKind,
        string $message,
        string $model,
        array $meta = [],
        string $oauthUrl = '',
        int $inputTokens = 0,
        int $outputTokens = 0
    ): void {
        $this->authFlowHelper->prepareDeviceFlow(
            $run,
            $messageRows,
            $missingCapabilities,
            $taskKind,
            $oauthUrl
        );

        $this->completeBlocked(
            $run,
            trim($message) !== '' ? $message : "\u{9700}\u{8981}\u{98DE}\u{4E66}\u{6388}\u{6743}\u{540E}\u{624D}\u{80FD}\u{7EE7}\u{7EED}\u{FF0C}\u{8BF7}\u{70B9}\u{51FB}\u{4E0A}\u{65B9}\u{94FE}\u{63A5}\u{5B8C}\u{6210}\u{6388}\u{6743}\u{3002}",
            $model,
            $meta,
            Run::STATUS_WAITING_AUTH,
            'waiting_auth',
            $inputTokens,
            $outputTokens
        );
    }

    private function applyIntent(Run $run, array $intent): void
    {
        $intentType = strtolower(trim((string) ($intent['intent'] ?? Run::INTENT_QUESTION)));
        if (! in_array($intentType, [Run::INTENT_CHAT, Run::INTENT_QUESTION, Run::INTENT_TASK], true)) {
            $intentType = Run::INTENT_QUESTION;
        }

        $executionMode = strtolower(trim((string) ($intent['execution_mode'] ?? 'respond')));
        $run->interaction_mode = ($intentType === Run::INTENT_TASK && $executionMode !== 'respond')
            ? Run::INTERACTION_CARD
            : Run::INTERACTION_TEXT;

        $meta = is_array($run->intent_meta) ? $run->intent_meta : [];
        $meta['reason'] = (string) ($intent['reason'] ?? '');
        $meta['execution_mode'] = $executionMode;
        $meta['task_kind'] = (string) ($intent['task_kind'] ?? 'none');
        $meta['required_capabilities'] = (array) ($intent['required_capabilities'] ?? []);
        $meta['source'] = (string) ($intent['source'] ?? '');
        $meta['should_clarify'] = (bool) ($intent['should_clarify'] ?? false);
        $meta['clarify_question'] = (string) ($intent['clarify_question'] ?? '');
        $meta['trigger_message_id'] = (int) ($meta['trigger_message_id'] ?? 0);

        if (is_array($intent['work_action'] ?? null)) {
            $meta['work_action'] = [
                'action_key' => (string) ($intent['work_action']['action_key'] ?? ''),
                'label' => (string) ($intent['work_action']['label'] ?? ''),
                'executor' => (string) ($intent['work_action']['executor'] ?? ''),
            ];
        } else {
            unset($meta['work_action']);
        }
        unset($meta['integration_skill']);

        $run->intent_type = $intentType;
        $run->intent_confidence = (float) ($intent['confidence'] ?? 0.0);
        $run->intent_meta = $meta;
        $run->save();
    }

    private function assertQuota(Run $run, array $messages): void
    {
        $payload = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $chars = $this->textLength(is_string($payload) ? $payload : '');
        $plannedTokens = max(320, (int) ceil($chars / 3.4) + 256);

        $this->quotaService->assertWithinQuota($run->user, $plannedTokens);
    }

    private function completeBlocked(Run $run, string $message, string $model, array $meta = [], string $status = Run::STATUS_FAILED, string $eventType = 'error', int $inputTokens = 0, int $outputTokens = 0): void
    {
        $message = trim($message);
        if ($message === '') {
            $message = '抱歉，任务无法执行。';
        }

        Message::query()->create([
            'conversation_id' => $run->conversation_id,
            'user_id' => null,
            'role' => 'assistant',
            'content' => $message,
            'meta' => array_filter(['run_id' => $run->id, 'model' => $model, 'intent' => $run->intent_type, 'blocked' => true, 'tool_trace' => $meta['tool_trace'] ?? null], static fn ($v) => $v !== null && $v !== []),
        ]);

        $this->recordRunUsage($run, $model, $inputTokens, $outputTokens);
        $this->persistCompletionMeta($run, $meta);

        $this->runStateService->transition($run, $status, 'execution_blocked', [
            'intent' => $run->intent_type,
            'model' => $model,
            'meta' => $meta,
        ]);

        $this->emit($run, $eventType, $message, array_merge([
            'model' => $model,
            'intent' => $run->intent_type,
            'blocked' => true,
        ], $meta));
    }

    private function completeEarly(Run $run, string $message, string $model, array $meta = [], int $inputTokens = 0, int $outputTokens = 0): void
    {
        $message = trim($message);
        if ($message === '') {
            $message = '任务已完成。';
        }

        Message::query()->create([
            'conversation_id' => $run->conversation_id,
            'user_id' => null,
            'role' => 'assistant',
            'content' => $message,
            'meta' => array_filter(['run_id' => $run->id, 'model' => $model, 'intent' => $run->intent_type, 'early_completed' => true, 'tool_trace' => $meta['tool_trace'] ?? null], static fn ($v) => $v !== null && $v !== []),
        ]);

        $this->recordRunUsage($run, $model, $inputTokens, $outputTokens);
        $this->persistCompletionMeta($run, $meta);

        $this->runStateService->transition($run, Run::STATUS_SUCCESS, 'execution_completed_early', [
            'intent' => $run->intent_type,
            'model' => $model,
            'meta' => $meta,
        ]);

        if (($meta['persist_memory'] ?? false) === true) {
            $this->persistMemorySafely($run, $message, [
                'session_key' => $meta['session_key'] ?? null,
                'model' => $model,
            ], 'early_completion');
        }

        $this->emit($run, 'final', $message, array_merge(['model' => $model, 'intent' => $run->intent_type], $meta));
    }

    private function completeClarification(Run $run, string $message, string $model, array $meta = [], int $inputTokens = 0, int $outputTokens = 0): void
    {
        $message = trim($message);
        if ($message === '') {
            $message = '请补充一些关键信息，我才能继续执行。';
        }

        Message::query()->create([
            'conversation_id' => $run->conversation_id,
            'user_id' => null,
            'role' => 'assistant',
            'content' => $message,
            'meta' => array_filter(['run_id' => $run->id, 'model' => $model, 'intent' => $run->intent_type, 'clarification_requested' => true, 'tool_trace' => $meta['tool_trace'] ?? null], static fn ($v) => $v !== null && $v !== []),
        ]);

        $this->recordRunUsage($run, $model, $inputTokens, $outputTokens);
        $this->persistCompletionMeta($run, $meta);

        $this->runStateService->transition($run, Run::STATUS_NEEDS_INPUT, 'execution_clarification_requested', [
            'intent' => $run->intent_type,
            'model' => $model,
            'meta' => $meta,
        ]);

        $this->emit($run, 'clarify', $message, array_merge(['model' => $model, 'intent' => $run->intent_type], $meta));
    }

    private function recordRunUsage(Run $run, string $model, int $inputTokens = 0, int $outputTokens = 0): void
    {
        $run->model = $model;
        $run->input_tokens = max(0, $inputTokens);
        $run->output_tokens = max(0, $outputTokens);
        $run->save();

        $totalTokens = max(0, $inputTokens) + max(0, $outputTokens);
        if ($totalTokens > 0) {
            $this->quotaService->consume($run, $totalTokens);
        }
    }

    private function persistCompletionMeta(Run $run, array $meta): void
    {
        if ($meta === []) {
            return;
        }

        $current = is_array($run->intent_meta) ? $run->intent_meta : [];
        $run->intent_meta = array_merge($current, $meta);
        $run->save();
    }

    /**
     * Memory persistence is best-effort: a storage or permission issue must not
     * turn an otherwise successful business action into a failed run.
     *
     * @param  array<string, mixed>  $context
     */
    private function persistMemorySafely(Run $run, string $message, array $context = [], string $phase = 'runtime'): void
    {
        try {
            $this->memoryService->persist($run, $message, $context);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[RunExecution] Non-fatal memory persistence failure', [
                'run_id' => $run->id,
                'phase' => $phase,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function handlePlatformSkillResult(Run $run, array $rawMessages, Collection $messageRows, array $intent, array $integrationSkill, array $platformResult): void
    {
        $status = strtolower(trim((string) ($platformResult['status'] ?? 'failed')));
        $model = (string) ($platformResult['model'] ?? ($integrationSkill['executor'] ?? 'platform-skill'));
        $baseInputTokens = (int) ($platformResult['input_tokens'] ?? 0);
        $baseOutputTokens = (int) ($platformResult['output_tokens'] ?? 0);

        if ($status === 'blocked' || $status === 'failed') {
            $missing = (array) ($platformResult['missing_capabilities'] ?? []);
            $authBlocked = $this->authFlowHelper->isAuthorizationBlockedResult($platformResult);

            if ($authBlocked) {
                $this->triggerDeviceFlowAndBlock(
                    $run,
                    $messageRows,
                    $missing,
                    (string) ($platformResult['task_kind'] ?? ($intent['task_kind'] ?? 'general_task')),
                    (string) ($platformResult['answer'] ?? "\u{9700}\u{8981}\u{98DE}\u{4E66}\u{6388}\u{6743}\u{540E}\u{624D}\u{80FD}\u{7EE7}\u{7EED}\u{FF0C}\u{8BF7}\u{70B9}\u{51FB}\u{4E0A}\u{65B9}\u{94FE}\u{63A5}\u{5B8C}\u{6210}\u{6388}\u{6743}\u{3002}"),
                    $model,
                    array_filter([
                        'missing_capabilities' => $missing,
                    ], static fn ($value) => $value !== [] && $value !== null),
                    '',
                    $baseInputTokens,
                    $baseOutputTokens
                );

                return;
            }

            $errorReply = $this->platformResultReporter->report($run, $rawMessages, $platformResult, $integrationSkill);
            $this->completeBlocked(
                $run,
                (string) ($errorReply['message'] ?? ''),
                $model,
                array_filter([
                    'missing_capabilities' => $missing,
                    'session_key' => $errorReply['session_key'] ?? null,
                ], static fn ($value) => $value !== null && $value !== []),
                Run::STATUS_FAILED,
                'error',
                $baseInputTokens + (int) ($errorReply['input_tokens'] ?? 0),
                $baseOutputTokens + (int) ($errorReply['output_tokens'] ?? 0)
            );

            return;
        }

        if ($status === 'clarify') {
            $focusOutput = $this->extractFocusOutputFromPlatformResult($platformResult);
            if (is_array($focusOutput)) {
                $this->storeRunFocusOutput($run, $focusOutput);
            }

            $this->completeClarification(
                $run,
                (string) ($platformResult['answer'] ?? ''),
                $model,
                array_filter([
                    'persist_memory' => false,
                    'focus_output' => $focusOutput,
                ], static fn ($value) => $value !== null),
                $baseInputTokens,
                $baseOutputTokens
            );

            return;
        }

        $focusOutput = $this->extractFocusOutputFromPlatformResult($platformResult);
        if (is_array($focusOutput)) {
            $this->storeRunFocusOutput($run, $focusOutput);
        }

        $this->emit($run, 'tool_end', 'Platform skill execution completed.');
        $naturalReply = $this->platformResultReporter->report($run, $rawMessages, $platformResult, $integrationSkill);
        $this->completeEarly(
            $run,
            (string) ($naturalReply['message'] ?? ''),
            $model,
            array_filter([
                'persist_memory' => true,
                'session_key' => $naturalReply['session_key'] ?? null,
                'focus_output' => $focusOutput,
            ], static fn ($value) => $value !== null),
            $baseInputTokens + (int) ($naturalReply['input_tokens'] ?? 0),
            $baseOutputTokens + (int) ($naturalReply['output_tokens'] ?? 0)
        );
    }

    /**
     * @param  array<string,mixed>  $platformResult
     * @return array<string,mixed>|null
     */
    private function extractFocusOutputFromPlatformResult(array $platformResult): ?array
    {
        $focusOutput = $this->focusEntityExtractor->extractFromPlatformResult($platformResult);
        if (! is_array($focusOutput)) {
            return null;
        }

        return $focusOutput;
    }

    /**
     * @param  array<string,mixed>  $focusOutput
     */
    private function storeRunFocusOutput(Run $run, array $focusOutput): void
    {
        if (($focusOutput['object_type'] ?? '') === '') {
            return;
        }

        $meta = is_array($run->intent_meta) ? $run->intent_meta : [];
        $meta['focus_output'] = $focusOutput;
        $run->intent_meta = $meta;
        $run->save();
    }

    private function textTruncate(string $text, int $max): string
    {
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') <= $max) {
            return $text;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $max - 1, 'UTF-8') . '…';
        }
        return strlen($text) <= $max ? $text : substr($text, 0, $max - 3) . '...';
    }

    private function emit(Run $run, string $eventType, ?string $message = null, array $payload = []): RunEvent
    {
        $event = RunEvent::query()->create([
            'run_id' => $run->id,
            'event_type' => $eventType,
            'message' => $message,
            'payload' => $payload,
        ]);

        try {
            $this->memoryService->appendRunEvent($run, $eventType, $message, $payload);
        } catch (Throwable) {
            // keep execution resilient when memory logging fails
        }

        if ($run->feishu_chat_id && in_array($eventType, ['thinking', 'intent', 'tool_start', 'tool_log', 'tool_end', 'final', 'error', 'waiting_auth', 'clarify'], true)) {
            // Keep the first task card as the single execution surface, including waiting_auth.
            if ($this->shouldUseCard($run)) {
                $this->feishuService->syncRunCard($run, $eventType, $message, $payload);
            }
            // Send text reply for terminal events.
            // For 'final': always send, even when card was synced, because the card
            // only shows execution steps — the natural-language reply goes as a
            // separate text message so the user actually sees it.
            if (in_array($eventType, ['final', 'error', 'clarify'], true)) {
                $this->feishuService->sendHybridDeltaText($run, $eventType, $message);
            }

            if (in_array($eventType, ['final', 'error', 'waiting_auth', 'clarify'], true)) {
                $this->feishuService->clearThinkingReaction($run);
            }
        }

        return $event;
    }

    private function shouldUseCard(Run $run): bool
    {
        $mode = strtolower(trim((string) ($run->interaction_mode ?? '')));
        if ($mode === Run::INTERACTION_CARD) {
            return true;
        }

        return strtolower(trim((string) ($run->intent_type ?? ''))) === Run::INTENT_TASK;
    }

    private function textLength(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($text, 'UTF-8');
        }

        return strlen($text);
    }

}
