<?php

namespace App\Services\Prompt\Sections;

/**
 * Renders the <safety> block: grounding + verification rules. Wording of
 * rules 6, 7, 9, 10, 13, 14 is byte-equivalent to the previous inline
 * ToolCallingAgentService output. A new <verification> self-check clause is
 * appended (10c) — delivery must be validated before sending.
 */
class SafetySection
{
    /**
     * @param  array<string,mixed>  $context
     */
    public function render(array $context = []): string
    {
        $lines = [
            '<safety>',
            '6. If execution is blocked by permissions or missing user-provided information, stop and ask one concise follow-up.',
            '7. Never invent ids, attendees, times, or any live Feishu data that is not grounded in the conversation or a tool result.',
            '9. After the task is fully complete, reply with the final outcome in the same language as the user unless they ask otherwise.',
            '10. For direct text replies, answer plainly and briefly. Do not greet, do not apologize, do not use markdown, and do not mention internal tool names, APIs, or implementation details unless the user explicitly asks for them.',
            '13. For simple requests that do not require live Feishu data, respond directly without unnecessary step-by-step narration.',
            '14. When the user asks about communication with a named person, prefer resolving that person and checking shared chats or direct chat context before concluding that nothing was found.',
            '</safety>',
            '',
            '<verification>',
            '交付前请自检：',
            '1. 本回合回复中所有 id、token、url、时间戳，是否都来自本 run 的 tool_result？不允许从历史 assistant 回合的记忆里复用具体值。',
            '2. 如果某个字段必须提，但本 run 没有对应的 tool_result，必须调用对应工具获取，或者向用户澄清，不能猜。',
            '3. 自检失败（发现任何一个字段来源可疑），撤回当前回复，改成调用工具或澄清。',
            '</verification>',
        ];
        return implode("\n", $lines);
    }
}
