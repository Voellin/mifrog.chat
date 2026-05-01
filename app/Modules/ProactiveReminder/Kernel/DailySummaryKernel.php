<?php

namespace App\Modules\ProactiveReminder\Kernel;

use App\Modules\ProactiveReminder\Analyzers\DailySummaryAnalyzer;
use App\Modules\ProactiveReminder\Contracts\ActivitySourceInterface;
use App\Modules\ProactiveReminder\Contracts\ReminderChannelInterface;
use App\Modules\ProactiveReminder\Contracts\ReminderStateStoreInterface;
use App\Modules\ProactiveReminder\DTO\ActivityBatch;
use App\Modules\ProactiveReminder\DTO\AnalyzerResult;
use App\Modules\ProactiveReminder\DTO\ReminderDecision;
use App\Modules\ProactiveReminder\DTO\ReminderRunResult;
use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use App\Modules\ProactiveReminder\Support\ActivityFingerprintBuilder;
use App\Modules\ProactiveReminder\Support\MessageCanonicalizer;
use Carbon\CarbonImmutable;

/**
 * Independent kernel for daily work summary.
 * Does NOT share cooldown/echo logic with ProactiveReminderKernel.
 * Runs once per day per user.
 */
class DailySummaryKernel
{
    /**
     * @param  iterable<int,ActivitySourceInterface>  $sources
     */
    public function __construct(
        private readonly iterable $sources,
        private readonly DailySummaryAnalyzer $analyzer,
        private readonly ReminderChannelInterface $channel,
        private readonly ReminderStateStoreInterface $stateStore,
        private readonly ActivityFingerprintBuilder $fingerprintBuilder,
        private readonly MessageCanonicalizer $canonicalizer,
    ) {
    }

    /**
     * Collect yesterday's activity for a single user and generate a daily summary.
     * Also collects today's calendar for the "today's agenda" section.
     *
     * @param  array<string,mixed>  $feishuConfig
     */
    public function run(ReminderScanRequest $request, array $feishuConfig): ReminderRunResult
    {
        // Phase 1: Collect yesterday's activity
        $batch = new ActivityBatch();
        foreach ($this->sources as $source) {
            if (!$source->supports($request)) {
                continue;
            }
            foreach ($source->collect($request, $feishuConfig) as $result) {
                $batch->add($result);
            }
        }

        // Phase 1b: Collect today's calendar separately (for "today's agenda")
        $todayCalendar = $this->collectTodayCalendar($request, $feishuConfig);

        // Create snapshot (reuse existing infra)
        $snapshot = $this->stateStore->createSnapshot($request, $batch);

        // Phase 2: Check if there's any activity worth summarizing
        // Note: tasks flow through items[] not a named bucket (ActivityBatch is frozen per BOUNDARY),
        // so we check items for task type separately.
        $hasTasks = count(array_filter($batch->items(), fn($item) => $item->type === 'task')) > 0;
        if (!$batch->hasActivity() && !$hasTasks && $todayCalendar === []) {
            $analysis = AnalyzerResult::skip('no_activity_for_summary');
            $decision = ReminderDecision::skip('no_activity_for_summary');

            $this->stateStore->updateSnapshot($snapshot, [
                'llm_should_notify' => false,
                'llm_reasoning' => $analysis->reasoning,
                'skip_reason' => $decision->reason,
            ]);

            return new ReminderRunResult($batch, $analysis, $decision, null, $snapshot);
        }

        // Phase 3: LLM generates daily summary
        $analysis = $this->analyzer->analyze($batch, $request, $todayCalendar);

        // Phase 4: Simple policy — if LLM generated a summary, send it
        $fingerprint = $this->fingerprintBuilder->build($batch);
        $message = trim((string) ($analysis->message ?? ''));
        $messageHash = $this->canonicalizer->hash($message);

        if (!$analysis->shouldNotify || $message === '') {
            $decision = ReminderDecision::skip(
                (string) ($analysis->reasoning ?? 'analyzer_declined'),
                $fingerprint
            );

            $this->stateStore->updateSnapshot($snapshot, [
                'llm_should_notify' => false,
                'llm_reasoning' => $analysis->reasoning,
                'skip_reason' => $decision->reason,
            ]);

            return new ReminderRunResult($batch, $analysis, $decision, null, $snapshot);
        }

        $decision = ReminderDecision::send('daily_summary_ready', $fingerprint ?? '', $message, $messageHash);
        $dispatch = null;

        if ($request->dryRun) {
            $decision = ReminderDecision::skip('dry_run', $fingerprint, $message, $messageHash);
        } else {
            $dispatch = $this->channel->send($request, $message);
            if (!$dispatch->sent) {
                $decision = ReminderDecision::skip('dispatch_failed', $fingerprint, $message, $messageHash);
            }
        }

        $this->stateStore->updateSnapshot($snapshot, [
            'llm_should_notify' => true,
            'llm_reasoning' => $analysis->reasoning,
            'llm_message' => $message,
            'activity_fingerprint' => $fingerprint,
            'notification_sent' => $dispatch?->sent ?? false,
            'notification_sent_at' => $dispatch?->sent ? $request->until : null,
            'notification_message_hash' => $messageHash,
            'notification_channel' => $dispatch?->channel,
            'skip_reason' => $dispatch?->sent ? null : $decision->reason,
            'notification_error' => $dispatch?->error,
        ]);

        return new ReminderRunResult($batch, $analysis, $decision, $dispatch, $snapshot);
    }

    /**
     * Collect today's calendar events for the "today's agenda" section.
     * Uses a separate request scope to avoid mixing with yesterday's window.
     *
     * @return array<int,array<string,mixed>>
     */
    private function collectTodayCalendar(ReminderScanRequest $request, array $feishuConfig): array
    {
        // Build a separate request for today's calendar only
        $todayStart = CarbonImmutable::now('Asia/Shanghai')->startOfDay();
        $todayEnd = $todayStart->endOfDay();

        $todayRequest = new ReminderScanRequest(
            userId: $request->userId,
            userName: $request->userName,
            openId: $request->openId,
            since: $todayStart,
            until: $todayEnd,
            windowMinutes: 1440,
            channel: $request->channel,
            collectionMode: $request->collectionMode,
            dryRun: $request->dryRun,
        );

        foreach ($this->sources as $source) {
            // Only use CalendarActivitySource for today's agenda
            if (!$source instanceof \App\Modules\ProactiveReminder\Sources\CalendarActivitySource) {
                continue;
            }
            if (!$source->supports($todayRequest)) {
                continue;
            }

            $results = $source->collect($todayRequest, $feishuConfig);
            foreach ($results as $result) {
                if ($result->bucket === 'calendar') {
                    return $result->records;
                }
            }
        }

        return [];
    }
}
