<?php

namespace App\Providers;

use App\Modules\ProactiveReminder\Channels\FeishuReminderChannel;
use App\Modules\ProactiveReminder\Contracts\ReminderChannelInterface;
use App\Modules\ProactiveReminder\Contracts\ReminderStateStoreInterface;
use App\Modules\ProactiveReminder\Sources\CalendarActivitySource;
use App\Modules\ProactiveReminder\Sources\ChatActivitySource;
use App\Modules\ProactiveReminder\Sources\DatabaseFallbackActivitySource;
use App\Modules\ProactiveReminder\Sources\DocumentActivitySource;
use App\Modules\ProactiveReminder\Sources\MeetingActivitySource;
use App\Modules\ProactiveReminder\Stores\DatabaseReminderStateStore;
use App\Modules\ProactiveReminder\Analyzers\DailySummaryAnalyzer;
use App\Modules\ProactiveReminder\Analyzers\WeeklySummaryAnalyzer;
use App\Modules\ProactiveReminder\Analyzers\ActivityArchiveAnalyzer;
use App\Modules\ProactiveReminder\Kernel\ActivityArchiveKernel;
use App\Modules\ProactiveReminder\Kernel\DailySummaryKernel;
use App\Modules\ProactiveReminder\Kernel\WeeklySummaryKernel;
use App\Modules\ProactiveReminder\Sources\BitableActivitySource;
use App\Modules\ProactiveReminder\Sources\MailActivitySource;
use App\Modules\ProactiveReminder\Sources\SheetsActivitySource;
use App\Modules\ProactiveReminder\Sources\TaskActivitySource;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Prompt hardening stack (10a/10b/10d). Auto-resolved via DI otherwise.
        $this->app->singleton(\App\Services\HistoryRedactor::class);
        $this->app->singleton(\App\Services\Prompt\ContextSanitizer::class);
        $this->app->singleton(\App\Services\Prompt\Sections\IdentitySection::class);
        $this->app->singleton(\App\Services\Prompt\Sections\ToolsSection::class);
        $this->app->singleton(\App\Services\Prompt\Sections\SafetySection::class);
        $this->app->singleton(\App\Services\Prompt\Sections\MemorySection::class);
        $this->app->singleton(\App\Services\Prompt\Sections\ChannelSection::class);
        $this->app->singleton(\App\Services\Prompt\Sections\RuntimeSection::class);
        $this->app->singleton(\App\Services\Prompt\PromptComposer::class);
        $this->app->resolving(\App\Services\MemoryService::class, function ($svc, $app) {
            $svc->setContextSanitizer($app->make(\App\Services\Prompt\ContextSanitizer::class));
        });

        $this->app->singleton(ReminderStateStoreInterface::class, DatabaseReminderStateStore::class);
        $this->app->singleton(ReminderChannelInterface::class, FeishuReminderChannel::class);

        $this->app->singleton(DailySummaryKernel::class, function ($app) {
            return new DailySummaryKernel(
                [
                    $app->make(CalendarActivitySource::class),
                    $app->make(ChatActivitySource::class),
                    $app->make(DocumentActivitySource::class),
                    $app->make(MeetingActivitySource::class),
                    $app->make(DatabaseFallbackActivitySource::class),
                    $app->make(TaskActivitySource::class),
                    $app->make(SheetsActivitySource::class),
                    $app->make(BitableActivitySource::class),
                    $app->make(MailActivitySource::class),
                ],
                $app->make(DailySummaryAnalyzer::class),
                $app->make(ReminderChannelInterface::class),
                $app->make(ReminderStateStoreInterface::class),
                $app->make(\App\Modules\ProactiveReminder\Support\ActivityFingerprintBuilder::class),
                $app->make(\App\Modules\ProactiveReminder\Support\MessageCanonicalizer::class),
            );
        });

        $this->app->singleton(ActivityArchiveKernel::class, function ($app) {
            return new ActivityArchiveKernel(
                [
                    $app->make(CalendarActivitySource::class),
                    $app->make(ChatActivitySource::class),
                    $app->make(DocumentActivitySource::class),
                    $app->make(MeetingActivitySource::class),
                    $app->make(DatabaseFallbackActivitySource::class),
                    $app->make(TaskActivitySource::class),
                    $app->make(SheetsActivitySource::class),
                    $app->make(BitableActivitySource::class),
                    $app->make(MailActivitySource::class),
                ],
                $app->make(ActivityArchiveAnalyzer::class),
                $app->make(\App\Services\MemoryService::class),
                $app->make(\App\Services\Feishu\FeishuDocFetcher::class),
                $app->make(\App\Services\AttachmentService::class),
                $app->make(\App\Services\FeishuCliClient::class),
                $app->make(\App\Services\Feishu\FeishuCalendarEventFetcher::class),
                $app->make(\App\Services\Feishu\FeishuSheetContentFetcher::class),
                $app->make(\App\Services\Feishu\FeishuBitableFetcher::class),
            );
        });

        $this->app->singleton(WeeklySummaryKernel::class, function ($app) {
            return new WeeklySummaryKernel(
                [
                    $app->make(CalendarActivitySource::class),
                    $app->make(ChatActivitySource::class),
                    $app->make(DocumentActivitySource::class),
                    $app->make(MeetingActivitySource::class),
                    $app->make(DatabaseFallbackActivitySource::class),
                    $app->make(TaskActivitySource::class),
                    $app->make(SheetsActivitySource::class),
                    $app->make(BitableActivitySource::class),
                    $app->make(MailActivitySource::class),
                ],
                $app->make(WeeklySummaryAnalyzer::class),
                $app->make(ReminderChannelInterface::class),
                $app->make(ReminderStateStoreInterface::class),
                $app->make(\App\Modules\ProactiveReminder\Support\ActivityFingerprintBuilder::class),
                $app->make(\App\Modules\ProactiveReminder\Support\MessageCanonicalizer::class),
            );
        });

    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Schema::defaultStringLength(191);

        Blade::if('adminCan', function (string $permission): bool {
            $admin = request()->attributes->get('admin_user');

            return $admin && method_exists($admin, 'hasAdminPermission') && $admin->hasAdminPermission($permission);
        });

        Blade::if('adminCanAny', function (string ...$permissions): bool {
            $admin = request()->attributes->get('admin_user');

            return $admin && method_exists($admin, 'hasAnyAdminPermission') && $admin->hasAnyAdminPermission($permissions);
        });
    }
}
