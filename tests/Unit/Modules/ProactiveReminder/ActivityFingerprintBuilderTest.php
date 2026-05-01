<?php

namespace Tests\Unit\Modules\ProactiveReminder;

use App\Modules\ProactiveReminder\DTO\ActivityBatch;
use App\Modules\ProactiveReminder\DTO\ActivityItem;
use App\Modules\ProactiveReminder\DTO\SourceCollectionResult;
use App\Modules\ProactiveReminder\Support\ActivityFingerprintBuilder;
use App\Modules\ProactiveReminder\Support\MessageCanonicalizer;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ActivityFingerprintBuilderTest extends TestCase
{
    public function testBuildIsStableAcrossItemOrder(): void
    {
        $builder = new ActivityFingerprintBuilder(new MessageCanonicalizer());

        $first = new ActivityBatch();
        $first->add(new SourceCollectionResult('messages', [], [
            new ActivityItem('message', 'feishu.im', '私聊', '请跟进客户报价', CarbonImmutable::parse('2026-04-10 10:00:00'), '张三', ['direction' => 'received']),
            new ActivityItem('meeting', 'feishu.vc', '项目周会', '整理会议纪要', CarbonImmutable::parse('2026-04-10 10:05:00')),
        ]));

        $second = new ActivityBatch();
        $second->add(new SourceCollectionResult('messages', [], [
            new ActivityItem('meeting', 'feishu.vc', '项目周会', '整理会议纪要', CarbonImmutable::parse('2026-04-10 10:05:00')),
            new ActivityItem('message', 'feishu.im', '私聊', '请跟进客户报价', CarbonImmutable::parse('2026-04-10 10:00:00'), '张三', ['direction' => 'received']),
        ]));

        $this->assertSame($builder->build($first), $builder->build($second));
    }

    public function testBuildReturnsNullForEmptyBatch(): void
    {
        $builder = new ActivityFingerprintBuilder(new MessageCanonicalizer());

        $this->assertNull($builder->build(new ActivityBatch()));
    }
}
