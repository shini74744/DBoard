<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->unsignedBigInteger('admin_group_number')->nullable();
            $table->unique(['admin_group', 'admin_group_number'], 'server_admin_group_number_unique');
        });
        Schema::create('dboard_node_group_sequences', function (Blueprint $table) {
            $table->string('group_name', 64)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
        });
        DB::transaction(function () {
            $counts = [];
            foreach (DB::table('v2_server')->orderBy('id')->get(['id', 'admin_group']) as $node) {
                $group = trim((string) $node->admin_group);
                $counts[$group] = ($counts[$group] ?? 0) + 1;
                DB::table('v2_server')->where('id', $node->id)->update(['admin_group_number' => $counts[$group]]);
            }
            foreach ($counts as $group => $last) {
                DB::table('dboard_node_group_sequences')->insert(['group_name' => (string) $group, 'last_number' => $last]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->dropUnique('server_admin_group_number_unique');
            $table->dropColumn('admin_group_number');
        });
        Schema::dropIfExists('dboard_node_group_sequences');
    }
};
