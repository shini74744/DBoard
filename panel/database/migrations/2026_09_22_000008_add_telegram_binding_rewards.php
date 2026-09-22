<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->string('telegram_username', 64)->nullable()->after('telegram_id');
            $table->unsignedInteger('telegram_username_synced_at')->nullable()->after('telegram_username');
            $table->unsignedBigInteger('telegram_bonus_cycle')->default(0)->after('telegram_username_synced_at');
            $table->unsignedBigInteger('telegram_bonus_permanent')->default(0)->after('telegram_bonus_cycle');
        });

        Schema::create('v2_telegram_traffic_grant', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->bigInteger('telegram_id')->nullable();
            $table->unsignedBigInteger('amount_bytes');
            $table->string('mode', 16);
            $table->string('source', 16);
            $table->string('reward_key', 100)->nullable()->unique();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedInteger('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_telegram_traffic_grant');
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropColumn(['telegram_username', 'telegram_username_synced_at', 'telegram_bonus_cycle', 'telegram_bonus_permanent']);
        });
    }
};
