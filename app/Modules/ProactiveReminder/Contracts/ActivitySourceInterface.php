<?php

namespace App\Modules\ProactiveReminder\Contracts;

use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use App\Modules\ProactiveReminder\DTO\SourceCollectionResult;

interface ActivitySourceInterface
{
    public function supports(ReminderScanRequest $request): bool;

    /**
     * @param  array<string,mixed>  $feishuConfig
     * @return array<int,SourceCollectionResult>
     */
    public function collect(ReminderScanRequest $request, array $feishuConfig): array;
}
