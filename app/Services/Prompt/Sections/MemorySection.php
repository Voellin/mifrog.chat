<?php

namespace App\Services\Prompt\Sections;

/**
 * Renders the <memory> and <recent_references> fences. Both are optional —
 * if the caller did not provide content, this section emits the empty string
 * and the PromptComposer filters it out.
 */
class MemorySection
{
    /**
     * @param  array{memory_context?: string, recent_references?: string|array<string,string>}  $context
     */
    public function render(array $context = []): string
    {
        $parts = [];

        $memory = (string) ($context['memory_context'] ?? '');
        if (trim($memory) !== '') {
            $parts[] = "<memory>\n".rtrim($memory)."\n</memory>";
        }

        $recent = $context['recent_references'] ?? '';
        $recentText = '';
        if (is_array($recent)) {
            $lines = [];
            foreach ($recent as $label => $value) {
                $lines[] = '- '.$label.': '.$value;
            }
            $recentText = implode("\n", $lines);
        } else {
            $recentText = (string) $recent;
        }

        if (trim($recentText) !== '') {
            $parts[] = "<recent_references>\n".rtrim($recentText)."\n</recent_references>";
        }

        return implode("\n\n", $parts);
    }
}
