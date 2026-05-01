<?php

namespace App\Modules\ProactiveReminder\Sources;

use App\Modules\ProactiveReminder\Contracts\ActivitySourceInterface;
use App\Modules\ProactiveReminder\DTO\ActivityItem;
use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use App\Modules\ProactiveReminder\DTO\SourceCollectionResult;
use App\Modules\ProactiveReminder\Support\ActivityTimeParser;
use App\Services\FeishuCliClient;
use Illuminate\Support\Facades\Log;
use Throwable;

class TaskActivitySource implements ActivitySourceInterface
{
    public function __construct(
        private readonly FeishuCliClient $cliClient,
        private readonly ActivityTimeParser $timeParser,
    ) {
    }

    public function supports(ReminderScanRequest $request): bool
    {
        return $request->collectionMode === 'full';
    }

    public function collect(ReminderScanRequest $request, array $feishuConfig): array
    {
        try {
            // lark-cli 没有 'task +list'——用 'task +get-my-tasks' 替代。
            // --page-all + --page-limit 拉到完整未完成任务列表。
            $raw = $this->cliClient->runSkillCommand($feishuConfig, '', [
                'task', '+get-my-tasks',
                '--page-all',
                '--page-limit', '5',
                '--format', 'json',
            ], 'user', $request->openId);
        } catch (Throwable $e) {
            Log::warning('[TaskActivitySource] CLI call failed', [
                'user_id' => $request->userId,
                'error' => $e->getMessage(),
            ]);
            return [new SourceCollectionResult('tasks', [], [])];
        }

        $records = [];
        $items = [];
        $tasks = (array) ($raw['data']['items'] ?? $raw['data']['tasks'] ?? $raw['data'] ?? []);

        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }

            $summary = trim((string) ($task['summary'] ?? $task['title'] ?? ''));
            if ($summary === '') {
                continue;
            }

            $dueRaw = $task['due'] ?? $task['due_date'] ?? '';
            $due = is_array($dueRaw) ? (string) ($dueRaw['datetime'] ?? ($dueRaw['date'] ?? '')) : (string) $dueRaw;

            $createdAt = (string) ($task['created_at'] ?? $task['create_time'] ?? '');
            $completedAt = (string) ($task['completed_at'] ?? $task['complete_time'] ?? '');
            $status = trim((string) ($task['status'] ?? ''));

            // Determine if this task is relevant to the requested window.
            // A task is "relevant" when:
            //   - it was created within [since, until], or
            //   - it was completed within [since, until], or
            //   - its due date falls within [since, until+1day] (include tasks due soon after window).
            // Tasks that fail all three are skipped — otherwise we pollute the summary
            // with unrelated pending tasks that had no activity in the window.
            $isCompleted = $completedAt !== '' || strtolower($status) === 'completed';
            $isCreatedInWindow = $createdAt !== ''
                && $this->timeParser->parse($createdAt)?->between($request->since, $request->until);
            $isCompletedInWindow = $completedAt !== ''
                && $this->timeParser->parse($completedAt)?->between($request->since, $request->until);
            $isDueInWindow = $due !== ''
                && $this->timeParser->parse($due)?->between($request->since, $request->until->addDay());

            if (!$isCreatedInWindow && !$isCompletedInWindow && !$isDueInWindow) {
                continue;
            }

            $record = [
                'summary' => $summary,
                'description' => mb_substr(trim((string) ($task['description'] ?? '')), 0, 200),
                'status' => $status,
                'due' => $due,
                'created_at' => $createdAt,
                'completed_at' => $completedAt,
                'is_completed' => $isCompleted,
                'creator' => trim((string) ($task['creator'] ?? '')),
                'task_id' => trim((string) ($task['guid'] ?? $task['id'] ?? $task['task_id'] ?? '')),
            ];

            $records[] = $record;
            $items[] = new ActivityItem(
                'task',
                'feishu.task',
                $summary,
                $record['description'],
                $this->timeParser->parse($createdAt),
                $record['creator'],
                $record
            );
        }

        return [new SourceCollectionResult('tasks', $records, $items)];
    }
}
