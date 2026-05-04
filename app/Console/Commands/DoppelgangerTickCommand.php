<?php

namespace App\Console\Commands;

use App\Modules\Doppelganger\Services\DoppelgangerService;
use App\Modules\Doppelganger\Services\WorkflowService;
use Illuminate\Console\Command;

class DoppelgangerTickCommand extends Command
{
    protected $signature = 'doppelganger:tick';
    protected $description = '数字分身周期任务：自动 expire 到期分身 + 推送工作流提醒';

    public function handle(
        DoppelgangerService $service,
        WorkflowService $workflowService,
    ): int {
        $expired = $service->tickExpire();
        $pushed = $workflowService->tickPush();
        $this->info("doppelganger:tick — expired={$expired}, workflows_pushed={$pushed}");
        return self::SUCCESS;
    }
}
