<?php

namespace Tests\Unit\Services;

use App\Models\MemoryEntry;
use App\Services\LlmGatewayService;
use App\Services\Memory\MemoryKeywordExtractor;
use App\Services\Memory\MemoryLayerPolicy;
use App\Services\Memory\MemoryRecallScorer;
use App\Services\Memory\MemoryTextSanitizer;
use App\Services\MemoryService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class MemoryServicePromotionTest extends TestCase
{
    private function makeService(): MemoryService
    {
        $keywordExtractor = new MemoryKeywordExtractor();
        $textSanitizer = new MemoryTextSanitizer();
        $layerPolicy = new MemoryLayerPolicy($textSanitizer);

        return new MemoryService(
            $this->createMock(LlmGatewayService::class),
            $keywordExtractor,
            $textSanitizer,
            $layerPolicy,
            new MemoryRecallScorer($keywordExtractor, $layerPolicy)
        );
    }

    public function testClassifyEntryForPromotionRejectsSystemSourceEntries(): void
    {
        $service = $this->makeService();

        $entry = new MemoryEntry();
        $entry->title = 'Recent context';
        $entry->content = '请用中文回复我，格式简洁一点。';
        $entry->tags = [];

        $invoke = \Closure::bind(function (MemoryEntry $entry): array {
            return $this->classifyEntryForPromotion($entry);
        }, $service, MemoryService::class);

        $decision = $invoke($entry);

        $this->assertFalse($decision['promote_l3']);
    }

    public function testClassifyEntryForPromotionRejectsOneShotTaskInstructions(): void
    {
        $service = $this->makeService();

        $entry = new MemoryEntry();
        $entry->title = 'Recent context';
        $entry->content = '帮我创建一个飞书表格，标题叫四月销售跟进。';
        $entry->tags = ['source:user', 'kind:episodic_context'];

        $invoke = \Closure::bind(function (MemoryEntry $entry): array {
            return $this->classifyEntryForPromotion($entry);
        }, $service, MemoryService::class);

        $decision = $invoke($entry);

        $this->assertFalse($decision['promote_l3']);
    }

    public function testClassifyEntryForPromotionRejectsExpiredEntries(): void
    {
        $service = $this->makeService();

        $entry = new MemoryEntry();
        $entry->title = 'Recent context';
        $entry->content = '以后默认先给结论。';
        $entry->tags = ['source:user', 'kind:episodic_context', 'ttl:1'];
        $entry->setRawAttributes(array_merge($entry->getAttributes(), [
            'expired_at' => Carbon::now()->subMinute(),
        ]), true);

        $invoke = \Closure::bind(function (MemoryEntry $entry): array {
            return $this->classifyEntryForPromotion($entry);
        }, $service, MemoryService::class);

        $decision = $invoke($entry);

        $this->assertFalse($decision['promote_l3']);
    }

    public function testShouldUseEntryInPromptRejectsAssistantEntries(): void
    {
        $service = $this->makeService();

        $assistantEntry = new MemoryEntry();
        $assistantEntry->summary = '王林您好，我刚帮你查到了联系人信息。';
        $assistantEntry->content = '王林您好，我刚帮你查到了联系人信息。';
        $assistantEntry->tags = ['source:assistant', 'kind:episodic_result'];

        $invoke = \Closure::bind(function (MemoryEntry $entry): bool {
            return $this->shouldUseEntryInPrompt($entry);
        }, $service, MemoryService::class);

        $this->assertFalse($invoke($assistantEntry));
    }

    public function testShouldUseEntryInPromptRejectsExpiredEntries(): void
    {
        $service = $this->makeService();

        $entry = new MemoryEntry();
        $entry->summary = '上周创建了一个销售周报文档。';
        $entry->content = '上周创建了一个销售周报文档。';
        $entry->tags = ['source:user', 'kind:episodic_context', 'ttl:1'];
        $entry->setRawAttributes(array_merge($entry->getAttributes(), [
            'expired_at' => Carbon::now()->subMinute(),
        ]), true);

        $invoke = \Closure::bind(function (MemoryEntry $entry): bool {
            return $this->shouldUseEntryInPrompt($entry);
        }, $service, MemoryService::class);

        $this->assertFalse($invoke($entry));
    }
}
