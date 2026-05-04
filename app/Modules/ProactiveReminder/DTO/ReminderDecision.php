<?php

namespace App\Modules\ProactiveReminder\DTO;

final readonly class ReminderDecision
{
    public function __construct(
        public bool $shouldSend,
        public string $reason,
        public ?string $activityFingerprint = null,
        public ?string $message = null,
        public ?string $notificationMessageHash = null,
    ) {
    }

    public static function skip(
        string $reason,
        ?string $activityFingerprint = null,
        ?string $message = null,
        ?string $notificationMessageHash = null,
    ): self {
        return new self(false, $reason, $activityFingerprint, $message, $notificationMessageHash);
    }

    public static function send(
        string $reason,
        string $activityFingerprint,
        string $message,
        ?string $notificationMessageHash,
    ): self {
        return new self(true, $reason, $activityFingerprint, $message, $notificationMessageHash);
    }
}
