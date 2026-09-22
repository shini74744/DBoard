<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->unsignedInteger('sort')->default(0)->after('id');
        });
        $ids = DB::table('v2_server_machine')->orderBy('id')->pluck('id');
        foreach ($ids as $position => $id) {
            DB::table('v2_server_machine')->where('id', $id)->update(['sort' => $position + 1]);
        }
    }

    public function down(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->dropColumn('sort');
        });
    }
};
