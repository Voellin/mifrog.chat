<?php

namespace App\Services\Feishu;

use App\Models\UserIdentity;
use App\Services\FeishuCliClient;
use App\Services\FeishuService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 用户级飞书文档正文抓取（lark-cli docs +fetch --doc）。
 *
 * 跟 FeishuResourceService 区别：
 *  - FeishuResourceService 走 bot 身份，下载 IM 消息附件
 *  - 这里走 user OAuth 身份，读用户飞书 Drive / Wiki 里的文档正文
 *
 * 设计：被 ActivityArchiveKernel 调用，把用户最近浏览/编辑过的飞书文档
 * 正文 fetch 下来，配合 AttachmentService::ingestRemoteDocument 落入用户知识库。
 *
 * 关键：项目把 user OAuth token 委托给 lark-cli keychain 自管，
 * user_identities.extra 里没有原始 token。所以这里通过 open_id 作 userKey
 * 让 lark-cli 自己从对应的 cli_home/keychain 拿 token。
 */
class FeishuDocFetcher
{
    public function __construct(
        private readonly FeishuCliClient $cliClient,
        private readonly FeishuService $feishuService,
    ) {
    }

    /**
     * fetch 一篇飞书文档（接受 wiki URL 或 doc token）。
     *
     * @return array{ok:bool, title?:string, doc_id?:string, markdown?:string, error?:string}
     */
    public function fetchDocument(int $userId, string $urlOrToken): array
    {
        $urlOrToken = trim($urlOrToken);
        if ($urlOrToken === '') {
            return ['ok' => false, 'error' => 'empty_url_or_token'];
        }

        $openId = $this->resolveOpenId($userId);
        if ($openId === null) {
            Log::warning('[FeishuDocFetcher] user open_id unavailable', [
                'user_id' => $userId,
            ]);
            return ['ok' => false, 'error' => 'no_open_id'];
        }

        $cfg = $this->feishuService->readConfig();
        if (empty($cfg['enabled'])) {
            return ['ok' => false, 'error' => 'feishu_disabled'];
        }

        try {
            // accessToken 留空，lark-cli 通过 userKey=open_id 解析到 scoped cli_home，
            // 从该 home 的 keychain 拿真 user_access_token。
            $r = $this->cliClient->runSkillCommand(
                $cfg,
                '',
                ['docs', '+fetch', '--doc', $urlOrToken],
                'user',
                $openId
            );
        } catch (Throwable $e) {
            Log::warning('[FeishuDocFetcher] cli exception', [
                'user_id' => $userId,
                'doc' => $urlOrToken,
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'cli_exception: ' . $e->getMessage()];
        }

        if (! ($r['ok'] ?? false)) {
            $err = is_array($r['error'] ?? null) ? json_encode($r['error'], JSON_UNESCAPED_UNICODE) : (string) ($r['error'] ?? 'unknown');
            Log::info('[FeishuDocFetcher] fetch returned non-ok', [
                'user_id' => $userId,
                'doc' => $urlOrToken,
                'error' => mb_substr($err, 0, 200),
            ]);
            return ['ok' => false, 'error' => $err];
        }

        $data = (array) ($r['data'] ?? []);
        $markdown = (string) ($data['markdown'] ?? '');
        $title = (string) ($data['title'] ?? '');
        $docId = (string) ($data['doc_id'] ?? '');

        if (trim($markdown) === '') {
            return ['ok' => false, 'error' => 'empty_markdown'];
        }

        return [
            'ok' => true,
            'title' => $title,
            'doc_id' => $docId,
            'markdown' => $markdown,
        ];
    }

    /**
     * 从 user_identities.extra 取 open_id（lark-cli 用这个找 scoped cli_home/keychain）。
     */
    private function resolveOpenId(int $userId): ?string
    {
        $identity = UserIdentity::query()
            ->where('user_id', $userId)
            ->where('provider', 'feishu')
            ->first();
        if (! $identity) {
            return null;
        }

        $extra = is_array($identity->extra) ? $identity->extra : [];
        $candidates = [
            $extra['open_id'] ?? null,
            $extra['user_token_open_id'] ?? null,
            $identity->provider_user_id ?? null,
        ];
        foreach ($candidates as $oid) {
            $oid = trim((string) ($oid ?? ''));
            if ($oid !== '') {
                return $oid;
            }
        }

        return null;
    }
}
