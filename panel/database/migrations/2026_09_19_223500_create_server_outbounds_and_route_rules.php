<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_server_outbound', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tag')->unique();
            $table->string('protocol', 32);
            $table->text('raw_link')->nullable();
            $table->json('settings');
            $table->string('proxy_tag')->nullable();
            $table->boolean('enabled')->default(true);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::table('v2_server', function (Blueprint $table) {
            $table->json('outbound_ids')->nullable()->after('route_ids');
            $table->json('custom_route_rules')->nullable()->after('custom_routes');
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->dropColumn(['outbound_ids', 'custom_route_rules']);
        });

        Schema::dropIfExists('v2_server_outbound');
    }
};
