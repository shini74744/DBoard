<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_subscribe_log') || !Schema::hasColumn('v2_subscribe_log', 'token')) {
            return;
        }

        Schema::table('v2_subscribe_log', function (Blueprint $table) {
            $table->string('token', 64)->change();
        });
    }

    public function down(): void
    {
        // Keep 64 characters on rollback. Existing SHA-256 values must not be truncated.
    }
};
