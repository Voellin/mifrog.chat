<?php

namespace App\Services;

use App\Models\Run;
use App\Support\MessageTextExtractor;
use Illuminate\Support\Facades\Log;
use Throwable;

class FeishuDocsTaskService extends AbstractFeishuTaskService
{
    private const REQUIRED_SCOPE_DOCX = 'docx:document';

    public function __construct(
        FeishuService $feishuService,
        private readonly LlmGatewayService $llmGatewayService,
        private readonly FeishuTokenService $feishuTokenService,
        private readonly MemoryService $memoryService,
        FeishuCliClient $feishuCliClient,
    ) {
        parent::__construct($feishuService, $feishuCliClient);
    }

    /**
     * OpenClaw executor: receives structured params from LLM + full conversation.
     *
     * Expected $params keys:
     *   - action: "create" or "read"
     *   - title: string (for create)
     *   - content_prompt: string (user's content requirement)
     *   - doc_token: string (for read)
     *   - doc_url: string
     *   - needs_clarification: bool
     *   - clarification_message: string
     *
     * @param  array<string,mixed>  $params
     * @param  array<int,array<string,mixed>>  $rawMessages  Full conversation for LLM content generation
     */
    public function execute(Run $run, array $params, array $rawMessages, ?callable $progressCallback = null): array
    {
        // ── Check if LLM extraction failed ──
        if (($params['_extraction_failed'] ?? false) === true) {
            return [
                'status' => 'clarify',
                'message' => '请告诉我需要创建或处理的飞书文档内容。',
            ];
        }

        // ── Check if LLM says needs clarification ──
        if (($params['needs_clarification'] ?? false) === true) {
            $msg = trim((string) ($params['clarification_message'] ?? ''));
            return [
                'status' => 'clarify',
                'message' => $msg !== '' ? $msg : '请说明你想创建还是阅读飞书文档，以及具体需求。',
            ];
        }

        // ── Token & scope check ──
        [$accessToken, $identity, $error] = $this->feishuTokenService->resolveUserToken($run, self::REQUIRED_SCOPE_DOCX, '飞书文档读写');
        if ($error !== null) {
            return $error;
        }
        $userKey = trim((string) ($identity?->provider_user_id ?: $this->resolveRunOpenId($run)));

        // ── CLI availability check ──
        if (! $this->feishuCliClient->isEnabled() || ! $this->feishuCliClient->isAvailable()) {
            return [
                'status' => 'failed',
                'message' => '飞书 CLI 工具不可用，无法操作文档。',
            ];
        }

        $action = strtolower(trim((string) ($params['action'] ?? 'create')));
        $docToken = trim((string) ($params['doc_token'] ?? ''));

        // Also try extracting from doc_url if doc_token is empty
        if ($docToken === '') {
            $docUrl = trim((string) ($params['doc_url'] ?? ''));
            if ($docUrl !== '' && preg_match('/\/(?:docx|docs)\/([a-zA-Z0-9_-]+)/i', $docUrl, $m) === 1) {
                $docToken = trim((string) ($m[1] ?? ''));
            }
        }

        if ($docToken === '') {
            $docToken = $this->extractLatestDocToken($rawMessages);
        }

        if ($action === 'read') {
            return $this->executeRead($run, $accessToken, $userKey, $docToken, $params, $rawMessages, $progressCallback);
        }

        return $this->executeCreate($run, $accessToken, $userKey, $params, $rawMessages, $progressCallback);
    }

