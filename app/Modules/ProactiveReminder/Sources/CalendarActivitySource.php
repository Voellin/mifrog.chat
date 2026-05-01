<?php

namespace App\Modules\ProactiveReminder\Sources;

use App\Modules\ProactiveReminder\Contracts\ActivitySourceInterface;
use App\Modules\ProactiveReminder\DTO\ActivityItem;
use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use App\Modules\ProactiveReminder\DTO\SourceCollectionResult;
use App\Modules\ProactiveReminder\Support\ActivityTimeParser;
use App\Services\FeishuCliClient;

class CalendarActivitySource implements ActivitySourceInterface
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
        $raw = $this->cliClient->runSkillCommand($feishuConfig, '', [
            'calendar', '+agenda',
            '--start', $request->since->toIso8601String(),
            '--end', $request->until->toIso8601String(),
            '--format', 'json',
        ], 'user', $request->openId);

        $records = [];
        $items = [];
        foreach ((array) ($raw['data'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $startRaw = $item['start_time'] ?? $item['start'] ?? '';
            $endRaw = $item['end_time'] ?? $item['end'] ?? '';
            $start = is_array($startRaw) ? (string) ($startRaw['datetime'] ?? ($startRaw['date'] ?? '')) : (string) $startRaw;
            $end = is_array($endRaw) ? (string) ($endRaw['datetime'] ?? ($endRaw['date'] ?? '')) : (string) $endRaw;
            $organizerRaw = $item['event_organizer'] ?? $item['organizer'] ?? '';
            $organizer = is_array($organizerRaw)
                ? trim((string) ($organizerRaw['display_name'] ?? ''))
                : trim((string) $organizerRaw);

            $record = [
                'summary' => trim((string) ($item['summary'] ?? $item['title'] ?? '')),
                'start_time' => $start,
                'end_time' => $end,
                'organizer' => $organizer,
                'location' => trim((string) ($item['location'] ?? '')),
                'description' => mb_substr(trim((string) ($item['description'] ?? '')), 0, 200),
                'status' => trim((string) ($item['status'] ?? '')),
                // 加上 event_id + calendar_id 供 D8 CalendarEventFetcher 拉完整 description+attendees
                'event_id' => trim((string) ($item['event_id'] ?? '')),
                'calendar_id' => trim((string) ($item['organizer_calendar_id'] ?? '')),
            ];
            $records[] = $record;
            $items[] = new ActivityItem(
                'calendar',
                'feishu.calendar',
                $record['summary'],
                $record['description'],
                $this->timeParser->parse($record['start_time']),
                $record['organizer'],
                $record
            );
        }

        return [new SourceCollectionResult('calendar', $records, $items)];
    }
}
