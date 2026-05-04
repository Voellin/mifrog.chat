<?php

namespace Tests\Feature\Modules\ProactiveReminder;

use App\Modules\ProactiveReminder\DTO\ActivityBatch;
use App\Modules\ProactiveReminder\DTO\ActivityItem;
use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use App\Modules\ProactiveReminder\DTO\SourceCollectionResult;
use App\Modules\ProactiveReminder\Stores\DatabaseReminderStateStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Modules\ProactiveReminder\Support\ProactiveReminderIntegrationTestCase;

class DatabaseReminderStateStoreTest extends ProactiveReminderIntegrationTestCase
{
    public function testCreateAndQueryNotificationState(): void
    {
        DB::table('users')->insert([
            'id' => 3,
            'name' => '东方',
            'feishu_open_id' => 'ou_xxx',
            'is_active' => 1,
        ]);

        $store = new DatabaseReminderStateStore();
        $batch = new ActivityBatch();
        $batch->add(new SourceCollectionResult('messages', [[
            'chat_name' => '私聊',
            'sender' => '张三',
            'direction' => 'received',
            'text' => '请帮我跟进报价',
            'text_hash' => 'hash-2',
        ]], [
            new ActivityItem('message', 'feishu.im', '私聊', '请帮我跟进报价', CarbonImmutable::parse('2026-04-10 10:00:00'), '张三', [
                'direction' => 'received',
                'text_hash' => 'hash-2',
            ]),
        ]));

        $request = new ReminderScanRequest(
            3,
            '东方',
            'ou_xxx',
            CarbonImmutable::parse('2026-04-10 09:30:00'),
            CarbonImmutable::parse('2026-04-10 10:00:00'),
            30
        );

        $snapshot = $store->createSnapshot($request, $batch);
        $store->updateSnapshot($snapshot, [
            'activity_fingerprint' => 'fp-1',
            'notification_sent' => true,
            'notification_sent_at' => CarbonImmutable::parse('2026-04-10 10:00:00'),
            'notification_message_hash' => 'hash-2',
        ]);

        $this->assertTrue($store->hasRecentNotification(3, CarbonImmutable::parse('2026-04-10 09:00:00')));
        $this->assertTrue($store->hasRecentNotificationForFingerprint(3, 'fp-1', CarbonImmutable::parse('2026-04-10 09:00:00')));
        $this->assertSame(['hash-2'], $store->recentNotificationHashes(3, CarbonImmutable::parse('2026-04-10 09:00:00')));
    }
}
