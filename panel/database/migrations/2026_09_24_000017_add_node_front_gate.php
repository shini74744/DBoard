<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('v2_server',function(Blueprint $t){
   $t->boolean('front_gate_enabled')->default(false)->index();
   $t->json('front_gate_node_ids')->nullable();
   $t->json('front_gate_group_ids')->nullable();
  });
  Schema::create('dboard_node_identity',function(Blueprint $t){
   $t->unsignedBigInteger('node_id')->primary();
   $t->text('certificate');$t->text('encrypted_key');
   $t->unsignedInteger('expires_at');$t->unsignedInteger('created_at');
  });
 }
 public function down():void {
  Schema::dropIfExists('dboard_node_identity');
  Schema::table('v2_server',fn(Blueprint $t)=>$t->dropColumn(['front_gate_enabled','front_gate_node_ids','front_gate_group_ids']));
 }
};
