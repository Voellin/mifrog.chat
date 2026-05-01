<?php

namespace Tests\Unit\Services;

use App\Models\Run;
use App\Services\FeishuBaseTaskService;
use App\Services\FeishuCliClient;
use App\Services\FeishuService;
use PHPUnit\Framework\TestCase;

class FeishuBaseTaskServiceTest extends TestCase
{
    public function testExecuteCreateBaseIncludesUrlAndBaseTokenInMessage(): void
    {
        $feishuService = $this->createMock(FeishuService::class);
        $cliClient = $this->createMock(FeishuCliClient::class);

        $feishuService->expects($this->once())
            ->method('readConfig')
            ->willReturn(['app_id' => 'cli-app']);
        $cliClient->expects($this->once())->method('isEnabled')->willReturn(true);
        $cliClient->expects($this->once())->method('isAvailable')->willReturn(true);
        $cliClient->expects($this->once())
            ->method('runSkillCommand')
            ->with(
                ['app_id' => 'cli-app'],
                '',
                ['base', '+base-create', '--name', 'Validation Base', '--time-zone', 'Asia/Shanghai'],
                'user'
            )
            ->willReturn([
                'ok' => true,
                'data' => [
                    'base' => [
                        'name' => 'Validation Base',
                        'url' => 'https://example.feishu.cn/base/app-token',
                        'base_token' => 'app-token',
                    ],
                ],
            ]);

        $service = new FeishuBaseTaskService($feishuService, $cliClient);

        $result = $service->execute(new Run(), [
            'action' => 'create_base',
            'base_name' => 'Validation Base',
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertStringContainsString('https://example.feishu.cn/base/app-token', (string) $result['message']);
        $this->assertStringContainsString('Base token: app-token', (string) $result['message']);
        $this->assertSame('app-token', $result['base_token']);
    }

    public function testExecuteCreateTableUsesCliIdFieldForTableId(): void
    {
        $feishuService = $this->createMock(FeishuService::class);
        $cliClient = $this->createMock(FeishuCliClient::class);

        $feishuService->expects($this->once())
            ->method('readConfig')
            ->willReturn(['app_id' => 'cli-app']);
        $cliClient->expects($this->once())->method('isEnabled')->willReturn(true);
        $cliClient->expects($this->once())->method('isAvailable')->willReturn(true);
        $cliClient->expects($this->once())
            ->method('runSkillCommand')
            ->willReturn([
                'ok' => true,
                'data' => [
                    'table' => [
                        'id' => 'tbl123',
                        'name' => '验证记录',
                    ],
                ],
            ]);

        $service = new FeishuBaseTaskService($feishuService, $cliClient);

        $result = $service->execute(new Run(), [
            'action' => 'create_table',
            'base_token' => 'app-token',
            'table_name' => '验证记录',
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertSame('tbl123', $result['table_id']);
        $this->assertSame('验证记录', $result['table_name']);
        $this->assertStringContainsString('table_id=tbl123', (string) $result['message']);
    }

    public function testExecuteCreateTableNormalizesFieldAliasesBeforeCallingCli(): void
    {
        $feishuService = $this->createMock(FeishuService::class);
        $cliClient = $this->createMock(FeishuCliClient::class);

        $feishuService->expects($this->once())
            ->method('readConfig')
            ->willReturn(['app_id' => 'cli-app']);
        $cliClient->expects($this->once())->method('isEnabled')->willReturn(true);
        $cliClient->expects($this->once())->method('isAvailable')->willReturn(true);
        $calls = 0;
        $cliClient->expects($this->exactly(2))
            ->method('runSkillCommand')
            ->willReturnCallback(function (array $config, string $token, array $command, string $identity) use (&$calls) {
                $calls++;
                $this->assertSame(['app_id' => 'cli-app'], $config);
                $this->assertSame('', $token);
                $this->assertSame('user', $identity);

                if ($calls === 1) {
                    $this->assertSame(
                        [
                            'base',
                            '+table-create',
                            '--base-token',
                            'app-token',
                            '--name',
                            '验证记录',
                            '--fields',
                            '[{"field_name":"验证项","type":"text"},{"field_name":"状态","type":"text"},{"field_name":"负责人","type":"text"}]',
                        ],
                        $command
                    );

                    return [
                        'ok' => true,
                        'data' => [
                            'table' => [
                                'id' => 'tbl123',
                                'name' => '验证记录',
                            ],
                        ],
                    ];
                }

                $this->assertSame(
                    ['base', '+field-list', '--base-token', 'app-token', '--table-id', 'tbl123'],
                    $command
                );

                return [
                    'ok' => true,
                    'data' => [
                        'items' => [
                            ['field_name' => '验证项'],
                            ['field_name' => '状态'],
                            ['field_name' => '负责人'],
                        ],
                    ],
                ];
            });

        $service = new FeishuBaseTaskService($feishuService, $cliClient);

        $result = $service->execute(new Run(), [
            'action' => 'create_table',
            'base_token' => 'app-token',
            'table_name' => '验证记录',
            'fields' => [
                ['name' => '验证项', 'type' => 'Text'],
                ['field_name' => '状态', 'field_type' => '文本'],
                ['name' => '负责人', 'type' => 'string'],
            ],
        ]);

        $this->assertSame('success', $result['status']);
    }

    public function testExecuteListTablesUsesTableNameFieldFromCliResponse(): void
    {
        $feishuService = $this->createMock(FeishuService::class);
        $cliClient = $this->createMock(FeishuCliClient::class);

        $feishuService->expects($this->once())
            ->method('readConfig')
            ->willReturn(['app_id' => 'cli-app']);
        $cliClient->expects($this->once())->method('isEnabled')->willReturn(true);
        $cliClient->expects($this->once())->method('isAvailable')->willReturn(true);
        $cliClient->expects($this->once())
            ->method('runSkillCommand')
            ->willReturn([
                'ok' => true,
                'data' => [
                    'items' => [
                        ['table_id' => 'tblA', 'table_name' => '数据表'],
                        ['table_id' => 'tblB', 'table_name' => '验证记录'],
                    ],
                ],
            ]);

        $service = new FeishuBaseTaskService($feishuService, $cliClient);

        $result = $service->execute(new Run(), [
            'action' => 'list_tables',
            'base_token' => 'app-token',
        ]);

        $message = (string) $result['message'];

        $this->assertSame('success', $result['status']);
        $this->assertStringContainsString('数据表 (table_id=tblA)', $message);
        $this->assertStringContainsString('验证记录 (table_id=tblB)', $message);
        $this->assertStringNotContainsString('Untitled table', $message);
    }

    public function testExecuteListRecordsUnderstandsTabularDataPayload(): void
    {
        $feishuService = $this->createMock(FeishuService::class);
        $cliClient = $this->createMock(FeishuCliClient::class);

        $feishuService->expects($this->once())
            ->method('readConfig')
            ->willReturn(['app_id' => 'cli-app']);
        $cliClient->expects($this->once())->method('isEnabled')->willReturn(true);
        $cliClient->expects($this->once())->method('isAvailable')->willReturn(true);
        $cliClient->expects($this->once())
            ->method('runSkillCommand')
            ->willReturn([
                'ok' => true,
                'data' => [
                    'fields' => ['验证项', '负责人', '状态'],
                    'data' => [
                        ['tool calling 全链路', '用户A', '已通过'],
                    ],
                ],
            ]);

        $service = new FeishuBaseTaskService($feishuService, $cliClient);

        $result = $service->execute(new Run(), [
            'action' => 'list_records',
            'base_token' => 'app-token',
            'table_id' => 'tbl123',
        ]);

        $message = (string) $result['message'];

        $this->assertSame('success', $result['status']);
        $this->assertStringContainsString('Listed 1 records', $message);
        $this->assertStringContainsString('tool calling 全链路', $message);
        $this->assertStringContainsString('负责人', $message);
    }

    public function testExecuteCreateTableRecoversByBackfillingMissingFields(): void
    {
        $feishuService = $this->createMock(FeishuService::class);
        $cliClient = $this->createMock(FeishuCliClient::class);

        $feishuService->expects($this->once())
            ->method('readConfig')
            ->willReturn(['app_id' => 'cli-app']);
        $cliClient->expects($this->once())->method('isEnabled')->willReturn(true);
        $cliClient->expects($this->once())->method('isAvailable')->willReturn(true);

        $calls = 0;
        $cliClient->expects($this->exactly(4))
            ->method('runSkillCommand')
            ->willReturnCallback(function (array $config, string $token, array $command, string $identity) use (&$calls) {
                $calls++;
                $this->assertSame(['app_id' => 'cli-app'], $config);
                $this->assertSame('', $token);
                $this->assertSame('user', $identity);

                return match ($calls) {
                    1 => [
                        'code' => 500,
                        'msg' => 'OpenAPIAddField limited',
                    ],
                    2 => [
                        'ok' => true,
                        'data' => [
                            'items' => [
                                ['table_id' => 'tbl123', 'table_name' => '验证记录'],
                            ],
                        ],
                    ],
                    3 => [
                        'ok' => true,
                        'data' => [
                            'items' => [
                                ['field_name' => '验证项'],
                                ['field_name' => '状态'],
                            ],
                        ],
                    ],
                    4 => [
                        'ok' => true,
                        'data' => [
                            'created' => true,
                            'field' => ['id' => 'fld3', 'name' => '负责人', 'type' => 'text'],
                        ],
                    ],
                };
            });

        $service = new FeishuBaseTaskService($feishuService, $cliClient);

        $result = $service->execute(new Run(), [
            'action' => 'create_table',
            'base_token' => 'app-token',
            'table_name' => '验证记录',
            'fields' => [
                ['field_name' => '验证项', 'type' => 'text'],
                ['field_name' => '状态', 'type' => 'text'],
                ['field_name' => '负责人', 'type' => 'text'],
            ],
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertSame('tbl123', $result['table_id']);
        $this->assertStringContainsString('Added missing fields: 负责人.', (string) $result['message']);
        $this->assertStringContainsString('Field sync was recovered', (string) $result['message']);
    }
}
