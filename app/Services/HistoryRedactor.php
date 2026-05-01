<?php

namespace App\Services;

/**
 * Replaces concrete identifiers (ids, tokens, urls, timestamps) in older
 * assistant history messages with opaque placeholders so the LLM cannot copy
 * them verbatim into new output. The most recent assistant turns are left
 * unmodified so legitimate follow-up references (e.g. "open that doc") still
 * work. User-role messages are never touched.
 */
class HistoryRedactor
{
    /** keep this many most-recent assistant turns unredacted */
    public const KEEP_RECENT_ASSISTANT_TURNS = 2;

    /**
     * Ordered list of (regex, placeholder_prefix) pairs. Order matters: more
     * specific patterns are listed first so their matches consume the text
     * before broader catch-all patterns (like the generic URL) run.
     *
     * @var list<array{0:string,1:string}>
     */
    private const PATTERNS = [
        // Feishu event_id
        ['/\bevent_[A-Za-z0-9_-]{8,}\b/', 'prev_event_id'],
        // calendar_id
        ['/\bcal_[A-Za-z0-9_-]{8,}\b/', 'prev_calendar_id'],
        // doc_token / doccn* / docx tokens
        ['/\b(doc[a-z]{2,3}[A-Za-z0-9]{10,})\b/', 'prev_doc_token'],
        // sheet_token
        ['/\b(shtcn[A-Za-z0-9]{8,})\b/', 'prev_sheet_token'],
        // spreadsheet_token
        ['/\b(sheet_[A-Za-z0-9_-]{8,})\b/', 'prev_spreadsheet_token'],
        // wiki token
        ['/\b(wik[a-z]{2,3}[A-Za-z0-9]{10,})\b/', 'prev_wiki_token'],
        // bitable / base app_token
        ['/\b(bascn[A-Za-z0-9]{10,})\b/', 'prev_base_app_token'],
        // chat_id
        ['/\b(oc_[A-Za-z0-9]{10,})\b/', 'prev_chat_id'],
        // message_id
        ['/\b(om_[A-Za-z0-9]{10,})\b/', 'prev_message_id'],
        // open_id
        ['/\b(ou_[A-Za-z0-9]{10,})\b/', 'prev_open_id'],
        // union_id
        ['/\b(on_[A-Za-z0-9]{10,})\b/', 'prev_union_id'],
        // Feishu / Lark URL (must come before the generic URL pattern)
        ['#https?://[\w.-]*(?:feishu|larksuite)\.\w+/[^\s<>"\x27]+#', 'prev_feishu_url'],
        // Generic url (catch-all)
        ['#https?://[^\s<>"\x27]+#', 'prev_url'],
        // Email
        ['/\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b/', 'prev_email'],
        // ISO 8601 timestamp
        ['/\b\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:[.]\d+)?(?:Z|[+-]\d{2}:?\d{2})?\b/', 'prev_iso_time'],
        // Chinese day-time phrases (e.g. 今天下午3点, 周五15:30)
        ['/(今天|明天|后天|昨天|下周[一二三四五六日天]?|周[一二三四五六日天])(?:[上下]午)?\d{1,2}[:：点时]\d{0,2}(?:分)?/u', 'prev_cn_time'],
    ];

    /**
     * Redact older assistant content in a chronological list of rows.
     *
     * @param  list<array<string,mixed>>  $rows  chronological rows with at least role + content keys
     * @return list<array<string,mixed>>  redacted rows in the same order and shape
     */
    public function redactHistory(array $rows): array
    {
        // 1. Collect the indices of all assistant messages in order.
        $assistantIndices = [];
        foreach ($rows as $idx => $row) {
            if (! is_array($row)) {
                continue;
            }
            $role = (string) ($row['role'] ?? '');
            if ($role === 'assistant') {
                $assistantIndices[] = $idx;
            }
        }

        // 2. Mark the last KEEP_RECENT_ASSISTANT_TURNS assistant indices as "keep".
        $total = count($assistantIndices);
        $keepFrom = max(0, $total - self::KEEP_RECENT_ASSISTANT_TURNS);
        $keepIndices = array_slice($assistantIndices, $keepFrom);
        $keepSet = array_flip($keepIndices);

        // 3. Walk the rows, redacting older assistant content.
        $out = [];
        foreach ($rows as $idx => $row) {
            if (! is_array($row)) {
                $out[] = $row;
                continue;
            }
            $role = (string) ($row['role'] ?? '');
            if ($role === 'assistant' && ! isset($keepSet[$idx])) {
                $content = (string) ($row['content'] ?? '');
                $row['content'] = $this->redactContent($content);
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Apply all redaction patterns to a single piece of text. Each prefix has
     * its own local counter so repeated ids in the same content yield
     * sequential placeholders (_1, _2, ...).
     */
    public function redactContent(string $content): string
    {
        if ($content === '') {
            return '';
        }

        foreach (self::PATTERNS as [$regex, $prefix]) {
            $counter = 0;
            $result = preg_replace_callback(
                $regex,
                function () use (&$counter, $prefix): string {
                    $counter++;
                    return '<'.$prefix.'_'.$counter.'>';
                },
                $content
            );
            if (is_string($result)) {
                $content = $result;
            }
        }

        return $content;
    }
}
