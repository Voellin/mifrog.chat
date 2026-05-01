<?php

namespace Tests\Feature\Modules\ProactiveReminder;

use App\Modules\ProactiveReminder\DTO\ReminderScanRequest;
use App\Modules\ProactiveReminder\Sources\DatabaseFallbackActivitySource;
use App\Modules\ProactiveReminder\Support\ActivityTimeParser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Modules\ProactiveReminder\Support\ProactiveReminderIntegrationTestCase;

class DatabaseFallbackActivitySourceTest extends ProactiveReminderIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('sqlite')->create('runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0);
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('run_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('run_id')->default(0);
            $table->string('event_type')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function testCollectDoesNotTreatAssistantConversationAsMessageActivity(): void
    {
        $runId = DB::connection('sqlite')->table('runs')->insertGetId([
            'user_id' => 3,
            'created_at' => '2026-04-12 13:55:00',
            'updated_at' => '2026-04-12 13:55:00',
        ]);

        DB::connection('sqlite')->table('run_events')->insert([
            [
                'run_id' => $runId,
                'event_type' => 'tool_start',
                'payload' => json_encode(['skill_key' => 'calendar.create', 'task_kind' => 'calendar_create'], JSON_UNESCAPED_UNICODE),
                'created_at' => '2026-04-12 13:55:10',
                'updated_at' => '2026-04-12 13:55:10',
            ],
            [
                'run_id' => $runId,
                'event_type' => 'tool_start',
                'payload' => json_encode(['skill_key' => 'docs.read', 'task_kind' => 'docs_read'], JSON_UNESCAPED_UNICODE),
                'created_at' => '2026-04-12 13:55:20',
                'updated_at' => '2026-04-12 13:55:20',
            ],
        ]);

        $source = new DatabaseFallbackActivitySource(new ActivityTimeParser());
        $request = new ReminderScanRequest(
            userId: 3,
            userName: '用户A',
            openId: 'ou_dongfang',
            since: CarbonImmutable::parse('2026-04-12 13:30:00', 'Asia/Shanghai'),
            until: CarbonImmutable::parse('2026-04-12 14:00:00', 'Asia/Shanghai'),
            windowMinutes: 30,
            collectionMode: 'fallback',
        );

        $results = $source->collect($request, []);

        $messages = collect($results)->firstWhere('bucket', 'messages');
        $calendar = collect($results)->firstWhere('bucket', 'calendar');
        $documents = collect($results)->firstWhere('bucket', 'documents');

        $this->assertNotNull($messages);
        $this->assertSame([], $messages->records);
        $this->assertNotNull($calendar);
        $this->assertCount(1, $calendar->records);
        $this->assertNotNull($documents);
        $this->assertCount(1, $documents->records);
    }
}
