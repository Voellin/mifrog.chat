<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Laravel scheduler is intentionally NOT used on prod —— there is no
     * `schedule:run` cron entry. The single source of truth for periodic
     * tasks is root's crontab (`crontab -l`). Adding entries here would
     * silently do nothing.
     *
     * Current cron schedule (read from prod crontab as of 2026-04-29):
     *   - feishu:daily-summary      * * * * *  (Command 内部按 Setting summary_schedule.daily_at 自判定)
     *   - feishu:weekly-summary     * * * * *  (同上，按 weekly_dow + weekly_at 自判定)
     *   - quota:check-low           0 9 * * *
     *   - feishu:sync-users         0 * * * *  (hourly)
     *   - memory:cleanup            30 3 * * *
     *   - keepalive.sh              0 *\/12 * * *
     *
     * memory:repair 和 memory:distill 等命令是手动运维工具，不挂 cron。
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Intentionally empty —— see docblock above. All scheduling lives in root's crontab.
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
