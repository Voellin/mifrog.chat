<?php

namespace Tests\Unit\Modules\ProactiveReminder;

use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use App\Modules\ProactiveReminder\Sources\ChatActivitySource;
use App\Modules\ProactiveReminder\Sources\Lark\P2pMessageSearcher;
use App\Modules\ProactiveReminder\Support\ActivityTimeParser;
use App\Modules\ProactiveReminder\Support\MessageCanonicalizer;
use App\Services\FeishuCliClient;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ChatActivitySourceTest extends TestCase
{
    public function testCollectUsesExplicitUserScopeAndBotExclusion(): void
    {
        $cliClient = $this->createMock(FeishuCliClient::class);
        $cliClient->expects($this->once())
            ->method('runSkillCommand')
            ->with(
                ['app_id' => 'cli-app'],
                '',
                $this->callback(function (array $command): bool {
                    $this->assertSame('im', $command[0]);
                    $this->assertSame('+messages-search', $command[1]);
                    $this->assertContains('--exclude-sender-type', $command);
                    $this->assertContains('bot', $command);

                    return true;
                }),
                'user',
                'ou_zhuque'
            )
            ->willReturn([
                'data' => [
                    'messages' => [
                        [
                            'chat_id' => 'oc_chat_1',
                            'chat_name' => '项目群',
                            'chat_type' => 'group',
                            'sender_name' => '用户B',
                            'sender' => ['id' => 'ou_zhuque', 'sender_type' => 'user'],
                            'content' => '这周先把样衣清单补齐',
                            'create_time' => '2026-04-12T13:55:00+08:00',
                            'msg_type' => 'text',
                        ],
                    ],
                ],
            ]);

        // P2P searcher 这个测试不关心，返回空即可；search() 必须被调一次（哪怕空）
        $p2pSearcher = $this->createMock(P2pMessageSearcher::class);
        $p2pSearcher->expects($this->once())->method('search')->willReturn([]);

        $source = new ChatActivitySource($cliClient, new ActivityTimeParser(), new MessageCanonicalizer(), $p2pSearcher);
        $request = new ReminderScanRequest(
            userId: 5,
            userName: '用户B',
            openId: 'ou_zhuque',
            since: CarbonImmutable::parse('2026-04-12 13:30:00', 'Asia/Shanghai'),
            until: CarbonImmutable::parse('2026-04-12 14:00:00', 'Asia/Shanghai'),
            windowMinutes: 30,
            collectionMode: 'full',
        );

        $results = $source->collect($request, ['app_id' => 'cli-app']);

        $this->assertCount(1, $results);
        $this->assertSame('messages', $results[0]->bucket);
        $this->assertCount(1, $results[0]->records);
        $this->assertSame('sent', $results[0]->records[0]['direction']);
        $this->assertSame('项目群', $results[0]->records[0]['chat_name']);
    }

    public function testCollectMergesP2pMessagesAndFiltersAppSenders(): void
    {
        $cliClient = $this->createMock(FeishuCliClient::class);
        // v1 search 返回空（这个测试只关心 P2P 路径）
        $cliClient->method('runSkillCommand')->willReturn(['data' => ['messages' => []]]);

        $p2pSearcher = $this->createMock(P2pMessageSearcher::class);
        $p2pSearcher->expects($this->once())->method('search')->willReturn([
            // 用户自己发的（用户B私聊）
            [
                'message_id' => 'om_1',
                'chat_id' => 'oc_zhuque_p2p',
                'sender' => ['id' => 'ou_self', 'sender_type' => 'user', 'name' => '用户A'],
                'content' => '你记得后天准备一下618的营销节奏汇报',
                'create_time' => '2026-04-29 15:10',
                'msg_type' => 'text',
            ],
            // 用户B的回复（用来定 peer_name）
            [
                'message_id' => 'om_2',
                'chat_id' => 'oc_zhuque_p2p',
                'sender' => ['id' => 'ou_zhuque', 'sender_type' => 'user', 'name' => '用户B'],
                'content' => '收到',
                'create_time' => '2026-04-29 15:11',
                'msg_type' => 'text',
            ],
            // 机器人卡片，应被过滤
            [
                'message_id' => 'om_3',
                'chat_id' => 'oc_bot_p2p',
                'sender' => ['id' => 'cli_xxx', 'sender_type' => 'app'],
                'content' => '<card>任务执行过程</card>',
                'create_time' => '2026-04-29 15:12',
                'msg_type' => 'interactive',
            ],
        ]);

        $source = new ChatActivitySource($cliClient, new ActivityTimeParser(), new MessageCanonicalizer(), $p2pSearcher);
        $request = new ReminderScanRequest(
            userId: 3,
            userName: '用户A',
            openId: 'ou_self',
            since: CarbonImmutable::parse('2026-04-29 00:00:00', 'Asia/Shanghai'),
            until: CarbonImmutable::parse('2026-04-29 23:59:59', 'Asia/Shanghai'),
            windowMinutes: 1440,
            collectionMode: 'full',
        );

        $results = $source->collect($request, ['app_id' => 'cli-app']);

        $this->assertCount(1, $results);
        $records = $results[0]->records;
        // 2 条 P2P（用户B双向），机器人卡片被过滤
        $this->assertCount(2, $records);

        // 双向消息都应归到"私聊·用户B"（peer_name 由用户B的 sender 推断）
        $chatNames = array_unique(array_column($records, 'chat_name'));
        $this->assertSame(['私聊·用户B'], array_values($chatNames));

        // direction 检验
        $directions = array_column($records, 'direction');
        $this->assertContains('sent', $directions);
        $this->assertContains('received', $directions);
    }
}
