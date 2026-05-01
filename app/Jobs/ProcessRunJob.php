<?php

namespace App\Jobs;

use App\Services\RunExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    private int $runId;

    public function __construct(int $runId)
    {
        $this->runId = $runId;
    }

    public function handle(RunExecutionService $runExecutionService): void
    {
        $runExecutionService->execute($this->runId);
    }
}
