<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('v2_server_outbound', function (Blueprint $table) {
            $table->unsignedBigInteger('target_server_id')->nullable()->unique();
        });
    }
    public function down(): void {
        Schema::table('v2_server_outbound', function (Blueprint $table) {
            $table->dropUnique(['target_server_id']);
            $table->dropColumn('target_server_id');
        });
    }
};
