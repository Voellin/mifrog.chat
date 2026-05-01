<?php

namespace App\Support;

/**
 * Provides UTF-8 sanitization for strings received from external sources.
 * Use in services that process data from Feishu webhooks, CLI output, etc.
 */
trait SanitizesUtf8
{
    protected function sanitizeUtf8(string $value): string
    {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        $value = str_replace("\0", '', $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;

        return $value;
    }

    protected function sanitizeUtf8Array(array $data): array
    {
        return array_map(function ($value) {
            if (is_array($value)) {
                return $this->sanitizeUtf8Array($value);
            }

            if (is_string($value)) {
                return $this->sanitizeUtf8($value);
            }

            return $value;
        }, $data);
    }
}
