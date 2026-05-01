<?php

namespace App\Services\Prompt\Sections;

/**
 * Emits the <runtime> block — time context and any run-level metadata the
 * caller wants surfaced to the model. Empty if no context supplied.
 */
class RuntimeSection
{
    /**
     * @param  array{time_context?: string, run_metadata?: array<string,mixed>}  $context
     */
    public function render(array $context = []): string
    {
        $lines = [];
        $time = trim((string) ($context['time_context'] ?? ''));
        if ($time !== '') {
            $lines[] = $time;
        }

        $meta = $context['run_metadata'] ?? [];
        if (is_array($meta)) {
            foreach ($meta as $k => $v) {
                if (is_scalar($v) && (string) $v !== '') {
                    $lines[] = $k.': '.$v;
                }
            }
        }

        if ($lines === []) {
            return '';
        }

        return "<runtime>\n".implode("\n", $lines)."\n</runtime>";
    }
}
