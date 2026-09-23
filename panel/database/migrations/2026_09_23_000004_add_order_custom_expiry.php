<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_order', function (Blueprint $table) {
            $table->unsignedInteger('custom_duration_days')->nullable();
            $table->unsignedInteger('custom_expired_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('v2_order', function (Blueprint $table) {
            $table->dropColumn(['custom_duration_days', 'custom_expired_at']);
        });
    }
};
