<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doppelganger_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doppelganger_id')->constrained('doppelgangers')->cascadeOnDelete();
            $table->string('workflow_name', 191)->comment('如「每周三 16:00 写周报」');
            $table->string('trigger_type', 32)->default('cron')
                  ->comment('cron / event / manual');
            $table->string('trigger_spec', 191)->nullable()
                  ->comment('cron 表达式 或 event id');
            $table->text('template_content')->nullable()->comment('该任务的执行模板/范例 markdown');
            $table->text('sample_excerpt')->nullable()->comment('过往执行片段，做 few-shot 用');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_pushed_at')->nullable()->comment('上次推送提醒时间');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['doppelganger_id', 'is_active'], 'dop_wf_by_dop_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doppelganger_workflows');
    }
};
