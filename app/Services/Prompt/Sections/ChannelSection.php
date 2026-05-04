<?php

namespace App\Services\Prompt\Sections;

/**
 * Single hardcoded Feishu/Lark channel hint. Later we can swap in
 * per-channel strings (e.g. DingTalk, WeCom) once we support them.
 */
class ChannelSection
{
    /**
     * @param  array<string,mixed>  $context
     */
    public function render(array $context = []): string
    {
        $body = 'You are operating inside Feishu (Lark). Use Feishu APIs via tool calls for contacts, docs, sheets, calendar, tasks, approvals, meetings, minutes, mail, wiki, drive, and base.';
        return "<channel>\n".$body."\n</channel>";
    }
}
