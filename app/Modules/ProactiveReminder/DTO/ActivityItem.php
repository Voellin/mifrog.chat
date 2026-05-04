<?php

namespace App\Modules\ProactiveReminder\DTO;

use Carbon\CarbonImmutable;

final readonly class ActivityItem
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        public string $type,
        public string $source,
        public string $title,
        public string $summary = '',
        public ?CarbonImmutable $occurredAt = null,
        public string $actor = '',
        public array $payload = [],
    ) {
    }
}
