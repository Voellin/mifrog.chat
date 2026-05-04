<?php

namespace Tests\Unit\Routing\Focus;

use App\Models\Conversation;
use App\Models\Run;
use App\Routing\Focus\FocusEntityExtractor;
use PHPUnit\Framework\TestCase;

class FocusEntityExtractorTest extends TestCase
{
    public function testSnapshotFromStoredReturnsFocusSnapshot(): void
    {
        $extractor = new FocusEntityExtractor();

        $snapshot = $extractor->snapshotFromStored([
            'object_type' => 'document',
            'object_id' => 'doc_123',
            'summary' => '产品需求文档',
            'confidence' => 0.97,
            'attributes' => ['document_url' => 'https://example.test/doc'],
        ]);

        $this->assertNotNull($snapshot);
        $this->assertSame('document', $snapshot->objectType);
        $this->assertSame('doc_123', $snapshot->objectId);
        $this->assertSame('产品需求文档', $snapshot->summary);
        $this->assertSame('https://example.test/doc', $snapshot->attributes['document_url']);
    }

    public function testExtractFromPlatformResultReturnsCalendarFocus(): void
    {
        $extractor = new FocusEntityExtractor();

        $focus = $extractor->extractFromPlatformResult([
            'work_action' => 'calendar.create',
            'raw' => [
                'event_id' => 'event_1',
                'calendar_id' => 'cal_1',
                'event_url' => 'https://example.test/event',
                'summary' => 'Roadmap Review',
            ],
        ]);

        $this->assertNotNull($focus);
        $this->assertSame('calendar_event', $focus['object_type']);
        $this->assertSame('event_1', $focus['object_id']);
        $this->assertSame('Roadmap Review', $focus['summary']);
    }

    public function testExtractFromRunUsesStoredFocusOutput(): void
    {
        $extractor = new FocusEntityExtractor();
        $run = $this->makeRun('feishu', [
            'focus_output' => [
                'object_type' => 'sheet',
                'object_id' => 'sheet_token_1',
                'summary' => '状态看板',
                'confidence' => 0.95,
                'attributes' => ['spreadsheet_url' => 'https://example.test/sheet'],
            ],
        ]);

        $snapshot = $extractor->extractFromRun($run);

        $this->assertNotNull($snapshot);
        $this->assertSame('sheet', $snapshot->objectType);
        $this->assertSame('sheet_token_1', $snapshot->objectId);
        $this->assertSame('状态看板', $snapshot->summary);
    }

    /**
     * @param  array<string,mixed>  $intentMeta
     */
    private function makeRun(string $channel, array $intentMeta): Run
    {
        $conversation = new Conversation();
        $conversation->id = 99;
        $conversation->user_id = 456;
        $conversation->channel = $channel;

        $run = new Run();
        $run->id = 123;
        $run->user_id = 456;
        $run->conversation_id = 99;
        $run->intent_meta = $intentMeta;
        $run->setRelation('conversation', $conversation);

        return $run;
    }
}
