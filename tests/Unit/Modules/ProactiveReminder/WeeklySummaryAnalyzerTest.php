<?php

namespace Tests\Unit\Modules\ProactiveReminder;

use App\Modules\ProactiveReminder\Analyzers\WeeklySummaryAnalyzer;
use App\Modules\ProactiveReminder\DTO\ActivityBatch;
use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use App\Modules\ProactiveReminder\DTO\SourceCollectionResult;
use App\Services\LlmGatewayService;
use Carbon\CarbonImmutable;
use Mockery as m;
use RuntimeException;
use Tests\TestCase;

class WeeklySummaryAnalyzerTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function testEmptyBatchSkipsWithoutCallingGateway(): void
    {
        $gateway = m::mock(LlmGatewayService::class);
        $gateway->shouldNotReceive('chat');

        $analyzer = new WeeklySummaryAnalyzer($gateway);
        $result = $analyzer->analyze(new ActivityBatch(), $this->scanRequest());

        $this->assertFalse($result->shouldNotify);
        $this->assertSame('no_activity', $result->reasoning);
    }

    public function testLlmReturnsNullStringBecomesSkip(): void
    {
        $gateway = m::mock(LlmGatewayService::class);
        $gateway->shouldReceive('chat')->once()
            ->andReturn(['content' => 'NULL']);

        $analyzer = new WeeklySummaryAnalyzer($gateway);
        $result = $analyzer->analyze($this->batchWithSomeActivity(), $this->scanRequest());

        $this->assertFalse($result->shouldNotify);
        $this->assertSame('llm_said_null', $result->reasoning);
    }

    public function testSuccessfulGenerationReturnsMessage(): void
    {
        $gateway = m::mock(LlmGatewayService::class);
        $gateway->shouldReceive('chat')->once()
            ->andReturn([
                'content' => "汇报周期：2026/04/13~2026/04/19\n本周项目进展\n- 完成一项",
                'input_tokens' => 100,
                'output_tokens' => 50,
            ]);

        $analyzer = new WeeklySummaryAnalyzer($gateway);
        $result = $analyzer->analyze($this->batchWithSomeActivity(), $this->scanRequest());

        $this->assertTrue($result->shouldNotify);
        $this->assertStringContainsString('汇报周期', (string) $result->message);
        $this->assertSame('weekly_summary_generated', $result->reasoning);
    }

    public function testGatewayThrowableBecomesErrorSkip(): void
    {
        $gateway = m::mock(LlmGatewayService::class);
        $gateway->shouldReceive('chat')->once()
            ->andThrow(new RuntimeException('gateway timeout'));

        $analyzer = new WeeklySummaryAnalyzer($gateway);
        $result = $analyzer->analyze($this->batchWithSomeActivity(), $this->scanRequest());

        $this->assertFalse($result->shouldNotify);
        $this->assertStringStartsWith('llm_error:', (string) $result->reasoning);
        $this->assertStringContainsString('gateway timeout', (string) $result->reasoning);
    }

    public function testDigestIncludesSheetsBitablesAndMailsBuckets(): void
    {
        $captured = null;
        $gateway = m::mock(LlmGatewayService::class);
        $gateway->shouldReceive('chat')->once()
            ->andReturnUsing(function (array $messages) use (&$captured) {
                $captured = $messages;
                return ['content' => 'dummy'];
            });

        $batch = new ActivityBatch();
        $batch->add(new SourceCollectionResult('sheets', [[
            'title' => '销售明细表', 'owner' => '王二',
            'modified_time' => '2026-04-15 14:00:00',
            'url' => 'https://feishu.example/sheet/1',
        ]], []));
        $batch->add(new SourceCollectionResult('bitables', [[
            'title' => '需求跟踪', 'modified_time' => '2026-04-16 10:00:00',
        ]], []));
        $batch->add(new SourceCollectionResult('mails', [[
            'subject' => 'Q2 预算评审', 'from' => 'cfo@example.com',
            'received_at' => '2026-04-14 09:00:00',
            'preview' => '请各业务线提交预算草案',
        ]], []));

        $analyzer = new WeeklySummaryAnalyzer($gateway);
        $analyzer->analyze($batch, $this->scanRequest());

        $this->assertNotNull($captured);
        $userContent = (string) ($captured[1]['content'] ?? '');
        $this->assertStringContainsString('【本周飞书表格】', $userContent);
        $this->assertStringContainsString('销售明细表', $userContent);
        $this->assertStringContainsString('【本周多维表格】', $userContent);
        $this->assertStringContainsString('需求跟踪', $userContent);
        $this->assertStringContainsString('【本周邮件】', $userContent);
        $this->assertStringContainsString('Q2 预算评审', $userContent);
    }

    private function scanRequest(): ReminderScanRequest
    {
        return new ReminderScanRequest(
            userId: 3,
            userName: '东方',
            openId: 'ou_xxx',
            since: CarbonImmutable::parse('2026-04-13 00:00:00', 'Asia/Shanghai'),
            until: CarbonImmutable::parse('2026-04-19 23:59:59', 'Asia/Shanghai'),
            windowMinutes: 10080,
            channel: 'feishu',
            collectionMode: 'full',
            dryRun: true,
        );
    }

    private function batchWithSomeActivity(): ActivityBatch
    {
        $batch = new ActivityBatch();
        $batch->add(new SourceCollectionResult('messages', [[
            'chat_name' => '项目群', 'sender' => '张三', 'text' => '方案已评审通过', 'text_hash' => 'hash-x',
        ]], []));
        return $batch;
    }
}
