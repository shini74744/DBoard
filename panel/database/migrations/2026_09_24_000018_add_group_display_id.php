<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
return new class extends Migration {
    public function up(): void {
        Schema::table('v2_server_group', function (Blueprint $table) { $table->unsignedBigInteger('display_id')->nullable()->unique(); });
        Schema::create('dboard_group_sequence', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('last_number')->default(0);
        });
        DB::transaction(function () {
            DB::table('v2_server_group')->update(['display_id' => DB::raw('id')]);
            DB::table('dboard_group_sequence')->insert(['id' => 1, 'last_number' => (int) DB::table('v2_server_group')->max('id')]);
        });
    }
    public function down(): void {
        Schema::table('v2_server_group', function (Blueprint $table) { $table->dropUnique(['display_id']); $table->dropColumn('display_id'); });
        Schema::dropIfExists('dboard_group_sequence');
    }
};
