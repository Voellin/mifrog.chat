<?php

namespace Tests\Unit\Services;

use App\Services\HistoryRedactor;
use PHPUnit\Framework\TestCase;

class HistoryRedactorTest extends TestCase
{
    public function testAssistantEventIdsAreRedacted(): void
    {
        $redactor = new HistoryRedactor();
        $out = $redactor->redactContent('Created event_abc12345xy for you.');

        $this->assertStringNotContainsString('event_abc12345xy', $out);
        $this->assertStringContainsString('<prev_event_id_1>', $out);
    }

    public function testRecentTwoAssistantTurnsAreKept(): void
    {
        $redactor = new HistoryRedactor();
        $rows = [
            ['role' => 'assistant', 'content' => 'first event_11111111aa'],
            ['role' => 'user', 'content' => 'ok'],
            ['role' => 'assistant', 'content' => 'second event_22222222bb'],
            ['role' => 'user', 'content' => 'ok'],
            ['role' => 'assistant', 'content' => 'third event_33333333cc'],
            ['role' => 'user', 'content' => 'ok'],
            ['role' => 'assistant', 'content' => 'fourth event_44444444dd'],
        ];

        $result = $redactor->redactHistory($rows);

        // oldest two assistant turns redacted
        $this->assertStringNotContainsString('event_11111111aa', $result[0]['content']);
        $this->assertStringNotContainsString('event_22222222bb', $result[2]['content']);
        $this->assertStringContainsString('<prev_event_id_1>', $result[0]['content']);
        $this->assertStringContainsString('<prev_event_id_1>', $result[2]['content']);

        // newest two assistant turns untouched
        $this->assertStringContainsString('event_33333333cc', $result[4]['content']);
        $this->assertStringContainsString('event_44444444dd', $result[6]['content']);
    }

    public function testUserMessagesAreNeverRedacted(): void
    {
        $redactor = new HistoryRedactor();
        $rows = [
            ['role' => 'user', 'content' => 'use event_userpaste1234 please'],
            ['role' => 'assistant', 'content' => 'ok'],
            ['role' => 'user', 'content' => 'and event_userpaste5678'],
        ];

        $result = $redactor->redactHistory($rows);
        $this->assertSame('use event_userpaste1234 please', $result[0]['content']);
        $this->assertSame('and event_userpaste5678', $result[2]['content']);
    }

    public function testRepeatedIdsGetSequentialSuffixes(): void
    {
        $redactor = new HistoryRedactor();
        $out = $redactor->redactContent('linked event_abc11111xy and event_def22222zw in the same reply.');

        $this->assertStringContainsString('<prev_event_id_1>', $out);
        $this->assertStringContainsString('<prev_event_id_2>', $out);
        $this->assertStringNotContainsString('event_abc11111xy', $out);
        $this->assertStringNotContainsString('event_def22222zw', $out);
    }

    public function testContentWithoutMatchesPassesThrough(): void
    {
        $redactor = new HistoryRedactor();
        $original = 'Hello, here is a plain message with no ids or urls.';
        $this->assertSame($original, $redactor->redactContent($original));
    }

    public function testFeishuUrlsAreRedactedBeforeGenericUrls(): void
    {
        $redactor = new HistoryRedactor();
        $out = $redactor->redactContent('See https://mifrog.feishu.cn/docx/abc123 and https://example.com/foo');

        $this->assertStringContainsString('<prev_feishu_url_1>', $out);
        $this->assertStringContainsString('<prev_url_1>', $out);
        $this->assertStringNotContainsString('mifrog.feishu.cn', $out);
        $this->assertStringNotContainsString('example.com', $out);
    }

    public function testIsoTimestampsAreRedacted(): void
    {
        $redactor = new HistoryRedactor();
        $out = $redactor->redactContent('scheduled at 2026-04-18T09:30:00+08:00');

        $this->assertStringNotContainsString('2026-04-18T09:30', $out);
        $this->assertStringContainsString('<prev_iso_time_1>', $out);
    }

    public function testChineseTimePhrasesAreRedacted(): void
    {
        $redactor = new HistoryRedactor();
        $out = $redactor->redactContent('今天下午3点开会 周五15:30');

        $this->assertStringContainsString('<prev_cn_time_', $out);
    }
}
