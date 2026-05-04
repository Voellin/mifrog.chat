<?php

namespace App\Services\Feishu;

use App\Models\Run;
use App\Support\FeishuScopeCatalog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push domain service extracted from FeishuService.
 *
 * Covers:
 * - Pushing plain-text messages to chat_id / open_id (tenant-token based)
 * - Authorization card push + dismiss flow (with 30-min de-dup cache)
 *
 * Behavior contract (preserved verbatim from FeishuService):
 * - pushAuthorizationCard short-circuits if a card was already sent for the
 *   same run_id within 30 minutes.
 * - dismissAuthorizationCard prefers deleting the temp auth card; if delete
 *   fails, falls back to in-place status update.
 * - Card body markdown is HTML-escaped (& < >) before being inserted.
 */
class FeishuPushService
{
    private const AUTH_CARD_SENT_CACHE_PREFIX = 'feishu_run_auth_card_sent_';
    private const AUTH_CARD_MSGID_USER_CACHE_PREFIX = 'feishu_auth_card_msgid_user_';

    public function __construct(
        private readonly FeishuTransport $transport,
        private readonly FeishuScopeCatalog $scopeCatalog,
    ) {
    }

    public function pushTextToChat(string $chatId, string $text): bool
    {
        $chatId = trim($chatId);
        $text = trim($text);
        if ($chatId === '' || $text === '') {
            return false;
        }

        $config = $this->transport->readConfig();
        if (! $config['enabled']) {
            return false;
        }

        $token = $this->transport->tenantToken($config['app_id'], $config['app_secret']);
        if (! $token) {
            return false;
        }

        return $this->transport->sendTextMessage($token, $chatId, $text);
    }

