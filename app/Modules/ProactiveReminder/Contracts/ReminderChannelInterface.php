<?php

namespace App\Modules\ProactiveReminder\Contracts;

use App\Modules\ProactiveReminder\DTO\DispatchResult;
use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;

interface ReminderChannelInterface
{
    public function send(ReminderScanRequest $request, string $message): DispatchResult;
}
