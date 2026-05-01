<?php

namespace Tests\Unit\Modules\ProactiveReminder;

use App\Models\ProactiveActivitySnapshot;
use App\Modules\ProactiveReminder\Analyzers\WeeklySummaryAnalyzer;
use App\Modules\ProactiveReminder\Contracts\ActivitySourceInterface;
use App\Modules\ProactiveReminder\Contracts\ReminderChannelInterface;
use App\Modules\ProactiveReminder\Contracts\ReminderStateStoreInterface;
use App\Modules\ProactiveReminder\DTO\ActivityBatch;
use App\Modules\ProactiveReminder\DTO\ActivityItem;
use App\Modules\ProactiveReminder\DTO\AnalyzerResult;
use App\Modules\ProactiveReminder\DTO\DispatchResult;
use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use App\Modules\ProactiveReminder\DTO\SourceCollectionResult;
use App\Modules\ProactiveReminder\Kernel\WeeklySummaryKernel;
use App\Modules\ProactiveReminder\Support\ActivityFingerprintBuilder;
use App\Modules\ProactiveReminder\Support\MessageCanonicalizer;
use Carbon\CarbonImmutable;
use Mockery as m;
use Tests\TestCase;

class WeeklySummaryKernelTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function testSkipsWhenNoActivityCollected(): void
    {
        $analyzer = m::mock(WeeklySummaryAnalyzer::class);
        $analyzer->shouldNotReceive('analyze');

        $channel = m::mock(ReminderChannelInterface::class);
        $channel->shouldNotReceive('send');

        $kernel = new WeeklySummaryKernel(
            [$this->emptySource()],
            $analyzer,
            $channel,
            $this->inMemoryStore(),
            new ActivityFingerprintBuilder(new MessageCanonicalizer()),
            new MessageCanonicalizer(),
        );

        $result = $kernel->run($this->scanRequest(), []);

        $this->assertSame('no_activity_for_weekly_summary', $result->decision->reason);
        $this->assertSame('no_activity_for_weekly_summary', $result->snapshot?->skip_reason);
        $this->assertNull($result->dispatch);
    }

    public function testAnalyzerDeclineShortCircuits(): void
    {
        $analyzer = m::mock(WeeklySummaryAnalyzer::class);
        $analyzer->shouldReceive('analyze')->once()
            ->andReturn(AnalyzerResult::skip('llm_said_null'));

        $channel = m::mock(ReminderChannelInterface::class);
        $channel->shouldNotReceive('send');

        $kernel = new WeeklySummaryKernel(
            [$this->oneMessageSource('hash-9')],
            $analyzer,
            $channel,
            $this->inMemoryStore(),
            new ActivityFingerprintBuilder(new MessageCanonicalizer()),
            new MessageCanonicalizer(),
        );

        $result = $kernel->run($this->scanRequest(), []);

        $this->assertSame('llm_said_null', $result->decision->reason);
        $this->assertFalse($result->snapshot?->llm_should_notify ?? true);
        $this->assertNull($result->dispatch);
    }

    public function testDryRunSkipsActualDispatch(): void
    {
        $analyzer = m::mock(WeeklySummaryAnalyzer::class);
        $analyzer->shouldReceive('analyze')->once()
            ->andReturn(new AnalyzerResult(true, "汇报周期：2026/04/13~2026/04/17\n本周项目进展\n- 完成一项", 'weekly_summary_generated'));

        $channel = m::mock(ReminderChannelInterface::class);
        $channel->shouldNotReceive('send');

        $kernel = new WeeklySummaryKernel(
            [$this->oneMessageSource('hash-weekly')],
            $analyzer,
            $channel,
            $this->inMemoryStore(),
            new ActivityFingerprintBuilder(new MessageCanonicalizer()),
            new MessageCanonicalizer(),
        );

        $result = $kernel->run($this->scanRequest(dryRun: true), []);

        $this->assertSame('dry_run', $result->decision->reason);
        $this->assertFalse($result->snapshot?->notification_sent ?? true);
        $this->assertNull($result->dispatch);
    }

    public function testRealRunInvokesChannelAndMarksSent(): void
    {
        $analyzer = m::mock(WeeklySummaryAnalyzer::class);
        $analyzer->shouldReceive('analyze')->once()
            ->andReturn(new AnalyzerResult(true, '汇报周期：2026/04/13~2026/04/17', 'weekly_summary_generated'));

        $channel = m::mock(ReminderChannelInterface::class);
        $channel->shouldReceive('send')->once()
            ->andReturn(new DispatchResult(true, 'feishu', null));

        $kernel = new WeeklySummaryKernel(
            [$this->oneMessageSource('hash-send')],
            $analyzer,
            $channel,
            $this->inMemoryStore(),
            new ActivityFingerprintBuilder(new MessageCanonicalizer()),
            new MessageCanonicalizer(),
        );

        $result = $kernel->run($this->scanRequest(), []);

        $this->assertSame('weekly_summary_ready', $result->decision->reason);
        $this->assertTrue($result->dispatch?->sent ?? false);
        $this->assertTrue($result->snapshot?->notification_sent ?? false);
        $this->assertSame('feishu', $result->snapshot?->notification_channel);
    }

    private function scanRequest(bool $dryRun = false): ReminderScanRequest
    {
        return new ReminderScanRequest(
            userId: 3,
            userName: '用户A',
            openId: 'ou_xxx',
            since: CarbonImmutable::parse('2026-04-13 00:00:00', 'Asia/Shanghai'),
            until: CarbonImmutable::parse('2026-04-19 23:59:59', 'Asia/Shanghai'),
            windowMinutes: 10080,
            channel: 'feishu',
            collectionMode: 'full',
            dryRun: $dryRun,
        );
    }

    private function emptySource(): ActivitySourceInterface
    {
        return new class implements ActivitySourceInterface {
            public function supports(ReminderScanRequest $request): bool { return true; }
            public function collect(ReminderScanRequest $request, array $feishuConfig): array { return []; }
        };
    }

    private function oneMessageSource(string $hash): ActivitySourceInterface
    {
        return new class($hash) implements ActivitySourceInterface {
            public function __construct(private readonly string $hash) {}
            public function supports(ReminderScanRequest $request): bool { return true; }
            public function collect(ReminderScanRequest $request, array $feishuConfig): array
            {
                return [new SourceCollectionResult('messages', [[
                    'chat_name' => '项目群', 'sender' => '张三', 'text' => '方案已评审通过', 'text_hash' => $this->hash,
                ]], [
                    new ActivityItem('message', 'feishu.im', '项目群', '方案已评审通过', null, '张三', ['text_hash' => $this->hash]),
                ])];
            }
        };
    }

    private function inMemoryStore(): ReminderStateStoreInterface
    {
        $snapshot = new ProactiveActivitySnapshot();
        return new class($snapshot) implements ReminderStateStoreInterface {
            public function __construct(private readonly ProactiveActivitySnapshot $snapshot) {}
            public function recentNotificationHashes(int $userId, CarbonImmutable $since): array { return []; }
            public function hasRecentNotification(int $userId, CarbonImmutable $since): bool { return false; }
            public function hasRecentNotificationForFingerprint(int $userId, string $fingerprint, CarbonImmutable $since): bool { return false; }
            public function createSnapshot(ReminderScanRequest $request, ActivityBatch $batch): ProactiveActivitySnapshot { return $this->snapshot; }
            public function updateSnapshot(ProactiveActivitySnapshot $snapshot, array $attributes): void { $snapshot->forceFill($attributes); }
        };
    }
}
