<?php

namespace App\Modules\ProactiveReminder\Kernel;

use App\Modules\ProactiveReminder\Analyzers\ActivityArchiveAnalyzer;
use App\Modules\ProactiveReminder\Contracts\ActivitySourceInterface;
use App\Modules\ProactiveReminder\DTO\ActivityBatch;
use App\Modules\ProactiveReminder\DTO\AnalyzerResult;
use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use App\Services\AttachmentService;
use App\Services\Feishu\FeishuDocFetcher;
use App\Services\MemoryService;
use Illuminate\Support\Facades\Log;

/**
 * Periodic activity archive kernel.
 *
 * Runs every 2 hours during 07:00~21:00. For each active user:
 *   1. Collect last 2 hours of feishu activity (reuses 9 ActivitySources)
 *   2. Distill into a short L2 episodic memory entry via ActivityArchiveAnalyzer
 *   3. Persist to memory_entries (layer=L2, tags=[source:proactive_collect])
 *      + L2 markdown file under storage/app/user_data/{uid}/memory/l2/
 *
 * Differs from DailySummaryKernel:
 *   - Does NOT send Feishu messages (no ReminderChannel involved)
 *   - Does NOT use cooldown/quiet_hours policy
 *   - Does NOT write proactive_activity_snapshots (skips state store)
 *   - Output goes to L2 memory, not as a chat message
 */
class ActivityArchiveKernel
{
    /**
     * @param  iterable<int,ActivitySourceInterface>  $sources
     */
    public function __construct(
        private readonly iterable $sources,
        private readonly ActivityArchiveAnalyzer $analyzer,
        private readonly MemoryService $memoryService,
        private readonly FeishuDocFetcher $docFetcher,
        private readonly AttachmentService $attachmentService,
        private readonly \App\Services\FeishuCliClient $cliClient,
        private readonly \App\Services\Feishu\FeishuCalendarEventFetcher $calendarFetcher,
        private readonly \App\Services\Feishu\FeishuSheetContentFetcher $sheetFetcher,
        private readonly \App\Services\Feishu\FeishuBitableFetcher $bitableFetcher,
    ) {
    }

    /**
     * @param  array<string,mixed>  $feishuConfig
     * @return array{archived:bool,reason:string,entry_id:?int,activity_count:int}
     */
    public function run(ReminderScanRequest $request, array $feishuConfig): array
    {
        // Phase 1: Collect activity from all 9 sources
        $batch = new ActivityBatch();
        foreach ($this->sources as $source) {
            if (! $source->supports($request)) {
                continue;
            }
            foreach ($source->collect($request, $feishuConfig) as $result) {
                $batch->add($result);
            }
        }

        $totalActivity = count($batch->items())
            + count($batch->calendar())
            + count($batch->messages())
            + count($batch->documents())
            + count($batch->meetings())
            + count($batch->sheets())
            + count($batch->bitables())
            + count($batch->mails());

        if (! $batch->hasActivity() && $totalActivity === 0) {
            return [
                'archived' => false,
                'reason' => 'no_activity',
                'entry_id' => null,
                'activity_count' => 0,
            ];
        }

        // Phase 2: 无条件 ingest（不论 analyzer 后面决定是否写 L2 摘要）。
        // 原文 chunk 化是为了以后用户查询时本地命中——跟"今天值不值得归档"无关。
        // 每个 ingestXxx 单独 try/catch + 失败 log，互不影响。
        $this->safeIngest('documents', fn () => $this->ingestDocuments($request, $batch));
        $this->safeIngest('chats', fn () => $this->ingestChats($request, $batch));
        $this->safeIngest('mails', fn () => $this->ingestMails($request, $batch, $feishuConfig));
        $this->safeIngest('tasks', fn () => $this->ingestTasks($request, $batch, $feishuConfig));
        $this->safeIngest('meetings', fn () => $this->ingestMeetings($request, $batch, $feishuConfig));
        $this->safeIngest('calendar', fn () => $this->ingestCalendarEvents($request, $batch, $feishuConfig));
        $this->safeIngest('sheets', fn () => $this->ingestSheetsDeep($request, $batch, $feishuConfig));
        $this->safeIngest('bitables', fn () => $this->ingestBitablesDeep($request, $batch, $feishuConfig));

        // Phase 3: LLM distill into archive summary
        $analysis = $this->analyzer->analyze($batch, $request);

        if (! $analysis->shouldNotify || trim((string) $analysis->message) === '') {
            return [
                'archived' => false,
                'reason' => (string) ($analysis->reasoning ?? 'analyzer_declined'),
                'entry_id' => null,
                'activity_count' => $totalActivity,
            ];
        }

        // Phase 3: Persist to L2
        $title = sprintf(
            '系统归档 %s~%s',
            $request->since->setTimezone('Asia/Shanghai')->format('H:i'),
            $request->until->setTimezone('Asia/Shanghai')->format('H:i')
        );

        try {
            $result = $this->memoryService->archiveProactiveSummary(
                $request->userId,
                $title,
                trim((string) $analysis->message)
            );
        } catch (\Throwable $e) {
            Log::error('[ActivityArchive] persist_failed', [
                'user_id' => $request->userId,
                'error' => $e->getMessage(),
            ]);
            return [
                'archived' => false,
                'reason' => 'persist_error: ' . $e->getMessage(),
                'entry_id' => null,
                'activity_count' => $totalActivity,
            ];
        }

        return [
            'archived' => $result['skipped'] === null,
            'reason' => $result['skipped'] ?? 'archived',
            'entry_id' => $result['entry_id'],
            'activity_count' => $totalActivity,
        ];
    }

