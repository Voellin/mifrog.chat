<?php

namespace Tests\Unit\Services\Memory;

use App\Services\Memory\MemoryKeywordExtractor;
use PHPUnit\Framework\TestCase;

class MemoryKeywordExtractorTest extends TestCase
{
    public function testExtractFiltersLowSignalTaskWords(): void
    {
        $extractor = new MemoryKeywordExtractor();

        $keywords = $extractor->extract('帮我创建一个会议，分析这个文档，再总结一下。');

        $this->assertNotContains('帮我', $keywords);
        $this->assertNotContains('创建', $keywords);
        $this->assertNotContains('一个', $keywords);
    }

    public function testExtractKeepsEntityLikeTerms(): void
    {
        $extractor = new MemoryKeywordExtractor();

        $keywords = $extractor->extract('请分析蕉下和用户A在秋冬新品项目里的重点。');

        $this->assertContains('蕉下', $keywords);
        $this->assertContains('用户A', $keywords);
        $this->assertTrue(collect($keywords)->contains(
            fn (string $keyword): bool => str_contains($keyword, '秋冬') || str_contains($keyword, '新品')
        ));
    }
}
