<?php

namespace App\Console\Commands;

use App\Models\UserIdentity;
use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use App\Models\Setting;
use App\Modules\ProactiveReminder\Kernel\DailySummaryKernel;
use App\Services\FeishuCliClient;
use App\Services\FeishuService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class FeishuDailySummaryCommand extends Command
{
    protected $signature = 'feishu:daily-summary
        {--user_id= : Only generate summary for one user}
        {--date= : Summarize a specific date (YYYY-MM-DD), default yesterday}
        {--dry-run : Generate summary but do not send message}';

    protected $description = 'Generate and send a daily work summary for each Feishu user, covering the previous day\'s activities and today\'s agenda.';

    public function __construct(
        private readonly FeishuService $feishuService,
        private readonly FeishuCliClient $cliClient,
        private readonly DailySummaryKernel $kernel,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $targetUserId = (int) ($this->option('user_id') ?: 0);
        $dateStr = trim((string) ($this->option('date') ?: ''));

        // schedule guard: 由 crontab 每分钟巡检；仅当现在的 H:M 与企业配置 summary_schedule.daily_at 匹配时才执行。
        // 手动指定 --user_id 或 --date 视为人工调用，跳过 guard。
        if ($targetUserId === 0 && $dateStr === '') {
            $cfg = Setting::read('summary_schedule', []);
            $dailyAt = is_array($cfg) ? trim((string) ($cfg['daily_at'] ?? '07:00')) : '07:00';
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $dailyAt)) { $dailyAt = '07:00'; }
            $now = CarbonImmutable::now('Asia/Shanghai')->format('H:i');
            if ($now !== $dailyAt) {
                return self::SUCCESS;
            }
        }

        // Determine the date to summarize
        if ($dateStr !== '') {
            try {
                $targetDate = CarbonImmutable::parse($dateStr, 'Asia/Shanghai')->startOfDay();
            } catch (Throwable) {
                $this->error("Invalid date format: {$dateStr}. Use YYYY-MM-DD.");
                return self::FAILURE;
            }
        } else {
            $targetDate = CarbonImmutable::now('Asia/Shanghai')->subDay()->startOfDay();
        }

        $since = $targetDate;
        $until = $targetDate->endOfDay();
        $windowMinutes = 1440; // 24 hours

        $feishuConfig = $this->feishuService->readConfig();
        if (empty($feishuConfig['app_id']) || empty($feishuConfig['app_secret'])) {
            $this->error('Feishu config missing app_id/app_secret');
            return self::FAILURE;
        }

        if (!$this->cliClient->isEnabled()) {
            $this->error('lark-cli disabled');
            return self::FAILURE;
        }

        $cliAvailable = $this->cliClient->isAvailable();
        if (!$cliAvailable) {
            $this->warn('lark-cli not available, daily summary will use local data only');
        }

        $query = UserIdentity::query()
            ->where('provider', 'feishu')
            ->with('user');

        if ($targetUserId > 0) {
            $query->where('user_id', $targetUserId);
        }

        $identities = $query->get();
        $this->info(sprintf(
            'Daily summary started: users=%d, date=%s, dry_run=%s',
            $identities->count(),
            $targetDate->toDateString(),
            $dryRun ? 'yes' : 'no'
        ));

        $sentCount = 0;
        $skipCount = 0;

        foreach ($identities as $identity) {
            $user = $identity->user;
            if (!$user || !$user->is_active) {
                continue;
            }

            $openId = trim((string) ($identity->provider_user_id ?: $user->feishu_open_id ?: ''));

            // STRICT IDENTITY ISOLATION: each user's auth is checked independently.
            // Never reuse another user's token or auth state.
            $hasUserAuth = $cliAvailable
                && $openId !== ''
                && $this->cliClient->hasVerifiedUserAuth($feishuConfig, $openId);

            $collectionMode = $hasUserAuth ? 'full' : 'fallback';
            $this->line("  user={$user->id} ({$user->name}): summarizing {$targetDate->toDateString()}... (mode={$collectionMode})");

            try {
                $request = new ReminderScanRequest(
                    userId: (int) $user->id,
                    userName: trim((string) $user->name),
                    openId: $openId,
                    since: $since,
                    until: $until,
                    windowMinutes: $windowMinutes,
                    channel: 'feishu',
                    collectionMode: $collectionMode,
                    dryRun: $dryRun,
                );

                $result = $this->kernel->run($request, $feishuConfig);
                $counts = $result->batch->counts();

                $this->line('    collected: ' . json_encode($counts, JSON_UNESCAPED_UNICODE));
                $this->line('    decision=' . $result->decision->reason . ', sent=' . (($result->dispatch?->sent ?? false) ? 'yes' : 'no'));

                if (($result->analysis->message ?? null) !== null) {
                    $msg = trim((string) $result->analysis->message);
                    if ($msg !== '') {
                        $this->line('    summary: ' . mb_substr($msg, 0, 120) . (mb_strlen($msg) > 120 ? '...' : ''));
                    }
                }

                if ($result->dispatch?->sent ?? false) {
                    $sentCount++;
                } else {
                    $skipCount++;
                }
            } catch (Throwable $e) {
                Log::error('[DailySummary] user_scan_failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $this->error("    error: {$e->getMessage()}");
                $skipCount++;
            }
        }

        $this->info("Daily summary complete: sent={$sentCount}, skipped={$skipCount}");
        return self::SUCCESS;
    }
}
