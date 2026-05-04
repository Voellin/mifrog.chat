<?php

namespace App\Modules\ProactiveReminder\Channels;

use App\Models\Conversation;
use App\Models\User;
use App\Modules\ProactiveReminder\Contracts\ReminderChannelInterface;
use App\Modules\ProactiveReminder\DTO\DispatchResult;
use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use App\Services\FeishuService;

class FeishuReminderChannel implements ReminderChannelInterface
{
    public function __construct(
        private readonly FeishuService $feishuService,
    ) {
    }

    public function send(ReminderScanRequest $request, string $message): DispatchResult
    {
        $user = User::query()->find($request->userId);
        if (! $user) {
            return DispatchResult::failed('user_not_found');
        }

        $chatId = $this->resolveBotChatId($request->userId);
        if ($chatId !== '' && $this->feishuService->pushTextToChat($chatId, $message)) {
            return DispatchResult::sent('chat', $chatId);
        }

        $openId = trim($request->openId ?: (string) ($user->feishu_open_id ?? ''));
        if ($openId !== '' && $this->feishuService->pushTextToOpenId($openId, $message)) {
            return DispatchResult::sent('open_id', $openId);
        }

        return DispatchResult::failed('delivery_target_unavailable');
    }

    private function resolveBotChatId(int $userId): string
    {
        $conversation = Conversation::query()
            ->where('user_id', $userId)
            ->where('channel', 'feishu')
            ->whereNotNull('channel_conversation_id')
            ->latest('id')
            ->first();

        return trim((string) ($conversation?->channel_conversation_id ?? ''));
    }
}
