<?php

namespace App\Services;

use App\Models\Run;
use App\Models\UserIdentity;
use App\Services\Prompt\PromptComposer;
use App\Support\MessageTextExtractor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ToolCallingAgentService
{
    private const MAX_AGENT_STEPS = 5;

    private const MAX_TOOL_CALLS = 8;

    private const MAX_REPEAT_SAME_CALL = 2;

    /**
     * How many recent assistant messages with meta.tool_trace to restore into
     * the LLM context in OpenAI tool_calls format. Prevents the "poisonous
     * message" pattern where a resumed run rebuilds context from prose only
     * and the LLM infers stale blocked/auth state from old assistant text.
     */
    private const TOOL_TRACE_REPLAY_WINDOW = 3;

    /** Truncation threshold (bytes) for restored tool result payloads. */
    private const RESTORED_TOOL_CONTENT_MAX = 4000;

    public function __construct(
        private readonly LlmGatewayService $llmGatewayService,
        private readonly ToolRegistryService $toolRegistryService,
        private readonly ToolCallExecutorService $toolCallExecutorService,
        private readonly LarkResultNormalizerService $larkResultNormalizerService,
        private readonly MemoryService $memoryService,
        private readonly ?PromptComposer $promptComposer = null,
        private readonly ?SkillRuntimeService $skillRuntimeService = null,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawMessages
     * @param  array<int, array<string, mixed>>  $enrichedMessages
     * @return array<string, mixed>
     */
    public function handle(Run $run, array $rawMessages, array $enrichedMessages = [], ?callable $onProgress = null): array
    {
        $messages = $enrichedMessages !== [] ? $enrichedMessages : $rawMessages;

        try {
            $systemPrompt = $this->buildSystemPrompt($run, $rawMessages);
            $tools = $this->toolRegistryService->getTools();
            $llmMessages = array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $this->buildConversationMessages($messages)
            );

            $totalInputTokens = 0;
            $totalOutputTokens = 0;
            $model = 'unknown';
            $usedTools = false;
            $toolHistory = [];
            $toolCallFingerprints = [];
            $lastWorkAction = [];
            $lastPlatformResult = null;

            for ($step = 0; $step < self::MAX_AGENT_STEPS; $step++) {
                if ($onProgress !== null && $step === 0) {
                    $onProgress('thinking', 'LLM is planning the next step.', ['step' => $step + 1]);
                }

                $response = $this->llmGatewayService->chatWithTools($llmMessages, $tools);
                $totalInputTokens += (int) ($response['input_tokens'] ?? 0);
                $totalOutputTokens += (int) ($response['output_tokens'] ?? 0);
                $model = (string) ($response['model'] ?? $model);

                $content = trim((string) ($response['content'] ?? ''));
                $toolCalls = array_values((array) ($response['tool_calls'] ?? []));

                if ($toolCalls === []) {
                    if (! $usedTools) {
                        return [
                            'type' => 'text_response',
                            'intent_type' => Run::INTENT_QUESTION,
                            'content' => $this->applyReplyConstraints($content),
                            'model' => $model,
                            'input_tokens' => $totalInputTokens,
                            'output_tokens' => $totalOutputTokens,
                        ];
                    }

                    if (! is_array($lastPlatformResult) || $lastWorkAction === []) {
                        return [
                            'type' => 'error',
                            'message' => 'Agent loop ended without a stable tool result.',
                        ];
                    }

                    if (($lastPlatformResult['status'] ?? 'failed') !== 'success') {
                        return $this->buildToolResultResponse(
                            $model,
                            $totalInputTokens,
                            $totalOutputTokens,
                            $lastWorkAction,
                            $lastPlatformResult,
                            $toolHistory
                        );
                    }

                    return $this->buildAgentTaskResponse(
                        $content,
                        $model,
                        $totalInputTokens,
                        $totalOutputTokens,
                        $lastWorkAction,
                        $lastPlatformResult,
                        $toolHistory
                    );
                }

                $llmMessages[] = [
                    'role' => 'assistant',
                    'content' => $content !== '' ? $content : null,
                    'tool_calls' => $toolCalls,
                ];

                foreach ($toolCalls as $index => $toolCall) {
                    if (count($toolHistory) >= self::MAX_TOOL_CALLS) {
                        if (is_array($lastPlatformResult) && $lastWorkAction !== []) {
                            if (($lastPlatformResult['status'] ?? 'failed') === 'success') {
                                return $this->buildAgentTaskResponse(
                                    '',
                                    $model,
                                    $totalInputTokens,
                                    $totalOutputTokens,
                                    $lastWorkAction,
                                    $lastPlatformResult,
                                    $toolHistory
                                );
                            }

                            return $this->buildToolResultResponse(
                                $model,
                                $totalInputTokens,
                                $totalOutputTokens,
                                $lastWorkAction,
                                $lastPlatformResult,
                                $toolHistory
                            );
                        }

                        return [
                            'type' => 'error',
                            'message' => 'Agent loop exceeded maximum tool calls.',
                        ];
                    }

                    $resolved = $this->resolveToolCall($toolCall);
                    if (($resolved['error'] ?? null) !== null) {
                        return [
                            'type' => 'error',
                            'message' => (string) $resolved['error'],
                        ];
                    }

                    $fingerprint = $this->toolCallFingerprint(
                        (string) $resolved['action_key'],
                        (array) $resolved['params']
                    );
                    $toolCallFingerprints[$fingerprint] = (int) ($toolCallFingerprints[$fingerprint] ?? 0) + 1;

                    if ($toolCallFingerprints[$fingerprint] > self::MAX_REPEAT_SAME_CALL) {
                        if (is_array($lastPlatformResult) && $lastWorkAction !== []) {
                            if (($lastPlatformResult['status'] ?? 'failed') === 'success') {
                                return $this->buildAgentTaskResponse(
                                    '',
                                    $model,
                                    $totalInputTokens,
                                    $totalOutputTokens,
                                    $lastWorkAction,
                                    $lastPlatformResult,
                                    $toolHistory
                                );
                            }

                            return $this->buildToolResultResponse(
                                $model,
                                $totalInputTokens,
                                $totalOutputTokens,
                                $lastWorkAction,
                                $lastPlatformResult,
                                $toolHistory
                            );
                        }

                        return [
                            'type' => 'error',
                            'message' => 'Agent loop detected repeated identical tool calls.',
                        ];
                    }

                    if ($onProgress !== null) {
                        $onProgress('tool_start', 'Executing tool call.', [
                            'work_action' => (string) ($resolved['action_key'] ?? ''),
                            'function' => (string) ($resolved['function_name'] ?? ''),
                        ]);
                    }

                    // Defensive observability — on the very first LLM turn, if
                    // the model calls request_authorization while auth is
                    // actually present, our context-restoration failed or the
                    // system prompt was ignored. Log-only (ToolCallExecutor's
                    // short-circuit still returns success), so we learn about
                    // silent regressions without blocking the user.
                    if ($step === 0
                        && (string) ($resolved['action_key'] ?? '') === 'request_authorization') {
                        $probedState = $this->getCurrentAuthState($run);
                        if ((bool) ($probedState['feishu_cli_keychain_populated'] ?? false)
                            || (bool) ($probedState['feishu_user_access_token_present'] ?? false)) {
                            $this->logWarning('[ToolCallingAgent] request_authorization called on turn 0 with auth already present', [
                                'run_id' => $run->id,
                                'user_id' => $run->user_id,
                                'auth_state' => $probedState,
                            ]);
                        }
                    }

                    $execution = $this->executeResolvedToolCall($run, $rawMessages, $resolved, $model);
                    if (($execution['type'] ?? '') === 'error') {
                        return $execution;
                    }

                    $usedTools = true;
                    $lastWorkAction = (array) ($execution['work_action'] ?? []);
                    $lastPlatformResult = (array) ($execution['platform_result'] ?? []);

                    if ($onProgress !== null) {
                        $onProgress('tool_log', 'Tool execution completed.', [
                            'work_action' => (string) ($resolved['action_key'] ?? ''),
                            'status' => (string) ($lastPlatformResult['status'] ?? 'unknown'),
                        ]);
                    }

                    $toolHistory[] = [
                        'function' => (string) ($resolved['function_name'] ?? ''),
                        'action_key' => (string) ($resolved['action_key'] ?? ''),
                        'params' => (array) ($resolved['params'] ?? []),
                        'platform_result' => $lastPlatformResult,
                    ];

                    $rawResult = is_array($execution['raw_result'] ?? null) ? $execution['raw_result'] : [];
                    $totalInputTokens += (int) ($rawResult['input_tokens'] ?? 0);
                    $totalOutputTokens += (int) ($rawResult['output_tokens'] ?? 0);

                    $llmMessages[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) ($toolCall['id'] ?? 'tool_'.($step + 1).'_'.($index + 1)),
                        'content' => $this->buildToolFeedbackJson(
                            (string) ($resolved['action_key'] ?? ''),
                            $lastPlatformResult
                        ),
                    ];

                    // ── LLM-driven recovery: only exit loop for explicit authorize signal ──
                    // blocked/clarify results stay in the loop so LLM can inspect the error
                    // and decide the next step (retry, clarify, or call request_authorization).
                    if ((string) ($lastPlatformResult['status'] ?? '') === 'authorize') {
                        return [
                            'type' => 'authorize_request',
                            'intent_type' => Run::INTENT_TASK,
                            'work_action' => $lastWorkAction,
                            'platform_result' => $lastPlatformResult,
                            'tool_trace' => $toolHistory,
                            'model' => $model,
                            'input_tokens' => $totalInputTokens,
                            'output_tokens' => $totalOutputTokens,
                        ];
                    }
                }
            }

            if (is_array($lastPlatformResult) && $lastWorkAction !== []) {
                if (($lastPlatformResult['status'] ?? 'failed') === 'success') {
                    return $this->buildAgentTaskResponse(
                        '',
                        $model,
                        $totalInputTokens,
                        $totalOutputTokens,
                        $lastWorkAction,
                        $lastPlatformResult,
                        $toolHistory
                    );
                }

                return $this->buildToolResultResponse(
                    $model,
                    $totalInputTokens,
                    $totalOutputTokens,
                    $lastWorkAction,
                    $lastPlatformResult,
                    $toolHistory
                );
            }

            return [
                'type' => 'error',
                'message' => 'Agent loop reached the step limit without a result.',
            ];
        } catch (Throwable $e) {
            $this->logError('[ToolCallingAgent] Handle failed', [
                'run_id' => $run->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return [
                'type' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawMessages
     */
    private function buildSystemPrompt(Run $run, array $rawMessages): string
    {
        if ($this->promptComposer !== null && $this->composerEnabled()) {
            $latestUserText = MessageTextExtractor::latestUserText($rawMessages);
            $memoryText = '';
            try {
                $memoryText = $this->memoryService->getMemoryContext($run, $latestUserText);
            } catch (Throwable) {
                $memoryText = '';
            }

            $recentRefs = $this->extractRecentReferences($rawMessages);

            $skillCatalog = [];
            if ($this->skillRuntimeService !== null && $run->user !== null) {
                try {
                    $skillCatalog = $this->skillRuntimeService->buildSkillCatalog($run->user);
                } catch (Throwable) {
                    $skillCatalog = [];
                }
            }

            return $this->promptComposer->compose([
                'mode' => 'tool_calling',
                'time_context' => $this->toolRegistryService->getTimeContext(),
                'memory_context' => $memoryText,
                'recent_references' => $recentRefs,
                'skill_catalog' => $skillCatalog,
                'run_metadata' => $this->getCurrentAuthState($run),
            ]);
        }

        return $this->buildSystemPromptLegacy($run, $rawMessages);
    }

    /**
     * Read current feishu auth state from UserIdentity.extra, cached 30s.
     * Surfaced as structured key/value lines (via PromptComposer's
     * RuntimeSection or the legacy-path "Runtime state:" block) so the LLM
     * can decide whether to call request_authorization or go straight to the
     * real operation. Hard-failure returns [] — callers treat that as unknown.
     *
     * @return array<string, mixed>
     */
    private function getCurrentAuthState(Run $run): array
    {
        $userId = (int) ($run->user_id ?? 0);
        if ($userId <= 0) {
            return [];
        }
        $cacheKey = 'mifrog:auth_state:user:'.$userId;
        try {
            return Cache::remember($cacheKey, 30, function () use ($userId) {
                $identity = UserIdentity::query()
                    ->where('user_id', $userId)
                    ->where('provider', 'feishu')
                    ->first();
                if (! $identity) {
                    return [
                        'feishu_identity_linked' => false,
                        'feishu_cli_keychain_populated' => false,
                        'feishu_user_access_token_present' => false,
                    ];
                }
                $extra = is_array($identity->extra) ? $identity->extra : [];
                return [
                    'feishu_identity_linked' => true,
                    'feishu_cli_keychain_populated' => (bool) ($extra['cli_keychain_populated'] ?? false),
                    'feishu_user_access_token_present' => trim((string) ($extra['user_access_token'] ?? '')) !== '',
                ];
            });
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Defensive config probe — test environments may instantiate this service
     * without a booted container, in which case config() would explode.
     */
    private function composerEnabled(): bool
    {
        try {
            if (function_exists('app') && app()->bound('config')) {
                return (bool) config('mifrog.prompt.use_composer', true);
            }
        } catch (Throwable) {
            // fall through
        }
        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawMessages
     */
    private function buildSystemPromptLegacy(Run $run, array $rawMessages): string
    {
        $latestUserText = MessageTextExtractor::latestUserText($rawMessages);
        $lines = [
            'You are MiFrog, an enterprise assistant focused on Feishu execution tasks.',
            $this->toolRegistryService->getTimeContext(),
            'Rules:',
            '1. When the user clearly wants a Feishu action, you may use multiple tool steps until the full task is complete.',
            '2. At each step, choose the single best next tool based on the latest conversation and tool result.',
            '3. Searching, listing, reading, checking, creating, updating, or managing Feishu data always requires a tool call, even when the user is only asking for information.',
            '4. Reuse ids, links, or structured results from prior tool outputs whenever possible.',
            '5. If a tool fails, inspect the tool result and recover with another tool or adjusted parameters when possible.',
            '6. If execution is blocked by permissions or missing user-provided information, stop and ask one concise follow-up.',
            '7. Never invent ids, attendees, times, or any live Feishu data that is not grounded in the conversation or a tool result.',
            '8. Never answer contacts, docs, sheets, calendar, tasks, approvals, meetings, minutes, mail, wiki, drive, or base requests from memory alone; use the tool.',
            '9. After the task is fully complete, reply with the final outcome in the same language as the user unless they ask otherwise.',
            '10. For direct text replies, answer plainly and briefly. Do not greet, do not apologize, do not use markdown, and do not mention internal tool names, APIs, or implementation details unless the user explicitly asks for them.',
            '11. If no suitable tool exists for the request, explain the current capability boundary directly instead of promising hidden support.',
            '12. For complex tasks, first identify the missing information and the best next step. Do not force multiple dependent actions into one tool call.',
            '13. For simple requests that do not require live Feishu data, respond directly without unnecessary step-by-step narration.',
            '14. When the user asks about communication with a named person, prefer resolving that person and checking shared chats or direct chat context before concluding that nothing was found.',
        ];

        $recentRefs = $this->extractRecentReferences($rawMessages);
        if ($recentRefs !== []) {
            $lines[] = 'Recent conversation references you may reuse when the user says "just now", "that one", or similar follow-up phrasing:';
            foreach ($recentRefs as $label => $value) {
                $lines[] = "- {$label}: {$value}";
            }
        }

        try {
            $memoryText = $this->memoryService->getMemoryContext($run, $latestUserText);
            if ($memoryText !== '') {
                $lines[] = 'Memory context:';
                $lines[] = $memoryText;
            }
        } catch (Throwable) {
            // Memory enrichment should never block tool selection.
        }

        if ($this->skillRuntimeService !== null && $run->user !== null) {
            try {
                $catalog = $this->skillRuntimeService->buildSkillCatalog($run->user);
            } catch (Throwable) {
                $catalog = [];
            }
            if (! empty($catalog)) {
                $lines[] = 'Available skills — load via load_skill before use:';
                foreach ($catalog as $entry) {
                    $skillKey = trim((string) ($entry['skill_key'] ?? ''));
                    if ($skillKey === '') { continue; }
                    $executor = (string) ($entry['executor'] ?? 'llm');
                    $tag = match ($executor) {
                        'sandbox' => 'sandbox',
                        'http_api' => 'api',
                        default => 'instruction',
                    };
                    $label = '/' . $skillKey;
                    if (! empty($entry['name'])) { $label .= ' — ' . $entry['name']; }
                    $label .= ' [' . $tag . ']';
                    if (! empty($entry['description'])) { $label .= '：' . $entry['description']; }
                    $lines[] = '- ' . $label;
                    if ($executor === 'http_api' && ! empty($entry['api_params']) && is_array($entry['api_params'])) {
                        foreach ($entry['api_params'] as $param) {
                            if (! is_array($param)) { continue; }
                            $pName = trim((string) ($param['name'] ?? ''));
                            $pKey = trim((string) ($param['api_key'] ?? ''));
                            if ($pName === '' && $pKey === '') { continue; }
                            $marker = ((bool) ($param['required'] ?? false)) ? '必填' : '可选';
                            $pDesc = trim((string) ($param['description'] ?? ''));
                            $lines[] = '    · ' . ($pName !== '' ? $pName : $pKey) . ' (' . $marker . ')' . ($pDesc !== '' ? '：' . $pDesc : '');
                        }
                    }
                }
                $lines[] = 'Usage: call load_skill(skill_key) to read skill.md; then execute_sandbox_skill for [sandbox], execute_api_skill for [api] (pass a JSON request mapping param name→value), or follow instructions yourself for [instruction].';
            }
        }

        $authState = $this->getCurrentAuthState($run);
        if ($authState !== []) {
            $lines[] = 'Runtime state (trust this over any older assistant message you may recall):';
            foreach ($authState as $stateKey => $stateVal) {
                $rendered = is_bool($stateVal) ? ($stateVal ? 'true' : 'false') : (string) $stateVal;
                $lines[] = '- '.$stateKey.': '.$rendered;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Build the LLM conversation array, restoring OpenAI tool_calls format for
     * the last N assistant messages that carry meta.tool_trace. This makes the
     * LLM aware of prior tool activity as structured facts — not prose —
     * so it never has to infer current state from stale "还没有授权" text.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function buildConversationMessages(array $messages): array
    {
        $result = [];
        $count = count($messages);
        $start = max(0, $count - 30);

        // Pass 1 — identify the last N assistant messages that have a real
        // tool_trace we can replay. Anything outside this window is rendered
        // as plain assistant text only (the original pre-B behavior).
        $toolTraceByIndex = [];
        for ($j = $count - 1; $j >= $start && count($toolTraceByIndex) < self::TOOL_TRACE_REPLAY_WINDOW; $j--) {
            if ((string) ($messages[$j]['role'] ?? '') !== 'assistant') {
                continue;
            }
            $meta = is_array($messages[$j]['meta'] ?? null) ? $messages[$j]['meta'] : [];
            $trace = is_array($meta['tool_trace'] ?? null) ? $meta['tool_trace'] : [];
            if ($trace === []) {
                continue;
            }
            $toolTraceByIndex[$j] = $trace;
        }

        // Pass 2 — emit messages in order, expanding tool_trace entries into
        // one assistant{content,tool_calls} message + N role=tool replies.
        for ($i = $start; $i < $count; $i++) {
            $role = (string) ($messages[$i]['role'] ?? 'user');
            $content = trim((string) ($messages[$i]['content'] ?? ''));

            if ($role === 'system') {
                if ($content === '') {
                    continue;
                }
                $result[] = [
                    'role' => 'user',
                    'content' => "[System context]\n{$content}",
                ];
                continue;
            }

            // Assistant message with replayable tool_trace — rebuild OpenAI format.
            if ($role === 'assistant' && isset($toolTraceByIndex[$i])) {
                $restored = $this->restoreAssistantToolTrace(
                    $messages[$i],
                    $content,
                    $toolTraceByIndex[$i],
                    $i
                );
                if ($restored !== []) {
                    foreach ($restored as $entry) {
                        $result[] = $entry;
                    }
                    continue;
                }
                // Fall through to plain text path if restore produced nothing.
            }

            if ($content === '') {
                continue;
            }

            if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($content, 'UTF-8') > 3000) {
                $content = mb_substr($content, 0, 3000, 'UTF-8').'...';
            }

            $result[] = ['role' => $role, 'content' => $content];
        }

        return $result;
    }

    /**
     * Emit an assistant{content,tool_calls} message plus paired role=tool
     * replies for each trace entry. Returns [] if no valid entries could be
     * built (caller falls back to plain-text assistant message).
     *
     * @param  array<string, mixed>  $message
     * @param  array<int, array<string, mixed>>  $trace
     * @return array<int, array<string, mixed>>
     */
    private function restoreAssistantToolTrace(array $message, string $content, array $trace, int $msgIndex): array
    {
        $meta = is_array($message['meta'] ?? null) ? $message['meta'] : [];
        $runIdRaw = $meta['run_id'] ?? $msgIndex;
        $runIdStr = is_scalar($runIdRaw) ? (string) $runIdRaw : (string) $msgIndex;

        $toolCalls = [];
        $toolReplies = [];
        foreach ($trace as $tIdx => $traceEntry) {
            if (! is_array($traceEntry)) {
                continue;
            }
            $funcName = trim((string) ($traceEntry['function'] ?? ''));
            if ($funcName === '') {
                continue;
            }
            $params = is_array($traceEntry['params'] ?? null) ? $traceEntry['params'] : [];
            $platformResult = is_array($traceEntry['platform_result'] ?? null) ? $traceEntry['platform_result'] : [];

            $callId = 'restored_msg'.$runIdStr.'_'.$msgIndex.'_t'.$tIdx;
            $argumentsJson = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (! is_string($argumentsJson)) {
                $argumentsJson = '{}';
            }

            $toolCalls[] = [
                'id' => $callId,
                'type' => 'function',
                'function' => [
                    'name' => $funcName,
                    'arguments' => $argumentsJson,
                ],
            ];

            $toolContent = json_encode($platformResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (! is_string($toolContent)) {
                $toolContent = '{}';
            }
            if (function_exists('mb_strlen') && function_exists('mb_substr')
                && mb_strlen($toolContent, 'UTF-8') > self::RESTORED_TOOL_CONTENT_MAX) {
                $toolContent = mb_substr($toolContent, 0, self::RESTORED_TOOL_CONTENT_MAX, 'UTF-8').'...[truncated]';
            }

            $toolReplies[] = [
                'role' => 'tool',
                'tool_call_id' => $callId,
                'content' => $toolContent,
            ];
        }

        if ($toolCalls === []) {
            return [];
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')
            && mb_strlen($content, 'UTF-8') > 3000) {
            $content = mb_substr($content, 0, 3000, 'UTF-8').'...';
        }

        $assistantMsg = [
            'role' => 'assistant',
            'content' => $content !== '' ? $content : null,
            'tool_calls' => $toolCalls,
        ];

        return array_merge([$assistantMsg], $toolReplies);
    }

    /**
     * @param  array<string, mixed>  $toolCall
     * @return array<string, mixed>
     */
    private function resolveToolCall(array $toolCall): array
    {
        $functionName = trim((string) ($toolCall['function']['name'] ?? ''));
        if ($functionName === '') {
            return ['error' => 'Tool call function name is missing.'];
        }

        $arguments = $toolCall['function']['arguments'] ?? '{}';
        $params = is_string($arguments) ? json_decode($arguments, true) : (array) $arguments;
        if (! is_array($params)) {
            $params = [];
        }

        $actionKey = $this->mapFunctionNameToActionKey($functionName);
        $workActionMeta = $this->toolRegistryService->getWorkActionMeta($actionKey);
        if ($workActionMeta === []) {
            return ['error' => "Unknown tool: {$functionName} ({$actionKey})"];
        }

        return [
            'function_name' => $functionName,
            'action_key' => $actionKey,
            'params' => $params,
            'work_action' => $workActionMeta,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawMessages
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     */
    private function executeResolvedToolCall(Run $run, array $rawMessages, array $resolved, string $model): array
    {
        $actionKey = (string) ($resolved['action_key'] ?? '');
        $params = (array) ($resolved['params'] ?? []);
        $workActionMeta = (array) ($resolved['work_action'] ?? []);

        $this->logInfo('[ToolCallingAgent] Tool call resolved', [
            'run_id' => $run->id,
            'function' => (string) ($resolved['function_name'] ?? ''),
            'action_key' => $actionKey,
            'params' => $params,
        ]);

        $result = $this->toolCallExecutorService->execute($run, $params, $actionKey, $rawMessages);
        if (! is_array($result)) {
            return [
                'type' => 'error',
                'message' => "Tool execution returned null for {$actionKey}",
            ];
        }

        $normalized = $this->larkResultNormalizerService->normalize($workActionMeta, $result);

        return [
            'type' => 'tool_execution',
            'model' => $model,
            'work_action' => $workActionMeta,
            'platform_result' => $normalized,
            'raw_result' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $workAction
     * @param  array<string, mixed>  $platformResult
     * @param  array<int, array<string, mixed>>  $toolHistory
     * @return array<string, mixed>
     */
    private function buildToolResultResponse(
        string $model,
        int $inputTokens,
        int $outputTokens,
        array $workAction,
        array $platformResult,
        array $toolHistory = []
    ): array {
        $platformResult['input_tokens'] = $inputTokens;
        $platformResult['output_tokens'] = $outputTokens;

        return [
            'type' => 'tool_result',
            'intent_type' => Run::INTENT_TASK,
            'work_action' => $workAction,
            'platform_result' => $platformResult,
            'tool_trace' => $toolHistory,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ];
    }

    /**
     * @param  array<string, mixed>  $workAction
     * @param  array<string, mixed>  $platformResult
     * @param  array<int, array<string, mixed>>  $toolHistory
     * @return array<string, mixed>
     */
    private function buildAgentTaskResponse(
        string $content,
        string $model,
        int $inputTokens,
        int $outputTokens,
        array $workAction,
        array $platformResult,
        array $toolHistory = []
    ): array {
        $content = $this->applyReplyConstraints($content);
        if ($content === '') {
            $content = $this->applyReplyConstraints((string) ($platformResult['answer'] ?? ''));
        }
        if ($content === '') {
            $content = 'Task completed.';
        }

        return [
            'type' => 'agent_task_response',
            'intent_type' => Run::INTENT_TASK,
            'content' => $content,
            'work_action' => $workAction,
            'platform_result' => $platformResult,
            'tool_trace' => $toolHistory,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ];
    }

    /**
     * @param  array<string, mixed>  $platformResult
     */
    private function buildToolFeedbackJson(string $actionKey, array $platformResult): string
    {
        $payload = [
            'status' => (string) ($platformResult['status'] ?? 'failed'),
            'work_action' => $actionKey,
            'task_kind' => (string) ($platformResult['task_kind'] ?? ''),
            'answer' => trim((string) ($platformResult['answer'] ?? '')),
            'guidance' => 'Decide the next best step using this result, or finish the task if the goal is already complete.',
        ];

        $missing = array_values(array_filter(array_map(
            static fn ($item) => trim((string) $item),
            (array) ($platformResult['missing_capabilities'] ?? [])
        )));
        if ($missing !== []) {
            $payload['missing_capabilities'] = $missing;
        }

        $raw = $this->sanitizeToolFeedbackValue($platformResult['raw'] ?? null);
        if ($raw !== null && $raw !== [] && $raw !== '') {
            $payload['data'] = $raw;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function sanitizeToolFeedbackValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 4) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }

            if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($value, 'UTF-8') > 600) {
                return mb_substr($value, 0, 600, 'UTF-8').'...';
            }

            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        if ($value === []) {
            return [];
        }

        if (array_is_list($value)) {
            $items = [];
            foreach (array_slice($value, 0, 12) as $item) {
                $sanitized = $this->sanitizeToolFeedbackValue($item, $depth + 1);
                if ($sanitized !== null && $sanitized !== [] && $sanitized !== '') {
                    $items[] = $sanitized;
                }
            }

            return $items;
        }

        $items = [];
        foreach (array_slice($value, 0, 24, true) as $key => $item) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }

            $sanitized = $this->sanitizeToolFeedbackValue($item, $depth + 1);
            if ($sanitized === null || $sanitized === [] || $sanitized === '') {
                continue;
            }

            $items[$key] = $sanitized;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function toolCallFingerprint(string $actionKey, array $params): string
    {
        $serialized = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return sha1($actionKey.'|'.($serialized === false ? '{}' : $serialized));
    }

    private function mapFunctionNameToActionKey(string $functionName): string
    {
        return match ($functionName) {
            'calendar_create' => 'calendar.create',
            'calendar_attendees_add' => 'calendar.attendees.add',
            'calendar_agenda' => 'calendar.agenda',
            'tasks_create' => 'tasks.create',
            'docs_create' => 'docs.create',
            'docs_read' => 'docs.read',
            'sheets_create' => 'sheets.create',
            'sheets_read' => 'sheets.read',
            'sheets_write' => 'sheets.write',
            'sheets_append' => 'sheets.append',
            'contact_lookup' => 'contact.lookup',
            'approval_manage' => 'approval.manage',
            'base_manage' => 'base.manage',
            'meeting_manage' => 'meeting.manage',
            'minutes_manage' => 'minutes.manage',
            'mail_manage' => 'mail.manage',
            'wiki_manage' => 'wiki.manage',
            'drive_manage' => 'drive.manage',
            'chat_history_read' => 'chat.history_read',
            'load_skill' => 'skill.load',
            'execute_sandbox_skill' => 'skill.execute_sandbox',
            'execute_api_skill' => 'skill.execute_api',
            'request_authorization' => 'request_authorization',
            default => str_replace('_', '.', $functionName),
        };
    }

    private function applyReplyConstraints(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        $content = preg_replace('/\*\*(.*?)\*\*/u', '$1', $content) ?? $content;
        $content = preg_replace('/__(.*?)__/u', '$1', $content) ?? $content;
        $content = preg_replace('/`([^`]+)`/u', '$1', $content) ?? $content;
        $content = preg_replace('/^\s{0,3}#{1,6}\s*/mu', '', $content) ?? $content;
        $content = preg_replace('/^\s*[-*]\s+/mu', '', $content) ?? $content;

        $content = str_replace(
            ['IM一对一/群聊历史', 'chat_history_read', 'calendar_agenda', 'drive_manage', 'ToolCallingAgent', 'function calling'],
            ['聊天记录', '聊天记录', '日程查询', '云盘操作', 'MiFrog', 'tool calling'],
            $content
        );

        $leadingNoisePatterns = [
            '/^\s*(?:抱歉(?:呀|哈|呢)?|不好意思|对不起)[，,。\s]*/u',
            '/^\s*(?:你好|您好|嗨|哈喽)[，,。\s]*/u',
        ];
        foreach ($leadingNoisePatterns as $pattern) {
            while (preg_match($pattern, $content) === 1) {
                $content = preg_replace($pattern, '', $content, 1) ?? $content;
            }
        }

        $content = preg_replace('/\s+\n/u', "\n", $content) ?? $content;
        $content = preg_replace("/\n{3,}/u", "\n\n", $content) ?? $content;

        return trim($content);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawMessages
     * @return array<string, string>
     */
    private function extractRecentReferences(array $rawMessages): array
    {
        $refs = [];

        for ($i = count($rawMessages) - 1; $i >= 0; $i--) {
            $content = trim((string) ($rawMessages[$i]['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            if (! isset($refs['latest_doc_url']) && preg_match('#https?://\S*/docx/[A-Za-z0-9_-]+#i', $content, $matches) === 1) {
                $refs['latest_doc_url'] = $matches[0];
            }

            if (! isset($refs['latest_sheet_url']) && preg_match('#https?://\S*/sheets/[A-Za-z0-9_-]+#i', $content, $matches) === 1) {
                $refs['latest_sheet_url'] = $matches[0];
            }

            if (! isset($refs['latest_base_url']) && preg_match('#https?://\S*/base/[A-Za-z0-9_-]+#i', $content, $matches) === 1) {
                $refs['latest_base_url'] = $matches[0];
            }

            if (! isset($refs['latest_base_token']) && preg_match('#/base/([A-Za-z0-9_-]+)#i', $content, $matches) === 1) {
                $refs['latest_base_token'] = $matches[1];
            }

            if (! isset($refs['latest_wiki_space_id']) && preg_match('/space_id(?:=|:)\\s*([A-Za-z0-9_-]+)/iu', $content, $matches) === 1) {
                $refs['latest_wiki_space_id'] = $matches[1];
            }

            if (! isset($refs['latest_calendar_event_url']) && preg_match('#https?://\S*/calendar/event/detail\?[^\s<>"\']+#i', $content, $matches) === 1) {
                $refs['latest_calendar_event_url'] = rtrim($matches[0], '.,;:!?)]}>');
                $calendarParts = $this->parseCalendarReference($refs['latest_calendar_event_url']);
                if (($calendarParts['calendar_id'] ?? '') !== '' && ! isset($refs['latest_calendar_id'])) {
                    $refs['latest_calendar_id'] = (string) $calendarParts['calendar_id'];
                }
                if (($calendarParts['event_id'] ?? '') !== '' && ! isset($refs['latest_event_id'])) {
                    $refs['latest_event_id'] = (string) $calendarParts['event_id'];
                }
            }

            if (count($refs) >= 8) {
                break;
            }
        }

        return $refs;
    }

    /**
     * @return array<string, string>
     */
    private function parseCalendarReference(string $url): array
    {
        $parts = parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
        if (! is_array($parts)) {
            return [];
        }

        parse_str((string) ($parts['query'] ?? ''), $query);

        return [
            'calendar_id' => trim((string) ($query['calendarId'] ?? '')),
            'event_id' => trim((string) ($query['key'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logInfo(string $message, array $context = []): void
    {
        if (Log::getFacadeRoot() !== null) {
            Log::info($message, $context);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logError(string $message, array $context = []): void
    {
        if (Log::getFacadeRoot() !== null) {
            Log::error($message, $context);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logWarning(string $message, array $context = []): void
    {
        if (Log::getFacadeRoot() !== null) {
            Log::warning($message, $context);
        }
    }
}
