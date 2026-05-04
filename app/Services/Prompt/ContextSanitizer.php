<?php

namespace App\Services\Prompt;

/**
 * Defensive scrubber for non-user content (historical assistant text, tool
 * results, knowledge snippets) before it gets fed back into the LLM. Strips
 * known prompt-injection patterns, HTML comments, hidden divs, and invisible
 * unicode formatting controls. User messages are left untouched — they
 * represent the current ask and must not be mutated.
 */
class ContextSanitizer
{
    /**
     * @var list<string>
     */
    private const PATTERNS = [
        // "ignore previous instructions" family
        '/ignore\s+(previous|above|prior)\s+instructions?/i',
        // "disregard your rules"
        '/disregard\s+(your|the|previous)\s+(rules?|instructions?)/i',
        // HTML comments
        '/<!--[\s\S]*?-->/',
        // hidden divs (attribute-based)
        '/<div[^>]*(?:hidden|style="display:\s*none")[^>]*>[\s\S]*?<\/div>/i',
        // Invisible unicode: zero-width space/joiner/non-joiner/LRM/RLM,
        // bidi overrides (LRE..PDF), bidi isolates (LRI..PDI), BOM.
        '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u',
    ];

    public function sanitize(string $content): string
    {
        if ($content === '') {
            return '';
        }

        foreach (self::PATTERNS as $pattern) {
            $result = preg_replace($pattern, '', $content);
            if (is_string($result)) {
                $content = $result;
            }
        }

        return $content;
    }

    /**
     * Apply sanitize() to every non-user row. User rows pass through because
     * they are the current ask; altering them could drop legitimate text the
     * user actually typed.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    public function sanitizeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                $out[] = $row;
                continue;
            }
            $role = (string) ($row['role'] ?? '');
            if ($role !== 'user' && isset($row['content']) && is_string($row['content'])) {
                $row['content'] = $this->sanitize($row['content']);
            }
            $out[] = $row;
        }
        return $out;
    }
}
