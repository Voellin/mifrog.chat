<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\AttachmentChunk;
use App\Models\Run;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ChatTaskService extends AbstractFeishuTaskService
{
    private const DEFAULT_TIMEZONE = 'Asia/Shanghai';

    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 50;

    private const MAX_CHAT_CANDIDATES = 8;

    private const MAX_MESSAGES_PER_CHAT = 50;

    public function __construct(
        FeishuService $feishuService,
        private readonly FeishuTokenService $feishuTokenService,
        FeishuCliClient $feishuCliClient,
    ) {
        parent::__construct($feishuService, $feishuCliClient);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function readHistory(Run $run, array $params): array
    {
        if (($params['_extraction_failed'] ?? false) === true) {
            return [
                'status' => 'clarify',
                'message' => 'Please tell me which chat history you want, for example a keyword, a time window, or the person you want me to focus on.',
            ];
        }

        if (($params['needs_clarification'] ?? false) === true) {
            $message = trim((string) ($params['clarification_message'] ?? ''));

            return [
                'status' => 'clarify',
                'message' => $message !== '' ? $message : 'Please tell me which chat history you want, for example a keyword, a time window, or the person you want me to focus on.',
            ];
        }

        // 先查本地 attachment_chunks（auto_archive + source_kind=chat）。
        // 命中就直接返回，不打飞书 API——速度快、不耗配额、token 失效/网抖也不挂。
        // 必须放在 token resolution 之前：本地查询不需要 user token。
        [$_startTime, $_endTime] = $this->resolveTimeWindow($params);
        $_limit = $this->normalizeLimit($params['limit'] ?? null);
        $_keyword = trim((string) ($params['keyword'] ?? ''));
        $_groupName = trim((string) ($params['group_name'] ?? ''));
        $_participantNames = $this->normalizeStringList($params['participant_names'] ?? []);
        $_participantOpenIds = $this->normalizeStringList($params['participant_open_ids'] ?? []);
        $localRecords = $this->readChatHistoryFromLocalChunks(
            $run,
            $_startTime,
            $_endTime,
            $_limit,
            $_participantNames,
            $_groupName,
            $_keyword
        );
        if ($localRecords !== null && $localRecords !== []) {
            return $this->buildCommunicationResult(
                $localRecords,
                $_participantOpenIds,
                $_participantNames,
                $_groupName,
                $_startTime,
                $_endTime
            );
        }

        [$accessToken, $identity, $error] = $this->feishuTokenService->resolveUserToken($run, '', 'chat history');
        if ($error !== null) {
            return $error;
        }

        if (! $this->feishuCliClient->isEnabled() || ! $this->feishuCliClient->isAvailable()) {
            return [
                'status' => 'failed',
                'message' => 'Feishu CLI is not available, so chat history cannot be read right now.',
            ];
        }

        $userKey = trim((string) ($identity?->provider_user_id ?: $this->resolveRunOpenId($run)));
        [$startTime, $endTime] = $this->resolveTimeWindow($params);
        $limit = $this->normalizeLimit($params['limit'] ?? null);
        $keyword = trim((string) ($params['keyword'] ?? ''));
        $chatId = trim((string) ($params['chat_id'] ?? ''));
        $groupName = trim((string) ($params['group_name'] ?? ''));
        $participantNames = $this->normalizeStringList($params['participant_names'] ?? []);
        $participantOpenIds = $this->normalizeStringList($params['participant_open_ids'] ?? []);

        $feishuConfig = $this->feishuService->readConfig();

        if ($participantOpenIds === [] && $participantNames !== []) {
            $resolution = $this->resolveParticipantsByName($feishuConfig, $userKey, $participantNames);
            if (($resolution['status'] ?? '') !== 'resolved') {
                return $resolution;
            }

            $participantOpenIds = array_values((array) ($resolution['participant_open_ids'] ?? []));
            $participantNames = array_values((array) ($resolution['participant_names'] ?? $participantNames));
        }

        if ($participantOpenIds !== [] || $participantNames !== [] || $groupName !== '') {
            return $this->readCommunicationHistory(
                $run,
                $feishuConfig,
                $accessToken,
                $userKey,
                $startTime,
                $endTime,
                $limit,
                $participantOpenIds,
                $participantNames,
                $groupName,
                $keyword
            );
        }

        return $this->readGenericHistory(
            $run,
            $feishuConfig,
            $accessToken,
            $userKey,
            $startTime,
            $endTime,
            $limit,
            $chatId,
            $keyword
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readGenericHistory(
        Run $run,
        array $feishuConfig,
        string $accessToken,
        string $userKey,
        CarbonImmutable $startTime,
        CarbonImmutable $endTime,
        int $limit,
        string $chatId,
        string $keyword
    ): array {
        $pageSize = min(100, max(30, $limit * 3));

        $command = [
            'im', '+messages-search',
            '--start', $startTime->toIso8601String(),
            '--end', $endTime->toIso8601String(),
            '--page-size', (string) $pageSize,
        ];
        if ($chatId !== '') {
            $command[] = '--chat-id';
            $command[] = $chatId;
        }
        if ($keyword !== '') {
            $command[] = '--query';
            $command[] = $keyword;
        }

        try {
            $result = $this->feishuCliClient->runSkillCommand(
                $feishuConfig,
                $accessToken,
                $command,
                'user',
                $userKey
            );
        } catch (Throwable $e) {
            return $this->blockedFromThrowable($e, 'Feishu authorization is required before I can read chat history.');
        }

        $code = (int) ($result['code'] ?? 0);
        if ($code !== 0) {
            if ($this->looksLikeAuthorizationPayload($result)) {
                return $this->blockedFromCliPayload($result, 'Feishu authorization is required before I can read chat history.');
            }

            return [
                'status' => 'failed',
                'message' => 'Reading chat history failed: ' . trim((string) ($result['msg'] ?? 'chat_history_read_failed')),
                'error' => $result,
            ];
        }

        $messages = (array) ($result['data']['messages'] ?? $result['data']['items'] ?? []);
        $records = [];
        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $record = $this->normalizeMessageRecord($run, $message);
            if ($record === null) {
                continue;
            }

            if ($chatId !== '' && (string) ($record['chat_id'] ?? '') !== $chatId) {
                continue;
            }

            if ($keyword !== '' && ! $this->matchesKeyword($record, $keyword)) {
                continue;
            }

            $records[] = $record;
            if (count($records) >= $limit) {
                break;
            }
        }

        if ($records === []) {
            return [
                'status' => 'read',
                'message' => 'I did not find matching chat messages in that time window.',
                'messages' => [],
                'raw_data' => [
                    'messages' => [],
                    'filters' => $this->filtersSummary($chatId, $keyword, $startTime, $endTime, $limit),
                ],
            ];
        }

        $lines = [];
        $lines[] = sprintf(
            'I found %d matching chat message%s.',
            count($records),
            count($records) === 1 ? '' : 's'
        );

        foreach ($records as $index => $record) {
            $parts = [];
            $time = trim((string) ($record['created_at'] ?? ''));
            if ($time !== '') {
                $parts[] = $time;
            }

            $chatName = trim((string) ($record['chat_name'] ?? ''));
            if ($chatName !== '') {
                $parts[] = $chatName;
            }

            $sender = trim((string) ($record['sender_name'] ?? ''));
            if ($sender !== '') {
                $parts[] = $sender;
            }

            $snippet = trim((string) ($record['snippet'] ?? ''));
            $lines[] = ($index + 1) . '. ' . implode(' | ', $parts) . ': ' . $snippet;
        }

        return [
            'status' => 'read',
            'message' => implode("\n", $lines),
            'messages' => $records,
            'raw_data' => [
                'messages' => $records,
                'filters' => $this->filtersSummary($chatId, $keyword, $startTime, $endTime, $limit),
            ],
        ];
    }

    /**
     * @param  array<int, string>  $participantOpenIds
     * @param  array<int, string>  $participantNames
     * @return array<string, mixed>
     */
    private function readCommunicationHistory(
        Run $run,
        array $feishuConfig,
        string $accessToken,
        string $userKey,
        CarbonImmutable $startTime,
        CarbonImmutable $endTime,
        int $limit,
        array $participantOpenIds,
        array $participantNames,
        string $groupName,
        string $keyword
    ): array {
        $currentUserOpenId = $this->resolveRunOpenId($run);
        $candidateChats = $this->collectCandidateChats(
            $feishuConfig,
            $accessToken,
            $userKey,
            $participantOpenIds,
            $groupName,
            $startTime,
            $endTime
        );

        $records = [];
        $seen = [];

        foreach ($candidateChats as $chat) {
            $chatId = trim((string) ($chat['chat_id'] ?? ''));
            if ($chatId === '') {
                continue;
            }

            $messages = $this->listMessagesForChat($feishuConfig, $accessToken, $userKey, $chatId, $startTime, $endTime);
            foreach ($messages as $message) {
                if (! is_array($message)) {
                    continue;
                }

                $record = $this->normalizeMessageRecord($run, $message, [
                    'scope' => $this->detectCommunicationScope($message),
                    'chat_name' => (string) ($chat['chat_name'] ?? ($message['chat_name'] ?? '')),
                    'chat_type' => (string) ($chat['chat_type'] ?? ($message['chat_type'] ?? '')),
                ]);
                if ($record === null) {
                    continue;
                }

                $senderId = trim((string) ($record['sender_open_id'] ?? ''));
                if ($senderId === '' || ! in_array($senderId, array_filter(array_merge([$currentUserOpenId], $participantOpenIds)), true)) {
                    continue;
                }

                if ($groupName !== '' && ! $this->matchesKeyword([
                    'chat_name' => (string) ($record['chat_name'] ?? ''),
                    'sender_name' => '',
                    'snippet' => '',
                ], $groupName)) {
                    continue;
                }

                if ($keyword !== '' && ! $this->matchesKeyword($record, $keyword)) {
                    continue;
                }

                $dedupeKey = implode('|', [
                    (string) ($record['chat_id'] ?? ''),
                    (string) ($record['created_at'] ?? ''),
                    (string) ($record['sender_open_id'] ?? ''),
                    (string) ($record['snippet'] ?? ''),
                ]);
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;

                $records[] = $record;
                if (count($records) >= $limit) {
                    break 2;
                }
            }
        }

        usort(
            $records,
            static fn (array $a, array $b): int => strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''))
        );

        return $this->buildCommunicationResult(
            $records,
            $participantOpenIds,
            $participantNames,
            $groupName,
            $startTime,
            $endTime
        );
    }

    /**
     * 把 records 格式化成 chat.history_read 标准返回。chunks 路径和飞书路径共用。
     *
     * @param  array<int, array<string, mixed>>  $records
     * @param  array<int, string>                $participantOpenIds
     * @param  array<int, string>                $participantNames
     * @return array<string, mixed>
     */
    private function buildCommunicationResult(
        array $records,
        array $participantOpenIds,
        array $participantNames,
        string $groupName,
        CarbonImmutable $startTime,
        CarbonImmutable $endTime
    ): array {
        $displayParticipants = $participantNames !== [] ? $participantNames : $participantOpenIds;
        $participantLabel = implode(', ', array_slice($displayParticipants, 0, 3));
        if ($participantLabel === '') {
            $participantLabel = 'the selected people';
        }

        if ($records === []) {
            $message = 'I did not find direct human chat messages between you and ' . $participantLabel . ' in that time window.';
            if ($groupName !== '') {
                $message .= ' I also checked the chat context around ' . $groupName . ', but there were no matching human messages.';
            } else {
                $message .= ' I checked both direct and shared chats, but only bot/system noise or no relevant messages were found.';
            }

            return [
                'status' => 'read',
                'message' => $message,
                'messages' => [],
                'raw_data' => [
                    'messages' => [],
                    'participants' => $participantOpenIds,
                    'participant_names' => $participantNames,
                    'group_name' => $groupName,
                    'start_time' => $startTime->toIso8601String(),
                    'end_time' => $endTime->toIso8601String(),
                ],
            ];
        }

        $chatCount = count(array_unique(array_map(
            static fn (array $record): string => (string) ($record['chat_id'] ?? ''),
            $records
        )));

        $lines = [];
        $lines[] = sprintf(
            'I found %d message%s involving you and %s across %d chat%s.',
            count($records),
            count($records) === 1 ? '' : 's',
            $participantLabel,
            $chatCount,
            $chatCount === 1 ? '' : 's'
        );

        foreach ($records as $index => $record) {
            $parts = [];
            $time = trim((string) ($record['created_at'] ?? ''));
            if ($time !== '') {
                $parts[] = $time;
            }

            $chatName = trim((string) ($record['chat_name'] ?? ''));
            if ($chatName !== '') {
                $parts[] = $chatName;
            }

            $sender = trim((string) ($record['sender_name'] ?? ''));
            if ($sender !== '') {
                $parts[] = $sender;
            }

            $scope = trim((string) ($record['scope'] ?? ''));
            if ($scope !== '') {
                $parts[] = $scope;
            }

            $lines[] = ($index + 1) . '. ' . implode(' | ', $parts) . ': ' . trim((string) ($record['snippet'] ?? ''));
        }

        return [
            'status' => 'read',
            'message' => implode("\n", $lines),
            'messages' => $records,
            'raw_data' => [
                'messages' => $records,
                'participants' => $participantOpenIds,
                'participant_names' => $participantNames,
                'group_name' => $groupName,
                'start_time' => $startTime->toIso8601String(),
                'end_time' => $endTime->toIso8601String(),
            ],
        ];
    }

    /**
     * 优先从本地 attachment_chunks 查 chat 归档（auto_archive + source_kind=chat）。
     * 命中返回 normalize 过的 records；未命中返回 null（让调用方 fallback 飞书 API）。
     *
     * 匹配策略（fallback 友好）：
     *  - participant_names: 在 attachment file_name LIKE '%name%' 模糊匹配（chat_name 推断为 "私聊·用户B" / 群聊 chat_name）
     *  - group_name: 同样走 file_name LIKE
     *  - keyword: chunks.content LIKE
     *  - 时间窗口: file_key 形如 "{chat_id}:YYYY-MM-DD"，按日期截
     *  - 三个都为空: 返回 null（不该走这里）
     *
     * @param  array<int, string>  $participantNames
     * @return array<int, array<string, mixed>>|null
     */
    private function readChatHistoryFromLocalChunks(
        Run $run,
        CarbonImmutable $startTime,
        CarbonImmutable $endTime,
        int $limit,
        array $participantNames,
        string $groupName,
        string $keyword
    ): ?array {
        $userId = (int) ($run->user_id ?? 0);
        if ($userId <= 0) {
            return null;
        }

        // 没有 participant 也没有 group，按 chunks 没法准确锁定到对话——交给 fallback
        if ($participantNames === [] && trim($groupName) === '') {
            return null;
        }

        $startDate = $startTime->setTimezone(self::DEFAULT_TIMEZONE)->format('Y-m-d');
        $endDate = $endTime->setTimezone(self::DEFAULT_TIMEZONE)->format('Y-m-d');

        $aq = Attachment::query()
            ->where('user_id', $userId)
            ->where('attachment_type', 'auto_archive')
            ->where('parse_status', Attachment::STATUS_READY);

        // source_kind=chat 过滤（meta JSON）
        $aq->where(function ($q) {
            $q->whereJsonContains('meta->source_kind', 'chat')
              ->orWhere('meta->source_kind', 'chat');
        });

        // participant_names / group_name → file_name LIKE
        $needles = array_filter(array_map('trim', array_merge($participantNames, [$groupName])));
        if ($needles !== []) {
            $aq->where(function ($q) use ($needles) {
                foreach ($needles as $n) {
                    $q->orWhere('file_name', 'LIKE', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $n) . '%');
                }
            });
        }

        $attachments = $aq->orderByDesc('id')->limit(50)->get();
        if ($attachments->isEmpty()) {
            return null;
        }

        // 按 file_key 解析的日期过滤（file_key = "{chat_id}:YYYY-MM-DD"）
        $attachmentIds = [];
        foreach ($attachments as $att) {
            $fk = (string) ($att->file_key ?? '');
            $date = '';
            if (preg_match('/:(\d{4}-\d{2}-\d{2})$/', $fk, $mm)) {
                $date = $mm[1];
            }
            if ($date === '' || ($date >= $startDate && $date <= $endDate)) {
                $attachmentIds[] = (int) $att->id;
            }
        }
        if ($attachmentIds === []) {
            return null;
        }

        $cq = AttachmentChunk::query()
            ->whereIn('attachment_id', $attachmentIds)
            ->where('user_id', $userId);

        if (trim($keyword) !== '') {
            $kw = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($keyword)) . '%';
            $cq->where('content', 'LIKE', $kw);
        }

        $chunks = $cq->orderBy('attachment_id')->orderBy('chunk_index')->limit($limit * 5)->get();
        if ($chunks->isEmpty()) {
            return null;
        }

        // 把每个 chunk 拆出 transcript 行，转成 record。chunk content 形如：
        // "[15:10 我→私聊·用户B] 你记得后天准备一下618的营销节奏汇报"
        $attachmentMap = $attachments->keyBy('id');
        $records = [];
        foreach ($chunks as $chunk) {
            $att = $attachmentMap[$chunk->attachment_id] ?? null;
            if (! $att) {
                continue;
            }
            $chatName = (string) ($att->file_name ?? '');
            $fileKey = (string) ($att->file_key ?? '');
            [$chatId, $date] = $this->splitChatFileKey($fileKey);

            $lines = preg_split('/\r?\n/', (string) $chunk->content);
            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                if (trim($keyword) !== '' && ! $this->matchesKeyword(['snippet' => $line, 'chat_name' => $chatName, 'sender_name' => ''], $keyword)) {
                    continue;
                }

                $rec = $this->parseTranscriptLine($line, $chatId, $chatName, $date);
                if ($rec === null) {
                    continue;
                }
                $records[] = $rec;
                if (count($records) >= $limit) {
                    break 2;
                }
            }
        }

        if ($records === []) {
            return null;
        }

        usort($records, static fn (array $a, array $b): int => strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? '')));

        return $records;
    }

    /**
     * file_key = "{chat_id}:YYYY-MM-DD" → [chat_id, date]
     *
     * @return array{0:string,1:string}
     */
    private function splitChatFileKey(string $fileKey): array
    {
        if (preg_match('/^(.*?):(\d{4}-\d{2}-\d{2})$/', $fileKey, $mm)) {
            return [$mm[1], $mm[2]];
        }
        return [$fileKey, ''];
    }

    /**
     * 解析 transcript 行 "[HH:mm A→B] text"，回填 normalize record。
     *
     * @return array<string, mixed>|null
     */
    private function parseTranscriptLine(string $line, string $chatId, string $chatName, string $date): ?array
    {
        if (! preg_match('/^\[(\d{1,2}:\d{2})\s+(.+?)\]\s+(.+)$/u', $line, $mm)) {
            return null;
        }
        $hhmm = $mm[1];
        $arrow = trim($mm[2]);
        $text = trim($mm[3]);
        if ($text === '') {
            return null;
        }

        $direction = 'received';
        $sender = '对方';
        if (str_starts_with($arrow, '我→')) {
            $direction = 'sent';
            $sender = '我';
        } else {
            // "用户B→我"
            $parts = explode('→', $arrow, 2);
            if (isset($parts[0]) && trim($parts[0]) !== '') {
                $sender = trim($parts[0]);
            }
        }

        return [
            'chat_id' => $chatId,
            'chat_name' => $chatName,
            'chat_type' => '',
            'sender_name' => $this->redactLabel($sender),
            'sender_open_id' => '',
            'direction' => $direction,
            'created_at' => trim($date . ' ' . $hhmm),
            'snippet' => $this->redactSensitiveText($text),
            'msg_type' => 'text',
            'scope' => 'local_chunks',
        ];
    }

    /**
     * @param  array<int, string>  $participantNames
     * @return array<string, mixed>
     */
    private function resolveParticipantsByName(array $feishuConfig, string $userKey, array $participantNames): array
    {
        $resolvedIds = [];
        $resolvedNames = [];

        foreach ($participantNames as $participantName) {
            $command = ['contact', '+search-user', '--query', $participantName];

            try {
                $result = $this->feishuCliClient->runSkillCommand($feishuConfig, '', $command, 'user', $userKey);
            } catch (Throwable $e) {
                return $this->blockedFromThrowable($e, 'Feishu authorization is required before I can resolve the participant you mentioned.');
            }

            if ((int) ($result['code'] ?? 0) !== 0) {
                if ($this->looksLikeAuthorizationPayload($result)) {
                    return $this->blockedFromCliPayload($result, 'Feishu authorization is required before I can resolve the participant you mentioned.');
                }

                return [
                    'status' => 'failed',
                    'message' => 'Resolving the participant failed: ' . trim((string) ($result['msg'] ?? 'contact_lookup_failed')),
                    'error' => $result,
                ];
            }

            $users = array_values(array_filter((array) ($result['data']['users'] ?? []), 'is_array'));
            $matched = $this->pickBestUserMatch($participantName, $users);
            if ($matched === []) {
                return [
                    'status' => 'clarify',
                    'message' => 'I could not reliably identify ' . $participantName . ' in Feishu contacts. Please provide the exact name or the specific group name.',
                ];
            }

            $openId = trim((string) ($matched['open_id'] ?? ''));
            if ($openId === '') {
                return [
                    'status' => 'clarify',
                    'message' => 'I found a contact for ' . $participantName . ', but the record does not contain a usable open_id yet.',
                ];
            }

            $resolvedIds[$openId] = true;
            $resolvedNames[] = trim((string) ($matched['name'] ?? $participantName));
        }

        return [
            'status' => 'resolved',
            'participant_open_ids' => array_keys($resolvedIds),
            'participant_names' => array_values(array_unique(array_filter($resolvedNames))),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $users
     * @return array<string, mixed>
     */
    private function pickBestUserMatch(string $targetName, array $users): array
    {
        $normalizedTarget = $this->normalizeKeyword($targetName);
        if ($normalizedTarget === '') {
            return [];
        }

        foreach ($users as $user) {
            $candidate = $this->normalizeKeyword((string) ($user['name'] ?? ($user['en_name'] ?? '')));
            if ($candidate !== '' && $candidate === $normalizedTarget) {
                return $user;
            }
        }

        foreach ($users as $user) {
            $candidate = $this->normalizeKeyword((string) ($user['name'] ?? ($user['en_name'] ?? '')));
            if ($candidate !== '' && (str_contains($candidate, $normalizedTarget) || str_contains($normalizedTarget, $candidate))) {
                return $user;
            }
        }

        return $users[0] ?? [];
    }

    /**
     * @param  array<int, string>  $participantOpenIds
     * @return array<int, array<string, string>>
     */
    private function collectCandidateChats(
        array $feishuConfig,
        string $accessToken,
        string $userKey,
        array $participantOpenIds,
        string $groupName,
        CarbonImmutable $startTime,
        CarbonImmutable $endTime
    ): array {
        $candidates = [];

        foreach ($participantOpenIds as $participantOpenId) {
            foreach ($this->searchMessagesBySender($feishuConfig, $accessToken, $userKey, $participantOpenId, $startTime, $endTime) as $message) {
                $chatId = trim((string) ($message['chat_id'] ?? ''));
                if ($chatId === '') {
                    continue;
                }

                $chatName = trim((string) ($message['chat_name'] ?? ''));
                $chatType = trim((string) ($message['chat_type'] ?? ''));

                if ($groupName !== '' && ! $this->matchesKeyword([
                    'chat_name' => $chatName,
                    'sender_name' => '',
                    'snippet' => '',
                ], $groupName)) {
                    continue;
                }

                $candidates[$chatId] = [
                    'chat_id' => $chatId,
                    'chat_name' => $chatName,
                    'chat_type' => $chatType,
                ];

                if (count($candidates) >= self::MAX_CHAT_CANDIDATES) {
                    break 2;
                }
            }
        }

        if ($candidates === [] && $participantOpenIds !== []) {
            foreach ($this->searchChatsByParticipants($feishuConfig, $accessToken, $userKey, $participantOpenIds, $groupName) as $chat) {
                $chatId = trim((string) ($chat['chat_id'] ?? ''));
                if ($chatId === '') {
                    continue;
                }

                $candidates[$chatId] = [
                    'chat_id' => $chatId,
                    'chat_name' => trim((string) ($chat['name'] ?? '')),
                    'chat_type' => 'group',
                ];

                if (count($candidates) >= self::MAX_CHAT_CANDIDATES) {
                    break;
                }
            }
        }

        return array_values($candidates);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchMessagesBySender(
        array $feishuConfig,
        string $accessToken,
        string $userKey,
        string $senderOpenId,
        CarbonImmutable $startTime,
        CarbonImmutable $endTime
    ): array {
        $command = [
            'im', '+messages-search',
            '--sender', $senderOpenId,
            '--start', $startTime->toIso8601String(),
            '--end', $endTime->toIso8601String(),
            '--page-size', '50',
            '--exclude-sender-type', 'bot',
        ];

        try {
            $result = $this->feishuCliClient->runSkillCommand($feishuConfig, $accessToken, $command, 'user', $userKey);
        } catch (Throwable) {
            return [];
        }

        if ((int) ($result['code'] ?? 0) !== 0) {
            return [];
        }

        return array_values(array_filter((array) ($result['data']['messages'] ?? []), 'is_array'));
    }

    /**
     * @param  array<int, string>  $participantOpenIds
     * @return array<int, array<string, mixed>>
     */
    private function searchChatsByParticipants(
        array $feishuConfig,
        string $accessToken,
        string $userKey,
        array $participantOpenIds,
        string $groupName
    ): array {
        $command = [
            'im', '+chat-search',
            '--member-ids', implode(',', $participantOpenIds),
            '--page-size', '20',
        ];
        if ($groupName !== '') {
            $command[] = '--query';
            $command[] = $groupName;
        }

        try {
            $result = $this->feishuCliClient->runSkillCommand($feishuConfig, $accessToken, $command, 'user', $userKey);
        } catch (Throwable) {
            return [];
        }

        if ((int) ($result['code'] ?? 0) !== 0) {
            return [];
        }

        return array_values(array_filter((array) ($result['data']['chats'] ?? []), 'is_array'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listMessagesForChat(
        array $feishuConfig,
        string $accessToken,
        string $userKey,
        string $chatId,
        CarbonImmutable $startTime,
        CarbonImmutable $endTime
    ): array {
        $command = [
            'im', '+chat-messages-list',
            '--chat-id', $chatId,
            '--start', $startTime->toIso8601String(),
            '--end', $endTime->toIso8601String(),
            '--page-size', (string) self::MAX_MESSAGES_PER_CHAT,
            '--sort', 'desc',
        ];

        try {
            $result = $this->feishuCliClient->runSkillCommand($feishuConfig, $accessToken, $command, 'user', $userKey);
        } catch (Throwable) {
            return [];
        }

        if ((int) ($result['code'] ?? 0) !== 0) {
            return [];
        }

        return array_values(array_filter((array) ($result['data']['messages'] ?? []), 'is_array'));
    }

    private function detectCommunicationScope(array $message): string
    {
        return trim((string) ($message['chat_type'] ?? '')) === 'p2p'
            ? 'direct_chat'
            : 'shared_chat';
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, string>  $overrides
     * @return array<string, string>|null
     */
    private function normalizeMessageRecord(Run $run, array $message, array $overrides = []): ?array
    {
        $sender = is_array($message['sender'] ?? null) ? $message['sender'] : [];
        $senderType = trim((string) ($sender['sender_type'] ?? ''));
        if (in_array($senderType, ['bot', 'app'], true)) {
            return null;
        }

        $msgType = trim((string) ($message['msg_type'] ?? 'text'));
        if ($msgType === 'system') {
            return null;
        }

        $text = trim((string) ($message['content'] ?? ''));
        if ($text === '') {
            $text = $this->extractMessageText($message);
        }
        if ($text === '') {
            return null;
        }

        $senderName = trim((string) ($message['sender_name'] ?? $sender['name'] ?? $sender['id'] ?? 'Unknown sender'));
        $senderId = trim((string) ($sender['id'] ?? ''));
        $chatType = trim((string) ($overrides['chat_type'] ?? ($message['chat_type'] ?? '')));
        $chatName = trim((string) ($overrides['chat_name'] ?? ($message['chat_name'] ?? '')));
        $chatId = trim((string) ($message['chat_id'] ?? ($overrides['chat_id'] ?? '')));

        if ($chatName === '' && $chatType === 'p2p') {
            $chatName = 'Private chat';
        }
        if ($chatName === '') {
            $chatPartner = is_array($message['chat_partner'] ?? null) ? $message['chat_partner'] : [];
            $chatName = trim((string) ($chatPartner['name'] ?? $chatId));
        }
        if ($chatName === '') {
            $chatName = 'Unknown chat';
        }

        return [
            'chat_id' => $chatId,
            'chat_name' => $this->redactLabel($chatName),
            'chat_type' => $chatType,
            'sender_name' => $this->redactLabel($senderName),
            'sender_open_id' => $senderId,
            'direction' => $senderId !== '' && $senderId === $this->resolveRunOpenId($run) ? 'sent' : 'received',
            'created_at' => $this->formatTime((string) ($message['create_time'] ?? '')),
            'snippet' => $this->redactSensitiveText($text),
            'msg_type' => $msgType,
            'scope' => trim((string) ($overrides['scope'] ?? '')),
        ];
    }

    private function extractMessageText(array $message): string
    {
        $body = trim((string) ($message['body']['content'] ?? ''));
        if ($body === '') {
            return '';
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['text'])) {
            return trim((string) $decoded['text']);
        }

        return trim($body);
    }

    /**
     * @param  array<string, string>  $record
     */
    private function matchesKeyword(array $record, string $keyword): bool
    {
        $needle = $this->normalizeKeyword($keyword);
        if ($needle === '') {
            return true;
        }

        foreach (['chat_name', 'sender_name', 'snippet'] as $field) {
            $haystack = $this->normalizeKeyword((string) ($record[$field] ?? ''));
            if ($haystack !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeKeyword(string $text): string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return '';
        }

        return function_exists('mb_strtolower')
            ? mb_strtolower($trimmed, 'UTF-8')
            : strtolower($trimmed);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveTimeWindow(array $params): array
    {
        $end = $this->parseIsoTime((string) ($params['end_time'] ?? '')) ?? CarbonImmutable::now(self::DEFAULT_TIMEZONE);
        $start = $this->parseIsoTime((string) ($params['start_time'] ?? '')) ?? $end->subHours(24);

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->subHours(24), $start];
        }

        return [$start, $end];
    }

    private function normalizeLimit(mixed $value): int
    {
        $limit = is_numeric($value) ? (int) $value : self::DEFAULT_LIMIT;

        return min(self::MAX_LIMIT, max(1, $limit));
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            $value = is_string($value) && trim($value) !== ''
                ? preg_split('/\s*,\s*/', trim($value), -1, PREG_SPLIT_NO_EMPTY)
                : [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($item) => trim((string) $item),
            (array) $value
        ))));
    }

    private function parseIsoTime(string $timeStr): ?CarbonImmutable
    {
        $timeStr = trim($timeStr);
        if ($timeStr === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($timeStr, self::DEFAULT_TIMEZONE);
        } catch (Throwable) {
            return null;
        }
    }

    private function formatTime(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        try {
            return CarbonImmutable::parse($raw, self::DEFAULT_TIMEZONE)->format('Y-m-d H:i');
        } catch (Throwable) {
            return $this->truncate($raw, 32);
        }
    }

    private function redactSensitiveText(string $text): string
    {
        $text = preg_replace('/https?:\/\/\S+/iu', '[link]', $text) ?? $text;
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', '[email]', $text) ?? $text;
        $text = preg_replace('/(?<!\d)(?:\+?\d[\d -]{6,}\d)(?!\d)/u', '[phone]', $text) ?? $text;
        $text = preg_replace('/\b(?:ou|on|od|cli|tok|app)[A-Za-z0-9_-]{8,}\b/u', '[id]', $text) ?? $text;
        $text = preg_replace('/\b[A-Za-z0-9_-]{24,}\b/u', '[token]', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return $this->truncate(trim($text), 160);
    }

    private function redactLabel(string $text): string
    {
        $text = preg_replace('/\b[A-Za-z0-9_-]{24,}\b/u', '[id]', $text) ?? $text;

        return $this->truncate(trim($text), 80);
    }

    /**
     * @return array<string, mixed>
     */
    private function filtersSummary(string $chatId, string $keyword, CarbonImmutable $startTime, CarbonImmutable $endTime, int $limit): array
    {
        return [
            'chat_id' => $chatId,
            'keyword' => $keyword,
            'start_time' => $startTime->toIso8601String(),
            'end_time' => $endTime->toIso8601String(),
            'limit' => $limit,
        ];
    }


    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function blockedFromCliPayload(array $result, string $message): array
    {
        $errorText = trim((string) ($result['msg'] ?? ''));
        $missing = ['feishu.oauth.user_token'];
        foreach ($this->extractScopesFromText($errorText) as $scope) {
            $missing[] = 'feishu.scope.' . $scope;
        }

        return [
            'status' => 'blocked',
            'message' => $message,
            'missing' => array_values(array_unique($missing)),
            'error' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function looksLikeAuthorizationPayload(array $result): bool
    {
        $errorType = strtolower(trim((string) ($result['error_type'] ?? '')));
        if (in_array($errorType, ['auth', 'token', 'permission', 'config'], true)) {
            return true;
        }

        return $this->looksLikeAuthorizationText((string) ($result['msg'] ?? ''));
    }

    private function looksLikeAuthorizationText(string $text): bool
    {
        $text = strtolower(trim($text));
        if ($text === '') {
            return false;
        }

        return str_contains($text, '[auth]')
            || str_contains($text, 'required scope:')
            || str_contains($text, 'not logged in')
            || str_contains($text, 'login required')
            || str_contains($text, 'authorization required')
            || str_contains($text, 'authorization is required')
            || str_contains($text, 'unauthorized')
            || str_contains($text, 'invalid access token')
            || str_contains($text, 'access token expired');
    }



}
