<?php

namespace App\Services;

use App\Models\Run;
use App\Models\RunStateTransition;

class RunStateService
{
    public function transition(Run $run, string $toStatus, string $reason, array $context = []): void
    {
        $toStatus = strtolower(trim($toStatus));
        if ($toStatus === '') {
            return;
        }

        $fromStatus = strtolower(trim((string) $run->status));

        if ($toStatus === Run::STATUS_RUNNING && $run->started_at === null) {
            $run->started_at = now();
        }

        if (in_array($toStatus, [Run::STATUS_SUCCESS, Run::STATUS_FAILED, Run::STATUS_NEEDS_INPUT], true)) {
            $run->finished_at = now();
        }

        if ($toStatus === Run::STATUS_WAITING_AUTH) {
            $run->finished_at = null;
        }

        $run->status = $toStatus;
        $run->save();

        if ($fromStatus !== $toStatus || trim($reason) !== '') {
            RunStateTransition::query()->create([
                'run_id' => $run->id,
                'from_status' => $fromStatus !== '' ? $fromStatus : null,
                'to_status' => $toStatus,
                'reason' => trim($reason) !== '' ? trim($reason) : null,
                'context' => $context !== [] ? $context : null,
            ]);
        }
    }
}
