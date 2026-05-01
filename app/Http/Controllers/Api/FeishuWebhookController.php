<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\FeishuService;
use App\Services\QuickReplyService;
use App\Services\RunFactoryService;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FeishuWebhookController extends Controller
{
    private RunFactoryService $runFactoryService;
    private FeishuService $feishuService;

    public function __construct(RunFactoryService $runFactoryService, FeishuService $feishuService)
    {
        $this->runFactoryService = $runFactoryService;
        $this->feishuService = $feishuService;
    }

    public function handle(Request $request)
    {
        $rawContent = (string) $request->getContent();
        $payload = $this->resolvePayload($request->all(), $rawContent);

        Log::info('feishu.webhook.inbound', [
            'path' => $request->path(),
            'ip' => $request->ip(),
            'payload_keys' => array_keys($payload),
            'has_challenge' => array_key_exists('challenge', $payload),
            'has_encrypt' => array_key_exists('encrypt', $payload),
            'event_type' => $payload['header']['event_type'] ?? null,
            'raw_size' => strlen($rawContent),
        ]);

        if (isset($payload['_decrypt_error'])) {
            return response()->json([
                'ok' => false,
                'error' => $payload['_decrypt_error'],
            ], 400);
        }

        if (isset($payload['challenge'])) {
            return response()->json(['challenge' => $payload['challenge']]);
        }

        if (! $this->verifyRequestToken($payload)) {
            Log::warning('feishu.webhook.verify_token_failed', [
                'event_type' => $payload['header']['event_type'] ?? null,
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'invalid_verification_token',
            ], 401);
        }

        // -- Idempotency guard --
        // Feishu retries the same event up to ~3 minutes on non-2xx or timeout.
        // We dedupe on event_id with a 10-minute Cache::add lock (atomic for
        // file/redis/memcached drivers). Duplicates return 200 immediately so
        // Feishu stops retrying and we don't create duplicate Runs.
        $eventId = (string) (
            Arr::get($payload, 'header.event_id')
            ?: Arr::get($payload, 'event.event_id')
            ?: Arr::get($payload, 'event_id', '')
        );
        if ($eventId !== '') {
            if (! Cache::add('feishu:event:'.$eventId, 1, 600)) {
                Log::info('feishu.webhook.duplicate_event_skipped', [
                    'event_id' => $eventId,
                    'event_type' => $payload['header']['event_type'] ?? null,
                ]);
                return response()->json(['ok' => true, 'duplicate' => true]);
            }
        }

        $event = $payload['event'] ?? [];
        $senderType = strtolower((string) ($event['sender']['sender_type'] ?? ''));
        if ($senderType === 'app' || $senderType === 'bot') {
            return response()->json(['ok' => true]);
        }

        $openId = $event['sender']['sender_id']['open_id'] ?? null;
        $sender = $openId
            ?? $event['sender']['sender_id']['user_id']
            ?? $event['sender']['sender_id']['union_id']
            ?? null;
        $chatId = $event['message']['chat_id'] ?? null;
        $messageId = $event['message']['message_id'] ?? null;
        $messageType = strtolower((string) ($event['message']['message_type'] ?? ''));
        $contentRaw = $event['message']['content'] ?? null;
        $attachments = $this->extractInboundAttachments($messageType, is_string($contentRaw) ? $contentRaw : '');

        if (! $sender || ! is_string($contentRaw) || trim($contentRaw) === '') {
            return response()->json(['ok' => true]);
        }

        $text = $this->extractUserText($contentRaw, $messageType);
        if (trim($text) === '' && empty($attachments)) {
            return response()->json(['ok' => true]);
        }
        if (trim($text) === '' && ! empty($attachments)) {
            $text = '请读取我刚上传的文件，并先给出摘要，再等待我的追问。';
        }

        $senderName = $this->extractSenderName($event);
        if ($senderName === '' && is_string($openId) && trim($openId) !== '') {
            $senderName = (string) ($this->feishuService->resolveUserNameByOpenId($openId) ?? '');
        }

        $identity = UserIdentity::query()
            ->where('provider', 'feishu')
            ->where('provider_user_id', $sender)
            ->first();

        $user = $identity?->user;
        if (! $user) {
            $user = User::query()->create([
                'name' => $senderName !== '' ? $senderName : '飞书用户'.substr($sender, -6),
                'email' => 'feishu_'.substr(md5($sender), 0, 12).'@mifrog.local',
                'password' => bcrypt(bin2hex(random_bytes(16))),
                'feishu_open_id' => $openId ?: $sender,
                'is_active' => true,
            ]);
            $identity = UserIdentity::query()->create([
                'user_id' => $user->id,
                'provider' => 'feishu',
                'provider_user_id' => $sender,
                'extra' => $senderName !== '' ? ['name' => $senderName] : [],
            ]);
        } else {
            if ($senderName !== '' && $this->isPlaceholderName((string) $user->name)) {
                $user->name = $senderName;
                $user->save();
            }

            if ($identity) {
                $extra = is_array($identity->extra) ? $identity->extra : [];
                if ($senderName !== '' && trim((string) ($extra['name'] ?? '')) === '') {
                    $extra['name'] = $senderName;
                    $identity->extra = $extra;
                    $identity->save();
                }
            }
        }

        // ── Quick Reply: social/greeting messages bypass Run pipeline ──
        $quickReply = app(QuickReplyService::class)->attempt($text);
        if ($quickReply !== null) {
            $conversation = Conversation::query()
                ->where('user_id', $user->id)
                ->where('channel', 'feishu')
                ->where('channel_conversation_id', $chatId)
                ->first();
            if (! $conversation) {
                $conversation = Conversation::query()->create([
                    'user_id' => $user->id,
                    'channel' => 'feishu',
                    'channel_conversation_id' => $chatId,
                ]);
            }
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'role' => 'user',
                'content' => $text,
                'meta' => ['channel' => 'feishu', 'source_message_id' => $messageId],
            ]);
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $quickReply,
                'meta' => ['source' => 'quick_reply'],
            ]);
            $this->feishuService->pushTextToChat($chatId, $quickReply);

            Log::info('feishu.webhook.quick_reply', [
                'user_id' => $user->id,
                'text' => $text,
                'reply' => $quickReply,
            ]);

            return response()->json(['ok' => true, 'quick_reply' => true]);
        }

        // ── Normal Run pipeline ──
        $run = $this->runFactoryService->createRun($user, $text, [
            'channel' => 'feishu',
            'channel_conversation_id' => $chatId,
            'feishu_chat_id' => $chatId,
            'source_message_id' => $messageId,
            'attachments' => $attachments,
        ]);

        return response()->json([
            'ok' => true,
            'run_id' => $run->id,
        ]);
    }

    private function resolvePayload(array $payload, string $rawContent): array
    {
        if (! array_key_exists('encrypt', $payload)) {
            return $payload;
        }

        $config = Setting::read('feishu', []);
        $encryptKey = trim((string) Arr::get($config, 'encrypt_key', env('FEISHU_ENCRYPT_KEY', '')));
        if ($encryptKey === '') {
            Log::warning('feishu.webhook.decrypt.failed', [
                'reason' => 'encrypt_key_missing',
                'raw_prefix' => substr($rawContent, 0, 180),
            ]);

            return ['_decrypt_error' => 'encrypt_key_missing'];
        }

        $decryptedJson = $this->decryptFeishuEvent((string) $payload['encrypt'], $encryptKey);
        if ($decryptedJson === null) {
            Log::warning('feishu.webhook.decrypt.failed', [
                'reason' => 'decrypt_failed',
            ]);

            return ['_decrypt_error' => 'decrypt_failed'];
        }

        $decoded = json_decode($decryptedJson, true);
        if (! is_array($decoded)) {
            Log::warning('feishu.webhook.decrypt.failed', [
                'reason' => 'decrypted_json_invalid',
                'decrypted_prefix' => substr($decryptedJson, 0, 180),
            ]);

            return ['_decrypt_error' => 'decrypted_json_invalid'];
        }

        return $decoded;
    }

    private function decryptFeishuEvent(string $encrypted, string $encryptKey): ?string
    {
        $cipher = base64_decode($encrypted, true);
        if ($cipher === false || $cipher === '') {
            return null;
        }

        $embeddedIvResult = $this->decryptWithEmbeddedIv($cipher, $encryptKey);
        if ($embeddedIvResult !== null) {
            return $embeddedIvResult;
        }

        foreach ($this->buildAesKeyCandidates($encryptKey) as $aesKey) {
            $iv = substr($aesKey, 0, 16);
            $plain = openssl_decrypt($cipher, 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
            if (! is_string($plain) || $plain === '') {
                continue;
            }

            $plain = $this->pkcs7Unpad($plain);
            if ($plain === null || $plain === '') {
                continue;
            }

            $json = $this->extractJsonBody($plain);
            if ($json !== null) {
                return $json;
            }
        }

        return null;
    }

    private function decryptWithEmbeddedIv(string $cipher, string $encryptKey): ?string
    {
        if (strlen($cipher) <= 16) {
            return null;
        }

        $iv = substr($cipher, 0, 16);
        $encryptedBody = substr($cipher, 16);

        $keys = [
            hash('sha256', trim($encryptKey), true),
        ];

        foreach ($this->buildAesKeyCandidates($encryptKey) as $candidate) {
            $keys[] = $candidate;
            $keys[] = hash('sha256', $candidate, true);
        }

        $unique = [];
        foreach ($keys as $key) {
            if (! is_string($key) || strlen($key) !== 32) {
                continue;
            }
            $unique[md5($key)] = $key;
        }

        foreach (array_values($unique) as $key) {
            $plain = openssl_decrypt($encryptedBody, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            if (! is_string($plain) || $plain === '') {
                continue;
            }

            $json = $this->extractJsonBody($plain);
            if ($json !== null) {
                return $json;
            }
        }

        return null;
    }

    private function buildAesKeyCandidates(string $encryptKey): array
    {
        $raw = trim($encryptKey);
        $candidates = [];

        if (strlen($raw) === 32) {
            $candidates[] = $raw;
        }

        $decodedDirect = base64_decode($raw, true);
        if ($decodedDirect !== false && strlen($decodedDirect) === 32) {
            $candidates[] = $decodedDirect;
        }

        $decodedWithPadding = base64_decode($raw.'=', true);
        if ($decodedWithPadding !== false && strlen($decodedWithPadding) === 32) {
            $candidates[] = $decodedWithPadding;
        }

        $unique = [];
        foreach ($candidates as $candidate) {
            $unique[md5($candidate)] = $candidate;
        }

        return array_values($unique);
    }

    private function extractJsonBody(string $plain): ?string
    {
        $trimmed = ltrim($plain);
        if ($trimmed !== '' && str_starts_with($trimmed, '{') && $this->isJsonObject($trimmed)) {
            return $trimmed;
        }

        if (strlen($plain) < 20) {
            return null;
        }

        $msgLenArr = unpack('N', substr($plain, 16, 4));
        $jsonLength = (int) ($msgLenArr[1] ?? 0);
        if ($jsonLength <= 0 || (20 + $jsonLength) > strlen($plain)) {
            return null;
        }

        $candidate = substr($plain, 20, $jsonLength);
        if (! $this->isJsonObject($candidate)) {
            return null;
        }

        return $candidate;
    }

    private function isJsonObject(string $text): bool
    {
        $decoded = json_decode($text, true);

        return is_array($decoded);
    }

    private function pkcs7Unpad(string $text): ?string
    {
        $length = strlen($text);
        if ($length === 0) {
            return null;
        }

        $pad = ord($text[$length - 1]);
        if ($pad < 1 || $pad > 32 || $pad > $length) {
            return null;
        }

        $padding = substr($text, -$pad);
        if ($padding !== str_repeat(chr($pad), $pad)) {
            return null;
        }

        return substr($text, 0, $length - $pad);
    }

    private function extractUserText(string $contentRaw, string $messageType): string
    {
        $content = json_decode($contentRaw, true);
        if (! is_array($content)) {
            return trim($contentRaw);
        }

        $text = trim((string) ($content['text'] ?? ''));
        if ($text !== '') {
            return $text;
        }

        if ($messageType !== 'post') {
            return '';
        }

        $langNode = null;
        foreach (['zh_cn', 'en_us', 'ja_jp'] as $lang) {
            if (isset($content[$lang]) && is_array($content[$lang])) {
                $langNode = $content[$lang];
                break;
            }
        }
        if (! is_array($langNode)) {
            $first = reset($content);
            if (is_array($first)) {
                $langNode = $first;
            }
        }
        if (! is_array($langNode)) {
            return '';
        }

        $chunks = [];
        $this->collectTextChunks($langNode, $chunks);

        return trim(implode('', $chunks));
    }

    private function collectTextChunks(mixed $node, array &$chunks): void
    {
        if (is_string($node)) {
            return;
        }

        if (! is_array($node)) {
            return;
        }

        if (($node['tag'] ?? null) === 'text' && isset($node['text']) && is_string($node['text'])) {
            $chunks[] = $node['text'];
        }

        foreach ($node as $value) {
            $this->collectTextChunks($value, $chunks);
        }
    }

    private function extractInboundAttachments(string $messageType, string $contentRaw): array
    {
        $content = json_decode($contentRaw, true);
        if (! is_array($content)) {
            return [];
        }

        $list = [];
        $push = function (array $item) use (&$list): void {
            $fileKey = trim((string) ($item['file_key'] ?? ''));
            if ($fileKey === '') {
                return;
            }
            $list[] = $item;
        };

        if ($messageType === 'file') {
            $push([
                'type' => 'file',
                'message_type' => $messageType,
                'file_key' => (string) ($content['file_key'] ?? ''),
                'file_name' => (string) ($content['file_name'] ?? ''),
                'file_ext' => strtolower(pathinfo((string) ($content['file_name'] ?? ''), PATHINFO_EXTENSION)),
                'mime_type' => (string) ($content['mime_type'] ?? ''),
                'file_size' => $content['file_size'] ?? null,
                'source_content' => $content,
            ]);
        }

        if ($messageType === 'image') {
            $imageKey = (string) ($content['image_key'] ?? '');
            $push([
                'type' => 'image',
                'message_type' => $messageType,
                'file_key' => $imageKey,
                'file_name' => (string) ($content['file_name'] ?? ('image_'.$imageKey.'.png')),
                'file_ext' => 'png',
                'mime_type' => 'image/png',
                'file_size' => $content['file_size'] ?? null,
                'source_content' => $content,
            ]);
        }

        if (in_array($messageType, ['audio', 'media', 'video'], true)) {
            $push([
                'type' => $messageType === 'audio' ? 'audio' : 'video',
                'message_type' => $messageType,
                'file_key' => (string) ($content['file_key'] ?? $content['media_key'] ?? ''),
                'file_name' => (string) ($content['file_name'] ?? ''),
                'file_ext' => strtolower(pathinfo((string) ($content['file_name'] ?? ''), PATHINFO_EXTENSION)),
                'mime_type' => (string) ($content['mime_type'] ?? ''),
                'file_size' => $content['file_size'] ?? null,
                'source_content' => $content,
            ]);
        }

        // post（飞书富文本）：图/文混排消息。遍历所有语言节点，递归找 tag=img/media/file 元素
        if ($messageType === 'post') {
            $this->collectPostAttachments($content, $push);
        }

        return $list;
    }



    /**
     * 递归遍历飞书 post 富文本节点，把内嵌的 img / media / file element 提成 attachment。
     * 飞书 post 节点形如：
     *   { "zh_cn": { "title": "...", "content": [[ {"tag":"text",...}, {"tag":"img","image_key":"img_xxx"}, ... ]] } }
     */
    private function collectPostAttachments(mixed $node, callable $push): void
    {
        if (! is_array($node)) {
            return;
        }

        $tag = $node['tag'] ?? null;

        if ($tag === 'img' && isset($node['image_key']) && is_string($node['image_key']) && trim($node['image_key']) !== '') {
            $imageKey = trim($node['image_key']);
            $push([
                'type' => 'image',
                'message_type' => 'post',
                'file_key' => $imageKey,
                'file_name' => 'image_'.$imageKey.'.png',
                'file_ext' => 'png',
                'mime_type' => 'image/png',
                'file_size' => null,
                'source_content' => $node,
            ]);
        }

        if ($tag === 'media' && isset($node['file_key']) && is_string($node['file_key']) && trim($node['file_key']) !== '') {
            $fileKey = trim($node['file_key']);
            $push([
                'type' => 'video',
                'message_type' => 'post',
                'file_key' => $fileKey,
                'file_name' => (string) ($node['file_name'] ?? 'media_'.$fileKey),
                'file_ext' => strtolower(pathinfo((string) ($node['file_name'] ?? ''), PATHINFO_EXTENSION)),
                'mime_type' => (string) ($node['mime_type'] ?? ''),
                'file_size' => $node['file_size'] ?? null,
                'source_content' => $node,
            ]);
        }

        if ($tag === 'file' && isset($node['file_key']) && is_string($node['file_key']) && trim($node['file_key']) !== '') {
            $fileKey = trim($node['file_key']);
            $push([
                'type' => 'file',
                'message_type' => 'post',
                'file_key' => $fileKey,
                'file_name' => (string) ($node['file_name'] ?? $fileKey),
                'file_ext' => strtolower(pathinfo((string) ($node['file_name'] ?? ''), PATHINFO_EXTENSION)),
                'mime_type' => (string) ($node['mime_type'] ?? ''),
                'file_size' => $node['file_size'] ?? null,
                'source_content' => $node,
            ]);
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectPostAttachments($value, $push);
            }
        }
    }

        private function extractSenderName(array $event): string
    {
        $candidates = [
            Arr::get($event, 'sender.sender_name'),
            Arr::get($event, 'sender.name'),
            Arr::get($event, 'sender.sender_display_name'),
            Arr::get($event, 'sender.sender_id.name'),
            Arr::get($event, 'sender.sender_id.user_name'),
        ];

        foreach ($candidates as $candidate) {
            $name = trim((string) $candidate);
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    private function isPlaceholderName(string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return true;
        }

        return str_starts_with($name, 'feishu_')
            || str_starts_with($name, '飞书用户');
    }

    private function verifyRequestToken(array $payload): bool
    {
        $config = Setting::read('feishu', []);
        $expected = trim((string) Arr::get($config, 'verification_token', env('FEISHU_VERIFICATION_TOKEN', '')));
        if ($expected === '') {
            // Fail-closed: if no verification_token is configured, we cannot
            // authenticate callers, so reject all requests. Configure it in
            // /admin/settings (channel tab) before accepting webhook traffic.
            Log::warning('feishu.webhook.verification_token_not_configured');
            return false;
        }

        $actual = trim((string) (
            Arr::get($payload, 'header.token')
            ?: Arr::get($payload, 'token')
            ?: Arr::get($payload, 'event.token')
            ?: ''
        ));

        if ($actual === '') {
            return false;
        }

        return hash_equals($expected, $actual);
    }
}

