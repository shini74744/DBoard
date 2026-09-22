<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('dboard_admin_groups')) {
            Schema::create('dboard_admin_groups', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 16);
                $table->string('name', 64);
                $table->unsignedBigInteger('created_at');
                $table->unsignedBigInteger('updated_at');
                $table->unique(['kind', 'name']);
            });
        }

        foreach (['machine' => 'v2_server_machine', 'node' => 'v2_server'] as $kind => $source) {
            if (!Schema::hasColumn($source, 'admin_group')) {
                continue;
            }
            $names = DB::table($source)->whereNotNull('admin_group')
                ->where('admin_group', '<>', '')->distinct()->pluck('admin_group');
            foreach ($names as $name) {
                DB::table('dboard_admin_groups')->insertOrIgnore([
                    'kind' => $kind,
                    'name' => $name,
                    'created_at' => time(),
                    'updated_at' => time(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dboard_admin_groups');
    }
};
