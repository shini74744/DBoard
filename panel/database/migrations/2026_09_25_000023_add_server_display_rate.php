<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('v2_server', fn (Blueprint $table) => $table->decimal('display_rate',12,4)->nullable()); }
    public function down(): void { Schema::table('v2_server', fn (Blueprint $table) => $table->dropColumn('display_rate')); }
};
