<?php

namespace App\Services\RunExecution;

use App\Models\Run;
use App\Services\LlmGatewayService;
use App\Services\Prompt\Sections\IdentitySection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reporter extracted from RunExecutionService.
 *
 * Takes a platform-skill execution result and generates a natural-language
 * reply via LLM, using the recent conversation tail + structured result hints.
 * Includes a post-hoc URL safety net to guarantee action links (document
 * URLs, task guids, etc.) survive the LLM rewrite.
 *
 * Behavior contract (preserved from the original inline implementation):
 * - If LLM call fails or returns empty, fall back to the template answer.
 * - If LLM reply drops a known URL from structured data or template answer,
 *   append the missing URL(s) on a new line.
 * - Never throws — non-fatal LLM failures are logged at warn level.
 */
class PlatformResultReporter
{
    public function __construct(
        private readonly LlmGatewayService $llmGatewayService,
        private readonly ?IdentitySection $identitySection = null,
    ) {
    }

    /**
     * @param  list<array{role:string,content:string}>  $rawMessages
     * @param  array<string,mixed>  $platformResult
     * @param  array<string,mixed>  $integrationSkill
     * @return array{message:string,input_tokens:int,output_tokens:int,session_key:?string}
     */
    public function report(Run $run, array $rawMessages, array $platformResult, array $integrationSkill): array
    {
        $templateAnswer = trim((string) ($platformResult['answer'] ?? ''));
        $inputTokens = 0;
        $outputTokens = 0;
        $sessionKey = null;

        try {
            $working = $this->buildReplyConversation($rawMessages);

            $status = strtolower(trim((string) ($platformResult['status'] ?? '')));
            $rawData = (array) ($platformResult['raw'] ?? []);
            $taskKind = (string) ($integrationSkill['task_kind'] ?? 'general_task');

            $resultLines = [];
            $resultLines[] = '任务类型: ' . $taskKind;
            $resultLines[] = '执行状态: ' . $status;
            if ($templateAnswer !== '') {
                $resultLines[] = '结果详情: ' . $this->textTruncate($templateAnswer, 600);
            }

            // Extract structured data for richer context — search both top-level and 'raw'
            $searchPools = [$rawData, $platformResult];
            foreach (['document_url', 'document_id', 'event', 'task_guid', 'url', 'link', 'title', 'sheet_url', 'sheet_token'] as $key) {
                foreach ($searchPools as $pool) {
                    $raw = $pool[$key] ?? null;
                    if ($raw === null || is_array($raw) || is_object($raw)) {
                        continue;
                    }
                    $val = trim((string) $raw);
                    if ($val !== '' && $val !== '[]' && $val !== '{}') {
                        $resultLines[] = $key . ': ' . $this->textTruncate($val, 300);
                        break;
                    }
                }
            }

            $tabularValues = $rawData['values'] ?? ($platformResult['values'] ?? null);
            if (is_array($tabularValues) && $tabularValues !== []) {
                $previewRows = [];
                foreach ($tabularValues as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $normalizedRow = array_map(
                        static fn ($cell): string => is_scalar($cell) || $cell === null ? trim((string) $cell) : '',
                        $row
                    );
                    if (array_filter($normalizedRow, static fn (string $cell): bool => $cell !== '') === []) {
                        continue;
                    }

                    $previewRows[] = $normalizedRow;
                    if (count($previewRows) >= 6) {
                        break;
                    }
                }

                if ($previewRows !== []) {
                    $previewJson = json_encode($previewRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if (is_string($previewJson) && $previewJson !== '') {
                        $resultLines[] = '表格预览: ' . $previewJson;
                    }
                }
            }

            $systemPrompt = $this->identitySection !== null
                ? $this->identitySection->render(['mode' => 'reporter']) . "\n\n" .
                    '请根据任务执行结果，用自然语言向用户汇报。只依据当前会话与给定结果回答，不要引用无关记忆，不要补写未执行的信息。'
                : implode("\n", [
                    '你是米蛙（MiFrog），用户的飞书智能助手。',
                    '请根据任务执行结果，用自然语言向用户汇报。',
                    '只依据当前会话与给定结果回答，不要引用无关记忆，不要补写未执行的信息。',
                ]);

            $working[] = ['role' => 'system', 'content' => $systemPrompt];
            $working[] = ['role' => 'user', 'content' => "以下是刚执行完的任务结果，请向用户汇报：\n" . implode("\n", $resultLines)];

            $resp = $this->llmGatewayService->chatWithCapability($working, 'text');
            $inputTokens = (int) ($resp['input_tokens'] ?? 0);
            $outputTokens = (int) ($resp['output_tokens'] ?? 0);
            $reply = trim((string) ($resp['content'] ?? ''));
            if ($reply !== '') {
                // Safety net: if result contains links but LLM dropped them, append.
                // Strategy: collect all http(s) URLs from structured data AND template answer,
                // then append any that the LLM reply is missing.
                $foundLinks = [];

                // 1) From structured data pools
                $linkKeys = ['document_url', 'url', 'link', 'sheet_url'];
                foreach ($linkKeys as $lk) {
                    foreach ($searchPools as $pool) {
                        $lv = $pool[$lk] ?? null;
                        if (is_string($lv) && preg_match('#^https?://#i', trim($lv))) {
                            $foundLinks[] = trim($lv);
                            break;
                        }
                    }
                }

                // 2) From template answer (most reliable — the task service already built it)
                if (preg_match_all('#https?://[^\s\x{0000}-\x{001F}]+#u', $templateAnswer, $m)) {
                    foreach ($m[0] as $u) {
                        $foundLinks[] = rtrim($u, ".,;:!?\u{FF0C}\u{3002}\u{300B}");
                    }
                }

                $foundLinks = array_unique($foundLinks);

                Log::debug('[RunExecution.Reporter] link_check', [
                    'found_links' => $foundLinks,
                    'reply_len' => mb_strlen($reply),
                ]);

                foreach ($foundLinks as $link) {
                    if (! str_contains($reply, $link)) {
                        $reply .= "\n" . $link;
                    }
                }

                return [
                    'message' => $reply,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'session_key' => $sessionKey,
                ];
            }
        } catch (Throwable $e) {
            Log::warning('[RunExecution.Reporter] generate_failed_using_template', [
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'message' => $templateAnswer !== '' ? $templateAnswer : "\u{4EFB}\u{52A1}\u{5DF2}\u{5B8C}\u{6210}\u{3002}",
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'session_key' => $sessionKey,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawMessages
     * @return array<int, array<string, string>>
     */
    private function buildReplyConversation(array $rawMessages): array
    {
        $result = [];
        $start = max(0, count($rawMessages) - 12);

        for ($i = $start; $i < count($rawMessages); $i++) {
            $role = (string) ($rawMessages[$i]['role'] ?? 'user');
            $content = trim((string) ($rawMessages[$i]['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            if ($role === 'system') {
                continue;
            }

            if ($this->textLength($content) > 1600) {
                $content = $this->textTruncate($content, 1600);
            }

            $result[] = [
                'role' => in_array($role, ['user', 'assistant'], true) ? $role : 'user',
                'content' => $content,
            ];
        }

        return $result;
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
