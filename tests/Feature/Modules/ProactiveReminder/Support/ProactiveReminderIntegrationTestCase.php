<?php

namespace Tests\Feature\Modules\ProactiveReminder\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

abstract class ProactiveReminderIntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.key', 'base64:' . base64_encode(str_repeat('p', 32)));
        Config::set('app.cipher', 'AES-256-CBC');
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->createTables();
    }

    private function createTables(): void
    {
        Schema::connection('sqlite')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('feishu_open_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0);
            $table->string('channel')->nullable();
            $table->string('channel_conversation_id')->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('proactive_activity_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedSmallInteger('scan_window_minutes')->default(30);
            $table->json('calendar_data')->nullable();
            $table->json('messages_data')->nullable();
            $table->json('documents_data')->nullable();
            $table->json('meetings_data')->nullable();
            $table->unsignedSmallInteger('calendar_count')->default(0);
            $table->unsignedSmallInteger('messages_count')->default(0);
            $table->unsignedSmallInteger('documents_count')->default(0);
            $table->unsignedSmallInteger('meetings_count')->default(0);
            $table->boolean('has_activity')->default(false);
            $table->boolean('llm_should_notify')->default(false);
            $table->text('llm_reasoning')->nullable();
            $table->text('llm_message')->nullable();
            $table->string('activity_fingerprint', 64)->nullable();
            $table->boolean('notification_sent')->default(false);
            $table->timestamp('notification_sent_at')->nullable();
            $table->string('notification_message_hash', 64)->nullable();
            $table->string('notification_channel', 32)->nullable();
            $table->string('skip_reason', 128)->nullable();
            $table->text('notification_error')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();
        });
    }
}