    private function executeRead(Run $run, string $accessToken, string $userKey, string $docToken, array $params, array $rawMessages, ?callable $progressCallback = null): array
    {
        if ($docToken === '') {
            return [
                'status' => 'clarify',
                'message' => '请先提供飞书文档链接或文档 token，我再为你阅读和处理。',
            ];
        }

        $feishuConfig = $this->feishuService->readConfig();

        // Use lark-cli docs +fetch to read document
        try {
            $fetchResult = $this->feishuCliClient->runSkillCommand($feishuConfig, $accessToken, [
                'docs', '+fetch',
                '--doc', $docToken,
                '--format', 'json',
            ], 'user', $userKey);
        } catch (Throwable $e) {
            Log::warning('[FeishuDocs] CLI fetch failed', ['error' => $e->getMessage()]);
            $errMsg = $e->getMessage();
            $isAuth = str_contains($errMsg, '"type":"auth"') || str_contains($errMsg, 'auth') || str_contains($errMsg, 'token');
            return [
                'status' => $isAuth ? 'blocked' : 'failed',
                'message' => '读取飞书文档失败：' . $errMsg,
                'missing' => $isAuth ? ['feishu.oauth.user_token'] : [],
            ];
        }

        $cliCode = (int) ($fetchResult['code'] ?? 0);
        if ($cliCode !== 0) {
            $errorMsg = trim((string) ($fetchResult['msg'] ?? 'doc_read_failed'));
            if ($this->looksLikePermissionError($cliCode)) {
                return [
                    'status' => 'blocked',
                    'message' => '读取飞书文档失败（权限不足）：' . $errorMsg,
                    'missing' => ['feishu.scope.' . self::REQUIRED_SCOPE_DOCX],
                ];
            }
            return [
                'status' => 'failed',
                'message' => '读取飞书文档失败：' . $errorMsg,
                'error' => $fetchResult,
            ];
        }

        // Extract content from CLI output
        $data = (array) ($fetchResult['data'] ?? $fetchResult);
        $rawContent = '';
        $docTitle = trim((string) ($data['title'] ?? ''));

        // CLI docs +fetch returns content in various formats
        $rawOutput = trim((string) ($fetchResult['_raw_output'] ?? ''));
        if ($rawOutput !== '' && ! json_decode($rawOutput)) {
            // Non-JSON output = likely markdown content
            $rawContent = $rawOutput;
        } else {
            // JSON output - extract content field
            $rawContent = trim((string) ($data['markdown'] ?? ($data['content'] ?? ($data['document']['content'] ?? ''))));
            if ($rawContent === '') {
                // Try blocks
                $blocks = $data['document']['body']['blocks'] ?? ($data['blocks'] ?? []);
                if (is_array($blocks) && $blocks !== []) {
                    $rawContent = $this->blocksToText($blocks);
                }
            }
        }

        if ($rawContent === '') {
            return [
                'status' => 'clarify',
                'message' => '文档内容目前为空，或你暂无可读内容权限。你可以先补充文档内容后再让我继续。',
            ];
        }

        $readAnswer = $this->buildReadOnlyAnswer($rawMessages, $rawContent, $docTitle);
        $documentUrl = trim((string) ($params['doc_url'] ?? ''));
        if ($documentUrl === '' && $docToken !== '') {
            $documentUrl = 'https://www.feishu.cn/docx/' . $docToken;
        }

        $responseTitle = trim((string) ($params['title'] ?? ''));
        if ($responseTitle === '') {
            $responseTitle = $docTitle;
        }

        return [
            'status' => 'read',
            'message' => (string) ($readAnswer['answer'] ?? ''),
            'model' => 'feishu-doc-read',
            'document_id' => $docToken,
            'document_url' => $documentUrl,
            'title' => $responseTitle,
            'input_tokens' => (int) ($readAnswer['input_tokens'] ?? 0),
            'output_tokens' => (int) ($readAnswer['output_tokens'] ?? 0),
        ];
    }

