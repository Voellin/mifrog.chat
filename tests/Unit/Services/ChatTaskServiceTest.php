<?php

namespace Tests\Unit\Services;

use App\Models\Run;
use App\Models\User;
use App\Services\ChatTaskService;
use App\Services\FeishuCliClient;
use App\Services\FeishuService;
use App\Services\FeishuTokenService;
use PHPUnit\Framework\TestCase;

class ChatTaskServiceTest extends TestCase
{
    public function testReadHistoryFiltersByKeywordAndRedactsSensitiveText(): void
    {
        $feishuService = $this->createMock(FeishuService::class);
        $tokenService = $this->createMock(FeishuTokenService::class);
        $cliClient = $this->createMock(FeishuCliClient::class);

        $tokenService->expects($this->once())
            ->method('resolveUserToken')
            ->willReturn(['access-token', null, null]);
        $cliClient->expects($this->once())->method('isEnabled')->willReturn(true);
        $cliClient->expects($this->once())->method('isAvailable')->willReturn(true);
        $feishuService->expects($this->once())->method('readConfig')->willReturn(['app_id' => 'cli-app']);

        $cliClient->expects($this->once())
            ->method('runSkillCommand')
            ->with(
                ['app_id' => 'cli-app'],
                'access-token',
                $this->callback(function (array $command): bool {
                    $this->assertSame('im', $command[0]);
                    $this->assertSame('+messages-search', $command[1]);
                    $this->assertContains('--start', $command);
                    $this->assertContains('--end', $command);
                    $this->assertContains('--page-size', $command);

                    return true;
                }),
                'user'
            )
            ->willReturn([
                'code' => 0,
                'data' => [
                    'messages' => [
                        [
                            'chat_id' => 'oc_chat_1',
                            'chat_name' => '项目群',
                            'chat_type' => 'group',
                            'sender_name' => '东方',
                            'content' => '东方手机号 13800138000 邮箱 test@example.com https://example.com 周报',
                            'create_time' => '2026-04-12T09:30:00+08:00',
                            'msg_type' => 'text',
                        ],
                        [
                            'chat_id' => 'oc_chat_2',
                            'chat_name' => '闲聊',
                            'chat_type' => 'group',
                            'sender_name' => '朱雀',
                            'content' => '今天中午吃什么',
                            'create_time' => '2026-04-12T09:40:00+08:00',
                            'msg_type' => 'text',
                        ],
                    ],
                ],
            ]);

        $service = new ChatTaskService($feishuService, $tokenService, $cliClient);

        $result = $service->readHistory(new Run(), [
            'keyword' => '周报',
            'limit' => 5,
        ]);

        $this->assertSame('read', $result['status']);
        $this->assertCount(1, $result['messages']);
        $this->assertStringContainsString('matching chat message', $result['message']);
        $this->assertStringNotContainsString('13800138000', $result['messages'][0]['snippet']);
        $this->assertStringNotContainsString('test@example.com', $result['messages'][0]['snippet']);
        $this->assertStringNotContainsString('https://example.com', $result['messages'][0]['snippet']);
        $this->assertStringContainsString('[phone]', $result['messages'][0]['snippet']);
        $this->assertStringContainsString('[email]', $result['messages'][0]['snippet']);
        $this->assertStringContainsString('[link]', $result['messages'][0]['snippet']);
    }

