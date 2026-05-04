<?php

namespace Tests\Unit\Services\Memory;

use App\Services\Memory\MemoryTextSanitizer;
use PHPUnit\Framework\TestCase;

class MemoryTextSanitizerTest extends TestCase
{
    public function testSanitizeAssistantReplyRemovesGreetingAndFollowUpOffer(): void
    {
        $sanitizer = new MemoryTextSanitizer();

        $text = "王林您好呀，已经帮你创建好营销会议文档啦。\n链接是 https://example.com/doc\n要不要我再继续补充竞品分析？";

        $result = $sanitizer->sanitizeAssistantReply($text, ['东方']);

        $this->assertStringNotContainsString('王林', $result);
        $this->assertStringNotContainsString('要不要我', $result);
        $this->assertStringContainsString('已经帮你创建好营销会议文档啦。', $result);
    }

    public function testSummarizeForMemoryReturnsConciseFirstSentence(): void
    {
        $sanitizer = new MemoryTextSanitizer();

        $summary = $sanitizer->summarizeForMemory("已经创建好Q2周报文档。\n如果需要我可以继续补充数据。");

        $this->assertSame('已经创建好Q2周报文档。', $summary);
    }
}
