<?php

namespace App\Console\Commands;

use App\Models\UserIdentity;
use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use App\Modules\ProactiveReminder\Kernel\ActivityArchiveKernel;
use App\Services\FeishuCliClient;
use App\Services\FeishuService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * proactive:collect-activities
 *
 * Periodic 2-hour rolling activity archive. Runs 8 times per day during
 * 07:00~21:00 (Asia/Shanghai) via crontab. Each run:
 *   - For every active feishu-bound user
 *   - Pulls last 2 hours of activity through 9 ActivitySources
 *   - Distills into a short L2 archive entry
 *   - Writes to memory_entries (no Feishu message sent)
 *
 * Manual usage:
 *   php artisan proactive:collect-activities                  # all users
 *   php artisan proactive:collect-activities --user_id=3      # one user
 *   php artisan proactive:collect-activities --window=120     # custom minutes
 */
class ProactiveCollectCommand extends Command
{
    protected $signature = 'proactive:collect-activities
        {--user_id= : Only archive for one user}
        {--window=120 : Minutes back from now to scan, default 120 (2 hours)}';

    protected $description = 'Periodic 2-hour activity archive into user L2 memory';

    public function __construct(
        private readonly FeishuService $feishuService,
        private readonly FeishuCliClient $cliClient,
        private readonly ActivityArchiveKernel $kernel,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $targetUserId = (int) ($this->option('user_id') ?: 0);
        $windowMinutes = max(15, (int) ($this->option('window') ?: 120));

        $until = CarbonImmutable::now('Asia/Shanghai');
        $since = $until->subMinutes($windowMinutes);

        $feishuConfig = $this->feishuService->readConfig();
        if (empty($feishuConfig['app_id']) || empty($feishuConfig['app_secret'])) {
            $this->error('Feishu config missing app_id/app_secret');
            return self::FAILURE;
        }

        if (! $this->cliClient->isEnabled()) {
            $this->error('lark-cli disabled');
            return self::FAILURE;
        }

        $cliAvailable = $this->cliClient->isAvailable();
        if (! $cliAvailable) {
            $this->warn('lark-cli not available, archive will use local data only');
        }

        $query = UserIdentity::query()
            ->where('provider', 'feishu')
            ->whereHas('user', fn ($q) => $q->where('is_active', true));

        if ($targetUserId > 0) {
            $query->where('user_id', $targetUserId);
        }

        $identities = $query->with('user')->get();

        if ($identities->isEmpty()) {
            $this->info('No eligible users to archive');
            return self::SUCCESS;
        }

        $archived = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($identities as $identity) {
            $user = $identity->user;
            if (! $user) {
                continue;
            }

            $extra = is_array($identity->extra) ? $identity->extra : [];
            $openId = trim((string) ($extra['open_id'] ?? $user->feishu_open_id ?? ''));

            $request = new ReminderScanRequest(
                userId: $user->id,
                userName: (string) ($user->name ?? '用户'),
                openId: $openId,
                since: $since,
                until: $until,
                windowMinutes: $windowMinutes,
                channel: 'feishu',
                collectionMode: 'full',
                dryRun: false,
            );

            try {
                $result = $this->kernel->run($request, $feishuConfig);

                if ($result['archived']) {
                    $archived++;
                    $this->info(sprintf(
                        '  ✓ user#%d archived (entry_id=%s, activity=%d)',
                        $user->id,
                        $result['entry_id'],
                        $result['activity_count']
                    ));
                } else {
                    $skipped++;
                    $this->line(sprintf(
                        '  - user#%d skipped (%s, activity=%d)',
                        $user->id,
                        $result['reason'],
                        $result['activity_count']
                    ));
                }
            } catch (Throwable $e) {
                $failed++;
                Log::error('[ProactiveCollect] run_failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn(sprintf('  ✗ user#%d failed: %s', $user->id, $e->getMessage()));
            }
        }

        $this->info(sprintf(
            'Done. archived=%d, skipped=%d, failed=%d (window=%dm, %s ~ %s)',
            $archived,
            $skipped,
            $failed,
            $windowMinutes,
            $since->format('Y-m-d H:i'),
            $until->format('Y-m-d H:i')
        ));

        return self::SUCCESS;
    }
}