    private function executeCreate(Run $run, string $accessToken, string $userKey, array $params, array $rawMessages, ?callable $progressCallback = null): array
    {
        $title = trim((string) ($params['title'] ?? ''));
        $contentPrompt = trim((string) ($params['content_prompt'] ?? ''));

        // Use LLM to generate document content from full conversation context
        if ($progressCallback) { ($progressCallback)('正在用 AI 生成文档内容...'); }

        $draft = $this->buildDraft($run, $rawMessages, $title, $contentPrompt);
        if ($progressCallback) { ($progressCallback)('文档草稿已生成，正在创建飞书文档...'); }
        $title = trim((string) ($draft['title'] ?? $title));
        if ($title === '') {
            $title = '米蛙自动生成文档';
        }

        $markdownContent = $this->resolveCreateMarkdown($draft, $contentPrompt, $rawMessages, $title);
        if ($markdownContent === '') {
            return [
                'status' => 'clarify',
                'message' => "\u{6211}\u{8FD9}\u{8FB9}\u{8FD8}\u{6CA1}\u{62FF}\u{5230}\u{53EF}\u{4EE5}\u{5199}\u{5165}\u{98DE}\u{4E66}\u{6587}\u{6863}\u{7684}\u{6B63}\u{6587}\u{5185}\u{5BB9}\u{3002}\u{8BF7}\u{76F4}\u{63A5}\u{544A}\u{8BC9}\u{6211}\u{8981}\u{5199}\u{5165}\u{6587}\u{6863}\u{7684}\u{8981}\u{70B9}\u{6216}\u{7ED3}\u{6784}\u{FF0C}\u{6211}\u{518D}\u{7EE7}\u{7EED}\u{521B}\u{5EFA}\u{3002}",
            ];
        }
        $feishuConfig = $this->feishuService->readConfig();

        // Use lark-cli docs +create with --markdown to create document with content in one call
        $cliCmd = [
            'docs', '+create',
            '--title', $title,
        ];

        if ($markdownContent !== '') {
            $cliCmd[] = '--markdown';
            $cliCmd[] = $markdownContent;
        }

        try {
            $create = $this->feishuCliClient->runSkillCommand($feishuConfig, $accessToken, $cliCmd, 'user', $userKey);
        } catch (Throwable $e) {
            Log::warning('[FeishuDocs] CLI create failed', ['error' => $e->getMessage()]);
            $errMsg = $e->getMessage();
            $isAuth = str_contains($errMsg, '"type":"auth"') || str_contains($errMsg, 'auth') || str_contains($errMsg, 'token');
            return [
                'status' => $isAuth ? 'blocked' : 'failed',
                'message' => '创建飞书文档失败：' . $errMsg,
                'missing' => $isAuth ? ['feishu.oauth.user_token'] : [],
            ];
        }

        $cliCode = (int) ($create['code'] ?? 0);
        if ($cliCode !== 0) {
            $errorMsg = trim((string) ($create['msg'] ?? 'doc_create_failed'));
            if ($this->looksLikeMissingMarkdownError($errorMsg)) {
                return [
                    'status' => 'clarify',
                    'message' => "\u{6211}\u{8FD9}\u{6B21}\u{521B}\u{5EFA}\u{6587}\u{6863}\u{65F6}\u{6CA1}\u{62FF}\u{5230}\u{53EF}\u{5199}\u{5165}\u{7684}\u{6B63}\u{6587}\u{5185}\u{5BB9}\u{3002}\u{8BF7}\u{76F4}\u{63A5}\u{8865}\u{5145}\u{8981}\u{5199}\u{5165}\u{6587}\u{6863}\u{7684}\u{8981}\u{70B9}\u{6216}\u{7ED3}\u{6784}\u{3002}",
                    'error' => $create,
                ];
            }
            if ($this->looksLikePermissionError($cliCode)) {
                return [
                    'status' => 'blocked',
                    'message' => '创建飞书文档失败（权限不足）：' . $errorMsg,
                    'missing' => ['feishu.scope.' . self::REQUIRED_SCOPE_DOCX],
                ];
            }
            return [
                'status' => 'failed',
                'message' => '创建飞书文档失败：' . $errorMsg,
                'error' => $create,
            ];
        }

        $data = (array) ($create['data'] ?? $create);
        $document = (array) ($data['document'] ?? $data);
        $documentId = trim((string) ($document['document_id'] ?? ($document['doc_id'] ?? ($data['document_id'] ?? ($data['doc_id'] ?? '')))));
        $documentUrl = trim((string) ($document['url'] ?? ($document['doc_url'] ?? ($data['url'] ?? ($data['doc_url'] ?? '')))));
        if ($documentUrl === '' && $documentId !== '') {
            $documentUrl = 'https://applink.feishu.cn/client/docx/open?docToken=' . $documentId;
        }

        if ($progressCallback) { ($progressCallback)('文档创建完成。'); }

        $lines = [];
        if ($markdownContent !== '') {
            $lines[] = '文档《' . $title . '》已创建完成，正文已写入。';
        } else {
            $lines[] = '文档《' . $title . '》已创建完成。';
        }
        if ($documentUrl !== '') {
            $lines[] = '你可以点击链接查看：' . $documentUrl;
        }

        return [
            'status' => 'created',
            'message' => implode("\n", $lines),
            'model' => 'feishu-doc-create',
            'document_id' => $documentId,
            'document_url' => $documentUrl,
            'title' => $title,
            'input_tokens' => (int) ($draft['input_tokens'] ?? 0),
            'output_tokens' => (int) ($draft['output_tokens'] ?? 0),
        ];
    }

