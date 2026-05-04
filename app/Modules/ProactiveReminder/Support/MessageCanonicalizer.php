<?php

namespace App\Modules\ProactiveReminder\Support;

final class MessageCanonicalizer
{
    public function normalize(string $text): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($text));
        $normalized = preg_replace('/https?:\/\/\S+/u', '', $normalized) ?? $normalized;
        $normalized = str_replace(
            ['，', '。', '！', '？', '：', '；', '（', '）', '【', '】', '、', '～'],
            [',', '.', '!', '?', ':', ';', '(', ')', '[', ']', ',', '~'],
            $normalized
        );
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return mb_strtolower(trim($normalized), 'UTF-8');
    }

    public function hash(string $text): ?string
    {
        $normalized = $this->normalize($text);

        return $normalized === '' ? null : hash('sha256', $normalized);
    }
}
