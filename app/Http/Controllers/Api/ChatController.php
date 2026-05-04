<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RunAccessTokenService;
use App\Services\RunFactoryService;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    private RunFactoryService $runFactoryService;
    private RunAccessTokenService $runAccessTokenService;

    public function __construct(RunFactoryService $runFactoryService, RunAccessTokenService $runAccessTokenService)
    {
        $this->runFactoryService = $runFactoryService;
        $this->runAccessTokenService = $runAccessTokenService;
    }

    public function send(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
            'feishu_open_id' => 'nullable|string',
            'content' => 'required|string|min:1|max:8000',
            'channel' => 'nullable|string',
            'channel_conversation_id' => 'nullable|string',
            'feishu_chat_id' => 'nullable|string',
        ]);

        $user = null;
        if (! empty($data['user_id'])) {
            $user = User::query()->find($data['user_id']);
        } elseif (! empty($data['feishu_open_id'])) {
            $user = User::query()->where('feishu_open_id', $data['feishu_open_id'])->first();
        }

        if (! $user) {
            return response()->json([
                'message' => 'User not found',
            ], 404);
        }

        $run = $this->runFactoryService->createRun($user, $data['content'], [
            'channel' => $data['channel'] ?? 'feishu',
            'channel_conversation_id' => $data['channel_conversation_id'] ?? null,
            'feishu_chat_id' => $data['feishu_chat_id'] ?? null,
        ]);

        $streamToken = $this->runAccessTokenService->issue((int) $run->id);

        return response()->json([
            'run_id' => $run->id,
            'status' => $run->status,
            'conversation_id' => $run->conversation_id,
            'stream_token' => $streamToken,
        ]);
    }
}