    public function pushTextToOpenId(string $openId, string $text): bool
    {
        $openId = trim($openId);
        $text = trim($text);
        if ($openId === '' || $text === '') {
            return false;
        }

        $config = $this->transport->readConfig();
        if (! $config['enabled']) {
            return false;
        }

        $token = $this->transport->tenantToken($config['app_id'], $config['app_secret']);
        if (! $token) {
            return false;
        }

        try {
            $body = $this->transport->requestJson('post', 'im/v1/messages?receive_id_type=open_id', [
                'headers' => $this->transport->authHeaders($token),
                'json' => [
                    'receive_id' => $openId,
                    'msg_type' => 'text',
                    'content' => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
                ],
            ]);
        } catch (Throwable $e) {
            Log::warning('feishu.send_open_id_text.exception', [
                'open_id' => $openId,
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        if ((int) Arr::get($body, 'code', -1) !== 0) {
            Log::warning('feishu.send_open_id_text.failed', [
                'open_id' => $openId,
                'response' => $body,
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param  string[]  $scopes
     */
    /**
     * 向 chat 发送 markdown 富文本（用 schema 2.0 interactive card + markdown 元素实现）。
     * 用于数字分身回复等需要加粗/列表/链接/分隔线的场景。
     * 失败返回 false——上层可 fall back 到 pushTextToChat。
     */
    public function pushInteractiveMarkdownToChat(string $chatId, string $markdown): bool
    {
        $chatId = trim($chatId);
        $markdown = trim($markdown);
        if ($chatId === '' || $markdown === '') {
            return false;
        }

        $config = $this->transport->readConfig();
        if (! $config['enabled']) {
            return false;
        }

        $token = $this->transport->tenantToken($config['app_id'], $config['app_secret']);
        if (! $token) {
            return false;
        }

        $card = [
            'schema' => '2.0',
            'config' => [
                'update_multi' => true,
            ],
            'body' => [
                'direction' => 'vertical',
                'elements' => [
                    [
                        'tag' => 'markdown',
                        'content' => $markdown,
                    ],
                ],
            ],
        ];

        $messageId = $this->transport->sendInteractiveCard($token, $chatId, $card);
        return $messageId !== null;
    }

    public function pushAuthorizationCard(Run $run, string $oauthUrl, array $scopes = []): bool
    {
        $oauthUrl = trim($oauthUrl);
        if (! $run->feishu_chat_id || $oauthUrl === '') {
            return false;
        }

        $cacheKey = self::AUTH_CARD_SENT_CACHE_PREFIX.(int) $run->id;
        if ((bool) Cache::get($cacheKey, false)) {
            return true;
        }

        $config = $this->transport->readConfig();
        if (! $config['enabled']) {
            return false;
        }

        $token = $this->transport->tenantToken($config['app_id'], $config['app_secret']);
        if (! $token) {
            return false;
        }

        $card = $this->buildAuthorizationCard($oauthUrl, $scopes);
        $messageId = $this->transport->sendInteractiveCard($token, (string) $run->feishu_chat_id, $card);
        if (! $messageId) {
            return false;
        }

        Cache::put(self::AUTH_CARD_MSGID_USER_CACHE_PREFIX.$run->user_id, $messageId, now()->addMinutes(30));
        Cache::put($cacheKey, true, now()->addMinutes(30));

        return true;
    }

    public function dismissAuthorizationCard(string $messageId, bool $authorized, ?string $detail = null): void
    {
        $messageId = trim($messageId);
        if ($messageId === '') {
            return;
        }

        // Prefer deleting the temp auth card entirely; fallback to in-place status update.
        if ($this->transport->deleteMessage($messageId)) {
            return;
        }

        $this->updateAuthorizationCardResult($messageId, $authorized, $detail);
    }

    private function updateAuthorizationCardResult(string $messageId, bool $authorized, ?string $detail = null): bool
    {
        $messageId = trim($messageId);
        if ($messageId === '') {
            return false;
        }

        $config = $this->transport->readConfig();
        if (! $config['enabled']) {
            return false;
        }

        $token = $this->transport->tenantToken($config['app_id'], $config['app_secret']);
        if (! $token) {
            return false;
        }

        $card = $this->buildAuthorizationResultCard($authorized, $detail);

        return $this->transport->updateInteractiveCard($token, $messageId, $card);
    }

    /**
     * @param  string[]  $scopes
     * @return array<string,mixed>
     */
    private function buildAuthorizationCard(string $oauthUrl, array $scopes): array
    {
        $normalizedScopes = $this->scopeCatalog->normalizeScopes($scopes);
        if ($normalizedScopes === []) {
            $normalizedScopes = ['offline_access'];
        }

        $scopeLines = array_map(
            fn (string $item) => '- '.$this->escapeMarkdown($item),
            $normalizedScopes
        );
        $scopeMarkdown = implode("\n", $scopeLines);

        return [
            'schema' => '2.0',
            'config' => [
                'update_multi' => true,
            ],
            'body' => [
                'direction' => 'vertical',
                'elements' => [
                    [
                        'tag' => 'markdown',
                        'content' => "授权后，应用将能够以您的身份执行相关操作。\n\n**所需权限：**\n".$scopeMarkdown,
                        'margin' => '0px 0px 0px 0px',
                        'element_id' => 'auth_scope',
                    ],
                    [
                        'tag' => 'column_set',
                        'flex_mode' => 'stretch',
                        'horizontal_spacing' => '8px',
                        'horizontal_align' => 'right',
                        'columns' => [
                            [
                                'tag' => 'column',
                                'width' => 'auto',
                                'elements' => [
                                    [
                                        'tag' => 'button',
                                        'text' => [
                                            'tag' => 'plain_text',
                                            'content' => '前往授权',
                                        ],
                                        'type' => 'primary_filled',
                                        'width' => 'fill',
                                        'behaviors' => [
                                            [
                                                'type' => 'open_url',
                                                'default_url' => $oauthUrl,
                                                'pc_url' => '',
                                                'ios_url' => '',
                                                'android_url' => '',
                                            ],
                                        ],
                                        'margin' => '4px 0px 4px 0px',
                                        'element_id' => 'auth_button',
                                    ],
                                ],
                                'vertical_spacing' => '8px',
                                'horizontal_align' => 'left',
                                'vertical_align' => 'top',
                            ],
                        ],
                        'margin' => '0px 0px 0px 0px',
                    ],
                    [
                        'tag' => 'markdown',
                        'content' => "授权链接将在 3 分钟后失效，届时需重新发起。\n\n如果你希望一次性授予我所有权限，可以告诉我“授予所有权限”，我会协助你完成。",
                        'text_align' => 'left',
                        'text_size' => 'notation',
                        'margin' => '0px 0px 0px 0px',
                        'element_id' => 'auth_tip',
                    ],
                ],
            ],
            'header' => [
                'title' => [
                    'tag' => 'plain_text',
                    'content' => '需要您的授权才能继续',
                ],
                'subtitle' => [
                    'tag' => 'plain_text',
                    'content' => '',
                ],
                'template' => 'blue',
                'padding' => '12px 8px 12px 8px',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildAuthorizationResultCard(bool $authorized, ?string $detail = null): array
    {
        $title = $authorized ? '授权已完成' : '授权未完成';
        $subtitle = $authorized ? '后续任务将自动继续' : '请重新发起授权后继续';
        $template = $authorized ? 'green' : 'red';
        $defaultLine = $authorized
            ? '授权成功，任务会回到原任务卡片继续执行。'
            : '授权失败或已过期，请重新授权后再试。';
        $line = trim((string) $detail);
        if ($line === '') {
            $line = $defaultLine;
        }

        return [
            'schema' => '2.0',
            'config' => [
                'update_multi' => true,
            ],
            'body' => [
                'direction' => 'vertical',
                'elements' => [
                    [
                        'tag' => 'markdown',
                        'content' => $this->escapeMarkdown($line),
                        'text_align' => 'left',
                        'margin' => '0px 0px 0px 0px',
                    ],
                ],
            ],
            'header' => [
                'title' => [
                    'tag' => 'plain_text',
                    'content' => $title,
                ],
                'subtitle' => [
                    'tag' => 'plain_text',
                    'content' => $subtitle,
                ],
                'template' => $template,
                'padding' => '12px 8px 12px 8px',
            ],
        ];
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
