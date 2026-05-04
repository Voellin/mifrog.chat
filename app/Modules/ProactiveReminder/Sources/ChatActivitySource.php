<?php

namespace App\Modules\ProactiveReminder\Sources;

use App\Modules\ProactiveReminder\Contracts\ActivitySourceInterface;
use App\Modules\ProactiveReminder\DTO\ActivityItem;
use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use App\Modules\ProactiveReminder\DTO\SourceCollectionResult;
use App\Modules\ProactiveReminder\Sources\Lark\P2pMessageSearcher;
use App\Modules\ProactiveReminder\Support\ActivityTimeParser;
use App\Modules\ProactiveReminder\Support\MessageCanonicalizer;
use App\Services\FeishuCliClient;

class ChatActivitySource implements ActivitySourceInterface
{
    public function __construct(
        private readonly FeishuCliClient $cliClient,
        private readonly ActivityTimeParser $timeParser,
        private readonly MessageCanonicalizer $canonicalizer,
        private readonly P2pMessageSearcher $p2pSearcher,
    ) {
    }

    public function supports(ReminderScanRequest $request): bool
    {
        return $request->collectionMode === 'full';
    }

    public function collect(ReminderScanRequest $request, array $feishuConfig): array
    {
        // ── 路径 A：v1 messages-search 走群聊（v1 不索引 P2P） ──
        // Use --page-all with max pages high enough to cover a full natural week.
        // 40 pages * 50 msgs/page = up to 2000 messages per scan (lark-cli hard caps page-size at 50).
        $raw = $this->cliClient->runSkillCommand($feishuConfig, '', [
            'im', '+messages-search',
            '--start', $request->since->toIso8601String(),
            '--end', $request->until->toIso8601String(),
            '--page-size', '50',
            '--page-all',
            '--page-limit', '40',
            '--exclude-sender-type', 'bot',
        ], 'user', $request->openId);

        $records = [];
        $items = [];
        $seenHashes = [];

        $messages = (array) ($raw['data']['messages'] ?? $raw['data']['items'] ?? []);
        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $built = $this->buildRecord($message, $request->openId, /*isP2pPath=*/false, '');
            if ($built === null) {
                continue;
            }
            [$record, $item] = $built;

            if (isset($seenHashes[$record['text_hash']])) {
                continue;
            }
            $seenHashes[$record['text_hash']] = true;

            $records[] = $record;
            $items[] = $item;
        }

        // ── 路径 B：v2 search/v2/message + chat_type=p2p_chat 补 P2P 私聊 ──
        // 飞书 v1 search 不索引 P2P，必须走 v2 endpoint 拿到 message_id 再 mget。
        // 设计原则：归档"用户视角"——只关心人和人的对话，过滤掉机器人卡片/答复。
        $p2pMessages = $this->p2pSearcher->search(
            $feishuConfig,
            $request->since,
            $request->until,
            $request->openId
        );

        // 第一轮扫描：聚合 peer_name + 标记 bot chat。
        // 优先用 P2pMessageSearcher 反查 chat-members 注入的 _peer_name（最准），
        // 兜底用对方发过的 sender.name（朱雀今天没回的话拿不到，靠 _peer_name）。
        $peerNameByChatId = [];
        $botChatIds = [];
        foreach ($p2pMessages as $message) {
            $chatId = trim((string) ($message['chat_id'] ?? ''));
            if ($chatId === '') {
                continue;
            }
            // 来自 P2pMessageSearcher 的 chat-members 反查结果
            if (! empty($message['_is_bot_chat'])) {
                $botChatIds[$chatId] = true;
                continue;
            }
            $injectedPeer = trim((string) ($message['_peer_name'] ?? ''));
            if ($injectedPeer !== '' && ! isset($peerNameByChatId[$chatId])) {
                $peerNameByChatId[$chatId] = $injectedPeer;
                continue;
            }
            // 兜底：对方发过言时 sender.name 即对方
            $sender = is_array($message['sender'] ?? null) ? $message['sender'] : [];
            $senderType = trim((string) ($sender['sender_type'] ?? ''));
            if ($senderType === 'app') {
                continue;
            }
            $senderOpenId = trim((string) ($sender['id'] ?? ''));
            if ($senderOpenId === '' || $senderOpenId === trim($request->openId)) {
                continue;
            }
            $name = trim((string) ($sender['name'] ?? ''));
            if ($name !== '' && ! isset($peerNameByChatId[$chatId])) {
                $peerNameByChatId[$chatId] = $name;
            }
        }

