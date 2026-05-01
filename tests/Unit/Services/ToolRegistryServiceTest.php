<?php

namespace Tests\Unit\Services;

use App\Services\ToolRegistryService;
use PHPUnit\Framework\TestCase;

class ToolRegistryServiceTest extends TestCase
{
    public function testGetToolsReturnsWrappedFunctionDefinitions(): void
    {
        $service = new ToolRegistryService();

        $tools = $service->getTools();
        $names = array_map(
            static fn (array $tool): string => (string) ($tool['function']['name'] ?? ''),
            $tools
        );

        $this->assertCount(23, $tools);
        $this->assertContains('load_skill', $names);
        $this->assertContains('execute_sandbox_skill', $names);
        $this->assertContains('execute_api_skill', $names);
        $this->assertContains('calendar_create', $names);
        $this->assertContains('calendar_agenda', $names);
        $this->assertContains('docs_create', $names);
        $this->assertContains('sheets_append', $names);
        $this->assertContains('drive_manage', $names);
        $this->assertContains('chat_history_read', $names);
        $this->assertContains('request_authorization', $names);

        $docsCreate = array_values(array_filter(
            $tools,
            static fn (array $tool): bool => ($tool['function']['name'] ?? '') === 'docs_create'
        ))[0];

        $this->assertSame('function', $docsCreate['type']);
        $this->assertSame(
            ['title', 'content_prompt'],
            $docsCreate['function']['parameters']['required']
        );

        $calendarAttendees = array_values(array_filter(
            $tools,
            static fn (array $tool): bool => ($tool['function']['name'] ?? '') === 'calendar_attendees_add'
        ))[0];
        $this->assertArrayHasKey('event_url', $calendarAttendees['function']['parameters']['properties']);

        $executeApi = array_values(array_filter(
            $tools,
            static fn (array $tool): bool => ($tool['function']['name'] ?? '') === 'execute_api_skill'
        ))[0];
        $this->assertSame('function', $executeApi['type']);
        $this->assertContains('skill_key', $executeApi['function']['parameters']['required']);
    }

    public function testGetWorkActionMetaReturnsExpectedExecutorMapping(): void
    {
        $service = new ToolRegistryService();

        $docsCreate = $service->getWorkActionMeta('docs.create');
        $sheetsAppend = $service->getWorkActionMeta('sheets.append');
        $calendarAgenda = $service->getWorkActionMeta('calendar.agenda');
        $chatHistory = $service->getWorkActionMeta('chat.history_read');
        $unknown = $service->getWorkActionMeta('unknown.action');

        $this->assertSame('docs.create', $docsCreate['action_key']);
        $this->assertSame('lark_cli.docs', $docsCreate['executor']);
        $this->assertSame('feishu_doc_create', $docsCreate['task_kind']);
        $this->assertSame('docs', $docsCreate['planner_profile']);
        $this->assertSame('sheets.append', $sheetsAppend['action_key']);
        $this->assertSame('lark_cli.sheets', $sheetsAppend['executor']);
        $this->assertSame('feishu_sheet_append', $sheetsAppend['task_kind']);
        $this->assertSame('sheets', $sheetsAppend['planner_profile']);
        $this->assertSame('calendar.agenda', $calendarAgenda['action_key']);
        $this->assertSame('lark_cli.calendar', $calendarAgenda['executor']);
        $this->assertSame('feishu_calendar_agenda', $calendarAgenda['task_kind']);
        $this->assertSame('chat.history_read', $chatHistory['action_key']);
        $this->assertSame('lark_cli.chat', $chatHistory['executor']);
        $this->assertSame('feishu_chat_history_read', $chatHistory['task_kind']);
        $this->assertSame([], $unknown);
    }

    public function testGetTimeContextIncludesConfiguredTimezone(): void
    {
        $service = new ToolRegistryService();

        $context = $service->getTimeContext();

        $this->assertStringContainsString('Current time:', $context);
        $this->assertStringContainsString('timezone: Asia/Shanghai', $context);
    }
}
