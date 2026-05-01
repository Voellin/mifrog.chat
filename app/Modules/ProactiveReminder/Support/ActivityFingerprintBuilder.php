<?php

namespace App\Modules\ProactiveReminder\Support;

use App\Modules\ProactiveReminder\DTO\ActivityBatch;
use App\Modules\ProactiveReminder\DTO\ActivityItem;

final class ActivityFingerprintBuilder
{
    public function __construct(
        private readonly MessageCanonicalizer $canonicalizer,
    ) {
    }

    public function build(ActivityBatch $batch): ?string
    {
        $tokens = [];

        foreach ($batch->items() as $item) {
            $signature = $this->signatureForItem($item);
            if ($signature !== '') {
                $tokens[] = $signature;
            }
        }

        $tokens = array_values(array_unique($tokens));
        sort($tokens, SORT_STRING);
        $tokens = array_slice($tokens, 0, 6);

        if ($tokens === []) {
            return null;
        }

        return hash('sha256', implode('|', $tokens));
    }

    private function signatureForItem(ActivityItem $item): string
    {
        $parts = [$item->type, $item->source, $item->actor, $item->title, $item->summary];

        if ($item->occurredAt !== null) {
            $parts[] = $item->occurredAt->format('Y-m-d H:i');
        }

        return $this->canonicalizer->normalize(implode(' | ', array_filter($parts)));
    }
}
