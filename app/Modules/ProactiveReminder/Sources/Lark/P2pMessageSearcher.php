<?php

namespace App\Modules\ProactiveReminder\Sources\Lark;

use App\Services\FeishuCliClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 飞书 P2P 私聊归档专用：search/v2/message → messages-mget 两跳。
 *
 * 为什么单独抽出来：
 *  - lark-cli 的 +messages-search 走的是 /im/v1/messages/search（v1），
 *    飞书侧 v1 search 不索引 P2P 私聊，永远拿不到 1:1 对话。
 *  - v2 endpoint /search/v2/message 支持 chat_type=p2p_chat，但只返回 message_id 数组，
 *    需要二跳 +messages-mget 才能拿到 sender/content/chat_id。
 *  - 两跳 + 50/批的分批 mget 逻辑塞回 ChatActivitySource 太重，单独封装。
 *
 * 设计：
 *  - search 阶段走 callUserApi（POST /search/v2/message）通用 API 层
 *  - mget 阶段走 runSkillCommand（im +messages-mget）高级 skill 层
 *  - 翻页保护：page_size=50，最多 MAX_PAGES 页（避免无限循环）
 *  - 单批 mget 上限 50（飞书 mget 接口硬限）
 */
class P2pMessageSearcher
{
    /** 单次 search 翻页上限：50 * 20 = 最多 1000 条 P2P/用户/周期 */
    private const MAX_PAGES = 20;

    /** mget 单批上限（飞书 API 硬限） */
    private const MGET_BATCH = 50;

    public function __construct(
        private readonly FeishuCliClient $cliClient,
    ) {
    }

