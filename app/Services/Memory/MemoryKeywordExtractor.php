<?php

namespace App\Services\Memory;

use Illuminate\Support\Collection;

class MemoryKeywordExtractor
{
    private const STOPWORDS = [
        '帮我', '请你', '请', '麻烦', '劳烦', '给我', '替我',
        '创建', '新建', '读取', '看看', '分析', '总结', '搜索', '查询', '写入', '追加', '更新', '安排', '添加', '删除', '同步', '导出', '发送', '打开', '提炼', '整理',
        '一个', '这个', '那个', '一下', '可以', '一下子', '现在', '刚刚', '今天', '明天', '后天',
        '我们', '你们', '他们', '她们', '这里', '那里', '就是', '还是', '然后', '还有', '已经',
        'please', 'help', 'create', 'read', 'analyze', 'summary',
    ];

    private const LOW_SIGNAL_PATTERNS = [
        '/^(帮我|请你|请|麻烦|劳烦|给我|替我)$/u',
        '/^(创建|新建|读取|看看|分析|总结|搜索|查询|写入|追加|更新|安排|添加|删除|同步|导出|发送|打开|提炼|整理)$/u',
        '/^(一个|这个|那个|一下|可以|现在|刚刚|今天|明天|后天)$/u',
    ];

    /**
     * @return array<int, string>
     */
    public function extract(string $text, int $limit = 24): array
    {
        $normalized = $this->normalize($text);
        if ($normalized === '') {
            return [];
        }

        $keywords = [];

        preg_match_all('/[a-z0-9][a-z0-9._:-]{1,31}/iu', $normalized, $latinMatches);
        foreach ((array) ($latinMatches[0] ?? []) as $token) {
            $token = trim(mb_strtolower((string) $token, 'UTF-8'), '._:-');
            if ($token !== '' && mb_strlen($token, 'UTF-8') >= 2) {
                $keywords[] = $token;
            }
        }

        preg_match_all('/\p{Han}+/u', $normalized, $cjkMatches);
        foreach ((array) ($cjkMatches[0] ?? []) as $segment) {
            $segment = trim((string) $segment);
            if ($segment === '') {
                continue;
            }

            $length = mb_strlen($segment, 'UTF-8');
            if ($length <= 4) {
                $keywords[] = $segment;
            }

            foreach ([2, 3, 4] as $gram) {
                if ($length < $gram) {
                    continue;
                }

                for ($i = 0; $i <= $length - $gram; $i++) {
                    $keywords[] = mb_substr($segment, $i, $gram, 'UTF-8');
                }
            }
        }

        return Collection::make($keywords)
            ->map(fn ($token) => trim((string) $token))
            ->filter(fn ($token) => $token !== '')
            ->reject(fn ($token) => $this->isStopword($token))
            ->unique()
            ->take(max(4, $limit))
            ->values()
            ->all();
    }

    public function containsCjk(string $text): bool
    {
        return preg_match('/\p{Han}/u', $text) === 1;
    }

    private function isStopword(string $token): bool
    {
        $token = trim(mb_strtolower($token, 'UTF-8'));
        if ($token === '') {
            return true;
        }

        if (in_array($token, self::STOPWORDS, true)) {
            return true;
        }

        foreach (self::LOW_SIGNAL_PATTERNS as $pattern) {
            if (preg_match($pattern, $token) === 1) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $text): string
    {
        $text = trim(mb_strtolower($text, 'UTF-8'));
        $text = preg_replace('/https?:\/\/\S+/iu', ' ', $text) ?? $text;
        $text = preg_replace('/[^\p{L}\p{N}\p{Han}\s._:-]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
