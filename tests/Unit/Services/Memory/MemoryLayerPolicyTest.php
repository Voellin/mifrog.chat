<?php

namespace Tests\Unit\Services\Memory;

use App\Services\Memory\MemoryLayerPolicy;
use App\Services\Memory\MemoryTextSanitizer;
use PHPUnit\Framework\TestCase;

class MemoryLayerPolicyTest extends TestCase
{
    public function testClassifyUserTextPromotesExplicitPreferenceToLongTermMemory(): void
    {
        $policy = new MemoryLayerPolicy(new MemoryTextSanitizer());

        $decision = $policy->classifyUserText('请用中文回复我，风格简洁一点。', true);

        $this->assertTrue($decision['store_l2']);
        $this->assertTrue($decision['promote_l3']);
        $this->assertSame('preference', $decision['category']);
    }

    public function testClassifyUserTextTreatsBreakfastAsTransient(): void
    {
        $policy = new MemoryLayerPolicy(new MemoryTextSanitizer());

        $decision = $policy->classifyUserText('我今天早饭吃了包子和豆浆。');

        $this->assertFalse($decision['store_l2']);
        $this->assertFalse($decision['promote_l3']);
        $this->assertSame('transient_detail', $decision['kind']);
    }

    public function testClassifyUserTextKeepsOneShotTaskInL2Only(): void
    {
        $policy = new MemoryLayerPolicy(new MemoryTextSanitizer());

        $decision = $policy->classifyUserText('帮我创建一个飞书表格，标题叫四月销售跟进。');

        $this->assertTrue($decision['store_l2']);
        $this->assertFalse($decision['promote_l3']);
        $this->assertSame('episodic_context', $decision['category']);
    }

    public function testClassifyUserTextShortCircuitsTaskEvenWhenSentenceContainsPersistentCue(): void
    {
        $policy = new MemoryLayerPolicy(new MemoryTextSanitizer());

        $decision = $policy->classifyUserText('帮我以后每周创建一个销售周报文档。');

        $this->assertTrue($decision['store_l2']);
        $this->assertFalse($decision['promote_l3']);
        $this->assertSame('episodic_context', $decision['category']);
    }

    public function testClassifyUserTextPromotesStableConstraint(): void
    {
        $policy = new MemoryLayerPolicy(new MemoryTextSanitizer());

        $decision = $policy->classifyUserText('以后默认先给结论，再给解释。');

        $this->assertTrue($decision['promote_l3']);
        $this->assertSame('constraint', $decision['category']);
    }

    public function testExplicitRememberWithUrlStaysOutOfLongTermMemory(): void
    {
        $policy = new MemoryLayerPolicy(new MemoryTextSanitizer());

        $decision = $policy->classifyUserText('记住这个文档链接：https://example.com/doc/123', true);

        $this->assertTrue($decision['store_l2']);
        $this->assertFalse($decision['promote_l3']);
    }

    public function testClassifyAssistantAnswerRejectsClarificationNoise(): void
    {
        $policy = new MemoryLayerPolicy(new MemoryTextSanitizer());

        $decision = $policy->classifyAssistantAnswer('麻烦你补充一下标题、时间和链接，我才能继续处理。', ['东方']);

        $this->assertFalse($decision['store_l2']);
        $this->assertFalse($decision['promote_l3']);
        $this->assertSame('noise', $decision['kind']);
    }

    public function testReviewFactRejectsPollutedAssistantGreeting(): void
    {
        $policy = new MemoryLayerPolicy(new MemoryTextSanitizer());

        $review = $policy->reviewFact('王林您好，我已经帮你创建好市场文档了。', 'constraint', ['priority' => 80]);

        $this->assertFalse($review['allow']);
        $this->assertSame('greeting_like', $review['reason']);
    }

    public function testReviewFactRejectsOneShotTaskInstruction(): void
    {
        $policy = new MemoryLayerPolicy(new MemoryTextSanitizer());

        $review = $policy->reviewFact('帮我读取一下这份会议纪要并总结重点。', 'project_anchor', ['priority' => 80]);

        $this->assertFalse($review['allow']);
        $this->assertSame('task_instruction', $review['reason']);
    }

    public function testReviewFactRejectsUrlPayload(): void
    {
        $policy = new MemoryLayerPolicy(new MemoryTextSanitizer());

        $review = $policy->reviewFact('这是长期文档：https://example.com/wiki/abc', 'project_anchor', ['priority' => 80]);

        $this->assertFalse($review['allow']);
        $this->assertSame('contains_url', $review['reason']);
    }

    public function testReviewFactKeepsStablePreference(): void
    {
        $policy = new MemoryLayerPolicy(new MemoryTextSanitizer());

        $review = $policy->reviewFact('请用中文回复我，少一点套话。', 'preference', ['priority' => 88]);

        $this->assertTrue($review['allow']);
        $this->assertSame('preference', $review['category']);
    }
}
