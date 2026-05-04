<?php

namespace Tests\Unit\Services;

use App\Models\Run;
use App\Services\LarkResultNormalizerService;
use App\Services\LlmGatewayService;
use App\Services\MemoryService;
use App\Services\ToolCallExecutorService;
use App\Services\ToolCallingAgentService;
use App\Services\ToolRegistryService;
use PHPUnit\Framework\TestCase;

class ToolCallingAgentServiceTest extends TestCase
{
    public function testHandleReturnsTextResponseWhenModelDoesNotSelectAnyTool(): void
    {
        $run = new Run();
        $run->id = 101;

        $llmGatewayService = $this->createMock(LlmGatewayService::class);
        $toolRegistryService = $this->createMock(ToolRegistryService::class);
        $toolCallExecutorService = $this->createMock(ToolCallExecutorService::class);
        $larkResultNormalizerService = $this->createMock(LarkResultNormalizerService::class);
        $memoryService = $this->createMock(MemoryService::class);

        $tools = [[
            'type' => 'function',
            'function' => [
                'name' => 'docs_create',
                'description' => 'Create a Feishu document from a prompt.',
            ],
        ]];

        $toolRegistryService->expects($this->once())->method('getTools')->willReturn($tools);
        $toolRegistryService->expects($this->once())->method('getTimeContext')->willReturn('Current time: 2026-04-11 22:00 CST');

        $memoryService->expects($this->once())
            ->method('getMemoryContext')
            ->with($run, 'What did I ask last time?')
            ->willReturn('Prior context');

        $llmGatewayService->expects($this->once())
            ->method('chatWithTools')
            ->with(
                $this->callback(function (array $messages): bool {
                $this->assertSame('system', $messages[0]['role']);
                    $this->assertStringContainsString('multiple tool steps', $messages[0]['content']);
                    $this->assertStringContainsString('Do not greet, do not apologize, do not use markdown', $messages[0]['content']);
                    $this->assertStringContainsString('Memory context:', $messages[0]['content']);
                    $this->assertSame('user', $messages[1]['role']);
                    $this->assertSame('What did I ask last time?', $messages[1]['content']);

                    return true;
                }),
                $tools
            )
            ->willReturn([
                'content' => 'You asked me to summarize the weekly report.',
                'tool_calls' => [],
                'model' => 'planner-model',
                'input_tokens' => 14,
                'output_tokens' => 9,
            ]);

        $toolCallExecutorService->expects($this->never())->method('execute');
        $larkResultNormalizerService->expects($this->never())->method('normalize');

        $service = new ToolCallingAgentService(
            $llmGatewayService,
            $toolRegistryService,
            $toolCallExecutorService,
            $larkResultNormalizerService,
            $memoryService
        );

        $result = $service->handle($run, [
            ['role' => 'user', 'content' => 'What did I ask last time?'],
        ]);

        $this->assertSame('text_response', $result['type']);
        $this->assertSame(Run::INTENT_QUESTION, $result['intent_type']);
        $this->assertSame('You asked me to summarize the weekly report.', $result['content']);
        $this->assertSame('planner-model', $result['model']);
        $this->assertSame(14, $result['input_tokens']);
        $this->assertSame(9, $result['output_tokens']);
    }

    public function testHandleSanitizesDirectTextResponses(): void
    {
        $run = new Run();
        $run->id = 102;

        $llmGatewayService = $this->createMock(LlmGatewayService::class);
        $toolRegistryService = $this->createMock(ToolRegistryService::class);
        $toolCallExecutorService = $this->createMock(ToolCallExecutorService::class);
        $larkResultNormalizerService = $this->createMock(LarkResultNormalizerService::class);
        $memoryService = $this->createMock(MemoryService::class);

        $toolRegistryService->expects($this->once())->method('getTools')->willReturn([]);
        $toolRegistryService->expects($this->once())->method('getTimeContext')->willReturn('Current time: 2026-04-11 22:00 CST');
        $memoryService->expects($this->once())->method('getMemoryContext')->with($run, '你能查聊天记录吗')->willReturn('');

        $llmGatewayService->expects($this->once())
            ->method('chatWithTools')
            ->willReturn([
                'content' => "抱歉呀东方\n**我目前不能直接访问 IM一对一/群聊历史。**",
                'tool_calls' => [],
                'model' => 'planner-model',
                'input_tokens' => 5,
                'output_tokens' => 4,
            ]);

        $service = new ToolCallingAgentService(
            $llmGatewayService,
            $toolRegistryService,
            $toolCallExecutorService,
            $larkResultNormalizerService,
            $memoryService
        );

        $result = $service->handle($run, [
            ['role' => 'user', 'content' => '你能查聊天记录吗'],
        ]);

        $this->assertSame('text_response', $result['type']);
        $this->assertStringNotContainsString('抱歉', $result['content']);
        $this->assertStringNotContainsString('**', $result['content']);
        $this->assertStringNotContainsString('IM一对一/群聊历史', $result['content']);
        $this->assertStringContainsString('聊天记录', $result['content']);
    }

