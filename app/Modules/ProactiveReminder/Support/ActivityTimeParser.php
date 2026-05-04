<?php

namespace App\Modules\ProactiveReminder\Support;

use Carbon\CarbonImmutable;
use Throwable;

final class ActivityTimeParser
{
    public function parse(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                $numeric = (string) $value;
                if (strlen($numeric) >= 13) {
                    return CarbonImmutable::createFromTimestampMs((int) $numeric, 'Asia/Shanghai');
                }

                return CarbonImmutable::createFromTimestamp((int) $numeric, 'Asia/Shanghai');
            }

            return CarbonImmutable::parse((string) $value, 'Asia/Shanghai');
        } catch (Throwable) {
            return null;
        }
    }
}
