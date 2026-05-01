<?php

namespace App\Modules\ProactiveReminder\DTO;

final readonly class DispatchResult
{
    public function __construct(
        public bool $sent,
        public ?string $channel = null,
        public ?string $target = null,
        public ?string $error = null,
    ) {
    }

    public static function sent(string $channel, string $target): self
    {
        return new self(true, $channel, $target, null);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, null, $error);
    }
}