    public function testHandleLoopsThroughSuccessfulToolExecutionAndReturnsAgentTaskResponse(): void
    {
        $run = new Run();
        $run->id = 202;

        $llmGatewayService = $this->createMock(LlmGatewayService::class);
        $toolRegistryService = $this->createMock(ToolRegistryService::class);
        $toolCallExecutorService = $this->createMock(ToolCallExecutorService::class);
        $larkResultNormalizerService = $this->createMock(LarkResultNormalizerService::class);
        $memoryService = $this->createMock(MemoryService::class);

        $tools = [[
            'type' => 'function',
            'function' => [
                'name' => 'docs_create',
                'description' => 'Create a Feishu document from a prompt.',
            ],
        ]];
        $workAction = [
            'action_key' => 'docs.create',
            'executor' => 'lark_cli.docs',
            'task_kind' => 'feishu_doc_create',
            'planner_profile' => 'docs',
            'required_capabilities' => ['feishu.docs'],
        ];
        $executorResult = [
            'status' => 'created',
            'message' => 'Document created.',
            'document_id' => 'doc-123',
            'document_url' => 'https://example.feishu.cn/docx/doc-123',
            'input_tokens' => 3,
            'output_tokens' => 4,
        ];
        $normalizedResult = [
            'handled' => true,
            'status' => 'success',
            'answer' => 'Document created.',
            'model' => 'lark_cli.docs',
            'task_kind' => 'feishu_doc_create',
            'work_action' => 'docs.create',
            'input_tokens' => 3,
            'output_tokens' => 4,
            'raw' => $executorResult,
        ];

        $toolRegistryService->expects($this->once())->method('getTools')->willReturn($tools);
        $toolRegistryService->expects($this->once())->method('getTimeContext')->willReturn('Current time: 2026-04-11 22:00 CST');
        $toolRegistryService->expects($this->once())->method('getWorkActionMeta')->with('docs.create')->willReturn($workAction);

        $memoryService->expects($this->once())->method('getMemoryContext')->with($run, 'Create a Q2 planning document')->willReturn('');

        $llmGatewayService->expects($this->exactly(2))
            ->method('chatWithTools')
            ->with(
                $this->callback(function (array $messages): bool {
                    if (count($messages) === 2) {
                        $this->assertSame('Create a Q2 planning document', $messages[1]['content']);

                        return true;
                    }

                    $toolMessage = $messages[count($messages) - 1];
                    $this->assertSame('tool', $toolMessage['role']);
                    $this->assertStringContainsString('document_url', (string) $toolMessage['content']);

                    return true;
                }),
                $tools
            )
            ->willReturnOnConsecutiveCalls(
                [
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'function' => [
                            'name' => 'docs_create',
                            'arguments' => '{"title":"Q2 Plan","content_prompt":"Create a Q2 planning document"}',
                        ],
                    ]],
                    'model' => 'planner-model',
                    'input_tokens' => 5,
                    'output_tokens' => 6,
                ],
                [
                    'content' => 'Q2 planning document is ready: https://example.feishu.cn/docx/doc-123',
                    'tool_calls' => [],
                    'model' => 'planner-model',
                    'input_tokens' => 7,
                    'output_tokens' => 8,
                ]
            );

        $toolCallExecutorService->expects($this->once())
            ->method('execute')
            ->with(
                $run,
                [
                    'title' => 'Q2 Plan',
                    'content_prompt' => 'Create a Q2 planning document',
                ],
                'docs.create',
                [
                    ['role' => 'user', 'content' => 'Create a Q2 planning document'],
                ]
            )
            ->willReturn($executorResult);

        $larkResultNormalizerService->expects($this->once())
            ->method('normalize')
            ->with($workAction, $executorResult)
            ->willReturn($normalizedResult);

        $service = new ToolCallingAgentService(
            $llmGatewayService,
            $toolRegistryService,
            $toolCallExecutorService,
            $larkResultNormalizerService,
            $memoryService
        );

        $result = $service->handle($run, [
            ['role' => 'user', 'content' => 'Create a Q2 planning document'],
        ]);

        $this->assertSame('agent_task_response', $result['type']);
        $this->assertSame(Run::INTENT_TASK, $result['intent_type']);
        $this->assertSame('Q2 planning document is ready: https://example.feishu.cn/docx/doc-123', $result['content']);
        $this->assertSame($workAction, $result['work_action']);
        $this->assertSame($normalizedResult, $result['platform_result']);
        $this->assertCount(1, $result['tool_trace']);
        $this->assertSame(15, $result['input_tokens']);
        $this->assertSame(18, $result['output_tokens']);
    }

    public function testHandleRecoversFromFailedToolByCallingAnotherTool(): void
    {
        $run = new Run();
        $run->id = 303;

        $llmGatewayService = $this->createMock(LlmGatewayService::class);
        $toolRegistryService = $this->createMock(ToolRegistryService::class);
        $toolCallExecutorService = $this->createMock(ToolCallExecutorService::class);
        $larkResultNormalizerService = $this->createMock(LarkResultNormalizerService::class);
        $memoryService = $this->createMock(MemoryService::class);

        $tools = [
            ['type' => 'function', 'function' => ['name' => 'calendar_attendees_add', 'description' => 'Add attendees to a calendar event.']],
            ['type' => 'function', 'function' => ['name' => 'contact_lookup', 'description' => 'Search contacts.']],
        ];
        $calendarAction = [
            'action_key' => 'calendar.attendees.add',
            'executor' => 'lark_cli.calendar',
            'task_kind' => 'feishu_calendar_attendees_add',
            'required_capabilities' => ['feishu.calendar'],
        ];
        $contactAction = [
            'action_key' => 'contact.lookup',
            'executor' => 'lark_cli.contact',
            'task_kind' => 'feishu_contact_lookup',
            'required_capabilities' => ['feishu.contact'],
        ];
        $failedAdd = [
            'status' => 'failed',
            'message' => 'user not found: 朱雀',
            'input_tokens' => 1,
            'output_tokens' => 2,
        ];
        $normalizedFailedAdd = [
            'handled' => true,
            'status' => 'failed',
            'answer' => 'user not found: 朱雀',
            'model' => 'lark_cli.calendar',
            'task_kind' => 'feishu_calendar_attendees_add',
            'work_action' => 'calendar.attendees.add',
            'input_tokens' => 1,
            'output_tokens' => 2,
            'raw' => $failedAdd,
        ];
        $contactResult = [
            'status' => 'success',
            'message' => 'Matched contact.',
            'users' => [['name' => '朱雀', 'open_id' => 'ou_xxx']],
            'input_tokens' => 1,
            'output_tokens' => 1,
        ];
        $normalizedContact = [
            'handled' => true,
            'status' => 'success',
            'answer' => 'Matched contact.',
            'model' => 'lark_cli.contact',
            'task_kind' => 'feishu_contact_lookup',
            'work_action' => 'contact.lookup',
            'input_tokens' => 1,
            'output_tokens' => 1,
            'raw' => $contactResult,
        ];
        $successfulAdd = [
            'status' => 'success',
            'message' => 'Attendee added.',
            'event_id' => 'event_123',
            'input_tokens' => 2,
            'output_tokens' => 2,
        ];
        $normalizedSuccessfulAdd = [
            'handled' => true,
            'status' => 'success',
            'answer' => 'Attendee added.',
            'model' => 'lark_cli.calendar',
            'task_kind' => 'feishu_calendar_attendees_add',
            'work_action' => 'calendar.attendees.add',
            'input_tokens' => 2,
            'output_tokens' => 2,
            'raw' => $successfulAdd,
        ];

        $toolRegistryService->expects($this->once())->method('getTools')->willReturn($tools);
        $toolRegistryService->expects($this->once())->method('getTimeContext')->willReturn('Current time: 2026-04-11 22:00 CST');

        $metaCalls = 0;
        $toolRegistryService->expects($this->exactly(3))
            ->method('getWorkActionMeta')
            ->willReturnCallback(function (string $actionKey) use (&$metaCalls, $calendarAction, $contactAction) {
                $expected = ['calendar.attendees.add', 'contact.lookup', 'calendar.attendees.add'];
                $this->assertSame($expected[$metaCalls], $actionKey);
                $metaCalls++;

                return $actionKey === 'contact.lookup' ? $contactAction : $calendarAction;
            });

        $memoryService->expects($this->once())->method('getMemoryContext')->with($run, 'Add Zhuque to the event we just created')->willReturn('');

        $llmGatewayService->expects($this->exactly(4))
            ->method('chatWithTools')
            ->with($this->isType('array'), $tools)
            ->willReturnOnConsecutiveCalls(
                [
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'function' => [
                            'name' => 'calendar_attendees_add',
                            'arguments' => '{"event_id":"event_123","attendee_names":["朱雀"]}',
                        ],
                    ]],
                    'model' => 'planner-model',
                    'input_tokens' => 3,
                    'output_tokens' => 3,
                ],
                [
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_2',
                        'function' => [
                            'name' => 'contact_lookup',
                            'arguments' => '{"query":"朱雀"}',
                        ],
                    ]],
                    'model' => 'planner-model',
                    'input_tokens' => 4,
                    'output_tokens' => 4,
                ],
                [
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_3',
                        'function' => [
                            'name' => 'calendar_attendees_add',
                            'arguments' => '{"event_id":"event_123","attendee_user_ids":["ou_xxx"]}',
                        ],
                    ]],
                    'model' => 'planner-model',
                    'input_tokens' => 5,
                    'output_tokens' => 5,
                ],
                [
                    'content' => '朱雀已经加到刚才的会议里了。',
                    'tool_calls' => [],
                    'model' => 'planner-model',
                    'input_tokens' => 2,
                    'output_tokens' => 3,
                ]
            );

        $executeCalls = 0;
        $toolCallExecutorService->expects($this->exactly(3))
            ->method('execute')
            ->willReturnCallback(function (Run $actualRun, array $payload, string $actionKey, array $messages) use (&$executeCalls, $run, $failedAdd, $contactResult, $successfulAdd) {
                $this->assertSame($run, $actualRun);
                $this->assertSame([
                    ['role' => 'user', 'content' => 'Add Zhuque to the event we just created'],
                ], $messages);

                $expected = [
                    [['event_id' => 'event_123', 'attendee_names' => ['朱雀']], 'calendar.attendees.add', $failedAdd],
                    [['query' => '朱雀'], 'contact.lookup', $contactResult],
                    [['event_id' => 'event_123', 'attendee_user_ids' => ['ou_xxx']], 'calendar.attendees.add', $successfulAdd],
                ];

                $this->assertSame($expected[$executeCalls][0], $payload);
                $this->assertSame($expected[$executeCalls][1], $actionKey);
                $result = $expected[$executeCalls][2];
                $executeCalls++;

                return $result;
            });

        $normalizeCalls = 0;
        $larkResultNormalizerService->expects($this->exactly(3))
            ->method('normalize')
            ->willReturnCallback(function (array $workAction, array $executorResult) use (&$normalizeCalls, $calendarAction, $contactAction, $failedAdd, $contactResult, $successfulAdd, $normalizedFailedAdd, $normalizedContact, $normalizedSuccessfulAdd) {
                $expected = [
                    [$calendarAction, $failedAdd, $normalizedFailedAdd],
                    [$contactAction, $contactResult, $normalizedContact],
                    [$calendarAction, $successfulAdd, $normalizedSuccessfulAdd],
                ];

                $this->assertSame($expected[$normalizeCalls][0], $workAction);
                $this->assertSame($expected[$normalizeCalls][1], $executorResult);
                $result = $expected[$normalizeCalls][2];
                $normalizeCalls++;

                return $result;
            });

        $service = new ToolCallingAgentService(
            $llmGatewayService,
            $toolRegistryService,
            $toolCallExecutorService,
            $larkResultNormalizerService,
            $memoryService
        );

        $result = $service->handle($run, [
            ['role' => 'user', 'content' => 'Add Zhuque to the event we just created'],
        ]);

        $this->assertSame('agent_task_response', $result['type']);
        $this->assertSame('朱雀已经加到刚才的会议里了。', $result['content']);
        $this->assertSame($calendarAction, $result['work_action']);
        $this->assertSame($normalizedSuccessfulAdd, $result['platform_result']);
        $this->assertCount(3, $result['tool_trace']);
        $this->assertSame(18, $result['input_tokens']);
        $this->assertSame(20, $result['output_tokens']);
    }

    public function testHandleReturnsToolResultImmediatelyForClarifyStatus(): void
    {
        $run = new Run();
        $run->id = 404;

        $llmGatewayService = $this->createMock(LlmGatewayService::class);
        $toolRegistryService = $this->createMock(ToolRegistryService::class);
        $toolCallExecutorService = $this->createMock(ToolCallExecutorService::class);
        $larkResultNormalizerService = $this->createMock(LarkResultNormalizerService::class);
        $memoryService = $this->createMock(MemoryService::class);

        $tools = [[
            'type' => 'function',
            'function' => [
                'name' => 'calendar_create',
                'description' => 'Create a calendar event.',
            ],
        ]];
        $workAction = [
            'action_key' => 'calendar.create',
            'executor' => 'lark_cli.calendar',
            'task_kind' => 'feishu_calendar_create',
        ];
        $executorResult = [
            'status' => 'clarify',
            'message' => 'Please confirm the title.',
        ];
        $normalizedResult = [
            'handled' => true,
            'status' => 'clarify',
            'answer' => 'Please confirm the title.',
            'model' => 'lark_cli.calendar',
            'task_kind' => 'feishu_calendar_create',
            'work_action' => 'calendar.create',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'raw' => $executorResult,
        ];

        $toolRegistryService->expects($this->once())->method('getTools')->willReturn($tools);
        $toolRegistryService->expects($this->once())->method('getTimeContext')->willReturn('Current time: 2026-04-11 22:00 CST');
        $toolRegistryService->expects($this->once())->method('getWorkActionMeta')->with('calendar.create')->willReturn($workAction);

        $memoryService->expects($this->once())->method('getMemoryContext')->with($run, 'Schedule something for tomorrow')->willReturn('');

        $llmGatewayService->expects($this->exactly(2))
            ->method('chatWithTools')
            ->with($this->isType('array'), $tools)
            ->willReturnOnConsecutiveCalls(
                [
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'function' => [
                            'name' => 'calendar_create',
                            'arguments' => '{"start_time":"tomorrow 5pm"}',
                        ],
                    ]],
                    'model' => 'planner-model',
                    'input_tokens' => 2,
                    'output_tokens' => 2,
                ],
                [
                    'content' => 'Please confirm the title for the event.',
                    'tool_calls' => [],
                    'model' => 'planner-model',
                    'input_tokens' => 3,
                    'output_tokens' => 3,
                ]
            );

        $toolCallExecutorService->expects($this->once())
            ->method('execute')
            ->willReturn($executorResult);

        $larkResultNormalizerService->expects($this->once())
            ->method('normalize')
            ->with($workAction, $executorResult)
            ->willReturn($normalizedResult);

        $service = new ToolCallingAgentService(
            $llmGatewayService,
            $toolRegistryService,
            $toolCallExecutorService,
            $larkResultNormalizerService,
            $memoryService
        );

        $result = $service->handle($run, [
            ['role' => 'user', 'content' => 'Schedule something for tomorrow'],
        ]);

        $this->assertSame('tool_result', $result['type']);
        $this->assertSame('clarify', $result['platform_result']['status']);
        $this->assertSame('Please confirm the title.', $result['platform_result']['answer']);
        $this->assertSame(5, $result['input_tokens']);
        $this->assertSame(5, $result['output_tokens']);
        $this->assertCount(1, $result['tool_trace']);
    }
}
