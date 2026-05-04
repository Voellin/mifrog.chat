<?php

namespace App\Modules\ProactiveReminder\DTO;

final readonly class AnalyzerResult
{
    public function __construct(
        public bool $shouldNotify,
        public ?string $message,
        public ?string $reasoning,
    ) {
    }

    public static function skip(string $reasoning): self
    {
        return new self(false, null, $reasoning);
    }
}