    /**
     * Build document draft using LLM with FULL conversation context.
     */
    private function buildDraft(Run $run, array $rawMessages, string $suggestedTitle, string $contentPrompt): array
    {
        $conversationContext = $this->buildConversationContext($rawMessages);
        $inputTokens = 0;
        $outputTokens = 0;

        // Inject memory context for personalized document content
        $memoryText = '';
        try {
            $memoryText = $this->memoryService->getMemoryContext($run, $contentPrompt !== '' ? $contentPrompt : $suggestedTitle);
        } catch (\Throwable $e) {
            // non-critical
        }
        if ($memoryText !== '') {
            $conversationContext = $memoryText . "\n\n" . $conversationContext;
        }

        $prompt = implode("\n", [
            '你是飞书文档助手。请根据用户的完整对话上下文生成可直接写入文档的内容。',
            '请使用正确的Markdown格式：',
            '  - 使用 #、##、### 表示标题',
            '  - 使用 - 表示无序列表',
            '  - 使用 1. 表示有序列表',
            '  - 使用 **bold** 表示粗体，使用 *italic* 表示斜体',
            '  - 使用 > 表示引用',
            '仅输出一行 JSON，不要输出解释。',
            'JSON schema: {"title":"<=60字","content":"<=3000字"}',
            '',
            $suggestedTitle !== '' ? '建议标题：' . $suggestedTitle : '',
            $contentPrompt !== '' ? '内容要求：' . $this->truncate($contentPrompt, 1200) : '',
            '',
            '完整对话上下文：',
            $this->truncate($conversationContext, 6000),
        ]);

        try {
            $resp = $this->llmGatewayService->chatWithCapability([
                ['role' => 'system', 'content' => '你是结构化生成器，只输出 JSON。'],
                ['role' => 'user', 'content' => $prompt],
            ], 'text');
            $inputTokens = (int) ($resp['input_tokens'] ?? 0);
            $outputTokens = (int) ($resp['output_tokens'] ?? 0);
        } catch (Throwable) {
            return [
                'title' => $suggestedTitle !== '' ? $suggestedTitle : '米蛙自动生成文档',
                'content' => $contentPrompt !== '' ? $contentPrompt : '',
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
            ];
        }

        $json = $this->extractJsonObject((string) ($resp['content'] ?? ''));
        if ($json === null) {
            return [
                'title' => $suggestedTitle !== '' ? $suggestedTitle : '米蛙自动生成文档',
                'content' => $contentPrompt !== '' ? $contentPrompt : '',
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
            ];
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [
                'title' => $suggestedTitle !== '' ? $suggestedTitle : '米蛙自动生成文档',
                'content' => $contentPrompt !== '' ? $contentPrompt : '',
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
            ];
        }

        return [
            'title' => $this->truncate(trim((string) ($decoded['title'] ?? $suggestedTitle)), 60),
            'content' => $this->truncate(trim((string) ($decoded['content'] ?? '')), 3000),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ];
    }

