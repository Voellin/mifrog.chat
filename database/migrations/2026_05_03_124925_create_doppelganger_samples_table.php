<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doppelganger_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doppelganger_id')->constrained('doppelgangers')->cascadeOnDelete();
            $table->string('sample_type', 32)->index()
                  ->comment('voice / workflow / decision / preference');
            $table->string('context_summary', 500)->nullable()->comment('该样本的语境一句话摘要');
            $table->mediumText('content')->comment('原文片段或 LLM 浓缩后的样本内容');
            $table->decimal('score', 6, 4)->default(0)->index()
                  ->comment('相关性 / 代表性评分，0-1');
            $table->json('meta')->nullable()->comment('源类型 / 时间窗 / 关键词');
            $table->timestamps();

            $table->index(['doppelganger_id', 'sample_type'], 'dop_samples_by_dop_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doppelganger_samples');
    }
};
