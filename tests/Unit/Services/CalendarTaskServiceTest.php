<?php

namespace Tests\Unit\Services;

use App\Models\Run;
use App\Services\CalendarTaskService;
use App\Services\FeishuCliClient;
use App\Services\FeishuService;
use App\Services\FeishuTokenService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class CalendarTaskServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $container->instance('log', new class
        {
            public function debug(...$args): void {}
            public function info(...$args): void {}
            public function warning(...$args): void {}
            public function error(...$args): void {}
        });

        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testAddAttendeesIgnoresTrailingTextAfterEventLink(): void
    {
        $feishuService = $this->createMock(FeishuService::class);
        $tokenService = $this->createMock(FeishuTokenService::class);
        $cliClient = $this->createMock(FeishuCliClient::class);

        $tokenService->expects($this->once())
            ->method('resolveUserToken')
            ->willReturn(['access-token', null, null]);
        $cliClient->expects($this->once())->method('isEnabled')->willReturn(true);
        $cliClient->expects($this->once())->method('isAvailable')->willReturn(true);
        $feishuService->expects($this->once())
            ->method('readConfig')
            ->willReturn(['app_id' => 'cli-app']);

        $cliClient->expects($this->once())
            ->method('runSkillCommand')
            ->with(
                ['app_id' => 'cli-app'],
                'access-token',
                $this->callback(function (array $command): bool {
                    $this->assertSame('calendar', $command[0]);
                    $this->assertSame('event.attendees', $command[1]);
                    $this->assertSame('create', $command[2]);

                    $paramsIndex = array_search('--params', $command, true);
                    $this->assertNotFalse($paramsIndex);
                    $payload = json_decode((string) $command[$paramsIndex + 1], true);
                    $this->assertIsArray($payload);
                    $this->assertSame('primary', $payload['calendar_id'] ?? null);
                    $this->assertSame('543737d4-fd2e-47ef-827e-62f8e278da38_0', $payload['event_id'] ?? null);

                    return true;
                }),
                'user'
            )
            ->willReturn(['code' => 0, 'data' => []]);

        $service = new CalendarTaskService($feishuService, $tokenService, $cliClient);

        $result = $service->addAttendeesToEvent(
            new Run(),
            [
                'attendee_user_ids' => ['ou_3db40cb1dac2013ad0afe74334552bf8'],
                'user_id_type' => 'open_id',
            ],
            [
                [
                    'role' => 'assistant',
                    'content' => '日程已创建，链接是https://applink.feishu.cn/client/calendar/event/detail?calendarId=primary&key=543737d4-fd2e-47ef-827e-62f8e278da38_0。要是需要添加参会人或者调整时间的话，随时告诉我哈。',
                ],
            ]
        );

        $this->assertSame('success', $result['status']);
    }

    public function testReadAgendaBuildsStructuredEventsFromCliData(): void
    {
        $feishuService = $this->createMock(FeishuService::class);
        $tokenService = $this->createMock(FeishuTokenService::class);
        $cliClient = $this->createMock(FeishuCliClient::class);

        $tokenService->expects($this->once())
            ->method('resolveUserToken')
            ->willReturn(['access-token', null, null]);
        $cliClient->expects($this->once())->method('isEnabled')->willReturn(true);
        $cliClient->expects($this->once())->method('isAvailable')->willReturn(true);
        $feishuService->expects($this->once())
            ->method('readConfig')
            ->willReturn(['app_id' => 'cli-app']);

        $cliClient->expects($this->once())
            ->method('runSkillCommand')
            ->with(
                ['app_id' => 'cli-app'],
                'access-token',
                $this->callback(function (array $command): bool {
                    $this->assertSame('calendar', $command[0]);
                    $this->assertSame('+agenda', $command[1]);
                    $this->assertContains('--start', $command);
                    $this->assertContains('--end', $command);
                    $this->assertContains('--format', $command);

                    return true;
                }),
                'user'
            )
            ->willReturn([
                'code' => 0,
                'data' => [
                    [
                        'summary' => '秋冬新品开发会',
                        'start_time' => '2026-04-13T17:00:00+08:00',
                        'end_time' => '2026-04-13T18:00:00+08:00',
                        'location' => '上海会议室A',
                        'calendar_id' => 'primary',
                        'event_id' => 'evt_123',
                    ],
                ],
            ]);

        $service = new CalendarTaskService($feishuService, $tokenService, $cliClient);

        $result = $service->readAgenda(new Run(), [
            'start_time' => '2026-04-13T00:00:00+08:00',
            'end_time' => '2026-04-13T23:59:59+08:00',
            'limit' => 5,
        ]);

        $this->assertSame('read', $result['status']);
        $this->assertCount(1, $result['events']);
        $this->assertSame('秋冬新品开发会', $result['events'][0]['summary']);
        $this->assertSame('2026-04-13 17:00', $result['events'][0]['start_time']);
        $this->assertStringContainsString('calendar/event/detail', $result['events'][0]['event_url']);
    }
}