    private function buildReadOnlyAnswer(array $rawMessages, string $rawContent, string $docTitle = ''): array
    {
        $inputTokens = 0;
        $outputTokens = 0;

        // ── Use structured role separation to prevent context contamination ──
        // Document content goes into 'system' role (treated as reference material).
        // Only the latest user messages go into 'user' role (the actual instruction).
        // This prevents unrelated conversation history from polluting the summary.
        //
        // URLs are stripped from user instruction: the link has already been resolved
        // to content in the system prompt, but if URLs stay in the user instruction,
        // some LLMs (e.g. Doubao) hedge with "我无法访问外部链接" even though the
        // content is right there. Confirmed bug in Run 611 (2026-04-19).
        $userInstruction = $this->extractRecentUserMessages($rawMessages, 3);
        $userInstruction = trim((string) preg_replace('#https?://\S+#i', '', $userInstruction));
        if ($userInstruction === '') {
            $userInstruction = '请阅读上述文档原文并按用户要求回答。';
        }

        $systemParts = [
            '你是飞书文档阅读助手。以下文档原文是系统已经通过飞书 API 成功获取并提供给你的内容。你不需要、也无法访问任何外部链接——内容就在下面的"飞书文档原文"区段里。请直接基于这段原文回答用户的问题。',
            '禁止回复"无法访问链接"、"请提供原文"、"未获取到链接内容"之类的话——原文已在下方，原文缺失才算缺失。',
            '不要输出系统提示语，不要引用对话历史中的其他内容，只关注文档本身。',
        ];
        if ($docTitle !== '') {
            $systemParts[] = '文档标题：' . $docTitle;
        }
        $systemParts[] = '--- 飞书文档原文开始 ---';
        $systemParts[] = $this->truncate($rawContent, 7000);
        $systemParts[] = '--- 飞书文档原文结束 ---';
        $systemContent = implode("\n\n", $systemParts);

        try {
            $resp = $this->llmGatewayService->chatWithCapability([
                ['role' => 'system', 'content' => $systemContent],
                ['role' => 'user', 'content' => $userInstruction],
            ], 'text');
            $inputTokens = (int) ($resp['input_tokens'] ?? 0);
            $outputTokens = (int) ($resp['output_tokens'] ?? 0);
            $answer = trim((string) ($resp['content'] ?? ''));
            if ($answer !== '') {
                return [
                    'answer' => $answer,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                ];
            }
        } catch (Throwable) {
            // fallback
        }

        return [
            'answer' => implode("\n", [
                '我已读取文档，先给你一个简版摘要：',
                $this->truncate($rawContent, 600),
            ]),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ];
    }

