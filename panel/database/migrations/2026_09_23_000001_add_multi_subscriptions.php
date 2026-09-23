<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->unsignedInteger('parent_id')->nullable()->index();
            $table->string('subscription_link_mode', 16)->default('merged');
            $table->string('duplicate_node_mode', 16)->default('all');
        });
        Schema::table('v2_order', function (Blueprint $table) {
            $table->unsignedInteger('subscription_user_id')->nullable()->index();
            $table->string('subscription_action', 16)->nullable();
        });
        Schema::table('v2_gift_card_usage', function (Blueprint $table) {
            $table->unsignedInteger('subscription_user_id')->nullable()->index();
        });
        Schema::table('v2_telegram_traffic_grant', function (Blueprint $table) {
            $table->unsignedInteger('account_user_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('v2_telegram_traffic_grant', function (Blueprint $table) {
            $table->dropIndex(['account_user_id']);
            $table->dropColumn('account_user_id');
        });
        Schema::table('v2_gift_card_usage', function (Blueprint $table) {
            $table->dropIndex(['subscription_user_id']);
            $table->dropColumn('subscription_user_id');
        });
        Schema::table('v2_order', function (Blueprint $table) {
            $table->dropIndex(['subscription_user_id']);
            $table->dropColumn(['subscription_user_id', 'subscription_action']);
        });
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropIndex(['parent_id']);
            $table->dropColumn(['parent_id', 'subscription_link_mode', 'duplicate_node_mode']);
        });
    }
};
