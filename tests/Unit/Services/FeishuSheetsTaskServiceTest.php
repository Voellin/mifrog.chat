<?php

namespace Tests\Unit\Services;

use App\Models\Run;
use App\Services\FeishuCliClient;
use App\Services\FeishuService;
use App\Services\FeishuSheetsTaskService;
use App\Services\FeishuTokenService;
use PHPUnit\Framework\TestCase;

class FeishuSheetsTaskServiceTest extends TestCase
{
    public function testExecuteWriteResolvesSheetTitlePrefixToSheetId(): void
    {
        $feishuService = $this->createMock(FeishuService::class);
        $tokenService = $this->createMock(FeishuTokenService::class);
        $cliClient = $this->createMock(FeishuCliClient::class);

        $feishuService->expects($this->once())
            ->method('readConfig')
            ->willReturn(['app_id' => 'cli-app']);
        $cliClient->expects($this->once())->method('isEnabled')->willReturn(true);
        $cliClient->expects($this->once())->method('isAvailable')->willReturn(true);

        $calls = 0;
        $cliClient->expects($this->exactly(2))
            ->method('runSkillCommand')
            ->willReturnCallback(function (array $config, string $accessToken, array $command, string $as) use (&$calls) {
                $calls++;
                $this->assertSame(['app_id' => 'cli-app'], $config);
                $this->assertSame('', $accessToken);
                $this->assertSame('user', $as);

                if ($calls === 1) {
                    $this->assertSame(['sheets', '+info', '--url', 'https://example.feishu.cn/sheets/token-123'], $command);

                    return [
                        'code' => 0,
                        'data' => [
                            'sheets' => [
                                'sheets' => [
                                    ['sheet_id' => 'edd914', 'title' => 'Sheet1'],
                                ],
                            ],
                        ],
                    ];
                }

                $this->assertSame('sheets', $command[0]);
                $this->assertSame('+write', $command[1]);
                $this->assertContains('--sheet-id', $command);
                $this->assertContains('edd914', $command);
                $rangeIndex = array_search('--range', $command, true);
                $this->assertNotFalse($rangeIndex);
                $this->assertSame('A4:D4', $command[$rangeIndex + 1]);

                return ['code' => 0, 'data' => []];
            });

        $service = new FeishuSheetsTaskService($feishuService, $tokenService, $cliClient);

        $result = $service->execute(new Run(), [
            'action' => 'write',
            'spreadsheet_url' => 'https://example.feishu.cn/sheets/token-123',
            'range' => 'Sheet1!A4:D4',
            'data' => [['复杂场景冒烟', '进行中', '用户A', '今天']],
        ]);

        $this->assertSame('success', $result['status']);
    }

    public function testExecuteReadDefaultsToFirstSheetIdWhenOnlyOneSheetExists(): void
    {
        $feishuService = $this->createMock(FeishuService::class);
        $tokenService = $this->createMock(FeishuTokenService::class);
        $cliClient = $this->createMock(FeishuCliClient::class);

        $feishuService->expects($this->once())
            ->method('readConfig')
            ->willReturn(['app_id' => 'cli-app']);
        $cliClient->expects($this->once())->method('isEnabled')->willReturn(true);
        $cliClient->expects($this->once())->method('isAvailable')->willReturn(true);

        $calls = 0;
        $cliClient->expects($this->exactly(2))
            ->method('runSkillCommand')
            ->willReturnCallback(function (array $config, string $accessToken, array $command, string $as) use (&$calls) {
                $calls++;
                $this->assertSame(['app_id' => 'cli-app'], $config);
                $this->assertSame('', $accessToken);
                $this->assertSame('user', $as);

                if ($calls === 1) {
                    return [
                        'code' => 0,
                        'data' => [
                            'sheets' => [
                                'sheets' => [
                                    ['sheet_id' => 'edd914', 'title' => 'Sheet1'],
                                ],
                            ],
                        ],
                    ];
                }

                $this->assertSame('sheets', $command[0]);
                $this->assertSame('+read', $command[1]);
                $this->assertContains('--sheet-id', $command);
                $this->assertContains('edd914', $command);

                return [
                    'code' => 0,
                    'data' => [
                        'valueRange' => [
                            'values' => [
                                ['验证项', '状态'],
                                ['路由桥接', '已通过'],
                            ],
                        ],
                    ],
                ];
            });

        $service = new FeishuSheetsTaskService($feishuService, $tokenService, $cliClient);

        $result = $service->execute(new Run(), [
            'action' => 'read',
            'spreadsheet_url' => 'https://example.feishu.cn/sheets/token-123',
            'range' => 'A1:B10',
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertStringContainsString('已读取', (string) $result['message']);
    }
    public function testExecuteAppendDefaultsToFirstSheetWithoutExplicitRange(): void
    {
        $feishuService = $this->createMock(FeishuService::class);
        $tokenService = $this->createMock(FeishuTokenService::class);
        $cliClient = $this->createMock(FeishuCliClient::class);

        $feishuService->expects($this->once())
            ->method('readConfig')
            ->willReturn(['app_id' => 'cli-app']);
        $cliClient->expects($this->once())->method('isEnabled')->willReturn(true);
        $cliClient->expects($this->once())->method('isAvailable')->willReturn(true);

        $calls = 0;
        $cliClient->expects($this->exactly(2))
            ->method('runSkillCommand')
            ->willReturnCallback(function (array $config, string $accessToken, array $command, string $as) use (&$calls) {
                $calls++;
                $this->assertSame(['app_id' => 'cli-app'], $config);
                $this->assertSame('', $accessToken);
                $this->assertSame('user', $as);

                if ($calls === 1) {
                    return [
                        'code' => 0,
                        'data' => [
                            'sheets' => [
                                'sheets' => [
                                    ['sheet_id' => 'edd914', 'title' => 'Sheet1'],
                                ],
                            ],
                        ],
                    ];
                }

                $this->assertSame('sheets', $command[0]);
                $this->assertSame('+append', $command[1]);
                $this->assertContains('--sheet-id', $command);
                $this->assertContains('edd914', $command);
                $rangeIndex = array_search('--range', $command, true);
                $this->assertNotFalse($rangeIndex);
                $this->assertSame('A:D', $command[$rangeIndex + 1]);

                return ['code' => 0, 'data' => []];
            });

        $service = new FeishuSheetsTaskService($feishuService, $tokenService, $cliClient);

        $result = $service->execute(new Run(), [
            'action' => 'append',
            'spreadsheet_url' => 'https://example.feishu.cn/sheets/token-123',
            'data' => [['complex-smoke', 'running', '用户A', 'today']],
        ]);

        $this->assertSame('success', $result['status']);
    }
}
