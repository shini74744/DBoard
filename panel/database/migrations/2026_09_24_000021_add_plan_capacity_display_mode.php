<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_plan', fn (Blueprint $table) => $table->string('capacity_display_mode', 16)->default('status'));
    }
    public function down(): void
    {
        Schema::table('v2_plan', fn (Blueprint $table) => $table->dropColumn('capacity_display_mode'));
    }
};
