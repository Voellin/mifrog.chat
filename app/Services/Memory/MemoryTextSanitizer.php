<?php

namespace App\Services\Memory;

class MemoryTextSanitizer
{
    /**
     * @param  array<int, string>  $userAliases
     */
    public function sanitizeAssistantReply(string $text, array $userAliases = []): string
    {
        $text = $this->normalize($text);
        if ($text === '') {
            return '';
        }

        $lines = preg_split('/\n+/u', $text) ?: [];
        $kept = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $line = $this->stripGreetingPrefix($line, $userAliases);
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if ($this->isAssistantOfferLine($line)) {
                continue;
            }

            $line = preg_replace('/https?:\/\/\S+/iu', '', $line) ?? $line;
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($line === '') {
                continue;
            }

            $kept[] = $line;
        }

        $result = trim(implode("\n", array_slice($kept, 0, 4)));
        $result = preg_replace('/\n{3,}/u', "\n\n", (string) $result) ?? $result;

        return trim((string) $result);
    }

    public function summarizeForMemory(string $text, int $maxLength = 220): string
    {
        $text = $this->sanitizeAssistantReply($text);
        if ($text === '') {
            return '';
        }

        $summary = preg_split('/(?<=[。！？.!?])\s*/u', $text) ?: [$text];
        $summary = trim((string) ($summary[0] ?? $text));

        if (mb_strlen($summary, 'UTF-8') <= $maxLength) {
            return $summary;
        }

        return mb_substr($summary, 0, $maxLength - 1, 'UTF-8').'…';
    }

    /**
     * @param  array<int, string>  $userAliases
     */
    public function isGreetingLine(string $line, array $userAliases = []): bool
    {
        $line = trim($line);
        if ($line === '') {
            return false;
        }

        if (preg_match('/^(你好|您好|嗨|哈喽|早上好|中午好|晚上好)[呀啊哦～!！,.， ]*/u', $line) === 1) {
            return true;
        }

        foreach ($userAliases as $alias) {
            $alias = trim((string) $alias);
            if ($alias === '') {
                continue;
            }

            $quoted = preg_quote($alias, '/');
            if (preg_match('/^'.$quoted.'(你好|您好|好呀|好呀～|好呀哦|您好呀|你好呀|歇会儿呀|歇会儿吧)?[呀啊哦～!！,.， ]*/u', $line) === 1) {
                return true;
            }
        }

        if (preg_match('/^[\p{Han}]{2,4}(你好|您好|好呀|您好呀|你好呀|歇会儿呀)[呀啊哦～!！,.， ]*/u', $line) === 1) {
            return true;
        }

        return false;
    }

    public function isAssistantOfferLine(string $line): bool
    {
        $patterns = [
            '/^(要不要|是否需要|如果需要|随时跟我说|欢迎继续|麻烦你|请告诉我|请补充|确认一下)/u',
            '/(要不要我|我可以继续|随时跟我说|请补充一下|还需要几个小细节|还缺几个小细节)/u',
            '/\?$|？$/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $userAliases
     */
    private function stripGreetingPrefix(string $line, array $userAliases = []): string
    {
        $patterns = [
            '/^(你好|您好|嗨|哈喽|早上好|中午好|晚上好)[呀啊哦～!！,.， ]*/u',
            '/^[\p{Han}]{2,4}(你好|您好|好呀|您好呀|你好呀|歇会儿呀)[呀啊哦～!！,.， ]*/u',
        ];

        foreach ($userAliases as $alias) {
            $alias = trim((string) $alias);
            if ($alias === '') {
                continue;
            }

            $patterns[] = '/^'.preg_quote($alias, '/').'(你好|您好|好呀|好呀～|好呀哦|您好呀|你好呀|歇会儿呀)?[呀啊哦～!！,.， ]*/u';
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return trim((string) preg_replace($pattern, '', $line, 1));
            }
        }

        return $line;
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
