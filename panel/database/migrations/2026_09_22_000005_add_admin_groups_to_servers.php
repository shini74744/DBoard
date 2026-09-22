<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_server_machine', 'admin_group')) {
            Schema::table('v2_server_machine', function (Blueprint $table) {
                $table->string('admin_group', 64)->nullable()->index();
            });
        }
        if (!Schema::hasColumn('v2_server', 'admin_group')) {
            Schema::table('v2_server', function (Blueprint $table) {
                $table->string('admin_group', 64)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_server', 'admin_group')) {
            Schema::table('v2_server', function (Blueprint $table) {
                $table->dropIndex(['admin_group']);
                $table->dropColumn('admin_group');
            });
        }
        if (Schema::hasColumn('v2_server_machine', 'admin_group')) {
            Schema::table('v2_server_machine', function (Blueprint $table) {
                $table->dropIndex(['admin_group']);
                $table->dropColumn('admin_group');
            });
        }
    }
};
