<?php

namespace App\Modules\ProactiveReminder\Kernel;

use App\Modules\ProactiveReminder\Analyzers\WeeklySummaryAnalyzer;
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

/**
 * Independent kernel for weekly work summary (周报).
 *
 * Parallels DailySummaryKernel but:
 *   - Window = last natural week (Mon 00:00 → Sun 23:59), windowMinutes = 10080
 *   - No "today's agenda" sub-collection (weekly is retrospective only)
 *   - Uses WeeklySummaryAnalyzer which enforces the Feishu wiki 周报 template schema
 *
 * Runs once per week per user (triggered by Monday 07:30 cron).
 * Does NOT share cooldown/echo logic with ProactiveReminderKernel.
 */
class WeeklySummaryKernel
{
    /**
     * @param  iterable<int,ActivitySourceInterface>  $sources
     */
    public function __construct(
        private readonly iterable $sources,
        private readonly WeeklySummaryAnalyzer $analyzer,
        private readonly ReminderChannelInterface $channel,
        private readonly ReminderStateStoreInterface $stateStore,
        private readonly ActivityFingerprintBuilder $fingerprintBuilder,
        private readonly MessageCanonicalizer $canonicalizer,
    ) {
    }

    /**
     * Collect the configured week's activity for a single user and generate a weekly report.
     *
     * @param  array<string,mixed>  $feishuConfig
     */
    public function run(ReminderScanRequest $request, array $feishuConfig): ReminderRunResult
    {
        // Phase 1: Collect last week's activity from all sources (calendar/chat/docs/meetings/tasks/sheets/bitables/mails).
        $batch = new ActivityBatch();
        foreach ($this->sources as $source) {
            if (!$source->supports($request)) {
                continue;
            }
            foreach ($source->collect($request, $feishuConfig) as $result) {
                $batch->add($result);
            }
        }

        // Create snapshot (reuse existing infra, now writes new buckets too).
        $snapshot = $this->stateStore->createSnapshot($request, $batch);

        // Phase 2: Short-circuit when absolutely nothing happened.
        // Tasks flow through items[] not a named bucket, so check them separately.
        $hasTasks = count(array_filter($batch->items(), fn($item) => $item->type === 'task')) > 0;
        if (!$batch->hasActivity() && !$hasTasks) {
            $analysis = AnalyzerResult::skip('no_activity_for_weekly_summary');
            $decision = ReminderDecision::skip('no_activity_for_weekly_summary');

            $this->stateStore->updateSnapshot($snapshot, [
                'llm_should_notify' => false,
                'llm_reasoning' => $analysis->reasoning,
                'skip_reason' => $decision->reason,
            ]);

            return new ReminderRunResult($batch, $analysis, $decision, null, $snapshot);
        }

        // Phase 3: LLM generates weekly report against the wiki template schema.
        $analysis = $this->analyzer->analyze($batch, $request);

        // Phase 4: Simple policy — if LLM generated a report, send it.
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

        $decision = ReminderDecision::send('weekly_summary_ready', $fingerprint ?? '', $message, $messageHash);
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
}
