<?php

namespace App\Modules\ProactiveReminder\DTO;

use Carbon\CarbonImmutable;

final readonly class ReminderScanRequest
{
    public function __construct(
        public int $userId,
        public string $userName,
        public string $openId,
        public CarbonImmutable $since,
        public CarbonImmutable $until,
        public int $windowMinutes,
        public string $channel = 'feishu',
        public string $collectionMode = 'full',
        public bool $dryRun = false,
    ) {
    }
}
