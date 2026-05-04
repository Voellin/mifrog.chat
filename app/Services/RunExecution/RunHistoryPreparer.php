<?php

namespace App\Services\RunExecution;

use App\Models\Run;
use App\Models\Setting;
use App\Services\HistoryRedactor;
use App\Services\MemoryService;
use App\Services\Prompt\ContextSanitizer;
use Illuminate\Support\Collection;
use Throwable;

/**
 * History preparer extracted from RunExecutionService.
 *
 * Responsibilities:
 * - Load the trailing conversation window the run should consider
 *   (default 40, cut off at trigger_message_id if present)
 * - Compress that window for LLM prompting using the three-tier fallback:
 *   (1) per-user session summary (10d) + 3-message tail when long enough
 *   (2) HistoryRedactor placeholderization (10a)
 *   (3) original rows
 *   — then always pass through ContextSanitizer (10b) when available.
 *
 * Behavior contract (preserved verbatim from the inline version):
 * - The session-summary branch only kicks in when user+assistant turns > 5
 *   AND user_id/run_id are valid — this is the per-user isolation invariant.
 * - Summary fetch failures degrade silently to the redactor branch.
 * - If neither redactor nor sanitizer is wired, original rows pass through.
 */
class RunHistoryPreparer
{
    public function __construct(
        private readonly MemoryService $memoryService,
        private readonly ?HistoryRedactor $historyRedactor = null,
        private readonly ?ContextSanitizer $contextSanitizer = null,
    ) {
    }

    public function load(Run $run): Collection
    {
        $meta = is_array($run->intent_meta) ? $run->intent_meta : [];
        $triggerMessageId = (int) ($meta['trigger_message_id'] ?? 0);

        // Hard window filter: only consider recent messages when compressing history.
        // Drives the "0-message means 0-message" invariant — stale cross-day tasks
        // must never leak back into the prompt. L2/L3 recall handles truly relevant
        // older context via its own scoring path.
        $hours = (int) Setting::read('memory.history_window_hours', 24);
        $hours = max(1, min(720, $hours));
        $cutoff = now()->subHours($hours);

        $query = $run->conversation->messages()
            ->where('created_at', '>=', $cutoff)
            ->orderByDesc('id');
        if ($triggerMessageId > 0) {
            $query->where('id', '<=', $triggerMessageId);
        }

        return $query->take(40)->get()->reverse()->values();
    }

    /**
     * @param  list<array{role:string,content:string,meta?:array<string,mixed>}>  $originalRows
     * @return list<array{role:string,content:string,meta?:array<string,mixed>}>
     */
    public function compress(Run $run, array $originalRows): array
    {
        if ($originalRows === []) {
            return $originalRows;
        }

        $userId = (int) ($run->user_id ?? 0);
        $runId = (int) ($run->id ?? 0);

        $assistantCount = 0;
        $userCount = 0;
        foreach ($originalRows as $r) {
            $role = (string) ($r['role'] ?? '');
            if ($role === 'assistant') {
                $assistantCount++;
            } elseif ($role === 'user') {
                $userCount++;
            }
        }

        $compressed = null;

        if (($assistantCount + $userCount) > 5 && $userId > 0 && $runId > 0) {
            try {
                $summary = $this->memoryService->getOrBuildSessionSummary(
                    $userId,
                    $runId,
                    $originalRows
                );
            } catch (Throwable) {
                $summary = null;
            }

            if (is_string($summary) && trim($summary) !== '') {
                $tail = array_slice($originalRows, -3);
                $compressed = array_merge(
                    [[
                        'role' => 'system',
                        'content' => "[Compressed history summary]\n" . $summary,
                    ]],
                    $tail
                );
            }
        }

        if ($compressed === null) {
            if ($this->historyRedactor !== null) {
                $compressed = $this->historyRedactor->redactHistory($originalRows);
            } else {
                $compressed = $originalRows;
            }
        }

        if ($this->contextSanitizer !== null) {
            $compressed = $this->contextSanitizer->sanitizeRows($compressed);
        }

        $out = [];
        foreach ($compressed as $row) {
            if (! is_array($row)) {
                continue;
            }
            $entry = [
                'role' => (string) ($row['role'] ?? ''),
                'content' => (string) ($row['content'] ?? ''),
            ];
            if (isset($row['meta']) && is_array($row['meta']) && $row['meta'] !== []) {
                $entry['meta'] = $row['meta'];
            }
            $out[] = $entry;
        }
        return $out;
    }
}
