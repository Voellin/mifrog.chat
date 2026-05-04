<?php

namespace App\Modules\ProactiveReminder\Stores;

use App\Models\ProactiveActivitySnapshot;
use App\Modules\ProactiveReminder\Contracts\ReminderStateStoreInterface;
use App\Modules\ProactiveReminder\DTO\ActivityBatch;
use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use Carbon\CarbonImmutable;

class DatabaseReminderStateStore implements ReminderStateStoreInterface
{
    public function recentNotificationHashes(int $userId, CarbonImmutable $since): array
    {
        return ProactiveActivitySnapshot::query()
            ->where('user_id', $userId)
            ->where('notification_sent', true)
            ->whereNotNull('notification_message_hash')
            ->where('notification_sent_at', '>=', $since->toDateTimeString())
            ->orderByDesc('notification_sent_at')
            ->pluck('notification_message_hash')
            ->filter(static fn (?string $hash): bool => trim((string) $hash) !== '')
            ->unique()
            ->values()
            ->all();
    }

    public function hasRecentNotification(int $userId, CarbonImmutable $since): bool
    {
        return ProactiveActivitySnapshot::query()
            ->where('user_id', $userId)
            ->where('notification_sent', true)
            ->where('notification_sent_at', '>=', $since->toDateTimeString())
            ->exists();
    }

    public function hasRecentNotificationForFingerprint(
        int $userId,
        string $fingerprint,
        CarbonImmutable $since
    ): bool {
        if (trim($fingerprint) === '') {
            return false;
        }

        return ProactiveActivitySnapshot::query()
            ->where('user_id', $userId)
            ->where('notification_sent', true)
            ->where('activity_fingerprint', $fingerprint)
            ->where('notification_sent_at', '>=', $since->toDateTimeString())
            ->exists();
    }

    public function createSnapshot(ReminderScanRequest $request, ActivityBatch $batch): ProactiveActivitySnapshot
    {
        $counts = $batch->counts();

        return ProactiveActivitySnapshot::create([
            'user_id' => $request->userId,
            'scan_window_minutes' => $request->windowMinutes,
            'calendar_data' => $batch->calendar(),
            'messages_data' => $batch->messages(),
            'documents_data' => $batch->documents(),
            'meetings_data' => $batch->meetings(),
            'sheets_data' => $batch->sheets(),
            'bitables_data' => $batch->bitables(),
            'mails_data' => $batch->mails(),
            'calendar_count' => $counts['calendar'],
            'messages_count' => $counts['messages'],
            'documents_count' => $counts['documents'],
            'meetings_count' => $counts['meetings'],
            'sheets_count' => $counts['sheets'] ?? 0,
            'bitables_count' => $counts['bitables'] ?? 0,
            'mails_count' => $counts['mails'] ?? 0,
            'has_activity' => $batch->hasActivity(),
            'scanned_at' => $request->until,
        ]);
    }

    public function updateSnapshot(ProactiveActivitySnapshot $snapshot, array $attributes): void
    {
        $snapshot->fill($attributes);
        $snapshot->save();
    }
}
