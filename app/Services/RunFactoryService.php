<?php

namespace App\Services;

use App\Jobs\ProcessRunJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Run;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class RunFactoryService
{
    private MemoryService $memoryService;
    private FeishuService $feishuService;
    private AttachmentService $attachmentService;
    private RunAccessTokenService $runAccessTokenService;

    public function __construct(
        MemoryService $memoryService,
        FeishuService $feishuService,
        AttachmentService $attachmentService,
        RunAccessTokenService $runAccessTokenService
    )
    {
        $this->memoryService = $memoryService;
        $this->feishuService = $feishuService;
        $this->attachmentService = $attachmentService;
        $this->runAccessTokenService = $runAccessTokenService;
    }

    public function createRun(User $user, string $content, array $context = []): Run
    {
        $channel = Arr::get($context, 'channel', 'feishu');
        $channelConversationId = Arr::get($context, 'channel_conversation_id');
        $feishuChatId = Arr::get($context, 'feishu_chat_id');
        $sourceMessageId = trim((string) Arr::get($context, 'source_message_id', ''));
        $attachments = (array) Arr::get($context, 'attachments', []);

        // 每天 cut 一次 conv：同一个 channel_conversation_id 在 conversations 表会有
        // 多行（每天一行）。查询取最新的那一条；如果它最后一条 message 不是今天，
        // 视为话题边界，新建 conv。
        $conversation = Conversation::query()
            ->where('user_id', $user->id)
            ->where('channel', $channel)
            ->when(
                $channelConversationId,
                fn ($query) => $query->where('channel_conversation_id', $channelConversationId)
            )
            ->latest('id')
            ->first();

        $shouldCut = false;
        if ($conversation) {
            $lastMessageAt = Message::query()
                ->where('conversation_id', $conversation->id)
                ->latest('id')
                ->value('created_at');
            if ($lastMessageAt !== null) {
                $todayStart = CarbonImmutable::now('Asia/Shanghai')->startOfDay();
                if ($lastMessageAt->copy()->setTimezone('Asia/Shanghai')->lt($todayStart)) {
                    $shouldCut = true;
                }
            }
        }

        if (! $conversation || $shouldCut) {
            $conversation = Conversation::query()->create([
                'user_id' => $user->id,
                'channel' => $channel,
                'channel_conversation_id' => $channelConversationId,
                'topic' => null,
            ]);
        }

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $content,
            'meta' => [
                'channel' => $channel,
                'source_message_id' => $sourceMessageId,
                'attachments' => $attachments,
            ],
        ]);

        $attachmentIds = $this->attachmentService->registerInboundAttachments(
            $user,
            $conversation,
            $message,
            $attachments,
            $context
        );
        if (! empty($attachmentIds)) {
            $meta = is_array($message->meta) ? $message->meta : [];
            $meta['attachment_ids'] = $attachmentIds;
            $message->meta = $meta;
            $message->save();
        }

        $intentMeta = null;
        if (Arr::get($context, 'oauth_resumed', false)) {
            $intentMeta = ['oauth_resumed' => true];
        }
        $intentMeta = is_array($intentMeta) ? $intentMeta : [];
        $intentMeta['trigger_message_id'] = (int) $message->id;

        $run = Run::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'status' => Run::STATUS_QUEUED,
            'model' => null,
            'intent_type' => null,
            'intent_confidence' => null,
            'intent_meta' => $intentMeta,
            'interaction_mode' => Run::INTERACTION_TEXT,
            'feishu_chat_id' => $feishuChatId,
        ]);

        $this->runAccessTokenService->issue((int) $run->id);

        $queueMessage = 'Request accepted and queued.';
        $this->emit($run->id, 'thinking', $queueMessage);
        $this->memoryService->appendRunEvent($run, 'thinking', $queueMessage, [
            'stage' => 'queued',
        ]);

        if ($channel === 'feishu' && $sourceMessageId !== '') {
            $this->feishuService->addThinkingReaction($run, $sourceMessageId);
        }

        ProcessRunJob::dispatch($run->id);

        return $run;
    }

    public function emit(int $runId, string $eventType, ?string $message = null, array $payload = []): void
    {
        \App\Models\RunEvent::query()->create([
            'run_id' => $runId,
            'event_type' => $eventType,
            'message' => $message,
            'payload' => $payload,
        ]);
    }
}
