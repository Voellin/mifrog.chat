<?php

namespace App\Modules\ProactiveReminder\DTO;

use App\Models\ProactiveActivitySnapshot;

final readonly class ReminderRunResult
{
    public function __construct(
        public ActivityBatch $batch,
        public AnalyzerResult $analysis,
        public ReminderDecision $decision,
        public ?DispatchResult $dispatch,
        public ?ProactiveActivitySnapshot $snapshot,
    ) {
    }
}
