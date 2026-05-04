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

/**
 * Collects Feishu Mail (邮箱) activity within the scan window.
 * Uses `lark-cli mail +triage` with a time_range filter.
 *
 * Gracefully degrades when the user's account has no mailbox seat
 * (error code 1230002 "user does not have email") — returns empty records,
 * does not throw. This lets the same Kernel run across users with mixed
 * mail-seat provisioning.
 */
class MailActivitySource implements ActivitySourceInterface
{
    private const MAX_MESSAGES = 50;

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
        $filter = [
            'time_range' => [
                'start_time' => $request->since->toIso8601String(),
                'end_time'   => $request->until->toIso8601String(),
            ],
        ];

        try {
            $raw = $this->cliClient->runSkillCommand($feishuConfig, '', [
                'mail', '+triage',
                '--format', 'json',
                '--max', (string) self::MAX_MESSAGES,
                '--filter', json_encode($filter, JSON_UNESCAPED_UNICODE),
            ], 'user', $request->openId);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            // Graceful skip: user has no mailbox seat in this tenant.
            if (str_contains($msg, '1230002') || str_contains($msg, 'user does not have email')) {
                Log::info('[ProactiveReminder] mail_skip_no_seat', [
                    'user_id' => $request->userId,
                ]);
                return [new SourceCollectionResult('mails', [], [])];
            }
            Log::warning('[ProactiveReminder] mail_triage_failed', [
                'user_id' => $request->userId,
                'error'   => $msg,
            ]);
            return [new SourceCollectionResult('mails', [], [])];
        }

        // mail +triage with --format json returns the messages array directly in 'data'.
        $messages = (array) ($raw['data'] ?? []);
        // Tolerate either a flat list or nested under 'messages'.
        if (isset($messages['messages']) && is_array($messages['messages'])) {
            $messages = $messages['messages'];
        }

        $records = [];
        $items = [];

        foreach ($messages as $m) {
            if (! is_array($m)) {
                continue;
            }
            $subject = trim((string) ($m['subject'] ?? '(无主题)'));
            $from    = trim((string) ($m['from'] ?? ''));
            $to      = trim((string) ($m['to'] ?? ''));
            $date    = trim((string) ($m['date'] ?? $m['internal_date'] ?? ''));
            $messageId = trim((string) ($m['message_id'] ?? ''));
            $snippet = trim((string) ($m['snippet'] ?? $m['preview'] ?? ''));
            $direction = 'received';
            // Heuristic: treat missing from+present to as sent-by-user (may be refined later).
            if ($from === '' && $to !== '') {
                $direction = 'sent';
            }

            $record = [
                'subject'    => $subject,
                'from'       => $from,
                'to'         => $to,
                'direction'  => $direction,
                'date'       => $date,
                'message_id' => $messageId,
                'snippet'    => mb_substr($snippet, 0, 200),
            ];
            $records[] = $record;

            $items[] = new ActivityItem(
                'mail',
                'feishu.mail',
                $subject,
                $snippet !== '' ? mb_substr($snippet, 0, 200) : $subject,
                $this->timeParser->parse($date),
                $from !== '' ? $from : $to,
                $record
            );
        }

        return [new SourceCollectionResult('mails', $records, $items)];
    }
}