    /**
     * 搜并补齐 P2P 私聊消息正文。
     *
     * @param  array<string,mixed>  $feishuConfig  来自 FeishuService::readConfig()
     * @return array<int, array<string,mixed>>  每条 = ['message_id','content','sender','chat_id','create_time','msg_type']
     */
    public function search(
        array $feishuConfig,
        CarbonInterface $since,
        CarbonInterface $until,
        string $openId
    ): array {
        $startTs = (string) $since->getTimestamp();
        $endTs = (string) $until->getTimestamp();

        // ── 1) v2 search 收集 message_id（带翻页） ──
        $messageIds = [];
        $pageToken = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $body = [
                'query' => '',
                'chat_type' => 'p2p_chat',
                'start_time' => $startTs,
                'end_time' => $endTs,
            ];
            if ($pageToken !== null && $pageToken !== '') {
                $body['page_token'] = $pageToken;
            }

            try {
                $resp = $this->cliClient->callUserApi(
                    $feishuConfig,
                    '',
                    'POST',
                    '/open-apis/search/v2/message?page_size=50',
                    ['json' => $body],
                    $openId
                );
            } catch (Throwable $e) {
                Log::warning('[P2pMessageSearcher] v2 search exception', [
                    'open_id' => $openId,
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);
                break;
            }

            $code = (int) ($resp['code'] ?? -1);
            if ($code !== 0) {
                Log::info('[P2pMessageSearcher] v2 search non-zero code', [
                    'open_id' => $openId,
                    'page' => $page,
                    'code' => $code,
                    'msg' => mb_substr((string) ($resp['msg'] ?? ''), 0, 200),
                ]);
                break;
            }

            $data = (array) ($resp['data'] ?? []);
            $items = (array) ($data['items'] ?? []);
            foreach ($items as $id) {
                $id = trim((string) $id);
                if ($id !== '') {
                    $messageIds[] = $id;
                }
            }

            if (! ($data['has_more'] ?? false)) {
                break;
            }

            $pageToken = trim((string) ($data['page_token'] ?? ''));
            if ($pageToken === '') {
                break;
            }
        }

        if ($messageIds === []) {
            return [];
        }

        // 去重（理论上 v2 不会重，但保险）
        $messageIds = array_values(array_unique($messageIds));

        // ── 2) 分批 mget 拉正文 ──
        $messages = [];
        foreach (array_chunk($messageIds, self::MGET_BATCH) as $batch) {
            try {
                $r = $this->cliClient->runSkillCommand(
                    $feishuConfig,
                    '',
                    [
                        'im', '+messages-mget',
                        '--message-ids', implode(',', $batch),
                    ],
                    'user',
                    $openId
                );
            } catch (Throwable $e) {
                Log::warning('[P2pMessageSearcher] mget exception', [
                    'open_id' => $openId,
                    'batch_size' => count($batch),
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (! ($r['ok'] ?? false)) {
                Log::info('[P2pMessageSearcher] mget non-ok', [
                    'open_id' => $openId,
                    'batch_size' => count($batch),
                    'error' => is_array($r['error'] ?? null)
                        ? json_encode($r['error'], JSON_UNESCAPED_UNICODE)
                        : (string) ($r['error'] ?? 'unknown'),
                ]);
                continue;
            }

            $batchMessages = (array) ($r['data']['messages'] ?? []);
            foreach ($batchMessages as $m) {
                if (is_array($m)) {
                    $messages[] = $m;
                }
            }
        }

        // mget 不返 chat_id，单独打 /im/v1/messages/{id} GET 补回（每条 1 次 API）。
        // 后续 ChatActivitySource 用 chat_id 给 transcript 分组、给 file_key 拼日期。
        foreach ($messages as &$m) {
            $mid = trim((string) ($m['message_id'] ?? ''));
            if ($mid === '' || ! empty($m['chat_id'])) {
                continue;
            }
            try {
                $detail = $this->cliClient->callUserApi(
                    $feishuConfig,
                    '',
                    'GET',
                    '/open-apis/im/v1/messages/' . $mid,
                    [],
                    $openId
                );
                $items = (array) ($detail['data']['items'] ?? []);
                $first = is_array($items[0] ?? null) ? $items[0] : [];
                $cid = trim((string) ($first['chat_id'] ?? ''));
                if ($cid !== '') {
                    $m['chat_id'] = $cid;
                }
            } catch (Throwable $e) {
                Log::info('[P2pMessageSearcher] chat_id_lookup_failed', [
                    'open_id' => $openId,
                    'message_id' => $mid,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        unset($m);

        // 按 chat_id 反查 chat-members：
        //  - 只返回自己 → 用户↔机器人 P2P，整个 chat 在调用方丢弃（_is_bot_chat=true）
        //  - 返回多人 → 取非自己的那位 name 作为 peer_name，让 chat_name 推断为"私聊·朱雀"
        // 缓存 24h（peer name 不会频繁变）。
        $chatIds = [];
        foreach ($messages as $m) {
            $cid = trim((string) ($m['chat_id'] ?? ''));
            if ($cid !== '' && ! in_array($cid, $chatIds, true)) {
                $chatIds[] = $cid;
            }
        }

        $chatMeta = [];  // chat_id => ['peer_name' => string, 'is_bot' => bool]
        foreach ($chatIds as $cid) {
            $cacheKey = 'p2p_chat_peer:' . $cid . ':' . $openId;
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $chatMeta[$cid] = $cached;
                continue;
            }

            $peerName = '';
            $isBot = false;
            try {
                $resp = $this->cliClient->callUserApi(
                    $feishuConfig,
                    '',
                    'GET',
                    '/open-apis/im/v1/chats/' . $cid . '/members',
                    [],
                    $openId
                );
                if ((int) ($resp['code'] ?? -1) === 0) {
                    $items = (array) ($resp['data']['items'] ?? []);
                    $others = [];
                    foreach ($items as $it) {
                        $mid = trim((string) ($it['member_id'] ?? ''));
                        if ($mid !== '' && $mid !== $openId) {
                            $others[] = trim((string) ($it['name'] ?? ''));
                        }
                    }
                    if (count($others) === 0) {
                        // chat-members 只列出了自己 → 用户↔机器人 P2P
                        $isBot = true;
                    } else {
                        $peerName = $others[0];
                    }
                }
            } catch (Throwable $e) {
                Log::info('[P2pMessageSearcher] chat_members_lookup_failed', [
                    'open_id' => $openId,
                    'chat_id' => $cid,
                    'error' => $e->getMessage(),
                ]);
            }

            $meta = ['peer_name' => $peerName, 'is_bot' => $isBot];
            $chatMeta[$cid] = $meta;
            Cache::put($cacheKey, $meta, 86400);
        }

        // 注入 _peer_name / _is_bot_chat 给下游消费
        foreach ($messages as &$m) {
            $cid = trim((string) ($m['chat_id'] ?? ''));
            if ($cid !== '' && isset($chatMeta[$cid])) {
                $m['_peer_name'] = $chatMeta[$cid]['peer_name'];
                $m['_is_bot_chat'] = $chatMeta[$cid]['is_bot'];
            }
        }
        unset($m);

        return $messages;
    }
}