    public function testReadHistoryFindsCommunicationWithNamedParticipantAcrossSharedChats(): void
    {
        $feishuService = $this->createMock(FeishuService::class);
        $tokenService = $this->createMock(FeishuTokenService::class);
        $cliClient = $this->createMock(FeishuCliClient::class);

        $identity = (object) ['provider_user_id' => 'ou_dongfang'];

        $tokenService->expects($this->once())
            ->method('resolveUserToken')
            ->willReturn(['access-token', $identity, null]);
        $cliClient->expects($this->once())->method('isEnabled')->willReturn(true);
        $cliClient->expects($this->once())->method('isAvailable')->willReturn(true);
        $feishuService->expects($this->once())->method('readConfig')->willReturn(['app_id' => 'cli-app']);

        $calls = 0;
        $cliClient->expects($this->exactly(3))
            ->method('runSkillCommand')
            ->willReturnCallback(function (array $config, string $accessToken, array $command, string $as, string $userKey) use (&$calls) {
                $calls++;
                $this->assertSame(['app_id' => 'cli-app'], $config);
                $this->assertSame('user', $as);
                $this->assertSame('ou_dongfang', $userKey);

                return match ($calls) {
                    1 => $this->assertParticipantLookupCommand($command),
                    2 => $this->assertParticipantSearchCommand($command),
                    3 => $this->assertSharedChatListCommand($command),
                };
            });

        $service = new ChatTaskService($feishuService, $tokenService, $cliClient);

        $run = new Run();
        $run->setRelation('user', new User([
            'name' => '东方',
            'feishu_open_id' => 'ou_dongfang',
        ]));

        $result = $service->readHistory($run, [
            'participant_names' => ['朱雀'],
            'start_time' => '2026-04-01T00:00:00+08:00',
            'end_time' => '2026-04-12T23:59:59+08:00',
            'limit' => 10,
        ]);

        $this->assertSame('read', $result['status']);
        $this->assertCount(2, $result['messages']);
        $this->assertStringContainsString('you and 朱雀 across 1 chat', $result['message']);
        $this->assertSame('shared_chat', $result['messages'][0]['scope']);
        $this->assertSame('shared_chat', $result['messages'][1]['scope']);
        $this->assertSame('sent', $result['messages'][1]['direction']);
        $this->assertStringNotContainsString('bot reminder', $result['message']);
    }

    /**
     * @param  array<int, string>  $command
     * @return array<string, mixed>
     */
    private function assertParticipantLookupCommand(array $command): array
    {
        $this->assertSame(['contact', '+search-user', '--query', '朱雀'], $command);

        return [
            'code' => 0,
            'data' => [
                'users' => [
                    ['name' => '朱雀', 'open_id' => 'ou_zhuque'],
                ],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $command
     * @return array<string, mixed>
     */
    private function assertParticipantSearchCommand(array $command): array
    {
        $this->assertSame('im', $command[0]);
        $this->assertSame('+messages-search', $command[1]);
        $this->assertContains('--sender', $command);
        $this->assertContains('ou_zhuque', $command);
        $this->assertContains('--exclude-sender-type', $command);
        $this->assertContains('bot', $command);

        return [
            'code' => 0,
            'data' => [
                'messages' => [
                    [
                        'chat_id' => 'oc_shared_chat',
                        'chat_name' => '秋冬项目群',
                        'chat_type' => 'group',
                        'sender_name' => '朱雀',
                        'sender' => ['id' => 'ou_zhuque', 'sender_type' => 'user'],
                        'content' => '请补一下样衣清单',
                        'create_time' => '2026-04-10T10:00:00+08:00',
                        'msg_type' => 'text',
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $command
     * @return array<string, mixed>
     */
    private function assertSharedChatListCommand(array $command): array
    {
        $this->assertSame(['im', '+chat-messages-list', '--chat-id', 'oc_shared_chat'], array_slice($command, 0, 4));

        return [
            'code' => 0,
            'data' => [
                'messages' => [
                    [
                        'chat_id' => 'oc_shared_chat',
                        'chat_name' => '秋冬项目群',
                        'chat_type' => 'group',
                        'sender_name' => '朱雀',
                        'sender' => ['id' => 'ou_zhuque', 'sender_type' => 'user'],
                        'content' => '请补一下样衣清单',
                        'create_time' => '2026-04-10T10:00:00+08:00',
                        'msg_type' => 'text',
                    ],
                    [
                        'chat_id' => 'oc_shared_chat',
                        'chat_name' => '秋冬项目群',
                        'chat_type' => 'group',
                        'sender_name' => '东方',
                        'sender' => ['id' => 'ou_dongfang', 'sender_type' => 'user'],
                        'content' => '我今天补上',
                        'create_time' => '2026-04-10T10:05:00+08:00',
                        'msg_type' => 'text',
                    ],
                    [
                        'chat_id' => 'oc_shared_chat',
                        'chat_name' => '秋冬项目群',
                        'chat_type' => 'group',
                        'sender_name' => 'MiFrog',
                        'sender' => ['id' => 'ou_bot', 'sender_type' => 'bot'],
                        'content' => 'bot reminder',
                        'create_time' => '2026-04-10T10:06:00+08:00',
                        'msg_type' => 'text',
                    ],
                ],
            ],
        ];
    }
}
