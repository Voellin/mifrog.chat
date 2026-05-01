<?php

namespace App\Modules\ProactiveReminder\DTO;

final readonly class SourceCollectionResult
{
    /**
     * @param  array<int,array<string,mixed>>  $records
     * @param  array<int,ActivityItem>  $items
     */
    public function __construct(
        public string $bucket,
        public array $records,
        public array $items,
    ) {
    }
}
