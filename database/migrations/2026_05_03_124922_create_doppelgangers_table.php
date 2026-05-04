<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doppelgangers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_user_id')->constrained('users')->cascadeOnDelete()
                  ->comment('源员工 id（被生成分身的人）');
            $table->string('display_name', 191)->comment('分身展示名，默认带「数字分身」后缀');
            $table->string('status', 32)->default('pending')->index()
                  ->comment('pending / sample_extracting / active / paused / expired / revoked');
            $table->timestamp('consent_signed_at')->nullable()->comment('员工签同意书日期');
            $table->string('consent_doc_path', 255)->nullable()->comment('同意书 PDF 在 storage 的路径');
            $table->timestamp('enabled_at')->nullable()->comment('管理员激活时间');
            $table->timestamp('expires_at')->nullable()->index()->comment('到期时间');
            $table->timestamp('service_fee_paid_until')->nullable()->comment('公司续费到期日');
            $table->timestamp('samples_extracted_at')->nullable()->comment('一次性样本提取完成时间');
            $table->json('meta')->nullable()->comment('额外元数据，如关系网络、风格特征摘要');
            $table->timestamps();

            $table->unique('source_user_id', 'doppelgangers_source_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doppelgangers');
    }
};