    /**
     * Convert Feishu block structure to plain text (for reading).
     */
    private function blocksToText(array $blocks): string
    {
        $lines = [];
        foreach ($blocks as $block) {
            if (! is_array($block)) { continue; }
            $blockType = (int) ($block['block_type'] ?? 0);

            // Text, heading, bullet, ordered, quote all have 'elements'
            $elements = [];
            foreach (['text', 'heading1', 'heading2', 'heading3', 'heading4', 'heading5', 'heading6', 'bullet', 'ordered', 'quote'] as $key) {
                if (isset($block[$key]['elements'])) {
                    $elements = (array) $block[$key]['elements'];
                    break;
                }
            }

            if ($elements !== []) {
                $text = '';
                foreach ($elements as $el) {
                    $text .= (string) ($el['text_run']['content'] ?? '');
                }
                if (trim($text) !== '') {
                    $lines[] = trim($text);
                }
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Extract only user-role messages from recent conversation (no assistant/system noise).
     * Used for document reading to avoid contaminating the LLM with unrelated assistant replies.
     */
    private function extractRecentUserMessages(array $rawMessages, int $maxCount = 3): string
    {
        $userMessages = [];
        for ($i = count($rawMessages) - 1; $i >= 0 && count($userMessages) < $maxCount; $i--) {
            $role = (string) ($rawMessages[$i]['role'] ?? '');
            if ($role !== 'user') {
                continue;
            }
            $content = trim((string) ($rawMessages[$i]['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            if (mb_strlen($content, 'UTF-8') > 500) {
                $content = mb_substr($content, 0, 500, 'UTF-8') . '...';
            }
            $userMessages[] = $content;
        }

        // Reverse to chronological order
        return implode("\n", array_reverse($userMessages));
    }

    private function buildConversationContext(array $rawMessages): string
    {
        $lines = [];
        $count = count($rawMessages);
        $start = max(0, $count - 20);

        for ($i = $start; $i < $count; $i++) {
            $role = (string) ($rawMessages[$i]['role'] ?? 'unknown');
            $content = trim((string) ($rawMessages[$i]['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $label = match ($role) {
                'user' => '用户',
                'assistant' => '助手',
                default => $role,
            };
            if (mb_strlen($content, 'UTF-8') > 1500) {
                $content = mb_substr($content, 0, 1500, 'UTF-8') . '...';
            }
            $lines[] = "[{$label}]: {$content}";
        }

        return implode("\n\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawMessages
     */
    private function extractLatestDocToken(array $rawMessages): string
    {
        for ($i = count($rawMessages) - 1; $i >= 0; $i--) {
            $content = trim((string) ($rawMessages[$i]['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            if (preg_match('/\/(?:docx|docs)\/([A-Za-z0-9_-]+)/iu', $content, $matches) === 1) {
                return trim((string) ($matches[1] ?? ''));
            }

            if (preg_match('/docToken=([A-Za-z0-9_-]+)/iu', $content, $matches) === 1) {
                return trim((string) ($matches[1] ?? ''));
            }
        }

        return '';
    }

    private function looksLikePermissionError(int $code): bool
    {
        return in_array($code, [99991672, 99991663, 99991695, 40003, 20027, 230001, 230006], true);
    }

    /**
     * @param  array<string,mixed>  $draft
     * @param  array<int,array<string,mixed>>  $rawMessages
     */
    private function resolveCreateMarkdown(array $draft, string $contentPrompt, array $rawMessages, string $title): string
    {
        $draftContent = trim((string) ($draft['content'] ?? ''));
        if ($draftContent !== '') {
            return $draftContent;
        }

        $fallbackSource = $contentPrompt !== ''
            ? $contentPrompt
            : MessageTextExtractor::latestUserText($rawMessages);

        return $this->formatFallbackMarkdown($title, $fallbackSource);
    }

    private function formatFallbackMarkdown(string $title, string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        $heading = trim($title);
        if ($heading === '') {
            return $content;
        }

        return '# ' . $heading . "\n\n" . $content;
    }

    private function looksLikeMissingMarkdownError(string $message): bool
    {
        return str_contains(strtolower($message), 'required flag(s) "markdown" not set');
    }

    private function extractJsonObject(string $text): ?string
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        return substr($text, $start, $end - $start + 1);
    }

    protected function truncate(string $text, int $maxChars): string
    {
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') <= $maxChars) {
                return $text;
            }
            return mb_substr($text, 0, $maxChars - 1, 'UTF-8') . '...';
        }
        if (strlen($text) <= $maxChars) {
            return $text;
        }
        return substr($text, 0, $maxChars - 3) . '...';
    }

}
