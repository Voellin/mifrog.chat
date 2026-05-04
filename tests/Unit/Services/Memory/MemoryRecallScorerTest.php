<?php

namespace Tests\Unit\Services\Memory;

use App\Services\Memory\MemoryKeywordExtractor;
use App\Services\Memory\MemoryLayerPolicy;
use App\Services\Memory\MemoryRecallScorer;
use App\Services\Memory\MemoryTextSanitizer;
use PHPUnit\Framework\TestCase;

class MemoryRecallScorerTest extends TestCase
{
    public function testTopicMatchedOldProjectOutscoresRecentUnrelatedLifeDetail(): void
    {
        $extractor = new MemoryKeywordExtractor();
        $policy = new MemoryLayerPolicy(new MemoryTextSanitizer());
        $scorer = new MemoryRecallScorer($extractor, $policy);
        $queryKeywords = $extractor->extract('继续做防晒衣市场大盘');

        $projectScore = $scorer->scoreEntry(
            '继续做防晒衣市场大盘',
            $queryKeywords,
            '继续推进防晒衣市场大盘和营销会议输入文档',
            $extractor->extract('继续推进防晒衣市场大盘和营销会议输入文档'),
            ['source:user', 'kind:episodic_context'],
            now()->subDays(45)->toDateTimeString()
        );

        $breakfastScore = $scorer->scoreEntry(
            '继续做防晒衣市场大盘',
            $queryKeywords,
            '我今天早饭吃了包子和豆浆',
            $extractor->extract('我今天早饭吃了包子和豆浆'),
            ['source:user', 'kind:transient_detail', 'ttl:1'],
            now()->subDay()->toDateTimeString()
        );

        $this->assertGreaterThan($breakfastScore, $projectScore);
        $this->assertGreaterThan(0.7, $projectScore);
    }

    public function testDurableFactGetsBoostFromRecallSignals(): void
    {
        $extractor = new MemoryKeywordExtractor();
        $policy = new MemoryLayerPolicy(new MemoryTextSanitizer());
        $scorer = new MemoryRecallScorer($extractor, $policy);
        $queryKeywords = $extractor->extract('中文输出');

        $score = $scorer->scoreFact(
            '中文输出',
            $queryKeywords,
            '用户偏好中文回复，风格简洁',
            'preference',
            88,
            ['recall_count' => 4, 'unique_query_count' => 3],
            now()->subDays(10)->toDateTimeString()
        );

        $this->assertGreaterThan(2.0, $score);
    }
}
