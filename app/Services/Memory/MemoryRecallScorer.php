<?php

namespace App\Services\Memory;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class MemoryRecallScorer
{
    public function __construct(
        private readonly MemoryKeywordExtractor $keywordExtractor,
        private readonly MemoryLayerPolicy $layerPolicy,
    ) {
    }

    /**
     * @param  array<int, string>  $queryKeywords
     * @param  array<int, string>  $memoryKeywords
     * @param  array<int, string>  $tags
     */
    public function scoreEntry(
        string $query,
        array $queryKeywords,
        string $content,
        array $memoryKeywords = [],
        array $tags = [],
        ?string $createdAt = null
    ): float {
        $query = trim(mb_strtolower($query, 'UTF-8'));
        $content = trim(mb_strtolower($content, 'UTF-8'));

        if ($query === '' || $content === '') {
            return 0.0;
        }

        if ($this->layerPolicy->isNoiseForPrompt($content)) {
            return 0.0;
        }

        $score = 0.05;
        $memoryKeywords = $memoryKeywords !== [] ? $memoryKeywords : $this->keywordExtractor->extract($content, 32);

        foreach ($queryKeywords as $keyword) {
            if (in_array($keyword, $memoryKeywords, true)) {
                $length = mb_strlen($keyword, 'UTF-8');
                $score += $length >= 4 ? 1.1 : ($length >= 3 ? 0.8 : 0.35);
                continue;
            }

            if (mb_strpos($content, $keyword) !== false) {
                $length = mb_strlen($keyword, 'UTF-8');
                $score += $length >= 4 ? 0.75 : ($length >= 3 ? 0.55 : 0.2);
            }
        }

        foreach ($tags as $tag) {
            if (str_starts_with($tag, 'source:user')) {
                $score += 0.25;
            }
            if (str_starts_with($tag, 'kind:durable_user_fact')) {
                $score += 0.4;
            }
            if (str_starts_with($tag, 'kind:episodic_result')) {
                $score += 0.1;
            }
            if (str_starts_with($tag, 'ttl:1')) {
                $score -= 0.55;
            }
            if (str_starts_with($tag, 'ttl:5')) {
                $score -= 0.15;
            }
        }

        if ($createdAt !== null) {
            $score *= $this->recencyWeight($createdAt);
        }

        return round(max(0.0, $score), 4);
    }

    /**
     * @param  array<int, string>  $queryKeywords
     * @param  array<string, mixed>  $meta
     */
    public function scoreFact(
        string $query,
        array $queryKeywords,
        string $fact,
        string $category,
        int $priority,
        array $meta = [],
        ?string $updatedAt = null
    ): float {
        $fact = trim(mb_strtolower($fact, 'UTF-8'));
        if ($fact === '' || $this->layerPolicy->isNoiseForPrompt($fact)) {
            return 0.0;
        }

        $score = max(0.3, $priority / 100);

        $factKeywords = $this->keywordExtractor->extract($fact, 32);
        foreach ($queryKeywords as $keyword) {
            if (in_array($keyword, $factKeywords, true) || mb_strpos($fact, $keyword) !== false) {
                $score += mb_strlen($keyword, 'UTF-8') >= 3 ? 1.15 : 0.7;
            }
        }

        if (in_array($category, ['identity', 'preference', 'constraint', 'style', 'project_anchor', 'work_context'], true)) {
            $score += 0.35;
        }

        $recallCount = (int) Arr::get($meta, 'recall_count', 0);
        $uniqueQueries = (int) Arr::get($meta, 'unique_query_count', 0);
        $score += min(1.0, $recallCount * 0.10);
        $score += min(0.7, $uniqueQueries * 0.15);

        if ($updatedAt !== null) {
            $score *= $this->recencyWeight($updatedAt, 28);
        }

        return round(max(0.0, $score), 4);
    }

    private function recencyWeight(string $timestamp, int $halfLifeDays = 21): float
    {
        try {
            $ageDays = max(0, Carbon::parse($timestamp)->diffInDays(now()));
        } catch (\Throwable) {
            return 1.0;
        }

        return max(0.35, 1 / (1 + ($ageDays / max(1, $halfLifeDays))));
    }
}
