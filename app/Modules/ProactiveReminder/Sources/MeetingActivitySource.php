<?php

namespace App\Modules\ProactiveReminder\Sources;

use App\Modules\ProactiveReminder\Contracts\ActivitySourceInterface;
use App\Modules\ProactiveReminder\DTO\ActivityItem;
use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use App\Modules\ProactiveReminder\DTO\SourceCollectionResult;
use App\Modules\ProactiveReminder\Support\ActivityTimeParser;
use App\Services\FeishuCliClient;
use Throwable;

class MeetingActivitySource implements ActivitySourceInterface
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
            'vc', '+search',
            '--start', $request->since->toIso8601String(),
            '--end', $request->until->toIso8601String(),
            '--participant-ids', $request->openId,
            '--format', 'json',
        ], 'user', $request->openId);

        $records = [];
        $items = [];
        $meetings = (array) ($raw['data']['meeting_list'] ?? $raw['data']['items'] ?? []);

        foreach ($meetings as $meeting) {
            if (! is_array($meeting)) {
                continue;
            }

            $meetingId = trim((string) ($meeting['meeting_id'] ?? $meeting['id'] ?? ''));
            $topic = trim((string) ($meeting['topic'] ?? $meeting['meeting_topic'] ?? ''));
            $notes = $this->fetchNotes($feishuConfig, $request->openId, $meetingId);

            $record = [
                'topic' => $topic,
                'meeting_id' => $meetingId,
                'start_time' => (string) ($meeting['start_time'] ?? ''),
                'end_time' => (string) ($meeting['end_time'] ?? ''),
                'notes' => $notes,
            ];
            $records[] = $record;
            $items[] = new ActivityItem(
                'meeting',
                'feishu.vc',
                $topic,
                $notes !== '' ? mb_substr($notes, 0, 200) : $topic,
                $this->timeParser->parse($record['start_time']),
                '',
                $record
            );
        }

        return [new SourceCollectionResult('meetings', $records, $items)];
    }

    private function fetchNotes(array $feishuConfig, string $userKey, string $meetingId): string
    {
        if ($meetingId === '') {
            return '';
        }

        try {
            $result = $this->cliClient->runSkillCommand($feishuConfig, '', [
                'vc', '+notes',
                '--meeting-ids', $meetingId,
                '--format', 'json',
            ], 'user', $userKey);

            $items = (array) ($result['data'] ?? []);
            if ($items === []) {
                return '';
            }

            return mb_substr(json_encode($items, JSON_UNESCAPED_UNICODE) ?: '', 0, 500);
        } catch (Throwable) {
            return '';
        }
    }
}
