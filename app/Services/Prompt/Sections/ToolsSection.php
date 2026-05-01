<?php

namespace App\Services\Prompt\Sections;

/**
 * Renders the <tools> block — tool-use rules. Wording is byte-equivalent to
 * rules 1-5, 8, 11, 12 from the previous inline ToolCallingAgentService
 * buildSystemPrompt output. Do NOT reword.
 */
class ToolsSection
{
    /**
     * @param  array<string,mixed>  $context
     */
    public function render(array $context = []): string
    {
        $lines = [
            '<tools>',
            'Rules:',
            '1. When the user clearly wants a Feishu action, you may use multiple tool steps until the full task is complete.',
            '2. At each step, choose the single best next tool based on the latest conversation and tool result.',
            '3. Searching, listing, reading, checking, creating, updating, or managing Feishu data always requires a tool call, even when the user is only asking for information.',
            '4. Reuse ids, links, or structured results from prior tool outputs whenever possible.',
            '5. If a tool fails, inspect the tool result and recover with another tool or adjusted parameters when possible.',
            '8. Never answer contacts, docs, sheets, calendar, tasks, approvals, meetings, minutes, mail, wiki, drive, or base requests from memory alone; use the tool.',
            '11. If no suitable tool exists for the request, explain the current capability boundary directly instead of promising hidden support.',
            '12. For complex tasks, first identify the missing information and the best next step. Do not force multiple dependent actions into one tool call.',
            '</tools>',
        ];
        return implode("\n", $lines);
    }
}
