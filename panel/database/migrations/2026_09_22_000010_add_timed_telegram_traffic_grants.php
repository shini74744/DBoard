<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->unsignedBigInteger('telegram_bonus_timed')->default(0)->after('telegram_bonus_permanent');
        });
        Schema::table('v2_telegram_traffic_grant', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_days')->nullable()->after('reason');
            $table->unsignedInteger('expires_at')->nullable()->after('duration_days');
            $table->unsignedInteger('applied_at')->nullable()->after('expires_at');
            $table->unsignedInteger('revoked_at')->nullable()->after('applied_at');
            $table->index(['mode', 'expires_at', 'revoked_at'], 'telegram_grant_expiry_idx');
        });
    }
    public function down(): void
    {
        Schema::table('v2_telegram_traffic_grant', function (Blueprint $table) {
            $table->dropIndex('telegram_grant_expiry_idx');
            $table->dropColumn(['duration_days', 'expires_at', 'applied_at', 'revoked_at']);
        });
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropColumn('telegram_bonus_timed');
        });
    }
};
