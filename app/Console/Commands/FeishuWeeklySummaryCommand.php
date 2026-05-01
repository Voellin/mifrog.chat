<?php

namespace App\Console\Commands;

use App\Models\UserIdentity;
use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use App\Models\Setting;
use App\Modules\ProactiveReminder\Kernel\WeeklySummaryKernel;
use App\Services\FeishuCliClient;
use App\Services\FeishuService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Weekly work summary (周报) command.
 *
 * Mirrors FeishuDailySummaryCommand but:
 *   - Window = one natural week (Monday 00:00 → Sunday 23:59), 10080 minutes.
 *   - --week-offset controls which week: 1 = last week (default), 0 = this week so far.
 *   - Runs via crontab on Monday 07:30 (Laravel scheduler is dormant on prod per known pitfall).
 */
class FeishuWeeklySummaryCommand extends Command
{
    protected $signature = 'feishu:weekly-summary
        {--user_id= : Only generate summary for one user}
        {--week-offset=1 : Which week to summarize. 1 = last week (default), 0 = current week so far}
        {--since= : Override start of week (YYYY-MM-DD)}
        {--until= : Override end of week (YYYY-MM-DD)}
        {--dry-run : Generate summary but do not send message}';

    protected $description = 'Generate and send a weekly work report (周报) for each Feishu user, covering the previous natural week.';

    public function __construct(
        private readonly FeishuService $feishuService,
        private readonly FeishuCliClient $cliClient,
        private readonly WeeklySummaryKernel $kernel,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $targetUserId = (int) ($this->option('user_id') ?: 0);
        $weekOffset = (int) ($this->option('week-offset') ?? 1);
        $sinceStr = trim((string) ($this->option('since') ?: ''));
        $untilStr = trim((string) ($this->option('until') ?: ''));

        // schedule guard: 由 crontab 每分钟巡检；仅当现在的"星期几 + H:M"与企业配置 summary_schedule.weekly_dow + weekly_at 都匹配时才执行。
        // 手动指定 --user_id / --since / --until / --week-offset != 1 视为人工调用，跳过 guard。
        $weekOffsetOpt = (int) ($this->option('week-offset') ?? 1);
        if ($targetUserId === 0 && $sinceStr === '' && $untilStr === '' && $weekOffsetOpt === 1) {
            $cfg = Setting::read('summary_schedule', []);
            $weeklyAt = is_array($cfg) ? trim((string) ($cfg['weekly_at'] ?? '07:30')) : '07:30';
            $weeklyDow = is_array($cfg) ? (int) ($cfg['weekly_dow'] ?? 1) : 1;
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $weeklyAt)) { $weeklyAt = '07:30'; }
            if ($weeklyDow < 1 || $weeklyDow > 7) { $weeklyDow = 1; }
            $now = CarbonImmutable::now('Asia/Shanghai');
            // CarbonImmutable->dayOfWeekIso: Mon=1 ... Sun=7  (matches our 1-7 convention)
            if ($now->dayOfWeekIso !== $weeklyDow || $now->format('H:i') !== $weeklyAt) {
                return self::SUCCESS;
            }
        }

        // Determine the week window.
        try {
            if ($sinceStr !== '' && $untilStr !== '') {
                $since = CarbonImmutable::parse($sinceStr, 'Asia/Shanghai')->startOfDay();
                $until = CarbonImmutable::parse($untilStr, 'Asia/Shanghai')->endOfDay();
            } else {
                // Natural week: Monday 00:00 → Sunday 23:59.
                // week-offset=1 means "last full calendar week".
                $anchor = CarbonImmutable::now('Asia/Shanghai')->startOfWeek(CarbonImmutable::MONDAY);
                $since = $anchor->subWeeks($weekOffset);
                $until = $since->addWeek()->subSecond(); // Sunday 23:59:59
            }
        } catch (Throwable $e) {
            $this->error("Invalid date window: {$e->getMessage()}");
            return self::FAILURE;
        }

        $windowMinutes = 10080; // 7 * 24 * 60

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
            $this->warn('lark-cli not available, weekly summary will use local data only');
        }

        $query = UserIdentity::query()
            ->where('provider', 'feishu')
            ->with('user');

        if ($targetUserId > 0) {
            $query->where('user_id', $targetUserId);
        }

        $identities = $query->get();
        $this->info(sprintf(
            'Weekly summary started: users=%d, window=%s~%s, dry_run=%s',
            $identities->count(),
            $since->toDateString(),
            $until->toDateString(),
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
            $this->line("  user={$user->id} ({$user->name}): summarizing week {$since->toDateString()}~{$until->toDateString()}... (mode={$collectionMode})");

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
                        $this->line('    report: ' . mb_substr($msg, 0, 180) . (mb_strlen($msg) > 180 ? '...' : ''));
                    }
                }

                if ($result->dispatch?->sent ?? false) {
                    $sentCount++;
                } else {
                    $skipCount++;
                }
            } catch (Throwable $e) {
                Log::error('[WeeklySummary] user_scan_failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $this->error("    error: {$e->getMessage()}");
                $skipCount++;
            }
        }

        $this->info("Weekly summary complete: sent={$sentCount}, skipped={$skipCount}");
        return self::SUCCESS;
    }
}