        // 第二轮：构造 record
        foreach ($p2pMessages as $message) {
            $chatId = trim((string) ($message['chat_id'] ?? ''));
            // 跳过用户↔机器人 P2P 整段（米蛙自己有 run/message 历史，不重复 ingest）
            if ($chatId !== '' && isset($botChatIds[$chatId])) {
                continue;
            }

            $sender = is_array($message['sender'] ?? null) ? $message['sender'] : [];
            $senderType = trim((string) ($sender['sender_type'] ?? ''));
            // 过滤机器人自己发的卡片/答复（归档用户视角，bot 行为另有 trace）
            if ($senderType === 'app') {
                continue;
            }

            $peer = $peerNameByChatId[$chatId] ?? '';
            $chatName = $peer !== ''
                ? '私聊·' . $peer
                : ($chatId !== '' ? '私聊（' . mb_substr($chatId, -8) . '）' : '私聊');

            $built = $this->buildRecord($message, $request->openId, /*isP2pPath=*/true, $chatName);
            if ($built === null) {
                continue;
            }
            [$record, $item] = $built;

            if (isset($seenHashes[$record['text_hash']])) {
                continue;
            }
            $seenHashes[$record['text_hash']] = true;

            $records[] = $record;
            $items[] = $item;
        }

        return [new SourceCollectionResult('messages', $records, $items)];
    }

    /**
     * 把 message dict 转成 (record, ActivityItem) 二元组，失败返回 null。
     *
     * @param  array<string,mixed>  $message
     * @return array{0:array<string,mixed>,1:ActivityItem}|null
     */
    private function buildRecord(array $message, string $selfOpenId, bool $isP2pPath, string $forcedChatName): ?array
    {
        $sender = is_array($message['sender'] ?? null) ? $message['sender'] : [];
        $senderName = trim((string) ($message['sender_name'] ?? $sender['name'] ?? $sender['id'] ?? ''));
        $senderOpenId = trim((string) ($sender['id'] ?? ''));

        if ($isP2pPath) {
            $chatName = $forcedChatName !== '' ? $forcedChatName : '私聊';
        } else {
            $chatType = trim((string) ($message['chat_type'] ?? ''));
            $chatName = trim((string) ($message['chat_name'] ?? ''));
            if ($chatName === '' && $chatType === 'p2p') {
                $chatName = '私聊';
            }
            if ($chatName === '') {
                $chatName = trim((string) ($message['chat_id'] ?? '未知会话'));
            }
        }

        $text = trim((string) ($message['content'] ?? ''));
        if ($text === '') {
            $text = $this->extractMessageText($message);
        }
        if ($text === '') {
            return null;
        }

        $direction = $senderOpenId === trim($selfOpenId) ? 'sent' : 'received';
        $textHash = $this->canonicalizer->hash($text);

        // preview = 拼好上下文的片段，给 ActivityArchiveAnalyzer 直接喂 LLM。
        // 不能只给 text——LLM 会丢失"谁在哪个 chat 说"这种关系信息。
        $previewText = mb_substr($text, 0, 200);
        $previewSender = $senderName !== '' ? $senderName : ($direction === 'sent' ? '我' : '对方');
        $preview = $direction === 'sent'
            ? "我在「{$chatName}」说：{$previewText}"
            : "{$previewSender} 在「{$chatName}」说：{$previewText}";

        $chatId = trim((string) ($message['chat_id'] ?? ''));

        $record = [
            'chat_id' => $chatId,
            'chat_name' => $chatName,
            'sender' => $senderName,
            'sender_open_id' => $senderOpenId,
            'direction' => $direction,
            'text' => $previewText,
            'preview' => mb_substr($preview, 0, 240),
            'time' => (string) ($message['create_time'] ?? ''),
            'msg_type' => trim((string) ($message['msg_type'] ?? 'text')),
            'text_hash' => $textHash,
        ];

        $item = new ActivityItem(
            'message',
            'feishu.im',
            $chatName,
            $record['text'],
            $this->timeParser->parse($record['time']),
            $senderName,
            $record
        );

        return [$record, $item];
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

        return mb_substr($body, 0, 200);
    }
}
