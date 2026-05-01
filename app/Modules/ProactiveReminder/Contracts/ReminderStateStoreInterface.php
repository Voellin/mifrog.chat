<?php

namespace App\Modules\ProactiveReminder\Contracts;

use App\Models\ProactiveActivitySnapshot;
use App\Modules\ProactiveReminder\DTO\ActivityBatch;
use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use Carbon\CarbonImmutable;

interface ReminderStateStoreInterface
{
    /**
     * @return array<int,string>
     */
    public function recentNotificationHashes(int $userId, CarbonImmutable $since): array;

    public function hasRecentNotification(int $userId, CarbonImmutable $since): bool;

    public function hasRecentNotificationForFingerprint(
        int $userId,
        string $fingerprint,
        CarbonImmutable $since
    ): bool;

    public function createSnapshot(ReminderScanRequest $request, ActivityBatch $batch): ProactiveActivitySnapshot;

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function updateSnapshot(ProactiveActivitySnapshot $snapshot, array $attributes): void;
}
