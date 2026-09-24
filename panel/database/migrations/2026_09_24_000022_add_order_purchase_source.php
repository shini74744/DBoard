<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('v2_order', function (Blueprint $table) {
        $table->string('purchase_source', 16)->nullable();
        $table->unsignedBigInteger('quoted_price')->nullable();
        $table->bigInteger('subscription_expired_at_before')->nullable();
    }); }
    public function down(): void { Schema::table('v2_order', fn (Blueprint $table) => $table->dropColumn(['purchase_source','quoted_price','subscription_expired_at_before'])); }
};
