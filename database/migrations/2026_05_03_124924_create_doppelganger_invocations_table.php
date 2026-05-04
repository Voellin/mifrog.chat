<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doppelganger_invocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doppelganger_id')->constrained('doppelgangers')->cascadeOnDelete();
            $table->foreignId('caller_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('caller_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->tinyInteger('level')->unsigned()->comment('1=knowledge / 2=voice / 3=workflow');
            $table->text('query')->nullable();
            $table->text('response_excerpt')->nullable();
            $table->unsignedInteger('token_input')->default(0);
            $table->unsignedInteger('token_output')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['doppelganger_id', 'created_at'], 'dop_inv_by_dop_time');
            $table->index(['caller_user_id', 'created_at'], 'dop_inv_by_caller_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doppelganger_invocations');
    }
};
