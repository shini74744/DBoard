<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_server', 'custom_balancers')) {
                $table->json('custom_balancers')->nullable()->after('custom_route_rules');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            if (Schema::hasColumn('v2_server', 'custom_balancers')) {
                $table->dropColumn('custom_balancers');
            }
        });
    }
};