    /**
     * 把 batch.documents() 里每篇文档的正文 fetch 下来落到 attachment_chunks。
     * 用户用 user OAuth；fetch 失败/缺 token 会被 FeishuDocFetcher 内部 log 掉，这里只记
     * 总成功/失败计数。
     */
    private function ingestDocuments(ReminderScanRequest $request, ActivityBatch $batch): void
    {
        $docs = $batch->documents();
        if (empty($docs)) {
            return;
        }

        $ingested = 0;
        $skipped = 0;
        $failed = 0;
        $newChunks = 0;

        foreach ($docs as $doc) {
            $url = trim((string) ($doc['url'] ?? ''));
            $title = trim((string) ($doc['title'] ?? ''));
            if ($url === '') {
                continue;
            }

            try {
                $r = $this->docFetcher->fetchDocument($request->userId, $url);
                if (! ($r['ok'] ?? false)) {
                    $failed++;
                    continue;
                }

                $sourceKey = (string) ($r['doc_id'] ?? $url);
                $markdown = (string) ($r['markdown'] ?? '');
                if (trim($markdown) === '') {
                    $skipped++;
                    continue;
                }

                $ingestResult = $this->attachmentService->ingestRemoteDocument(
                    $request->userId,
                    'doc',
                    $sourceKey,
                    $title !== '' ? $title : (string) ($r['title'] ?? ''),
                    $markdown,
                    'text/markdown'
                );

                if ($ingestResult['skipped'] === null) {
                    $ingested++;
                    $newChunks += (int) ($ingestResult['chunk_count'] ?? 0);
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('[ActivityArchive] doc_ingest_failed', [
                    'user_id' => $request->userId,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (($ingested + $skipped + $failed) > 0) {
            Log::info('[ActivityArchive] doc_ingest_summary', [
                'user_id' => $request->userId,
                'docs_seen' => count($docs),
                'ingested' => $ingested,
                'skipped_duplicate' => $skipped,
                'failed' => $failed,
                'new_chunks' => $newChunks,
            ]);
        }
    }

    /**
     * 把 batch.messages() 里的 P2P / 群聊原文按 chat_id + date 分组打包成 markdown
     * transcript，调 AttachmentService::ingestRemoteDocument 落到 attachment_chunks
     * （source_kind='chat'），跟文档共用 RAG 检索路径。
     *
     * 设计：
     *  - file_key = "{chat_id}:{YYYY-MM-DD}" —— 同一对话同一天若再次扫描会按 hash 防重
     *  - file_name = "与{对方名}的对话 {YYYY-MM-DD}" 或 "群聊·{chat_name} {YYYY-MM-DD}"
     *  - transcript 行 = "[HH:mm 发件人→接收方] 文本"，按 time 升序
     */
    private function ingestChats(ReminderScanRequest $request, ActivityBatch $batch): void
    {
        $messages = $batch->messages();
        if (empty($messages)) {
            return;
        }

        // 按 chat_id + date 分组
        $groups = [];
        foreach ($messages as $m) {
            $chatId = trim((string) ($m['chat_id'] ?? ''));
            if ($chatId === '') {
                continue;
            }
            $time = trim((string) ($m['time'] ?? ''));
            $date = $this->extractDate($time, $request);
            $key = $chatId . '|' . $date;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'chat_id' => $chatId,
                    'date' => $date,
                    'chat_name' => trim((string) ($m['chat_name'] ?? '')),
                    'rows' => [],
                ];
            }
            $groups[$key]['rows'][] = $m;
        }

        $ingested = 0;
        $skipped = 0;
        $failed = 0;
        $newChunks = 0;

        foreach ($groups as $g) {
            try {
                $transcript = $this->renderTranscript($g['rows']);
                if (trim($transcript) === '') {
                    continue;
                }

                $title = $this->renderChatTitle($g['chat_name'], $g['date']);
                $fileKey = $g['chat_id'] . ':' . $g['date'];

                $r = $this->attachmentService->ingestRemoteDocument(
                    $request->userId,
                    'chat',
                    $fileKey,
                    $title,
                    $transcript,
                    'text/markdown'
                );

                if (($r['skipped'] ?? null) === null) {
                    $ingested++;
                    $newChunks += (int) ($r['chunk_count'] ?? 0);
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('[ActivityArchive] chat_ingest_failed', [
                    'user_id' => $request->userId,
                    'chat_id' => $g['chat_id'] ?? '',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (($ingested + $skipped + $failed) > 0) {
            Log::info('[ActivityArchive] chat_ingest_summary', [
                'user_id' => $request->userId,
                'chats_seen' => count($groups),
                'ingested' => $ingested,
                'skipped_duplicate' => $skipped,
                'failed' => $failed,
                'new_chunks' => $newChunks,
            ]);
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function renderTranscript(array $rows): string
    {
        // 按 time 字符串字典序升序（YYYY-MM-DD HH:mm 同序）
        usort($rows, static fn ($a, $b) => strcmp((string) ($a['time'] ?? ''), (string) ($b['time'] ?? '')));

        $lines = [];
        foreach ($rows as $r) {
            $time = trim((string) ($r['time'] ?? ''));
            // 取 HH:mm
            $hhmm = '';
            if (preg_match('/(\d{1,2}:\d{2})/', $time, $mm)) {
                $hhmm = $mm[1];
            }

            $direction = trim((string) ($r['direction'] ?? ''));
            $sender = trim((string) ($r['sender'] ?? '')) ?: ($direction === 'sent' ? '我' : '对方');
            $arrow = $direction === 'sent' ? '我→' . (trim((string) ($r['chat_name'] ?? '')) ?: '对话') : ($sender . '→我');

            $text = trim((string) ($r['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $prefix = $hhmm !== '' ? '[' . $hhmm . ' ' . $arrow . '] ' : '[' . $arrow . '] ';
            $lines[] = $prefix . $text;
        }

        return implode("
", $lines);
    }

    private function renderChatTitle(string $chatName, string $date): string
    {
        $chatName = trim($chatName);
        if ($chatName === '') {
            $chatName = '对话';
        }
        // chat_name 已经是"私聊·朱雀"这种格式，直接拼日期
        return $chatName . ' ' . $date;
    }

    private function extractDate(string $time, ReminderScanRequest $request): string
    {
        // time 格式 "2026-04-29 15:10" 或 ISO；取 YYYY-MM-DD
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $time, $mm)) {
            return $mm[1];
        }
        // fallback：用 request 的 since 日期
        return $request->since->setTimezone('Asia/Shanghai')->format('Y-m-d');
    }


    /**
     * 包装 ingest 调用，失败不抛、log warning。一个 source 失败不影响其它。
     */
    private function safeIngest(string $kind, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('[ActivityArchive] ingest_phase_failed', [
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ─────────────────────── Mail ───────────────────────

    /**
     * 邮件 ingest：用 lark-cli mail +message 拉单封正文（含 attachments meta）。
     * 失败：飞书企业邮箱未开通时返 4013，整个 user 跳过即可。
     */
    private function ingestMails(ReminderScanRequest $request, ActivityBatch $batch, array $feishuConfig): void
    {
        $mails = $batch->mails();
        if (empty($mails)) {
            return;
        }
        $cfg = $feishuConfig;
        $ingested = $skipped = $failed = $newChunks = 0;

        foreach ($mails as $mail) {
            $msgId = trim((string) ($mail['message_id'] ?? ''));
            if ($msgId === '') {
                $failed++;
                continue;
            }
            try {
                $r = $this->cliClient->runSkillCommand(
                    $cfg, '',
                    ['mail', '+message', '--message-id', $msgId, '--html=false'],
                    'user', $request->openId
                );
                if (! ($r['ok'] ?? false)) {
                    $err = is_array($r['error'] ?? null) ? json_encode($r['error'], JSON_UNESCAPED_UNICODE) : (string) ($r['error'] ?? 'unknown');
                    if (str_contains($err, 'user not found') || str_contains($err, '4013')) {
                        // 用户没开企业邮箱 — 当 user 维度跳过整个 mail ingest
                        Log::info('[ActivityArchive] mail_user_not_provisioned', ['user_id' => $request->userId]);
                        return;
                    }
                    $failed++;
                    continue;
                }
                $body = trim((string) ($r['data']['body_plain'] ?? $r['data']['body'] ?? ''));
                $subject = trim((string) ($mail['subject'] ?? $r['data']['subject'] ?? ''));
                $from = trim((string) ($mail['from'] ?? ''));
                $to = trim((string) ($mail['to'] ?? ''));
                $date = trim((string) ($mail['date'] ?? ''));
                if ($subject === '' && $body === '') {
                    $failed++;
                    continue;
                }
                $md = "# 邮件: " . ($subject ?: '(no subject)') . "\n"
                    . "- 发件人: " . $from . "\n"
                    . "- 收件人: " . $to . "\n"
                    . "- 时间: " . $date . "\n\n"
                    . $body;
                $title = '邮件·' . ($subject !== '' ? mb_substr($subject, 0, 60) : $msgId);
                $res = $this->attachmentService->ingestRemoteDocument(
                    $request->userId, 'mail', $msgId, $title, $md, 'text/markdown'
                );
                if (($res['skipped'] ?? null) === null) { $ingested++; $newChunks += (int) ($res['chunk_count'] ?? 0); }
                else { $skipped++; }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('[ActivityArchive] mail_ingest_failed', [
                    'user_id' => $request->userId,
                    'message_id' => $msgId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $this->logIngestSummary('mail_ingest_summary', $request->userId, count($mails), $ingested, $skipped, $failed, $newChunks);
    }

    // ─────────────────────── Task ───────────────────────

    /**
     * 任务 ingest：用 lark-cli task tasks get 拉单条 task 详情（description / due / members）。
     */
    private function ingestTasks(ReminderScanRequest $request, ActivityBatch $batch, array $feishuConfig): void
    {
        $tasks = array_filter($batch->items(), fn ($it) => isset($it->type) && $it->type === 'task');
        $cfg = $feishuConfig;
        $ingested = $skipped = $failed = $newChunks = 0;
        $seen = 0;

        foreach ($tasks as $it) {
            $seen++;
            $raw = is_array($it->payload ?? null) ? $it->payload : [];
            $taskId = trim((string) ($raw['task_id'] ?? ''));
            if ($taskId === '') { $failed++; continue; }

            try {
                $r = $this->cliClient->runSkillCommand(
                    $cfg, '',
                    ['task', 'tasks', 'get', '--params', json_encode(['task_guid' => $taskId])],
                    'user', $request->openId
                );
                if ((int) ($r['code'] ?? -1) !== 0) {
                    $failed++;
                    continue;
                }
                $task = (array) ($r['data']['task'] ?? []);
                $summary = trim((string) ($task['summary'] ?? $raw['summary'] ?? ''));
                $description = trim((string) ($task['description'] ?? ''));
                $status = $task['status'] ?? '';
                $due = is_array($task['due'] ?? null) ? ((string) ($task['due']['timestamp'] ?? '')) : '';
                $members = (array) ($task['members'] ?? []);
                $memberLines = [];
                foreach ($members as $m) {
                    $memberLines[] = '- ' . (string) ($m['role'] ?? 'member') . ': ' . (string) ($m['id'] ?? '');
                }

                $md = "# 任务: " . ($summary ?: $taskId) . "\n"
                    . "- 状态: " . (is_array($status) ? json_encode($status, JSON_UNESCAPED_UNICODE) : (string) $status) . "\n"
                    . "- 截止: " . $due . "\n"
                    . ($memberLines ? "- 成员:\n" . implode("\n", $memberLines) . "\n" : "")
                    . "\n## 详情\n\n" . $description;
                $title = '任务·' . ($summary !== '' ? mb_substr($summary, 0, 60) : $taskId);
                $res = $this->attachmentService->ingestRemoteDocument(
                    $request->userId, 'task', $taskId, $title, $md, 'text/markdown'
                );
                if (($res['skipped'] ?? null) === null) { $ingested++; $newChunks += (int) ($res['chunk_count'] ?? 0); }
                else { $skipped++; }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('[ActivityArchive] task_ingest_failed', [
                    'user_id' => $request->userId, 'task_id' => $taskId, 'error' => $e->getMessage(),
                ]);
            }
        }
        $this->logIngestSummary('task_ingest_summary', $request->userId, $seen, $ingested, $skipped, $failed, $newChunks);
    }

    // ─────────────────────── Meeting ───────────────────────

    /**
     * 会议 ingest：用 lark-cli vc +notes --meeting-ids 拉 minutes（如有）。
     */
    private function ingestMeetings(ReminderScanRequest $request, ActivityBatch $batch, array $feishuConfig): void
    {
        $meetings = $batch->meetings();
        if (empty($meetings)) {
            return;
        }
        $cfg = $feishuConfig;
        $ingested = $skipped = $failed = $newChunks = 0;

        foreach ($meetings as $m) {
            $mid = trim((string) ($m['meeting_id'] ?? ''));
            if ($mid === '') { $failed++; continue; }
            try {
                $r = $this->cliClient->runSkillCommand(
                    $cfg, '', ['vc', '+notes', '--meeting-ids', $mid], 'user', $request->openId
                );
                $notes = trim((string) ($r['data']['notes'] ?? ($m['notes'] ?? '')));
                if ($notes === '') {
                    // minute 没开通 → 用现有 notes/topic 做最小 ingest，避免完全空
                    $notes = trim((string) ($m['notes'] ?? ''));
                }
                $topic = trim((string) ($m['topic'] ?? ''));
                $start = (string) ($m['start_time'] ?? '');
                $end = (string) ($m['end_time'] ?? '');
                if ($notes === '' && $topic === '') { $failed++; continue; }

                $md = "# 会议: " . ($topic ?: $mid) . "\n"
                    . "- 开始: " . $start . "\n- 结束: " . $end . "\n\n"
                    . ($notes !== '' ? "## Minutes\n\n" . $notes : "（无 minutes）");
                $title = '会议·' . ($topic !== '' ? mb_substr($topic, 0, 60) : $mid);
                $res = $this->attachmentService->ingestRemoteDocument(
                    $request->userId, 'meeting', $mid, $title, $md, 'text/markdown'
                );
                if (($res['skipped'] ?? null) === null) { $ingested++; $newChunks += (int) ($res['chunk_count'] ?? 0); }
                else { $skipped++; }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('[ActivityArchive] meeting_ingest_failed', [
                    'user_id' => $request->userId, 'meeting_id' => $mid, 'error' => $e->getMessage(),
                ]);
            }
        }
        $this->logIngestSummary('meeting_ingest_summary', $request->userId, count($meetings), $ingested, $skipped, $failed, $newChunks);
    }

    // ─── Calendar (浅层：record 元数据 ingest，event_id 不在 record 里没法拉单条详情) ───

    private function ingestCalendarEvents(ReminderScanRequest $request, ActivityBatch $batch, array $feishuConfig): void
    {
        $events = $batch->calendar();
        if (empty($events)) { return; }
        $ingested = $skipped = $failed = $newChunks = 0;

        foreach ($events as $i => $ev) {
            $summary = trim((string) ($ev['summary'] ?? ''));
            $start = trim((string) ($ev['start_time'] ?? ''));
            $end = trim((string) ($ev['end_time'] ?? ''));
            $organizer = trim((string) ($ev['organizer'] ?? ''));
            $location = trim((string) ($ev['location'] ?? ''));
            $description = trim((string) ($ev['description'] ?? ''));
            $eventId = trim((string) ($ev['event_id'] ?? ''));
            $calendarId = trim((string) ($ev['calendar_id'] ?? ''));
            if ($summary === '' && $description === '') { continue; }

            // 优先调 CalendarEventFetcher 拉完整 description + attendees
            $attendees = [];
            if ($eventId !== '' && $calendarId !== '') {
                $r = $this->calendarFetcher->fetch($feishuConfig, $request->openId, $calendarId, $eventId);
                if ($r['ok'] ?? false) {
                    $description = trim((string) ($r['description'] ?? $description));
                    $attendees = (array) ($r['attendees'] ?? []);
                    if (trim((string) ($r['summary'] ?? '')) !== '') {
                        $summary = (string) $r['summary'];
                    }
                }
            }

            $fileKey = $eventId !== ''
                ? 'calendar:' . $eventId
                : 'calendar:' . sha1($summary . '|' . $start) . ':' . substr($start, 0, 10);
            $attLine = empty($attendees) ? '' : "- 参会人: " . implode(', ', array_slice($attendees, 0, 30)) . "\n";
            $md = "# 日历事件: " . ($summary ?: '(无标题)') . "\n"
                . "- 开始: " . $start . "\n- 结束: " . $end . "\n"
                . "- 组织者: " . $organizer . "\n- 地点: " . $location . "\n"
                . $attLine
                . "\n"
                . ($description ?: '（无描述）');
            $title = '日历·' . ($summary !== '' ? mb_substr($summary, 0, 60) : 'event_' . $i);
            try {
                $res = $this->attachmentService->ingestRemoteDocument(
                    $request->userId, 'calendar', $fileKey, $title, $md, 'text/markdown'
                );
                if (($res['skipped'] ?? null) === null) { $ingested++; $newChunks += (int) ($res['chunk_count'] ?? 0); }
                else { $skipped++; }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('[ActivityArchive] calendar_ingest_failed', [
                    'user_id' => $request->userId, 'summary' => $summary, 'error' => $e->getMessage(),
                ]);
            }
        }
        $this->logIngestSummary('calendar_ingest_summary', $request->userId, count($events), $ingested, $skipped, $failed, $newChunks);
    }

    // ─── Sheets / Bitables (浅层：title + url + modified_at，第一版不拉 cells/records) ───

    private function ingestSheetsDeep(ReminderScanRequest $request, ActivityBatch $batch, array $feishuConfig): void
    {
        $items = $batch->sheets();
        if (empty($items)) { return; }
        $ingested = $skipped = $failed = $newChunks = 0;

        foreach ($items as $it) {
            $token = trim((string) ($it['token'] ?? ''));
            $titleHint = trim((string) ($it['title'] ?? ''));
            $url = trim((string) ($it['url'] ?? ''));
            $modifiedAt = trim((string) ($it['modified_at'] ?? ''));
            if ($token === '') continue;

            $r = $this->sheetFetcher->fetch($feishuConfig, $request->openId, $token);
            $title = trim((string) ($r['title'] ?? '')) ?: $titleHint;
            if ($r['ok'] ?? false) {
                $body = (string) $r['markdown'];
                $md = "# 电子表格: " . ($title ?: $token) . "\n"
                    . "- 链接: " . $url . "\n- 最后修改: " . $modifiedAt . "\n\n"
                    . $body;
            } else {
                // 拉 cells 失败，降级到元数据
                $md = "# 电子表格: " . ($title ?: $token) . "\n"
                    . "- 链接: " . $url . "\n- 最后修改: " . $modifiedAt . "\n\n"
                    . "（cells 拉取失败：" . (string) ($r['error'] ?? 'unknown') . "，仅 ingest 元数据）";
            }
            $titleStr = '电子表格·' . mb_substr($title ?: $token, 0, 60);
            try {
                $res = $this->attachmentService->ingestRemoteDocument(
                    $request->userId, 'sheet', $token, $titleStr, $md, 'text/markdown'
                );
                if (($res['skipped'] ?? null) === null) { $ingested++; $newChunks += (int) ($res['chunk_count'] ?? 0); }
                else { $skipped++; }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('[ActivityArchive] sheet_ingest_failed', [
                    'user_id' => $request->userId, 'token' => $token, 'error' => $e->getMessage(),
                ]);
            }
        }
        $this->logIngestSummary('sheet_ingest_summary', $request->userId, count($items), $ingested, $skipped, $failed, $newChunks);
    }

    private function ingestBitablesDeep(ReminderScanRequest $request, ActivityBatch $batch, array $feishuConfig): void
    {
        $items = $batch->bitables();
        if (empty($items)) { return; }
        $ingested = $skipped = $failed = $newChunks = 0;

        foreach ($items as $it) {
            $token = trim((string) ($it['token'] ?? ''));
            $title = trim((string) ($it['title'] ?? ''));
            $url = trim((string) ($it['url'] ?? ''));
            $modifiedAt = trim((string) ($it['modified_at'] ?? ''));
            if ($token === '' || $title === '') continue;

            $r = $this->bitableFetcher->fetch($feishuConfig, $request->openId, $token);
            if ($r['ok'] ?? false) {
                $body = (string) $r['markdown'];
                $md = "# 多维表: " . $title . "\n"
                    . "- 链接: " . $url . "\n- 最后修改: " . $modifiedAt . "\n\n"
                    . $body;
            } else {
                $md = "# 多维表: " . $title . "\n"
                    . "- 链接: " . $url . "\n- 最后修改: " . $modifiedAt . "\n\n"
                    . "（tables/records 拉取失败：" . (string) ($r['error'] ?? 'unknown') . "，仅 ingest 元数据）";
            }
            $titleStr = '多维表·' . mb_substr($title, 0, 60);
            try {
                $res = $this->attachmentService->ingestRemoteDocument(
                    $request->userId, 'bitable', $token, $titleStr, $md, 'text/markdown'
                );
                if (($res['skipped'] ?? null) === null) { $ingested++; $newChunks += (int) ($res['chunk_count'] ?? 0); }
                else { $skipped++; }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('[ActivityArchive] bitable_ingest_failed', [
                    'user_id' => $request->userId, 'token' => $token, 'error' => $e->getMessage(),
                ]);
            }
        }
        $this->logIngestSummary('bitable_ingest_summary', $request->userId, count($items), $ingested, $skipped, $failed, $newChunks);
    }

    /**
     * 浅层 ingest：把 title/url/modified_at 当 metadata 写一个 attachment。
     * 用于 sheet/bitable 这类需要正文 fetcher 但还没做的 source。
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function ingestSimpleResource(string $sourceKind, string $kindLabel, array $items, int $userId): void
    {
        if (empty($items)) { return; }
        $ingested = $skipped = $failed = $newChunks = 0;

        foreach ($items as $it) {
            $token = trim((string) ($it['token'] ?? ''));
            $title = trim((string) ($it['title'] ?? ''));
            $url = trim((string) ($it['url'] ?? ''));
            $modifiedAt = trim((string) ($it['modified_at'] ?? ''));
            if ($token === '' || $title === '') { continue; }

            $md = "# {$kindLabel}: " . $title . "\n"
                . "- 链接: " . $url . "\n- 最后修改: " . $modifiedAt . "\n\n"
                . "（该 {$kindLabel} 只 ingest 了元数据；正文 fetch 留待后续 D7+ 任务）";
            $titleStr = $kindLabel . '·' . mb_substr($title, 0, 60);
            try {
                $res = $this->attachmentService->ingestRemoteDocument(
                    $userId, $sourceKind, $token, $titleStr, $md, 'text/markdown'
                );
                if (($res['skipped'] ?? null) === null) { $ingested++; $newChunks += (int) ($res['chunk_count'] ?? 0); }
                else { $skipped++; }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('[ActivityArchive] simple_ingest_failed', [
                    'kind' => $sourceKind, 'user_id' => $userId, 'token' => $token, 'error' => $e->getMessage(),
                ]);
            }
        }
        $this->logIngestSummary($sourceKind . '_ingest_summary', $userId, count($items), $ingested, $skipped, $failed, $newChunks);
    }

    private function logIngestSummary(string $tag, int $userId, int $seen, int $ingested, int $skipped, int $failed, int $newChunks): void
    {
        if (($ingested + $skipped + $failed) > 0) {
            Log::info('[ActivityArchive] ' . $tag, [
                'user_id' => $userId,
                'seen' => $seen,
                'ingested' => $ingested,
                'skipped_duplicate' => $skipped,
                'failed' => $failed,
                'new_chunks' => $newChunks,
            ]);
        }
    }
}