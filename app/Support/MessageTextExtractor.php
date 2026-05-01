<?php

namespace App\Support;

class MessageTextExtractor
{
    /**
     * @param  array<int,array<string,mixed>>  $messages
     */
    public static function latestUserText(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                return trim((string) ($messages[$i]['content'] ?? ''));
            }
        }

        return '';
    }
}
