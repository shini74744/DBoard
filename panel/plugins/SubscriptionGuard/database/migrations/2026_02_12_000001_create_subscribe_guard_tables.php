<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_subscribe_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->string('ip', 128);
            $table->string('ip_region', 255)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('token', 64)->index();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('v2_subscribe_abuse', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->unique();
            $table->unsignedSmallInteger('unique_ip_count');
            $table->json('ip_list');
            $table->string('action_taken', 32); // auto_ban, alert_only, reset_only
            $table->text('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_subscribe_log');
        Schema::dropIfExists('v2_subscribe_abuse');
    }
};
