<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
return new class extends Migration {
 public function up(): void {
  Schema::table('v2_server_outbound',function(Blueprint $t){$t->unsignedBigInteger('display_id')->nullable()->unique();});
  Schema::create('dboard_outbound_sequence',function(Blueprint $t){$t->unsignedInteger('id')->primary();$t->unsignedBigInteger('last_number')->default(0);});
  DB::transaction(function(){
   DB::table('v2_server_outbound')->update(['display_id'=>DB::raw('id')]);
   DB::table('dboard_outbound_sequence')->insert(['id'=>1,'last_number'=>(int)DB::table('v2_server_outbound')->max('id')]);
  });
 }
 public function down(): void {
  Schema::table('v2_server_outbound',function(Blueprint $t){$t->dropUnique(['display_id']);$t->dropColumn('display_id');});
  Schema::dropIfExists('dboard_outbound_sequence');
 }
};
