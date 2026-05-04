<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doppelganger_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doppelganger_id')->constrained('doppelgangers')->cascadeOnDelete();
            $table->foreignId('grantee_user_id')->constrained('users')->cascadeOnDelete()
                  ->comment('接班人 user id');
            $table->string('access_level', 32)->default('read_only')
                  ->comment('read_only / use_voice / use_workflow / full');
            $table->foreignId('granted_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['doppelganger_id', 'grantee_user_id'], 'dop_grants_unique');
            $table->index(['grantee_user_id', 'doppelganger_id'], 'dop_grants_by_grantee');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doppelganger_grants');
    }
};
