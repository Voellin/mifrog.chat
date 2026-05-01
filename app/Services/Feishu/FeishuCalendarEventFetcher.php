<?php

namespace App\Services\Feishu;

use App\Services\FeishuCliClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 调 lark-cli `calendar events get` + `calendar event.attendees list` 拉单个日程的完整 description + 参会人。
 * 给 ActivityArchiveKernel.ingestCalendarEvents 用。
 */
class FeishuCalendarEventFetcher
{
    public function __construct(private readonly FeishuCliClient $cliClient) {}

    /**
     * @param  array<string,mixed>  $feishuConfig
     * @return array{ok:bool, summary?:string, description?:string, attendees?:array<int,string>, error?:string}
     */
    public function fetch(array $feishuConfig, string $openId, string $calendarId, string $eventId): array
    {
        if ($calendarId === '' || $eventId === '') {
            return ['ok' => false, 'error' => 'missing_ids'];
        }
        try {
            $r = $this->cliClient->runSkillCommand(
                $feishuConfig, '',
                [
                    'calendar', 'events', 'get',
                    '--params', json_encode(['calendar_id' => $calendarId, 'event_id' => $eventId], JSON_UNESCAPED_UNICODE),
                ],
                'user', $openId
            );
            if ((int) ($r['code'] ?? -1) !== 0) {
                return ['ok' => false, 'error' => 'event_get_failed: ' . (string) ($r['msg'] ?? '')];
            }
            $event = (array) ($r['data']['event'] ?? []);
            $summary = trim((string) ($event['summary'] ?? ''));
            $description = trim((string) ($event['description'] ?? ''));

            // 参会人
            $attendees = [];
            try {
                $aResp = $this->cliClient->runSkillCommand(
                    $feishuConfig, '',
                    [
                        'calendar', 'event.attendees', 'list',
                        '--params', json_encode([
                            'calendar_id' => $calendarId,
                            'event_id' => $eventId,
                            'page_size' => 50,
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                    'user', $openId
                );
                if ((int) ($aResp['code'] ?? -1) === 0) {
                    foreach ((array) ($aResp['data']['items'] ?? []) as $att) {
                        $name = trim((string) ($att['display_name'] ?? $att['name'] ?? ''));
                        $rsvp = trim((string) ($att['rsvp_status'] ?? ''));
                        if ($name !== '') {
                            $attendees[] = $rsvp !== '' ? "$name ($rsvp)" : $name;
                        }
                    }
                }
            } catch (Throwable $e) {
                Log::info('[FeishuCalendarEventFetcher] attendees fetch failed', ['error' => $e->getMessage()]);
            }

            return [
                'ok' => true,
                'summary' => $summary,
                'description' => $description,
                'attendees' => $attendees,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'exception: ' . $e->getMessage()];
        }
    }
}
