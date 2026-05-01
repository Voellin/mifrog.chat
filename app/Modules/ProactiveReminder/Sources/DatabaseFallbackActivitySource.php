<?php

namespace App\Modules\ProactiveReminder\Sources;

use App\Models\Run;
use App\Models\RunEvent;
use App\Modules\ProactiveReminder\Contracts\ActivitySourceInterface;
use App\Modules\ProactiveReminder\DTO\ActivityItem;
use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use App\Modules\ProactiveReminder\DTO\SourceCollectionResult;
use App\Modules\ProactiveReminder\Support\ActivityTimeParser;

class DatabaseFallbackActivitySource implements ActivitySourceInterface
{
    public function __construct(
        private readonly ActivityTimeParser $timeParser,
    ) {
    }

    public function supports(ReminderScanRequest $request): bool
    {
        return $request->collectionMode === 'fallback';
    }

    public function collect(ReminderScanRequest $request, array $feishuConfig): array
    {
        $sinceUtc = $request->since->utc();
        $results = [];

        // Fallback mode only has local MiFrog conversations and local run events.
        // The assistant conversation itself should never be treated as a proactive reminder signal.
        $results[] = new SourceCollectionResult('messages', [], []);

        $calendarRecords = [];
        $calendarItems = [];
        $documentRecords = [];
        $documentItems = [];
        $meetingRecords = [];
        $meetingItems = [];

        $runs = Run::query()
            ->where('user_id', $request->userId)
            ->where('created_at', '>=', $sinceUtc)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'created_at']);

        foreach ($runs as $run) {
            $events = RunEvent::query()
                ->where('run_id', $run->id)
                ->where('event_type', 'tool_start')
                ->limit(10)
                ->get(['payload', 'created_at']);

            foreach ($events as $event) {
                $payload = is_array($event->payload) ? $event->payload : [];
                $skillKey = (string) ($payload['skill_key'] ?? '');
                $taskKind = (string) ($payload['task_kind'] ?? '');
                $title = '通过 Mifrog 执行: '.($taskKind !== '' ? $taskKind : $skillKey);
                $occurredAt = $event->created_at?->toIso8601String();

                if (str_contains($skillKey, 'calendar') || str_contains($taskKind, 'calendar')) {
                    $record = [
                        'summary' => $title,
                        'start_time' => $occurredAt,
                        'end_time' => '',
                        'organizer' => '',
                        'location' => '',
                        'description' => $taskKind,
                    ];
                    $calendarRecords[] = $record;
                    $calendarItems[] = new ActivityItem(
                        'calendar',
                        'database.run_events',
                        $record['summary'],
                        $record['description'],
                        $this->timeParser->parse($occurredAt),
                        '',
                        $record
                    );
                }

                if (
                    str_contains($skillKey, 'doc') || str_contains($skillKey, 'sheet')
                    || str_contains($skillKey, 'wiki') || str_contains($taskKind, 'doc')
                    || str_contains($taskKind, 'sheet') || str_contains($taskKind, 'wiki')
                ) {
                    $record = [
                        'title' => $title,
                        'type' => strtoupper($skillKey !== '' ? $skillKey : 'DOCUMENT'),
                        'url' => '',
                        'owner' => $request->userName,
                        'created_at' => $occurredAt,
                        'updated_at' => $occurredAt,
                        'preview' => $taskKind,
                        'last_open_time' => $event->created_at?->timestamp,
                        'recently_created' => true,
                        'recently_updated' => true,
                        'recently_opened' => true,
                        'owner_matches_user' => true,
                    ];
                    $documentRecords[] = $record;
                    $documentItems[] = new ActivityItem(
                        'document',
                        'database.run_events',
                        $record['title'],
                        $record['preview'],
                        $this->timeParser->parse($occurredAt),
                        $request->userName,
                        $record
                    );
                }

                if (
                    str_contains($skillKey, 'meeting') || str_contains($skillKey, 'vc')
                    || str_contains($taskKind, 'meeting') || str_contains($taskKind, 'vc')
                ) {
                    $record = [
                        'topic' => $title,
                        'start_time' => $occurredAt,
                        'end_time' => '',
                        'notes' => '',
                    ];
                    $meetingRecords[] = $record;
                    $meetingItems[] = new ActivityItem(
                        'meeting',
                        'database.run_events',
                        $record['topic'],
                        $record['topic'],
                        $this->timeParser->parse($occurredAt),
                        '',
                        $record
                    );
                }
            }
        }

        $results[] = new SourceCollectionResult('calendar', $calendarRecords, $calendarItems);
        $results[] = new SourceCollectionResult('documents', $documentRecords, $documentItems);
        $results[] = new SourceCollectionResult('meetings', $meetingRecords, $meetingItems);

        return $results;
    }
}
