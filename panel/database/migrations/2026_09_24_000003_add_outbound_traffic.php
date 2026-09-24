<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  Schema::table('v2_server_outbound',function(Blueprint $t){
   $t->unsignedBigInteger('traffic_upload')->default(0);
   $t->unsignedBigInteger('traffic_download')->default(0);
   $t->unsignedInteger('traffic_started_at')->nullable();
   $t->unsignedInteger('traffic_updated_at')->nullable();
  });
  DB::table('v2_server_outbound')->update(['traffic_started_at'=>time()]);
  Schema::create('v2_outbound_traffic_cursor',function(Blueprint $t){
   $t->id();$t->unsignedBigInteger('outbound_id');$t->unsignedBigInteger('server_id');
   $t->uuid('session');$t->unsignedBigInteger('upload')->default(0);$t->unsignedBigInteger('download')->default(0);
   $t->unique(['outbound_id','server_id','session'],'outbound_traffic_cursor_unique');
   $t->foreign('outbound_id')->references('id')->on('v2_server_outbound')->onDelete('cascade');
  });
 }
 public function down(): void {
  Schema::dropIfExists('v2_outbound_traffic_cursor');
  Schema::table('v2_server_outbound',fn(Blueprint $t)=>$t->dropColumn(['traffic_upload','traffic_download','traffic_started_at','traffic_updated_at']));
 }
};
